<?php
// api/notify.php — Email notifications for attendance events
require_once '../includes/cors.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');
requireRole('admin', 'rep', 'lecturer');

$input  = json_decode(file_get_contents('php://input'), true);
$type   = $input['type']   ?? ''; // 'approved', 'rejected', 'low_attendance', 'announcement'
$target = $input['target'] ?? ''; // student_id or 'all'

// Simple PHP mail wrapper — works on most hosts
// For production, swap sendMail() with PHPMailer or Mailgun
function sendMail(string $to, string $name, string $subject, string $body): bool {
    $from    = getenv('MAIL_FROM') ?: 'noreply@citadel.edu';
    $fromName = 'Citadel Attendance System';
    $headers  = implode("\r\n", [
        "From: $fromName <$from>",
        "Reply-To: $from",
        "Content-Type: text/html; charset=UTF-8",
        "MIME-Version: 1.0",
        "X-Mailer: Citadel/2.0"
    ]);
    return mail($to, $subject, $body, $headers);
}

function emailTemplate(string $title, string $body, string $color = '#c9a84c'): string {
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><meta name='viewport' content='width=device-width'></head>
    <body style='margin:0;padding:0;background:#060910;font-family:Arial,sans-serif'>
      <table width='100%' cellpadding='0' cellspacing='0'>
        <tr><td align='center' style='padding:40px 20px'>
          <table width='560' cellpadding='0' cellspacing='0' style='background:#0c1018;border:1px solid #1a2535;border-radius:4px;overflow:hidden'>
            <tr><td style='background:linear-gradient(135deg,#7a5f28,$color);padding:4px'></td></tr>
            <tr><td style='padding:32px 40px'>
              <div style='font-family:Georgia,serif;font-size:28px;font-weight:700;letter-spacing:4px;color:$color;margin-bottom:8px'>CITADEL</div>
              <div style='font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#6b7a8d;margin-bottom:32px'>Attendance Management System</div>
              <h2 style='font-family:Georgia,serif;font-size:18px;color:#e8eaf0;margin:0 0 16px'>$title</h2>
              <div style='font-size:14px;color:#9aa8b8;line-height:1.7'>$body</div>
            </td></tr>
            <tr><td style='padding:20px 40px;border-top:1px solid #1a2535'>
              <div style='font-size:11px;color:#4a5568'>This is an automated message from Citadel. Do not reply to this email.</div>
            </td></tr>
          </table>
        </td></tr>
      </table>
    </body>
    </html>";
}

$sent = 0; $failed = 0;

