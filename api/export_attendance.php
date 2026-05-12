<?php
// api/export_attendance.php
require_once '../includes/cors.php';
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireRole('admin', 'rep');

$courseCode = $_GET['course']      ?? '';
$courseId   = (int)($_GET['course_id'] ?? 0);
$sessionId  = (int)($_GET['session_id'] ?? 0);
$semesterId = (int)($_GET['semester_id'] ?? 0);
$dateFrom   = $_GET['from'] ?? '';
$dateTo     = $_GET['to']   ?? '';
$status     = $_GET['status'] ?? '';

// Default to active semester if none specified
if (!$semesterId && !$sessionId) {
    $row = $pdo->query("SELECT id FROM semesters WHERE is_active=1 LIMIT 1")->fetch();
    $semesterId = $row['id'] ?? 0;
}

$where  = ["a.status IN ('present','late','absent')"];
$params = [];

if ($sessionId) {
    $where[]  = 'a.session_id = ?';
    $params[] = $sessionId;
} else {
    if ($semesterId) {
        $where[]  = 's.semester_id = ?';
        $params[] = $semesterId;
    }
    if ($courseId) {
        $where[]  = 's.course_id = ?';
        $params[] = $courseId;
    } elseif ($courseCode) {
        $where[]  = 's.course_code = ?';
        $params[] = $courseCode;
    }
}

if ($dateFrom) { $where[] = 'DATE(a.timestamp) >= ?'; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(a.timestamp) <= ?'; $params[] = $dateTo; }
if ($status)   { $where[] = 'a.status = ?'; $params[] = $status; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        u.index_no,
        u.full_name,
        s.course_code,
        s.course_name,
        sem.name AS semester,
        a.status,
        DATE(a.timestamp)    AS date,
        TIME(a.timestamp)    AS time
    FROM attendance a
    JOIN users u    ON a.student_id  = u.id
    JOIN sessions s ON a.session_id  = s.id
    LEFT JOIN semesters sem ON sem.id = s.semester_id
    $whereSQL
    ORDER BY s.course_code, u.full_name, a.timestamp
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'citadel_attendance_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fputcsv($out, ['Index No.', 'Full Name', 'Course Code', 'Course Name', 'Semester', 'Status', 'Date', 'Time']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['index_no'],
        $r['full_name'],
        $r['course_code'],
        $r['course_name'],
        $r['semester'] ?? '',
        strtoupper($r['status']),
        $r['date'],
        $r['time'],
    ]);
}

fclose($out);
exit;
