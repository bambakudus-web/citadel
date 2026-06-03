<?php
// api/mark_attendance.php
require_once '../includes/cors.php';
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/logger.php';
requireLogin();

$user      = currentUser();
$userId    = $_SESSION['user_id'];
$role      = $_SESSION['role'];
$input     = json_decode(file_get_contents('php://input'), true);
$sessionId = (int)($input['session_id'] ?? 0);
$selfieB64 = $input['selfie']           ?? '';
$matchScore     = isset($input['face_match_score'])  ? (float)$input['face_match_score']  : null;
$aiConfidence   = isset($input['ai_confidence'])     ? (float)$input['ai_confidence']     : null;
$livenessPass   = $input['liveness_pass']            ?? false;
$enrolling      = $input['enrolling']                ?? false; // true = first time, enrolling face

if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'No session ID provided']);
    exit;
}

// Fetch active session
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

// Check enrollment
if (in_array($role, ['student', 'rep']) && $session['course_id']) {
    $enrolled = $pdo->prepare("SELECT id FROM course_enrollments WHERE student_id=? AND course_id=? AND status='active'");
    $enrolled->execute([$userId, $session['course_id']]);
    if (!$enrolled->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You are not enrolled in this course']);
        exit;
    }
}

// Check already submitted
$existing = $pdo->prepare("SELECT * FROM attendance WHERE session_id=? AND student_id=?");
$existing->execute([$sessionId, $userId]);
$existing = $existing->fetch();
if ($existing) {
    $msg = $existing['status'] === 'pending'
        ? 'Your attendance is pending review'
        : 'You have already been marked ' . $existing['status'];
    echo json_encode(['success' => false, 'message' => $msg, 'status' => $existing['status']]);
    exit;
}

// ── Determine status based on AI confidence ──
// Auto-approve if: face match score is good AND liveness passed
$AUTO_APPROVE_THRESHOLD = 85.0; // % confidence needed for auto-approval
$autoApproved = false;
$status       = 'pending'; // default — goes to rep queue

if ($matchScore !== null && $livenessPass) {
    if ($matchScore >= $AUTO_APPROVE_THRESHOLD) {
        // High confidence — auto approve
        $sessionStart = strtotime($session['start_time']);
        $now          = time();
        $diff         = $now - $sessionStart;
        $status       = $diff <= 900 ? 'present' : 'late';
        $autoApproved = true;
    } elseif ($matchScore >= 60) {
        // Medium confidence — send to rep with flag
        $status = 'pending';
    } else {
        // Low confidence — reject outright
        echo json_encode([
            'success' => false,
            'message' => 'Face verification failed. Your face does not match your registered profile. Contact admin if this is an error.',
            'face_match_score' => $matchScore,
        ]);
        audit('FACE_MISMATCH', 'attendance', 0);
        exit;
    }
} elseif ($matchScore === null) {
    // No face profile yet — enrolling for first time
    $status = 'pending';
}

// Calculate minutes late
$minutesLate = 0;
if ($status === 'late') {
    $sessionStart = strtotime($session['start_time']);
    $minutesLate  = (int)floor((time() - $sessionStart) / 60);
}

// Insert attendance record
try {
    $stmt = $pdo->prepare("
        INSERT INTO attendance 
        (session_id, student_id, status, selfie_url, minutes_late, ai_confidence, ai_auto_approved, face_match_score, timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $sessionId,
        $userId,
        $status,
        $selfieB64,
        $minutesLate,
        $aiConfidence,
        $autoApproved ? 1 : 0,
        $matchScore,
    ]);
    $attId = $pdo->lastInsertId();
} catch (\PDOException $e) {
    logError('MARK_ATTENDANCE', $e);
    echo json_encode(['success' => false, 'message' => 'Failed to record attendance: ' . $e->getMessage()]);
    exit;
}

// If enrolling face for first time, save descriptor
if ($enrolling && !empty($input['face_descriptor'])) {
    $descriptor = $input['face_descriptor'];
    if (is_array($descriptor) && count($descriptor) === 128) {
        $pdo->prepare("UPDATE users SET face_profile=?, face_enrolled_at=NOW() WHERE id=?")
            ->execute([json_encode($descriptor), $userId]);
        audit('FACE_ENROLL', 'user', $userId);
    }
}

audit('ATTENDANCE_' . strtoupper($status), 'attendance', $attId);

$message = match($status) {
    'present' => $autoApproved ? '✅ Verified & marked Present automatically!' : 'Marked Present',
    'late'    => $autoApproved ? '⚠️ Verified & marked Late automatically' : 'Marked Late',
    default   => 'Selfie submitted — awaiting review by Course Rep',
};

echo json_encode([
    'success'          => true,
    'status'           => $status,
    'auto_approved'    => $autoApproved,
    'face_match_score' => $matchScore,
    'message'          => $message,
    'course_code'      => $session['course_code'] ?? null,
    'course_name'      => $session['course_name'] ?? null,
]);
