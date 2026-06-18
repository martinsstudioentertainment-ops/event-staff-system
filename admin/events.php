<?php

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Events error</title></head><body style="font-family:system-ui,sans-serif;padding:2rem;max-width:40rem">';
    echo '<h1>Events page could not load</h1><p>Send this to support:</p><pre style="background:#f1f5f9;padding:1rem;border-radius:8px;overflow:auto">';
    echo htmlspecialchars($err['message'] . "\n" . $err['file'] . ':' . $err['line'], ENT_QUOTES, 'UTF-8');
    echo '</pre><p><a href="dashboard.php">Back to dashboard</a></p></body></html>';
});

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/live-events-sync.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/event-capacity.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';

requireAdminCapability('events');

$pdo         = getDB();
$allEvents   = getAllEvents($pdo);
$todayYmd    = date('Y-m-d');
$view        = strtolower(trim((string) ($_GET['view'] ?? 'all')));
$allowedViews = ['all', 'upcoming', 'past', 'active', 'inactive'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'all';
}

$filteredEvents = array_values(array_filter(
    $allEvents,
    static function (array $event) use ($view, $todayYmd): bool {
        $date   = substr((string) ($event['event_date'] ?? ''), 0, 10);
        $active = (int) ($event['is_active'] ?? 0) === 1;

        return match ($view) {
            'upcoming' => $date >= $todayYmd,
            'past'     => $date !== '' && $date < $todayYmd,
            'active'   => $active,
            'inactive' => !$active,
            default    => true,
        };
    }
));

$eventsPerPage = adminEventsListPerPage();
$totalEvents   = count($filteredEvents);
$page          = adminListPage();
$events        = array_slice($filteredEvents, adminListOffset($page, $eventsPerPage), $eventsPerPage);
$viewCounts    = [
    'all'      => count($allEvents),
    'upcoming' => 0,
    'past'     => 0,
    'active'   => 0,
    'inactive' => 0,
];
foreach ($allEvents as $event) {
    $date   = substr((string) ($event['event_date'] ?? ''), 0, 10);
    $active = (int) ($event['is_active'] ?? 0) === 1;
    if ($date >= $todayYmd) {
        $viewCounts['upcoming']++;
    }
    if ($date !== '' && $date < $todayYmd) {
        $viewCounts['past']++;
    }
    if ($active) {
        $viewCounts['active']++;
    } else {
        $viewCounts['inactive']++;
    }
}
$flash         = getAdminFlash();
$sheetStatus   = countEventsGoogleSheetStatus($pdo);
$hasSa         = isGoogleServiceAccountConfigured();
$hasOauth      = googleDriveOAuthConfigured($pdo);
$hasDriveFolder = getGoogleSheetsDriveParentFolderId($pdo) !== '';
$canAutoSheet  = $hasSa && $hasDriveFolder;
$syncEnabled   = isGoogleSheetsSyncEnabled($pdo);
$linkedSheets  = (int) ($sheetStatus['total'] - $sheetStatus['missing']);
$missingSheets = (int) $sheetStatus['missing'];
$driveSheets   = [];
$showSheetBulk = $events !== [] && ($canAutoSheet || $linkedSheets > 0);
$tableColCount = $showSheetBulk ? 12 : 11;

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

