<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/data-integrity.php';
require_once __DIR__ . '/../includes/platform/test-data-cleanup.php';
require_once __DIR__ . '/../includes/platform/data-integrity-reports.php';

requireAdminCapability('settings');

if (!isAdminSuperUser()) {
    setAdminFlash('error', 'Data integrity tools are limited to administrators.');
    header('Location: dashboard.php');
    exit;
}

$pdo      = getDB();
$applyPdo = getApplyVaultPdo();
ensureDataIntegritySchema($pdo);

$flash = getAdminFlash();
$audit = runFullDataIntegrityAudit($pdo, $applyPdo);
$testData = detectTestAccounts($pdo, $applyPdo);
$testEmails = collectTestEmailsForCleanup($pdo);
$vaultReview = collectVaultReviewAccounts($pdo, $applyPdo);
$vaultHealth = computeVaultHealthScore($applyPdo);
$integrityScore = computeDataIntegrityScore($audit, $vaultHealth);
$mergeRecs = buildMergeRecommendations($pdo, $applyPdo);
$trustQ = auditTrustScoreDataQuality($pdo);
$gpsReady = verifyGpsSignInReadiness($pdo);

if (($_SERVER['REQUEST_METHOD'] === 'POST') && ($_POST['action'] ?? '') === 'purge_test_data') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        setAdminFlash('error', 'Session expired. Try again.');
    } elseif (empty($_POST['confirm_purge'])) {
        setAdminFlash('error', 'Tick the confirmation box to purge test data.');
    } else {
        $result = runProductionTestDataCleanup($pdo, [
            'dry_run'                      => false,
            'purge_sheets'                 => true,
            'purge_service_account_sheets' => true,
        ]);
        $msg = 'Purged ' . (int) ($result['test_emails_found'] ?? 0) . ' test email(s). '
            . ($result['sheets']['message'] ?? '')
            . ' Test accounts remaining: ' . (int) ($result['test_accounts_remaining'] ?? 0) . '. '
            . 'GPS checks: ' . (int) ($result['gps_sign_in']['pass'] ?? 0) . ' pass, '
            . (int) ($result['gps_sign_in']['fail'] ?? 0) . ' fail.';
        if (!empty($result['errors'])) {
            setAdminFlash('warning', $msg . ' Issues: ' . implode('; ', $result['errors']));
        } else {
            setAdminFlash(($result['gps_sign_in']['ok'] ?? false) ? 'success' : 'warning', $msg);
        }
    }
    header('Location: data-integrity.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && ($_POST['action'] ?? '') === 'regenerate') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        setAdminFlash('error', 'Session expired. Try again.');
    } else {
        try {
            $result = generateSprint66DataIntegrityReports($pdo, $applyPdo);
            setAdminFlash($result['ok'] ? 'success' : 'error', $result['message']);
        } catch (Throwable $e) {
            setAdminFlash('error', 'Report generation failed: ' . $e->getMessage());
        }
    }
    header('Location: data-integrity.php');
    exit;
}

$pageTitle  = 'Data integrity';
$activePage = 'data-integrity';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="data-integrity-page">
<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Data integrity center</h2>
            <p class="card__subtitle">Sprint 6.6 — audit mode only. No automatic deletions or merges.</p>
        </div>
        <form method="post" style="display:flex;gap:0.5rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="regenerate">
            <button type="submit" class="btn btn--secondary">Regenerate reports</button>
            <a href="duplicate-merge.php" class="btn btn--primary">Review duplicates</a>
        </form>
    </div>

    <div class="platform-ops-grid">
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= (int) $integrityScore['score'] ?>%</div>
            <div class="platform-ops-metric__label">Data integrity (<?= h($integrityScore['grade']) ?>)</div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= (int) $vaultHealth['score'] ?>%</div>
            <div class="platform-ops-metric__label">Vault health</div>
        </div>
        <div class="platform-ops-metric platform-ops-metric--warn">
            <div class="platform-ops-metric__value"><?= count($mergeRecs) ?></div>
            <div class="platform-ops-metric__label">Merge recommendations</div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= count($testEmails) ?></div>
            <div class="platform-ops-metric__label">Test emails to purge</div>
        </div>
        <div class="platform-ops-metric platform-ops-metric--<?= ($gpsReady['ok'] ?? false) ? 'ok' : 'warn' ?>">
            <div class="platform-ops-metric__value"><?= (int) ($gpsReady['pass'] ?? 0) ?>/<?= (int) (($gpsReady['pass'] ?? 0) + ($gpsReady['fail'] ?? 0)) ?></div>
            <div class="platform-ops-metric__label">GPS sign-in checks</div>
        </div>
    </div>
</section>

