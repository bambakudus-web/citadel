<?php
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
requireRole('admin');

switch ($method) {

    // Get all assignments, optionally filtered by semester or lecturer
    case 'GET':
        $semester_id  = $_GET['semester_id']  ?? null;
        $lecturer_id  = $_GET['lecturer_id']  ?? null;

        if (!$semester_id) {
            $row = $pdo->query("SELECT id FROM semesters WHERE is_active = 1 LIMIT 1")->fetch();
            $semester_id = $row['id'] ?? null;
        }

        $where = []; $params = [];
        if ($semester_id) { $where[] = 'ca.semester_id = ?'; $params[] = $semester_id; }
        if ($lecturer_id) { $where[] = 'ca.lecturer_id = ?'; $params[] = $lecturer_id; }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT ca.*, c.code AS course_code, c.name AS course_name,
                   u.full_name AS lecturer_name, u.email AS lecturer_email,
                   s.name AS semester_name
            FROM course_assignments ca
            JOIN courses c ON c.id = ca.course_id
            JOIN users u ON u.id = ca.lecturer_id
            JOIN semesters s ON s.id = ca.semester_id
            $whereSQL
            ORDER BY c.code ASC
        ");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        break;

    // Assign lecturer to course
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['course_id']) || empty($data['lecturer_id']) || empty($data['semester_id'])) {
            http_response_code(400); echo json_encode(['error' => 'course_id, lecturer_id, semester_id required']); exit;
        }
        // Remove old assignment for this course in this semester first
        $pdo->prepare("DELETE FROM course_assignments WHERE course_id = ? AND semester_id = ?")
            ->execute([$data['course_id'], $data['semester_id']]);
        $pdo->prepare("INSERT INTO course_assignments (course_id, lecturer_id, semester_id) VALUES (?, ?, ?)")
            ->execute([$data['course_id'], $data['lecturer_id'], $data['semester_id']]);

        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, detail, ip_address) VALUES (?, 'ASSIGN_LECTURER', 'course', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $data['course_id'], json_encode(['lecturer_id' => $data['lecturer_id']]), $_SERVER['REMOTE_ADDR'] ?? null]);

        echo json_encode(['success' => true, 'message' => 'Lecturer assigned']);
        break;

    // Remove assignment
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['course_id']) || empty($data['semester_id'])) {
            http_response_code(400); echo json_encode(['error' => 'course_id and semester_id required']); exit;
        }
        $pdo->prepare("DELETE FROM course_assignments WHERE course_id = ? AND semester_id = ?")
            ->execute([$data['course_id'], $data['semester_id']]);
        echo json_encode(['success' => true, 'message' => 'Assignment removed']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
