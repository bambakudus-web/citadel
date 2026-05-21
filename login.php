<?php
// login.php — redirect to index.php which has the login modal built in
session_start();
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'student';
    $map = ['super_admin'=>'super_admin','admin'=>'admin','lecturer'=>'lecturer','rep'=>'rep','student'=>'student'];
    header('Location: pages/' . ($map[$role] ?? 'student') . '/dashboard.php');
    exit;
}
header('Location: index.php');
exit;
