<?php
header('Content-Type: application/json');
require_once '../includes/cors.php';
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();
$inst_id = (int)($_SESSION['institution_id'] ?? 1);
$user   = currentUser();
$userId = $user['id'];
$__q = $pdo->prepare("SELECT s.id, s.course_code FROM sessions s JOIN users u ON u.id=s.lecturer_id WHERE s.active_status=1 AND u.institution_id=? LIMIT 1"); $__q->execute([$inst_id]); $session = $__q->fetch();
$myRecord = null;
if ($session) {
    $chk = $pdo->prepare("SELECT status FROM attendance WHERE session_id=? AND student_id=?");
    $chk->execute([$session['id'], $userId]);
    $myRecord = $chk->fetch();
}
echo json_encode([
    'active'      => $session ? true : false,
    'session_id'  => $session['id'] ?? null,
    'course_code' => $session['course_code'] ?? null,
    'my_status'   => $myRecord['status'] ?? null,
]);
