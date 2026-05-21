<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'student';
    $map = ['super_admin'=>'super_admin','admin'=>'admin','lecturer'=>'lecturer','rep'=>'rep','student'=>'student'];
    header('Location: pages/' . ($map[$role] ?? 'student') . '/dashboard.php');
    exit;
}
header('Location: index.php');
exit;
