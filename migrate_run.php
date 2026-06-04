<?php
if ($_GET['key'] ?? '' !== 'citadel_migrate_2026') { http_response_code(403); die('No'); }
require_once 'includes/db.php';
$results = [];
$queries = [
    "ALTER TABLE attendance ADD COLUMN IF NOT EXISTS ai_confidence FLOAT NULL",
    "ALTER TABLE attendance ADD COLUMN IF NOT EXISTS ai_auto_approved TINYINT(1) DEFAULT 0",
    "ALTER TABLE attendance ADD COLUMN IF NOT EXISTS face_match_score FLOAT NULL",
];
foreach ($queries as $q) {
    try { $pdo->exec($q); $results[] = "OK: $q"; }
    catch(PDOException $e) { $results[] = "Skip: " . $e->getMessage(); }
}
$cols = $pdo->query("DESCRIBE attendance")->fetchAll(PDO::FETCH_COLUMN);
echo implode("\n", $results) . "\n\nColumns: " . implode(', ', $cols);
