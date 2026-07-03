<?php
// Dynamic icon generator: renders a square PNG with brand-blue background
// and centers the Daytona Supply DS logo so iOS/Android home screen
// icons have consistent branding.
// Usage: /assets/icon.php?size=180

$size = isset($_GET['size']) ? (int)$_GET['size'] : 180;
if ($size < 16) $size = 16; if ($size > 1024) $size = 1024;
// Brand blue from CSS :root --brand (#005EBD)
$brandHex = '#005EBD';
function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) { $r = hexdec(str_repeat($hex[0],2)); $g = hexdec(str_repeat($hex[1],2)); $b = hexdec(str_repeat($hex[2],2)); }
    else { $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2)); }
    return [$r,$g,$b];
}
[$r,$g,$b] = hexToRgb($brandHex);

// Utility: robust image loader that accepts PNG/JPG/GIF via content sniffing
function loadImageAny($path) {
    if (!is_file($path)) return null;
    $data = @file_get_contents($path);
    if ($data !== false && function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring($data);
        if ($im !== false) return $im;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'png' && function_exists('imagecreatefrompng')) return @imagecreatefrompng($path);
    if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('imagecreatefromjpeg')) return @imagecreatefromjpeg($path);
    if ($ext === 'gif' && function_exists('imagecreatefromgif')) return @imagecreatefromgif($path);
    return null;
}

// Fallback if GD is unavailable: serve Boxey image if possible, else DS logo
if (!function_exists('imagecreatetruecolor')) {
    $root = dirname(__DIR__);
    $prefer = $root . '/assets/images/boxey with smartphone.png';
    $altJpg = $root . '/assets/images/boxey with smartphone.jpg';
    $fallback = $root . '/assets/images/DaytonaSupplyDSlogo.png';
    $serve = is_file($prefer) ? $prefer : (is_file($altJpg) ? $altJpg : $fallback);
    if (is_file($serve)) {
        $mime = 'image/png';
        if (preg_match('/\.(jpe?g)$/i', $serve)) $mime = 'image/jpeg';
        elseif (preg_match('/\.gif$/i', $serve)) $mime = 'image/gif';
        header('Content-Type: ' . $mime);
        readfile($serve);
        exit;
    }
}

// Create base image
$img = imagecreatetruecolor($size, $size);
imagesavealpha($img, true);
$bgColor = imagecolorallocate($img, $r, $g, $b);
imagefill($img, 0, 0, $bgColor);

// Try to load the DS logo; fall back gracefully if missing
$root = dirname(__DIR__);
$logoPaths = [
    $root . '/assets/images/DaytonaSupplyDSlogo.png',   // preferred DS logo
    $root . '/assets/images/boxey with smartphone.png', // fallback artwork
    $root . '/assets/images/boxey with smartphone.jpg'  // alternate fallback
];
$logo = null; $logoW = 0; $logoH = 0;
foreach ($logoPaths as $p) {
    $tmp = loadImageAny($p);
    if ($tmp) { $logo = $tmp; $logoW = imagesx($logo); $logoH = imagesy($logo); break; }
}

if ($logo) {
    // Fit within ~86% for a bit more prominence while preserving padding.
    $maxSide = (int)round($size * 0.86);
    $scale = min($maxSide / max(1,$logoW), $maxSide / max(1,$logoH));
    $dstW = max(1, (int)round($logoW * $scale));
    $dstH = max(1, (int)round($logoH * $scale));
    $dstX = (int)floor(($size - $dstW) / 2);
    $dstY = (int)floor(($size - $dstH) / 2);
    imagealphablending($img, true); imagesavealpha($img, true);
    imagecopyresampled($img, $logo, $dstX, $dstY, 0, 0, $dstW, $dstH, $logoW, $logoH);
    imagedestroy($logo);
} else {
    // Fallback: draw simple white "DS" initials if logo missing
    $white = imagecolorallocate($img, 255,255,255);
    $fontSize = max(10, (int)round($size * 0.28));
    $text = 'DS';
    // Use GD built-in font if no TTF available
    // Center approx using bounding box estimates
    $fw = imagefontwidth(5) * strlen($text);
    $fh = imagefontheight(5);
    $x = (int)floor(($size - $fw) / 2);
    $y = (int)floor(($size - $fh) / 2);
    imagestring($img, 5, $x, $y, $text, $white);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
imagepng($img);
imagedestroy($img);
