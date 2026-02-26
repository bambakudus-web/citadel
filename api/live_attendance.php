<?php
// api/live_attendance.php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) { echo json_encode(['rows' => [], 'total' => 0]); exit; }

$stmt = $pdo->prepare("
    SELECT u.full_name, u.index_no, a.status,
           DATE_FORMAT(a.timestamp, '%H:%i:%s') as time
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    WHERE a.session_id = ?
    ORDER BY a.timestamp DESC
");
$stmt->execute([$sessionId]);
$rows = $stmt->fetchAll();

echo json_encode([
    'rows'  => $rows,
    'total' => count($rows)
]);
