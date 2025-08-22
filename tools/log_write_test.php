<?php
// Append a test message to the project-level catalogue error log
chdir(__DIR__ . '/..');
$msg = '[' . date('c') . '] TEST WRITE from CLI: ' . getmypid() . "\n";
$log = __DIR__ . '/../data/logs/catalogue_errors.log';
file_put_contents($log, $msg, FILE_APPEND | LOCK_EX);
echo "Wrote to $log\n";
