<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/mobile/schema/mobile-app-release-schema.php';

function staffAppAndroidStorageRoot(): string
{
    return dirname(__DIR__) . '/storage/mobile/android';
}

function staffAppAndroidAllowedStoragePrefix(): string
{
    return 'storage/mobile/android/';
}

function staffAppAndroidNormalizeRelativePath(string $relative): string
{
    $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
    if ($relative === '' || str_contains($relative, '..')) {
        return '';
    }

    if (!str_starts_with($relative, staffAppAndroidAllowedStoragePrefix())) {
        return '';
    }

    return $relative;
}

function staffAppAndroidAbsolutePathFromRelative(string $relative): string
{
    $relative = staffAppAndroidNormalizeRelativePath($relative);
    if ($relative === '') {
        return '';
    }

    $root = dirname(__DIR__);
    $full = $root . '/' . $relative;

    return is_file($full) ? $full : '';
}

function staffAppAndroidApkRelativePath(PDO $pdo): string
{
    ensureMobileAppReleaseSchema($pdo);
    $current = mobileAppReleaseCurrent($pdo);
    if ($current !== null && ($current['apk_relative_path'] ?? '') !== '') {
        return (string) $current['apk_relative_path'];
    }

    return trim(getSetting($pdo, 'mobile_android_apk_path', ''));
}

function staffAppAndroidApkAbsolutePath(PDO $pdo): string
{
    $relative = staffAppAndroidApkRelativePath($pdo);
    if ($relative === '') {
        return '';
    }

    return staffAppAndroidAbsolutePathFromRelative($relative);
}

function staffAppAndroidDownloadPageUrl(PDO $pdo): string
{
    return rtrim(getRegistrationSiteUrl($pdo), '/') . '/staff-app-download.php';
}

function staffAppAndroidDownloadUrl(PDO $pdo): string
{
    if (staffAppAndroidApkAbsolutePath($pdo) === '') {
        return '';
    }

    return staffAppAndroidDownloadPageUrl($pdo) . '?download=1';
}

function staffAppAndroidVersionLabel(PDO $pdo): string
{
    ensureMobileAppReleaseSchema($pdo);
    $current = mobileAppReleaseCurrent($pdo);
    if ($current !== null && ($current['version_name'] ?? '') !== '') {
        return (string) $current['version_name'];
    }

    $label = trim(getSetting($pdo, 'mobile_portal_version_label', ''));

    return $label !== '' ? $label : trim(getSetting($pdo, 'mobile_min_app_version', '1.0.0'));
}

function staffAppAndroidFormatFileSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes / 1048576, 2) . ' MB';
}

/**
 * @return array<string, mixed>|null
 */
