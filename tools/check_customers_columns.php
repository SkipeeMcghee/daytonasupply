<?php
require_once __DIR__ . '/../includes/db.php';
$db = getDb();
$stmt = $db->query('PRAGMA table_info(customers)');
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['name'] . PHP_EOL;
}
