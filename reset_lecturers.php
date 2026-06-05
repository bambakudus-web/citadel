<?php
$key = $_GET['key'] ?? '';
if ($key !== 'citadel_reset_lec_2026') { http_response_code(403); die('No'); }
require_once 'includes/db.php';
$hash = '$2y$10$pqu3wDFTVeCzGl2Rnq7ORe3E8qp8yuTsul/1WeZQHh0s/8xZVtOoe';
$stmt = $pdo->prepare("UPDATE users SET password_hash=? WHERE role='lecturer'");
$stmt->execute([$hash]);
echo "Updated " . $stmt->rowCount() . " lecturers. Password is now: Lecturer@123";
