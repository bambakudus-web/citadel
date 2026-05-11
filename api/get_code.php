<?php
// api/get_code.php
require_once '../includes/cors.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

requireLogin();
header('Content-Type: application/json');

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) {
    echo json_encode(['code' => null, 'message' => 'No session ID provided']);
    exit;
}

// Fetch session with course info
$stmt = $pdo->prepare("
    SELECT s.*, c.name AS course_name, c.code AS course_code,
           c.id AS course_id, sem.name AS semester_name
    FROM sessions s
    LEFT JOIN courses  c   ON c.id   = s.course_id
    LEFT JOIN semesters sem ON sem.id = s.semester_id
    WHERE s.id = ? AND s.active_status = 1
");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['code' => null, 'message' => 'Session not found or already closed']);
    exit;
}

// Lecturers and admins can always get the code
// Students/reps must be enrolled in the course
$role = $_SESSION['role'] ?? '';
if (in_array($role, ['student', 'rep']) && $session['course_id']) {
    $enrolled = $pdo->prepare("
        SELECT id FROM course_enrollments
        WHERE student_id = ? AND course_id = ? AND status = 'active'
    ");
    $enrolled->execute([$_SESSION['user_id'], $session['course_id']]);
    if (!$enrolled->fetch()) {
        echo json_encode(['code' => null, 'message' => 'You are not enrolled in this course']);
        exit;
    }
}

// Generate TOTP-style rolling code (rotates every 120 seconds)
$window = (int)floor(time() / 120);
$hash   = hash_hmac('sha256', (string)$window, $session['secret_key']);
$offset = hexdec(substr($hash, -1)) & 0xf;
$code   = (hexdec(substr($hash, $offset * 2, 8)) & 0x7fffffff) % 1000000;
$code   = str_pad((string)$code, 6, '0', STR_PAD_LEFT);

echo json_encode([
    'code'        => $code,
    'remaining'   => 120 - (time() % 120),
    'course_name' => $session['course_name'] ?? $session['course_name'],
    'course_code' => $session['course_code'] ?? $session['course_code'],
    'semester'    => $session['semester_name'] ?? null,
]);
