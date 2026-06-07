<?php
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/guard.php';

header('Content-Type: application/json');
requireRole('admin');
$inst_id = (int)($_SESSION['institution_id'] ?? 1);

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'])) verifyCsrf();
$data   = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE institution_id=? ORDER BY name");
    $stmt->execute([$inst_id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $name = trim($data['name'] ?? '');
    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Name required']); exit; }
    $chk = $pdo->prepare("SELECT id FROM departments WHERE name=? AND institution_id=?");
    $chk->execute([$name, $inst_id]);
    if ($chk->fetch()) { echo json_encode(['ok'=>false,'error'=>'Department already exists']); exit; }
    $pdo->prepare("INSERT INTO departments (name, institution_id) VALUES (?,?)")->execute([$name, $inst_id]);
    $newId = (int)$pdo->lastInsertId();
    audit('CREATE_DEPARTMENT', 'department', $newId);
    echo json_encode(['ok'=>true, 'id'=>$newId]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($data['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID required']); exit; }
    $pdo->prepare("DELETE FROM departments WHERE id=? AND institution_id=?")->execute([$id, $inst_id]);
    audit('DELETE_DEPARTMENT', 'department', $id);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
