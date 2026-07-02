<?php

declare(strict_types=1);

/**
 * Generate static PWA / Play Store icons from Olasentra branding master logo.
 *
 * CLI: php scripts/generate-pwa-icons.php
 */

$root = dirname(__DIR__);
$src  = $root . '/storage/branding/olasentra-logo-master.png';
$outDir = $root . '/assets/icons/pwa';

if (!is_file($src)) {
    fwrite(STDERR, "Missing source logo: {$src}\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension required.\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

$source = imagecreatefrompng($src);
if ($source === false) {
    fwrite(STDERR, "Failed to load PNG.\n");
    exit(1);
}

$sizes = [
    'icon-48.png'   => 48,
    'icon-72.png'   => 72,
    'icon-96.png'   => 96,
    'icon-144.png'  => 144,
    'icon-180.png'  => 180,
    'icon-192.png'  => 192,
    'icon-512.png'  => 512,
    'icon-maskable-512.png' => 512,
];

foreach ($sizes as $filename => $size) {
    $canvas = imagecreatetruecolor($size, $size);
    if ($canvas === false) {
        continue;
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
    imagealphablending($canvas, true);

    $srcW = imagesx($source);
    $srcH = imagesy($source);
    $isMaskable = str_contains($filename, 'maskable');
    $padding = $isMaskable ? (int) round($size * 0.1) : (int) round($size * 0.08);
    $target = $size - ($padding * 2);
    $scale  = min($target / $srcW, $target / $srcH);
    $newW   = (int) round($srcW * $scale);
    $newH   = (int) round($srcH * $scale);
    $dstX   = (int) (($size - $newW) / 2);
    $dstY   = (int) (($size - $newH) / 2);

    if ($isMaskable) {
        $bg = imagecolorallocate($canvas, 15, 23, 42);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);
    }

    imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
    $path = $outDir . '/' . $filename;
    imagepng($canvas, $path, 9);
    imagedestroy($canvas);
    echo "Wrote {$path}\n";
}

imagedestroy($source);
echo "Done.\n";
