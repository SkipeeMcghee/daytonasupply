<?php
// Connect through the site bootstrap without running schema initialization.
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
const SCHEMA_VERSION = 2;
const SCHEMA_VERSION_MIN = 1;
$catalogSyncResponded = false;
$catalogSyncCommitted = false;
$catalogSyncDb = null;
$catalogSyncStage = 'bootstrap';

function catalogSyncLogFailure($class, $file, $line, $stage)
{
    // Messages and traces can contain credentials, SQL values or request data.
    error_log('Catalog sync failed: stage=' . $stage . ' class=' . $class
        . ' at=' . basename($file) . ':' . $line);
}

function catalogSyncRespond($status, array $body)
{
    global $catalogSyncResponded;
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $status = 500;
        $json = '{"ok":false,"error":"Could not encode response."}';
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $catalogSyncResponded = true;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
register_shutdown_function(function () {
    global $catalogSyncResponded, $catalogSyncDb, $catalogSyncCommitted, $catalogSyncStage;
    $error = error_get_last();
    if (!$catalogSyncResponded && $error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        if ($catalogSyncDb instanceof PDO && $catalogSyncDb->inTransaction()) {
            $catalogSyncDb->rollBack();
        }
        catalogSyncLogFailure('FatalError', $error['file'], $error['line'], $catalogSyncStage);
        if ($catalogSyncStage === 'database') {
            catalogSyncRespond(503, array('ok' => false, 'error' => 'sync_db_unreachable'));
        }
        catalogSyncRespond(500, array('ok' => false, 'error' => $catalogSyncCommitted
            ? 'Database changes committed, but sync finalization failed. Check server logs and stats before retrying.'
            : 'sync_internal_error'));
    }
});

function catalogSyncIdentifier($value)
{
    if (!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)) {
        throw new RuntimeException('Invalid catalog schema configuration.');
    }
    return '`' . $value . '`';
}

function catalogSyncDatabase($respondOnFailure = true)
{
    global $catalogSyncDb, $catalogSyncStage;
    $catalogSyncStage = 'database';
    try {
        $bootstrap = __DIR__ . '/../includes/db.php';
        if (!is_readable($bootstrap)) {
            throw new RuntimeException('Database bootstrap is unavailable.');
        }
        require_once $bootstrap;
        $catalogSyncDb = getDb(false);
    } catch (Throwable $e) {
        if (!$respondOnFailure) {
            throw $e;
        }
        catalogSyncLogFailure(get_class($e), $e->getFile(), $e->getLine(), $catalogSyncStage);
        catalogSyncRespond(503, array('ok' => false, 'error' => 'sync_db_unreachable'));
    }
    $catalogSyncStage = 'sync';
    return $catalogSyncDb;
}

