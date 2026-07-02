<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cleanup-audit.php';
require_once __DIR__ . '/../includes/system-cleanup.php';

requireAdminCapability('settings');

$pdo    = getDB();
$flash  = getAdminFlash();
$report = readLatestCleanupAuditReport();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    if (($_POST['action'] ?? '') === 'run_audit') {
        try {
            $fresh = runCleanupAudit();
            $path  = writeCleanupAuditReport($fresh);
            setAdminFlash('success', 'Cleanup audit complete. Report saved — no files were deleted.');
            $report = $fresh;
        } catch (Throwable $e) {
            setAdminFlash('error', 'Audit failed: ' . $e->getMessage());
        }
        header('Location: cleanup-report.php');
        exit;
    }
}

$pageTitle  = 'Cleanup report';
$activePage = 'go-live';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Cleanup audit report</h2>
            <p class="card__subtitle">
                Read-only scan — generated before each deploy. Review findings before any manual delete.
            </p>
        </div>
        <div class="toolbar toolbar--compact">
            <form method="post" action="cleanup-report.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="run_audit">
                <button type="submit" class="btn btn--primary">Run audit now</button>
            </form>
            <a href="system-cleanup.php" class="btn btn--secondary">Manual cleanup</a>
            <a href="go-live.php" class="btn btn--secondary">Go live</a>
        </div>
    </div>

    <div class="alert alert--warning alert--visible">
        <strong>No automatic deletions.</strong> This report lists candidates only.
        Use <a href="system-cleanup.php">System cleanup</a> for log truncation (manual, reversible from backup).
        Protected: <code>config.php</code>, credentials, <code>vendor/</code>, database backups you choose to keep.
    </div>

    <?php if ($report === null): ?>
        <p class="data-table__empty">No report yet. Run audit or deploy via <code>deploy.ps1</code> (runs audit automatically).</p>
    <?php else: ?>
        <?php $summary = $report['summary'] ?? []; ?>
        <div class="stat-grid" style="margin-bottom:1rem">
            <div class="stat-card">
                <p class="stat-card__value"><?= (int) ($summary['total_findings'] ?? 0) ?></p>
                <p class="stat-card__label">Total findings</p>
            </div>
            <div class="stat-card">
                <p class="stat-card__value"><?= (int) ($summary['issues_high'] ?? 0) ?></p>
                <p class="stat-card__label">High</p>
            </div>
            <div class="stat-card">
                <p class="stat-card__value"><?= (int) ($summary['issues_medium'] ?? 0) ?></p>
                <p class="stat-card__label">Medium</p>
            </div>
            <div class="stat-card">
                <p class="stat-card__value"><?= (int) ($summary['issues_low'] ?? 0) ?></p>
                <p class="stat-card__label">Low</p>
            </div>
        </div>
        <p class="form-hint">Generated: <?= h((string) ($report['generated_at'] ?? '')) ?> · Mode: <?= h((string) ($report['mode'] ?? 'read_only')) ?></p>

        <?php if (($report['findings'] ?? []) === []): ?>
            <div class="alert alert--success alert--visible">No cleanup issues detected.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table data-table--compact">
                    <thead>
                        <tr>
                            <th>Severity</th>
                            <th>Category</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['findings'] as $row): ?>
                            <tr>
                                <td><span class="badge badge--<?= ($row['severity'] ?? '') === 'high' ? 'pending' : 'approved' ?>"><?= h((string) ($row['severity'] ?? '')) ?></span></td>
                                <td><?= h((string) ($row['category'] ?? '')) ?></td>
                                <td>
                                    <?= h((string) ($row['message'] ?? '')) ?>
                                    <?php if (!empty($row['path'])): ?>
                                        <br><code><?= h((string) $row['path']) ?></code>
                                        <?php if (!empty($row['bytes'])): ?>
                                            · <?= h(formatBytesHuman((int) $row['bytes'])) ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($row['paths']) && is_array($row['paths'])): ?>
                                        <ul style="margin:0.25rem 0 0 1rem">
                                            <?php foreach ($row['paths'] as $p): ?>
                                                <li><code><?= h((string) $p) ?></code></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h3 class="form-section-title">Storage overview</h3>
        <table class="data-table data-table--compact">
            <thead><tr><th>Path</th><th>Size</th></tr></thead>
            <tbody>
                <?php foreach (($report['storage'] ?? []) as $row): ?>
                    <tr>
                        <td><code><?= h((string) ($row['path'] ?? '')) ?></code></td>
                        <td><?= h(formatBytesHuman((int) ($row['bytes'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
