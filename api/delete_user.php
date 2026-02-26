<?php
// api/delete_user.php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireRole('admin');

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $pdo->prepare("DELETE FROM users WHERE id=? AND role NOT IN ('admin')")->execute([$id]);
}
header('Location: ../pages/admin/dashboard.php');
exit;
