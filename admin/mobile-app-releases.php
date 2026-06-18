<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/staff-app-android.php';

requireAdminCapability('settings');

$pdo = getDB();

$success = (string) ($_SESSION['mobile_release_flash_success'] ?? '');
$error   = (string) ($_SESSION['mobile_release_flash_error'] ?? '');
unset($_SESSION['mobile_release_flash_success'], $_SESSION['mobile_release_flash_error']);

$current   = mobileAppReleaseCurrent($pdo);
$releases  = mobileAppReleaseList($pdo, 100);
$downloadPage = staffAppAndroidDownloadPageUrl($pdo);
$downloadApk  = staffAppAndroidDownloadUrl($pdo);
$settings     = getAllSettings($pdo);

$defaultVersion = trim((string) ($settings['mobile_portal_version_label'] ?? '1.0.17'));
$defaultBuild   = (int) ($settings['mobile_android_version_code'] ?? 17);

$pageTitle         = 'Staff app releases';
$activePage        = 'mobile-app-releases';
$erpSettingsActive = 'mobile';

include __DIR__ . '/../includes/admin/layout-top.php';
renderErpSettingsLayoutStart('mobile');
?>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">Android release deployment</h2>
        <p class="card__subtitle">
            Upload APK and AAB builds, manage version history, and control the public download page at
            <a href="<?= h($downloadPage) ?>" target="_blank" rel="noopener"><?= h($downloadPage) ?></a>.
        </p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="alert alert--info alert--visible" style="margin-bottom:1rem;">
        <strong>Current live release</strong><br>
        <?php if ($current !== null): ?>
            Version <strong><?= h((string) $current['version_name']) ?></strong>
            · Build <strong><?= (int) ($current['version_code'] ?? 0) ?></strong>
            · <?= h(staffAppAndroidFormatFileSize((int) ($current['apk_bytes'] ?? 0))) ?>
            · Released <?= h((string) $current['released_at']) ?>
            <?php if ($downloadApk !== ''): ?>
                · <a href="<?= h($downloadApk) ?>" target="_blank" rel="noopener">Direct APK</a>
            <?php endif; ?>
        <?php else: ?>
            No release record yet. Upload an APK below or run the deploy script after a Gradle build.
        <?php endif; ?>
    </div>
</section>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">Upload new release</h2>
    </div>
    <form method="post" action="mobile-app-release-action.php" enctype="multipart/form-data" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="task" value="upload">

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label" for="version_name">Version number</label>
                <input class="form-input" type="text" id="version_name" name="version_name" required
                       pattern="\d+\.\d+\.\d+" placeholder="1.0.17"
                       value="<?= h($defaultVersion) ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="version_code">Build number</label>
                <input class="form-input" type="number" id="version_code" name="version_code" min="1" required
                       value="<?= $defaultBuild > 0 ? $defaultBuild : 17 ?>">
            </div>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="release_notes">Release notes</label>
            <textarea class="form-input" id="release_notes" name="release_notes" rows="3"
                      placeholder="What changed in this build?"><?= h((string) ($settings['mobile_portal_version_notes'] ?? '')) ?></textarea>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label" for="apk_file">APK (required)</label>
                <input class="form-input" type="file" id="apk_file" name="apk_file" accept=".apk,application/vnd.android.package-archive" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="aab_file">AAB (Play Store)</label>
                <input class="form-input" type="file" id="aab_file" name="aab_file" accept=".aab,application/octet-stream">
            </div>
        </div>

        <div class="form-group form-group--full">
            <label class="form-checkbox">
                <input type="checkbox" name="set_current" value="1" checked>
                <span>Make this the active staff download immediately</span>
            </label>
        </div>

        <div class="form-actions form-actions--end">
            <button type="submit" class="btn btn--primary">Upload release</button>
        </div>
    </form>
</section>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">Version history</h2>
        <p class="card__subtitle">Previous releases stay archived on the server. Roll back if a build needs to be re-published.</p>
    </div>

    <?php if ($releases === []): ?>
        <p class="card__subtitle">No releases recorded yet.</p>
    <?php else: ?>
        <table class="data-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Version</th>
                    <th>Build</th>
                    <th>Released</th>
                    <th>APK size</th>
                    <th>AAB</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($releases as $row): ?>
                <tr>
                    <td><?= h((string) $row['version_name']) ?></td>
                    <td><?= (int) ($row['version_code'] ?? 0) ?></td>
                    <td><?= h((string) $row['released_at']) ?></td>
                    <td><?= h(staffAppAndroidFormatFileSize((int) ($row['apk_bytes'] ?? 0))) ?></td>
                    <td><?= !empty($row['aab_relative_path']) ? 'Yes' : '—' ?></td>
                    <td><?= !empty($row['is_current']) ? '<span style="color:#15803d;font-weight:600;">Live</span>' : 'Archived' ?></td>
                    <td>
                        <?php if (empty($row['is_current'])): ?>
                            <form method="post" action="mobile-app-release-action.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="task" value="rollback">
                                <input type="hidden" name="release_id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn btn--secondary btn--sm"
                                        onclick="return confirm('Set version <?= h((string) $row['version_name']) ?> as the active staff download?');">
                                    Rollback
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (trim((string) ($row['release_notes'] ?? '')) !== ''): ?>
                <tr>
                    <td colspan="7" style="font-size:0.9rem;opacity:0.85;padding-top:0;">
                        <?= nl2br(h((string) $row['release_notes'])) ?>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">Automated deploy</h2>
    </div>
    <p class="card__subtitle">After a successful Gradle release build, run from the repo root:</p>
    <pre style="background:rgba(0,0,0,0.25);padding:1rem;border-radius:8px;overflow:auto;"><code>powershell -ExecutionPolicy Bypass -File .\scripts\build-and-deploy-android-release.ps1</code></pre>
    <p class="form-hint">This builds APK + AAB, uploads to production FTP, registers the release, and updates the download page.</p>
</section>

<?php
renderErpSettingsLayoutEnd();
include __DIR__ . '/../includes/admin/layout-bottom.php';
