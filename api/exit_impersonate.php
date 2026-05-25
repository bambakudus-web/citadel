<?php
session_start();
if(isset($_SESSION['super_admin_origin'])){
    $origin = $_SESSION['super_admin_origin'];
    $_SESSION['user_id'] = $origin['id'];
    $_SESSION['role'] = $origin['role'];
    $_SESSION['institution_id'] = $origin['institution_id'];
    $_SESSION['user'] = $origin;
    unset($_SESSION['super_admin_origin']);
}
header('Location: ../../pages/super_admin/schools.php');
exit;
