<?php
// api/users.php — unified user management API (replaces add_student.php + edit_student.php)
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
requireRole('admin');

switch ($method) {

    // --------------------------------------------------------
    // GET /api/users.php
    // GET /api/users.php?id=1
    // GET /api/users.php?role=student&program_id=1&level=2
    // --------------------------------------------------------
    case 'GET':
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.index_no, u.email, u.role,
                       u.level, u.phone, u.is_active, u.profile_photo, u.created_at,
                       d.name AS department_name, p.name AS program_name, i.name AS institution_name
                FROM users u
                LEFT JOIN departments  d ON d.id = u.department_id
                LEFT JOIN programs     p ON p.id = u.program_id
                LEFT JOIN institutions i ON i.id = u.institution_id
                WHERE u.id = ?
            ");
            $stmt->execute([$_GET['id']]);
            $user = $stmt->fetch();
            if (!$user) { http_response_code(404); echo json_encode(['error' => 'User not found']); exit; }

            // Get enrolled courses if student/rep
            if (in_array($user['role'], ['student', 'rep'])) {
                $courses = $pdo->prepare("
                    SELECT c.code, c.name, s.name AS semester, ce.status
                    FROM course_enrollments ce
                    JOIN courses c ON c.id = ce.course_id
                    JOIN semesters s ON s.id = ce.semester_id
                    WHERE ce.student_id = ?
                    ORDER BY s.academic_year DESC, c.code ASC
                ");
                $courses->execute([$user['id']]);
                $user['enrollments'] = $courses->fetchAll();
            }

            // Get assigned courses if lecturer
            if ($user['role'] === 'lecturer') {
                $courses = $pdo->prepare("
                    SELECT c.code, c.name, s.name AS semester
                    FROM course_assignments ca
                    JOIN courses c ON c.id = ca.course_id
                    JOIN semesters s ON s.id = ca.semester_id
                    WHERE ca.lecturer_id = ?
                    ORDER BY s.academic_year DESC, c.code ASC
                ");
                $courses->execute([$user['id']]);
                $user['assignments'] = $courses->fetchAll();
            }

            echo json_encode($user);

        } else {
            $where = ['1=1']; $params = [];

            if (!empty($_GET['role']))           { $where[] = 'u.role = ?';           $params[] = $_GET['role']; }
            if (!empty($_GET['program_id']))      { $where[] = 'u.program_id = ?';     $params[] = $_GET['program_id']; }
            if (!empty($_GET['department_id']))   { $where[] = 'u.department_id = ?';  $params[] = $_GET['department_id']; }
            if (!empty($_GET['institution_id']))  { $where[] = 'u.institution_id = ?'; $params[] = $_GET['institution_id']; }
            if (!empty($_GET['level']))           { $where[] = 'u.level = ?';          $params[] = $_GET['level']; }
            if (isset($_GET['is_active']))        { $where[] = 'u.is_active = ?';      $params[] = (int)$_GET['is_active']; }
            if (!empty($_GET['search'])) {
                $where[] = '(u.full_name LIKE ? OR u.index_no LIKE ? OR u.email LIKE ?)';
                $s = '%' . $_GET['search'] . '%';
                $params[] = $s; $params[] = $s; $params[] = $s;
            }

            $whereSQL = implode(' AND ', $where);
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.index_no, u.email, u.role,
                       u.level, u.phone, u.is_active, u.created_at,
                       d.name AS department_name, p.name AS program_name
                FROM users u
                LEFT JOIN departments d ON d.id = u.department_id
                LEFT JOIN programs    p ON p.id = u.program_id
                WHERE $whereSQL
                ORDER BY u.role ASC, u.full_name ASC
            ");
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
        }
        break;

    // --------------------------------------------------------
    // POST /api/users.php — Create user (student, lecturer, rep, admin)
    // --------------------------------------------------------
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        $required = ['full_name', 'role'];
        foreach ($required as $field) {
            if (empty($data[$field])) { http_response_code(400); echo json_encode(['error' => "Field '$field' is required"]); exit; }
        }

        $allowedRoles = ['student', 'rep', 'lecturer', 'admin'];
        if (!in_array($data['role'], $allowedRoles)) {
            http_response_code(400); echo json_encode(['error' => 'Invalid role']); exit;
        }

        $full_name      = trim($data['full_name']);
        $role           = $data['role'];
        $index_no       = trim($data['index_no'] ?? '');
        $institution_id = $data['institution_id'] ?? 1;
        $department_id  = $data['department_id']  ?? null;
        $program_id     = $data['program_id']     ?? null;
        $level          = $data['level']          ?? null;
        $phone          = trim($data['phone']     ?? '');

        // Auto-generate email
        $email = trim($data['email'] ?? '');
        if (!$email) {
            $email = $index_no ? $index_no . '@citadel.edu' : strtolower(str_replace(' ', '.', $full_name)) . '@citadel.edu';
        }

        // Auto-generate password: index_no for students, 'citadel123' for staff
        $raw_password = $index_no ?: ($data['password'] ?? 'citadel123');
        $password_hash = password_hash($raw_password, PASSWORD_DEFAULT);

        // Check duplicate email
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) { http_response_code(409); echo json_encode(['error' => 'Email already exists']); exit; }

        // Check duplicate index_no if provided
        if ($index_no) {
            $check2 = $pdo->prepare("SELECT id FROM users WHERE index_no = ?");
            $check2->execute([$index_no]);
            if ($check2->fetch()) { http_response_code(409); echo json_encode(['error' => 'Index number already exists']); exit; }
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, index_no, email, password_hash, role, institution_id, department_id, program_id, level, phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$full_name, $index_no ?: null, $email, $password_hash, $role, $institution_id, $department_id, $program_id, $level, $phone ?: null]);
        $newId = $pdo->lastInsertId();

        // Auto-enroll student in all active semester courses for their program
        if (in_array($role, ['student', 'rep']) && $program_id) {
            $activeSem = $pdo->query("SELECT id FROM semesters WHERE is_active = 1 LIMIT 1")->fetch();
            if ($activeSem) {
                $courses = $pdo->prepare("SELECT id FROM courses WHERE program_id = ? AND semester_id = ?");
                $courses->execute([$program_id, $activeSem['id']]);
                $enroll = $pdo->prepare("INSERT IGNORE INTO course_enrollments (course_id, student_id, semester_id) VALUES (?, ?, ?)");
                foreach ($courses->fetchAll() as $c) {
                    $enroll->execute([$c['id'], $newId, $activeSem['id']]);
                }
            }
        }

        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, detail, ip_address) VALUES (?, 'CREATE_USER', 'user', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $newId, json_encode(['name' => $full_name, 'role' => $role]), $_SERVER['REMOTE_ADDR'] ?? null]);

        http_response_code(201);
        echo json_encode([
            'success'       => true,
            'id'            => $newId,
            'email'         => $email,
            'temp_password' => $raw_password,
            'message'       => 'User created'
        ]);
        break;

    // --------------------------------------------------------
    // PUT /api/users.php — Update user
    // --------------------------------------------------------
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) { http_response_code(400); echo json_encode(['error' => 'User ID required']); exit; }

        $check = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
        $check->execute([$data['id']]);
        $existing = $check->fetch();
        if (!$existing) { http_response_code(404); echo json_encode(['error' => 'User not found']); exit; }

        $fields = []; $values = [];
        if (!empty($data['full_name']))     { $fields[] = 'full_name = ?';     $values[] = trim($data['full_name']); }
        if (!empty($data['index_no']))      { $fields[] = 'index_no = ?';      $values[] = trim($data['index_no']); }
        if (!empty($data['email']))         { $fields[] = 'email = ?';         $values[] = trim($data['email']); }
        if (!empty($data['role']))          { $fields[] = 'role = ?';          $values[] = $data['role']; }
        if (!empty($data['department_id'])) { $fields[] = 'department_id = ?'; $values[] = (int)$data['department_id']; }
        if (!empty($data['program_id']))    { $fields[] = 'program_id = ?';    $values[] = (int)$data['program_id']; }
        if (!empty($data['level']))         { $fields[] = 'level = ?';         $values[] = (int)$data['level']; }
        if (!empty($data['phone']))         { $fields[] = 'phone = ?';         $values[] = trim($data['phone']); }
        if (isset($data['is_active']))      { $fields[] = 'is_active = ?';     $values[] = (int)$data['is_active']; }

        // Password reset
        if (!empty($data['new_password'])) {
            $fields[] = 'password_hash = ?';
            $values[] = password_hash($data['new_password'], PASSWORD_DEFAULT);
        }

        if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'No fields to update']); exit; }

        $values[] = $data['id'];
        $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);

        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address) VALUES (?, 'UPDATE_USER', 'user', ?, ?)")
            ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR'] ?? null]);

        echo json_encode(['success' => true, 'message' => 'User updated']);
        break;

    // --------------------------------------------------------
    // PATCH /api/users.php — Toggle active status
    // --------------------------------------------------------
    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) { http_response_code(400); echo json_encode(['error' => 'User ID required']); exit; }

        // Prevent deactivating yourself
        if ((int)$data['id'] === (int)$_SESSION['user_id']) {
            http_response_code(403); echo json_encode(['error' => 'Cannot deactivate your own account']); exit;
        }

        $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$data['id']]);
        $status = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
        $status->execute([$data['id']]);
        $row = $status->fetch();

        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, detail, ip_address) VALUES (?, 'TOGGLE_USER', 'user', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], $data['id'], json_encode(['is_active' => $row['is_active']]), $_SERVER['REMOTE_ADDR'] ?? null]);

        echo json_encode(['success' => true, 'is_active' => (bool)$row['is_active']]);
        break;

    // --------------------------------------------------------
    // DELETE /api/users.php — Hard delete (admin only, with safety checks)
    // --------------------------------------------------------
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id'])) { http_response_code(400); echo json_encode(['error' => 'User ID required']); exit; }

        if ((int)$data['id'] === (int)$_SESSION['user_id']) {
            http_response_code(403); echo json_encode(['error' => 'Cannot delete your own account']); exit;
        }

        // Check attendance records
        $check = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE student_id = ?");
        $check->execute([$data['id']]);
        if ($check->fetchColumn() > 0 && empty($data['force'])) {
            http_response_code(409);
            echo json_encode(['error' => 'User has attendance records. Pass force:true to confirm deletion.']);
            exit;
        }

        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$data['id']]);

        $pdo->prepare("INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address) VALUES (?, 'DELETE_USER', 'user', ?, ?)")
            ->execute([$_SESSION['user_id'], $data['id'], $_SERVER['REMOTE_ADDR'] ?? null]);

        echo json_encode(['success' => true, 'message' => 'User deleted']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
