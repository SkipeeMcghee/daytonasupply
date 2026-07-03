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
    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode(['success' => true, 'suggestions' => []]);
        exit;
    }
    // APCu cache for snappy results on repeated prefixes
    $cacheKey = 'sugg_' . md5($q);
    $useApc = function_exists('apcu_fetch');
    $sugs = null;
    if ($useApc) {
        $sugs = @apcu_fetch($cacheKey, $ok);
        if (!$ok) $sugs = null;
    }
    if (!is_array($sugs)) {
        // Prefer cache-only products when DB fallback is active or to accelerate first hits
        $list = getAllProductsCachedOnly();
        if (is_array($list) && count($list) > 0) {
            $sugs = buildSuggestionsFromList($q, $list, 8);
        }
        // If cache is empty (cold start), fall back to DB-limited query
        if (!is_array($sugs) || count($sugs) === 0) {
            $sugs = getProductSuggestions($q, 8);
        }
        if ($useApc) { @apcu_store($cacheKey, $sugs, 60); }
    }
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
    // Prevent caching by intermediaries; suggestions are transient
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode(['success' => true, 'suggestions' => $out]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
