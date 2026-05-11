<?php
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET is open to all logged-in users; mutations are admin-only
requireLogin();

switch ($method) {

    case 'GET':
        $semester_id = $_GET['semester_id'] ?? null;
        $program_id  = $_GET['program_id']  ?? null;

        // Default to active semester if none specified
        if (!$semester_id) {
            $row = $pdo->query("SELECT id FROM semesters WHERE is_active = 1 LIMIT 1")->fetch();
            $semester_id = $row['id'] ?? null;
        }

        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare("
                SELECT c.*, s.name AS semester_name, p.name AS program_name,
                       u.full_name AS lecturer_name
                FROM courses c
                JOIN semesters s ON s.id = c.semester_id
                JOIN programs  p ON p.id = c.program_id
                LEFT JOIN course_assignments ca ON ca.course_id = c.id AND ca.semester_id = c.semester_id
                LEFT JOIN users u ON u.id = ca.lecturer_id
                WHERE c.id = ?
            ");
            $stmt->execute([$_GET['id']]);
            $course = $stmt->fetch();
            if (!$course) { http_response_code(404); echo json_encode(['error' => 'Course not found']); exit; }
            // Get enrolled student count
            $enroll = $pdo->prepare("SELECT COUNT(*) FROM course_enrollments WHERE course_id = ? AND status = 'active'");
            $enroll->execute([$course['id']]);
            $course['enrolled_count'] = (int)$enroll->fetchColumn();
            echo json_encode($course);
        } else {
            $where = []; $params = [];
            if ($semester_id) { $where[] = 'c.semester_id = ?'; $params[] = $semester_id; }
            if ($program_id)  { $where[] = 'c.program_id = ?';  $params[] = $program_id; }
            $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare("
                SELECT c.*, s.name AS semester_name, p.name AS program_name,
                       u.full_name AS lecturer_name, u.id AS lecturer_id,
                       COUNT(DISTINCT ce.student_id) AS enrolled_count
                FROM courses c
                JOIN semesters s ON s.id = c.semester_id
                JOIN programs  p ON p.id = c.program_id
                LEFT JOIN course_assignments ca ON ca.course_id = c.id AND ca.semester_id = c.semester_id
                LEFT JOIN users u ON u.id = ca.lecturer_id
                LEFT JOIN course_enrollments ce ON ce.course_id = c.id AND ce.status = 'active'
                $whereSQL
                GROUP BY c.id
                ORDER BY c.code ASC
            ");
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
        }
        break;

    case 'POST':
        requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        $required = ['program_id', 'semester_id', 'code', 'name'];
        foreach ($required as $field) {
            if (empty($data[$field])) { http_response_code(400); echo json_encode(['error' => "Field '$field' is required"]); exit; }
        }
        $check = $pdo->prepare("SELECT id FROM courses WHERE code = ? AND semester_id = ?");
        $check->execute([$data['code'], $data['semester_id']]);
        if ($check->fetch()) { http_response_code(409); echo json_encode(['error' => 'Course code already exists in this semester']); exit; }
        $stmt = $pdo->prepare("INSERT INTO courses (program_id, semester_id, code, name, credit_hrs) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$data['program_id'], $data['semester_id'], strtoupper(trim($data['code'])), trim($data['name']), $data['credit_hrs'] ?? 3]);
        $courseId = $pdo->lastInsertId();

        // Auto-assign lecturer if provided
        if (!empty($data['lecturer_id'])) {
            $pdo->prepare("INSERT IGNORE INTO course_assignments (course_id, lecturer_id, semester_id) VALUES (?, ?, ?)")
                ->execute([$courseId, $data['lecturer_id'], $data['semester_id']]);
        }

        // Auto-enroll all active students in same program if requested
        if (!empty($data['enroll_all'])) {
            $students = $pdo->prepare("SELECT id FROM users WHERE role IN ('student','rep') AND program_id = ? AND is_active = 1");
            $students->execute([$data['program_id']]);
            $enroll = $pdo->prepare("INSERT IGNORE INTO course_enrollments (course_id, student_id, semester_id) VALUES (?, ?, ?)");
            foreach ($students->fetchAll() as $s) {
                $enroll->execute([$courseId, $s['id'], $data['semester_id']]);
            }
        }

        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, detail, ip_address) VALUES (?, 'CREATE_COURSE', 'course', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $courseId, json_encode(['code' => $data['code'], 'name' => $data['name']]), $_SERVER['REMOTE_ADDR'] ?? null]);

        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $courseId, 'message' => 'Course created']);
        break;

    case 'PUT':
        requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) { http_response_code(400); echo json_encode(['error' => 'Course ID required']); exit; }
        $fields = []; $values = [];
        if (!empty($data['code']))       { $fields[] = 'code = ?';       $values[] = strtoupper(trim($data['code'])); }
        if (!empty($data['name']))       { $fields[] = 'name = ?';       $values[] = trim($data['name']); }
        if (!empty($data['credit_hrs'])) { $fields[] = 'credit_hrs = ?'; $values[] = (int)$data['credit_hrs']; }
        if (!empty($data['program_id'])) { $fields[] = 'program_id = ?'; $values[] = (int)$data['program_id']; }
        if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'No fields to update']); exit; }
        $values[] = $data['id'];
        $pdo->prepare("UPDATE courses SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);

        // Update lecturer assignment if provided
        if (!empty($data['lecturer_id']) && !empty($data['semester_id'])) {
            $pdo->prepare("DELETE FROM course_assignments WHERE course_id = ? AND semester_id = ?")->execute([$data['id'], $data['semester_id']]);
            $pdo->prepare("INSERT IGNORE INTO course_assignments (course_id, lecturer_id, semester_id) VALUES (?, ?, ?)")->execute([$data['id'], $data['lecturer_id'], $data['semester_id']]);
        }

        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address) VALUES (?, 'UPDATE_COURSE', 'course', ?, ?)")
            ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
        echo json_encode(['success' => true, 'message' => 'Course updated']);
        break;

    case 'DELETE':
        requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) { http_response_code(400); echo json_encode(['error' => 'Course ID required']); exit; }
        $check = $pdo->prepare("SELECT COUNT(*) FROM sessions WHERE course_id = ?");
        $check->execute([$data['id']]);
        if ($check->fetchColumn() > 0) { http_response_code(409); echo json_encode(['error' => 'Cannot delete course with existing attendance sessions']); exit; }
        $pdo->prepare("DELETE FROM courses WHERE id = ?")->execute([$data['id']]);
        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address) VALUES (?, 'DELETE_COURSE', 'course', ?, ?)")
            ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
        echo json_encode(['success' => true, 'message' => 'Course deleted']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
