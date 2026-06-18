<?php

declare(strict_types=1);

/**
 * Generate WhatsApp share card, PWA icons, and Android launcher assets from master logo.
 *
 * CLI: php scripts/generate-branding-assets.php
 */

$root = dirname(__DIR__);
$src  = $root . '/storage/branding/olasentra-logo-master.png';

if (!is_file($src)) {
    fwrite(STDERR, "Missing source logo: {$src}\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension required.\n");
    exit(1);
}

/** @return GdImage|null */
function brandingLoadImage(string $path)
{
    $info = @getimagesize($path);
    if ($info === false) {
        return null;
    }

    return match ($info[2]) {
        IMAGETYPE_PNG  => @imagecreatefrompng($path) ?: null,
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path) ?: null,
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
        default        => null,
    };
}

function brandingFillGradient(GdImage $canvas, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
{
    for ($y = 0; $y < $h; $y++) {
        $t = $h > 1 ? $y / ($h - 1) : 0;
        $r = (int) round($r1 + ($r2 - $r1) * $t);
        $g = (int) round($g1 + ($g2 - $g1) * $t);
        $b = (int) round($b1 + ($b2 - $b1) * $t);
        $color = imagecolorallocate($canvas, $r, $g, $b);
        imageline($canvas, 0, $y, $w - 1, $y, $color);
    }
}

function brandingCopyContained(GdImage $canvas, GdImage $source, int $padding = 0): void
{
    $size = imagesx($canvas);
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    $target = $size - ($padding * 2);
    $scale  = min($target / $srcW, $target / $srcH);
    $newW   = (int) round($srcW * $scale);
    $newH   = (int) round($srcH * $scale);
    $dstX   = (int) (($size - $newW) / 2);
    $dstY   = (int) (($size - $newH) / 2);
    imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
}

function brandingCopyContainedRect(GdImage $canvas, GdImage $source, int $dstX, int $dstY, int $maxW, int $maxH): void
{
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    $scale  = min($maxW / $srcW, $maxH / $srcH);
    $newW   = (int) round($srcW * $scale);
    $newH   = (int) round($srcH * $scale);
    $x = $dstX + (int) (($maxW - $newW) / 2);
    $y = $dstY + (int) (($maxH - $newH) / 2);
    imagecopyresampled($canvas, $source, $x, $y, 0, 0, $newW, $newH, $srcW, $srcH);
}

function brandingEnsureDir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create {$dir}");
    }
}

$source = brandingLoadImage($src);
if ($source === null) {
    fwrite(STDERR, "Failed to load master logo.\n");
    exit(1);
}

// WhatsApp / OG share card (1200×630)
$sharePath = $root . '/storage/branding/olasentra-whatsapp-share.png';
$share = imagecreatetruecolor(1200, 630);
imagealphablending($share, true);
imagesavealpha($share, true);
brandingFillGradient($share, 1200, 630, 91, 33, 182, 49, 46, 129);

$iconMaxH = 420;
$iconMaxW = 420;
brandingCopyContainedRect($share, $source, 120, (630 - $iconMaxH) / 2, $iconMaxW, $iconMaxH);

$white = imagecolorallocate($share, 255, 255, 255);
$lavender = imagecolorallocate($share, 196, 181, 253);
imagestring($share, 5, 580, 240, 'Olasentra', $white);
imagestring($share, 3, 580, 280, 'Security Updates', $lavender);
imagestring($share, 2, 580, 320, 'Event staff registration & check-in', $lavender);

imagepng($share, $sharePath, 9);
imagedestroy($share);
echo "Wrote {$sharePath}\n";

// PWA icons
$pwaDir = $root . '/assets/icons/pwa';
brandingEnsureDir($pwaDir);

$pwaSizes = [
    'icon-48.png'           => 48,
    'icon-72.png'           => 72,
    'icon-96.png'           => 96,
    'icon-144.png'          => 144,
    'icon-180.png'          => 180,
    'icon-192.png'          => 192,
    'icon-512.png'          => 512,
    'icon-maskable-512.png' => 512,
];

foreach ($pwaSizes as $filename => $size) {
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
    imagealphablending($canvas, true);

    $isMaskable = str_contains($filename, 'maskable');
    if ($isMaskable) {
        brandingFillGradient($canvas, $size, $size, 91, 33, 182, 76, 29, 149);
    }

    $padding = $isMaskable ? (int) round($size * 0.08) : (int) round($size * 0.04);
    brandingCopyContained($canvas, $source, $padding);

    $path = $pwaDir . '/' . $filename;
    imagepng($canvas, $path, 9);
    imagedestroy($canvas);
    echo "Wrote {$path}\n";
}

// Android adaptive icon foreground bitmap + legacy mipmaps
$androidRes = $root . '/android/olasentra-staff/app/src/main/res';
brandingEnsureDir($androidRes . '/drawable-nodpi');

$foregroundSize = 432;
$foreground = imagecreatetruecolor($foregroundSize, $foregroundSize);
imagealphablending($foreground, false);
imagesavealpha($foreground, true);
$transparent = imagecolorallocatealpha($foreground, 0, 0, 0, 127);
imagefilledrectangle($foreground, 0, 0, $foregroundSize, $foregroundSize, $transparent);
imagealphablending($foreground, true);
brandingCopyContained($foreground, $source, (int) round($foregroundSize * 0.06));
$fgPath = $androidRes . '/drawable-nodpi/ic_launcher_foreground_bitmap.png';
imagepng($foreground, $fgPath, 9);
imagedestroy($foreground);
echo "Wrote {$fgPath}\n";

$mipmapSizes = [
    'mipmap-mdpi'    => 48,
    'mipmap-hdpi'    => 72,
    'mipmap-xhdpi'   => 96,
    'mipmap-xxhdpi'  => 144,
    'mipmap-xxxhdpi' => 192,
];

foreach ($mipmapSizes as $folder => $size) {
    $dir = $androidRes . '/' . $folder;
    brandingEnsureDir($dir);

    foreach (['ic_launcher.png', 'ic_launcher_round.png'] as $name) {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, true);
        brandingFillGradient($canvas, $size, $size, 91, 33, 182, 76, 29, 149);
        brandingCopyContained($canvas, $source, (int) round($size * 0.06));
        $path = $dir . '/' . $name;
        imagepng($canvas, $path, 9);
        imagedestroy($canvas);
        echo "Wrote {$path}\n";
    }
}

// TWA project shares launcher icons
$twaRes = $root . '/android/olasentra-staff-twa/app/src/main/res';
if (is_dir($twaRes)) {
    foreach ($mipmapSizes as $folder => $size) {
        $dir = $twaRes . '/' . $folder;
        brandingEnsureDir($dir);
        foreach (['ic_launcher.png', 'ic_launcher_round.png'] as $name) {
            $from = $androidRes . '/' . $folder . '/' . $name;
            if (is_file($from)) {
                copy($from, $dir . '/' . $name);
                echo "Copied to {$dir}/{$name}\n";
            }
        }
    }
}

imagedestroy($source);
echo "Done.\n";
