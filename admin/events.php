<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/live-events-sync.php';

requireAdminCapability('events');

$pdo         = getDB();
$events      = getAllEvents($pdo);
$flash       = getAdminFlash();
$sheetStatus   = countEventsGoogleSheetStatus($pdo);
$hasSa         = isGoogleServiceAccountConfigured();
$hasOauth      = googleDriveOAuthConfigured($pdo);
$hasDriveFolder = getGoogleSheetsDriveParentFolderId($pdo) !== '';
$canAutoSheet  = $hasSa && $hasDriveFolder;
$syncEnabled   = isGoogleSheetsSyncEnabled($pdo);
$linkedSheets  = (int) ($sheetStatus['total'] - $sheetStatus['missing']);
$missingSheets = (int) $sheetStatus['missing'];

$masterContractor     = '';
$eventsMissingCompany = 0;
if (is_file(getLiveEventsMasterFilePath())) {
    try {
        $masterContractor = trim((string) (loadLiveEventsMasterData()['main_security_company'] ?? ''));
    } catch (Throwable $e) {
        $masterContractor = '';
    }
}
foreach ($events as $event) {
    if (formatEventMainSecurityLabel($event) === '') {
        $eventsMissingCompany++;
    }
}

$pageTitle  = 'Event Management';
$activePage = 'events';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<?php if ($masterContractor !== '' && $eventsMissingCompany > 0): ?>
    <div class="alert alert--warning alert--visible">
        <strong><?= (int) $eventsMissingCompany ?> event(s)</strong> still have no listed contractor.
        The master roster expects <strong><?= h($masterContractor) ?></strong>.
        Open <a href="import-roster.php"><strong>Import master roster</strong></a> and click <strong>Run import now</strong>
        (or use <strong>Import (quick)</strong> above) to apply it to all 32 events.
    </div>
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
            <?php if ($sheetStatus['total'] > $sheetStatus['missing'] && isGoogleSheetsSyncEnabled($pdo)): ?>
                <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Push all registrations to their linked Google Sheets?');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="sync_registrations">
                    <button type="submit" class="btn btn--secondary">Sync registrations to sheets</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="alert alert--info alert--visible" style="margin-bottom:1rem">
        <p style="margin:0 0 0.5rem"><strong>Summer go-live — do these in order</strong></p>
        <ol style="margin:0 0 0 1.25rem;padding:0">
            <li><strong>Import roster</strong> — <a href="import-roster.php">Import master roster</a> → <em>Run import now</em> (loads 32 events + listed contractor from <code>live-events-2026.php</code>). Do not use <code>register.olasentra.com/import-summer-roster.php</code> (often 404).</li>
            <li><strong>Google Sheets setup</strong> — <a href="settings-production.php#google-sheets">Settings → Google Sheets</a>: service account JSON, Drive folder ID, <em>Connect Google account</em> (Gmail), enable <em>live sync</em>. Optional: <a href="google-sheets-diagnostic.php">Run diagnostic</a>.</li>
            <li><strong>Create sheets</strong> — Click <strong>Create <?= $missingSheets ?> Google Sheet(s)</strong> above (one file per event in your Drive folder). Status: <strong><?= $linkedSheets ?> linked</strong>, <strong><?= $missingSheets ?> still need a sheet</strong>.</li>
            <?php if ($syncEnabled && $linkedSheets > 0): ?>
                <li><strong>Sync staff rows</strong> — After sheets exist, click <strong>Sync registrations to sheets</strong> (or approve staff — each approval updates the sheet).</li>
            <?php elseif ($syncEnabled): ?>
                <li><strong>Sync staff rows</strong> — Available after step 3 links sheets to events.</li>
            <?php else: ?>
                <li><strong>Sync staff rows</strong> — Turn on <em>Enable live sync</em> in Settings after step 3.</li>
            <?php endif; ?>
        </ol>
        <?php if (!$canAutoSheet): ?>
            <p style="margin:0.75rem 0 0;font-size:0.9rem">
                <?php if (!$hasSa): ?>Upload the <strong>service account JSON</strong> in Settings.<?php endif; ?>
                <?php if ($hasSa && !$hasDriveFolder): ?> Paste your shared <strong>Drive folder ID</strong> in Settings.<?php endif; ?>
                <?php if ($hasSa && $hasDriveFolder && !$hasOauth): ?> <strong>Connect Google account</strong> in Settings so copies use your Gmail storage (required for auto-create).<?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <?php if ($missingSheets > 0 && $canAutoSheet): ?>
        <div class="alert alert--warning alert--visible" style="margin-bottom:1rem">
            <strong><?= $missingSheets ?> event(s)</strong> have no Google Sheet yet. Click
            <strong>Create <?= $missingSheets ?> Google Sheet(s)</strong> in the toolbar (may take a few minutes).
        </div>
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
