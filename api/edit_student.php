<?php
// api/edit_student.php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

$id       = (int)($_POST['id']        ?? 0);
$fullName = trim($_POST['full_name']  ?? '');
$indexNo  = trim($_POST['index_no']   ?? '');
$email    = trim($_POST['email']      ?? '');
$role     = in_array($_POST['role'] ?? '', ['student','rep','lecturer','admin']) ? $_POST['role'] : 'student';

if ($id && $fullName && $indexNo) {
    $stmt = $pdo->prepare("UPDATE users SET full_name=?, index_no=?, email=?, role=? WHERE id=?");
    $stmt->execute([$fullName, $indexNo, $email, $role, $id]);
}

$ref = $_SERVER['HTTP_REFERER'] ?? '../pages/admin/dashboard.php';
header('Location: ' . $ref);
exit;
