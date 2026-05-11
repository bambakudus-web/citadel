<?php
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

requireRole('admin');

switch ($method) {

    case 'GET':
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare("
                SELECT s.*, i.name AS institution_name
                FROM semesters s
                JOIN institutions i ON i.id = s.institution_id
                WHERE s.id = ?
            ");
            $stmt->execute([$_GET['id']]);
            $semester = $stmt->fetch();
            if (!$semester) { http_response_code(404); echo json_encode(['error' => 'Semester not found']); exit; }
            echo json_encode($semester);
        } else {
            $stmt = $pdo->prepare("
                SELECT s.*, i.name AS institution_name,
                       COUNT(DISTINCT c.id) AS course_count
                FROM semesters s
                JOIN institutions i ON i.id = s.institution_id
                LEFT JOIN courses c ON c.semester_id = s.id
                GROUP BY s.id
                ORDER BY s.academic_year DESC, s.semester_no DESC
            ");
            $stmt->execute();
            echo json_encode($stmt->fetchAll());
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $required = ['name', 'academic_year', 'semester_no', 'start_date', 'end_date'];
        foreach ($required as $field) {
            if (empty($data[$field])) { http_response_code(400); echo json_encode(['error' => "Field '$field' is required"]); exit; }
        }
        $institution_id = $data['institution_id'] ?? 1;
        if (!in_array((int)$data['semester_no'], [1, 2])) { http_response_code(400); echo json_encode(['error' => 'semester_no must be 1 or 2']); exit; }
        $check = $pdo->prepare("SELECT id FROM semesters WHERE institution_id = ? AND academic_year = ? AND semester_no = ?");
        $check->execute([$institution_id, $data['academic_year'], $data['semester_no']]);
        if ($check->fetch()) { http_response_code(409); echo json_encode(['error' => 'Semester already exists for this academic year']); exit; }
        $stmt = $pdo->prepare("INSERT INTO semesters (institution_id, name, academic_year, semester_no, start_date, end_date, is_active) VALUES (?, ?, ?, ?, ?, ?, 0)");
        $stmt->execute([$institution_id, trim($data['name']), $data['academic_year'], (int)$data['semester_no'], $data['start_date'], $data['end_date']]);
        $newId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, detail, ip_address) VALUES (?, 'CREATE_SEMESTER', 'semester', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $newId, json_encode(['name' => $data['name']]), $_SERVER['REMOTE_ADDR'] ?? null]);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Semester created']);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) { http_response_code(400); echo json_encode(['error' => 'Semester ID required']); exit; }
        $check = $pdo->prepare("SELECT id FROM semesters WHERE id = ?");
        $check->execute([$data['id']]);
        if (!$check->fetch()) { http_response_code(404); echo json_encode(['error' => 'Semester not found']); exit; }
        $fields = []; $values = [];
        if (!empty($data['name']))          { $fields[] = 'name = ?';          $values[] = trim($data['name']); }
        if (!empty($data['academic_year'])) { $fields[] = 'academic_year = ?'; $values[] = $data['academic_year']; }
        if (!empty($data['semester_no']))   { $fields[] = 'semester_no = ?';   $values[] = (int)$data['semester_no']; }
        if (!empty($data['start_date']))    { $fields[] = 'start_date = ?';    $values[] = $data['start_date']; }
        if (!empty($data['end_date']))      { $fields[] = 'end_date = ?';      $values[] = $data['end_date']; }
        if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'No fields to update']); exit; }
        $values[] = $data['id'];
        $pdo->prepare("UPDATE semesters SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);
        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address) VALUES (?, 'UPDATE_SEMESTER', 'semester', ?, ?)")
            ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
        echo json_encode(['success' => true, 'message' => 'Semester updated']);
        break;

    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id']) || empty($data['action'])) { http_response_code(400); echo json_encode(['error' => 'id and action required']); exit; }
        if ($data['action'] === 'set_active') {
            $pdo->prepare("UPDATE semesters SET is_active = 0 WHERE institution_id = (SELECT institution_id FROM semesters WHERE id = ?)")->execute([$data['id']]);
            $pdo->prepare("UPDATE semesters SET is_active = 1 WHERE id = ?")->execute([$data['id']]);
            $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address) VALUES (?, 'SET_ACTIVE_SEMESTER', 'semester', ?, ?)")
                ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
            echo json_encode(['success' => true, 'message' => 'Active semester updated']);
        } else {
            http_response_code(400); echo json_encode(['error' => 'Unknown action']);
        }
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) { http_response_code(400); echo json_encode(['error' => 'Semester ID required']); exit; }
        $check = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN sessions s ON s.id = a.session_id WHERE s.semester_id = ?");
        $check->execute([$data['id']]);
        if ($check->fetchColumn() > 0) { http_response_code(409); echo json_encode(['error' => 'Cannot delete semester with existing attendance records']); exit; }
        $active = $pdo->prepare("SELECT is_active FROM semesters WHERE id = ?");
        $active->execute([$data['id']]);
        $row = $active->fetch();
        if ($row && $row['is_active']) { http_response_code(409); echo json_encode(['error' => 'Cannot delete the currently active semester']); exit; }
        $pdo->prepare("DELETE FROM semesters WHERE id = ?")->execute([$data['id']]);
        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address) VALUES (?, 'DELETE_SEMESTER', 'semester', ?, ?)")
            ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
        echo json_encode(['success' => true, 'message' => 'Semester deleted']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
