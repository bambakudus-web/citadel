<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

$user = currentUser();
$userId = $user['id'];

$session = $pdo->query("SELECT id, course_code, course_name FROM sessions WHERE active_status=1 LIMIT 1")->fetch();

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
