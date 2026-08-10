<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function returnToInventoryManager(string $message, bool $isError = false): void
{
    $_SESSION['inventory_manager_notice'] = $message;
    $_SESSION['inventory_manager_notice_error'] = $isError;
    header('Location: ../managerportal.php?section=products#inventory-manager');
    exit;
}

function logInventoryManagerError(Throwable $error): string
{
    try {
        $reference = bin2hex(random_bytes(4));
    } catch (Throwable $_) {
        $reference = substr(md5(uniqid('', true)), 0, 8);
    }
    $message = sprintf(
        "[%s] inventory update error [%s]: %s\n%s\n",
        date('c'),
        $reference,
        $error->getMessage(),
        $error->getTraceAsString()
    );
    error_log($message);
    $logDirectories = [__DIR__ . '/../data/logs', __DIR__ . '/../data'];
    foreach ($logDirectories as $directory) {
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
        if (@file_put_contents($directory . '/inventory_errors.log', $message, FILE_APPEND | LOCK_EX) !== false) {
            break;
        }
    }
    return $reference;
}

$inventoryRequestFinished = false;
register_shutdown_function(function () use (&$inventoryRequestFinished): void {
    if ($inventoryRequestFinished) {
        return;
    }
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!$error || !in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }
    $message = sprintf(
        "[%s] inventory fatal error: %s in %s:%d\n",
        date('c'),
        (string)$error['message'],
        (string)$error['file'],
        (int)$error['line']
    );
    error_log($message);
    @file_put_contents(__DIR__ . '/../data/inventory_errors.log', $message, FILE_APPEND | LOCK_EX);
});

try {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/inventory.php';
} catch (Throwable $error) {
    $inventoryRequestFinished = true;
    $reference = logInventoryManagerError($error);
    returnToInventoryManager('Inventory tools could not start. Error reference: ' . $reference, true);
}

if (empty($_SESSION['admin'])) {
    header('Location: ../managerportal.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    returnToInventoryManager('Choose a CSV file before updating inventory.', true);
}
$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['manager_csrf']) || !hash_equals((string)$_SESSION['manager_csrf'], $csrfToken)) {
    returnToInventoryManager('Your session expired. Refresh the manager portal and try again.', true);
}

$inventoryPath = __DIR__ . '/../data/inventory.json';
$backupPath = __DIR__ . '/../data/inventory.previous.json';
$action = (string)($_POST['inventory_action'] ?? '');

try {
    if ($action === 'upload') {
        if (empty($_FILES['inventory_csv']) || !is_array($_FILES['inventory_csv'])) {
            throw new InvalidArgumentException('Choose an inventory CSV file.');
        }
        $upload = $_FILES['inventory_csv'];
        $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
                ? 'The CSV file is larger than the server allows.'
                : 'The CSV upload did not complete. Please try again.');
        }
        if ((int)($upload['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('The CSV file must be 10 MB or smaller.');
        }
        $originalName = (string)($upload['name'] ?? '');
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
            throw new InvalidArgumentException('Choose a file with a .csv extension.');
        }
        $temporaryPath = (string)($upload['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new InvalidArgumentException('The uploaded CSV could not be verified.');
        }

        $parsed = parseInventoryCsv($temporaryPath);
        $db = getDb();
        writeInventoryJson($backupPath, snapshotCurrentInventory($db));
        writeInventoryJson($inventoryPath, $parsed['items']);
        $_SESSION['inventory_upload_summary'] = [
            'excluded_count' => $parsed['excluded_count'],
            'blank_price_count' => $parsed['blank_price_count'],
        ];
        $_SESSION['inventory_update_mode'] = 'upload';
    } elseif ($action === 'restore') {
        $previousItems = readInventoryJson($backupPath);
        writeInventoryJson($inventoryPath, $previousItems);
        $_SESSION['inventory_update_mode'] = 'restore';
    } else {
        throw new InvalidArgumentException('Choose an inventory action.');
    }

    define('INVENTORY_UPDATE_AUTHORIZED', true);
    require __DIR__ . '/update_inventory.php';
} catch (Throwable $error) {
    $inventoryRequestFinished = true;
    $reference = logInventoryManagerError($error);
    returnToInventoryManager($error->getMessage() . ' Error reference: ' . $reference, true);
}