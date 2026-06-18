<?php

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/theme.php';

function getCompanyLogoRelativePath(PDO $pdo): string
{
    return trim(getSetting($pdo, 'company_logo', ''));
}

function getCompanyLogoFilesystemPath(PDO $pdo): string
{
    $relative = getCompanyLogoRelativePath($pdo);
    if ($relative === '') {
        return '';
    }

    $full = dirname(__DIR__) . '/' . str_replace(['\\', '..'], ['/', ''], $relative);
    return is_file($full) ? $full : '';
}

function getCompanyLogoUrl(?PDO $pdo, string $assetBase = ''): string
{
    if ($pdo === null) {
        return '';
    }

    $relative = getCompanyLogoRelativePath($pdo);
    if ($relative === '' || getCompanyLogoFilesystemPath($pdo) === '') {
        return '';
    }

    return $assetBase . $relative;
}

function hasCompanyLogo(?PDO $pdo): bool
{
    return getCompanyLogoUrl($pdo) !== '';
}

/** @return true|string true on success, error message otherwise */
function handleCompanyLogoUpload(PDO $pdo, array $file): bool|string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return true;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return 'Logo upload failed. Please try again.';
    }

    $maxBytes = 2 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return 'Logo must be 2 MB or smaller.';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name'] ?? '') ?: '';
    $allowed = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return 'Logo must be PNG, JPG, WebP, or GIF.';
    }

    $dir = dirname(__DIR__) . '/storage/branding';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return 'Unable to create logo storage folder.';
    }

    deleteCompanyLogoFile($pdo);

    $filename = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target   = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Unable to save uploaded logo.';
    }

    saveSettings($pdo, ['company_logo' => 'storage/branding/' . $filename]);

    return true;
}

function deleteCompanyLogoFile(PDO $pdo): void
{
    $path = getCompanyLogoFilesystemPath($pdo);
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
    saveSettings($pdo, ['company_logo' => '']);
}

function getCompanyShareImageRelativePath(PDO $pdo): string
{
    return trim(getSetting($pdo, 'company_share_image', ''));
}

function getCompanyShareImageFilesystemPath(PDO $pdo): string
{
    $relative = getCompanyShareImageRelativePath($pdo);
    if ($relative === '') {
        return '';
    }

    $full = dirname(__DIR__) . '/' . str_replace(['\\', '..'], ['/', ''], $relative);

    return is_file($full) ? $full : '';
}

function getCompanyShareImageUrl(?PDO $pdo, string $assetBase = ''): string
{
    if ($pdo === null) {
        return '';
    }

    $relative = getCompanyShareImageRelativePath($pdo);
    if ($relative === '' || getCompanyShareImageFilesystemPath($pdo) === '') {
        return '';
    }

    return $assetBase . $relative;
}

/** @return true|string */
function handleCompanyShareImageUpload(PDO $pdo, array $file): bool|string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return true;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return 'WhatsApp preview image upload failed. Please try again.';
    }

    $maxBytes = 3 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return 'WhatsApp preview image must be 3 MB or smaller.';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name'] ?? '') ?: '';
    $allowed = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        return 'WhatsApp preview must be PNG or JPG (1200×630 recommended).';
    }

    $dir = dirname(__DIR__) . '/storage/branding';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return 'Unable to create branding storage folder.';
    }

    deleteCompanyShareImageFile($pdo);

    $filename = 'share-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target   = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Unable to save WhatsApp preview image.';
    }

    saveSettings($pdo, ['company_share_image' => 'storage/branding/' . $filename]);

    return true;
}

function deleteCompanyShareImageFile(PDO $pdo): void
{
    $path = getCompanyShareImageFilesystemPath($pdo);
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
    saveSettings($pdo, ['company_share_image' => '']);
}

/**
 * Load a raster image for GD compositing.
 *
 * @return GdImage|null
 */
function brandLogoLoadGdImage(string $path)
{
    $mime = mime_content_type($path) ?: '';
    if ($mime === 'image/png') {
        $im = @imagecreatefrompng($path);

        return $im !== false ? $im : null;
    }
    if ($mime === 'image/jpeg') {
        $im = @imagecreatefromjpeg($path);

        return $im !== false ? $im : null;
    }
    if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $im = @imagecreatefromwebp($path);

        return $im !== false ? $im : null;
    }
    if ($mime === 'image/gif') {
        $im = @imagecreatefromgif($path);

        return $im !== false ? $im : null;
    }

    return null;
}

/**
 * Build a 1200×630 WhatsApp / Open Graph card from the company logo (never serve raw 8K logo).
 */
