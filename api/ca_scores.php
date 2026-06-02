<?php
require_once '../includes/security.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
header('Content-Type: application/json');

$user = currentUser();
$role = $user['role'] ?? '';
$inst_id = (int)($_SESSION['institution_id'] ?? 1);
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: fetch scores ──
if ($method === 'GET') {
    $type    = $_GET['type'] ?? 'course';
    
    // Admin fetches all scores
    if ($type === 'all') {
        $sem_id  = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : null;
        $ca_type = $_GET['ca_type'] ?? null;
        $sql = "SELECT cs.*, u.full_name, u.index_no,
                       c.code AS course_code, c.name AS course_name,
                       l.full_name AS lecturer_name
                FROM ca_scores cs
                JOIN users u ON u.id = cs.student_id
                LEFT JOIN courses c ON c.id = cs.course_id
                LEFT JOIN users l ON l.id = cs.lecturer_id
                WHERE cs.institution_id = ?";
        $params = [$inst_id];
        if ($sem_id)  { $sql .= " AND cs.semester_id = ?";  $params[] = $sem_id; }
        if ($ca_type) { $sql .= " AND cs.ca_type = ?";      $params[] = $ca_type; }
        $sql .= " ORDER BY u.full_name ASC, cs.ca_type ASC";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        echo json_encode(['success' => true, 'scores' => $stmt->fetchAll()]);
        exit;
    }

    // Lecturer fetches scores for a course
    if ($type === 'course' && isset($_GET['course_id'])) {
        $course_id  = (int)$_GET['course_id'];
        $sem_id     = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : null;
        $ca_type    = $_GET['ca_type'] ?? null;

        $sql = "SELECT cs.*, u.full_name, u.index_no
                FROM ca_scores cs
                JOIN users u ON u.id = cs.student_id
                WHERE cs.course_id = ? AND cs.institution_id = ?";
        $params = [$course_id, $inst_id];
        if ($sem_id)  { $sql .= " AND cs.semester_id = ?";  $params[] = $sem_id; }
        if ($ca_type) { $sql .= " AND cs.ca_type = ?";      $params[] = $ca_type; }
        $sql .= " ORDER BY u.full_name ASC";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        echo json_encode(['success' => true, 'scores' => $stmt->fetchAll()]);
        exit;
    }

    // Student fetches their own scores
    if ($type === 'student') {
        $student_id = $role === 'student' ? $user['id'] : (int)($_GET['student_id'] ?? 0);
        $sem_id     = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : null;
        $sql = "SELECT cs.*, c.name AS course_name, c.code AS course_code
                FROM ca_scores cs
                JOIN courses c ON c.id = cs.course_id
                WHERE cs.student_id = ? AND cs.institution_id = ?";
        $params = [$student_id, $inst_id];
        if ($sem_id) { $sql .= " AND cs.semester_id = ?"; $params[] = $sem_id; }
        $sql .= " ORDER BY c.code ASC, cs.ca_type ASC";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        echo json_encode(['success' => true, 'scores' => $stmt->fetchAll()]);
        exit;
    }

    // Fetch enrolled students for a course (for upload form)
    if ($type === 'students' && isset($_GET['course_id'])) {
        $course_id = (int)$_GET['course_id'];
        $sem_id    = isset($_GET['semester_id']) ? (int)$_GET['semester_id'] : null;
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.index_no
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.student_id
            WHERE ce.course_id = ? AND ce.status = 'active' AND u.institution_id = ?
            ORDER BY u.full_name ASC
        ");
        $stmt->execute([$course_id, $inst_id]);
        echo json_encode(['success' => true, 'students' => $stmt->fetchAll()]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// ── POST: upload/update scores ──
if ($method === 'POST') {
    if (!in_array($role, ['lecturer','admin','school'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    $course_id  = (int)($body['course_id'] ?? 0);
    $ca_type    = trim($body['ca_type'] ?? 'CA1');
    $max_score  = (float)($body['max_score'] ?? 100);
    $semester_id = isset($body['semester_id']) ? (int)$body['semester_id'] : null;
    $scores     = $body['scores'] ?? []; // [{student_id, score, remarks}]

    if (!$course_id || empty($scores)) {
        echo json_encode(['success' => false, 'error' => 'Missing course or scores']); exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO ca_scores (course_id, student_id, lecturer_id, institution_id, ca_type, score, max_score, semester_id, remarks)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE score=VALUES(score), max_score=VALUES(max_score), remarks=VALUES(remarks), updated_at=NOW()
    ");

    $pdo->beginTransaction();
    try {
        foreach ($scores as $s) {
            $stmt->execute([
                $course_id,
                (int)$s['student_id'],
                $user['id'],
                $inst_id,
                $ca_type,
                (float)$s['score'],
                $max_score,
                $semester_id,
                $s['remarks'] ?? null,
            ]);
        }
        $pdo->commit();
        audit('CA_UPLOAD', 'course', $course_id);

        // Notify students their CA scores are available
        try {
            $courseName = $pdo->prepare("SELECT name, code FROM courses WHERE id=?");
            $courseName->execute([$course_id]); $cInfo = $courseName->fetch();
            foreach ($scores as $s) {
                $stuRow = $pdo->prepare("SELECT email, full_name FROM users WHERE id=?");
                $stuRow->execute([(int)$s['student_id']]); $stu = $stuRow->fetch();
                if ($stu && filter_var($stu['email'], FILTER_VALIDATE_EMAIL)
                    && strpos($stu['email'], '@citadel.edu') === false) {
                    $subject = "CA Score Uploaded — " . ($cInfo['code'] ?? '') . " " . $ca_type;
                    $msg = "Dear {$stu['full_name']},

Your {$ca_type} score for " .
                           ($cInfo['code'] ?? 'your course') . " — " . ($cInfo['name'] ?? '') .
                           " has been uploaded.

Score: {$s['score']} / {$body['max_score']}

" .
                           "Log in to Citadel to view your full CA report.

Citadel Academic System";
                    @mail($stu['email'], $subject, $msg,
                        "From: noreply@citadel.edu
Content-Type: text/plain; charset=UTF-8");
                }
            }
        } catch (Exception $e) { /* non-fatal */ }

        echo json_encode(['success' => true, 'saved' => count($scores)]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── DELETE: remove a score ──
if ($method === 'DELETE') {
    if (!in_array($role, ['lecturer','admin'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); exit; }
    $pdo->prepare("DELETE FROM ca_scores WHERE id=? AND institution_id=?")->execute([$id, $inst_id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Method not allowed']);
