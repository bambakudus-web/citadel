<?php
// api/close_session.php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $pdo->prepare("UPDATE sessions SET active_status=0, end_time=NOW() WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