function mobileAppReleaseCurrent(PDO $pdo): ?array
{
    ensureMobileAppReleaseSchema($pdo);

    try {
        $stmt = $pdo->query(
            'SELECT * FROM mobile_app_releases WHERE is_current = 1 ORDER BY released_at DESC, id DESC LIMIT 1'
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function mobileAppReleaseList(PDO $pdo, int $limit = 50): array
{
    ensureMobileAppReleaseSchema($pdo);
    $limit = max(1, min($limit, 200));

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM mobile_app_releases ORDER BY released_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array{ok: bool, error?: string, release_id?: int}
 */
function mobileAppReleaseRegister(
    PDO $pdo,
    string $versionName,
    int $versionCode,
    string $apkRelativePath,
    int $apkBytes,
    string $releaseNotes = '',
    ?string $aabRelativePath = null,
    ?int $aabBytes = null,
    ?int $adminId = null,
    bool $setCurrent = true
): array {
    ensureMobileAppReleaseSchema($pdo);

    $versionName = trim($versionName);
    if ($versionName === '' || !preg_match('/^\d+\.\d+\.\d+$/', $versionName)) {
        return ['ok' => false, 'error' => 'Version must use format 1.0.17'];
    }

    $apkRelativePath = staffAppAndroidNormalizeRelativePath($apkRelativePath);
    if ($apkRelativePath === '' || staffAppAndroidAbsolutePathFromRelative($apkRelativePath) === '') {
        return ['ok' => false, 'error' => 'APK file is missing or outside allowed storage.'];
    }

    if ($aabRelativePath !== null && $aabRelativePath !== '') {
        $aabRelativePath = staffAppAndroidNormalizeRelativePath($aabRelativePath);
        if ($aabRelativePath === '' || staffAppAndroidAbsolutePathFromRelative($aabRelativePath) === '') {
            return ['ok' => false, 'error' => 'AAB file is missing or outside allowed storage.'];
        }
    } else {
        $aabRelativePath = null;
        $aabBytes        = null;
    }

    $versionCode = max(0, $versionCode);
    $apkBytes    = max(0, $apkBytes);
    $notes       = trim($releaseNotes);
    $releasedAt  = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        if ($setCurrent) {
            $pdo->exec('UPDATE mobile_app_releases SET is_current = 0 WHERE is_current = 1');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO mobile_app_releases
                (version_name, version_code, apk_relative_path, aab_relative_path, apk_bytes, aab_bytes,
                 release_notes, is_current, released_at, created_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                version_code = VALUES(version_code),
                apk_relative_path = VALUES(apk_relative_path),
                aab_relative_path = VALUES(aab_relative_path),
                apk_bytes = VALUES(apk_bytes),
                aab_bytes = VALUES(aab_bytes),
                release_notes = VALUES(release_notes),
                is_current = VALUES(is_current),
                released_at = VALUES(released_at),
                created_by_admin_id = VALUES(created_by_admin_id)'
        );
        $stmt->execute([
            $versionName,
            $versionCode,
            $apkRelativePath,
            $aabRelativePath,
            $apkBytes,
            $aabBytes,
            $notes !== '' ? $notes : null,
            $setCurrent ? 1 : 0,
            $releasedAt,
            $adminId,
        ]);

        $releaseId = (int) $pdo->lastInsertId();
        if ($releaseId === 0) {
            $lookup = $pdo->prepare('SELECT id FROM mobile_app_releases WHERE version_name = ? LIMIT 1');
            $lookup->execute([$versionName]);
            $releaseId = (int) $lookup->fetchColumn();
        }

        if ($setCurrent) {
            mobileAppReleaseApplySettings($pdo, $versionName, $versionCode, $apkRelativePath, $aabRelativePath, $notes);
        }

        $pdo->commit();

        return ['ok' => true, 'release_id' => $releaseId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Could not save release record.'];
    }
}

function mobileAppReleaseApplySettings(
    PDO $pdo,
    string $versionName,
    int $versionCode,
    string $apkRelativePath,
    ?string $aabRelativePath,
    string $releaseNotes
): void {
    $settings = [
        'mobile_android_apk_path'     => $apkRelativePath,
        'mobile_portal_version_label' => $versionName,
        'mobile_portal_version_notes' => $releaseNotes,
        'mobile_api_enabled'          => '1',
        'mobile_android_version_code' => (string) max(0, $versionCode),
        'mobile_android_released_at'  => date('c'),
    ];

    if ($aabRelativePath !== null && $aabRelativePath !== '') {
        $settings['mobile_android_aab_path'] = $aabRelativePath;
    }

    saveSettings($pdo, $settings);
    clearSettingsCache();
}

/**
 * @return array{ok: bool, error?: string}
 */
function mobileAppReleaseSetCurrent(PDO $pdo, int $releaseId): array
{
    ensureMobileAppReleaseSchema($pdo);

    try {
        $stmt = $pdo->prepare('SELECT * FROM mobile_app_releases WHERE id = ? LIMIT 1');
        $stmt->execute([$releaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Release not found.'];
        }

        if (staffAppAndroidAbsolutePathFromRelative((string) $row['apk_relative_path']) === '') {
            return ['ok' => false, 'error' => 'APK file for this release is missing on disk.'];
        }

        $pdo->beginTransaction();
        $pdo->exec('UPDATE mobile_app_releases SET is_current = 0 WHERE is_current = 1');
        $upd = $pdo->prepare('UPDATE mobile_app_releases SET is_current = 1 WHERE id = ?');
        $upd->execute([$releaseId]);
        mobileAppReleaseApplySettings(
            $pdo,
            (string) $row['version_name'],
            (int) $row['version_code'],
            (string) $row['apk_relative_path'],
            $row['aab_relative_path'] !== null ? (string) $row['aab_relative_path'] : null,
            (string) ($row['release_notes'] ?? '')
        );
        $pdo->commit();

        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Could not activate release.'];
    }
}

/**
 * @return array{ok: bool, error?: string, relative?: string}
 */
function mobileAppReleaseStoreUploadedFile(array $file, string $kind): array
{
    $kind = strtolower($kind);
    if (!in_array($kind, ['apk', 'aab'], true)) {
        return ['ok' => false, 'error' => 'Invalid package type.'];
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }

    $original = strtolower((string) ($file['name'] ?? ''));
    $ext      = $kind === 'apk' ? '.apk' : '.aab';
    if (!str_ends_with($original, $ext)) {
        return ['ok' => false, 'error' => 'File must be ' . strtoupper($ext) . '.'];
    }

    $destDir = staffAppAndroidStorageRoot();
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return ['ok' => false, 'error' => 'Storage directory is not writable.'];
    }

    $safeName = 'upload-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . $ext;
    $destFs   = $destDir . '/' . $safeName;
    if (!move_uploaded_file($tmp, $destFs)) {
        return ['ok' => false, 'error' => 'Could not save uploaded file.'];
    }

    return [
        'ok'       => true,
        'relative' => staffAppAndroidAllowedStoragePrefix() . $safeName,
        'bytes'    => (int) filesize($destFs),
    ];
}

/**
 * @return array{ok: bool, error?: string}
 */
function staffAppAndroidStreamDownload(PDO $pdo): array
{
    $path = staffAppAndroidApkAbsolutePath($pdo);
    if ($path === '') {
        return ['ok' => false, 'error' => 'Android app package is not available yet.'];
    }

    $filename = basename($path);
    $size     = filesize($path);
    if ($size === false) {
        return ['ok' => false, 'error' => 'Unable to read app package.'];
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . (string) $size);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
        return ['ok' => true];
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'Unable to open app package.'];
    }

    fpassthru($handle);
    fclose($handle);

    return ['ok' => true];
}

function staffAppAndroidRenderDownloadPage(PDO $pdo): void
{
    ensureMobileAppReleaseSchema($pdo);

    $current     = mobileAppReleaseCurrent($pdo);
    $history     = mobileAppReleaseList($pdo, 10);
    $downloadUrl = staffAppAndroidDownloadUrl($pdo);
    $pageUrl     = staffAppAndroidDownloadPageUrl($pdo);
    $siteName    = trim(getSetting($pdo, 'mobile_portal_app_name', 'Olasentra'));
    if ($siteName === '') {
        $siteName = 'Olasentra';
    }

    $versionLabel = staffAppAndroidVersionLabel($pdo);
    $buildCode    = $current !== null
        ? (int) ($current['version_code'] ?? 0)
        : (int) getSetting($pdo, 'mobile_android_version_code', '0');
    $releasedAt   = $current['released_at'] ?? getSetting($pdo, 'mobile_android_released_at', '');
    $apkBytes     = $current !== null
        ? (int) ($current['apk_bytes'] ?? 0)
        : (int) (filesize(staffAppAndroidApkAbsolutePath($pdo) ?: '') ?: 0);
    $notes        = trim((string) ($current['release_notes'] ?? getSetting($pdo, 'mobile_portal_version_notes', '')));

    $releaseDisplay = '—';
    if ($releasedAt !== '') {
        try {
            $dt = new DateTimeImmutable($releasedAt);
            $releaseDisplay = $dt->format('d/m/Y H:i');
        } catch (Throwable $e) {
            $releaseDisplay = $releasedAt;
        }
    }

    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($siteName) ?> — Staff App Download</title>
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .app-dl-wrap { max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        .app-dl-meta { display: grid; gap: 0.75rem; margin: 1.25rem 0; }
        .app-dl-meta div { display: flex; justify-content: space-between; gap: 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.5rem; }
        .app-dl-actions { margin-top: 1.5rem; display: flex; flex-wrap: wrap; gap: 0.75rem; }
        .app-dl-history { margin-top: 2rem; }
        .app-dl-history table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        .app-dl-history th, .app-dl-history td { text-align: left; padding: 0.5rem 0.35rem; border-bottom: 1px solid rgba(255,255,255,0.08); }
    </style>
</head>
<body class="login-page">
<main class="app-dl-wrap">
    <section class="card">
        <div class="card__header">
            <h1 class="card__title"><?= h($siteName) ?> Staff App</h1>
            <p class="card__subtitle">Install the latest Android app for shifts, attendance, and notifications.</p>
        </div>
        <div class="card__body">
            <?php if ($downloadUrl === ''): ?>
                <p>No approved release is available yet. Check back soon or contact your manager.</p>
            <?php else: ?>
                <div class="app-dl-meta">
                    <div><span>Current version</span><strong><?= h($versionLabel) ?></strong></div>
                    <div><span>Build number</span><strong><?= $buildCode > 0 ? (int) $buildCode : '—' ?></strong></div>
                    <div><span>Release date</span><strong><?= h($releaseDisplay) ?></strong></div>
                    <div><span>File size</span><strong><?= h($apkBytes > 0 ? staffAppAndroidFormatFileSize($apkBytes) : '—') ?></strong></div>
                </div>
                <?php if ($notes !== ''): ?>
                    <p><strong>Release notes</strong><br><?= nl2br(h($notes)) ?></p>
                <?php endif; ?>
                <div class="app-dl-actions">
                    <a class="btn btn--primary" href="<?= h($downloadUrl) ?>">Download APK</a>
                </div>
                <p class="form-hint" style="margin-top:1rem;">On Android, allow installs from this browser if prompted. Package: <code>com.olasentra.app</code></p>
            <?php endif; ?>
        </div>
    </section>

    <?php if (count($history) > 1): ?>
    <section class="card app-dl-history">
        <div class="card__header">
            <h2 class="card__title" style="font-size:1.1rem;">Previous releases</h2>
        </div>
        <table>
            <thead>
                <tr><th>Version</th><th>Build</th><th>Released</th><th>Size</th></tr>
            </thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <?php if (!empty($row['is_current'])) { continue; } ?>
                <tr>
                    <td><?= h((string) $row['version_name']) ?></td>
                    <td><?= (int) ($row['version_code'] ?? 0) ?></td>
                    <td><?= h((string) $row['released_at']) ?></td>
                    <td><?= h(staffAppAndroidFormatFileSize((int) ($row['apk_bytes'] ?? 0))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <p style="text-align:center;margin-top:1.5rem;opacity:0.7;font-size:0.9rem;">
        <a href="<?= h($pageUrl) ?>"><?= h($pageUrl) ?></a>
    </p>
</main>
</body>
</html>
    <?php
}
