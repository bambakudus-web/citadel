<?php
// api/mark_attendance.php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

$user   = currentUser();
$userId = $user['id'];

$input     = json_decode(file_get_contents('php://input'), true);
$sessionId = (int)($input['session_id'] ?? 0);
$selfieB64   = $input['selfie']   ?? '';
$classroomB64 = $input['classroom'] ?? '';

if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'No active session']);
    exit;
}

// Check session is still active
$session = $pdo->prepare("SELECT * FROM sessions WHERE id=? AND active_status=1");
$session->execute([$sessionId]);
$session = $session->fetch();

if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Session has ended']);
    exit;
}

// Check already submitted
$existing = $pdo->prepare("SELECT * FROM attendance WHERE session_id=? AND student_id=?");
$existing->execute([$sessionId, $userId]);
$existing = $existing->fetch();

if ($existing) {
    $msg = $existing['status'] === 'pending' 
        ? 'Your selfie is pending Rep approval' 
        : 'You have already been marked ' . $existing['status'];
    echo json_encode(['success' => false, 'message' => $msg, 'status' => $existing['status']]);
    exit;
}

// Save selfie image
$selfieUrl = '';
if (!empty($selfieB64)) {
    $selfieB64  = preg_replace('/^data:image\/\w+;base64,/', '', $selfieB64);
    $selfieData = base64_decode($selfieB64);
    $selfieDir  = '../uploads/selfies/';
    if (!is_dir($selfieDir)) mkdir($selfieDir, 0755, true);
    $selfieFile = $selfieDir . $userId . '_' . $sessionId . '_' . time() . '.jpg';
    file_put_contents($selfieFile, $selfieData);
    $selfieUrl = 'uploads/selfies/' . basename($selfieFile);
}
// Save classroom image
$classroomUrl = '';
if (!empty($classroomB64)) {
    $classroomB64  = preg_replace('/^data:image\/\w+;base64,/', '', $classroomB64);
    $classroomData = base64_decode($classroomB64);
    $classroomFile = '../uploads/selfies/' . $userId . '_' . $sessionId . '_class_' . time() . '.jpg';
    file_put_contents($classroomFile, $classroomData);
    $classroomUrl = 'uploads/selfies/' . basename($classroomFile);
}

// Insert as PENDING — Rep must approve
$stmt = $pdo->prepare("INSERT INTO attendance (session_id, student_id, status, selfie_url, classroom_url, timestamp) VALUES (?,?,'pending',?,?,NOW())");
$stmt->execute([$sessionId, $userId, $selfieUrl, $classroomUrl]);

echo json_encode([
    'success'    => true,
    'status'     => 'pending',
    'message'    => 'Selfie submitted! Waiting for Rep approval.',
    'selfie_url' => $selfieUrl
]);
