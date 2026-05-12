<?php
// api/attendance_certificate.php
require_once '../includes/cors.php';
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

require_once '../vendor/autoload.php';

$currentUser = currentUser();
$targetId    = (int)($_GET['student_id'] ?? $currentUser['id']);

if ($targetId !== $currentUser['id'] && !in_array($currentUser['role'], ['admin', 'rep'])) {
    $targetId = $currentUser['id'];
}

// Student info
$stmt = $pdo->prepare("
    SELECT u.*, p.name AS program_name, d.name AS department_name, i.name AS institution_name
    FROM users u
    LEFT JOIN programs     p ON p.id = u.program_id
    LEFT JOIN departments  d ON d.id = u.department_id
    LEFT JOIN institutions i ON i.id = u.institution_id
    WHERE u.id = ?
");
$stmt->execute([$targetId]);
$student = $stmt->fetch();
if (!$student) { die('Student not found.'); }

// Active semester
$activeSem = $pdo->query("SELECT * FROM semesters WHERE is_active=1 LIMIT 1")->fetch();
$semId     = $activeSem['id'] ?? null;

// Per-course stats — scoped to enrolled courses if semester active
if ($semId) {
    $stats = $pdo->prepare("
        SELECT c.code AS course_code, c.name AS course_name,
               COUNT(DISTINCT s.id)                                           AS total,
               SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END)           AS present,
               SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END)           AS late,
               SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END)           AS absent
        FROM course_enrollments ce
        JOIN courses c  ON c.id  = ce.course_id
        JOIN sessions s ON s.course_id = c.id
        JOIN attendance a ON a.session_id = s.id AND a.student_id = ?
        WHERE ce.student_id = ? AND ce.semester_id = ? AND ce.status = 'active'
          AND a.status IN ('present','late','absent')
        GROUP BY c.id ORDER BY c.code
    ");
    $stats->execute([$targetId, $targetId, $semId]);
} else {
    // Fallback: all time
    $stats = $pdo->prepare("
        SELECT s.course_code, s.course_name,
               COUNT(DISTINCT s.id) AS total,
               SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
               SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END) AS late,
               SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent
        FROM attendance a JOIN sessions s ON a.session_id=s.id
        WHERE a.student_id=? AND a.status IN ('present','late','absent')
        GROUP BY s.course_code, s.course_name ORDER BY s.course_code
    ");
    $stats->execute([$targetId]);
}
$courses = $stats->fetchAll();

// Overall
$totalAtt = 0; $totalPresent = 0;
foreach ($courses as $c) {
    $totalAtt     += $c['total'];
    $totalPresent += $c['present'] + $c['late'];
}
$overallPct = $totalAtt > 0 ? round(($totalPresent / $totalAtt) * 100) : 0;

// Generate PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Citadel Attendance System');
$pdf->SetAuthor('Citadel');
$pdf->SetTitle('Attendance Certificate - ' . $student['full_name']);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(20, 20, 20);
$pdf->AddPage();

// Background
$pdf->SetFillColor(6, 9, 16);
$pdf->Rect(0, 0, 210, 297, 'F');

// Gold border
$pdf->SetDrawColor(201, 168, 76);
$pdf->SetLineWidth(0.8);
$pdf->Rect(10, 10, 190, 277);
$pdf->SetLineWidth(0.3);
$pdf->Rect(12, 12, 186, 273);

// Header
$pdf->SetY(25);
$pdf->SetFont('helvetica', 'B', 28);
$pdf->SetTextColor(201, 168, 76);
$pdf->Cell(0, 12, 'CITADEL', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(107, 122, 141);
$pdf->Cell(0, 6, 'ATTENDANCE MANAGEMENT SYSTEM', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 5, htmlspecialchars($student['institution_name'] ?? 'Kumasi Technical University'), 0, 1, 'C');
if ($activeSem) {
    $pdf->Cell(0, 5, htmlspecialchars($activeSem['name']), 0, 1, 'C');
}

// Divider
$pdf->SetY($pdf->GetY() + 4);
$pdf->SetDrawColor(201, 168, 76);
$pdf->SetLineWidth(0.5);
$pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());

// Certificate title
$pdf->SetY($pdf->GetY() + 6);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetTextColor(232, 234, 240);
$pdf->Cell(0, 8, 'ATTENDANCE CERTIFICATE', 0, 1, 'C');

