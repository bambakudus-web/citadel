<?php
// api/verify_code.php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$data       = json_decode(file_get_contents('php://input'), true);
$inputCode  = trim($data['code']      ?? '');
$sessionId  = (int)($data['session_id'] ?? 0);

if (!$inputCode || !$sessionId) {
    echo json_encode(['valid' => false, 'message' => 'Missing code or session.']);
    exit;
}

// Fetch active session
$stmt = $pdo->prepare("SELECT * FROM sessions WHERE id=? AND active_status=1");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['valid' => false, 'message' => 'Session not found or closed.']);
    exit;
}

// Generate expected TOTP-style code
// Code is derived from: secret_key + floor(time/30)
$window    = (int)floor(time() / 120);
$secretKey = $session['secret_key'];

// Allow ±1 window for network latency
$valid = false;
for ($i = -1; $i <= 1; $i++) {
    $expected = generateCode($secretKey, $window + $i);
    if (hash_equals($expected, $inputCode)) {
        $valid = true;
        break;
    }
}

echo json_encode(['valid' => $valid, 'session_id' => $sessionId]);

function generateCode(string $secret, int $window): string {
    $hash = hash_hmac('sha256', (string)$window, $secret);
    $offset = hexdec(substr($hash, -1)) & 0xf;
    $code   = (hexdec(substr($hash, $offset * 2, 8)) & 0x7fffffff) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}
