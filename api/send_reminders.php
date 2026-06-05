<?php
// api/send_reminders.php — called every minute by cron-job.org
$key = $_GET['key'] ?? '';
if ($key !== 'citadel_cron_remind_2026') { http_response_code(403); die('No'); }

require_once '../includes/db.php';
require_once '../includes/brevo_mail.php';

$now        = new DateTime();
$target     = (clone $now)->modify('+20 minutes');
$dayOfWeek  = $now->format('l'); // Monday, Tuesday...
$targetTime = $target->format('H:i:s');
// Window: classes starting between +19min and +21min
$from = (clone $target)->modify('-1 minute')->format('H:i:s');
$to   = (clone $target)->modify('+1 minute')->format('H:i:s');

// Find timetable slots starting in ~20 mins today
$stmt = $pdo->prepare("
    SELECT t.*, c.id AS cid, c.name AS cname, c.code AS ccode,
           u.full_name AS lecturer_name, i.id AS inst_id
    FROM timetable t
    JOIN courses c ON c.id = t.course_id
    JOIN users u ON u.id = t.lecturer_id
    JOIN institutions i ON i.id = u.institution_id
    WHERE t.day_of_week = ?
    AND t.start_time BETWEEN ? AND ?
    AND i.is_active = 1
");
$stmt->execute([$dayOfWeek, $from, $to]);
$slots = $stmt->fetchAll();

if (empty($slots)) { echo "No classes in 20 mins."; exit; }

$sent = 0; $skipped = 0;

foreach ($slots as $slot) {
    // Get enrolled students with valid emails
    $students = $pdo->prepare("
        SELECT u.id, u.full_name, u.email
        FROM course_enrollments ce
        JOIN users u ON u.id = ce.student_id
        WHERE ce.course_id = ? AND ce.status = 'active'
        AND u.email IS NOT NULL AND u.email != ''
        AND u.is_active = 1
    ");
    $students->execute([$slot['cid']]);
    $students = $students->fetchAll();

    $startFmt = date('g:i A', strtotime($slot['start_time']));
    $room     = $slot['room'] ? ' · Room ' . $slot['room'] : '';

    foreach ($students as $student) {
        if (!filter_var($student['email'], FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }

        // Check not already sent in last 30 mins (avoid duplicates)
        $dup = $pdo->prepare("SELECT id FROM audit_log WHERE actor_id=? AND action='REMINDER_SENT' AND entity_type=? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        $dup->execute([$student['id'], 'timetable_' . $slot['id']]);
        if ($dup->fetch()) { $skipped++; continue; }

        $subject = "Class in 20 mins — {$slot['ccode']}";
        $html = emailTemplate("Class Reminder", '
            <h2 style="color:#e8eaf0;font-size:20px;margin:0 0 12px">&#9200; Class Starting Soon</h2>
            <p style="color:#6b7a8d;font-size:14px;line-height:1.6;margin:0 0 20px">Hi <strong style="color:#e8eaf0">' . htmlspecialchars($student['full_name']) . '</strong>, you have a class starting in <strong style="color:#c9a84c">20 minutes</strong>.</p>
            <div style="background:#060910;border:1px solid #1a2535;border-left:3px solid #c9a84c;border-radius:2px;padding:16px 20px;margin-bottom:20px">
              <div style="font-size:11px;color:#6b7a8d;letter-spacing:2px;text-transform:uppercase">' . htmlspecialchars($slot['ccode']) . '</div>
              <div style="font-size:16px;color:#e8eaf0;margin-top:4px">' . htmlspecialchars($slot['cname']) . '</div>
              <div style="font-size:13px;color:#6b7a8d;margin-top:6px">&#128336; ' . $startFmt . $room . '</div>
              <div style="font-size:13px;color:#6b7a8d;margin-top:4px">&#128106; ' . htmlspecialchars($slot['lecturer_name']) . '</div>
            </div>
            <p style="color:#6b7a8d;font-size:12px;margin:0">Open Citadel and be ready to mark your attendance when the session starts.</p>
        ', 'Class reminder · Citadel');

        $result = sendBrevoEmail($student['email'], $student['full_name'], $subject, $html);
        if ($result['success']) {
            $sent++;
            // Log to prevent duplicates
            $pdo->prepare("INSERT INTO audit_log (actor_id, action, entity_type, entity_id, created_at) VALUES (?, 'REMINDER_SENT', ?, ?, NOW())")
                ->execute([$student['id'], 'timetable_' . $slot['id'], $slot['id']]);
        } else { $skipped++; }
    }
}

echo "Done. Sent: $sent, Skipped: $skipped, Slots: " . count($slots);
