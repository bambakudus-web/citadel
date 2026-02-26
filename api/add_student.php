<?php
// api/add_student.php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

$fullName    = trim($_POST['full_name'] ?? '');
$indexNo     = trim($_POST['index_no']  ?? '');
$email       = trim($_POST['email']     ?? '') ?: ($indexNo . '@citadel.edu');
$role        = in_array($_POST['role'] ?? '', ['student','rep']) ? $_POST['role'] : 'student';
$hash        = password_hash($indexNo, PASSWORD_DEFAULT);

if ($fullName && $indexNo) {
    try {
        $stmt = $pdo->prepare("INSERT INTO users (full_name, index_no, email, password_hash, role) VALUES (?,?,?,?,?)");
        $stmt->execute([$fullName, $indexNo, $email, $hash, $role]);
    } catch (Exception $e) {}
}

header('Location: ../pages/admin/dashboard.php');
exit;
