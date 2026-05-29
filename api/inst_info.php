<?php
require_once '../includes/db.php';
header('Content-Type: application/json');
$code = strtolower(trim($_GET['code'] ?? ''));
if (!$code) { echo json_encode(['ok'=>false]); exit; }
$stmt = $pdo->prepare("SELECT name, inst_type, slug FROM institutions WHERE slug=? AND is_active=1 LIMIT 1");
$stmt->execute([$code]);
$inst = $stmt->fetch();
if (!$inst) { echo json_encode(['ok'=>false,'msg'=>'Not found']); exit; }
echo json_encode(['ok'=>true, 'name'=>$inst['name'], 'inst_type'=>$inst['inst_type'], 'slug'=>$inst['slug']]);
