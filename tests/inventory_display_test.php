<?php
require_once __DIR__ . '/../includes/inventory_display.php';

function inventoryAssert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

$states = [
    [null, null, null],
    [-3, 'Out of stock', null],
    [0, 'Out of stock', null],
    [1, 'Low stock', 1],
    [24, 'Low stock', 24],
    [25, 'In stock', 25],
    [120, 'In stock', 120],
];
foreach ($states as list($qty, $label, $count)) {
    inventoryAssert(stock_badge_html($qty, false) === '', 'Guest badge leaked.');
    $badge = stock_badge($qty);
    if ($label === null) {
        inventoryAssert($badge === null && stock_badge_html($qty, true) === '', 'NULL rendered a badge.');
    } else {
        inventoryAssert($badge['label'] === $label && $badge['count'] === $count, 'Incorrect boundary state.');
        $text = $label . ($count === null ? '' : ' - ' . $count . ' left');
        inventoryAssert(strip_tags(stock_badge_html($qty, true)) === $text, 'Incorrect badge text.');
    }
}
$now = strtotime('2026-10-28T19:00:00Z');
foreach ([0, 59, 60, 12 * 60, 6 * 60 * 60 - 1] as $age) {
    $date = gmdate('Y-m-d H:i:s', $now - $age);
    inventoryAssert(stock_updated_html(3, $date, true, $now) !== '', 'Recent timestamp hidden.');
    inventoryAssert(stock_updated_html(3, $date, false, $now) === '', 'Guest freshness leaked.');
    inventoryAssert(stock_updated_html(null, $date, true, $now) === '', 'NULL freshness rendered.');
}
foreach ([6 * 60 * 60, 7 * 60 * 60, -1] as $age) {
    inventoryAssert(stock_updated_html(3, gmdate('Y-m-d H:i:s', $now - $age), true, $now) === '',
        'Stale or future timestamp rendered.');
}
inventoryAssert(stock_updated_html(3, '2026-10-28 18:48:00', true, $now)
    === '<div class="cat-stock-updated">Updated 12 min ago</div>', 'Incorrect freshness text.');
inventoryAssert(stock_updated_html(3, 'invalid', true, $now) === '', 'Invalid timestamp rendered.');
inventoryAssert(substr(file_get_contents(__DIR__ . '/../includes/inventory_display.php'), 0, 3) !== "\xEF\xBB\xBF",
    'Helper has a BOM.');
echo "Inventory display tests passed (guest privacy, NULL, negative/zero, 1/24/25 boundaries, UTC freshness).\n";
