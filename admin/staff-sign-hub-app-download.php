<?php

declare(strict_types=1);

/**
 * Admin — Staff Sign Hub APK / web download page.
 * Records download events when APK is fetched; web app tracks its own opens/installs.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/platform/staff-sign-hub.php';
require_once __DIR__ . '/../includes/staff-sign-hub-installs.php';

requireAdminCapability('attendance');

header('X-Olasentra-Staff-Sign-Hub-Apk: admin-controlled');

$apkRelative = '../storage/mobile/android/olasentra-staff-sign-hub-latest.apk';
$apkPath     = __DIR__ . '/' . $apkRelative;
if (!is_file($apkPath)) {
    $apkPath = dirname(__DIR__) . '/storage/mobile/android/olasentra-staff-sign-hub-latest.apk';
}

$version = '1.0.0';
$appName = 'Olasentra Staff Sign Hub';
$wantsDownload = isset($_GET['download']) || isset($_GET['apk']);

if ($wantsDownload) {
    if (!is_file($apkPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Staff Sign Hub APK not packaged yet. Use the web app link from Staff Sign Hub.';
        exit;
    }

    // Log APK download (admin-distributed). visitor_key optional from query.
    try {
        $pdo = getDB();
        $vk = normalizeStaffSignHubVisitorKey((string) ($_GET['visitor_key'] ?? ''));
        if ($vk === '') {
            $vk = bin2hex(random_bytes(16));
        }
        recordStaffSignHubDeviceEvent($pdo, 'download', [
            'visitor_key'  => $vk,
            'channel'      => 'apk',
            'display_mode' => 'standalone',
            'app'          => '1',
            'user_agent'   => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] staff sign hub apk download log: ' . $e->getMessage());
    }

    $size = filesize($apkPath);
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="olasentra-staff-sign-hub-v' . $version . '.apk"');
    header('Content-Length: ' . (string) $size);
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    readfile($apkPath);
    exit;
}

$ready = is_file($apkPath);
$mtime = $ready ? date('Y-m-d H:i', filemtime($apkPath)) : '';
$sizeMb = $ready ? number_format(filesize($apkPath) / 1048576, 2) : '';
$webApp = staffSignHubAppPublicUrl(0);
if ($webApp !== '' && !str_contains($webApp, 'app=1')) {
    $webApp .= (str_contains($webApp, '?') ? '&' : '?') . 'app=1';
}

$pageTitle  = 'Staff Sign APK';
$activePage = 'attendance';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= h($appName) ?></h2>
            <p class="card__subtitle">
                Standalone staff sign-in/out for <strong>all events</strong> (staff pick the event in-app).
                Short phone link: <strong><a href="<?= h(staffSignHubShortShareUrl()) ?>" target="_blank" rel="noopener"><?= h(staffSignHubShortShareUrl()) ?></a></strong>
                (iPhone &amp; Android). Installs appear in <a href="staff-sign-hub.php">Staff Sign Hub report</a>.
            </p>
        </div>
        <div class="toolbar toolbar--compact">
            <a href="staff-sign-hub.php" class="btn btn--secondary">Staff Sign Hub report</a>
            <a href="<?= h($webApp) ?>" target="_blank" rel="noopener" class="btn btn--primary">Open web app</a>
        </div>
    </div>

    <?php if ($ready): ?>
        <p class="form-hint" style="margin-bottom:1rem">
            Version <?= h($version) ?> · <?= h($sizeMb) ?> MB · updated <?= h($mtime) ?>
        </p>
        <a class="btn btn--primary" href="?download=1">Download Staff Sign APK</a>
    <?php else: ?>
        <div class="alert alert--info alert--visible">
            APK file is not on the server yet. Staff can use the <strong>web app</strong> now —
            opens are tracked in the install report as soon as they launch it and pick their name.
            Place the APK at <code>storage/mobile/android/olasentra-staff-sign-hub-latest.apk</code> when packaged.
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
