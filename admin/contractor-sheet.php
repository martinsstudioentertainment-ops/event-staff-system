<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/friendly-response.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/attendance-roster-helpers.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';
require_once __DIR__ . '/../includes/registration-bib.php';
require_once __DIR__ . '/../includes/event-signin-export.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

try {
    requireAdminCapability('export');

    $pdo       = getDB();
    $eventId   = (int) ($_GET['event_id'] ?? 0);
    $download  = isset($_GET['download']) && (string) $_GET['download'] === '1';
    $format    = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
    if (!in_array($format, ['xlsx', 'csv'], true)) {
        $format = 'xlsx';
    }

    if ($download && $eventId > 0) {
        $event = getEventById($pdo, $eventId);
        if ($event === null) {
            setAdminFlash('error', 'Event not found.');
            header('Location: contractor-sheet.php');
            exit;
        }

        $rows = getContractorSheetSignInRows($pdo, $eventId);

        logAdminAudit(
            $pdo,
            'export_contractor_sheet',
            'event',
            $eventId,
            count($rows) . ' signed-in rows (' . $format . ')'
        );

        sendContractorSheetDownload($pdo, $eventId, $rows, $format);
        exit;
    }

    $events           = getEventsForAttendanceFilter($pdo);
    $signedInList     = $eventId > 0 ? getContractorSheetRosterRows($pdo, $eventId) : [];
    $downloadCount    = $eventId > 0 ? count(getContractorSheetSignInRows($pdo, $eventId)) : 0;
    $selectedEvent    = $eventId > 0 ? getEventById($pdo, $eventId) : null;
    $bibEnabled       = registrationBibColumnEnabled($pdo);
    $canEdit          = adminCan('attendance') && in_array(getAdminRole(), ['admin', 'manager'], true);
    $flash            = getAdminFlash();

    $pageTitle          = 'Contractor sheet';
    $activePage         = 'contractor-sheet';
    $adminSectionNav    = getAdminExportNavItems();
    $adminSectionActive = 'contractor-sheet';
} catch (Throwable $e) {
    friendlyLogError('contractor-sheet', $e);
    renderFriendlyHtmlPage(
        'Contractor sheet unavailable',
        'We could not load contractor sheet right now. Please return to Attendance and try again.',
        200,
        [['label' => 'Open Attendance', 'href' => 'attendance.php']]
    );
}

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Contractor sheet</h2>
        <p class="card__subtitle">
            Choose an event to review signed-in staff and download the contractor sheet. Use <strong>Edit</strong> on each row to fix payable hours or bib numbers before downloading.
        </p>
        <p class="form-hint" style="margin-top:0.5rem;">
            Download includes staff with payable hours only. Rows with 0 hours stay on this list so you can fix them here or on <a href="work-hours.php<?= $eventId > 0 ? '?event_id=' . (int) $eventId : '' ?>">Work hours</a>.
        </p>
    </div>

    <form method="get" action="contractor-sheet.php" class="filter-bar">
        <div class="filter-bar__group">
            <select class="form-select" name="event_id" required>
                <option value="">Select event…</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>"<?= $eventId === (int) $event['id'] ? ' selected' : '' ?>>
                        <?= h(formatEventFilterOptionLabel($event)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Show signed-in staff</button>
            <?php if ($eventId > 0): ?>
                <a href="contractor-sheet.php" class="btn btn--secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($eventId > 0): ?>
        <div class="toolbar toolbar--compact" style="margin-top:1rem;">
            <span class="badge badge--approved"><?= count($signedInList) ?> checked in</span>
            <span class="badge badge--pending"><?= (int) $downloadCount ?> on download</span>
            <?php if ($selectedEvent): ?>
                <span class="form-hint"><?= h(formatEventLabel($selectedEvent)) ?></span>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <a href="work-hours.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary btn--small">Work hours</a>
                <a href="manual-signin.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary btn--small">Manual sign-in</a>
            <?php endif; ?>
            <a href="contractor-sheet.php?event_id=<?= (int) $eventId ?>&amp;download=1&amp;format=xlsx" class="btn btn--primary btn--small">Download Excel</a>
            <a href="contractor-sheet.php?event_id=<?= (int) $eventId ?>&amp;download=1&amp;format=csv" class="btn btn--secondary btn--small">Download CSV</a>
        </div>
    <?php endif; ?>
</section>

<?php if ($eventId > 0): ?>
<section class="card">
    <div class="card__header">
        <h2 class="card__title">Signed-in staff</h2>
        <p class="card__subtitle">
            Numbered list in <strong>A–Z order by first name</strong> (same order in Excel/CSV download).
            <?php if ($canEdit): ?>
                Click <strong>Edit</strong> to change payable hours or bib number.
            <?php endif; ?>
        </p>
    </div>

    <div class="data-table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <?php if ($bibEnabled): ?><th>Bib #</th><?php endif; ?>
                    <th>Role</th>
                    <th>Sign-in type</th>
                    <th>Sign-in time</th>
                    <th>Hours paid</th>
                    <?php if ($canEdit): ?><th>Edit</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $tableCols = 6 + ($bibEnabled ? 1 : 0) + ($canEdit ? 1 : 0);
                $rowNumber = 0;
                ?>
                <?php if ($signedInList === []): ?>
                    <tr>
                        <td colspan="<?= $tableCols ?>" class="data-table__empty">No active sign-ins found for this event.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($signedInList as $row): ?>
                        <?php
                        $rowNumber++;
                        $checkInAt = trim((string) ($row['export_checked_in_at'] ?? $row['checked_in_at'] ?? ''));
                        $hoursPaid = (float) ($row['hours_paid'] ?? 0);
                        $hoursWorked = (float) ($row['hours_worked'] ?? 0);
                        $rowScheduled = (float) ($row['scheduled_hours'] ?? 0);
                        if ($rowScheduled <= 0) {
                            $rowScheduled = resolveEventScheduledHoursFromRow($row);
                        }
                        $attendanceId = (int) ($row['attendance_id'] ?? 0);
                        $registrationId = (int) ($row['registration_id'] ?? $row['id'] ?? 0);
                        ?>
                        <tr>
                            <td data-label="#" class="contractor-sheet__num"><?= (int) $rowNumber ?></td>
                            <td data-label="Name">
                                <?php if ($registrationId > 0): ?>
                                    <a href="view-staff.php?id=<?= $registrationId ?>"><?= h(trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''))) ?></a>
                                <?php else: ?>
                                    <?= h(trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''))) ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($bibEnabled): ?>
                                <td data-label="Bib #">
                                    <?php if ($canEdit && $registrationId > 0): ?>
                                        <form method="post" action="contractor-sheet-save-action.php" class="toolbar-inline-form" style="display:flex;gap:0.35rem;align-items:center;flex-wrap:wrap;">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="registration_id" value="<?= $registrationId ?>">
                                            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                            <input type="hidden" name="attendance_id" value="<?= $attendanceId ?>">
                                            <input type="hidden" name="hours_paid" value="<?= h($hoursPaid > 0 ? (string) $hoursPaid : (string) $rowScheduled) ?>">
                                            <input type="hidden" name="hours_note" value="<?= h((string) ($row['hours_note'] ?? '')) ?>">
                                            <input class="form-input" type="text" name="assigned_bib_number" style="min-width:6rem;max-width:9rem;"
                                                value="<?= h((string) ($row['assigned_bib_number'] ?? '')) ?>"
                                                placeholder="Enter bib #" maxlength="32" aria-label="Bib number">
                                            <button type="submit" class="btn btn--small btn--secondary">Save</button>
                                        </form>
                                    <?php else: ?>
                                        <?= h((string) ($row['assigned_bib_number'] ?? '—')) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td data-label="Role"><?= h(formatRoleLabel((string) ($row['staff_role'] ?? ''))) ?></td>
                            <td data-label="Sign-in type"><?= h(resolveContractorSheetSignInType($row)) ?></td>
                            <td data-label="Sign-in time"><?= $checkInAt !== '' ? h(formatSystemDateTime($checkInAt, $pdo)) : '—' ?></td>
                            <td data-label="Hours paid">
                                <?php if ($hoursPaid > 0): ?>
                                    <?= h((string) $row['hours_paid']) ?>
                                <?php else: ?>
                                    <span class="badge badge--pending">0 — fix hours</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canEdit): ?>
                                <td data-label="Edit">
                                    <?php if ($attendanceId < 1): ?>
                                        <a href="manual-signin.php?event_id=<?= (int) $eventId ?>" class="btn btn--small btn--secondary">Manual sign-in</a>
                                    <?php else: ?>
                                        <details class="work-hours-edit">
                                            <summary class="btn btn--small btn--secondary">Edit</summary>
                                            <div class="work-hours-edit__form">
                                                <form method="post" action="contractor-sheet-save-action.php">
                                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                                    <input type="hidden" name="attendance_id" value="<?= $attendanceId ?>">
                                                    <input type="hidden" name="registration_id" value="<?= $registrationId ?>">
                                                    <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                                    <input type="hidden" name="save_hours" value="1">
                                                    <p class="form-hint" style="margin:0 0 0.5rem;"><strong>Payable hours &amp; bib</strong></p>
                                                    <label class="form-label" for="contractor_hours_<?= $attendanceId ?>">Hours paid</label>
                                                    <input class="form-input" type="number" step="0.25" min="0.25"
                                                        max="<?= h((string) max(0.25, $rowScheduled)) ?>"
                                                        id="contractor_hours_<?= $attendanceId ?>" name="hours_paid"
                                                        value="<?= h($hoursPaid > 0 ? (string) $hoursPaid : (string) $rowScheduled) ?>" required>
                                                    <p class="form-hint">Scheduled shift: <?= h(formatHoursDecimal($rowScheduled)) ?></p>
                                                    <label class="form-label" for="contractor_note_<?= $attendanceId ?>">Note</label>
                                                    <input class="form-input" type="text" id="contractor_note_<?= $attendanceId ?>" name="hours_note"
                                                        value="<?= h((string) ($row['hours_note'] ?? '')) ?>"
                                                        placeholder="e.g. Worked full shift — manual correction">
                                                    <?php if ($bibEnabled && $registrationId > 0): ?>
                                                    <label class="form-label" for="contractor_bib_<?= $registrationId ?>">Bib #</label>
                                                    <input class="form-input" type="text" id="contractor_bib_<?= $registrationId ?>" name="assigned_bib_number"
                                                        value="<?= h((string) ($row['assigned_bib_number'] ?? '')) ?>"
                                                        placeholder="e.g. 1601" maxlength="32">
                                                    <?php endif; ?>
                                                    <button type="submit" class="btn btn--primary btn--small">Save hours &amp; bib</button>
                                                </form>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
