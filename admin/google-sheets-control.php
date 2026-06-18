<?php

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/admin-capabilities.php';

require_once __DIR__ . '/../includes/platform/google-sheets-control.php';

require_once __DIR__ . '/../includes/google-sheets-schedule.php';

require_once __DIR__ . '/../includes/google-sheets-queue.php';

require_once __DIR__ . '/../includes/system-settings.php';

require_once __DIR__ . '/../includes/audit-log.php';



requireAdminCapability('events');



$pdo      = getDB();

$flash    = getAdminFlash();

$health   = summarizeGoogleSheetsControl($pdo);

$sheets   = listConnectedEventSheets($pdo);

$failures = getRecentSheetsSyncFailures($pdo, 15);

$schedule = getGoogleSheetsSyncScheduleSettings($pdo);

$cronKey  = trim(getSetting($pdo, 'reminder_cron_key', ''));

$adminUrl = rtrim(getSetting($pdo, 'admin_site_url', ''), '/');

if ($adminUrl === '') {

    $adminUrl = 'https://admin.olasentra.com';

}



$pageTitle  = 'Sheets Control';

$activePage = 'google-sheets-control';



include __DIR__ . '/../includes/admin/layout-top.php';

?>

<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css?v=3">



<?php if ($flash): ?>

<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>

<?php endif; ?>



<div class="sheets-control-page">



<section class="card erp-card">

    <div class="card__header card__header--row">

        <div>

            <h2 class="card__title">Google Sheets control center</h2>

            <p class="card__subtitle">Sync status, schedule, failures, and manual re-sync for linked event sheets.</p>

        </div>

        <div class="toolbar" style="gap:0.5rem;flex-wrap:wrap">
            <form method="post" action="google-sheets-resync.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <button type="submit" class="btn btn--primary" onclick="return confirm('Queue a full re-sync for all linked event sheets? (Processes ~1 event per minute via cron)');">Re-sync all sheets</button>
            </form>
            <?php if (!empty($health['queue_worker_on']) && (int) ($health['queue_pending'] ?? 0) > 0): ?>
                <form method="post" action="google-sheets-resync.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="process_queue">
                    <input type="hidden" name="max_jobs" value="5">
                    <button type="submit" class="btn btn--secondary">Process queue now (5)</button>
                </form>
            <?php endif; ?>
        </div>

    </div>



    <p class="sheets-control-status<?= !empty($health['live_sync_allowed']) ? ' sheets-control-status--ok' : ' sheets-control-status--paused' ?>">

        <?= h((string) ($health['schedule_status'] ?? '')) ?>

        <?php if (!empty($health['last_live_sync_at'])): ?>

            · Last auto sync <?= h(formatSystemDateTime((string) $health['last_live_sync_at'], $pdo)) ?>

        <?php endif; ?>

    </p>



    <div class="platform-ops-grid">

        <div class="platform-ops-metric platform-ops-metric--ok"><div class="platform-ops-metric__value"><?= (int) $health['connected_events'] ?></div><div class="platform-ops-metric__label">Connected sheets</div></div>

        <div class="platform-ops-metric<?= !empty($health['sync_enabled']) ? ' platform-ops-metric--ok' : '' ?>"><div class="platform-ops-metric__value"><?= !empty($health['sync_enabled']) ? 'ON' : 'OFF' ?></div><div class="platform-ops-metric__label">Live sync</div></div>

        <div class="platform-ops-metric"><div class="platform-ops-metric__value"><?= !empty($health['api_configured']) ? 'OK' : 'Missing' ?></div><div class="platform-ops-metric__label">API credentials</div></div>

        <div class="platform-ops-metric platform-ops-metric--ok"><div class="platform-ops-metric__value"><?= (int) $health['success_24h'] ?></div><div class="platform-ops-metric__label">Success (24h)</div></div>

        <div class="platform-ops-metric<?= (int) $health['failed_24h'] > 0 ? ' platform-ops-metric--danger' : '' ?>"><div class="platform-ops-metric__value"><?= (int) $health['failed_24h'] ?></div><div class="platform-ops-metric__label">Failed (24h)</div></div>

        <div class="platform-ops-metric<?= (int) ($health['queue_pending'] ?? 0) > 0 ? ' platform-ops-metric--warn' : '' ?>"><div class="platform-ops-metric__value"><?= (int) ($health['queue_pending'] ?? 0) ?></div><div class="platform-ops-metric__label">Queue pending</div></div>

        <div class="platform-ops-metric"><div class="platform-ops-metric__value"><?= !empty($health['queue_worker_on']) ? 'ON' : 'OFF' ?></div><div class="platform-ops-metric__label">Queue worker</div></div>

    </div>



    <?php if (!empty($health['queue_stuck'])): ?>
    <p class="alert alert--warning alert--visible sheets-control-alert">
        <strong><?= (int) ($health['queue_pending'] ?? 0) ?> sheet(s) still in the queue</strong>
        <?php if (!empty($health['queue_oldest_pending'])): ?>
            (oldest since <?= h(formatSystemDateTime((string) $health['queue_oldest_pending'], $pdo)) ?>).
        <?php endif; ?>
        The system now drains this automatically when you use the admin console, when staff register, or when approvals run —
        refresh in a few minutes. Optional: add the cPanel cron below for 24/7 draining without opening admin.
    </p>
    <?php elseif (!empty($health['queue_worker_on'])): ?>
    <p class="sheets-control-status sheets-control-status--ok">
        <strong>24/7 automatic</strong> — the server heartbeat drains the queue (~1 event/min when busy, keepalive every 90s)
        with no admin login required.
        <?php if ((int) ($health['queue_pending'] ?? 0) > 0): ?>
            <?= (int) $health['queue_pending'] ?> event(s) waiting<?= (int) ($health['queue_processing'] ?? 0) > 0 ? ', ' . (int) $health['queue_processing'] . ' in progress' : '' ?>.
        <?php endif; ?>
        <?php if (!empty($health['queue_last_run_at'])): ?>
            Last worker run <?= h(formatSystemDateTime((string) $health['queue_last_run_at'], $pdo)) ?>.
        <?php endif; ?>
    </p>
    <?php endif; ?>



    <?php if (!empty($health['last_error'])): ?>

    <p class="alert alert--error alert--visible sheets-control-alert">Last API error: <?= h((string) $health['last_error']) ?></p>

    <?php endif; ?>

