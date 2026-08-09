<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';
require_once __DIR__ . '/../includes/attendance-roster-helpers.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/registration-bib.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('attendance');

$pdo      = getDB();
$eventId  = (int) ($_GET['event_id'] ?? 0);
$workDate = trim((string) ($_GET['work_date'] ?? ''));
$events   = getEventsForAttendanceFilter($pdo);
$allList  = getWorkHoursList($pdo, $eventId, $workDate);
$page     = adminListPage();
$list     = array_slice($allList, adminListOffset($page), adminListPerPage());
$listTotal = count($allList);
$totals   = getWorkHoursTotals($pdo, $eventId, $workDate);
$flash    = getAdminFlash();
$selectedEvent = $eventId > 0 ? getEventById($pdo, $eventId) : null;
$eventInvoice  = $eventId > 0 ? getCommissionInvoiceByEventId($pdo, $eventId) : null;
$canEdit  = adminCan('attendance') && in_array(getAdminRole(), ['admin', 'manager'], true);
$bibEnabled = registrationBibColumnEnabled($pdo);

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
                        <?= h(formatEventFilterOptionLabel($event)) ?>
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
        <p class="card__subtitle">Per-person payable hours and bib numbers. Use <strong>Edit</strong> to set full shift hours or reduce when sent home early.</p>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <?php if ($bibEnabled): ?><th>Bib #</th><?php endif; ?>
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
                <?php
                $tableCols = 9 + ($bibEnabled ? 1 : 0) + ($canEdit ? 1 : 0);
                ?>
                <?php if ($list === []): ?>
                    <tr>
                        <td colspan="<?= $tableCols ?>" class="data-table__empty">No sign-ins yet for this filter. Staff appear here after they check in.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                        <?php
                        $rowScheduled = (float) ($row['scheduled_hours'] ?? 0);
                        if ($rowScheduled <= 0) {
                            $rowScheduled = resolveEventScheduledHoursFromRow($row);
                        }
                        $hoursWorked = (float) ($row['hours_worked'] ?? 0);
                        ?>
                        <tr>
                            <td>
                                <a href="view-staff.php?id=<?= (int) $row['registration_id'] ?>"><?= h($row['first_name'] . ' ' . $row['surname']) ?></a>
                            </td>
                            <?php if ($bibEnabled): ?>
                            <td><?= h(formatRegistrationBibDisplay($row['assigned_bib_number'] ?? null)) ?></td>
                            <?php endif; ?>
                            <td><?= h(formatGenderLabel((string) $row['gender'])) ?></td>
                            <td><?= h(formatEventLabel($row)) ?></td>
                            <td><?= h(formatRoleLabel($row['staff_role'])) ?></td>
                            <td><?= h(formatSystemDateTime((string) $row['checked_in_at'], $pdo)) ?></td>
                            <td><?= $row['work_end_at'] ? h(formatSystemDateTime((string) $row['work_end_at'], $pdo)) : '—' ?></td>
                            <td><?= h(formatHoursDecimal($hoursWorked)) ?></td>
                            <td>
                                <strong><?= h(formatHoursDecimal((float) ($row['hours_paid'] ?? 0))) ?></strong>
                                <?php if ((float) ($row['hours_paid'] ?? 0) < $hoursWorked): ?>
                                    <span class="badge badge--pending" title="Adjusted down">Adj</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h((string) ($row['hours_note'] ?? '')) ?></td>
                            <?php if ($canEdit): ?>
                                <td>
                                    <details class="work-hours-edit">
                                        <summary class="btn btn--small btn--secondary">Edit</summary>
                                        <div class="work-hours-edit__form">
                                            <form method="post" action="work-hours-action.php">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                <input type="hidden" name="attendance_id" value="<?= (int) $row['attendance_id'] ?>">
                                                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                                <input type="hidden" name="work_date" value="<?= h($workDate) ?>">
                                                <input type="hidden" name="hours_override" value="1">
                                                <p class="form-hint" style="margin:0 0 0.5rem;"><strong>Set full shift hours</strong></p>
                                                <label class="form-label" for="override_hours_<?= (int) $row['attendance_id'] ?>">Payable hours</label>
                                                <input class="form-input" type="number" step="0.25" min="0.25"
                                                    max="<?= h((string) max(0.25, $rowScheduled)) ?>"
                                                    id="override_hours_<?= (int) $row['attendance_id'] ?>" name="hours_paid"
                                                    value="<?= h((string) ($row['hours_paid'] ?? '0')) ?>" required>
                                                <p class="form-hint">Scheduled shift: <?= h(formatHoursDecimal($rowScheduled)) ?></p>
                                                <label class="form-label" for="override_note_<?= (int) $row['attendance_id'] ?>">Note</label>
                                                <input class="form-input" type="text" id="override_note_<?= (int) $row['attendance_id'] ?>" name="hours_note"
                                                    value="<?= h((string) ($row['hours_note'] ?? '')) ?>"
                                                    placeholder="e.g. Worked full shift — manual correction">
                                                <button type="submit" class="btn btn--primary btn--small">Save shift hours</button>
                                            </form>

                                            <?php if ($hoursWorked > 0): ?>
                                            <form method="post" action="work-hours-action.php" style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--border-color, #334155);">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                <input type="hidden" name="attendance_id" value="<?= (int) $row['attendance_id'] ?>">
                                                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                                <input type="hidden" name="work_date" value="<?= h($workDate) ?>">
                                                <input type="hidden" name="sent_home" value="1">
                                                <p class="form-hint" style="margin:0 0 0.5rem;"><strong>Sent home early</strong></p>
                                                <label class="form-label" for="sent_home_hours_<?= (int) $row['attendance_id'] ?>">Payable hours</label>
                                                <input class="form-input" type="number" step="0.25" min="0"
                                                    max="<?= h((string) $hoursWorked) ?>"
                                                    id="sent_home_hours_<?= (int) $row['attendance_id'] ?>" name="hours_paid"
                                                    value="<?= h((string) ($row['hours_paid'] ?? '0')) ?>" required>
                                                <p class="form-hint">Max <?= h(formatHoursDecimal($hoursWorked)) ?> worked</p>
                                                <label class="form-label" for="sent_home_note_<?= (int) $row['attendance_id'] ?>">Reason</label>
                                                <input class="form-input" type="text" id="sent_home_note_<?= (int) $row['attendance_id'] ?>" name="hours_note"
                                                    value="<?= h((string) ($row['hours_note'] ?? '')) ?>"
                                                    placeholder="e.g. Sent home early — unwell">
                                                <button type="submit" class="btn btn--secondary btn--small">Save reduced hours</button>
                                            </form>
                                            <?php endif; ?>

                                            <?php if ($bibEnabled): ?>
                                            <form method="post" action="registration-bib-action.php" style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--border-color, #334155);">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                <input type="hidden" name="registration_id" value="<?= (int) $row['registration_id'] ?>">
                                                <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                                <input type="hidden" name="work_date" value="<?= h($workDate) ?>">
                                                <p class="form-hint" style="margin:0 0 0.5rem;"><strong>Bib number</strong></p>
                                                <label class="form-label" for="bib_<?= (int) $row['registration_id'] ?>">Assigned bib #</label>
                                                <input class="form-input" type="text" id="bib_<?= (int) $row['registration_id'] ?>" name="assigned_bib_number"
                                                    value="<?= h((string) ($row['assigned_bib_number'] ?? '')) ?>"
                                                    placeholder="e.g. 1601" maxlength="32">
                                                <button type="submit" class="btn btn--secondary btn--small">Save bib</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="data-table__foot">
                        <td colspan="<?= $bibEnabled ? 7 : 6 ?>"><strong>Day / filter total</strong></td>
                        <td><strong><?= h(formatHoursDecimal($totals['hours_worked'])) ?></strong></td>
                        <td><strong><?= h(formatHoursDecimal($totals['hours_paid'])) ?></strong></td>
                        <td colspan="<?= $canEdit ? 2 : 1 ?>"></td>
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
