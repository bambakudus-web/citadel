<?php
// api/get_code.php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$sessionId = (int)($_GET['session_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM sessions WHERE id=? AND active_status=1");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['code' => null]);
    exit;
}

$window = (int)floor(time() / 120);
$hash   = hash_hmac('sha256', (string)$window, $session['secret_key']);
$offset = hexdec(substr($hash, -1)) & 0xf;
$code   = (hexdec(substr($hash, $offset * 2, 8)) & 0x7fffffff) % 1000000;
$code   = str_pad((string)$code, 6, '0', STR_PAD_LEFT);

echo json_encode([
    'code'      => $code,
    'remaining' => 120 - (time() % 120)
]);
