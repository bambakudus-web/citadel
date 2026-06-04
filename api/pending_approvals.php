<?php
// api/pending_approvals.php
require_once '../includes/cors.php';
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireRole('rep', 'admin');
$inst_id = (int)($_SESSION['institution_id'] ?? 1);

$sessionId = (int)($_GET['session_id'] ?? 0);

if (!$sessionId) {
    echo json_encode(['rows' => [], 'total' => 0]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT a.id, a.student_id, a.selfie_url, a.classroom_url, a.timestamp,
           a.face_match_score, a.ai_confidence, a.ai_auto_approved,
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
        'id'              => $r['id'],
        'full_name'       => $r['full_name'],
        'index_no'        => $r['index_no'],
        'selfie_url'      => !empty($r['selfie_url']) ? $r['selfie_url'] : '',
        'classroom_url'   => $r['classroom_url'] ?? '',
        'time'            => date('H:i:s', strtotime($r['timestamp'])),
        'face_match_score'  => $r['face_match_score'] ?? null,
        'ai_confidence'     => $r['ai_confidence'] ?? null,
        'ai_auto_approved'  => (int)($r['ai_auto_approved'] ?? 0),
    ];
}, $rows);

echo json_encode(['rows' => $result, 'total' => count($result)]);
