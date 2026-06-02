<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';

requireAdminCapability('events');

$pdo         = getDB();
$events      = getAllEvents($pdo);
$flash       = getAdminFlash();
$sheetStatus = countEventsGoogleSheetStatus($pdo);
$canAutoSheet = isGoogleServiceAccountConfigured();

$pageTitle  = 'Event Management';
$activePage = 'events';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Events</h2>
            <p class="card__subtitle"><?= count($events) ?> event(s) — active events appear on the registration form.</p>
        </div>
        <div class="toolbar">
            <a href="event-form.php" class="btn btn--primary">+ Add Event</a>
            <a href="import-roster.php" class="btn btn--secondary">Import master roster</a>
            <a href="roster-diagnostic.php" class="btn btn--secondary">Roster diagnostic</a>
            <form method="post" action="events-roster-action.php" class="inline-form" onsubmit="return confirm('Import all 32 summer events from the master list?');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <button type="submit" class="btn btn--secondary">Import (quick)</button>
            </form>
            <?php if ($canAutoSheet && $sheetStatus['missing'] > 0): ?>
                <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Create <?= (int) $sheetStatus['missing'] ?> Google Sheet(s) via the service account? This may take a few minutes.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="create_all">
                    <button type="submit" class="btn btn--secondary">Create <?= (int) $sheetStatus['missing'] ?> Google Sheet(s)</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <p class="form-hint" style="margin:0 0 1rem;">Your full table is in <code>database/live-events-2026.php</code>. After deploy, open <a href="import-roster.php"><strong>Import master roster</strong></a> (or <code>https://admin.olasentra.com/import-roster.php</code> while logged in). Do not use <code>register.olasentra.com/import-summer-roster.php</code> — that URL often 404s if the register folder is empty.</p>
    <?php if ($canAutoSheet): ?>
        <p class="form-hint" style="margin:0 0 1rem;">Google Sheets: <strong><?= (int) ($sheetStatus['total'] - $sheetStatus['missing']) ?></strong> linked, <strong><?= (int) $sheetStatus['missing'] ?></strong> without a sheet. Use <strong>Create … Google Sheet(s)</strong> to auto-generate one spreadsheet per event (no manual copy/paste). Optional: add your Gmail in <a href="settings-production.php#google-sheets">Settings → Google Sheets</a> so new sheets are shared with you.</p>
    <?php else: ?>
        <p class="form-hint" style="margin:0 0 1rem;">To auto-create one Google Sheet per event, upload the service account JSON under <a href="settings-production.php#google-sheets">Settings → Google Sheets</a>.</p>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Event Name</th>
                    <th>Listed contractor</th>
                    <th>Date</th>
                    <th>Venue</th>
                    <th>Work type</th>
                    <th>Location</th>
                    <th>Staff needed</th>
                    <th>Google Sheet</th>
                    <th>Status</th>
                    <th>Registrations</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($events === []): ?>
                    <tr>
                        <td colspan="11" class="data-table__empty">No events yet. Add your first event.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= h($event['name']) ?></td>
                            <td><?= h(formatEventMainSecurityLabel($event) !== '' ? formatEventMainSecurityLabel($event) : '—') ?></td>
                            <td><?= h(formatEventDateLabel($event['event_date'])) ?></td>
                            <td><?= h((string) ($event['venue_name'] ?? '—')) ?></td>
                            <td><?= h(formatWorkTypeLabel((string) ($event['work_type'] ?? 'special_event'))) ?></td>
                            <td><?= h(formatEventLocationLabel($event)) ?></td>
                            <td><?= isset($event['staff_needed']) && $event['staff_needed'] !== null && $event['staff_needed'] !== '' ? (int) $event['staff_needed'] : '—' ?></td>
                            <td>
                                <?php if (trim((string) ($event['google_sheet_url'] ?? '')) !== ''): ?>
                                    <span class="badge badge--approved" title="Syncs on registration">Linked</span>
                                <?php else: ?>
                                    <span class="form-hint">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $event['is_active'] === 1): ?>
                                    <span class="badge badge--approved">Active</span>
                                <?php else: ?>
                                    <span class="badge badge--rejected">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $event['registration_count'] ?></td>
                            <td>
                                <div class="action-group">
                                    <a href="event-sign-qr.php?id=<?= (int) $event['id'] ?>" class="btn btn--small btn--primary">Sign-in</a>
                                    <a href="event-form.php?id=<?= (int) $event['id'] ?>" class="btn btn--small btn--secondary">Edit</a>
                                    <form method="post" action="toggle-event.php">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">
                                        <input type="hidden" name="active" value="<?= (int) $event['is_active'] === 1 ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn--small btn--secondary">
                                            <?= (int) $event['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
