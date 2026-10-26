<?php
// Isolated HTTP tests: never connect to the site's configured database.
$fixture = tempnam(sys_get_temp_dir(), 'daytona_catalog_sync_');
unlink($fixture);
mkdir($fixture, 0700);
mkdir($fixture . '/web');
mkdir($fixture . '/web/api');
mkdir($fixture . '/config', 0700);
mkdir($fixture . '/logs', 0700);
copy(__DIR__ . '/../api/catalog_sync.php', $fixture . '/web/api/catalog_sync.php');
$token = bin2hex(random_bytes(32));
$config = "<?php\n\$SYNC_TOKEN = " . var_export($token, true) . ";\n"
    . '$DB_DSN = ' . var_export('sqlite:' . $fixture . '/catalog.sqlite', true) . ";\n"
    . "\$DB_USER = '';\n\$DB_PASS = '';\n";
file_put_contents($fixture . '/config/catalog_sync.php', $config);
$db = new PDO('sqlite:' . $fixture . '/catalog.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE products (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, price NUMERIC NOT NULL,
    description TEXT, is_hidden INTEGER NOT NULL DEFAULT 0,
    image TEXT DEFAULT 'default.jpg', category TEXT DEFAULT 'existing', sort_order INTEGER DEFAULT 7
)");
$db->exec("INSERT INTO products (name, price, description, is_hidden) VALUES
    ('CASE-1', 1, 'Old', 0), ('HIDDEN-1', 5, 'Hidden', 1), ('FREE-1', 0, 'Free', 1)");

function syncAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function syncRequest($method, $query = '', $payload = null, $auth = 'bearer')
{
    global $baseUrl, $token;
    $headers = array('Content-Type: application/json', 'Connection: close');
    if ($auth === 'bearer') {
        $headers[] = 'Authorization: Bearer ' . $token;
    } elseif ($auth === 'header') {
        $headers[] = 'X-Sync-Token: ' . $token;
    } elseif ($auth === 'bad') {
        $headers[] = 'Authorization: Bearer wrong';
    }
    $options = array('method' => $method, 'header' => implode("\r\n", $headers),
        'ignore_errors' => true, 'timeout' => 5);
    if ($payload !== null) {
        $options['content'] = is_string($payload) ? $payload : json_encode($payload);
    }
    $body = file_get_contents($baseUrl . $query, false, stream_context_create(array('http' => $options)));
    syncAssert($body !== false, 'HTTP request failed.');
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $json = json_decode($body, true);
    syncAssert(is_array($json) && json_last_error() === JSON_ERROR_NONE, 'Response was not clean JSON.');
    syncAssert(in_array('Content-Type: application/json; charset=utf-8', $http_response_header, true), 'Missing JSON content type.');
    syncAssert(in_array('Cache-Control: no-store', $http_response_header, true), 'Missing no-store header.');
    return array((int) $match[1], $json);
}

function syncPayload(array $items, $dryRun = false, $force = false)
{
    return array('schema' => 1, 'mode' => 'upsert', 'dry_run' => $dryRun, 'force' => $force, 'items' => $items);
}

function syncRemoveFixture($path)
{
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir()) {
            syncRemoveFixture($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Could not remove test fixture directory: ' . $path);
    }
}

$server = null;
$heldLock = null;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    syncAssert($socket !== false, 'Cannot reserve test server port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address . '/api/catalog_sync.php';
    $server = proc_open(escapeshellarg(PHP_BINARY) . ' -S ' . $address . ' -t ' . escapeshellarg($fixture . '/web'),
        array(0 => array('pipe', 'r'), 1 => array('file', $fixture . '/server.log', 'a'),
            2 => array('file', $fixture . '/server.log', 'a')), $pipes, $fixture, null, array('bypass_shell' => true));
    syncAssert(is_resource($server), 'Cannot start PHP HTTP server.');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    syncAssert($ready && proc_get_status($server)['running'], 'PHP HTTP server did not become ready.');

    list($status, $json) = syncRequest('GET', '?action=ping&token=' . $token, null, 'none');
    syncAssert($status === 200 && $json['schema'] === 1 && isset($json['pong']), 'Query-token ping failed.');
    list($status) = syncRequest('GET', '?action=ping', null, 'bad');
    syncAssert($status === 403, 'Bad token was accepted.');
    list($status) = syncRequest('POST', '', syncPayload(array()), 'none');
    syncAssert($status === 403, 'Missing token was accepted.');
    list($status) = syncRequest('POST', '?token=' . $token, syncPayload(array()), 'none');
    syncAssert($status === 403, 'Query token authorized a write.');
    list($status) = syncRequest('GET');
    syncAssert($status === 405, 'GET without a read-only action was accepted.');
    list($status) = syncRequest('PUT', '', syncPayload(array()));
    syncAssert($status === 405, 'PUT was accepted.');
    list($status, $json) = syncRequest('GET', '?action=stats', null, 'header');
    syncAssert($status === 200 && $json['products'] === 3 && $json['last_sync'] === null, 'Initial stats incorrect.');
    list($status) = syncRequest('POST', '', '{not json');
    syncAssert($status === 400, 'Malformed JSON was accepted.');
    list($status) = syncRequest('POST', '', array('schema' => 2, 'mode' => 'upsert', 'items' => array()));
    syncAssert($status === 400, 'Unknown schema was accepted.');
    list($status) = syncRequest('POST', '', array('schema' => 1, 'mode' => 'delete', 'items' => array()));
    syncAssert($status === 400, 'Delete mode was accepted.');
    $invalidFlag = syncPayload(array());
    $invalidFlag['dry_run'] = 'false';
    list($status) = syncRequest('POST', '', $invalidFlag);
    syncAssert($status === 400, 'Nonboolean flag was accepted.');
    $prune = syncPayload(array(), false, true);
    $prune['prune'] = true;
    list($status) = syncRequest('POST', '', $prune);
    syncAssert($status === 400, 'Pruning was accepted.');

    $items = array(
        array('name' => 'case-1', 'price' => 2, 'description' => 'New'),
        array('name' => 'HIDDEN-1', 'price' => 5, 'description' => 'Hidden'),
        array('name' => 'FREE-1', 'price' => 0, 'description' => 'Free'),
    );
    for ($i = 0; $i < 321; $i++) {
        $items[] = array('name' => 'NEW-' . $i, 'price' => '6.455', 'description' => "14\" x 18\", bundle\x01");
    }
    list($status, $dry) = syncRequest('POST', '', syncPayload($items, true));
    syncAssert($status === 200 && $dry['counts'] === array('received' => 324, 'valid' => 324,
        'added' => 321, 'updated' => 1, 'unchanged' => 2, 'skipped' => 0), 'Dry-run counts incorrect.');
    syncAssert($dry['updated'][0]['changed'] === array('price', 'description')
        && $dry['updated'][0]['from'] === array('price' => 1, 'description' => 'Old')
        && $dry['updated'][0]['to'] === array('price' => 2, 'description' => 'New'), 'Change details incorrect.');
    syncAssert($dry['added'][0]['description'] === '14" x 18", bundle' && $dry['added'][0]['price'] === 6.46, 'Normalization incorrect.');
    syncAssert((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 3
        && !is_file($fixture . '/config/.catalog_sync_state.json')
        && !is_file($fixture . '/logs/catalog_sync.log'), 'Dry run wrote persistent data.');
    list($status, $live) = syncRequest('POST', '', syncPayload($items), 'header');
    syncAssert($status === 200 && $live['counts'] === $dry['counts'] && $live['added'] === $dry['added']
        && $live['updated'] === $dry['updated'] && $live['total_rows'] === 324, 'Live run did not match preview.');
    syncAssert((int) $db->query("SELECT is_hidden FROM products WHERE name='NEW-0'")->fetchColumn() === 0, 'Insert did not preserve hidden default.');
    $protected = $db->query("SELECT id, name, is_hidden, image, category, sort_order FROM products WHERE name='HIDDEN-1'")->fetch(PDO::FETCH_ASSOC);
    list($status, $replay) = syncRequest('POST', '', syncPayload($items));
    syncAssert($status === 200 && $replay['counts']['added'] === 0 && $replay['counts']['updated'] === 0
        && $replay['counts']['unchanged'] === 324, 'Replay was not idempotent.');
    list($status, $stats) = syncRequest('GET', '?action=stats');
    syncAssert($status === 200 && $stats['products'] === 324 && $stats['last_sync']['counts'] === $replay['counts'], 'Stats did not reflect last push.');
    $savedState = file_get_contents($fixture . '/config/.catalog_sync_state.json');
    $savedLog = file_get_contents($fixture . '/logs/catalog_sync.log');
    list($status) = syncRequest('POST', '', syncPayload($items, true));
    syncAssert($status === 200 && file_get_contents($fixture . '/config/.catalog_sync_state.json') === $savedState
        && file_get_contents($fixture . '/logs/catalog_sync.log') === $savedLog, 'Dry run overwrote prior live sync metadata.');

    $hidden = array('name' => 'hidden-1', 'price' => 0, 'description' => 'Changed hidden description');
    list($status) = syncRequest('POST', '', syncPayload(array($hidden)));
    syncAssert($status === 409, 'Partial push guard failed.');
    list($status) = syncRequest('POST', '', syncPayload(array_fill(0, 300, $hidden)));
    syncAssert($status === 409, 'Duplicate rows bypassed partial push guard.');
    list($status) = syncRequest('POST', '', syncPayload(array_fill(0, 2001, $hidden), false, true));
    syncAssert($status === 413, 'Maximum item guard failed.');
    list($status) = syncRequest('POST', '', str_repeat(' ', 8 * 1024 * 1024 + 1));
    syncAssert($status === 413, 'Maximum body size guard failed.');
    list($status, $json) = syncRequest('POST', '', syncPayload(array($hidden), false, true));
    syncAssert($status === 200 && $json['counts']['updated'] === 1, 'Forced small push failed.');
    syncAssert((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 324, 'Small upsert pruned omitted rows.');
    syncAssert($protected === $db->query("SELECT id, name, is_hidden, image, category, sort_order FROM products WHERE name='HIDDEN-1'")->fetch(PDO::FETCH_ASSOC), 'Protected hidden row fields changed.');
    syncAssert($db->query("SELECT name FROM products WHERE id=1")->fetchColumn() === 'CASE-1', 'Existing name case changed.');

    $invalid = array(
        array('name' => '', 'price' => 1), array('name' => str_repeat('A', 101), 'price' => 1),
        array('name' => "NON\x01ASCII", 'price' => 1), array('name' => "\xc3\xa9", 'price' => 1),
        array('name' => 'BAD', 'price' => 'no'), array('name' => 'BAD', 'price' => -1),
        array('name' => 'BAD', 'price' => 100000.01), array('name' => 'BAD', 'price' => '1e999'),
        array('name' => 'BAD', 'price' => true), array('name' => 'BAD', 'price' => 1, 'description' => array()),
        array('name' => 'BAD', 'price' => 1, 'description' => str_repeat("\xc3\xa9", 501)), null,
    );
    $valid = array(
        array('name' => 'LAST-WINS', 'price' => 1, 'description' => 'First'),
        array('name' => 'last-wins', 'price' => 100000, 'description' => str_repeat("\xc3\xa9", 500)),
    );
    list($status, $json) = syncRequest('POST', '', syncPayload(array_merge($invalid, $valid), false, true));
    syncAssert($status === 200 && $json['counts']['skipped'] === count($invalid) && $json['counts']['valid'] === 1
        && $json['added'][0]['name'] === 'last-wins' && $json['added'][0]['price'] === 100000, 'Validation or last-wins deduplication failed.');
    syncAssert($db->query("SELECT description FROM products WHERE name='last-wins'")->fetchColumn() === str_repeat("\xc3\xa9", 500), '500-character Unicode description not preserved.');
    list($status, $json) = syncRequest('POST', '', syncPayload(array_merge($items, $invalid)));
    syncAssert($status === 200 && $json['counts']['skipped'] === count($invalid), 'Invalid rows failed the valid remainder of a batch.');

    $heldLock = fopen($fixture . '/config/.catalog_sync.lock', 'c');
    syncAssert(flock($heldLock, LOCK_EX | LOCK_NB), 'Could not acquire test lock.');
    list($status) = syncRequest('POST', '', syncPayload($items));
    syncAssert($status === 409, 'Concurrent push was not rejected.');
    flock($heldLock, LOCK_UN);
    fclose($heldLock);
    $heldLock = null;

    $stateBefore = file_get_contents($fixture . '/config/.catalog_sync_state.json');
    $logBefore = file_get_contents($fixture . '/logs/catalog_sync.log');
    $db->exec("CREATE TRIGGER fail_sync BEFORE UPDATE ON products WHEN NEW.description='ROLLBACK'
        BEGIN SELECT RAISE(ABORT, 'deliberate test failure'); END");
    list($status, $json) = syncRequest('POST', '', syncPayload(array(
        array('name' => 'MUST-ROLL-BACK', 'price' => 1, 'description' => 'Inserted first'),
        array('name' => 'HIDDEN-1', 'price' => 9, 'description' => 'ROLLBACK'),
    ), false, true));
    syncAssert($status === 500 && $json['ok'] === false, 'Database failure was not JSON 500.');
    syncAssert((int) $db->query("SELECT COUNT(*) FROM products WHERE name='MUST-ROLL-BACK'")->fetchColumn() === 0, 'Failed batch did not roll back inserts.');
    syncAssert(file_get_contents($fixture . '/config/.catalog_sync_state.json') === $stateBefore
        && file_get_contents($fixture . '/logs/catalog_sync.log') === $logBefore, 'Failed batch changed sync state or success log.');
    $db->exec('DROP TRIGGER fail_sync');
    rename($fixture . '/logs', $fixture . '/logs_unavailable');
    list($status) = syncRequest('POST', '', syncPayload(array(
        array('name' => 'NO-LOG-NO-COMMIT', 'price' => 1, 'description' => 'Must roll back'),
    ), false, true));
    syncAssert($status === 500 && (int) $db->query("SELECT COUNT(*) FROM products WHERE name='NO-LOG-NO-COMMIT'")->fetchColumn() === 0, 'Unavailable success log did not roll back writes.');
    rename($fixture . '/logs_unavailable', $fixture . '/logs');
    file_put_contents($fixture . '/config/.catalog_sync_state.json', 'broken state');
    list($status) = syncRequest('GET', '?action=stats');
    syncAssert($status === 500, 'Corrupted state was silently ignored.');
    file_put_contents($fixture . '/config/.catalog_sync_state.json', $stateBefore);

    file_put_contents($fixture . '/config/catalog_sync.php', "\xEF\xBB\xBF" . $config);
    list($status) = syncRequest('GET', '?action=stats');
    syncAssert($status === 200, 'BOM from included config corrupted JSON.');
    file_put_contents($fixture . '/config/catalog_sync.php', $config . "\n\$DB_DSN = 'invalid:unavailable';\n");
    list($status) = syncRequest('GET', '?action=ping');
    syncAssert($status === 200, 'Ping accessed the database.');
    list($status) = syncRequest('GET', '?action=stats');
    syncAssert($status === 500, 'Bad database configuration was not surfaced.');
    file_put_contents($fixture . '/config/catalog_sync.php', $config);

    $db->exec("INSERT INTO products (name, price, description) VALUES ('case-1', 1, 'Duplicate')");
    list($status) = syncRequest('POST', '', syncPayload($items));
    syncAssert($status === 409, 'Ambiguous database names were silently updated.');
    $db->exec("DELETE FROM products WHERE name='case-1'");
    $db->exec('CREATE TABLE custom_catalog (sku_id INTEGER PRIMARY KEY, sku TEXT, amount NUMERIC, details TEXT)');
    file_put_contents($fixture . '/config/catalog_sync.php', $config
        . "\n\$CFG_TABLE='custom_catalog'; \$CFG_COL_ID='sku_id'; \$CFG_COL_NAME='sku'; \$CFG_COL_PRICE='amount'; \$CFG_COL_DESC='details';\n");
    list($status, $json) = syncRequest('POST', '', syncPayload(array($hidden), false, true));
    syncAssert($status === 200 && $json['counts']['added'] === 1
        && $db->query('SELECT sku FROM custom_catalog')->fetchColumn() === 'hidden-1', 'Schema overrides failed.');
    file_put_contents($fixture . '/config/catalog_sync.php', $config . "\n\$CFG_TABLE='products; DROP TABLE products';\n");
    list($status) = syncRequest('GET', '?action=stats');
    syncAssert($status === 500, 'Unsafe schema identifier was accepted.');
    file_put_contents($fixture . '/config/catalog_sync.php', "<?php\nthis is not valid PHP;\n");
    list($status) = syncRequest('GET', '?action=ping');
    syncAssert($status === 500, 'Included config parse failure did not return JSON.');
    unlink($fixture . '/config/catalog_sync.php');
    mkdir($fixture . '/web/includes');
    file_put_contents($fixture . '/web/includes/catalog_sync_config.php', $config);
    list($status, $json) = syncRequest('GET', '?action=stats');
    syncAssert($status === 200 && $json['products'] === 325, 'Protected fallback config path failed.');
    unlink($fixture . '/web/includes/catalog_sync_config.php');
    list($status) = syncRequest('GET', '?action=ping');
    syncAssert($status === 500, 'Missing config did not return JSON.');
    syncAssert(strpos(file_get_contents($fixture . '/logs/catalog_sync.log'), $token) === false, 'Success log leaked token.');
    echo "Catalog sync HTTP integration tests passed (auth, validation, guards, dry run, upsert, replay, hidden/default fields, locks, rollback, stats, BOM, schema overrides).\n";
} finally {
    if (is_resource($heldLock)) {
        flock($heldLock, LOCK_UN);
        fclose($heldLock);
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $db = null;
    syncRemoveFixture($fixture);
}