// Student info box
$pdf->SetY($pdf->GetY() + 4);
$pdf->SetFillColor(12, 16, 24);
$pdf->SetDrawColor(26, 37, 53);
$pdf->SetLineWidth(0.3);
$pdf->RoundedRect(20, $pdf->GetY(), 170, 32, 2, '1111', 'DF');

$pdf->SetY($pdf->GetY() + 5);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->SetTextColor(201, 168, 76);
$pdf->Cell(0, 7, strtoupper($student['full_name']), 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(107, 122, 141);
$pdf->Cell(0, 5, 'Index No: ' . $student['index_no'], 0, 1, 'C');
$pdf->Cell(0, 5, htmlspecialchars($student['program_name'] ?? '') . '   |   Generated: ' . date('d M Y'), 0, 1, 'C');

// Overall badge
$pdf->SetY($pdf->GetY() + 6);
$color = $overallPct >= 75 ? [76,175,130] : ($overallPct >= 50 ? [224,160,80] : [224,92,92]);
$pdf->SetFillColor($color[0], $color[1], $color[2]);
$pdf->SetTextColor(6, 9, 16);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->RoundedRect(75, $pdf->GetY(), 60, 12, 2, '1111', 'F');
$pdf->SetY($pdf->GetY() + 3);
$pdf->Cell(0, 6, 'OVERALL: ' . $overallPct . '%', 0, 1, 'C');

// Course table
$pdf->SetY($pdf->GetY() + 8);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetTextColor(107, 122, 141);
$pdf->SetFillColor(17, 23, 34);
$pdf->SetDrawColor(26, 37, 53);

$headers = ['Course Code', 'Course Name', 'Total', 'Present', 'Late', 'Absent', 'Rate'];
$widths  = [28, 62, 15, 18, 14, 16, 17];
foreach ($headers as $i => $h) {
    $pdf->Cell($widths[$i], 8, $h, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('helvetica', '', 8);
$row = 0;
foreach ($courses as $c) {
    $attended = $c['present'] + $c['late'];
    $pct      = $c['total'] > 0 ? round(($attended / $c['total']) * 100) : 0;
    $fill     = $row % 2 === 0;
    $pdf->SetFillColor($fill ? 12 : 17, $fill ? 16 : 23, $fill ? 24 : 34);
    $pdf->SetTextColor(232, 234, 240);
    $pdf->Cell($widths[0], 7, $c['course_code'], 1, 0, 'C', true);
    $pdf->Cell($widths[1], 7, $c['course_name'], 1, 0, 'L', true);
    $pdf->Cell($widths[2], 7, $c['total'],        1, 0, 'C', true);
    $pdf->SetTextColor(76, 175, 130);
    $pdf->Cell($widths[3], 7, $c['present'],      1, 0, 'C', true);
    $pdf->SetTextColor(201, 168, 76);
    $pdf->Cell($widths[4], 7, $c['late'],         1, 0, 'C', true);
    $pdf->SetTextColor(224, 92, 92);
    $pdf->Cell($widths[5], 7, $c['absent'],       1, 0, 'C', true);
    $pctColor = $pct >= 75 ? [76,175,130] : ($pct >= 50 ? [224,160,80] : [224,92,92]);
    $pdf->SetTextColor($pctColor[0], $pctColor[1], $pctColor[2]);
    $pdf->Cell($widths[6], 7, $pct . '%',         1, 0, 'C', true);
    $pdf->Ln();
    $row++;
}

if (empty($courses)) {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->SetTextColor(107, 122, 141);
    $pdf->Cell(0, 10, 'No attendance records found for this semester.', 0, 1, 'C');
}

// Footer
$pdf->SetY($pdf->GetY() + 8);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->SetTextColor(107, 122, 141);
$pdf->Cell(0, 5, 'This certificate is automatically generated by the Citadel Attendance System.', 0, 1, 'C');
$pdf->Cell(0, 5, 'Minimum required attendance: 75% per course.', 0, 1, 'C');

// Seal
$sealY = $pdf->GetY() + 4;
$pdf->SetDrawColor(201, 168, 76);
$pdf->SetLineWidth(0.5);
$pdf->Circle(105, $sealY + 12, 14, 0, 360, 'D');
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetTextColor(201, 168, 76);
$pdf->SetY($sealY + 7);
$pdf->Cell(0, 4, 'CITADEL', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 5);
$pdf->SetTextColor(107, 122, 141);
$pdf->Cell(0, 3, 'VERIFIED', 0, 1, 'C');

$filename = 'Citadel_Certificate_' . str_replace(' ', '_', $student['full_name']) . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');