</section>



<section class="card erp-card">

    <h3 class="card__title">Sync schedule &amp; timing</h3>

    <p class="card__subtitle">Control when automatic sheet updates run. Manual re-sync and bulk sync are queued — they no longer burst the Google API.</p>



    <form method="post" action="google-sheets-settings-action.php" class="sheets-control-settings">

        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">



        <label class="form-check sheets-control-settings__row">

            <input type="checkbox" name="google_sheets_sync_enabled" value="1"<?= ($schedule['sync_enabled'] ?? '0') === '1' ? ' checked' : '' ?>>

            <span><strong>Live sync ON</strong> — push to sheets on approve, registration, and profile updates</span>

        </label>



        <label class="form-check sheets-control-settings__row">

            <input type="checkbox" name="google_sheets_sync_quiet_enabled" value="1"<?= ($schedule['quiet_hours_enabled'] ?? '0') === '1' ? ' checked' : '' ?>>

            <span><strong>Quiet hours</strong> — pause automatic sync during this window (<?= h((string) ($schedule['timezone'] ?? 'Europe/Dublin')) ?>)</span>

        </label>



        <div class="sheets-control-settings__grid">

            <label class="form-field">

                <span class="form-label">Quiet hours start</span>

                <input type="time" name="google_sheets_sync_quiet_start" class="form-input" value="<?= h((string) ($schedule['quiet_start'] ?? '22:00')) ?>">

            </label>

            <label class="form-field">

                <span class="form-label">Quiet hours end</span>

                <input type="time" name="google_sheets_sync_quiet_end" class="form-input" value="<?= h((string) ($schedule['quiet_end'] ?? '07:00')) ?>">

            </label>

            <label class="form-field">

                <span class="form-label">Min minutes between auto syncs</span>

                <input type="number" name="google_sheets_sync_min_interval_minutes" class="form-input" min="0" max="1440" value="<?= (int) ($schedule['min_interval_minutes'] ?? 0) ?>">

                <span class="form-hint">0 = no limit. Use 5–15 to reduce API bursts.</span>

            </label>

            <label class="form-field">

                <span class="form-label">Apply payroll sync interval (minutes)</span>

                <input type="number" name="google_sheets_apply_sync_interval_minutes" class="form-input" min="1" max="120" value="<?= (int) ($schedule['apply_interval_minutes'] ?? 2) ?>">

                <span class="form-hint">Background sync on Apply admin (secure portal).</span>

            </label>

            <label class="form-check sheets-control-settings__row">

                <input type="checkbox" name="google_sheets_queue_enabled" value="1"<?= ($schedule['queue_enabled'] ?? '1') === '1' ? ' checked' : '' ?>>

                <span><strong>Queue worker ON</strong> — route all sheet syncs through the cron queue (recommended)</span>

            </label>

            <label class="form-field">

                <span class="form-label">Events per cron run</span>

                <input type="number" name="google_sheets_cron_jobs_per_run" class="form-input" min="1" max="3" value="<?= (int) ($schedule['cron_jobs_per_run'] ?? 1) ?>">

                <span class="form-hint">1 = safest for Google rate limits. Max 3.</span>

            </label>

        </div>



        <div class="toolbar">

            <button type="submit" class="btn btn--primary">Save sync schedule</button>

            <a href="settings-production.php#google-sheets" class="btn btn--secondary">Drive folder &amp; API setup</a>

            <a href="google-sheets-diagnostic.php" class="btn btn--secondary">Diagnostics</a>

        </div>

    </form>

