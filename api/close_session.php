<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireRole('rep', 'admin', 'lecturer');
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$id    = (int)($_GET['id'] ?? $input['id'] ?? 0);
if (!$id && isset($_POST['session_id'])) $id = (int)$_POST['session_id'];

if (!$id) { echo json_encode(['success'=>false]); exit; }

$pdo->prepare("UPDATE sessions SET active_status=0, end_time=NOW() WHERE id=?")->execute([$id]);

$marked = $pdo->prepare("SELECT student_id FROM attendance WHERE session_id=? AND status IN ('present','late')");
$marked->execute([$id]);
$markedIds = array_column($marked->fetchAll(), 'student_id');

$pdo->prepare("DELETE FROM attendance WHERE session_id=? AND status='pending'")->execute([$id]);

$students = $pdo->query("SELECT id FROM users WHERE role IN ('student','rep')")->fetchAll();
foreach ($students as $s) {
    if (!in_array($s['id'], $markedIds)) {
        try {
            $pdo->prepare("INSERT INTO attendance (session_id, student_id, status, timestamp) VALUES (?,?,'absent',NOW())")->execute([$id, $s['id']]);
        } catch (Exception $e) {}
    }
}

echo json_encode(['success'=>true]);
