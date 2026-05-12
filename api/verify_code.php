<?php
// api/verify_code.php
require_once '../includes/cors.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$data      = json_decode(file_get_contents('php://input'), true);
$inputCode = trim($data['code']       ?? '');
$sessionId = (int)($data['session_id'] ?? 0);
$userId    = $_SESSION['user_id'];

if (!$inputCode || !$sessionId) {
    echo json_encode(['success' => false, 'message' => 'Missing code or session.']);
    exit;
}

// Fetch active session
$stmt = $pdo->prepare("SELECT * FROM sessions WHERE id=? AND active_status=1");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Session not found or already closed.']);
    exit;
}

// Check enrollment if course_id is set
if ($session['course_id']) {
    $enrolled = $pdo->prepare("SELECT id FROM course_enrollments WHERE student_id=? AND course_id=? AND status='active'");
    $enrolled->execute([$userId, $session['course_id']]);
    if (!$enrolled->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You are not enrolled in this course.']);
        exit;
    }
}

// Check already marked
$existing = $pdo->prepare("SELECT status FROM attendance WHERE session_id=? AND student_id=?");
$existing->execute([$sessionId, $userId]);
$existing = $existing->fetch();
if ($existing && $existing['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'You have already been marked ' . $existing['status'] . '.']);
    exit;
}

// Validate TOTP code — allow ±1 window for latency
function generateCode(string $secret, int $window): string {
    $hash   = hash_hmac('sha256', (string)$window, $secret);
    $offset = hexdec(substr($hash, -1)) & 0xf;
    $code   = (hexdec(substr($hash, $offset * 2, 8)) & 0x7fffffff) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

$window = (int)floor(time() / 120);
$valid  = false;
for ($i = -1; $i <= 1; $i++) {
    if (hash_equals(generateCode($session['secret_key'], $window + $i), $inputCode)) {
        $valid = true;
        break;
    }
}

if ($valid) {
    echo json_encode(['success' => true, 'message' => 'Code verified']);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid code. Please try again.']);
}