function outputOgShareImage(PDO $pdo): void
{
    $sharePath = getCompanyShareImageFilesystemPath($pdo);
    if ($sharePath !== '') {
        $im = brandLogoLoadGdImage($sharePath);
        if ($im !== null) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=86400');
            imagejpeg($im, null, 82);
            imagedestroy($im);

            return;
        }

        $mime = mime_content_type($sharePath) ?: 'image/png';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        readfile($sharePath);

        return;
    }

    $logoPath = getCompanyLogoFilesystemPath($pdo);
    $siteName = getSiteName($pdo);
    $tagline  = trim(getSetting($pdo, 'company_tagline', 'Security updates & shift portal'));
    if ($tagline === '') {
        $tagline = 'Security updates & shift portal';
    }

    $w = 1200;
    $h = 630;
    $canvas = imagecreatetruecolor($w, $h);
    if ($canvas === false) {
        http_response_code(500);
        return;
    }

    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);

    $navy = imagecolorallocate($canvas, 15, 23, 42);
    $blue = imagecolorallocate($canvas, 37, 99, 235);
    $purple = imagecolorallocate($canvas, 99, 102, 241);
    $white = imagecolorallocate($canvas, 248, 250, 252);
    $muted = imagecolorallocate($canvas, 148, 163, 184);

    for ($y = 0; $y < $h; $y++) {
        $ratio = $y / max(1, $h - 1);
        $r = (int) (15 + (37 - 15) * $ratio * 0.35);
        $g = (int) (23 + (99 - 23) * $ratio * 0.35);
        $b = (int) (42 + (235 - 42) * $ratio * 0.25);
        $line = imagecolorallocate($canvas, $r, $g, $b);
        imageline($canvas, 0, $y, $w, $y, $line);
    }

    imagefilledrectangle($canvas, 0, 0, $w, 10, $blue);
    imagefilledrectangle($canvas, 0, $h - 6, $w, $h, $purple);

    if ($logoPath !== '') {
        $logo = brandLogoLoadGdImage($logoPath);
        if (is_resource($logo) || (class_exists('GdImage', false) && $logo instanceof GdImage)) {
            $lw = imagesx($logo);
            $lh = imagesy($logo);
            $maxW = (int) ($w * 0.72);
            $maxH = (int) ($h * 0.55);
            $scale = min($maxW / max(1, $lw), $maxH / max(1, $lh), 1.0);
            $dw = max(1, (int) round($lw * $scale));
            $dh = max(1, (int) round($lh * $scale));
            $dx = (int) (($w - $dw) / 2);
            $dy = (int) (($h - $dh) / 2) - 24;
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $logo, $dx, $dy, 0, 0, $dw, $dh, $lw, $lh);
            imagedestroy($logo);
        }
    } else {
        $label = $siteName !== '' ? $siteName : 'Olasentra Security Updates';
        $tw = imagefontwidth(5) * strlen($label);
        imagestring($canvas, 5, (int) max(24, ($w - $tw) / 2), (int) ($h / 2) - 30, $label, $white);
    }

    $sub = strlen($tagline) > 72 ? substr($tagline, 0, 69) . '...' : $tagline;
    $sw = imagefontwidth(4) * strlen($sub);
    imagestring($canvas, 4, (int) max(24, ($w - $sw) / 2), (int) ($h / 2) + 120, $sub, $muted);

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    imagepng($canvas);
    imagedestroy($canvas);
}

/**
 * Company logo on staff portal / app (header, hero, sign-in card). Falls back to theme icon.
 */
function renderStaffBrandLogo(?PDO $pdo, string $wrapperClass = 'staff-public-header__logo', string $assetBase = '', string $alt = ''): void
{
    $url     = getCompanyLogoUrl($pdo, $assetBase);
    $altText = $alt !== '' ? $alt : ($pdo ? getSiteName($pdo) : 'Company logo');

    if ($url !== '') {
        echo '<span class="' . h($wrapperClass) . ' staff-brand-logo--has-image">';
        echo '<img class="staff-brand-logo__img" src="' . h($url) . '" alt="' . h($altText) . '" decoding="async">';
        echo '</span>';

        return;
    }

    echo '<span class="' . h($wrapperClass) . ' brand-icon" aria-hidden="true">';
    echo $pdo ? renderThemeBrandIcon($pdo) : renderThemeCategoryIcon('events');
    echo '</span>';
}

/**
 * @param 'header'|'footer'|'hero' $variant
 */
function renderSiteBrandLogo(?PDO $pdo, string $variant = 'header', string $assetBase = '', string $alt = ''): void
{
    $url = getCompanyLogoUrl($pdo, $assetBase);
    $class = 'site-brand-logo site-brand-logo--' . preg_replace('/[^a-z]/', '', $variant);

    if ($url !== '') {
        echo '<img class="' . h($class) . '" src="' . h($url) . '" alt="' . h($alt !== '' ? $alt : 'Company logo') . '" decoding="async">';
        return;
    }

    echo '<span class="' . h($class) . ' site-brand-logo--fallback" aria-hidden="true">';
    echo $pdo ? renderThemeCategoryIcon(getThemeCategory($pdo)) : renderThemeCategoryIcon('security');
    echo '</span>';
}
