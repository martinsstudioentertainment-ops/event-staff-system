<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/status-repository.php';
require_once __DIR__ . '/../includes/maps.php';
require_once __DIR__ . '/../includes/sensitive-data.php';
require_once __DIR__ . '/../includes/staff-blacklist.php';
require_once __DIR__ . '/../includes/staff-registration-schema.php';
require_once __DIR__ . '/../includes/platform/trust-scores.php';
require_once __DIR__ . '/../includes/feature-flags.php';
require_once __DIR__ . '/../includes/staff-allocation.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/admin-manual-signin.php';
require_once __DIR__ . '/../includes/staff-app-v3-data.php';
require_once __DIR__ . '/../includes/registration-bib.php';

requireAdminCapability('staff');

$id  = (int) ($_GET['id'] ?? 0);
$pdo = getDB();
ensureStaffRegistrationSaveSchema($pdo);
$row = $id > 0 ? getStaffRegistrationById($pdo, $id) : null;

if (!$row) {
    setAdminFlash('error', 'Registration not found.');
    header('Location: staff.php');
    exit;
}

$relatedRows        = getStaffRegistrationsByEmail($pdo, $row['email']);
$attendance         = $row['status'] === 'approved' ? getAttendanceByRegistration($pdo, (int) $row['id']) : null;
$currentShiftOutcome = null;
foreach ($relatedRows as $relatedShiftRow) {
    if ((int) ($relatedShiftRow['id'] ?? 0) === (int) $row['id']) {
        $currentShiftOutcome = resolveStaffShiftOutcomeMeta($relatedShiftRow);
        break;
    }
}
if ($currentShiftOutcome === null) {
    $currentShiftOutcome = resolveStaffShiftOutcomeMeta($row);
}
$statusToken = null;
$statusUrl   = '';
try {
    $statusToken = ensureStatusToken($pdo, (int) $row['id']);
    $statusUrl   = $statusToken ? getStatusUrl($statusToken, $pdo) : '';
} catch (Throwable $e) {
    error_log('[EventStaff] view-staff status token: ' . $e->getMessage());
}

$blacklistEntry     = null;
$consecutiveNoShows = 0;
try {
    $blacklistEntry     = getActiveBlacklistEntry($pdo, (string) $row['email']);
    $consecutiveNoShows = countConsecutiveNoShows($pdo, (string) $row['email']);
} catch (Throwable $e) {
    error_log('[EventStaff] view-staff blacklist: ' . $e->getMessage());
}
$flash              = getAdminFlash();
$trustScore         = null;
$staffIdForTrust    = (int) ($row['staff_id'] ?? 0);
if ($staffIdForTrust < 1) {
    $staffIdForTrust = (int) (ensureStaffRecordForEmail($pdo, (string) ($row['email'] ?? '')) ?? 0);
}
if ($staffIdForTrust > 0 && isFeatureEnabled($pdo, 'trust_scores')) {
    $trustScore = getStaffTrustScoreCached($pdo, $staffIdForTrust);
}

$assignmentHistory = getStaffAssignmentHistory(
    $pdo,
    $staffIdForTrust > 0 ? $staffIdForTrust : null,
    (string) ($row['email'] ?? ''),
    (int) $row['id']
);

