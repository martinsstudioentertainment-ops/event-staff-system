<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('attendance');

$pdo     = getDB();
$eventId = (int) ($_GET['event_id'] ?? 0);
$events  = getEventsForFilter($pdo);
$page    = adminListPage();
$listTotal = countAttendanceList($pdo, $eventId);
$list    = getAttendanceList($pdo, $eventId, adminListPerPage(), adminListOffset($page));
$stats   = getAttendanceStats($pdo, $eventId);
$flash   = getAdminFlash();
$selectedEvent = $eventId > 0 ? getEventById($pdo, $eventId) : null;

$pageTitle  = 'Attendance';
$activePage = 'attendance';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card" id="attendance-live-board" data-event-id="<?= (int) $eventId ?>">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Live attendance</h2>
            <p class="card__subtitle">
                Auto-refreshes every 15 seconds.
                <span id="attendance-live-updated" class="attendance-live__stamp"></span>
            </p>
        </div>
        <div class="toolbar toolbar--compact no-print">
            <?php if ($eventId > 0): ?>
                <a href="staff-message.php?event_id=<?= (int) $eventId ?>" class="btn btn--primary">Email staff</a>
            <?php endif; ?>
            <a href="export-attendance.php<?= $eventId > 0 ? '?event_id=' . (int) $eventId : '' ?>" class="btn btn--secondary">Export CSV</a>
            <a href="work-hours.php<?= $eventId > 0 ? '?event_id=' . (int) $eventId : '' ?>" class="btn btn--secondary">Work hours</a>
            <?php if ($eventId > 0): ?>
                <a href="scan-checkin.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">Scan QR</a>
                <a href="print-roster.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">Print Roster</a>
                <a href="print-qr.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">Print Staff QR</a>
                <a href="event-sign-qr.php?id=<?= (int) $eventId ?>" class="btn btn--secondary">Sign-in Links</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <p class="stat-card__value" id="live-stat-approved"><?= (int) $stats['approved'] ?></p>
            <p class="stat-card__label">Approved<?= $eventId > 0 ? '' : ' (filtered)' ?></p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value" id="live-stat-checked-in"><?= (int) $stats['checked_in'] ?></p>
            <p class="stat-card__label">Checked in</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value" id="live-stat-missing"><?= (int) $stats['missing'] ?></p>
            <p class="stat-card__label">Not yet arrived</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value" id="live-stat-today"><?= (int) $stats['today'] ?></p>
            <p class="stat-card__label">Today's check-ins</p>
        </div>
        <div class="stat-card" data-staff-capacity<?= $stats['staff_needed'] === null ? ' hidden' : '' ?>>
            <p class="stat-card__value" id="live-stat-needed"><?= $stats['staff_needed'] !== null ? (int) $stats['staff_needed'] : '—' ?></p>
            <p class="stat-card__label">Staff needed</p>
        </div>
        <div class="stat-card" data-staff-capacity<?= $stats['staff_needed'] === null ? ' hidden' : '' ?>>
            <p class="stat-card__value" id="live-stat-spaces"><?= $stats['spaces_remaining'] !== null ? (int) $stats['spaces_remaining'] : '—' ?></p>
            <p class="stat-card__label">Spaces remaining</p>
        </div>
    </div>

    <?php if ($stats['staff_needed'] !== null): ?>
        <div class="attendance-capacity">
            <p class="attendance-capacity__label" id="attendance-capacity-label">
                <?= (int) $stats['approved'] ?> / <?= (int) $stats['staff_needed'] ?> approved
                · <?= (int) $stats['spaces_remaining'] ?> spaces left
            </p>
            <div class="attendance-capacity__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= min(100, (int) round(($stats['approved'] / max(1, $stats['staff_needed'])) * 100)) ?>">
                <div class="attendance-capacity__bar" id="attendance-capacity-bar" style="width: <?= min(100, (int) round(($stats['approved'] / max(1, $stats['staff_needed'])) * 100)) ?>%"></div>
            </div>
        </div>
    <?php else: ?>
        <div class="attendance-capacity" hidden>
            <p class="attendance-capacity__label" id="attendance-capacity-label"></p>
            <div class="attendance-capacity__track"><div class="attendance-capacity__bar" id="attendance-capacity-bar"></div></div>
        </div>
    <?php endif; ?>

    <?php if ($selectedEvent): ?>
        <p class="form-hint attendance-live__event-meta">
            <?= h($selectedEvent['name']) ?> · <?= h(formatEventLocationLabel($selectedEvent)) ?>
            · <?= h(formatEventTimeRangeLabel($selectedEvent)) ?>
        </p>
    <?php endif; ?>

    <div class="attendance-live__recent-wrap">
        <h3 class="attendance-live__recent-title">Recent check-ins</h3>
        <ul class="attendance-live__recent" id="attendance-live-recent">
            <li class="attendance-live__empty">Loading…</li>
        </ul>
    </div>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Check-in roster</h2>
        <p class="card__subtitle">QR check-in for approved staff only.</p>
    </div>

    <form method="get" class="filter-bar filter-bar--attendance">
        <div class="filter-bar__group">
            <select class="form-select" name="event_id">
                <option value="">All events</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>"<?= $eventId === (int) $event['id'] ? ' selected' : '' ?>>
                        <?= h($event['name'] . ' — ' . date('d.m.Y', strtotime($event['event_date']))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="attendance.php" class="btn btn--secondary">Reset</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Event</th>
                    <th>Role</th>
                    <th>Check-in Status</th>
                    <th>Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($list === []): ?>
                    <tr>
                        <td colspan="6" class="data-table__empty">No approved staff found for this filter.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td><?= h($row['first_name'] . ' ' . $row['surname']) ?></td>
                            <td><?= h(formatEventLabel($row)) ?></td>
                            <td><?= h(formatRoleLabel($row['staff_role'])) ?></td>
                            <td>
                                <?php if ((int) $row['is_checked_in'] === 1): ?>
                                    <span class="badge badge--approved">Checked In</span>
                                <?php else: ?>
                                    <span class="badge badge--pending">Waiting</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $row['checked_in_at'] ? h(date('d.m.Y H:i', strtotime($row['checked_in_at']))) : '—' ?>
                            </td>
                            <td>
                                <div class="action-group">
                                    <a href="qr.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--secondary">QR Code</a>
                                    <?php if ((int) $row['is_checked_in'] === 0): ?>
                                        <form method="post" action="checkin-action.php">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                            <button type="submit" class="btn btn--small btn--success">Check In</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    renderAdminPagination($page, $listTotal, 'attendance.php', $eventId > 0 ? ['event_id' => $eventId] : []);
    ?>
</section>

<?php
$enableAttendanceLive = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';
