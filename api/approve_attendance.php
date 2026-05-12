<?php
// api/approve_attendance.php
require_once '../includes/cors.php';
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireRole('rep', 'admin');

$input        = json_decode(file_get_contents('php://input'), true);
$attendanceId = (int)($input['attendance_id'] ?? 0);
$action       = $input['action'] ?? '';

if (!$attendanceId || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$att = $pdo->prepare("SELECT a.*, s.start_time FROM attendance a JOIN sessions s ON a.session_id=s.id WHERE a.id=?");
$att->execute([$attendanceId]);
$att = $att->fetch();

if (!$att) {
    echo json_encode(['success' => false, 'message' => 'Record not found']);
    exit;
}

if ($action === 'approve') {
    $sessionStart = strtotime($att['start_time']);
    $submitTime   = strtotime($att['timestamp']);
    $diff         = $submitTime - $sessionStart;
    $status       = $diff <= 900 ? 'present' : 'late';
    $minutesLate  = $diff > 0 ? (int)floor($diff / 60) : 0;
    $pdo->prepare("UPDATE attendance SET status=?, minutes_late=? WHERE id=?")
        ->execute([$status, $minutesLate, $attendanceId]);
    echo json_encode(['success' => true, 'status' => $status, 'message' => 'Marked as ' . $status]);
} else {
    $pdo->prepare("DELETE FROM attendance WHERE id=?")->execute([$attendanceId]);
    echo json_encode(['success' => true, 'status' => 'rejected', 'message' => 'Attendance rejected']);
}