try {
    $webroot = dirname(__DIR__);
    $privateRoot = dirname($webroot);
    $configFile = $webroot . '/includes/sync_config.php';
    if (is_file($configFile)) {
        require $configFile;
    }
    $SYNC_TOKEN = getenv('SYNC_TOKEN');
    if (!is_string($SYNC_TOKEN) || strlen($SYNC_TOKEN) < 32) {
        error_log('Catalog sync failed: stage=bootstrap error=sync_token_not_configured');
        catalogSyncRespond(503, array('ok' => false, 'error' => 'sync_token_not_configured'));
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    $action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
    if ($method !== 'POST' && !($method === 'GET' && in_array($action, array('ping', 'stats'), true))) {
        header('Allow: POST, GET');
        catalogSyncRespond(405, array('ok' => false, 'error' => 'Use POST for writes or GET with action=ping or action=stats.'));
    }
    $token = '';
    $authorization = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION']
        : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');
    if (preg_match('/^Bearer\s+(\S+)$/iD', $authorization, $match)) {
        $token = $match[1];
    } elseif (isset($_SERVER['HTTP_X_SYNC_TOKEN'])) {
        $token = $_SERVER['HTTP_X_SYNC_TOKEN'];
    } elseif ($method === 'GET' && isset($_GET['token']) && is_string($_GET['token'])) {
        $token = $_GET['token'];
    }
    if (!hash_equals($SYNC_TOKEN, $token)) {
        catalogSyncRespond(403, array('ok' => false, 'error' => 'Invalid token.'));
    }
    if ($method === 'GET' && $action === 'ping') {
        $time = gmdate('c');
        catalogSyncRespond(200, array('ok' => true, 'php' => PHP_VERSION,
            'service' => 'catalog_sync', 'time' => $time, 'pong' => $time, 'schema' => SCHEMA_VERSION));
    }
    if ($method === 'POST' && $action !== '') {
        catalogSyncRespond(400, array('ok' => false, 'error' => 'POST does not accept an action.'));
    }
    $section = 'all';
    if ($method === 'GET' && $action === 'stats') {
        $section = isset($_GET['section']) ? $_GET['section'] : 'all';
        if (!is_string($section) || !in_array($section, array('all', 'counts', 'qty', 'sync'), true)) {
            catalogSyncRespond(400, array('ok' => false, 'error' => 'section must be all, counts, qty or sync.'));
        }
    }

    $table = catalogSyncIdentifier(isset($CFG_TABLE) ? $CFG_TABLE : 'products');
    $id = catalogSyncIdentifier(isset($CFG_COL_ID) ? $CFG_COL_ID : 'id');
    $name = catalogSyncIdentifier(isset($CFG_COL_NAME) ? $CFG_COL_NAME : 'name');
    $price = catalogSyncIdentifier(isset($CFG_COL_PRICE) ? $CFG_COL_PRICE : 'price');
    $description = catalogSyncIdentifier(isset($CFG_COL_DESC) ? $CFG_COL_DESC : 'description');
    $catalogSyncStage = 'storage';
    $stateDir = $privateRoot . '/config';
    $stateFile = $stateDir . '/.catalog_sync_state.json';
    $lock = fopen($stateDir . '/.catalog_sync.lock', 'c');
    if (!$lock) {
        throw new RuntimeException('Cannot open sync lock.');
    }
    if (!flock($lock, ($method === 'GET' ? LOCK_SH : LOCK_EX) | LOCK_NB)) {
        fclose($lock);
        catalogSyncRespond(409, array('ok' => false, 'error' => 'Another catalog sync is running.'));
    }

    if ($method === 'GET') {
        $out = array('ok' => true);
        $db = null;
        $sections = $section === 'all' ? array('counts', 'qty', 'sync') : array($section);
        foreach ($sections as $statsSection) {
            $catalogSyncStage = 'stats_' . $statsSection;
            try {
                if ($statsSection !== 'sync' && !($db instanceof PDO)) {
                    $db = catalogSyncDatabase(false);
                    $catalogSyncStage = 'stats_' . $statsSection;
                }
                if ($statsSection === 'counts') {
                    $out['products'] = (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                } elseif ($statsSection === 'qty') {
                    $stock = $db->query("SELECT COUNT(qty) AS tracked,
                        COUNT(CASE WHEN qty <= 0 THEN 1 END) AS stock_out,
                        COUNT(CASE WHEN qty > 0 AND qty < 25 THEN 1 END) AS low,
                        MAX(qty_updated_at) AS last_update FROM $table")->fetch(PDO::FETCH_ASSOC);
                    $out['qty'] = array('tracked' => (int) $stock['tracked'], 'out' => (int) $stock['stock_out'],
                        'low' => (int) $stock['low'], 'last_update' => $stock['last_update'] === null ? null
                            : (new DateTimeImmutable($stock['last_update'], new DateTimeZone('UTC')))->format('c'));
                } else {
                    $lastSync = null;
                    if (is_file($stateFile)) {
                        $lastSync = json_decode(file_get_contents($stateFile), true);
                        if (!is_array($lastSync) || json_last_error() !== JSON_ERROR_NONE) {
                            throw new RuntimeException('Invalid sync state file.');
                        }
                    }
                    $out['last_sync'] = $lastSync;
                }
            } catch (Throwable $e) {
                catalogSyncLogFailure(get_class($e), $e->getFile(), $e->getLine(), 'stats_' . $statsSection);
                $out['partial'] = true;
                $out['warnings'][] = array('code' => 'sync_stats_section_failed', 'section' => $statsSection,
                    'message' => 'Stats section unavailable. Check server logs.');
            }
        }
        catalogSyncRespond(200, $out);
    }

    $maxBytes = 8 * 1024 * 1024;
    if (isset($_SERVER['CONTENT_LENGTH']) && (float) $_SERVER['CONTENT_LENGTH'] > $maxBytes) {
        catalogSyncRespond(413, array('ok' => false, 'error' => 'Payload exceeds 8 MiB.'));
    }
    $input = fopen('php://input', 'rb');
    $body = stream_get_contents($input, $maxBytes + 1);
    fclose($input);
    if (strlen($body) > $maxBytes) {
        catalogSyncRespond(413, array('ok' => false, 'error' => 'Payload exceeds 8 MiB.'));
    }
    $payload = json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE || !($payload instanceof stdClass)) {
        catalogSyncRespond(400, array('ok' => false, 'error' => 'Expected a JSON object.'));
    }
    if (!isset($payload->schema, $payload->mode, $payload->items) || !is_int($payload->schema)
        || $payload->schema < SCHEMA_VERSION_MIN || $payload->schema > SCHEMA_VERSION
        || $payload->mode !== 'upsert' || !is_array($payload->items)) {
        catalogSyncRespond(400, array('ok' => false, 'error' => 'Expected schema=1 or 2, mode=upsert and an items array.'));
    }
    foreach (array('dry_run', 'force') as $flag) {
        if (property_exists($payload, $flag) && !is_bool($payload->$flag)) {
            catalogSyncRespond(400, array('ok' => false, 'error' => $flag . ' must be a boolean.'));
        }
    }
    if (property_exists($payload, 'prune')) {
        catalogSyncRespond(400, array('ok' => false, 'error' => 'Pruning is not supported.'));
    }
    $received = count($payload->items);
    if ($received > 2000) {
        catalogSyncRespond(413, array('ok' => false, 'error' => 'Maximum 2000 items per push.'));
    }
    $dryRun = isset($payload->dry_run) ? $payload->dry_run : false;
    $force = isset($payload->force) ? $payload->force : false;
    $items = array();
    $skipped = array();
    foreach ($payload->items as $index => $row) {
        $reason = null;
        $qtyPresent = $payload->schema >= 2 && $row instanceof stdClass && property_exists($row, 'qty');
        if (!($row instanceof stdClass) || !isset($row->name) || !is_string($row->name)
            || !preg_match('/^[\x20-\x7E]{1,100}$/D', $row->name) || trim($row->name) === '') {
            $reason = 'Name must be printable ASCII, nonblank and at most 100 characters.';
        } elseif (!isset($row->price) || (!is_int($row->price) && !is_float($row->price) && !is_string($row->price))
            || !is_numeric($row->price) || !is_finite((float) $row->price)
            || (float) $row->price < 0 || (float) $row->price > 100000) {
            $reason = 'Price must be numeric and between 0 and 100000.';
        } elseif (property_exists($row, 'description') && !is_string($row->description)) {
            $reason = 'Description must be a string.';
        } elseif ($qtyPresent && $row->qty !== null && !is_int($row->qty)) {
            $reason = 'qty not an integer';
        } elseif ($qtyPresent && $row->qty !== null && ($row->qty < -99999 || $row->qty > 999999)) {
            $reason = 'qty out of range';
        } else {
            $desc = isset($row->description) ? preg_replace('/\p{Cc}/u', '', $row->description) : '';
            if (preg_match_all('/./us', $desc) > 500) {
                $reason = 'Description must be at most 500 characters.';
            }
        }
        if ($reason !== null) {
            $entry = array('index' => $index, 'reason' => $reason);
            if ($row instanceof stdClass && isset($row->name) && is_string($row->name)) {
                $entry['name'] = $row->name;
            }
            $skipped[] = $entry;
            continue;
        }
        $sku = trim($row->name);
        $items[strtolower($sku)] = array('name' => $sku, 'price' => round((float) $row->price, 2), 'description' => $desc);
        $items[strtolower($sku)]['qty_present'] = $qtyPresent;
        $items[strtolower($sku)]['qty'] = $qtyPresent ? $row->qty : null;
    }
    // Guard the usable, deduplicated catalog too, not just the raw array length.
    if (!$force && ($received < 300 || count($items) < 300)) {
        catalogSyncRespond(409, array('ok' => false, 'error' => 'Refusing a partial push: at least 300 valid unique items required; use force=true to override.', 'skipped' => $skipped));
    }

    $db = catalogSyncDatabase();
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $engineQuery = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $engineQuery->execute(array(isset($CFG_TABLE) ? $CFG_TABLE : 'products'));
        if (strcasecmp((string) $engineQuery->fetchColumn(), 'InnoDB') !== 0) {
            throw new RuntimeException('Catalog table must use InnoDB for transactional writes.');
        }
    }
    $db->beginTransaction();
    $existing = array();
    foreach ($db->query("SELECT $id AS sync_id, $name AS sync_name, $price AS sync_price, $description AS sync_description, qty AS sync_qty FROM $table") as $row) {
        $key = strtolower(trim($row['sync_name']));
        if (isset($existing[$key])) {
            $db->rollBack();
            catalogSyncRespond(409, array('ok' => false, 'error' => 'Catalog contains duplicate case-insensitive names. Resolve them before syncing.'));
        }
        $existing[$key] = $row;
    }
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $columns = $db->query($mysql ? "SHOW COLUMNS FROM $table LIKE 'is_hidden'" : "PRAGMA table_info($table)");
    $insertVisible = false;
    foreach ($columns as $column) {
        if (($mysql ? $column['Field'] : $column['name']) === 'is_hidden') {
            $default = $mysql ? $column['Default'] : $column['dflt_value'];
            $insertVisible = trim((string) $default, "()'\" \t\r\n") === '1';
        }
    }
    $insert = $db->prepare($insertVisible
        ? "INSERT INTO $table ($name, $price, $description, `is_hidden`) VALUES (?, ?, ?, 0)"
        : "INSERT INTO $table ($name, $price, $description) VALUES (?, ?, ?)");
    $update = $db->prepare("UPDATE $table SET $price = ?, $description = ? WHERE $id = ?");
    $insertQty = $db->prepare($insertVisible
        ? "INSERT INTO $table ($name, $price, $description, qty, qty_updated_at, `is_hidden`) VALUES (?, ?, ?, ?, ?, 0)"
        : "INSERT INTO $table ($name, $price, $description, qty, qty_updated_at) VALUES (?, ?, ?, ?, ?)");
    $updateQty = $db->prepare("UPDATE $table SET $price = ?, $description = ?, qty = ?, qty_updated_at = ? WHERE $id = ?");
    $qtyChanges = 0;
    $qtyUpdatedAt = gmdate('Y-m-d H:i:s');
    $added = array();
    $updated = array();
    $unchanged = 0;
    foreach ($items as $key => $item) {
        if (!isset($existing[$key])) {
            $new = array('name' => $item['name'], 'price' => $item['price'], 'description' => $item['description']);
            if ($item['qty_present']) {
                $new['qty'] = $item['qty'];
                if ($item['qty'] !== null) $qtyChanges++;
            }
            $added[] = $new;
            if (!$dryRun) {
                if ($item['qty_present']) {
                    $insertQty->execute(array($item['name'], $item['price'], $item['description'], $item['qty'], $qtyUpdatedAt));
                } else {
                    $insert->execute(array($item['name'], $item['price'], $item['description']));
                }
            }
            continue;
        }
        $old = $existing[$key];
        $oldQty = $old['sync_qty'] === null ? null : (int) $old['sync_qty'];
        $from = array('price' => round((float) $old['sync_price'], 2), 'description' => (string) $old['sync_description'], 'qty' => $oldQty);
        $to = array('price' => $item['price'], 'description' => $item['description'],
            'qty' => $item['qty_present'] ? $item['qty'] : $oldQty);
        $changed = array();
        foreach (array('price', 'description') as $field) {
            if ($from[$field] !== $to[$field]) {
                $changed[] = $field;
            }
        }
        $qtyChanged = $item['qty_present'] && $oldQty !== $item['qty'];
        if ($qtyChanged) {
            $changed[] = 'qty';
            $qtyChanges++;
        }
        if (!$changed) {
            $unchanged++;
            continue;
        }
        $updated[] = array('name' => $item['name'], 'changed' => $changed, 'from' => $from, 'to' => $to);
        if (!$dryRun) {
            if ($qtyChanged) {
                $updateQty->execute(array($item['price'], $item['description'], $item['qty'], $qtyUpdatedAt, $old['sync_id']));
            } else {
                $update->execute(array($item['price'], $item['description'], $old['sync_id']));
            }
        }
    }
    $total = (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    $summary = array(
        'ok' => true, 'schema' => $payload->schema, 'mode' => 'upsert', 'dry_run' => $dryRun,
        'finished_at' => gmdate('c'),
        'counts' => array('received' => $received, 'valid' => count($items), 'added' => count($added),
            'updated' => count($updated), 'unchanged' => $unchanged, 'skipped' => count($skipped)),
        'total_rows' => $total,
    );
    if ($dryRun) {
        $db->rollBack();
    } else {
        $log = fopen($stateDir . '/catalog_sync.log', 'ab');
        if (!$log) {
            throw new RuntimeException('Cannot open sync log.');
        }
        $stateJson = json_encode($summary, JSON_UNESCAPED_SLASHES);
        if ($stateJson === false) {
            throw new RuntimeException('Cannot encode sync state.');
        }
        $stateTemp = tempnam($stateDir, '.catalog_sync_state_');
        try {
            if (file_put_contents($stateTemp, $stateJson . "\n") !== strlen($stateJson) + 1
                || !chmod($stateTemp, 0640)) {
                throw new RuntimeException('Cannot prepare sync state.');
            }
            $db->commit();
            $catalogSyncCommitted = true;
            try {
                require_once __DIR__ . '/../includes/functions.php';
                invalidateProductsCache(true);
            } catch (Throwable $cacheError) {
                catalogSyncLogFailure(get_class($cacheError), $cacheError->getFile(),
                    $cacheError->getLine(), 'cache_invalidation');
                $summary['db_committed'] = true;
                $summary['warnings'] = array(array(
                    'code' => 'sync_cache_invalidation_failed',
                    'message' => 'Database changes committed, but product cache invalidation failed. Catalogue or suggestions may be stale. Check server logs; do not treat this as a rollback.',
                ));
            }
            if (!rename($stateTemp, $stateFile)) {
                throw new RuntimeException('Cannot publish sync state.');
            }
            $ip = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^a-fA-F0-9:.]/', '', $_SERVER['REMOTE_ADDR']) : '';
            $line = $summary['finished_at'] . ' IP=' . $ip . ' ' . json_encode($summary['counts']) . ' qty=' . $qtyChanges . "\n";
            if (fwrite($log, $line) !== strlen($line) || !fflush($log)) {
                throw new RuntimeException('Cannot write sync log.');
            }
        } finally {
            fclose($log);
            if (is_file($stateTemp)) {
                unlink($stateTemp);
            }
        }
    }
    catalogSyncRespond(200, array_merge($summary, array('added' => $added, 'updated' => $updated, 'skipped' => $skipped)));
} catch (Throwable $e) {
    if ($catalogSyncDb instanceof PDO && $catalogSyncDb->inTransaction()) {
        $catalogSyncDb->rollBack();
    }
    catalogSyncLogFailure(get_class($e), $e->getFile(), $e->getLine(), $catalogSyncStage);
    catalogSyncRespond(500, array('ok' => false, 'error' => $catalogSyncCommitted
        ? 'Database changes committed, but sync finalization failed. Check server logs and stats before retrying.'
        : 'sync_internal_error'));
}
