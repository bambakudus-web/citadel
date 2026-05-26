<?php
// api/mark_attendance.php
require_once '../includes/cors.php';
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

requireLogin();

$user   = currentUser();
$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'];

$input        = json_decode(file_get_contents('php://input'), true);
$sessionId    = (int)($input['session_id'] ?? 0);
$selfieB64    = $input['selfie']    ?? '';
$classroomB64 = $input['classroom'] ?? '';

if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'No session ID provided']);
    exit;
}

// Fetch active session with course info
$stmt = $pdo->prepare("
    SELECT s.*, c.id AS course_id, c.code AS course_code, c.name AS course_name,
           sem.id AS semester_id
    FROM sessions s
    LEFT JOIN courses   c   ON c.id   = s.course_id
    LEFT JOIN semesters sem ON sem.id = s.semester_id
    WHERE s.id = ? AND s.active_status = 1
");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Session has ended or does not exist']);
    exit;
}

// Verify student is enrolled in this course
if (in_array($role, ['student', 'rep']) && $session['course_id']) {
    $enrolled = $pdo->prepare("
        SELECT id FROM course_enrollments
        WHERE student_id = ? AND course_id = ? AND status = 'active'
    ");
    $enrolled->execute([$userId, $session['course_id']]);
    if (!$enrolled->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You are not enrolled in this course']);
        exit;
    }
}

// Check already submitted
$existing = $pdo->prepare("SELECT * FROM attendance WHERE session_id = ? AND student_id = ?");
$existing->execute([$sessionId, $userId]);
$existing = $existing->fetch();
if ($existing) {
    $msg = $existing['status'] === 'pending'
        ? 'Your selfie is pending Rep approval'
        : 'You have already been marked ' . $existing['status'];
    echo json_encode(['success' => false, 'message' => $msg, 'status' => $existing['status']]);
    exit;
}

// Store base64 images directly in DB (Railway has no persistent filesystem)
$selfieUrl    = !empty($selfieB64)    ? $selfieB64    : '';
$classroomUrl = !empty($classroomB64) ? $classroomB64 : '';

// Check if attendance table has classroom_url column, insert accordingly
try {
    $stmt = $pdo->prepare("
        INSERT INTO attendance (session_id, student_id, status, selfie_url, classroom_url, timestamp)
        VALUES (?, ?, 'pending', ?, ?, NOW())
    ");
    $stmt->execute([$sessionId, $userId, $selfieUrl, $classroomUrl]);
} catch (\PDOException $e) {
    // classroom_url column may not exist in older installs — fallback
    if (strpos($e->getMessage(), 'classroom_url') !== false) {
        $stmt = $pdo->prepare("
            INSERT INTO attendance (session_id, student_id, status, selfie_url, timestamp)
            VALUES (?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([$sessionId, $userId, $selfieUrl]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to record attendance']);
        exit;
    }
}

echo json_encode([
    'success'      => true,
    'status'       => 'pending',
    'message'      => 'Selfie submitted! Waiting for Rep approval.',
    'selfie_url'   => !empty($selfieUrl) ? 'data:image/jpeg;base64,' . $selfieUrl : '',
    'course_code'  => $session['course_code'] ?? null,
    'course_name'  => $session['course_name'] ?? null,
]);
