<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/admin-manual-signin.php';
require_once __DIR__ . '/../includes/attendance-gps-phase1.php';
require_once __DIR__ . '/../includes/registration-bib.php';

requireAdminCapability('attendance');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can record manual sign-ins.');
    header('Location: attendance.php');
    exit;
}

$pdo     = getDB();
$eventId = (int) ($_GET['event_id'] ?? 0);
$sort    = strtolower(trim((string) ($_GET['sort'] ?? 'az')));
if (!in_array($sort, ['az', 'za'], true)) {
    $sort = 'az';
}
$events  = getEventsForFilter($pdo);
$flash   = getAdminFlash();

$selectedEvent = $eventId > 0 ? getEventById($pdo, $eventId) : null;
$missing       = $eventId > 0 ? getApprovedStaffMissingCheckin($pdo, $eventId) : [];

if ($missing !== []) {
    usort(
        $missing,
        static function (array $a, array $b) use ($sort): int {
            $nameA = strtolower(trim((string) ($a['first_name'] ?? '') . ' ' . (string) ($a['surname'] ?? '')));
            $nameB = strtolower(trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['surname'] ?? '')));
            $cmp   = strnatcasecmp($nameA, $nameB);

            return $sort === 'za' ? -$cmp : $cmp;
        }
    );
}
$defaultHours  = $selectedEvent ? suggestManualSigninHours($selectedEvent) : 8.0;
$gpsOn         = isGpsAttendanceV2Enabled($pdo);
$bibEnabled    = registrationBibColumnEnabled($pdo);

