<?php
// api/timetable.php — CRUD for timetable slots
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
requireRole('admin');

switch ($method) {

    case 'GET':
        $day = $_GET['day'] ?? null;
        $where = $day ? "WHERE t.day_of_week = ?" : "";
        $params = $day ? [$day] : [];
        $stmt = $pdo->prepare("
            SELECT t.*, u.full_name AS lecturer_name,
                   COALESCE(c.code, t.course_code) AS course_code,
                   COALESCE(c.name, t.course_name) AS course_name
            FROM timetable t
            LEFT JOIN users u ON u.id = t.lecturer_id
            LEFT JOIN courses c ON c.id = t.course_id
            $where
            ORDER BY FIELD(t.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday'), t.start_time
        ");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $required = ['day_of_week', 'start_time', 'end_time', 'course_code'];
        foreach ($required as $f) {
            if (empty($data[$f])) {
                http_response_code(400);
                echo json_encode(['error' => "Field '$f' is required"]);
                exit;
            }
        }
        $allowed_days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
        if (!in_array($data['day_of_week'], $allowed_days)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid day']);
            exit;
        }
        $stmt = $pdo->prepare("
            INSERT INTO timetable (course_code, course_name, day_of_week, start_time, end_time, room, lecturer_id, course_id, semester_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            strtoupper(trim($data['course_code'])),
            trim($data['course_name'] ?? ''),
            $data['day_of_week'],
            $data['start_time'],
            $data['end_time'],
            trim($data['room'] ?? ''),
            $data['lecturer_id'] ?: null,
            $data['course_id']   ?: null,
            $data['semester_id'] ?? null,
        ]);
        $newId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, detail, ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$_SESSION['user_id'], 'CREATE_TIMETABLE_SLOT', 'timetable', $newId,
                json_encode(['code' => $data['course_code'], 'day' => $data['day_of_week']]),
                $_SERVER['REMOTE_ADDR'] ?? null]);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Slot created']);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            http_response_code(400); echo json_encode(['error' => 'ID required']); exit;
        }
        $fields = []; $values = [];
        $map = [
            'day_of_week'  => 'day_of_week',
            'start_time'   => 'start_time',
            'end_time'     => 'end_time',
            'course_code'  => 'course_code',
            'course_name'  => 'course_name',
            'room'         => 'room',
            'lecturer_id'  => 'lecturer_id',
            'course_id'    => 'course_id',
        ];
        foreach ($map as $key => $col) {
            if (array_key_exists($key, $data)) {
                $fields[] = "$col = ?";
                $val = $data[$key];
                if ($key === 'course_code') $val = strtoupper(trim($val));
                $values[] = $val ?: null;
            }
        }
        if (empty($fields)) {
            http_response_code(400); echo json_encode(['error' => 'No fields to update']); exit;
        }
        $values[] = $data['id'];
        $pdo->prepare("UPDATE timetable SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);
        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address) VALUES (?,?,?,?,?)")
            ->execute([$_SESSION['user_id'], 'UPDATE_TIMETABLE_SLOT', 'timetable', $data['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
        echo json_encode(['success' => true, 'message' => 'Slot updated']);
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) {
            http_response_code(400); echo json_encode(['error' => 'ID required']); exit;
        }
        $pdo->prepare("DELETE FROM timetable WHERE id = ?")->execute([$data['id']]);
        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address) VALUES (?,?,?,?,?)")
            ->execute([$_SESSION['user_id'], 'DELETE_TIMETABLE_SLOT', 'timetable', $data['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
        echo json_encode(['success' => true, 'message' => 'Slot deleted']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
