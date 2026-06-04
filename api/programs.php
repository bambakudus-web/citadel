<?php
require_once '../includes/db.php';
require_once '../includes/logger.php';
require_once '../includes/auth.php';
require_once '../includes/guard.php';
requireRole('admin');
$inst_id = (int)($_SESSION['institution_id'] ?? 1);
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST') {
    $name     = trim($data['name'] ?? '');
    $code     = trim($data['code'] ?? '');
    $dept_id  = (int)($data['department_id'] ?? 0);
    $duration = (int)($data['duration_yrs'] ?? 2);
    $id       = (int)($data['id'] ?? 0);

    if (!$name || !$code) { echo json_encode(['ok'=>false,'error'=>'Name and code required']); exit; }

    // Verify dept belongs to institution
    $check = $pdo->prepare("SELECT id FROM departments WHERE id=? AND institution_id=?");
    $check->execute([$dept_id, $inst_id]);
    if (!$check->fetch()) { echo json_encode(['ok'=>false,'error'=>'Invalid department']); exit; }

    if ($id) {
        $pdo->prepare("UPDATE programs SET name=?,code=?,department_id=?,duration_yrs=? WHERE id=?")->execute([$name,$code,$dept_id,$duration,$id]);
        audit('UPDATE_PROGRAM','program',$id);
    } else {
        $pdo->prepare("INSERT INTO programs (name,code,department_id,duration_yrs) VALUES (?,?,?,?)")->execute([$name,$code,$dept_id,$duration]);
        audit('CREATE_PROGRAM','program',(int)$pdo->lastInsertId());
    }
    echo json_encode(['ok'=>true]); exit;
}

if ($method === 'DELETE') {
    $id = (int)($data['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID required']); exit; }
    $pdo->prepare("DELETE FROM programs WHERE id=?")->execute([$id]);
    audit('DELETE_PROGRAM','program',$id);
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
