<?php
// Simple migration: add missing discrete customer columns for SQLite.
// Safe to run multiple times; checks existing columns first.
require_once __DIR__ . '/../includes/functions.php';

$db = getDb();
$existing = array_map('strval', getTableColumns('customers'));
$wanted = [
    'billing_line1' => 'TEXT',
    'billing_line2' => 'TEXT',
    'billing_city' => 'TEXT',
    'billing_state' => 'TEXT',
    'billing_postal_code' => 'TEXT',
    'shipping_line1' => 'TEXT',
    'shipping_line2' => 'TEXT',
    'shipping_city' => 'TEXT',
    'shipping_state' => 'TEXT',
    'shipping_postal_code' => 'TEXT',
    'verification_token' => 'TEXT',
    'is_verified' => 'INTEGER DEFAULT 0',
    'reset_token' => 'TEXT',
    'reset_token_expires' => 'TEXT'
];
$added = [];
foreach ($wanted as $col => $type) {
    if (!in_array($col, $existing, true)) {
        $sql = "ALTER TABLE customers ADD COLUMN $col $type";
        try {
            $db->exec($sql);
            $added[] = $col;
            echo "Added column: $col\n";
        } catch (Exception $e) {
            echo "Failed to add $col: " . $e->getMessage() . "\n";
        }
    }
}
if (empty($added)) {
    echo "No columns added; customers table already has discrete columns.\n";
} else {
    echo "Migration complete. Added: " . implode(', ', $added) . "\n";
}
