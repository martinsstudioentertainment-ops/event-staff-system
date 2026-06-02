<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

requireAdminCapability('export');

$pdo     = getDB();
$eventId = (int) ($_GET['event_id'] ?? 0);

if (isset($_GET['event_id'])) {
    $list = getAttendanceList($pdo, $eventId);
    $filename = 'attendance-' . date('Y-m-d-His') . '.csv';

    logAdminAudit($pdo, 'export_attendance', 'event', $eventId > 0 ? $eventId : null, count($list) . ' rows');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Name', 'Email', 'Role', 'Event', 'Check-in Status', 'Check-in Time', 'Method']);

    foreach ($list as $row) {
        fputcsv($output, [
            trim($row['first_name'] . ' ' . $row['surname']),
            $row['email'],
            formatRoleLabel($row['staff_role']),
            formatEventLabel($row),
            (int) $row['is_checked_in'] === 1 ? 'Checked In' : 'Waiting',
            $row['checked_in_at'] ?? '',
            $row['checked_in_method'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}

$events = getEventsForFilter($pdo);$flash  = getAdminFlash();

$pageTitle          = 'Export Attendance CSV';
$activePage         = 'export-attendance';
$adminSectionNav    = getAdminExportNavItems();
$adminSectionActive = 'attendance';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Attendance CSV</h2>
        <p class="card__subtitle">Download approved staff with check-in status and times.</p>
    </div>

    <form method="get" action="export-attendance.php" class="filter-bar">
        <div class="filter-bar__group">
            <select class="form-select" name="event_id">
                <option value="">All events</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>">
                        <?= h($event['name'] . ' — ' . date('d.m.Y', strtotime($event['event_date']))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Download CSV</button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
