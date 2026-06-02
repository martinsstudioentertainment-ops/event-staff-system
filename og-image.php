<?php
/**
 * Social share image (WhatsApp, Facebook) — PNG/JPEG only; crawlers ignore SVG.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/brand-logo.php';
require_once __DIR__ . '/includes/settings-repository.php';

$pdo = getDB();
$path = getCompanyLogoFilesystemPath($pdo);

if ($path !== '') {
    $mime = mime_content_type($path) ?: '';
    if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }
}

$siteName = getSiteName($pdo);
$w        = 1200;
$h        = 630;
$im       = imagecreatetruecolor($w, $h);
if ($im === false) {
    http_response_code(500);
    exit;
}

$bg   = imagecolorallocate($im, 15, 23, 42);
$bar  = imagecolorallocate($im, 37, 99, 235);
$text = imagecolorallocate($im, 248, 250, 252);
imagefilledrectangle($im, 0, 0, $w, $h, $bg);
imagefilledrectangle($im, 0, 0, $w, 12, $bar);

$label = $siteName !== '' ? $siteName : 'Event Staff Registration';
if (function_exists('imagestring')) {
    $size  = 5;
    $tw    = imagefontwidth($size) * strlen($label);
    $x     = (int) max(24, ($w - $tw) / 2);
    imagestring($im, $size, $x, (int) ($h / 2) - 20, $label, $text);
    imagestring($im, 3, 48, (int) ($h / 2) + 16, 'Staff registration portal', $text);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
imagepng($im);
imagedestroy($im);
