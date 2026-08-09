<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/friendly-response.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/event-signin-export.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

requireAdminCapability('export');

$pdo     = getDB();
$eventId = (int) ($_GET['event_id'] ?? 0);
$format  = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'csv'], true)) {
    $format = 'xlsx';
}

if ($eventId > 0) {
    try {
        $event = getEventById($pdo, $eventId);
        if ($event === null) {
            setAdminFlash('error', 'Event not found.');
            header('Location: export-event-signins.php');
            exit;
        }

        $rows = getContractorSheetSignInRows($pdo, $eventId);

        logAdminAudit(
            $pdo,
            'export_event_signins',
            'event',
            $eventId,
            count($rows) . ' rows (' . $format . ')'
        );

        sendEventSignInExportDownload($pdo, $eventId, $rows, $format);
        exit;
    } catch (Throwable $e) {
        friendlyLogError('export-event-signins', $e);
        renderFriendlyHtmlPage(
            'Export unavailable',
            'We could not export sign-ins right now. Please return to Attendance and try again.',
            200,
            [['label' => 'Open Attendance', 'href' => 'attendance.php']]
        );
    }
}

$events = getEventsForFilter($pdo);
$flash  = getAdminFlash();

$pageTitle          = 'Export event sign-ins';
$activePage         = 'export-attendance';
$adminSectionNav    = getAdminExportNavItems();
$adminSectionActive = 'signins';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Event sign-ins</h2>
        <p class="card__subtitle">Download signed-in staff only for one event (Excel or CSV) — ready to send to contractors. Does not include waiting or no-show staff.</p>
    </div>

    <form method="get" action="export-event-signins.php" class="filter-bar">
        <div class="filter-bar__group">
            <select class="form-select" name="event_id" required>
                <option value="">Select event…</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>">
                        <?= h($event['name'] . ' — ' . formatEventDateLabel((string) $event['event_date'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="format">
                <option value="xlsx" selected>Excel (.xlsx)</option>
                <option value="csv">CSV</option>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Download</button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
