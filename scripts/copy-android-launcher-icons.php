<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$src  = $root . '/assets/icons/pwa/icon-512.png';
$res  = $root . '/android/olasentra-staff-twa/app/src/main/res';
$map  = [
    'mipmap-mdpi'    => 48,
    'mipmap-hdpi'    => 72,
    'mipmap-xhdpi'   => 96,
    'mipmap-xxhdpi'  => 144,
    'mipmap-xxxhdpi' => 192,
];

if (!is_file($src)) {
    fwrite(STDERR, "Missing {$src}\n");
    exit(1);
}

$source = imagecreatefrompng($src);
foreach ($map as $folder => $size) {
    $dir = $res . '/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        continue;
    }
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
    imagealphablending($canvas, true);
    $srcW = imagesx($source);
    $srcH = imagesy($source);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $size, $size, $srcW, $srcH);
    $launcher = $dir . '/ic_launcher.png';
    $round    = $dir . '/ic_launcher_round.png';
    imagepng($canvas, $launcher, 9);
    copy($launcher, $round);
    imagedestroy($canvas);
    echo "Wrote {$launcher}\n";
}
imagedestroy($source);