<?php $eventsGuideOpen = $missingSheets > 0 || !$canAutoSheet || $eventsMissingCompany > 0; ?>
<section class="card events-page">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Events</h2>
            <p class="card__subtitle">Manage gigs, capacity, and Google Sheet links.</p>
        </div>
        <div class="events-page__actions">
            <a href="event-form.php" class="btn btn--primary">+ Add event</a>
            <a href="import-roster.php" class="btn btn--secondary">Import roster</a>
            <details class="events-page__more">
                <summary class="btn btn--secondary">More actions</summary>
                <div class="events-page__more-panel">
                    <div class="events-page__more-group">
                        <p class="events-page__more-label">Roster</p>
                        <a href="roster-diagnostic.php" class="btn btn--small btn--secondary">Roster diagnostic</a>
                        <a href="same-day-conflicts.php" class="btn btn--small btn--secondary">Same-day conflicts</a>
                        <form method="post" action="events-roster-action.php" class="inline-form" onsubmit="return confirm('Import all summer events from the master list?');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <button type="submit" class="btn btn--small btn--secondary">Quick import</button>
                        </form>
                    </div>
                    <?php if ($canAutoSheet || $linkedSheets > 0 || $syncEnabled): ?>
                        <div class="events-page__more-group">
                            <p class="events-page__more-label">Google Sheets</p>
                            <?php if ($canAutoSheet && $missingSheets > 0): ?>
                                <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Link spreadsheets in Drive to <?= (int) $missingSheets ?> unlinked event(s)?');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="link_from_folder">
                                    <button type="submit" class="btn btn--small btn--primary">Link from Drive folder</button>
                                </form>
                                <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Create <?= (int) $missingSheets ?> new sheet(s)? Use Link from folder if files already exist.');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="create_all">
                                    <button type="submit" class="btn btn--small btn--secondary">Create <?= (int) $missingSheets ?> sheet(s)</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($syncEnabled && $linkedSheets > 0): ?>
                                <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Push approved registrations to linked sheets?');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="sync_registrations">
                                    <button type="submit" class="btn btn--small btn--secondary">Sync registrations</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($linkedSheets > 0): ?>
                                <form method="post" action="events-sheets-action.php" class="inline-form" onsubmit="return confirm('Unlink all <?= (int) $linkedSheets ?> event(s)? Drive files are kept.');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="unlink_all">
                                    <button type="submit" class="btn btn--small btn--secondary">Unlink all</button>
                                </form>
                            <?php endif; ?>
                            <a href="google-sheets-diagnostic.php" class="btn btn--small btn--secondary">Sheets diagnostic</a>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    </div>

    <div class="events-page__stats" aria-label="Event summary">
        <span class="events-page__chip"><strong><?= (int) count($allEvents) ?></strong> events total</span>
        <span class="events-page__chip"><strong><?= (int) $viewCounts['upcoming'] ?></strong> upcoming</span>
        <span class="events-page__chip"><strong><?= (int) $viewCounts['past'] ?></strong> past</span>
        <span class="events-page__chip events-page__chip--ok"><strong><?= (int) $linkedSheets ?></strong> sheets linked</span>
        <?php if ($missingSheets > 0): ?>
            <span class="events-page__chip events-page__chip--warn"><strong><?= (int) $missingSheets ?></strong> not linked</span>
        <?php endif; ?>
        <?php if ($syncEnabled): ?>
            <span class="events-page__chip">Live sync on</span>
        <?php endif; ?>
    </div>

    <?php if ($missingSheets > 0 && $canAutoSheet): ?>
        <p class="events-page__nudge">
            <?= (int) $missingSheets ?> event(s) need a sheet —
            use <strong>Link from Drive folder</strong> in More actions if files already exist.
        </p>
    <?php endif; ?>

    <details class="events-page__guide"<?= $eventsGuideOpen ? ' open' : '' ?>>
        <summary>Setup guide &amp; troubleshooting</summary>
        <div class="events-page__guide-body">
            <ol class="events-page__steps">
                <li><strong>Import roster</strong> — <a href="import-roster.php">Import master roster</a> → Run import now.</li>
                <li><strong>Google Sheets</strong> — <a href="settings-production.php#google-sheets">Settings</a>: service account, Drive folder, Connect Google account, enable live sync.</li>
                <li><strong>Link sheets</strong> — Link from Drive folder, or tick rows below → create/link selected.</li>
                <li><strong>Sync rows</strong> — Sync registrations after sheets are linked<?= $syncEnabled ? '' : ' (enable live sync in Settings first)' ?>.</li>
            </ol>
            <?php if (!$canAutoSheet): ?>
                <p class="form-hint" style="margin:0.5rem 0 0">
                    <?php if (!$hasSa): ?>Upload service account JSON in Settings.<?php endif; ?>
                    <?php if ($hasSa && !$hasDriveFolder): ?> Add Drive folder ID in Settings.<?php endif; ?>
                    <?php if ($hasSa && $hasDriveFolder && !$hasOauth): ?> Connect Google account in Settings.<?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($canAutoSheet): ?>
                <details class="events-page__subguide">
                    <summary>Start fresh (recreate sheets)</summary>
                    <ol class="events-page__steps events-page__steps--compact">
                        <li>Delete old spreadsheets in Google Drive.</li>
                        <li>Tick events → Unlink selected (or Unlink all).</li>
                        <li>Tick events → Create sheets for selected.</li>
                        <li>Sync registrations when ready.</li>
                    </ol>
                </details>
            <?php endif; ?>
        </div>
    </details>

    <nav class="events-page__filters" aria-label="Filter events">
        <?php
        $filterLinks = [
            'all'      => 'All',
            'upcoming' => 'Upcoming',
            'past'     => 'Past',
            'active'   => 'Active',
            'inactive' => 'Inactive',
        ];
        foreach ($filterLinks as $key => $label):
            $isActive = $view === $key;
            $href     = 'events.php' . ($key !== 'all' ? '?view=' . rawurlencode($key) : '');
            ?>
            <a href="<?= h($href) ?>" class="events-page__filter<?= $isActive ? ' events-page__filter--active' : '' ?>">
                <?= h($label) ?> <span class="events-page__filter-count"><?= (int) ($viewCounts[$key] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($totalEvents > 0): ?>
        <?php renderAdminPagination($page, $totalEvents, 'events.php', $view !== 'all' ? ['view' => $view] : [], $eventsPerPage); ?>
    <?php endif; ?>

    <?php if ($showSheetBulk): ?>
        <form method="post" action="events-sheets-action.php" class="bulk-toolbar events-page__bulk" id="events-sheets-bulk-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <span class="bulk-toolbar__label"><span id="events-sheet-selected-count">0</span> selected</span>
            <?php if ($canAutoSheet): ?>
                <button type="submit" name="action" value="create_selected" class="btn btn--small btn--primary">Create sheets</button>
            <?php endif; ?>
            <?php if ($canAutoSheet && $driveSheets !== []): ?>
                <button type="submit" name="action" value="link_selected" class="btn btn--small btn--secondary">Link from folder</button>
            <?php endif; ?>
            <button type="submit" name="action" value="unlink_selected" class="btn btn--small btn--secondary">Unlink</button>
        </form>
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
                        <td colspan="<?= (int) $tableColCount ?>" class="data-table__empty">
                            <?php if ($allEvents === []): ?>
                                No events yet. Add your first event.
                            <?php else: ?>
                                No events match this filter. <a href="events.php">Show all <?= (int) count($allEvents) ?> events</a>
                            <?php endif; ?>
                        </td>
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
                                    <?php if (adminCan('export')): ?>
                                        <a href="export-event-signins.php?event_id=<?= (int) $event['id'] ?>&format=xlsx" class="btn btn--small btn--secondary">Sign-ins Excel</a>
                                    <?php endif; ?>
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
                                    <a href="event-form.php?id=<?= (int) $event['id'] ?>#cancel-event-shifts" class="btn btn--small btn--danger">Cancel shifts</a>
                                    <form method="post" action="toggle-event.php">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">
                                        <input type="hidden" name="active" value="<?= (int) $event['is_active'] === 1 ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn--small btn--secondary">
                                            <?= (int) $event['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                    <a
                                        href="delete-event.php?id=<?= (int) $event['id'] ?>"
                                        class="btn btn--small btn--secondary events-delete-btn"
                                    >Delete</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php renderAdminPagination($page, $totalEvents, 'events.php', $view !== 'all' ? ['view' => $view] : [], $eventsPerPage); ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
