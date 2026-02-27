<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();
$rows = $pdo->query("SELECT a.message, a.created_at, u.full_name FROM announcements a JOIN users u ON a.rep_id=u.id ORDER BY a.created_at DESC LIMIT 10")->fetchAll();
$result = array_map(function($r){
    return [
        'message'   => $r['message'],
        'full_name' => $r['full_name'],
        'time'      => date('d M H:i', strtotime($r['created_at']))
    ];
}, $rows);
echo json_encode(['rows' => $result]);