</section>



<section class="card erp-card">

    <h3 class="card__title">24/7 background heartbeat</h3>

    <p class="card__subtitle">Sheets sync and GPS attendance activation run automatically on a self-chaining server loop (~every 90 seconds). No admin login or cPanel cron required. The optional cPanel line below is a backup only.</p>



    <p><strong>Active loop URL</strong> (server calls this automatically — for monitoring only):</p>

    <?php if ($cronKey !== ''): ?>

    <pre class="sheets-control-cron"><code><?= h($adminUrl) ?>/cron/system-heartbeat.php?key=<?= h($cronKey) ?></code></pre>

    <?php else: ?>

    <p class="alert alert--error alert--visible">Set <strong>Cron secret key</strong> in <a href="settings-email.php">Email settings</a> (saved automatically on next heartbeat).</p>

    <?php endif; ?>

    <p><strong>Optional cPanel backup</strong> (only if the self-chain ever stops):</p>

    <pre class="sheets-control-cron"><code>* * * * * /usr/local/bin/php /home/USER/public_html/cron/system-heartbeat.php</code></pre>

    <p><strong>Legacy sheets-only URL</strong>:</p>

    <?php if ($cronKey !== ''): ?>

    <pre class="sheets-control-cron"><code><?= h($adminUrl) ?>/cron/google-sheets-sync.php?key=<?= h($cronKey) ?></code></pre>

    <?php else: ?>

    <p class="alert alert--error alert--visible">Set <strong>Cron secret key</strong> in <a href="settings-email.php">Email settings</a> before using web cron.</p>

    <?php endif; ?>



    <p class="form-hint">Schedule: <code>* * * * *</code> (every minute). Each run rebuilds <?= (int) ($schedule['cron_jobs_per_run'] ?? 1) ?> linked event sheet(s).</p>

</section>



<section class="card erp-card">

    <h3 class="card__title">Linked events</h3>

    <div class="table-wrap sheets-control-table-wrap">

        <table class="data-table sheets-control-table">

            <thead><tr><th>Event</th><th>Date</th><th>Last sync</th><th>Failed (7d)</th><th>Actions</th></tr></thead>

            <tbody>

            <?php foreach ($sheets as $row): ?>

                <tr>

                    <td><strong><?= h((string) ($row['name'] ?? '')) ?></strong></td>

                    <td><?= h(formatSystemDate((string) ($row['event_date'] ?? ''), $pdo)) ?></td>

                    <td><?= !empty($row['last_sync']) ? h(formatSystemDateTime((string) $row['last_sync'], $pdo)) : '—' ?></td>

                    <td><?= (int) ($row['failed_7d'] ?? 0) ?></td>

                    <td class="table-actions">

                        <a href="event-hub.php?event_id=<?= (int) ($row['id'] ?? 0) ?>" class="btn btn--small btn--secondary">Hub</a>

                        <form method="post" action="google-sheets-resync.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="event_id" value="<?= (int) ($row['id'] ?? 0) ?>">
                            <button type="submit" class="btn btn--small btn--secondary" onclick="return confirm('Queue re-sync for this event sheet? (~1 minute via cron)');">Re-sync</button>
                        </form>

                        <?php if (!empty($row['google_sheet_url'])): ?>

                        <a href="<?= h((string) $row['google_sheet_url']) ?>" target="_blank" rel="noopener" class="btn btn--small btn--ghost sheets-control-sheet-link">Open sheet</a>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>

            <?php if ($sheets === []): ?>

                <tr><td colspan="5" class="sheets-control-empty">No linked sheets. Add URLs on the Events page.</td></tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>



<section class="card erp-card">

    <h3 class="card__title">Recent failures</h3>

    <div class="table-wrap sheets-control-table-wrap">

        <table class="data-table sheets-control-table">

            <thead><tr><th>When</th><th>Event</th><th>Detail</th></tr></thead>

            <tbody>

            <?php foreach ($failures as $f): ?>

                <tr>

                    <td><?= h(formatSystemDateTime((string) ($f['created_at'] ?? ''), $pdo)) ?></td>

                    <td><?= h((string) ($f['event_name'] ?? '')) ?></td>

                    <td><?= h((string) ($f['detail'] ?? '')) ?></td>

                </tr>

            <?php endforeach; ?>

            <?php if ($failures === []): ?>

                <tr><td colspan="3" class="sheets-control-empty">No recent failures logged.</td></tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>



</div>



<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>


