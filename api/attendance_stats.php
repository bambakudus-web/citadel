<?php
// api/attendance_stats.php — Chart data for admin dashboard
require_once '../includes/cors.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireRole('admin', 'lecturer');
header('Content-Type: application/json');

$type   = $_GET['type']        ?? 'trend';
$semId  = (int)($_GET['semester_id'] ?? 0);

if (!$semId) {
    $row   = $pdo->query("SELECT id FROM semesters WHERE is_active=1 LIMIT 1")->fetch();
    $semId = $row['id'] ?? null;
}

switch ($type) {

    case 'trend':
        // Last 10 closed sessions — present vs absent counts
        $stmt = $pdo->prepare("
            SELECT s.id, s.course_code,
                   DATE_FORMAT(s.start_time,'%d %b') AS label,
                   COUNT(DISTINCT CASE WHEN a.status IN ('present','late') THEN a.student_id END) AS present,
                   COUNT(DISTINCT CASE WHEN a.status='absent' THEN a.student_id END) AS absent
            FROM sessions s
            LEFT JOIN attendance a ON a.session_id = s.id
            WHERE s.active_status = 0
              AND (s.semester_id = ? OR ? IS NULL)
            GROUP BY s.id
            ORDER BY s.start_time DESC
            LIMIT 10
        ");
        $stmt->execute([$semId, $semId]);
        $rows = array_reverse($stmt->fetchAll());

        echo json_encode([
            'labels'  => array_column($rows, 'label'),
            'present' => array_map('intval', array_column($rows, 'present')),
            'absent'  => array_map('intval', array_column($rows, 'absent')),
        ]);
        break;

    case 'courses':
        // Per-course attendance rate for active semester
        $stmt = $pdo->prepare("
            SELECT c.code,
                   COUNT(DISTINCT CASE WHEN a.status IN ('present','late') THEN a.student_id END) AS attended,
                   COUNT(DISTINCT ce.student_id) AS enrolled
            FROM courses c
            LEFT JOIN sessions s ON s.course_id = c.id AND s.active_status = 0
            LEFT JOIN attendance a ON a.session_id = s.id
            LEFT JOIN course_enrollments ce ON ce.course_id = c.id AND ce.status = 'active'
            WHERE c.semester_id = ?
            GROUP BY c.id
            ORDER BY c.code ASC
        ");
        $stmt->execute([$semId]);
        $rows = $stmt->fetchAll();

        $labels = [];
        $rates  = [];
        foreach ($rows as $r) {
            $labels[] = $r['code'];
            $rates[]  = $r['enrolled'] > 0 ? round(($r['attended'] / $r['enrolled']) * 100) : 0;
        }

        echo json_encode(['labels' => $labels, 'rates' => $rates]);
        break;

    case 'overview':
        // Summary stats for topbar
        $stmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT u.id) AS total_students,
                COUNT(DISTINCT s.id) AS total_sessions,
                COUNT(DISTINCT CASE WHEN a.status IN ('present','late') THEN a.id END) AS total_present,
                COUNT(DISTINCT CASE WHEN a.status='absent' THEN a.id END) AS total_absent
            FROM users u
            CROSS JOIN sessions s
            LEFT JOIN attendance a ON a.session_id = s.id AND a.student_id = u.id
            WHERE u.role IN ('student','rep')
              AND (s.semester_id = ? OR ? IS NULL)
        ");
        $stmt->execute([$semId, $semId]);
        $row = $stmt->fetch();

        $total = ($row['total_present'] + $row['total_absent']) ?: 1;
        echo json_encode([
            'total_students' => (int)$row['total_students'],
            'total_sessions' => (int)$row['total_sessions'],
            'overall_rate'   => round(($row['total_present'] / $total) * 100),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown type. Use: trend, courses, overview']);
}
