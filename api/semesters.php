<?php
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
requireRole('admin');

$inst_id = (int)($_SESSION['institution_id'] ?? 1);
$method  = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'])) verifyCsrf();

switch ($method) {

    case 'GET':
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare("
                SELECT s.*, i.name AS institution_name
                FROM semesters s
                JOIN institutions i ON i.id = s.institution_id
                WHERE s.id = ? AND s.institution_id = ?
            ");
            $stmt->execute([$_GET['id'], $inst_id]);
            $semester = $stmt->fetch();
            if (!$semester) { http_response_code(404); echo json_encode(['error'=>'Semester not found']); exit; }
            echo json_encode($semester);
        } else {
            $stmt = $pdo->prepare("
                SELECT s.*, COUNT(DISTINCT c.id) AS course_count
                FROM semesters s
                LEFT JOIN courses c ON c.semester_id = s.id
                WHERE s.institution_id = ?
                GROUP BY s.id
                ORDER BY s.academic_year DESC, s.semester_no DESC
            ");
            $stmt->execute([$inst_id]);
            echo json_encode($stmt->fetchAll());
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        foreach (['name','academic_year','semester_no','start_date','end_date'] as $f) {
            if (empty($data[$f])) { http_response_code(400); echo json_encode(['error'=>"Field '$f' required"]); exit; }
        }
        if (!in_array((int)$data['semester_no'], [1,2,3])) {
            http_response_code(400); echo json_encode(['error'=>'semester_no must be 1, 2 or 3']); exit;
        }
        $check = $pdo->prepare("SELECT id FROM semesters WHERE institution_id=? AND academic_year=? AND semester_no=?");
        $check->execute([$inst_id, $data['academic_year'], $data['semester_no']]);
        if ($check->fetch()) { http_response_code(409); echo json_encode(['error'=>'Semester already exists for this year']); exit; }
        $stmt = $pdo->prepare("INSERT INTO semesters (institution_id,name,academic_year,semester_no,start_date,end_date,is_active) VALUES (?,?,?,?,?,?,0)");
        $stmt->execute([$inst_id, trim($data['name']), $data['academic_year'], (int)$data['semester_no'], $data['start_date'], $data['end_date']]);
        $newId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO audit_log (actor_id,action,target_type,target_id,detail,ip_address) VALUES (?,'CREATE_SEMESTER','semester',?,?,?)")
            ->execute([$_SESSION['user_id'], $newId, json_encode(['name'=>$data['name']]), $_SERVER['REMOTE_ADDR']??null]);
        http_response_code(201);
        echo json_encode(['success'=>true,'id'=>$newId,'message'=>'Semester created']);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) { http_response_code(400); echo json_encode(['error'=>'ID required']); exit; }
        $fields = []; $values = [];
        if (!empty($data['name']))          { $fields[]='name=?';          $values[]=trim($data['name']); }
        if (!empty($data['academic_year'])) { $fields[]='academic_year=?'; $values[]=$data['academic_year']; }
        if (!empty($data['semester_no']))   { $fields[]='semester_no=?';   $values[]=(int)$data['semester_no']; }
        if (!empty($data['start_date']))    { $fields[]='start_date=?';    $values[]=$data['start_date']; }
        if (!empty($data['end_date']))      { $fields[]='end_date=?';      $values[]=$data['end_date']; }
        if (empty($fields)) { http_response_code(400); echo json_encode(['error'=>'Nothing to update']); exit; }
        $values[] = $data['id']; $values[] = $inst_id;
        $pdo->prepare("UPDATE semesters SET ".implode(',',$fields)." WHERE id=? AND institution_id=?")->execute($values);
        $pdo->prepare("INSERT INTO audit_log (actor_id,action,target_type,target_id,ip_address) VALUES (?,'UPDATE_SEMESTER','semester',?,?)")
            ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR']??null]);
        echo json_encode(['success'=>true,'message'=>'Semester updated']);
        break;

    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id']) || empty($data['action'])) {
            http_response_code(400); echo json_encode(['error'=>'id and action required']); exit;
        }
        if ($data['action'] === 'set_active') {
            // Close all active sessions for this institution before switching
            $pdo->prepare("
                UPDATE sessions s JOIN users u ON u.id=s.lecturer_id
                SET s.active_status=0, s.end_time=NOW()
                WHERE s.active_status=1 AND u.institution_id=?
            ")->execute([$inst_id]);
            // Deactivate all semesters for this institution
            $pdo->prepare("UPDATE semesters SET is_active=0 WHERE institution_id=?")->execute([$inst_id]);
            // Activate the selected one
            $pdo->prepare("UPDATE semesters SET is_active=1 WHERE id=? AND institution_id=?")->execute([$data['id'], $inst_id]);
            $pdo->prepare("INSERT INTO audit_log (actor_id,action,target_type,target_id,ip_address) VALUES (?,'SET_ACTIVE_SEMESTER','semester',?,?)")
                ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR']??null]);
            echo json_encode(['success'=>true,'message'=>'Active semester updated. Old sessions closed.']);
        } else {
            http_response_code(400); echo json_encode(['error'=>'Unknown action']);
        }
        break;

    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) { http_response_code(400); echo json_encode(['error'=>'ID required']); exit; }
        $active = $pdo->prepare("SELECT is_active FROM semesters WHERE id=? AND institution_id=?");
        $active->execute([$data['id'], $inst_id]); $row = $active->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        if ($row['is_active']) { http_response_code(409); echo json_encode(['error'=>'Cannot delete the active semester']); exit; }
        $hasAttendance = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN sessions s ON s.id=a.session_id WHERE s.semester_id=?");
        $hasAttendance->execute([$data['id']]);
        if ($hasAttendance->fetchColumn() > 0) {
            http_response_code(409); echo json_encode(['error'=>'Cannot delete semester with attendance records']); exit;
        }
        $pdo->prepare("DELETE FROM semesters WHERE id=? AND institution_id=?")->execute([$data['id'], $inst_id]);
        $pdo->prepare("INSERT INTO audit_log (actor_id,action,target_type,target_id,ip_address) VALUES (?,'DELETE_SEMESTER','semester',?,?)")
            ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR']??null]);
        echo json_encode(['success'=>true,'message'=>'Semester deleted']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error'=>'Method not allowed']);
}
