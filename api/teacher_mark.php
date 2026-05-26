<?php
// api/teacher_mark.php — Teacher manually marks student attendance (SHS/JHS/Primary)
require_once '../includes/cors.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/guard.php';
requireRole('lecturer');
header('Content-Type: application/json');

$data      = json_decode(file_get_contents('php://input'), true) ?? [];
$action    = $data['action'] ?? '';
$sessionId = (int)($data['session_id'] ?? 0);
$userId    = $_SESSION['user_id'];
$inst_id   = (int)($_SESSION['institution_id'] ?? 1);

// Get institution type
$instRow = $pdo->prepare("SELECT inst_type FROM institutions WHERE id=?");
$instRow->execute([$inst_id]); $instRow = $instRow->fetch();
$instType = $instRow['inst_type'] ?? 'university';

// Only allow for non-university types OR if explicitly enabled
if ($action === 'get_students') {
    if (!$sessionId) { echo json_encode(['ok'=>false,'msg'=>'No session']); exit; }
    
    $sess = $pdo->prepare("SELECT * FROM sessions WHERE id=? AND lecturer_id=? AND active_status=1");
    $sess->execute([$sessionId, $userId]); $sess = $sess->fetch();
    if (!$sess) { echo json_encode(['ok'=>false,'msg'=>'Session not found']); exit; }

    // Get all enrolled students for this course
    $students = $pdo->prepare("
        SELECT u.id, u.full_name, u.index_no,
               a.status, a.id AS att_id
        FROM course_enrollments ce
        JOIN users u ON u.id = ce.student_id
        LEFT JOIN attendance a ON a.session_id=? AND a.student_id=u.id
        WHERE ce.course_id=? AND ce.status='active' AND u.is_active=1
        ORDER BY u.full_name ASC
    ");
    $students->execute([$sessionId, $sess['course_id']]);
    echo json_encode(['ok'=>true, 'students'=>$students->fetchAll(), 'session'=>$sess]);
    exit;
}

if ($action === 'mark') {
    $studentId = (int)($data['student_id'] ?? 0);
    $status    = $data['status'] ?? 'present';
    if (!in_array($status, ['present','late','absent'])) { echo json_encode(['ok'=>false,'msg'=>'Invalid status']); exit; }
    if (!$sessionId || !$studentId) { echo json_encode(['ok'=>false,'msg'=>'Missing data']); exit; }

    // Upsert attendance record
    $existing = $pdo->prepare("SELECT id FROM attendance WHERE session_id=? AND student_id=?");
    $existing->execute([$sessionId, $studentId]); $existing = $existing->fetch();

    if ($existing) {
        $pdo->prepare("UPDATE attendance SET status=?, timestamp=NOW() WHERE id=?")->execute([$status, $existing['id']]);
    } else {
        $pdo->prepare("INSERT INTO attendance (session_id, student_id, status, timestamp) VALUES (?,?,?,NOW())")->execute([$sessionId, $studentId, $status]);
    }
    echo json_encode(['ok'=>true, 'status'=>$status]);
    exit;
}

if ($action === 'mark_all') {
    // Mark all unmarked students as absent
    if (!$sessionId) { echo json_encode(['ok'=>false,'msg'=>'No session']); exit; }
    $sess = $pdo->prepare("SELECT * FROM sessions WHERE id=? AND lecturer_id=?");
    $sess->execute([$sessionId, $userId]); $sess = $sess->fetch();
    if (!$sess) { echo json_encode(['ok'=>false,'msg'=>'Not your session']); exit; }

    $unmarked = $pdo->prepare("
        SELECT u.id FROM course_enrollments ce
        JOIN users u ON u.id=ce.student_id
        LEFT JOIN attendance a ON a.session_id=? AND a.student_id=u.id
        WHERE ce.course_id=? AND ce.status='active' AND a.id IS NULL
    ");
    $unmarked->execute([$sessionId, $sess['course_id']]);
    $unmarkedIds = array_column($unmarked->fetchAll(), 'id');

    foreach ($unmarkedIds as $sid) {
        $pdo->prepare("INSERT INTO attendance (session_id, student_id, status, timestamp) VALUES (?,?,'absent',NOW())")->execute([$sessionId, $sid]);
    }
    echo json_encode(['ok'=>true, 'marked'=>count($unmarkedIds)]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
