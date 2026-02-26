<?php
// api/mark_attendance.php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$data      = json_decode(file_get_contents('php://input'), true);
$sessionId = (int)($data['session_id'] ?? 0);
$selfieB64 = $data['selfie'] ?? '';
$userId    = currentUser()['id'];

if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'No session specified.']);
    exit;
}

// Check session is still active
$stmt = $pdo->prepare("SELECT * FROM sessions WHERE id=? AND active_status=1");
$stmt->execute([$sessionId]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Session is no longer active.']);
    exit;
}

// Check if already marked
$check = $pdo->prepare("SELECT id FROM attendance WHERE session_id=? AND student_id=?");
$check->execute([$sessionId, $userId]);
if ($check->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Attendance already marked for this session.']);
    exit;
}

// Save selfie image
$selfieUrl = null;
if ($selfieB64) {
    $selfieData = preg_replace('/^data:image\/\w+;base64,/', '', $selfieB64);
    $selfieData = base64_decode($selfieData);
    $dir        = __DIR__ . '/../uploads/selfies/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename   = $userId . '_' . $sessionId . '_' . time() . '.jpg';
    file_put_contents($dir . $filename, $selfieData);
    $selfieUrl  = 'uploads/selfies/' . $filename;
}

// Determine status based on time
$startTime = strtotime($session['start_time']);
$now       = time();
$elapsed   = ($now - $startTime) / 60; // minutes
$status    = $elapsed <= 15 ? 'present' : 'late';

// Insert attendance record
try {
    $ins = $pdo->prepare("INSERT INTO attendance (session_id, student_id, status, selfie_url, timestamp) VALUES (?,?,?,?,NOW())");
    $ins->execute([$sessionId, $userId, $status, $selfieUrl]);
    echo json_encode([
        'success'  => true,
        'status'   => $status,
        'message'  => 'Attendance marked as ' . strtoupper($status),
        'selfie'   => $selfieUrl
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to record attendance.']);
}
