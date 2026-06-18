<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/data-integrity.php';

requireAdminCapability('settings');

if (!isAdminSuperUser()) {
    setAdminFlash('error', 'Duplicate merge review requires administrator access.');
    header('Location: dashboard.php');
    exit;
}

$pdo      = getDB();
$applyPdo = getApplyVaultPdo();
ensureDataIntegritySchema($pdo);

$flash     = getAdminFlash();
$dismissed = dataIntegrityDismissedKeys($pdo);
$recs      = array_values(array_filter(
    buildMergeRecommendations($pdo, $applyPdo),
    static fn (array $r): bool => !in_array((string) ($r['key'] ?? ''), $dismissed, true)
));

$preview = null;
if (isset($_GET['preview_keep'], $_GET['preview_merge'])) {
    $preview = previewStaffMergePlan($pdo, (int) $_GET['preview_keep'], (int) $_GET['preview_merge']);
}

$pageTitle  = 'Duplicate merge review';
$activePage = 'data-integrity';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Safe merge review</h2>
            <p class="card__subtitle">Compare duplicates before any action. Merges require explicit superuser confirmation — no automatic merging.</p>
        </div>
        <a href="data-integrity.php" class="btn btn--ghost">← Data integrity</a>
    </div>
</section>

<?php if ($preview !== null): ?>
<section class="card erp-card">
    <h3 class="card__title">Merge preview</h3>
    <p><?= h((string) ($preview['message'] ?? '')) ?></p>
    <?php if (!empty($preview['plan'])): ?>
        <ol>
            <?php foreach ($preview['plan'] as $step): ?>
                <li><?= h($step) ?></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <p class="text-muted">To execute a merge, contact platform admin with approval — execution is not automated in this sprint.</p>
</section>
<?php endif; ?>

<?php if ($recs === []): ?>
<section class="card erp-card">
    <p>No open duplicate groups (or all dismissed). Run audit from <a href="data-integrity.php">Data integrity</a>.</p>
