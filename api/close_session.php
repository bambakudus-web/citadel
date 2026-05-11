<?php
// api/close_session.php
require_once '../includes/db.php';
require_once '../includes/cors.php';
require_once '../includes/auth.php';

requireRole('rep', 'admin', 'lecturer');
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$id    = (int)($_GET['id'] ?? $input['id'] ?? 0);
if (!$id && isset($_POST['session_id'])) $id = (int)$_POST['session_id'];

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Session ID required']);
    exit;
}

// Fetch session with course info
$stmt = $pdo->prepare("
    SELECT s.*, c.id AS course_id, s.semester_id
    FROM sessions s
    LEFT JOIN courses c ON c.id = s.course_id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Session not found']);
    exit;
}

// Lecturers can only close their own sessions
if ($_SESSION['role'] === 'lecturer' && $session['lecturer_id'] !== $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You can only close your own sessions']);
    exit;
}

// Close the session
$pdo->prepare("UPDATE sessions SET active_status = 0, end_time = NOW() WHERE id = ?")
    ->execute([$id]);

// Get students already marked present or late
$marked = $pdo->prepare("
    SELECT student_id FROM attendance
    WHERE session_id = ? AND status IN ('present', 'late')
");
$marked->execute([$id]);
$markedIds = array_column($marked->fetchAll(), 'student_id');

// Drop all pending approvals — if not approved before close, they're absent
$pdo->prepare("DELETE FROM attendance WHERE session_id = ? AND status = 'pending'")
    ->execute([$id]);

// Get ONLY students enrolled in this specific course (not all students)
if ($session['course_id']) {
    $students = $pdo->prepare("
        SELECT ce.student_id
        FROM course_enrollments ce
        WHERE ce.course_id = ? AND ce.status = 'active'
    ");
    $students->execute([$session['course_id']]);
} else {
    // Fallback: no course_id set (old session) — use semester enrollments or all students
    $students = $pdo->query("SELECT id AS student_id FROM users WHERE role IN ('student','rep') AND is_active = 1");
}

$absentCount   = 0;
$insertAbsent  = $pdo->prepare("
    INSERT IGNORE INTO attendance (session_id, student_id, status, timestamp)
    VALUES (?, ?, 'absent', NOW())
");

foreach ($students->fetchAll() as $s) {
    if (!in_array($s['student_id'], $markedIds)) {
        $insertAbsent->execute([$id, $s['student_id']]);
        $absentCount++;
    }
}

// Summary counts
$summary = $pdo->prepare("
    SELECT status, COUNT(*) AS count
    FROM attendance
    WHERE session_id = ?
    GROUP BY status
");
$summary->execute([$id]);
$counts = [];
foreach ($summary->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['count'];
}

echo json_encode([
    'success'  => true,
    'message'  => 'Session closed',
    'summary'  => [
        'present' => $counts['present'] ?? 0,
        'late'    => $counts['late']    ?? 0,
        'absent'  => $counts['absent']  ?? 0,
    ]
]);
