<?php
// api/add_student.php
require_once '../includes/cors.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/brevo_mail.php';
requireLogin();
$inst_id = (int)($_SESSION['institution_id'] ?? 1);

$fullName    = trim($_POST['full_name'] ?? '');
$indexNo     = trim($_POST['index_no']  ?? '');
$email       = trim($_POST['email']     ?? '') ?: ($indexNo . '@citadel.edu');
$role        = in_array($_POST['role'] ?? '', ['student','rep']) ? $_POST['role'] : 'student';
$hash        = password_hash($indexNo, PASSWORD_DEFAULT);

if ($fullName && $indexNo) {
    try {
        $stmt = $pdo->prepare("INSERT INTO users (full_name, index_no, email, password_hash, role, institution_id) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$fullName, $indexNo, $email, $hash, $role, $inst_id]);
$newId = $pdo->lastInsertId();
// Send welcome email — default password is index number
if ($email) {
    $instRow = $pdo->prepare("SELECT name FROM institutions WHERE id=? LIMIT 1");
    $instRow->execute([$inst_id]);
    $instName = $instRow->fetchColumn() ?: 'Citadel';
    sendWelcomeEmail($email, $fullName, $indexNo ?: 'N/A', $indexNo ?: 'your index number', $instName);
}
    } catch (Exception $e) {}
}

header('Location: ../pages/admin/dashboard.php');
exit;
