<?php

/**
 * PNG app icon for PWA manifest (180 / 192 / 512).
 * Serves branded static icons when present; falls back to generated placeholder.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/theme.php';

$size = (int) ($_GET['size'] ?? 192);
if (!in_array($size, [180, 192, 512], true)) {
    $size = 192;
}

$staticMap = [
    180 => '/assets/icons/pwa/icon-180.png',
    192 => '/assets/icons/pwa/icon-192.png',
    512 => '/assets/icons/pwa/icon-512.png',
];

$staticPath = dirname(__DIR__) . ($staticMap[$size] ?? $staticMap[192]);
if (is_file($staticPath)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    readfile($staticPath);
    exit;
}

$pdo   = getDB();
$hex   = ltrim(getThemeColor($pdo), '#');
$rgb   = sscanf($hex, '%02x%02x%02x');
$bgR   = $rgb[0] ?? 37;
$bgG   = $rgb[1] ?? 99;
$bgB   = $rgb[2] ?? 235;

if (!function_exists('imagecreatetruecolor')) {
    header('Content-Type: image/svg+xml');
    readfile(dirname(__DIR__) . '/assets/icons/icon.svg');
    exit;
}

$img = imagecreatetruecolor($size, $size);
if ($img === false) {
    http_response_code(500);
    exit;
}

$bg = imagecolorallocate($img, $bgR, $bgG, $bgB);
$fg = imagecolorallocate($img, 255, 255, 255);
imagefilledrectangle($img, 0, 0, $size, $size, $bg);

$font = 5;
$text = 'ES';
if ($size >= 192) {
    $textWidth  = imagefontwidth($font) * strlen($text);
    $textHeight = imagefontheight($font);
    $x          = (int) (($size - $textWidth) / 2);
    $y          = (int) (($size - $textHeight) / 2);
    imagestring($img, $font, $x, $y, $text, $fg);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($img);
imagedestroy($img);