switch ($type) {

    case 'approved':
        // Notify student their attendance was approved
        $studentId = (int)($input['student_id'] ?? 0);
        $courseCode = $input['course_code'] ?? '';
        $status     = $input['status'] ?? 'present';
        if (!$studentId) { echo json_encode(['error' => 'student_id required']); exit; }
        $student = $pdo->prepare("SELECT full_name, email FROM users WHERE id=?");
        $student->execute([$studentId]); $student = $student->fetch();
        if ($student && $student['email']) {
            $color = $status === 'present' ? '#4caf82' : '#e0a050';
            $body  = emailTemplate(
                'Attendance ' . ucfirst($status),
                "Dear <strong style='color:#e8eaf0'>{$student['full_name']}</strong>,<br><br>
                Your attendance for <strong style='color:#c9a84c'>$courseCode</strong> has been marked as
                <strong style='color:$color'>" . strtoupper($status) . "</strong>.<br><br>
                Log in to Citadel to view your full attendance record.",
                $color
            );
            sendMail($student['email'], $student['full_name'], "Citadel — Attendance $status", $body) ? $sent++ : $failed++;
        }
        break;

    case 'rejected':
        $studentId  = (int)($input['student_id'] ?? 0);
        $courseCode = $input['course_code'] ?? '';
        if (!$studentId) { echo json_encode(['error' => 'student_id required']); exit; }
        $student = $pdo->prepare("SELECT full_name, email FROM users WHERE id=?");
        $student->execute([$studentId]); $student = $student->fetch();
        if ($student && $student['email']) {
            $body = emailTemplate(
                'Attendance Submission Rejected',
                "Dear <strong style='color:#e8eaf0'>{$student['full_name']}</strong>,<br><br>
                Your attendance submission for <strong style='color:#c9a84c'>$courseCode</strong> was <strong style='color:#e05c5c'>rejected</strong>.<br><br>
                Please contact your Course Rep if you believe this is an error.",
                '#e05c5c'
            );
            sendMail($student['email'], $student['full_name'], "Citadel — Attendance Rejected", $body) ? $sent++ : $failed++;
        }
        break;

    case 'low_attendance':
        // Notify all students below 75% in a course
        $courseId = (int)($input['course_id'] ?? 0);
        if (!$courseId) { echo json_encode(['error' => 'course_id required']); exit; }

        $course = $pdo->prepare("SELECT code, name FROM courses WHERE id=?");
        $course->execute([$courseId]); $course = $course->fetch();

        $students = $pdo->prepare("
            SELECT u.id, u.full_name, u.email,
                   COUNT(DISTINCT s.id) AS total,
                   SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS attended
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.student_id
            LEFT JOIN sessions s ON s.course_id = ce.course_id
            LEFT JOIN attendance a ON a.session_id = s.id AND a.student_id = u.id
            WHERE ce.course_id = ? AND ce.status = 'active'
            GROUP BY u.id
            HAVING total > 3 AND (attended / total * 100) < 75
        ");
        $students->execute([$courseId]);

        foreach ($students->fetchAll() as $s) {
            if (!$s['email']) continue;
            $pct  = $s['total'] > 0 ? round(($s['attended'] / $s['total']) * 100) : 0;
            $body = emailTemplate(
                'Low Attendance Warning',
                "Dear <strong style='color:#e8eaf0'>{$s['full_name']}</strong>,<br><br>
                Your attendance in <strong style='color:#c9a84c'>{$course['code']} — {$course['name']}</strong>
                is currently <strong style='color:#e05c5c'>$pct%</strong>, which is below the required 75%.<br><br>
                You have attended <strong>{$s['attended']}</strong> out of <strong>{$s['total']}</strong> sessions.<br><br>
                Please improve your attendance to avoid academic penalties.",
                '#e05c5c'
            );
            sendMail($s['email'], $s['full_name'], "Citadel — Low Attendance Warning: {$course['code']}", $body) ? $sent++ : $failed++;
        }
        break;

    case 'announcement':
        // Send announcement email to all enrolled students
        $message    = trim($input['message'] ?? '');
        $courseId   = (int)($input['course_id'] ?? 0);
        $senderName = $input['sender_name'] ?? 'Course Rep';
        if (!$message) { echo json_encode(['error' => 'message required']); exit; }

        if ($courseId) {
            $students = $pdo->prepare("
                SELECT u.full_name, u.email FROM course_enrollments ce
                JOIN users u ON u.id = ce.student_id
                WHERE ce.course_id = ? AND ce.status = 'active' AND u.email IS NOT NULL
            ");
            $students->execute([$courseId]);
        } else {
            $students = $pdo->query("SELECT full_name, email FROM users WHERE role IN ('student','rep') AND is_active=1 AND email IS NOT NULL");
        }

        foreach ($students->fetchAll() as $s) {
            $body = emailTemplate(
                'Class Announcement',
                "Dear <strong style='color:#e8eaf0'>{$s['full_name']}</strong>,<br><br>
                <strong style='color:#4a6fa5'>$senderName</strong> posted an announcement:<br><br>
                <blockquote style='border-left:3px solid #c9a84c;padding-left:16px;margin:16px 0;color:#e8eaf0'>$message</blockquote>",
                '#4a6fa5'
            );
            sendMail($s['email'], $s['full_name'], "Citadel — Class Announcement", $body) ? $sent++ : $failed++;
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown notification type. Use: approved, rejected, low_attendance, announcement']);
        exit;
}

echo json_encode([
    'success' => true,
    'sent'    => $sent,
    'failed'  => $failed,
    'message' => "$sent email(s) sent"
]);
