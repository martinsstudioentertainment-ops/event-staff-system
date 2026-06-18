<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ops-checklist.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';

requireAdminCapability('dashboard');

$pdo      = getDB();
$flash    = getAdminFlash();
$metrics  = getOpsLiveMetrics($pdo);
$items    = getOpsChecklistView($pdo);
$counts   = countOpsManualDone($pdo);
$applySync = $metrics['apply_url'] !== ''
    ? rtrim($metrics['apply_url'], '/') . '/admin/admin/sync-sheets.php?action=run'
    : '';

$pageTitle  = 'Ops checklist';
$activePage = 'ops';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Operations checklist</h2>
            <p class="card__subtitle">
                Weekly routine after events and approvals.
                <?= (int) $counts['done'] ?>/<?= (int) $counts['total'] ?> tasks ticked.
            </p>
        </div>
        <a href="go-live.php" class="btn btn--secondary">Go live setup</a>
    </div>

    <div class="stat-grid">
        <div class="stat-card stat-card--warn">
            <p class="stat-card__label">Pending approvals</p>
            <p class="stat-card__value"><?= (int) $metrics['pending'] ?></p>
            <?php if ((int) $metrics['pending'] > 0): ?>
                <a href="staff.php?status=pending&amp;page=1" class="btn btn--small btn--primary" style="margin-top:0.5rem;">Review now</a>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <p class="stat-card__label">Approved registrations</p>
            <p class="stat-card__value"><?= (int) $metrics['approved'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-card__label">Today&apos;s check-ins</p>
            <p class="stat-card__value"><?= (int) $metrics['today_checkins'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-card__label">Active events</p>
            <p class="stat-card__value"><?= (int) $metrics['active_events'] ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-card__label">Google Sheets sync</p>
            <p class="stat-card__value" style="font-size:1rem;"><?= $metrics['sheets_enabled'] ? 'On' : 'Off' ?></p>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Quick actions</h2>
    </div>
    <div class="toolbar">
        <a href="staff.php?status=pending&amp;page=1" class="btn btn--primary">Pending staff</a>
        <a href="staff-directory.php?profile=incomplete" class="btn btn--secondary">Incomplete profiles</a>
        <a href="apply-portal.php" class="btn btn--secondary">Apply admin</a>
        <?php if ($applySync !== ''): ?>
            <a href="<?= h($applySync) ?>" class="btn btn--success" target="_blank" rel="noopener">Run apply sync</a>
        <?php endif; ?>
        <a href="google-sheets-diagnostic.php" class="btn btn--secondary">Sheets diagnostic</a>
        <a href="go-live.php" class="btn btn--ghost">Go live checklist</a>
    </div>
    <div class="alert alert--info alert--visible" style="margin-top:1rem;">
        <strong>Recommended server cron (not auto-login)</strong>
        <ul style="margin:0.5rem 0 0 1.25rem;font-size:0.9rem;">
            <?php if ($metrics['cron_apply_hint'] !== ''): ?>
                <li><strong>Every 5–15 min</strong> — apply payroll/sheets sync:
                    <code style="font-size:0.75rem;">*/10 * * * * curl -fsS "<?= h($metrics['cron_apply_hint']) ?>"</code>
                </li>
            <?php endif; ?>
            <li><strong>Once daily ~04:00</strong> — clear log files (safe):
                <code style="font-size:0.75rem;">0 4 * * * curl -fsS "https://admin.olasentra.com/cron/daily-cleanup.php?key=YOUR_CRON_KEY"</code>
            </li>
            <li><strong>Weekly</strong> — database backup: <code style="font-size:0.75rem;">0 3 * * 0 php …/cron/weekly-backup.php</code></li>
        </ul>
        <p style="margin:0.75rem 0 0;font-size:0.85rem;">
            Do <em>not</em> schedule automatic admin login every 5 minutes — that is a security risk and does not speed up the site.
        </p>
    </div>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Weekly routine</h2>
        <p class="card__subtitle">Tick each item when done, then save. Reset each week as needed.</p>
    </div>

    <form method="post" action="ops-checklist-action.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <div class="readiness-list">
            <?php foreach ($items as $item): ?>
                <article class="readiness-item readiness-item--<?= h($item['status']) ?> readiness-item--manual">
                    <label class="form-checkbox go-live-manual__label readiness-item__manual-label">
                        <input type="checkbox" name="manual[<?= h($item['key']) ?>]" value="1"<?= $item['done'] ? ' checked' : '' ?>>
                        <span>
                            <span class="readiness-item__head">
                                <span class="readiness-item__badge"><?= $item['done'] ? 'DONE' : 'TODO' ?></span>
                                <span class="readiness-item__title"><?= h($item['label']) ?></span>
                            </span>
                            <span class="readiness-item__detail"><?= h($item['hint']) ?></span>
                        </span>
                    </label>
                    <?php if (!empty($item['fix_url'])): ?>
                        <a href="<?= h($item['fix_url']) ?>" class="readiness-item__link">Open →</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save checklist</button>
            <button type="submit" name="reset" value="1" class="btn btn--ghost" onclick="return confirm('Clear all ticks for a fresh week?');">Reset all</button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
