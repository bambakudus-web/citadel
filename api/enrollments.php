<?php
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
requireRole('admin');

switch ($method) {

    // GET enrollments for a course or a student
    case 'GET':
        if (!empty($_GET['course_id'])) {
            $semester_id = $_GET['semester_id'] ?? null;
            if (!$semester_id) {
                $row = $pdo->query("SELECT id FROM semesters WHERE is_active = 1 LIMIT 1")->fetch();
                $semester_id = $row['id'] ?? null;
            }
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.index_no, u.email, u.role, ce.status, ce.enrolled_at
                FROM course_enrollments ce
                JOIN users u ON u.id = ce.student_id
                WHERE ce.course_id = ? AND ce.semester_id = ?
                ORDER BY u.full_name ASC
            ");
            $stmt->execute([$_GET['course_id'], $semester_id]);
            echo json_encode($stmt->fetchAll());
        } elseif (!empty($_GET['student_id'])) {
            $stmt = $pdo->prepare("
                SELECT c.id, c.code, c.name, s.name AS semester_name, ce.status, ce.enrolled_at,
                       u.full_name AS lecturer_name
                FROM course_enrollments ce
                JOIN courses c ON c.id = ce.course_id
                JOIN semesters s ON s.id = ce.semester_id
                LEFT JOIN course_assignments ca ON ca.course_id = c.id AND ca.semester_id = ce.semester_id
                LEFT JOIN users u ON u.id = ca.lecturer_id
                WHERE ce.student_id = ?
                ORDER BY s.academic_year DESC, c.code ASC
            ");
            $stmt->execute([$_GET['student_id']]);
            echo json_encode($stmt->fetchAll());
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'course_id or student_id required']);
        }
        break;

    // Enroll student(s) in a course
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['course_id']) || empty($data['semester_id'])) {
            http_response_code(400); echo json_encode(['error' => 'course_id and semester_id required']); exit;
        }

        // Bulk enroll array of student_ids, or single
        $student_ids = $data['student_ids'] ?? (isset($data['student_id']) ? [$data['student_id']] : []);
        if (empty($student_ids)) { http_response_code(400); echo json_encode(['error' => 'student_id or student_ids required']); exit; }

        $stmt = $pdo->prepare("INSERT IGNORE INTO course_enrollments (course_id, student_id, semester_id) VALUES (?, ?, ?)");
        $count = 0;
        foreach ($student_ids as $sid) {
            $stmt->execute([$data['course_id'], $sid, $data['semester_id']]);
            $count++;
        }

        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, detail, ip_address) VALUES (?, 'ENROLL_STUDENTS', 'course', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $data['course_id'], json_encode(['count' => $count]), $_SERVER['REMOTE_ADDR'] ?? null]);

        echo json_encode(['success' => true, 'enrolled' => $count]);
        break;

    // Update enrollment status (drop / reactivate)
    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['course_id']) || empty($data['student_id']) || empty($data['status'])) {
            http_response_code(400); echo json_encode(['error' => 'course_id, student_id, status required']); exit;
        }
        $allowed = ['active', 'dropped', 'completed'];
        if (!in_array($data['status'], $allowed)) { http_response_code(400); echo json_encode(['error' => 'Invalid status']); exit; }
        $pdo->prepare("UPDATE course_enrollments SET status = ? WHERE course_id = ? AND student_id = ?")
            ->execute([$data['status'], $data['course_id'], $data['student_id']]);
        echo json_encode(['success' => true, 'message' => 'Enrollment status updated']);
        break;

    // Remove enrollment
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['course_id']) || empty($data['student_id'])) {
            http_response_code(400); echo json_encode(['error' => 'course_id and student_id required']); exit;
        }
        $pdo->prepare("DELETE FROM course_enrollments WHERE course_id = ? AND student_id = ?")
            ->execute([$data['course_id'], $data['student_id']]);
        echo json_encode(['success' => true, 'message' => 'Enrollment removed']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
