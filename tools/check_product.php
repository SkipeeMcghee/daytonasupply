<?php
require_once __DIR__ . '/../includes/db.php';
$db = getDb();
$id = $argv[1] ?? 12;
$stmt = $db->prepare('SELECT id,name,price FROM products WHERE id = :id');
$stmt->execute([':id' => (int)$id]);
$r = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
