<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/feature-flags.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdmin();

if (!isAdminSuperUser()) {
    setAdminFlash('error', 'Only administrators can manage feature flags.');
    header('Location: dashboard.php');
    exit;
}

$pdo  = getDB();
$defs = getFeatureFlagDefinitions();
$vals = getAllFeatureFlagValues($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    $result = saveFeatureFlagsFromInput($pdo, $_POST);
    if ($result['ok']) {
        logAdminAudit($pdo, 'feature_flags_update', 'settings', null, 'Feature flags updated');
        setAdminFlash('success', $result['message']);
    } else {
        setAdminFlash('error', $result['message']);
    }
    header('Location: feature-flags.php');
    exit;
}

$vals  = getAllFeatureFlagValues($pdo);
$audit = getFeatureFlagAuditMetadata();

$pageTitle  = 'Feature flags';
$activePage = 'settings';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Production feature flags</h2>
            <p class="card__subtitle">
                Zero-downtime rollout. OFF = legacy behaviour. No routes or permissions change.
                Rollback: turn flag OFF and refresh, or restore pre-deploy backup.
            </p>
        </div>
        <a href="go-live.php" class="btn btn--secondary">Go live</a>
    </div>

    <div class="alert alert--info alert--visible" style="margin-bottom:1rem">
        <strong>Live production rules</strong>
        <ul style="margin:0.5rem 0 0 1.25rem">
            <li>No automatic file or database deletions</li>
            <li>All visual/API changes ship behind flags until validated</li>
            <li>Auto-approval: use shadow mode (1) before live (2)</li>
        </ul>
    </div>

    <form method="post" action="feature-flags.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <table class="data-table">
            <thead>
                <tr>
                    <th>Flag</th>
                    <th>Phase</th>
                    <th>Tier</th>
                    <th>Status</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($defs as $key => $meta): ?>
                    <?php
                    $current = $vals[$key] ?? $meta['default'];
                    $isAuto  = $key === 'feature_auto_approval';
                    $tierMeta = $audit[$key] ?? ['tier' => 'unknown', 'wired' => false, 'safe_remove' => false];
                    $tier = (string) ($tierMeta['tier'] ?? 'unknown');
                    ?>
                    <tr>
                        <td><code><?= h($key) ?></code></td>
                        <td><?= h($meta['phase']) ?></td>
                        <td>
                            <span class="badge badge--<?= $tier === 'active' ? 'success' : ($tier === 'stub' ? 'pending' : 'warning') ?>">
                                <?= h(ucfirst($tier)) ?>
                            </span>
                            <?php if (!empty($tierMeta['safe_remove'])): ?>
                                <span class="form-hint" title="No runtime wiring — safe to delete flag definition in a future cleanup">removable</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isAuto): ?>
                                <select class="form-select" name="<?= h($key) ?>" style="max-width:10rem">
                                    <option value="0"<?= $current === '0' ? ' selected' : '' ?>>Off</option>
                                    <option value="1"<?= $current === '1' ? ' selected' : '' ?>>Shadow (log only)</option>
                                    <option value="2"<?= $current === '2' ? ' selected' : '' ?>>Live</option>
                                </select>
                            <?php else: ?>
                                <label class="form-checkbox" style="margin:0">
                                    <input type="hidden" name="<?= h($key) ?>" value="0">
                                    <input type="checkbox" name="<?= h($key) ?>" value="1"<?= isFeatureEnabled($pdo, $key) ? ' checked' : '' ?>>
                                    <?= isFeatureEnabled($pdo, $key) ? 'ON' : 'OFF' ?>
                                </label>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= h($meta['label']) ?></strong><br>
                            <span class="form-hint"><?= h($meta['desc']) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="alert alert--info alert--visible" style="margin-top:1rem">
            <strong>Flag audit summary</strong>
            <ul style="margin:0.5rem 0 0 1.25rem">
                <li><strong>Active (wired):</strong> public premium, registration wizard, GPS attendance</li>
                <li><strong>Stub (9 flags):</strong> roadmap only — no code paths; safe to leave OFF; definitions removable in cleanup sprint</li>
                <li><strong>Unused:</strong> auto-approval — keep until engine ships</li>
            </ul>
        </div>

        <div class="toolbar" style="margin-top:1rem">
            <button type="submit" class="btn btn--primary">Save flags</button>
            <a href="system-health.php" class="btn btn--secondary">System health</a>
            <a href="cleanup-report.php" class="btn btn--secondary">Cleanup report</a>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
