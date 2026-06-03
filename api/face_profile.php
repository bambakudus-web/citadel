<?php
// api/face_profile.php — Save and retrieve face descriptors
require_once '../includes/cors.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/security.php';
require_once '../includes/logger.php';
header('Content-Type: application/json');
requireLogin();

$user   = currentUser();
$userId = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: fetch enrolled face descriptor ──
if ($method === 'GET') {
    $row = $pdo->prepare("SELECT face_profile, face_enrolled_at FROM users WHERE id=?");
    $row->execute([$userId]);
    $row = $row->fetch();
    echo json_encode([
        'enrolled'    => !empty($row['face_profile']),
        'enrolled_at' => $row['face_enrolled_at'] ?? null,
        'descriptor'  => $row['face_profile'] ? json_decode($row['face_profile']) : null,
    ]);
    exit;
}

// ── POST: save face descriptor ──
if ($method === 'POST') {
    $body       = json_decode(file_get_contents('php://input'), true);
    $descriptor = $body['descriptor'] ?? null; // Float32Array as plain array
    $sampleB64  = $body['sample_image'] ?? '';  // Optional enrollment photo

    if (!$descriptor || !is_array($descriptor) || count($descriptor) !== 128) {
        echo json_encode(['success' => false, 'error' => 'Invalid face descriptor — must be 128-element array']);
        exit;
    }

    // Validate all values are numbers
    foreach ($descriptor as $v) {
        if (!is_numeric($v)) {
            echo json_encode(['success' => false, 'error' => 'Invalid descriptor values']);
            exit;
        }
    }

    $pdo->prepare("UPDATE users SET face_profile=?, face_enrolled_at=NOW() WHERE id=?")
        ->execute([json_encode($descriptor), $userId]);

    audit('FACE_ENROLL', 'user', $userId);

    echo json_encode(['success' => true, 'message' => 'Face enrolled successfully']);
    exit;
}

// ── DELETE: remove face profile ──
if ($method === 'DELETE') {
    $pdo->prepare("UPDATE users SET face_profile=NULL, face_enrolled_at=NULL WHERE id=?")
        ->execute([$userId]);
    audit('FACE_RESET', 'user', $userId);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Method not allowed']);
