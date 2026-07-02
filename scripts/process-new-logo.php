<?php

declare(strict_types=1);

/**
 * Build clean transparent purple logo for dark theme (no black/white box).
 * CLI: php scripts/process-new-logo.php [source.png]
 */

$root = dirname(__DIR__);
$candidates = [
    $argv[1] ?? '',
    $root . '/storage/branding/source-logo.png',
    dirname($root) . '/Desktop/Olasentra-Personal-Handover/branding/olasentra-logo-master.png',
    $root . '/storage/branding/olasentra-logo-master.png',
];

$srcPath = '';
foreach ($candidates as $candidate) {
    if ($candidate !== '' && is_file($candidate)) {
        $srcPath = $candidate;
        break;
    }
}

if ($srcPath === '') {
    fwrite(STDERR, "No source logo found.\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD required.\n");
    exit(1);
}

$info = getimagesize($srcPath);
if ($info === false) {
    fwrite(STDERR, "Invalid image: {$srcPath}\n");
    exit(1);
}

$src = match ($info[2]) {
    IMAGETYPE_PNG  => imagecreatefrompng($srcPath),
    IMAGETYPE_JPEG => imagecreatefromjpeg($srcPath),
    default        => false,
};

if ($src === false) {
    fwrite(STDERR, "Load failed.\n");
    exit(1);
}

$w = imagesx($src);
$h = imagesy($src);

$isBackground = static function (int $r, int $g, int $b): bool {
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $sat = $max - $min;

    if ($max < 32) {
        return true;
    }
    if ($max < 60 && $sat < 28) {
        return true;
    }

    return false;
};

$cut = imagecreatetruecolor($w, $h);
imagealphablending($cut, false);
imagesavealpha($cut, true);
$clear = imagecolorallocatealpha($cut, 0, 0, 0, 127);
imagefilledrectangle($cut, 0, 0, $w, $h, $clear);

for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgba = imagecolorat($src, $x, $y);
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;

        if ($isBackground($r, $g, $b)) {
            continue;
        }

        $color = imagecolorallocatealpha($cut, $r, $g, $b, 0);
        imagesetpixel($cut, $x, $y, $color);
    }
}

$minX = $w;
$minY = $h;
$maxX = 0;
$maxY = 0;

for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $a = (imagecolorat($cut, $x, $y) >> 24) & 0x7F;
        if ($a < 120) {
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }
    }
}

if ($maxX <= $minX) {
    fwrite(STDERR, "Nothing left after background removal.\n");
    exit(1);
}

$cropW = $maxX - $minX + 1;
$cropH = $maxY - $minY + 1;
$cropped = imagecreatetruecolor($cropW, $cropH);
imagealphablending($cropped, false);
imagesavealpha($cropped, true);
imagefilledrectangle($cropped, 0, 0, $cropW, $cropH, $clear);
imagecopy($cropped, $cut, 0, 0, $minX, $minY, $cropW, $cropH);

$size = 512;
$out = imagecreatetruecolor($size, $size);
imagealphablending($out, false);
imagesavealpha($out, true);
imagefilledrectangle($out, 0, 0, $size, $size, $clear);
imagealphablending($out, true);

$pad = (int) round($size * 0.04);
$target = $size - ($pad * 2);
$scale = min($target / $cropW, $target / $cropH);
$newW = (int) round($cropW * $scale);
$newH = (int) round($cropH * $scale);
$dstX = (int) (($size - $newW) / 2);
$dstY = (int) (($size - $newH) / 2);
imagecopyresampled($out, $cropped, $dstX, $dstY, 0, 0, $newW, $newH, $cropW, $cropH);

$targets = [
    $root . '/new-logo.png',
    $root . '/storage/branding/olasentra-logo-master.png',
    $root . '/storage/branding/mobile/app-logo.png',
    $root . '/storage/branding/mobile/splash-logo.png',
    $root . '/storage/branding/mobile/login-logo.png',
    $root . '/storage/branding/mobile/dashboard-logo.png',
];

foreach ($targets as $path) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    imagepng($out, $path, 9);
    echo "Wrote {$path}\n";
}

imagedestroy($src);
imagedestroy($cut);
imagedestroy($cropped);
imagedestroy($out);
echo "Source: {$srcPath}\nDone.\n";