<section class="card erp-card">
    <h3 class="card__title">Test data cleanup</h3>
    <p class="card__subtitle">Removes only obvious test emails (@olasentra-e2e.test, @example.com, demo@, e2e@, etc.) and API test spreadsheets. Real Gmail addresses are never auto-deleted.</p>
    <?php if ($testEmails === []): ?>
        <p class="form-hint">No test emails detected in staff or registrations.</p>
    <?php else: ?>
        <ul class="detail-list detail-list--compact" style="max-height:12rem;overflow:auto;margin-bottom:1rem">
            <?php foreach ($testEmails as $email): ?>
                <li><code><?= h($email) ?></code></li>
            <?php endforeach; ?>
        </ul>
        <form method="post" onsubmit="return confirm('Permanently delete the <?= count($testEmails) ?> test email(s) listed above?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="purge_test_data">
            <label class="form-checkbox">
                <input type="checkbox" name="confirm_purge" value="1" required>
                I confirm these are test accounts only — purge them from the server
            </label>
            <button type="submit" class="btn btn--secondary" style="margin-top:0.75rem">Purge test data now</button>
        </form>
    <?php endif; ?>
    <?php if ($vaultReview !== []): ?>
        <div class="alert alert--warning alert--visible" style="margin-top:1rem">
            <strong>Review only — not included in purge</strong>
            <p style="margin:0.5rem 0 0">These real emails have a test PSA value in Apply vault. Fix in Apply admin — do not purge.</p>
            <ul style="margin:0.5rem 0 0 1.25rem">
                <?php foreach ($vaultReview as $row): ?>
                    <li><code><?= h($row['email']) ?></code> — PSA <?= h($row['psa']) ?> (vault #<?= (int) $row['vault_id'] ?>)</li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</section>

<section class="card erp-card">
    <h3 class="card__title">GPS sign-in readiness</h3>
    <ul class="detail-list detail-list--compact">
        <?php foreach ($gpsReady['checks'] ?? [] as $check): ?>
            <li>
                <span class="badge badge--<?= !empty($check['ok']) ? 'approved' : 'rejected' ?>"><?= !empty($check['ok']) ? 'OK' : 'Fix' ?></span>
                <?= h((string) ($check['name'] ?? '')) ?>
                <?php if (trim((string) ($check['detail'] ?? '')) !== ''): ?>
                    <span class="text-muted"> — <?= h((string) $check['detail']) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="form-hint" style="margin-top:0.75rem">
        Live check-in at a venue still requires staff to be at the event GPS pin with geo sign-in ON.
        <?php if ($gpsReady['ok'] ?? false): ?>
            <strong>All automated GPS checks passed.</strong>
        <?php endif; ?>
    </p>
</section>

<section class="card erp-card">
    <h3 class="card__title">Audit summary</h3>
    <ul class="detail-list">
        <li>Duplicate phone groups (main): <?= count($audit['duplicate_phones_main'] ?? []) ?></li>
        <li>Duplicate phone groups (vault): <?= count($audit['duplicate_phones_vault'] ?? []) ?></li>
        <li>Duplicate PSA groups (vault): <?= count($audit['duplicate_psa_vault'] ?? []) ?></li>
        <li>Duplicate name+DOB profiles: <?= count($audit['duplicate_staff_profiles'] ?? []) ?></li>
        <li>Orphan record types: <?= count($audit['orphaned'] ?? []) ?></li>
        <li>Trust score distortion risk: <?= ($trustQ['distorted'] ?? false) ? 'Yes' : 'No' ?></li>
    </ul>
</section>

<section class="card erp-card">
    <h3 class="card__title">HTML reports (docs/)</h3>
    <ul class="detail-list detail-list--compact">
        <?php
        $reports = [
            'DATA-INTEGRITY-AUDIT-REPORT.html',
            'TEST-DATA-INVENTORY-REPORT.html',
            'PHONE-DUPLICATE-REPORT.html',
            'PSA-INTEGRITY-REPORT.html',
            'IMPORT-STABILIZATION-REPORT.html',
            'VAULT-HEALTH-REPORT.html',
            'TRUST-SCORE-DATA-QUALITY-REPORT.html',
            'PRODUCTION-CLEANUP-PLAN.html',
        ];
        foreach ($reports as $file):
            $path = dirname(__DIR__) . '/docs/' . $file;
            $exists = is_file($path);
        ?>
        <li>
            <?php if ($exists): ?>
                <a href="../docs/<?= h($file) ?>" target="_blank" rel="noopener"><?= h($file) ?></a>
                <span class="text-muted"> — <?= h(gmdate('Y-m-d H:i', filemtime($path))) ?> UTC</span>
            <?php else: ?>
                <?= h($file) ?> <span class="text-muted">(run Regenerate reports)</span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>

</div>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
