<?php
// api/export_attendance.php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireRole('admin', 'rep');

$courseCode = $_GET['course'] ?? '';
$sessionId  = (int)($_GET['session_id'] ?? 0);
$dateFrom   = $_GET['from'] ?? '';
$dateTo     = $_GET['to']   ?? '';

// Build query
$where  = ["a.status IN ('present','late','absent')"];
$params = [];

if ($courseCode) {
    $where[]  = 's.course_code = ?';
    $params[] = $courseCode;
}
if ($sessionId) {
    $where[]  = 'a.session_id = ?';
    $params[] = $sessionId;
}
if ($dateFrom) {
    $where[]  = 'DATE(a.timestamp) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where[]  = 'DATE(a.timestamp) <= ?';
    $params[] = $dateTo;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        u.index_no,
        u.full_name,
        s.course_code,
        s.course_name,
        a.status,
        DATE(a.timestamp) as date,
        TIME(a.timestamp) as time
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    JOIN sessions s ON a.session_id = s.id
    $whereSQL
    ORDER BY s.course_code, u.full_name, a.timestamp
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Output CSV
$filename = 'citadel_attendance_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, ['Index No.', 'Full Name', 'Course Code', 'Course Name', 'Status', 'Date', 'Time']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['index_no'],
        $r['full_name'],
        $r['course_code'],
        $r['course_name'],
        strtoupper($r['status']),
        $r['date'],
        $r['time'],
    ]);
}

fclose($out);
exit;
