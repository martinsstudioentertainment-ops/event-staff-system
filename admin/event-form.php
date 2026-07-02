<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/work-types-repository.php';
require_once __DIR__ . '/../includes/maps.php';
require_once __DIR__ . '/../includes/attendance-gps-phase1.php';
require_once __DIR__ . '/../includes/feature-flags.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/event-complete-purge.php';

requireAdminCapability('events');

$pdo   = getDB();
$id    = (int) ($_GET['id'] ?? 0);
$event = $id > 0 ? getEventById($pdo, $id) : null;
$venues = getAllVenues($pdo, true);

if ($id > 0 && !$event) {
    setAdminFlash('error', 'Event not found.');
    header('Location: events.php');
    exit;
}

$isEdit = $event !== null;
$errors = $_SESSION['event_form_errors'] ?? [];
$old    = $_SESSION['event_form_old'] ?? [];
unset($_SESSION['event_form_errors'], $_SESSION['event_form_old']);

$mapsKey = getGoogleMapsApiKey($pdo);
$mapsOn  = googleMapsEnabled($pdo);
$gpsV2On = isGpsAttendanceV2Enabled($pdo);
$signinRadiusValue = $isEdit && isset($event['signin_radius_m']) && (int) $event['signin_radius_m'] > 0
    ? (int) $event['signin_radius_m']
    : ($gpsV2On ? EVENT_SIGNIN_RADIUS_DEFAULT_M : EVENT_SIGNIN_RADIUS_LEGACY_M);
if (isset($old['signin_radius_m']) && trim((string) $old['signin_radius_m']) !== '') {
    $signinRadiusValue = (int) $old['signin_radius_m'];
}
$radiusLabel = $gpsV2On
    ? formatEventSigninRadiusLabel(['signin_radius_m' => $signinRadiusValue], $pdo)
    : EVENT_SIGNIN_RADIUS_LEGACY_M . ' m';
$saEmail          = getGoogleServiceAccountClientEmail();
$canAutoSheet     = isGoogleSheetsAutoCreateReady($pdo);
$syncEnabled      = isGoogleSheetsSyncEnabled($pdo);

function eventOld(array $old, ?array $event, string $key, string $default = ''): string
{
    if (isset($old[$key])) {
        return h((string) $old[$key]);
    }
    if ($event && isset($event[$key])) {
        if ($key === 'event_date') {
            return h((string) $event['event_date']);
        }
        if (in_array($key, ['start_time', 'end_time', 'checkin_open_time', 'checkin_close_time'], true)) {
            $raw = (string) $event[$key];
            return $raw !== '' ? h(substr($raw, 0, 5)) : h($default);
        }
        return h((string) $event[$key]);
    }
    return h($default);
}

