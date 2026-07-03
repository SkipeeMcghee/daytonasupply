<?php
// Debug form submission - place this at the very top of managerportal.php temporarily
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_products'])) {
    $debugLog = "=== FORM SUBMISSION DEBUG ===\n";
    $debugLog .= "Time: " . date('Y-m-d H:i:s') . "\n";
    $debugLog .= "All POST data:\n";
    
    foreach ($_POST as $key => $value) {
        if (strpos($key, '392') !== false) { // Only log the SBO product
            $debugLog .= "  $key = '$value'\n";
        }
    }
    
    $debugLog .= "\n";
    file_put_contents(__DIR__ . '/data/form_debug.log', $debugLog, FILE_APPEND | LOCK_EX);
}
?>