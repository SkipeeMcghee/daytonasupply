<?php
// One-off migration script: adds `darkmode` column to customers table if missing.
require_once __DIR__ . '/../includes/db.php';

try {
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (strcasecmp($driver, 'sqlite') === 0) {
        $cols = $db->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_ASSOC);
        $found = false; foreach ($cols as $c) { if (strcasecmp($c['name'], 'darkmode') === 0) { $found = true; break; } }
        if (!$found) {
            $db->exec("ALTER TABLE customers ADD COLUMN darkmode INTEGER DEFAULT 0");
            echo "Added darkmode column to customers (sqlite)\n";
        } else echo "darkmode column already exists\n";
    } else {
        // MySQL
        $cols = [];
        $stmt = $db->query("SHOW COLUMNS FROM customers");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cols[] = $r['Field'];
        if (!in_array('darkmode', $cols, true)) {
            $db->exec("ALTER TABLE customers ADD COLUMN darkmode TINYINT(1) NOT NULL DEFAULT 0");
            echo "Added darkmode column to customers (mysql)\n";
        } else echo "darkmode column already exists\n";
    }
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
