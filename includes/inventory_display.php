<?php
const LOW_STOCK_MAX = 25;

function stock_badge($qty)
{
    if ($qty === null) return null;
    $q = (int) $qty;
    if ($q <= 0) return ['class' => 'cat-stock-out', 'label' => 'Out of stock', 'count' => null];
    if ($q < LOW_STOCK_MAX) return ['class' => 'cat-stock-low', 'label' => 'Low stock', 'count' => $q];
    return ['class' => 'cat-stock-in', 'label' => 'In stock', 'count' => $q];
}

function stock_badge_html($qty, $isLoggedIn)
{
    if (!$isLoggedIn) return '';
    $badge = stock_badge($qty);
    if ($badge === null) return '';
    $text = $badge['label'];
    if ($badge['count'] !== null) {
        $text .= ' - ' . $badge['count'] . ' left';
    }
    return '<span class="cat-stock ' . $badge['class'] . '">'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
}

function stock_updated_html($qty, $updatedAt, $isLoggedIn, $now = null)
{
    if (!$isLoggedIn || $qty === null || !$updatedAt) return '';
    $updated = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $updatedAt, new DateTimeZone('UTC'));
    if (!$updated || $updated->format('Y-m-d H:i:s') !== $updatedAt) return '';
    $age = ($now === null ? time() : $now) - $updated->getTimestamp();
    if ($age < 0 || $age >= 6 * 60 * 60) return '';
    $minutes = (int) floor($age / 60);
    $text = $minutes < 1 ? 'Updated just now' : 'Updated ' . $minutes . ' min ago';
    return '<div class="cat-stock-updated">' . $text . '</div>';
}
