<?php
// api/import_students.php — Bulk student import via CSV
require_once '../includes/cors.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');
requireRole('admin', 'rep');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (empty($_FILES['csv']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No CSV file uploaded']);
    exit;
}

// Active semester for auto-enrollment
$activeSem = $pdo->query("SELECT id FROM semesters WHERE is_active=1 LIMIT 1")->fetch();
$semId     = $activeSem['id'] ?? null;

// Default program/department/institution
$programId     = 1;
$departmentId  = 1;
$institutionId = 1;

$file    = $_FILES['csv']['tmp_name'];
$handle  = fopen($file, 'r');

if (!$handle) {
    http_response_code(400);
    echo json_encode(['error' => 'Could not read file']);
    exit;
}

$inserted = 0;
$skipped  = 0;
$errors   = [];
$row      = 0;

// Prepare statements
$insertUser = $pdo->prepare("
    INSERT INTO users (full_name, index_no, email, password_hash, role, institution_id, department_id, program_id, level)
    VALUES (?, ?, ?, ?, 'student', ?, ?, ?, 2)
");
$enroll = $pdo->prepare("
    INSERT IGNORE INTO course_enrollments (course_id, student_id, semester_id) VALUES (?, ?, ?)
");
$checkDup = $pdo->prepare("SELECT id FROM users WHERE index_no=? OR email=?");

// Get active semester courses for auto-enrollment
$courses = [];
if ($semId) {
    $cstmt = $pdo->prepare("SELECT id FROM courses WHERE semester_id=? AND program_id=?");
    $cstmt->execute([$semId, $programId]);
    $courses = array_column($cstmt->fetchAll(), 'id');
}

while (($line = fgetcsv($handle, 1000, ',')) !== false) {
    $row++;

    // Skip header row
    if ($row === 1 && !is_numeric(trim($line[0] ?? ''))) {
        continue;
    }

    // Expected columns: full_name, index_no, email (optional), role (optional)
    $fullName = trim($line[0] ?? '');
    $indexNo  = trim($line[1] ?? '');
    $email    = trim($line[2] ?? '') ?: ($indexNo . '@citadel.edu');
    $role     = in_array(trim($line[3] ?? ''), ['student','rep']) ? trim($line[3]) : 'student';

    if (!$fullName || !$indexNo) {
        $errors[] = "Row $row: Missing name or index number";
        $skipped++;
        continue;
    }

    // Check duplicate
    $checkDup->execute([$indexNo, $email]);
    if ($checkDup->fetch()) {
        $errors[] = "Row $row: $indexNo already exists — skipped";
        $skipped++;
        continue;
    }

    try {
        $hash = password_hash($indexNo, PASSWORD_DEFAULT);
        $insertUser->execute([
            $fullName, $indexNo, $email, $hash,
            $institutionId, $departmentId, $programId
        ]);
        $newId = $pdo->lastInsertId();

        // Auto-enroll in active semester courses
        foreach ($courses as $courseId) {
            $enroll->execute([$courseId, $newId, $semId]);
        }

        $inserted++;
    } catch (Exception $e) {
        $errors[] = "Row $row: $indexNo — " . $e->getMessage();
        $skipped++;
    }
}

fclose($handle);

// Log to audit
$pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, detail, ip_address) VALUES (?, 'BULK_IMPORT', 'user', ?, ?)")
    ->execute([
        $_SESSION['user_id'],
        json_encode(['inserted' => $inserted, 'skipped' => $skipped]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

echo json_encode([
    'success'  => true,
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'message'  => "$inserted students imported, $skipped skipped"
]);
