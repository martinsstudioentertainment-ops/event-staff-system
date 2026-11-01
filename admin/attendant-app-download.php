<?php

declare(strict_types=1);

/**
 * Admin-only Attendant APK download.
 * https://admin.olasentra.com/attendant-app-download.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdminCapability('attendance');

// Marker: admin-only attendant apk gate
header('X-Olasentra-Attendant-Apk: admin-only');

$apkRelative = '../storage/mobile/android/olasentra-attendant-latest.apk';
$apkPath     = __DIR__ . '/' . $apkRelative;
// Fallback if admin vhost document root differs
if (!is_file($apkPath)) {
    $apkPath = dirname(__DIR__) . '/storage/mobile/android/olasentra-attendant-latest.apk';
}

$version = '1.0.6';
$appName = 'Olasentra Gate Check';
$wantsDownload = isset($_GET['download']) || isset($_GET['apk']);

if ($wantsDownload) {
    if (!is_file($apkPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Attendant APK not found on server yet.';
        exit;
    }

    $size = filesize($apkPath);
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="olasentra-attendant-v' . $version . '.apk"');
    header('Content-Length: ' . (string) $size);
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    readfile($apkPath);
    exit;
}

$ready = is_file($apkPath);
$mtime = $ready ? date('Y-m-d H:i', filemtime($apkPath)) : '';
$sizeMb = $ready ? number_format(filesize($apkPath) / 1048576, 2) : '';
$adminUser = getAdminUser();
$adminName = trim((string) (($adminUser['username'] ?? '') ?: ($adminUser['name'] ?? 'Admin')));

$pageTitle  = 'Attendant APK';
$activePage = 'attendance';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= h($appName) ?> — Admin only</h2>
            <p class="card__subtitle">
                Private gate-phone install. Not a public staff download.
                Signed in as <strong><?= h($adminName) ?></strong>.
            </p>
        </div>
        <a href="attendant.php" class="btn btn--secondary">Open Attendant</a>
    </div>

    <?php if ($ready): ?>
        <p class="form-hint" style="margin-bottom:1rem">
            Version <?= h($version) ?> · <?= h($sizeMb) ?> MB · updated <?= h($mtime) ?>
        </p>
        <a class="btn btn--primary" href="?download=1">Download Attendant APK</a>
    <?php else: ?>
        <div class="alert alert--warning alert--visible">APK file is not on the server yet.</div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
