<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/live-events-sync.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/event-capacity.php';

requireAdminCapability('events');

$pdo         = getDB();
$allEvents   = getAllEvents($pdo);
$totalEvents = count($allEvents);
$page        = adminListPage();
$events      = array_slice($allEvents, adminListOffset($page), adminListPerPage());
$flash       = getAdminFlash();
$sheetStatus   = countEventsGoogleSheetStatus($pdo);
$hasSa         = isGoogleServiceAccountConfigured();
$hasOauth      = googleDriveOAuthConfigured($pdo);
$hasDriveFolder = getGoogleSheetsDriveParentFolderId($pdo) !== '';
$canAutoSheet  = $hasSa && $hasDriveFolder;
$syncEnabled   = isGoogleSheetsSyncEnabled($pdo);
$linkedSheets  = (int) ($sheetStatus['total'] - $sheetStatus['missing']);
$missingSheets = (int) $sheetStatus['missing'];
$driveSheets    = $canAutoSheet ? listGoogleDriveSpreadsheetsForAdmin($pdo) : [];
$showSheetBulk  = $events !== [] && ($canAutoSheet || $linkedSheets > 0);
$tableColCount  = $showSheetBulk ? 12 : 11;

$masterContractor     = '';
$eventsMissingCompany = 0;
if (is_file(getLiveEventsMasterFilePath())) {
    try {
        $masterContractor = trim((string) (loadLiveEventsMasterData()['main_security_company'] ?? ''));
    } catch (Throwable $e) {
        $masterContractor = '';
    }
}
foreach ($allEvents as $event) {
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
            <p class="card__subtitle"><?= (int) $totalEvents ?> event(s) — active events with free slots appear on the registration form. Full events stay active here until you increase staff needed.</p>
        </div>
        <div class="toolbar">
            <a href="event-form.php" class="btn btn--primary">+ Add Event</a>
            <a href="import-roster.php" class="btn btn--secondary">Import master roster</a>
            <a href="roster-diagnostic.php" class="btn btn--secondary">Roster diagnostic</a>
            <form method="post" action="events-roster-action.php" class="inline-form" onsubmit="return confirm('Import all 32 summer events from the master list?');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <button type="submit" class="btn btn--secondary">Import (quick)</button>
            </form>
            <?php if ($canAutoSheet && $missingSheets > 0): ?>
                <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Link existing spreadsheets in your Drive folder to the <?= (int) $missingSheets ?> events that have no sheet URL saved?');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="link_from_folder">
                    <button type="submit" class="btn btn--primary">Link sheets from Drive folder</button>
                </form>
                <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Create <?= (int) $missingSheets ?> new Google Sheet(s)? Skip this if sheets already exist in the folder — use Link sheets from Drive folder instead.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="create_all">
                    <button type="submit" class="btn btn--secondary">Create <?= (int) $missingSheets ?> Google Sheet(s)</button>
                </form>
            <?php endif; ?>
            <?php if ($syncEnabled): ?>
                <?php if ($linkedSheets > 0): ?>
                    <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Push approved registrations to linked Google Sheets and remove pending/rejected rows?');">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="sync_registrations">
                        <button type="submit" class="btn btn--secondary">Sync registrations to sheets</button>
                    </form>
                <?php else: ?>
                    <span class="btn btn--secondary" style="opacity:0.55;cursor:not-allowed" title="Create Google Sheet(s) for events first">Sync registrations to sheets</span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($linkedSheets > 0): ?>
                <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Unlink all <?= (int) $linkedSheets ?> event(s) from Google Sheets? Files in Drive are not deleted.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="unlink_all">
                    <button type="submit" class="btn btn--secondary">Unlink all sheets</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="alert alert--info alert--visible" style="margin-bottom:1rem">
        <p style="margin:0 0 0.5rem"><strong>Summer go-live — do these in order</strong></p>
        <ol style="margin:0 0 0 1.25rem;padding:0">
            <li><strong>Import roster</strong> — <a href="import-roster.php">Import master roster</a> → <em>Run import now</em> (loads 32 events + listed contractor from <code>live-events-2026.php</code>). Do not use <code>register.olasentra.com/import-summer-roster.php</code> (often 404).</li>
            <li><strong>Google Sheets setup</strong> — <a href="settings-production.php#google-sheets">Settings → Google Sheets</a>: service account JSON, Drive folder ID, <em>Connect Google account</em> (Gmail), enable <em>live sync</em>. Optional: <a href="google-sheets-diagnostic.php">Run diagnostic</a>.</li>
            <li><strong>Link sheets</strong> — Existing files: <strong>Link sheets from Drive folder</strong>. New files: tick events → <strong>Create sheets for selected</strong>, or <strong>Create <?= $missingSheets ?> Google Sheet(s)</strong> for all unlinked. Status: <strong><?= $linkedSheets ?> linked</strong>, <strong><?= $missingSheets ?> not linked</strong>.</li>
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
            <strong><?= $missingSheets ?> event(s)</strong> are not linked in admin (Google Sheet column shows —).
            If you already created files in Drive, click <strong>Link sheets from Drive folder</strong> — do not create duplicates.
        </div>
    <?php endif; ?>

    <?php if ($canAutoSheet): ?>
        <div class="alert alert--info alert--visible" style="margin-bottom:1rem">
            <p style="margin:0 0 0.35rem"><strong>Start fresh (delete old sheets and recreate)</strong></p>
            <ol style="margin:0 0 0 1.25rem;padding:0;font-size:0.9rem">
                <li>In Google Drive, delete the old event spreadsheets in your shared folder (or move them to trash).</li>
                <li>On this page, tick the events you want → <strong>Unlink selected</strong> (or <strong>Unlink all sheets</strong>).</li>
                <li>Tick the same events → <strong>Create sheets for selected</strong> (you do not need all 32 at once).</li>
                <li><strong>Sync registrations to sheets</strong> when ready.</li>
            </ol>
            <p style="margin:0.5rem 0 0;font-size:0.85rem">Admin unlink does not delete Drive files — delete them in Drive yourself. Sheets created with your Gmail use your storage; <a href="google-sheets-diagnostic.php">Diagnostic</a> can purge sheets owned by the service account only.</p>
        </div>
    <?php endif; ?>

    <?php if ($showSheetBulk): ?>
        <form method="post" action="events-sheets-action.php" class="bulk-toolbar" id="events-sheets-bulk-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <span class="bulk-toolbar__label"><span id="events-sheet-selected-count">0</span> selected</span>
            <?php if ($canAutoSheet): ?>
                <button type="submit" name="action" value="create_selected" class="btn btn--small btn--primary">Create sheets for selected</button>
            <?php endif; ?>
            <?php if ($canAutoSheet && $driveSheets !== []): ?>
                <button type="submit" name="action" value="link_selected" class="btn btn--small btn--secondary">Link selected from folder</button>
            <?php endif; ?>
            <button type="submit" name="action" value="unlink_selected" class="btn btn--small btn--secondary">Unlink selected</button>
        </form>
        <p class="form-hint" style="margin:-0.5rem 0 1rem">
            Tick events below — create new sheets for some events only, link existing files, or unlink.
            <?php if ($canAutoSheet && $driveSheets !== []): ?>
                Or use <strong>Pick Google Sheet</strong> on each row to choose a file from your Drive folder.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <?php if ($showSheetBulk): ?>
                        <th class="data-table__check"><input type="checkbox" id="events-sheet-select-all" aria-label="Select all events"></th>
                    <?php endif; ?>
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
                        <td colspan="<?= (int) $tableColCount ?>" class="data-table__empty">No events yet. Add your first event.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($events as $event): ?>
                        <?php
                        $sheetUrl = trim((string) ($event['google_sheet_url'] ?? ''));
                        $linkedSheetId = $sheetUrl !== '' ? (parseGoogleSpreadsheetId($sheetUrl) ?? '') : '';
                        ?>
                        <tr>
                            <?php if ($showSheetBulk): ?>
                                <td class="data-table__check">
                                    <input
                                        type="checkbox"
                                        form="events-sheets-bulk-form"
                                        name="event_ids[]"
                                        value="<?= (int) $event['id'] ?>"
                                        class="events-sheet-row-check"
                                        aria-label="Select <?= h($event['name']) ?>"
                                    >
                                </td>
                            <?php endif; ?>
                            <td><?= h($event['name']) ?></td>
                            <td><?= h(formatEventMainSecurityLabel($event) !== '' ? formatEventMainSecurityLabel($event) : '—') ?></td>
                            <td><?= h(formatEventDateLabel($event['event_date'])) ?></td>
                            <td><?= h((string) ($event['venue_name'] ?? '—')) ?></td>
                            <td><?= h(formatWorkTypeLabel((string) ($event['work_type'] ?? 'special_event'))) ?></td>
                            <td><?= h(formatEventLocationLabel($event)) ?></td>
                            <td>
                                <?php
                                $capacity = getEventCapacitySummary($pdo, $event);
                                if ($capacity['needed'] === null): ?>
                                    —
                                <?php else: ?>
                                    <?= h(formatEventCapacityAdminLabel($pdo, $event)) ?>
                                    <?php if ($capacity['is_full']): ?>
                                        <br><span class="badge badge--pending">Full</span>
                                    <?php elseif ($capacity['remaining'] !== null && $capacity['remaining'] <= 3): ?>
                                        <br><span class="form-hint"><?= (int) $capacity['remaining'] ?> slot<?= $capacity['remaining'] === 1 ? '' : 's' ?> left</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="events-sheet-cell">
                                <?php if ($sheetUrl !== ''): ?>
                                    <a href="<?= h($sheetUrl) ?>" target="_blank" rel="noopener" class="badge badge--approved" title="Open Google Sheet">Linked ↗</a>
                                <?php else: ?>
                                    <span class="form-hint">Not linked</span>
                                <?php endif; ?>
                                <?php if ($canAutoSheet && $driveSheets !== []): ?>
                                    <form method="post" action="events-sheets-action.php" class="events-sheet-picker">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="link_pick">
                                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                        <select name="spreadsheet_id" class="form-input events-sheet-picker__select" aria-label="Pick Google Sheet for <?= h($event['name']) ?>">
                                            <option value="">Pick Google Sheet…</option>
                                            <?php foreach ($driveSheets as $driveFile): ?>
                                                <option
                                                    value="<?= h($driveFile['id']) ?>"
                                                    <?= $linkedSheetId === $driveFile['id'] ? ' selected' : '' ?>
                                                ><?= h($driveFile['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn--small btn--secondary">Link</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $event['is_active'] === 1): ?>
                                    <span class="badge badge--approved">Active</span>
                                    <?php if ($capacity['is_full'] ?? false): ?>
                                        <br><span class="badge badge--pending" title="Hidden from staff registration until you increase staff needed">Hidden from registration</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge--rejected">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $event['registration_count'] ?></td>
                            <td>
                                <div class="action-group">
                                    <a href="event-sign-qr.php?id=<?= (int) $event['id'] ?>" class="btn btn--small btn--primary">Sign-in</a>
                                    <a href="event-form.php?id=<?= (int) $event['id'] ?>" class="btn btn--small btn--secondary">Edit</a>
                                    <?php if ($sheetUrl !== ''): ?>
                                        <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Unlink this event from its Google Sheet? The file in Drive is not deleted.');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="unlink_one">
                                            <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                            <input type="hidden" name="redirect" value="events.php">
                                            <button type="submit" class="btn btn--small btn--secondary">Unlink sheet</button>
                                        </form>
                                    <?php endif; ?>
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
    <?php renderAdminPagination($page, $totalEvents, 'events.php'); ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
