<?php
require_once __DIR__ . '/../includes/db.php';

echo "Favorites schema check\n";
try {
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Driver: $driver\n";
    if (strcasecmp($driver, 'mysql') === 0) {
        echo "\nCOLUMNS:\n";
        $stmt = $db->query('SHOW COLUMNS FROM favorites');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo sprintf("- %s %s %s\n", $r['Field'], $r['Type'], ($r['Key'] ?: ''));
        }
        echo "\nINDEXES:\n";
        $idx = $db->query("SHOW INDEX FROM favorites");
        $byName = [];
        while ($r = $idx->fetch(PDO::FETCH_ASSOC)) { $byName[$r['Key_name']][] = $r['Column_name']; }
        foreach ($byName as $name => $cols) {
            echo "- $name: " . implode(',', $cols) . "\n";
        }
    } else {
        echo "\nCOLUMNS (PRAGMA table_info):\n";
        $ti = $db->query('PRAGMA table_info(favorites)');
        while ($r = $ti->fetch(PDO::FETCH_ASSOC)) {
            echo sprintf("- %s pk=%s\n", $r['name'], $r['pk']);
        }
        echo "\nINDEXES (PRAGMA index_list / index_info):\n";
        $il = $db->query("PRAGMA index_list('favorites')");
        while ($ir = $il->fetch(PDO::FETCH_ASSOC)) {
            $iname = $ir['name'];
            $unique = (int)$ir['unique'] === 1 ? 'UNIQUE' : 'NONUNIQUE';
            $ii = $db->query("PRAGMA index_info('" . str_replace("'","''", $iname) . "')");
            $cols = [];
            while ($ci = $ii->fetch(PDO::FETCH_ASSOC)) { $cols[] = $ci['name']; }
            echo "- $iname ($unique): " . implode(',', $cols) . "\n";
        }
    }
    echo "\nSample counts by id (first 5):\n";
    $stmt = $db->query('SELECT id, COUNT(*) c FROM favorites GROUP BY id ORDER BY c DESC LIMIT 5');
    foreach ($stmt as $row) {
        echo sprintf("- id=%s count=%s\n", $row['id'], $row['c']);
    }
    echo "\nDone.\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
