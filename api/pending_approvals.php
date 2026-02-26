<?php
// api/pending_approvals.php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireRole('rep', 'admin');

$sessionId = (int)($_GET['session_id'] ?? 0);

if (!$sessionId) {
    echo json_encode(['rows' => [], 'total' => 0]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT a.id, a.student_id, a.selfie_url, a.classroom_url, a.timestamp,
           u.full_name, u.index_no
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    WHERE a.session_id=? AND a.status='pending'
    ORDER BY a.timestamp ASC
");
$stmt->execute([$sessionId]);
$rows = $stmt->fetchAll();

$result = array_map(function($r) {
    return [
        'id'         => $r['id'],
        'full_name'  => $r['full_name'],
        'index_no'   => $r['index_no'],
        'selfie_url'    => $r['selfie_url'],
        'classroom_url' => $r['classroom_url'],
        'time'       => date('H:i:s', strtotime($r['timestamp'])),
    ];
}, $rows);

echo json_encode(['rows' => $result, 'total' => count($result)]);