</section>
<?php else: ?>
    <?php foreach ($recs as $rec): ?>
        <?php
        $a = $rec['record_a'] ?? [];
        $b = $rec['record_b'] ?? [];
        $ctx = $rec['context'] ?? [];
        $isMain = str_starts_with((string) ($rec['type'] ?? ''), 'main_');
        ?>
        <section class="card erp-card">
            <h3 class="card__title"><?= h((string) ($rec['label'] ?? 'Duplicate')) ?></h3>
            <p class="card__subtitle">Recommended keeper: ID <?= (int) ($rec['recommended'] ?? 0) ?></p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1rem 0;">
                <div>
                    <h4>Record A</h4>
                    <?php if ($isMain): ?>
                        <dl class="detail-list detail-list--compact">
                            <div class="detail-list__row"><dt>Staff ID</dt><dd><?= (int) ($a['id'] ?? 0) ?></dd></div>
                            <div class="detail-list__row"><dt>Name</dt><dd><?= h(dataIntegrityStaffLabel($a)) ?></dd></div>
                            <div class="detail-list__row"><dt>Email</dt><dd><?= h((string) ($a['email'] ?? '')) ?></dd></div>
                            <div class="detail-list__row"><dt>Phone</dt><dd><?= h((string) ($a['mobile'] ?? '')) ?></dd></div>
                            <div class="detail-list__row"><dt>PSA</dt><dd><?= h((string) ($a['psa_licence'] ?? '')) ?></dd></div>
                        </dl>
                    <?php else: ?>
                        <dl class="detail-list detail-list--compact">
                            <div class="detail-list__row"><dt>Vault ID</dt><dd><?= (int) ($a['id'] ?? 0) ?></dd></div>
                            <div class="detail-list__row"><dt>Name</dt><dd><?= h(dataIntegrityVaultLabel($a)) ?></dd></div>
                            <div class="detail-list__row"><dt>Email</dt><dd><?= h((string) ($a['email'] ?? '')) ?></dd></div>
                            <div class="detail-list__row"><dt>Phone</dt><dd><?= h((string) ($a['phone'] ?? '')) ?></dd></div>
                            <div class="detail-list__row"><dt>PSA</dt><dd><?= h((string) ($a['psa_licence'] ?? '')) ?></dd></div>
                        </dl>
                    <?php endif; ?>
                </div>
                <div>
                    <h4>Record B</h4>
                    <?php if ($isMain): ?>
                        <dl class="detail-list detail-list--compact">
                            <div class="detail-list__row"><dt>Staff ID</dt><dd><?= (int) ($b['id'] ?? 0) ?></dd></div>
                            <div class="detail-list__row"><dt>Name</dt><dd><?= h(dataIntegrityStaffLabel($b)) ?></dd></div>
                            <div class="detail-list__row"><dt>Email</dt><dd><?= h((string) ($b['email'] ?? '')) ?></dd></div>
                            <div class="detail-list__row"><dt>Phone</dt><dd><?= h((string) ($b['mobile'] ?? '')) ?></dd></div>
                            <div class="detail-list__row"><dt>PSA</dt><dd><?= h((string) ($b['psa_licence'] ?? '')) ?></dd></div>
                        </dl>
                    <?php else: ?>
                        <dl class="detail-list detail-list--compact">
                            <div class="detail-list__row"><dt>Vault ID</dt><dd><?= (int) ($b['id'] ?? 0) ?></dd></div>
                            <div class="detail-list__row"><dt>Name</dt><dd><?= h(dataIntegrityVaultLabel($b)) ?></dd></div>
                            <div class="detail-list__row"><dt>Email</dt><dd><?= h((string) ($b['email'] ?? '')) ?></dd></div>
                            <div class="detail-list__row"><dt>Phone</dt><dd><?= h((string) ($b['phone'] ?? '')) ?></dd></div>
                            <div class="detail-list__row"><dt>PSA</dt><dd><?= h((string) ($b['psa_licence'] ?? '')) ?></dd></div>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isMain): ?>
                <?php
                $ctxA = getStaffMergeContextForId($pdo, (int) ($a['id'] ?? 0));
                $ctxB = getStaffMergeContextForId($pdo, (int) ($b['id'] ?? 0));
                $vaultA = findApplyVaultRecordByEmail($applyPdo, (string) ($a['email'] ?? ''));
                $vaultB = findApplyVaultRecordByEmail($applyPdo, (string) ($b['email'] ?? ''));
                $applyRoot = getApplySiteUrl($pdo);
                ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:0.75rem 0;">
                    <div>
                        <p><strong>Staff #<?= (int) ($a['id'] ?? 0) ?> activity</strong></p>
                        <ul class="detail-list">
                            <li><?= (int) ($ctxA['registrations'] ?? 0) ?> registrations</li>
                            <li><?= (int) ($ctxA['attendance'] ?? 0) ?> attendance</li>
                            <li><?= (int) ($ctxA['notifications'] ?? 0) ?> notifications</li>
                            <li><?= (int) ($ctxA['messages'] ?? 0) ?> messages</li>
                        </ul>
                        <?php if ($vaultA && $applyRoot !== ''): ?>
                            <p><a href="<?= h($applyRoot . '/admin/admin/view-staff.php?id=' . (int) ($vaultA['id'] ?? 0)) ?>" target="_blank" rel="noopener">Apply vault #<?= (int) ($vaultA['id'] ?? 0) ?></a></p>
                        <?php elseif ($vaultA === null && $applyPdo instanceof PDO): ?>
                            <p class="text-muted">No Apply vault row for this email</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p><strong>Staff #<?= (int) ($b['id'] ?? 0) ?> activity</strong></p>
                        <ul class="detail-list">
                            <li><?= (int) ($ctxB['registrations'] ?? 0) ?> registrations</li>
                            <li><?= (int) ($ctxB['attendance'] ?? 0) ?> attendance</li>
                            <li><?= (int) ($ctxB['notifications'] ?? 0) ?> notifications</li>
                            <li><?= (int) ($ctxB['messages'] ?? 0) ?> messages</li>
                        </ul>
                        <?php if ($vaultB && $applyRoot !== ''): ?>
                            <p><a href="<?= h($applyRoot . '/admin/admin/view-staff.php?id=' . (int) ($vaultB['id'] ?? 0)) ?>" target="_blank" rel="noopener">Apply vault #<?= (int) ($vaultB['id'] ?? 0) ?></a></p>
                        <?php elseif ($vaultB === null && $applyPdo instanceof PDO): ?>
                            <p class="text-muted">No Apply vault row for this email</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($ctx !== []): ?>
                <p><strong>Related data:</strong>
                    <?= (int) ($ctx['registrations'] ?? 0) ?> registrations,
                    <?= (int) ($ctx['attendance'] ?? 0) ?> attendance,
                    <?= (int) ($ctx['messages'] ?? 0) ?> messages,
                    <?= (int) ($ctx['notifications'] ?? 0) ?> notifications
                </p>
            <?php endif; ?>

            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                <?php if ($isMain): ?>
                    <a class="btn btn--secondary" href="view-staff.php?id=<?= (int) ($a['id'] ?? 0) ?>">Staff #<?= (int) ($a['id'] ?? 0) ?> profile</a>
                    <a class="btn btn--secondary" href="view-staff.php?id=<?= (int) ($b['id'] ?? 0) ?>">Staff #<?= (int) ($b['id'] ?? 0) ?> profile</a>
                    <a class="btn btn--ghost" href="duplicate-merge.php?preview_keep=<?= (int) ($rec['recommended'] ?? $a['id'] ?? 0) ?>&preview_merge=<?= (int) ($b['id'] ?? 0) ?>">Preview merge</a>
                <?php endif; ?>
                <form method="post" action="duplicate-merge-action.php" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="dup_key" value="<?= h((string) ($rec['key'] ?? '')) ?>">
                    <input type="hidden" name="dup_type" value="<?= h((string) ($rec['type'] ?? '')) ?>">
                    <input type="hidden" name="action" value="ignore">
                    <button type="submit" class="btn btn--ghost">Ignore</button>
                </form>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
