<?php
$key = $_GET['key'] ?? '';
if ($key !== 'citadel_migrate_2026') { http_response_code(403); die('No'); }
require_once 'includes/db.php';
$results = [];
$queries = [
    "ALTER TABLE attendance ADD COLUMN ai_confidence FLOAT NULL",
    "ALTER TABLE attendance ADD COLUMN ai_auto_approved TINYINT(1) DEFAULT 0",
    "ALTER TABLE attendance ADD COLUMN face_match_score FLOAT NULL",
];
foreach ($queries as $q) {
    try { $pdo->exec($q); $results[] = "OK: $q"; }
    catch(PDOException $e) { $results[] = "Skip (exists?): " . $e->getMessage(); }
}
$cols = array_column($pdo->query("DESCRIBE attendance")->fetchAll(PDO::FETCH_ASSOC), 'Field');
echo implode("\n", $results) . "\n\nColumns: " . implode(', ', $cols);
