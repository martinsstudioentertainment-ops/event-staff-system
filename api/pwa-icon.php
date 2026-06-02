<?php
/**
 * PNG app icon for PWA manifest (192 / 512).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/theme.php';

$size = (int) ($_GET['size'] ?? 192);
if (!in_array($size, [192, 512], true)) {
    $size = 192;
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

$fontSize = (int) round($size * 0.28);
$text     = 'ES';
$font     = 5;
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
