<?php
require_once __DIR__ . '/../includes/db.php';

echo "Fixing MySQL favorites schema...\n";
$db = getDb();
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
if (strcasecmp($driver, 'mysql') !== 0) {
    echo "Not a MySQL connection. Aborting.\n";
    exit(0);
}
try {
    // Ensure table exists
    $db->exec("CREATE TABLE IF NOT EXISTS favorites (
        id INT NOT NULL,
        sku VARCHAR(255) NOT NULL,
        PRIMARY KEY (id, sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Bump sku length to 255 if it's smaller
    $cols = [];
    $colExtras = [];
    $stmt = $db->query('SHOW COLUMNS FROM favorites');
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $field = strtolower($r['Field']);
        $cols[$field] = strtolower($r['Type']);
        // Capture original Type and Extra (case-preserving for reconstructing column definition)
        $colExtras[$field] = [
            'Type' => $r['Type'],
            'Extra' => strtolower($r['Extra'] ?? ''),
        ];
    }
    if (!empty($cols['sku']) && preg_match('/varchar\((\d+)\)/', $cols['sku'], $m)) {
        $len = (int)$m[1];
        if ($len < 255) {
            echo "Altering favorites.sku length from $len to 255...\n";
            $db->exec('ALTER TABLE favorites MODIFY sku VARCHAR(255) NOT NULL');
        }
    }

    // If id is AUTO_INCREMENT, drop the AUTO_INCREMENT attribute before changing PK
    if (!empty($colExtras['id']) && strpos($colExtras['id']['Extra'] ?? '', 'auto_increment') !== false) {
        $idType = $colExtras['id']['Type'] ?: 'INT'; // e.g., 'int(10) unsigned'
        echo "Removing AUTO_INCREMENT from favorites.id (type: {$idType})...\n";
        $db->exec('ALTER TABLE favorites MODIFY id ' . $idType . ' NOT NULL');
    }

    // Check PK
    $pkCols = [];
    $pks = $db->query("SHOW KEYS FROM favorites WHERE Key_name='PRIMARY' ORDER BY Seq_in_index ASC");
    while ($r = $pks->fetch(PDO::FETCH_ASSOC)) { $pkCols[] = strtolower($r['Column_name']); }
    if (count($pkCols) === 1 && $pkCols[0] === 'id') {
        echo "Primary key is id-only. Updating to composite (id, sku)...\n";
        $db->beginTransaction();
        $db->exec('ALTER TABLE favorites DROP PRIMARY KEY');
        try {
            $db->exec('ALTER TABLE favorites ADD PRIMARY KEY (id, sku)');
            $db->commit();
            echo "Primary key updated.\n";
        } catch (PDOException $e) {
            $errInfo = $e->errorInfo ?? [];
            $mysqlErr = isset($errInfo[1]) ? (int)$errInfo[1] : 0;
            if ($mysqlErr === 1071) { // Specified key was too long; max key length is 767 bytes
                echo "Key too long for utf8mb4(255). Reducing favorites.sku length to 191 and retrying...\n";
                try {
                    // With utf8mb4, 191 * 4 = 764 bytes < 767
                    $db->exec('ALTER TABLE favorites MODIFY sku VARCHAR(191) NOT NULL');
                    $db->exec('ALTER TABLE favorites ADD PRIMARY KEY (id, sku)');
                    $db->commit();
                    echo "Primary key updated after length reduction to 191.\n";
                } catch (PDOException $e2) {
                    $errInfo2 = $e2->errorInfo ?? [];
                    $mysqlErr2 = isset($errInfo2[1]) ? (int)$errInfo2[1] : 0;
                    if ($mysqlErr2 === 1071) {
                        echo "Still too long. Switching favorites.sku to utf8 (3-byte) at 255 and retrying...\n";
                        $db->exec('ALTER TABLE favorites MODIFY sku VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL');
                        $db->exec('ALTER TABLE favorites ADD PRIMARY KEY (id, sku)');
                        $db->commit();
                        echo "Primary key updated after charset adjustment.\n";
                    } else {
                        throw $e2;
                    }
                }
            } else {
                // Re-throw for outer catch to handle
                throw $e;
            }
        }
    } else {
        echo "Primary key already composite or unexpected: [" . implode(',', $pkCols) . "]\n";
    }

    // Drop any stray unique index on sku-only
    $uni = $db->query("SHOW INDEX FROM favorites WHERE Non_unique=0 AND Key_name<>'PRIMARY'");
    $toDrop = [];
    $byName = [];
    while ($r = $uni->fetch(PDO::FETCH_ASSOC)) { $byName[$r['Key_name']][] = strtolower($r['Column_name']); }
    foreach ($byName as $name => $colsArr) {
        if (count($colsArr) === 1 && $colsArr[0] === 'sku') { $toDrop[] = $name; }
    }
    foreach ($toDrop as $idx) {
        echo "Dropping stray unique index on sku: $idx...\n";
        $db->exec('DROP INDEX `' . str_replace('`','',$idx) . '` ON favorites');
    }

    echo "\nFinal schema:\n";
    $stmt = $db->query('SHOW COLUMNS FROM favorites');
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { echo sprintf("- %s %s %s\n", $r['Field'], $r['Type'], ($r['Key'] ?: '')); }
    echo "\nIndexes:\n";
    $idx = $db->query('SHOW INDEX FROM favorites');
    $byName2 = [];
    while ($r = $idx->fetch(PDO::FETCH_ASSOC)) { $byName2[$r['Key_name']][] = $r['Column_name']; }
    foreach ($byName2 as $name => $colsArr) { echo "- $name: " . implode(',', $colsArr) . "\n"; }

    echo "\nDone.\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    try { $db->rollBack(); } catch (Exception $_) {}
    exit(1);
}