try {
    $moveEventOptions = $pdo->query(
        "SELECT id, name, event_date FROM events WHERE is_active = 1 ORDER BY event_date ASC, name ASC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $moveEventOptions = [];
}

$allocationType = trim((string) ($row['allocation_type'] ?? 'standard'));
$canEditHours   = adminCan('attendance') && in_array(getAdminRole(), ['admin', 'manager'], true);
$bibEnabled     = registrationBibColumnEnabled($pdo);
if ($attendance) {
    $needsHoursInit = $attendance['hours_worked'] === null
        || ((float) ($attendance['hours_worked'] ?? 0) < 0.01
            && in_array((string) ($attendance['checked_in_method'] ?? ''), ['admin', 'admin_manual'], true));
    if ($needsHoursInit) {
        initializeWorkHoursForRegistration($pdo, (int) $row['id']);
        $attendance = getAttendanceByRegistration($pdo, (int) $row['id']);
    }
}

$shiftScheduledHours = 0.0;
if ($attendance) {
    $eventForHours = getEventById($pdo, (int) ($row['event_id'] ?? 0));
    if ($eventForHours !== null) {
        $shiftScheduledHours = suggestManualSigninHours($eventForHours);
    }
}
$hoursNeedCorrection = $attendance
    && (float) ($attendance['hours_worked'] ?? 0) < 0.01
    && (float) ($attendance['hours_paid'] ?? 0) < 0.01;

$pageTitle  = 'Staff Details';
$activePage = 'staff';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= h($row['first_name'] . ' ' . $row['surname']) ?></h2>
            <p class="card__subtitle">
                Registration #<?= (int) $row['id'] ?> —
                <span class="badge badge--<?= h($currentShiftOutcome['badge']) ?>"><?= h($currentShiftOutcome['label']) ?></span>
                <?php if ($trustScore !== null): ?>
                    <span class="badge badge--<?= h((string) ($trustScore['tier'] ?? 'bronze')) ?>" title="Trust score <?= (int) ($trustScore['score'] ?? 0) ?>"><?= h(trustScoreTierLabel((string) ($trustScore['tier'] ?? 'bronze'))) ?></span>
                <?php endif; ?>
                <?php if ($blacklistEntry): ?>
                    <span class="badge badge--blacklist">Blacklisted</span>
                <?php elseif ($consecutiveNoShows > 0): ?>
                    <span class="badge badge--pending"><?= (int) $consecutiveNoShows ?> consecutive no-show<?= $consecutiveNoShows === 1 ? '' : 's' ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="card__header-actions" style="display:flex;flex-wrap:wrap;gap:0.5rem;">
            <?php
            $editStaffId = (int) (ensureStaffRecordForEmail($pdo, (string) ($row['email'] ?? '')) ?? 0);
            if ($editStaffId > 0):
            ?>
                <a href="staff-edit.php?id=<?= $editStaffId ?>" class="btn btn--primary">Edit profile</a>
            <?php endif; ?>
            <a href="staff.php" class="btn btn--secondary">← Back to Staff List</a>
        </div>
    </div>

    <div class="detail-grid">
        <div class="detail-group">
            <h3 class="form-section-title">Personal Details</h3>
            <dl class="detail-list">
                <div class="detail-list__row"><dt>Surname</dt><dd><?= h($row['surname']) ?></dd></div>
                <div class="detail-list__row"><dt>First Name</dt><dd><?= h($row['first_name']) ?></dd></div>
                <div class="detail-list__row"><dt>Full Address</dt><dd><?= h($row['full_address']) ?></dd></div>
                <div class="detail-list__row"><dt>Eircode</dt><dd><?= h($row['eircode']) ?></dd></div>
                <?php
                $mapLink = buildGoogleMapsLink(
                    isset($row['location_lat']) ? (float) $row['location_lat'] : null,
                    isset($row['location_lng']) ? (float) $row['location_lng'] : null
                );
                if ($mapLink !== ''): ?>
                <div class="detail-list__row"><dt>Location</dt><dd><a href="<?= h($mapLink) ?>" target="_blank" rel="noopener">View on Google Maps ↗</a> (<?= h((string) $row['location_lat']) ?>, <?= h((string) $row['location_lng']) ?>)</dd></div>
                <?php endif; ?>
                <div class="detail-list__row"><dt>Date of Birth</dt><dd><?= h(formatSystemDate((string) ($row['date_of_birth'] ?? ''), $pdo)) ?></dd></div>
                <div class="detail-list__row"><dt>Gender</dt><dd><?= h(formatGenderLabel($row['gender'])) ?></dd></div>
            </dl>
        </div>

        <div class="detail-group">
            <h3 class="form-section-title">Contact &amp; Financial</h3>
            <dl class="detail-list">
                <div class="detail-list__row"><dt>Email</dt><dd><?= h($row['email']) ?></dd></div>
                <div class="detail-list__row"><dt>Mobile</dt><dd><?= h($row['mobile']) ?></dd></div>
                <div class="detail-list__row">
                    <dt>NI / PPS Number</dt>
                    <dd class="sensitive-field">
                        <span class="js-sensitive-value" data-full="<?= h($row['pps_number']) ?>"><?= h(maskPpsNumber((string) $row['pps_number'])) ?></span>
                        <button type="button" class="btn btn--small btn--secondary js-sensitive-reveal" aria-pressed="false">Reveal</button>
                    </dd>
                </div>
                <div class="detail-list__row">
                    <dt>Bank / IBAN</dt>
                    <dd class="sensitive-field">
                        <span class="js-sensitive-value" data-full="<?= h($row['bank_iban']) ?>"><?= h(maskBankIban((string) $row['bank_iban'])) ?></span>
                        <button type="button" class="btn btn--small btn--secondary js-sensitive-reveal" aria-pressed="false">Reveal</button>
                    </dd>
                </div>
                <p class="form-hint">Full IBAN is included in CSV export for company payments.</p>
            </dl>
        </div>

        <div class="detail-group detail-group--full">
            <h3 class="form-section-title">Selected shift</h3>
            <dl class="detail-list">
                <div class="detail-list__row"><dt>Role</dt><dd><?= h(formatRoleLabel($row['staff_role'])) ?></dd></div>
                <?php if ($bibEnabled): ?>
                <div class="detail-list__row">
                    <dt>Bib #</dt>
                    <dd>
                        <?= h(formatRegistrationBibDisplay($row['assigned_bib_number'] ?? null)) ?>
                        <?php if ($canEditHours): ?>
                        <form method="post" action="registration-bib-action.php" class="toolbar-inline-form" style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-end;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="redirect" value="view-staff.php?id=<?= (int) $row['id'] ?>">
                            <div>
                                <label class="form-label" for="profile_bib">Update bib</label>
                                <input class="form-input" type="text" id="profile_bib" name="assigned_bib_number"
                                    value="<?= h((string) ($row['assigned_bib_number'] ?? '')) ?>"
                                    placeholder="e.g. 1601" maxlength="32" style="min-width:8rem;">
                            </div>
                            <button type="submit" class="btn btn--small btn--secondary">Save bib</button>
                        </form>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php endif; ?>
                <div class="detail-list__row"><dt>Event</dt><dd><?= h(formatEventLabel($row)) ?></dd></div>
                <?php if ($allocationType !== '' && $allocationType !== 'standard'): ?>
                <div class="detail-list__row"><dt>Allocation</dt><dd><?= h(formatAllocationTypeLabel($allocationType)) ?></dd></div>
                <?php endif; ?>
                <?php if (!empty($row['override_reason'])): ?>
                <div class="detail-list__row"><dt>Override reason</dt><dd><?= h((string) $row['override_reason']) ?></dd></div>
                <?php endif; ?>
                <div class="detail-list__row"><dt>Registered</dt><dd><?= h(formatSystemDateTime((string) $row['created_at'], $pdo)) ?></dd></div>
                <div class="detail-list__row"><dt>Exported</dt><dd><?= $row['exported_at'] ? h(formatSystemDateTime((string) $row['exported_at'], $pdo)) : 'Not yet exported' ?></dd></div>
                <?php if ($row['status'] === 'approved'): ?>
                    <div class="detail-list__row">
                        <dt>Check-in</dt>
                        <dd>
                            <?php if ($attendance): ?>
                                Checked in <?= h(formatSystemDateTime((string) $attendance['checked_in_at'], $pdo)) ?>
                                (<?= h(match ($attendance['checked_in_method'] ?? 'self') {
                                    'admin', 'admin_manual' => 'Admin',
                                    'scan'  => 'Scan',
                                    default => 'Venue QR',
                                }) ?>)
                                <?php if (!empty($attendance['checked_out_at'])): ?>
                                    <br>Signed out <?= h(formatSystemDateTime((string) $attendance['checked_out_at'], $pdo)) ?>
                                    <?php if (!empty($attendance['signout_reason'])): ?>
                                        (<?= h(str_replace('_', ' ', (string) $attendance['signout_reason'])) ?>)
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                Not checked in yet
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if ($statusUrl !== ''): ?>
                    <div class="detail-list__row">
                        <dt>Status Link</dt>
                        <dd>
                            <div class="copy-field">
                                <input class="form-input copy-field__input" type="text" id="staff-status-url" readonly value="<?= h($statusUrl) ?>">
                                <button type="button" class="btn btn--small btn--secondary copy-field__btn" data-copy-target="staff-status-url">Copy</button>
                            </div>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if ($attendance): ?>
        <div class="detail-group detail-group--full">
            <h3 class="form-section-title">Shift hours &amp; sent home</h3>
            <dl class="detail-list">
                <div class="detail-list__row">
                    <dt>Hours worked</dt>
                    <dd><?= h(formatHoursDecimal((float) ($attendance['hours_worked'] ?? 0))) ?></dd>
                </div>
                <div class="detail-list__row">
                    <dt>Hours payable</dt>
                    <dd>
                        <strong><?= h(formatHoursDecimal((float) ($attendance['hours_paid'] ?? 0))) ?></strong>
                        <?php if ((float) ($attendance['hours_paid'] ?? 0) < (float) ($attendance['hours_worked'] ?? 0)): ?>
                            <span class="badge badge--pending">Adjusted</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if (!empty($attendance['work_end_at'])): ?>
                <div class="detail-list__row">
                    <dt>Work ended</dt>
                    <dd><?= h(formatSystemDateTime((string) $attendance['work_end_at'], $pdo)) ?></dd>
                </div>
                <?php endif; ?>
                <div class="detail-list__row">
                    <dt>Payroll note</dt>
                    <dd><?= trim((string) ($attendance['hours_note'] ?? '')) !== '' ? h((string) $attendance['hours_note']) : '—' ?></dd>
                </div>
                <?php if (!empty($attendance['hours_adjusted_at'])): ?>
                <div class="detail-list__row">
                    <dt>Last updated</dt>
                    <dd><?= h(formatSystemDateTime((string) $attendance['hours_adjusted_at'], $pdo)) ?></dd>
                </div>
                <?php endif; ?>
            </dl>

            <?php if ($canEditHours): ?>
            <?php if ($hoursNeedCorrection): ?>
            <p class="form-hint" style="margin-top:0.75rem;padding:0.65rem 0.85rem;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;">
                Hours show <strong>0.00</strong> because this person was signed in <em>after</em> the shift ended on the calendar
                (e.g. you used <strong>Sign in</strong> instead of <strong>Manual sign-in</strong> with hours).
                Enter the hours they worked below and save — up to <?= h(formatHoursDecimal($shiftScheduledHours)) ?> for this event.
            </p>
            <?php endif; ?>

            <form method="post" action="work-hours-action.php" class="form-grid" style="margin-top:1rem;max-width:28rem;">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="attendance_id" value="<?= (int) $attendance['id'] ?>">
                <input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="event_id" value="<?= (int) ($row['event_id'] ?? 0) ?>">
                <input type="hidden" name="hours_override" value="1">
                <input type="hidden" name="redirect" value="view-staff.php?id=<?= (int) $row['id'] ?>">

                <div class="form-group form-group--full">
                    <label class="form-label" for="shift_hours_paid">Payable hours</label>
                    <input class="form-input" type="number" step="0.25" min="0.25"
                        max="<?= h((string) max(0.25, $shiftScheduledHours)) ?>"
                        id="shift_hours_paid" name="hours_paid"
                        value="<?= h($hoursNeedCorrection && $shiftScheduledHours > 0
                            ? (string) $shiftScheduledHours
                            : (string) ($attendance['hours_paid'] ?? '0')) ?>"
                        required>
                    <p class="form-hint">Full shift for this event: <?= h(formatHoursDecimal($shiftScheduledHours)) ?>. Use after missed bulk sign-in or late admin sign-in.</p>
                </div>
                <div class="form-group form-group--full">
                    <label class="form-label" for="shift_hours_note">Note (optional)</label>
                    <input class="form-input" type="text" id="shift_hours_note" name="hours_note"
                        value="<?= h((string) ($attendance['hours_note'] ?? '')) ?>"
                        placeholder="e.g. Manual sign-in missed — worked full shift">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Save shift hours</button>
                    <a href="work-hours.php?event_id=<?= (int) ($row['event_id'] ?? 0) ?>" class="btn btn--secondary">All work hours</a>
                </div>
            </form>

            <?php if ((float) ($attendance['hours_worked'] ?? 0) > 0): ?>
            <details style="margin-top:1rem;max-width:28rem;">
                <summary class="btn btn--small btn--secondary" style="cursor:pointer;">Sent home early (reduce hours)</summary>
                <form method="post" action="work-hours-action.php" class="form-grid" style="margin-top:0.75rem;">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="attendance_id" value="<?= (int) $attendance['id'] ?>">
                    <input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="event_id" value="<?= (int) ($row['event_id'] ?? 0) ?>">
                    <input type="hidden" name="sent_home" value="1">
                    <input type="hidden" name="redirect" value="view-staff.php?id=<?= (int) $row['id'] ?>">
                    <div class="form-group form-group--full">
                        <label class="form-label" for="sent_home_hours">Payable hours (less than worked)</label>
                        <input class="form-input" type="number" step="0.25" min="0"
                            max="<?= h((string) ($attendance['hours_worked'] ?? 0)) ?>"
                            id="sent_home_hours" name="hours_paid"
                            value="<?= h((string) ($attendance['hours_paid'] ?? '0')) ?>" required>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label" for="sent_home_note_custom">Reason</label>
                        <input class="form-input" type="text" id="sent_home_note_custom" name="hours_note"
                            value="<?= h((string) ($attendance['hours_note'] ?? '')) ?>"
                            placeholder="e.g. Sent home early — unwell">
                    </div>
                    <button type="submit" class="btn btn--secondary btn--small">Save sent home</button>
                </form>
            </details>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="toolbar">
        <?php
        $msgStaffId = (int) (ensureStaffRecordForEmail($pdo, (string) ($row['email'] ?? '')) ?? 0);
        if ($msgStaffId > 0):
        ?>
            <a href="staff-inbox-thread.php?staff_id=<?= $msgStaffId ?>" class="btn btn--secondary">Message</a>
        <?php endif; ?>
        <?php if ($row['status'] === 'approved'): ?>
            <a href="qr.php?id=<?= (int) $row['id'] ?>" class="btn btn--secondary">QR Check-in</a>
        <?php endif; ?>
        <?php if (in_array($row['status'], ['pending', 'approved'], true)): ?>
            <form method="post" action="send-shift-reminder-action.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="registration">
                <input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="redirect" value="view-staff.php?id=<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--secondary">Send shift reminder</button>
            </form>
        <?php endif; ?>
        <?php if ($row['status'] !== 'approved'): ?>
            <form method="post" action="update-status.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="approved">
                <input type="hidden" name="redirect" value="view-staff.php?id=<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--success">Approve</button>
            </form>
        <?php endif; ?>
        <?php if ($row['status'] !== 'rejected'): ?>
            <form method="post" action="update-status.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="rejected">
                <input type="hidden" name="redirect" value="view-staff.php?id=<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--danger">Reject</button>
            </form>
        <?php endif; ?>
        <?php if ($blacklistEntry): ?>
            <form method="post" action="blacklist-action.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="email" value="<?= h((string) $row['email']) ?>">
                <input type="hidden" name="return_id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--secondary">Remove from blacklist</button>
            </form>
        <?php else: ?>
            <form method="post" action="blacklist-action.php" onsubmit="return confirm('Block this person from registering?');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="email" value="<?= h((string) $row['email']) ?>">
                <input type="hidden" name="return_id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="btn btn--danger">Add to blacklist</button>
            </form>
        <?php endif; ?>
        <a href="allocation-centre.php" class="btn btn--secondary">Allocation centre</a>
    </div>

    <?php if (in_array($row['status'], ['pending', 'approved'], true)): ?>
    <div class="card erp-card" style="margin-top:1rem;">
        <h3 class="form-section-title">Admin shift override</h3>
        <div class="detail-grid">
            <form method="post" action="allocation-action.php" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>">
                <h4>Move to another shift</h4>
                <select name="event_id" class="input" required>
                    <option value="">Choose event…</option>
                    <?php foreach ($moveEventOptions as $opt): ?>
                        <?php if ((int) ($opt['id'] ?? 0) === (int) ($row['event_id'] ?? 0)) { continue; } ?>
                        <option value="<?= (int) ($opt['id'] ?? 0) ?>">
                            <?= h((string) ($opt['name'] ?? '')) ?> — <?= h(formatSystemDate((string) ($opt['event_date'] ?? ''), $pdo)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <textarea name="reason" class="input" rows="2" required placeholder="Reason for move (audit log)"></textarea>
                <label class="checkbox-label"><input type="checkbox" name="confirm_duplicate" value="1"> Confirm duplicate on target event</label>
                <label class="checkbox-label"><input type="checkbox" name="confirm_same_day" value="1"> Confirm same-day override</label>
                <button type="submit" class="btn btn--secondary">Move assignment</button>
            </form>
            <form method="post" action="allocation-action.php" class="form-stack" onsubmit="return confirm('Remove this assignment? Registration will be rejected.');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>">
                <h4>Remove assignment</h4>
                <textarea name="reason" class="input" rows="2" required placeholder="Reason for removal (audit log)"></textarea>
                <button type="submit" class="btn btn--danger">Remove from shift</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</section>

<?php if ($relatedRows !== []): ?>
<section class="card">
    <div class="card__header">
        <h2 class="card__title">Shifts &amp; events</h2>
        <p class="card__subtitle"><?= count($relatedRows) ?> shift<?= count($relatedRows) === 1 ? '' : 's' ?> for <?= h($row['email']) ?></p>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($relatedRows as $related): ?>
                    <tr<?= (int) $related['id'] === (int) $row['id'] ? ' class="data-table__highlight"' : '' ?>>
                        <td><?= h(formatEventLabel($related)) ?></td>
                        <td><?= h(formatRoleLabel($related['staff_role'])) ?></td>
                        <?php $shiftOutcome = resolveStaffShiftOutcomeMeta($related); ?>
                        <td><span class="badge badge--<?= h($shiftOutcome['badge']) ?>" title="<?= h(formatStatusLabel($related['status'])) ?>"><?= h($shiftOutcome['label']) ?></span></td>
                        <td><?= h(formatSystemDateTime((string) $related['created_at'], $pdo)) ?></td>
                        <td>
                            <?php if ((int) $related['id'] !== (int) $row['id']): ?>
                                <a href="view-staff.php?id=<?= (int) $related['id'] ?>" class="btn btn--small btn--secondary">View</a>
                            <?php else: ?>
                                <span class="detail-list__current">Current</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($assignmentHistory !== []): ?>
<section class="card">
    <div class="card__header">
        <h2 class="card__title">Assignment history</h2>
        <p class="card__subtitle">Admin allocation and override audit trail</p>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Action</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Admin</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignmentHistory as $log): ?>
                    <tr>
                        <td><?= h(formatSystemDateTime((string) ($log['created_at'] ?? ''), $pdo)) ?></td>
                        <td><?= h(str_replace('_', ' ', (string) ($log['action'] ?? ''))) ?></td>
                        <td><?= h((string) ($log['from_event_name'] ?? '—')) ?></td>
                        <td><?= h((string) ($log['to_event_name'] ?? '—')) ?></td>
                        <td><?= h((string) ($log['admin_username'] ?? '')) ?></td>
                        <td><?= h((string) ($log['reason'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