$pageTitle  = $isEdit ? 'Edit Event' : 'Add Event';
$activePage = 'events';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= $isEdit ? 'Edit Event' : 'Add New Event' ?></h2>
            <p class="card__subtitle">Venue Eircode and GPS are required — venue sign-in works within <?= h($radiusLabel) ?>.</p>
        </div>
        <a href="events.php" class="btn btn--secondary">← Back to Events</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert--error alert--visible">
            <?php foreach ($errors as $msg): ?>
                <div><?= h($msg) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="save-event.php" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">
        <?php endif; ?>

        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="name">Event Name</label>
            <input class="form-input" type="text" id="name" name="name" value="<?= eventOld($old, $event, 'name') ?>" placeholder="e.g. Metallica" required>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="main_security_company">Listed contractor (optional)</label>
            <input class="form-input" type="text" id="main_security_company" name="main_security_company" value="<?= eventOld($old, $event, 'main_security_company', '') ?>" placeholder="Leave blank unless you must show a third-party name">
            <p class="form-hint">Third-party information only — e.g. who provides security on site. Leave empty if unsure. Emails and the portal always send from <strong>your</strong> company name in Settings, never from this field.</p>
        </div>

        <div class="form-group">
            <label class="form-label form-label--required" for="work_type">Work type</label>
            <select class="form-select" id="work_type" name="work_type" required>
                <?php
                $selectedWorkType = (string) ($old['work_type'] ?? $event['work_type'] ?? 'special_event');
                foreach (getWorkTypeOptionsForSelect($pdo, $selectedWorkType) as $value => $label):
                ?>
                    <option value="<?= h($value) ?>"<?= $selectedWorkType === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint"><a href="work-types.php">Manage work types</a> — add your own, then enable them on <a href="forms.php">Registration forms</a> so the right staff see each shift.</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="venue_id">Linked venue</label>
            <select class="form-select" id="venue_id" name="venue_id">
                <option value="">— Not linked —</option>
                <?php
                $selectedVenueId = (string) ($old['venue_id'] ?? $event['venue_id'] ?? '');
                foreach ($venues as $venueRow):
                ?>
                    <option value="<?= (int) $venueRow['id'] ?>"<?= $selectedVenueId === (string) $venueRow['id'] ? ' selected' : '' ?>>
                        <?= h($venueRow['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint"><a href="venues.php">Manage venues</a> — pick a linked venue to copy its name, Eircode and GPS to this event. Editing a venue in Venues updates all linked events.</p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label form-label--required">Roles that can register</label>
            <div class="form-checkbox-group">
                <?php
                $savedRoles = normalizeRolesNeeded(['roles_needed' => $old['roles_needed'] ?? $event['roles_needed'] ?? 'dsp,static']);
                foreach (getStaffRoleValuesForEvents() as $role):
                ?>
                    <label class="form-checkbox">
                        <input type="checkbox" name="roles_needed[]" value="<?= h($role) ?>"<?= in_array($role, $savedRoles, true) ? ' checked' : '' ?>>
                        <?= h(formatStaffRoleLabel($role)) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label form-label--required" for="event_date">Event Date</label>
            <input class="form-input" type="date" id="event_date" name="event_date" value="<?= eventOld($old, $event, 'event_date') ?>" required>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="location">Venue name</label>
            <input class="form-input" type="text" id="location" name="location" value="<?= eventOld($old, $event, 'location') ?>" placeholder="e.g. Malahide Castle">
            <p class="form-hint">Short venue label only — do not paste a full address here. Use <strong>Venue Eircode</strong> and the map below for GPS sign-in.</p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="reporting_point">Reporting point / gate</label>
            <input class="form-input" type="text" id="reporting_point" name="reporting_point" value="<?= eventOld($old, $event, 'reporting_point') ?>" placeholder="e.g. Gate 08 — staff entrance, north side">
            <p class="form-hint">Optional info for staff (who provides security on site). Emails are always sent from your company in Settings — not from this name.</p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="whatsapp_group_url">WhatsApp group invite link</label>
            <input class="form-input" type="url" id="whatsapp_group_url" name="whatsapp_group_url" value="<?= eventOld($old, $event, 'whatsapp_group_url') ?>" placeholder="https://chat.whatsapp.com/…">
            <p class="form-hint">Required for per-event staff app links. Open the event group in WhatsApp → Group info → Invite via link, then paste here (must start with <code>https://chat.whatsapp.com/</code>). Each event gets its own button in the staff app — not the company-wide group from Settings.</p>
            <?php if ($isEdit && trim((string) ($event['whatsapp_group_url'] ?? '')) !== ''): ?>
                <p class="form-hint" style="margin-top:0.5rem">
                    <strong>Linked.</strong>
                    <a href="<?= h((string) $event['whatsapp_group_url']) ?>" target="_blank" rel="noopener noreferrer">Open group invite ↗</a>
                </p>
            <?php endif; ?>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="google_sheet_url">Google Sheet URL</label>
            <input class="form-input" type="url" id="google_sheet_url" name="google_sheet_url" value="<?= eventOld($old, $event, 'google_sheet_url') ?>" placeholder="https://docs.google.com/spreadsheets/d/…/edit">
            <?php if ($saEmail !== ''): ?>
                <p class="form-hint">
                    <strong>Manual link (no Gmail Connect):</strong> create a sheet in your Drive → Share with
                    <code style="word-break:break-all"><?= h($saEmail) ?></code> as <strong>Editor</strong> → paste the URL here → Save.
                    <?php if (!$syncEnabled): ?>
                        Also enable <strong>live sync</strong> in <a href="settings-production.php#google-sheets-manual">Settings → Google Sheets</a>.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="form-hint">
                    Upload the service account JSON in <a href="settings-production.php#google-sheets-manual">Settings → Google Sheets</a> first, then share each sheet with the robot email shown there.
                </p>
            <?php endif; ?>
            <?php if ($isEdit && trim((string) ($event['google_sheet_url'] ?? '')) !== ''): ?>
                <p class="form-hint" style="margin-top:0.5rem">
                    <strong>Linked.</strong>
                    <a href="<?= h((string) $event['google_sheet_url']) ?>" target="_blank" rel="noopener">Open sheet ↗</a>
                </p>
                <button
                    type="submit"
                    class="btn btn--small btn--secondary"
                    style="margin-top:0.5rem;"
                    formaction="events-sheets-action.php"
                    formmethod="post"
                    name="action"
                    value="unlink_one"
                    onclick="return confirm('Unlink this event from the Google Sheet? The file in Drive will stay — only admin stops syncing to it.');"
                >Unlink Google Sheet</button>
                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                <input type="hidden" name="redirect" value="event-form.php?id=<?= (int) $event['id'] ?>">
            <?php elseif ($isEdit && $canAutoSheet): ?>
                <button
                    type="submit"
                    class="btn btn--small btn--secondary"
                    style="margin-top:0.5rem;"
                    formaction="events-sheets-action.php"
                    formmethod="post"
                    name="action"
                    value="create_one"
                    onclick="return confirm('Create a Google Sheet for this event now?');"
                >Auto-create Google Sheet (needs Gmail Connect)</button>
                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="google_sheet_tab">Sheet tab name</label>
            <input class="form-input" type="text" id="google_sheet_tab" name="google_sheet_tab" value="<?= eventOld($old, $event, 'google_sheet_tab', 'Sheet1') ?>" placeholder="Sheet1">
            <p class="form-hint">Bottom tab name in Google Sheets — usually <code>Sheet1</code> for a new blank sheet (not the Settings default for auto-create).</p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="venue_eircode">Venue Eircode</label>
            <div class="form-inline-group" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-start;">
                <input class="form-input" type="text" id="venue_eircode" name="venue_eircode" value="<?= eventOld($old, $event, 'venue_eircode') ?>" placeholder="e.g. D02 X285" autocomplete="postal-code" maxlength="8" required style="flex:1;min-width:180px;">
                <?php if ($mapsOn): ?>
                    <button type="button" class="btn btn--secondary" id="venue_eircode_lookup">Look up GPS</button>
                <?php endif; ?>
            </div>
            <p class="form-hint">Required. GPS is set from this Eircode — venue sign-in only works within <?= h($radiusLabel) ?>.</p>
            <p class="form-hint" id="venue_gps_status" hidden></p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label form-label--required">Venue GPS (sign-in point)</label>
            <?php if ($mapsOn): ?>
                <input class="form-input" type="text" id="venue_search" placeholder="Or search full address…" autocomplete="off">
            <?php else: ?>
                <p class="form-hint">Add a Google Maps API key in Settings → Site to look up GPS from Eircode, or enter coordinates below.</p>
            <?php endif; ?>
            <input type="hidden" id="venue_lat" name="venue_lat" value="<?= eventOld($old, $event, 'venue_lat') ?>">
            <input type="hidden" id="venue_lng" name="venue_lng" value="<?= eventOld($old, $event, 'venue_lng') ?>">
            <?php if ($gpsV2On): ?>
                <div class="form-group" style="margin-top:0.75rem;">
                    <label class="form-label form-label--required" for="signin_radius_m">Sign-in radius (metres)</label>
                    <input
                        class="form-input"
                        type="number"
                        id="signin_radius_m"
                        name="signin_radius_m"
                        min="<?= (int) EVENT_SIGNIN_RADIUS_MIN_M ?>"
                        max="<?= (int) EVENT_SIGNIN_RADIUS_MAX_M ?>"
                        step="1"
                        value="<?= (int) $signinRadiusValue ?>"
                        required
                        style="max-width:12rem;"
                    >
                    <p class="form-hint">
                        How far from the map pin staff can sign in and stay on shift. Default <?= (int) EVENT_SIGNIN_RADIUS_DEFAULT_M ?> m (1 km).
                        Allowed range <?= (int) EVENT_SIGNIN_RADIUS_MIN_M ?>–<?= (int) EVENT_SIGNIN_RADIUS_MAX_M ?> m.
                        After attendance is active, staff are <strong>signed out automatically</strong> if they leave this radius (GPS monitoring on the sign-in page).
                    </p>
                </div>
            <?php else: ?>
                <input type="hidden" name="signin_radius_m" value="<?= (int) EVENT_SIGNIN_RADIUS_LEGACY_M ?>">
            <?php endif; ?>
            <?php if (!$mapsOn): ?>
                <div class="form-grid" style="margin-top:0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="venue_lat_manual">Latitude</label>
                        <input class="form-input" type="text" id="venue_lat_manual" inputmode="decimal" placeholder="53.3498" value="<?= eventOld($old, $event, 'venue_lat') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="venue_lng_manual">Longitude</label>
                        <input class="form-input" type="text" id="venue_lng_manual" inputmode="decimal" placeholder="-6.2603" value="<?= eventOld($old, $event, 'venue_lng') ?>">
                    </div>
                </div>
            <?php endif; ?>
            <div id="event-venue-map" class="event-venue-map" data-maps-enabled="<?= $mapsOn ? '1' : '0' ?>" style="height:280px;margin-top:0.75rem;border-radius:12px;background:#e2e8f0;<?= $mapsOn ? '' : ' display:none;' ?>">
                <?php if (!$mapsOn): ?>
                    <p class="event-venue-map__placeholder" style="padding:1rem;color:#64748b;">Map preview requires Google Maps API key.</p>
                <?php endif; ?>
            </div>
            <p class="form-hint">Confirm the pin at the staff entrance. Staff must be within <?= h($radiusLabel) ?> of this point to sign in<?= $gpsV2On ? ' — edit the radius above if the venue needs a different distance' : '' ?>.</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="staff_needed">Staff needed</label>
            <input class="form-input" type="number" id="staff_needed" name="staff_needed" min="1" max="99999" step="1" value="<?= eventOld($old, $event, 'staff_needed') ?>" placeholder="e.g. 25">
            <p class="form-hint">Number of lads needed for this shift. Used on the admin attendance board (spaces remaining). Not shown on the public registration form.</p>
        </div>

        <div class="form-group">
            <label class="form-label form-label--required" for="start_time">Start Time</label>
            <input class="form-input" type="time" id="start_time" name="start_time" value="<?= eventOld($old, $event, 'start_time', '09:00') ?>" required>
        </div>

        <div class="form-group">
            <label class="form-label form-label--required" for="end_time">End Time</label>
            <input class="form-input" type="time" id="end_time" name="end_time" value="<?= eventOld($old, $event, 'end_time', '23:00') ?>" required>
        </div>

        <div class="form-group">
            <label class="form-label" for="checkin_open_time">Sign-in opens</label>
            <input class="form-input" type="time" id="checkin_open_time" name="checkin_open_time" value="<?= eventOld($old, $event, 'checkin_open_time') ?>">
            <p class="form-hint">When staff can start venue QR sign-in on event day. Leave blank = 1 hour before shift start.</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="checkin_close_time">Sign-in closes</label>
            <input class="form-input" type="time" id="checkin_close_time" name="checkin_close_time" value="<?= eventOld($old, $event, 'checkin_close_time') ?>">
            <p class="form-hint">Last time staff can sign in. Leave blank = 1 hour after shift end.</p>
        </div>

        <?php if ($event): ?>
            <?php
            require_once __DIR__ . '/../includes/attendance-repository.php';
            $previewWindow = getEventCheckinWindow($event);
            ?>
            <p class="form-hint form-group--full">
                Current sign-in window for this event:
                <strong><?= h(formatCheckinWindowRangeLabel($previewWindow)) ?></strong>
                <?= $previewWindow['uses_custom_times'] ? '(custom times)' : '(default: 1h before start / 1h after end)' ?>
            </p>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label">Registration form</label>
            <?php
            $timesConfirmed = isset($old['times_confirmed'])
                ? !empty($old['times_confirmed'])
                : ($event ? eventShowsTimeOnRegistration($event) : false);
            ?>
            <label class="form-radio">
                <input type="checkbox" name="times_confirmed" value="1"<?= $timesConfirmed ? ' checked' : '' ?>>
                Show shift times on the staff registration form
            </label>
            <p class="form-hint">Tick only when start/end are real (not the 09:00–23:00 placeholder). Other events stay date-only for staff. Bulk roster: <code>php database/sync-live-events.php</code> · shift times only: <code>php database/apply-event-shift-times.php</code></p>
        </div>

        <div class="form-group">
            <label class="form-label">Status</label>
            <label class="form-radio">
                <?php
                $isActive = isset($old['is_active'])
                    ? !empty($old['is_active'])
                    : ($event ? (int) $event['is_active'] === 1 : true);
                ?>
                <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>>
                Active (show on registration form)
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save Changes' : 'Create Event' ?></button>
        </div>
    </form>

    <?php if ($isEdit && $event !== null): ?>
        <?php
        require_once __DIR__ . '/../includes/event-cancel-registrations.php';
        $cancelCounts = countEventRegistrationsEligibleForCancel($pdo, (int) $event['id']);
        ?>
        <section class="card" id="cancel-event-shifts" style="margin-top:1.5rem;border-color:var(--warning, #d97706);">
            <div class="card__header">
                <h2 class="card__title" style="color:var(--warning, #d97706);">Cancel all shifts for this event</h2>
                <p class="card__subtitle">
                    Rejects every <strong>approved</strong> (<?= (int) $cancelCounts['approved'] ?>) and <strong>pending</strong> (<?= (int) $cancelCounts['pending'] ?>) registration,
                    deactivates the event, and emails each person (HTML email with your cancellation reason) plus an in-app alert.
                    Use this when the whole event is cancelled (e.g. weather, client change).
                </p>
            </div>
            <form method="post" action="cancel-event-registrations.php" class="form-grid settings-form" onsubmit="return confirm('Cancel ALL shifts for this event? <?= (int) $cancelCounts['total'] ?> staff will be notified.');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                <input type="hidden" name="redirect" value="event-form.php?id=<?= (int) $event['id'] ?>">
                <div class="form-group form-group--full">
                    <label class="form-label form-label--required" for="cancel_reason">Reason (audit log)</label>
                    <textarea class="form-textarea" id="cancel_reason" name="reason" rows="2" required placeholder="e.g. Event cancelled — venue unavailable"></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn--danger"<?= (int) $cancelCounts['total'] === 0 ? ' disabled' : '' ?>>Cancel all shifts &amp; notify staff</button>
                </div>
            </form>
        </section>
        <?php $deleteImpact = countEventPurgeImpact($pdo, (int) $event['id']); ?>
        <section class="card" id="delete-event" style="margin-top:1.5rem;border-color:var(--danger, #dc2626);">
            <div class="card__header">
                <h2 class="card__title" style="color:var(--danger, #dc2626);">Delete event permanently</h2>
                <p class="card__subtitle">
                    Removes this event from the database and all related history:
                    <?= (int) $deleteImpact['registrations'] ?> registration(s),
                    <?= (int) $deleteImpact['attendance'] ?> attendance record(s),
                    <?= (int) $deleteImpact['invoices'] ?> commission invoice(s),
                    sign-in logs, roster slots, waitlist entries, and in-app notifications.
                    Staff profiles are kept. This cannot be undone.
                </p>
            </div>
            <div class="form-group form-group--full form-actions">
                <a href="delete-event.php?id=<?= (int) $event['id'] ?>&amp;redirect=event-form.php" class="btn btn--secondary" style="border-color:#dc2626;color:#dc2626;">Delete event permanently</a>
            </div>
        </section>
    <?php endif; ?>
</section>

<script>window.GOOGLE_MAPS_API_KEY = <?= json_encode($mapsKey, JSON_THROW_ON_ERROR) ?>;</script>
<script src="../assets/js/admin-event-venue.js"></script>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
