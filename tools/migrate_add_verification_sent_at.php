<?php
require_once __DIR__ . '/../includes/functions.php';
$db = getDb();
$existing = array_map('strval', getTableColumns('customers'));
if (!in_array('verification_sent_at', $existing, true)) {
    try {
        $db->exec('ALTER TABLE customers ADD COLUMN verification_sent_at TEXT');
        echo "Added verification_sent_at\n";
    } catch (Exception $e) {
        echo "Failed to add verification_sent_at: " . $e->getMessage() . "\n";
    }
} else {
    echo "verification_sent_at already exists\n";
}
