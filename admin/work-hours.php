<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('attendance');

$pdo      = getDB();
$eventId  = (int) ($_GET['event_id'] ?? 0);
$workDate = trim((string) ($_GET['work_date'] ?? ''));
$events   = getEventsForFilter($pdo);
$allList  = getWorkHoursList($pdo, $eventId, $workDate);
$page     = adminListPage();
$list     = array_slice($allList, adminListOffset($page), adminListPerPage());
$listTotal = count($allList);
$totals   = getWorkHoursTotals($pdo, $eventId, $workDate);
$flash    = getAdminFlash();
$selectedEvent = $eventId > 0 ? getEventById($pdo, $eventId) : null;
$eventInvoice  = $eventId > 0 ? getCommissionInvoiceByEventId($pdo, $eventId) : null;
$canEdit  = adminCan('attendance') && in_array(getAdminRole(), ['admin', 'manager'], true);

$pageTitle  = 'Work hours';
$activePage = 'work-hours';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Work hours ledger</h2>
            <p class="card__subtitle">One row per staff member who signed in. Hours are calculated from sign-in time to event end — adjust down if sent home early or absent part of shift.</p>
        </div>
        <div class="toolbar toolbar--compact">
            <?php if ($eventId > 0 && adminCan('invoices')): ?>
                <?php if ($eventInvoice): ?>
                    <a href="invoice-form.php?id=<?= (int) $eventInvoice['id'] ?>" class="btn btn--primary">Open commission invoice</a>
                <?php else: ?>
                    <a href="invoice-form.php?event_id=<?= (int) $eventId ?>" class="btn btn--primary">Create commission invoice</a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="export-work-hours.php?event_id=<?= (int) $eventId ?>&amp;work_date=<?= h(urlencode($workDate)) ?>" class="btn btn--secondary">Export CSV</a>
            <a href="attendance.php<?= $eventId > 0 ? '?event_id=' . (int) $eventId : '' ?>" class="btn btn--secondary">← Attendance</a>
        </div>
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
        <div class="filter-bar__group">
            <input class="form-input" type="date" name="work_date" value="<?= h($workDate) ?>" placeholder="Event date">
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="work-hours.php" class="btn btn--secondary">Reset</a>
        </div>
    </form>

    <div class="stat-grid">
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $totals['headcount'] ?></p>
            <p class="stat-card__label">Staff signed in</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= h(formatHoursDecimal($totals['hours_worked'])) ?></p>
            <p class="stat-card__label">Total hours worked</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= h(formatHoursDecimal($totals['hours_paid'])) ?></p>
            <p class="stat-card__label">Total hours payable</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= h(formatHoursDecimal($totals['scheduled_hours'])) ?></p>
            <p class="stat-card__label">Scheduled shift total</p>
        </div>
    </div>

    <?php if ($selectedEvent): ?>
        <p class="form-hint">
            <?= h($selectedEvent['name']) ?> · <?= h(formatEventDateLabel((string) $selectedEvent['event_date'])) ?>
            · <?= h(formatEventTimeRangeLabel($selectedEvent)) ?>
            · Job day total payable: <strong><?= h(formatHoursDecimal($totals['hours_paid'])) ?></strong>
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Staff timesheet</h2>
        <p class="card__subtitle">Lads &amp; ladies who checked in — edit payable hours when someone leaves early or is sent home.</p>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Gender</th>
                    <th>Event / job</th>
                    <th>Role</th>
                    <th>Signed in</th>
                    <th>Shift end</th>
                    <th>Worked</th>
                    <th>Payable</th>
                    <th>Note</th>
                    <?php if ($canEdit): ?><th>Adjust</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($list === []): ?>
                    <tr>
                        <td colspan="<?= $canEdit ? 10 : 9 ?>" class="data-table__empty">No sign-ins yet for this filter. Staff appear here after they check in.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td>
                                <a href="view-staff.php?id=<?= (int) $row['registration_id'] ?>"><?= h($row['first_name'] . ' ' . $row['surname']) ?></a>
                            </td>
                            <td><?= h(formatGenderLabel((string) $row['gender'])) ?></td>
                            <td><?= h(formatEventLabel($row)) ?></td>
                            <td><?= h(formatRoleLabel($row['staff_role'])) ?></td>
                            <td><?= h(date('d.m.Y H:i', strtotime((string) $row['checked_in_at']))) ?></td>
                            <td><?= $row['work_end_at'] ? h(date('d.m.Y H:i', strtotime((string) $row['work_end_at']))) : '—' ?></td>
                            <td><?= h(formatHoursDecimal((float) ($row['hours_worked'] ?? 0))) ?></td>
                            <td>
                                <strong><?= h(formatHoursDecimal((float) ($row['hours_paid'] ?? 0))) ?></strong>
                                <?php if ((float) ($row['hours_paid'] ?? 0) < (float) ($row['hours_worked'] ?? 0)): ?>
                                    <span class="badge badge--pending" title="Adjusted down">Adj</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h((string) ($row['hours_note'] ?? '')) ?></td>
                            <?php if ($canEdit): ?>
                                <td>
                                    <details class="work-hours-edit">
                                        <summary class="btn btn--small btn--secondary">Edit</summary>
                                        <form method="post" action="work-hours-action.php" class="work-hours-edit__form">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="attendance_id" value="<?= (int) $row['attendance_id'] ?>">
                                            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                            <input type="hidden" name="work_date" value="<?= h($workDate) ?>">
                                            <label class="form-label" for="hours_paid_<?= (int) $row['attendance_id'] ?>">Payable hours</label>
                                            <input class="form-input" type="number" step="0.25" min="0" max="<?= h((string) ($row['hours_worked'] ?? 0)) ?>" id="hours_paid_<?= (int) $row['attendance_id'] ?>" name="hours_paid" value="<?= h((string) ($row['hours_paid'] ?? '0')) ?>" required>
                                            <p class="form-hint">Max <?= h(formatHoursDecimal((float) ($row['hours_worked'] ?? 0))) ?> (calculated from sign-in to shift end)</p>
                                            <label class="form-label" for="hours_note_<?= (int) $row['attendance_id'] ?>">Reason (sent home, sick, etc.)</label>
                                            <input class="form-input" type="text" id="hours_note_<?= (int) $row['attendance_id'] ?>" name="hours_note" value="<?= h((string) ($row['hours_note'] ?? '')) ?>" placeholder="e.g. Sent home early — unwell">
                                            <button type="submit" class="btn btn--primary btn--small">Save hours</button>
                                        </form>
                                    </details>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="data-table__foot">
                        <td colspan="6"><strong>Day / filter total</strong></td>
                        <td><strong><?= h(formatHoursDecimal($totals['hours_worked'])) ?></strong></td>
                        <td><strong><?= h(formatHoursDecimal($totals['hours_paid'])) ?></strong></td>
                        <td colspan="<?= $canEdit ? 3 : 2 ?>"></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    renderAdminPagination($page, $listTotal, 'work-hours.php', array_filter([
        'event_id'  => $eventId > 0 ? $eventId : null,
        'work_date' => $workDate !== '' ? $workDate : null,
    ]));
    ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
