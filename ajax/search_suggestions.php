<?php
// Return JSON suggestions for the header search box
// Query param: q
// Response: { success: true, suggestions: [ { id, name, description, price, url } ] }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

try {
    $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
    $q = normalizeScalar($q, 150, '');
    if ($q === '') {
        echo json_encode(['success' => true, 'suggestions' => []]);
        exit;
    }
    $sugs = getProductSuggestions($q, 8);
    $out = [];
    $loggedIn = !empty($_SESSION['customer']['id']);
    foreach ($sugs as $s) {
        $out[] = [
            'id' => (int)$s['id'],
            'name' => (string)$s['name'],
            'description' => (string)$s['description'],
            'price' => $loggedIn ? number_format((float)$s['price'], 2, '.', '') : null,
            'url' => 'productinfo.php?id=' . (int)$s['id']
        ];
    }
    echo json_encode(['success' => true, 'suggestions' => $out]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
