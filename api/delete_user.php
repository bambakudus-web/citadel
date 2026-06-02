<?php
// api/delete_user.php
require_once '../includes/cors.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireRole('admin');
$inst_id = (int)($_SESSION['institution_id'] ?? 1);

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $pdo->prepare("DELETE FROM users WHERE id=? AND role NOT IN ('admin') AND institution_id=?")->execute([$id, $inst_id]);
}
header('Location: ../pages/admin/dashboard.php');
exit;
