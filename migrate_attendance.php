<?php
$host   = getenv('MYSQLHOST')     ?: 'localhost';
$dbname = getenv('MYSQLDATABASE') ?: 'citadel';
$user   = getenv('MYSQLUSER')     ?: 'root';
$pass   = getenv('MYSQLPASSWORD') ?: '';
$port   = getenv('MYSQLPORT')     ?: '3306';
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass);
try {
    $pdo->exec("ALTER TABLE attendance ADD COLUMN ai_confidence FLOAT NULL, ADD COLUMN ai_auto_approved TINYINT(1) DEFAULT 0, ADD COLUMN face_match_score FLOAT NULL");
    echo "Columns added OK\n";
} catch(PDOException $e) {
    echo "Note: " . $e->getMessage() . "\n";
}
// Check what's there
$cols = $pdo->query("DESCRIBE attendance")->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) echo $c['Field'] . "\n";