$pageTitle  = 'Manual sign-in';
$activePage = 'attendance';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Manual sign-in &amp; hours</h2>
            <p class="card__subtitle">
                For staff who <strong>worked the shift</strong> but could not complete venue QR sign-in.
                Records attendance and payable hours in one step — works even when GPS is ON or the check-in window is closed.
            </p>
        </div>
        <div class="toolbar toolbar--compact">
            <a href="attendance.php<?= $eventId > 0 ? '?event_id=' . (int) $eventId : '' ?>" class="btn btn--secondary">← Attendance</a>
            <?php if ($eventId > 0): ?>
                <a href="work-hours.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">Work hours</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($gpsOn): ?>
        <div class="alert alert--info alert--visible">
            GPS sign-in is <strong>ON</strong> for staff self check-in. As admin you can still use
            <strong>Attendance → Check In</strong>, <strong>Scan QR</strong>, or this page to override.
        </div>
    <?php endif; ?>

    <form method="get" class="filter-bar filter-bar--attendance">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <div class="filter-bar__group">
            <label class="form-label" for="manual-event">Event</label>
            <select class="form-select" id="manual-event" name="event_id" required>
                <option value="">Select event…</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>"<?= $eventId === (int) $event['id'] ? ' selected' : '' ?>>
                        <?= h($event['name'] . ' — ' . formatEventDateLabel((string) $event['event_date'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Load staff</button>
        </div>
    </form>

    <?php if ($selectedEvent): ?>
        <p class="form-hint">
            <?= h($selectedEvent['name']) ?> · <?= h(formatEventDateLabel((string) $selectedEvent['event_date'])) ?>
            · <?= h(formatEventTimeRangeLabel($selectedEvent)) ?>
            · Suggested full shift: <strong><?= h(formatHoursDecimal($defaultHours)) ?></strong>
        </p>
    <?php endif; ?>
</section>

<?php if ($eventId > 0): ?>
<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff not signed in (<?= count($missing) ?>)</h2>
            <p class="card__subtitle">Enter payable hours and bib numbers for each person who worked, then sign them in.</p>
        </div>
        <div class="toolbar toolbar--compact">
            <a
                href="manual-signin.php?event_id=<?= (int) $eventId ?>&amp;sort=az"
                class="btn btn--small<?= $sort === 'az' ? ' btn--primary' : ' btn--secondary' ?>"
            >A → Z</a>
            <a
                href="manual-signin.php?event_id=<?= (int) $eventId ?>&amp;sort=za"
                class="btn btn--small<?= $sort === 'za' ? ' btn--primary' : ' btn--secondary' ?>"
            >Z → A</a>
            <button type="button" class="btn btn--secondary btn--small" id="manual-signin-select-all">Select all</button>
            <button type="button" class="btn btn--secondary btn--small" id="manual-signin-deselect-all">Deselect all</button>
        </div>
    </div>

    <?php if ($missing === []): ?>
        <p class="data-table__empty" style="padding:1.5rem;">Everyone approved for this event is already signed in.</p>
    <?php else: ?>
        <form method="post" action="manual-signin-action.php" id="manual-signin-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">

            <div class="form-group form-group--full" style="margin-bottom:1rem;">
                <label class="form-label" for="bulk_note">Note for all (optional)</label>
                <input class="form-input" type="text" id="bulk_note" name="hours_note" placeholder="e.g. Nick Cave — venue QR sign-in failed, worked full shift">
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Email</th>
                            <?php if ($bibEnabled): ?><th style="width:120px">Bib #</th><?php endif; ?>
                            <th style="width:140px">Hours worked</th>
                            <th style="width:100px">
                                <label class="form-check" style="margin:0;white-space:nowrap;">
                                    <input type="checkbox" id="manual-signin-select-all-head" checked aria-label="Select all staff">
                                    <span>Sign in</span>
                                </label>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($missing as $row): ?>
                            <?php $rid = (int) $row['id']; ?>
                            <tr>
                                <td>
                                    <a href="view-staff.php?id=<?= $rid ?>"><?= h(trim($row['first_name'] . ' ' . $row['surname'])) ?></a>
                                </td>
                                <td><?= h(formatRoleLabel((string) $row['staff_role'])) ?></td>
                                <td><?= h((string) $row['email']) ?></td>
                                <?php if ($bibEnabled): ?>
                                <td>
                                    <input
                                        class="form-input"
                                        type="text"
                                        name="bib[<?= $rid ?>]"
                                        maxlength="20"
                                        placeholder="Bib #"
                                        value="<?= h(trim((string) ($row['assigned_bib_number'] ?? ''))) ?>"
                                        aria-label="Bib number for <?= h($row['first_name'] . ' ' . $row['surname']) ?>"
                                    >
                                </td>
                                <?php endif; ?>
                                <td>
                                    <input
                                        class="form-input"
                                        type="number"
                                        name="hours[<?= $rid ?>]"
                                        step="0.25"
                                        min="0"
                                        max="24"
                                        value="<?= h((string) $defaultHours) ?>"
                                        aria-label="Hours for <?= h($row['first_name'] . ' ' . $row['surname']) ?>"
                                    >
                                </td>
                                <td>
                                    <label class="form-check" style="margin:0;">
                                        <input type="checkbox" class="manual-signin-row-check" name="selected[]" value="<?= $rid ?>" checked>
                                        <span>Yes</span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions" style="margin-top:1rem;">
                <button type="submit" class="btn btn--primary">Sign in selected staff with hours</button>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($eventId > 0 && $missing !== []): ?>
<script>
(function () {
    var form = document.getElementById('manual-signin-form');
    if (!form) {
        return;
    }
    var rowChecks = form.querySelectorAll('.manual-signin-row-check');
    var headCheck = document.getElementById('manual-signin-select-all-head');
    var selectAllBtn = document.getElementById('manual-signin-select-all');
    var deselectAllBtn = document.getElementById('manual-signin-deselect-all');

    function setAll(checked) {
        rowChecks.forEach(function (cb) {
            cb.checked = checked;
        });
        if (headCheck) {
            headCheck.checked = checked;
            headCheck.indeterminate = false;
        }
    }

    function syncHead() {
        if (!headCheck) {
            return;
        }
        var total = rowChecks.length;
        var checked = 0;
        rowChecks.forEach(function (cb) {
            if (cb.checked) {
                checked++;
            }
        });
        headCheck.checked = total > 0 && checked === total;
        headCheck.indeterminate = checked > 0 && checked < total;
    }

    if (headCheck) {
        headCheck.addEventListener('change', function () {
            setAll(headCheck.checked);
        });
    }
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
            setAll(true);
        });
    }
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function () {
            setAll(false);
        });
    }
    rowChecks.forEach(function (cb) {
        cb.addEventListener('change', syncHead);
    });
    syncHead();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
