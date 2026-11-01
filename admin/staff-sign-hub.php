<?php

declare(strict_types=1);

/**
 * Staff Sign Hub — admin control (multi-event geocode + install report + per-event sign-ins).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/registration-bib.php';
require_once __DIR__ . '/../includes/platform/staff-sign-hub.php';
require_once __DIR__ . '/../includes/staff-sign-hub-installs.php';
require_once __DIR__ . '/../includes/maps-core.php';
require_once __DIR__ . '/../includes/staff-sign-geo-lock.php';
require_once __DIR__ . '/../includes/settings-repository.php';

requireAdminCapability('attendance');

$pdo     = getDB();
$flash   = getAdminFlash();
$eventId = (int) ($_GET['event_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        setAdminFlash('error', 'Invalid request. Please try again.');
        header('Location: staff-sign-hub.php' . ($eventId > 0 ? '?event_id=' . $eventId : ''));
        exit;
    }
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'unlock_device') {
        $lockId = (int) ($_POST['lock_id'] ?? 0);
        $lockEventId = (int) ($_POST['event_id'] ?? $eventId);
        $result = adminUnlockStaffSignHubDeviceLock($pdo, $lockId, $lockEventId);
        setAdminFlash(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? 'Unlock failed.'));
        header('Location: staff-sign-hub.php?event_id=' . max(0, $lockEventId) . '#phone-locks');
        exit;
    }
    if ($postAction === 'save_geo_lock_pin') {
        $newPin = trim((string) ($_POST['staff_sign_geo_lock_pin'] ?? ''));
        if ($newPin === '' || !preg_match('/^[0-9A-Za-z]{4,12}$/', $newPin)) {
            setAdminFlash('error', 'Admin geo PIN must be 4–12 letters or numbers.');
        } else {
            setSetting($pdo, STAFF_SIGN_GEO_PIN_SETTING, $newPin);
            setAdminFlash('success', 'Phone Admin Geo Lock PIN updated.');
        }
        header('Location: staff-sign-hub.php' . ($eventId > 0 ? '?event_id=' . $eventId : '') . '#admin-geo-pin');
        exit;
    }
}

$geoLockPin = staffSignGeoLockPin($pdo);
$geoLockBase = '';
if (defined('REGISTRATION_SITE_URL')) {
    $geoLockBase = rtrim((string) REGISTRATION_SITE_URL, '/');
} elseif (defined('MAIN_SITE_URL')) {
    $geoLockBase = rtrim((string) MAIN_SITE_URL, '/');
}
if ($geoLockBase === '') {
    $geoLockBase = 'https://register.olasentra.com';
}
$geoLockPhoneUrl = $geoLockBase . '/staff-sign-geo-lock.php';

if (isset($_GET['export']) && (string) $_GET['export'] === 'installs') {
    sendStaffSignHubInstallsCsv($pdo);
}

$events     = listEventsForStaffSignHub($pdo, 80);
$geoEvents  = listStaffSignHubEventsGeoStatus($pdo, 80);
$installRep = getStaffSignHubInstallReport($pdo, 500);
$hub        = null;
$phoneLocks = [];

try {
    if ($eventId > 0) {
        $hub = getStaffSignHubSnapshot($pdo, $eventId);
        $phoneLocks = listStaffSignHubDeviceLocksForEvent($pdo, $eventId);
    }
} catch (Throwable $e) {
    error_log('[EventStaff] staff-sign-hub.php: ' . $e->getMessage());
    setAdminFlash('error', 'Staff Sign Hub could not load: ' . $e->getMessage());
    $flash = getAdminFlash();
}

$pageTitle  = 'Staff Sign Hub';
$activePage = 'attendance';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff Sign Hub</h2>
            <p class="card__subtitle">
                Standalone staff app for <strong>every event</strong> — not locked to one shift.
                Choose an event below to set its geocode. Staff pick the event themselves when they sign in.
            </p>
        </div>
        <div class="toolbar toolbar--compact">
            <a href="staff-sign-hub-app-download.php" class="btn btn--primary">Staff Sign APK / download</a>
            <a href="<?= h(staffSignHubShortShareUrl()) ?>" target="_blank" rel="noopener" class="btn btn--secondary">Short link (phones)</a>
            <a href="attendance.php" class="btn btn--secondary">← Attendance</a>
        </div>
    </div>
    <p class="form-hint" style="margin-top:.75rem">
        Share with iPhone &amp; Android:
        <strong><a href="<?= h(staffSignHubShortShareUrl()) ?>" target="_blank" rel="noopener"><?= h(staffSignHubShortShareUrl()) ?></a></strong>
        (opens Staff Sign app mode).
    </p>
</section>

<section class="card" id="admin-geo-pin">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Phone Admin Geo Lock</h2>
            <p class="card__subtitle">
                Set venue pin + range from a phone — no web-admin login.
                After Confirm, this hub shows the same locked geocode.
            </p>
        </div>
        <a href="<?= h($geoLockPhoneUrl) ?>" target="_blank" rel="noopener" class="btn btn--primary">Open on phone</a>
    </div>
    <p class="form-hint">
        Phone page:
        <strong><a href="<?= h($geoLockPhoneUrl) ?>" target="_blank" rel="noopener"><?= h($geoLockPhoneUrl) ?></a></strong>
    </p>
    <form method="post" class="form-grid" style="max-width:28rem;margin-top:1rem">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save_geo_lock_pin">
        <?php if ($eventId > 0): ?>
            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
        <?php endif; ?>
        <label for="staff_sign_geo_lock_pin">Admin lock PIN (4–12 chars)</label>
        <div class="toolbar toolbar--compact" style="gap:.5rem;align-items:center">
            <input type="text" id="staff_sign_geo_lock_pin" name="staff_sign_geo_lock_pin"
                   value="<?= h($geoLockPin) ?>" maxlength="12" autocomplete="off"
                   pattern="[0-9A-Za-z]{4,12}" required style="max-width:12rem">
            <button type="submit" class="btn btn--secondary">Save PIN</button>
        </div>
        <p class="form-hint">Enter this PIN once on the phone Admin Geo Lock screen. Not your web password.</p>
    </form>
</section>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Download &amp; install report</h2>
            <p class="card__subtitle">
                Full list of devices that opened, downloaded, or installed Staff Sign Hub.
                Staff name appears after they select themselves in the app.
            </p>
        </div>
        <a href="staff-sign-hub.php?export=installs" class="btn btn--secondary">Export installs CSV</a>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $installRep['total'] ?></p>
            <p class="stat-card__label">Devices seen</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $installRep['downloaded'] ?></p>
            <p class="stat-card__label">Downloaded</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $installRep['installed'] ?></p>
            <p class="stat-card__label">Installed / APK</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $installRep['identified'] ?></p>
            <p class="stat-card__label">Linked to staff name</p>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:1rem">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Staff</th>
                    <th>Downloaded</th>
                    <th>Installed</th>
                    <th>Channel</th>
                    <th>Device</th>
                    <th>Opens</th>
                    <th>First seen</th>
                    <th>Last seen</th>
                    <th>Last event used</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($installRep['devices'] === []): ?>
                <tr><td colspan="9">No downloads or installs recorded yet. Share the Staff Sign app / APK with staff.</td></tr>
            <?php else: ?>
                <?php foreach ($installRep['devices'] as $dev): ?>
                    <?php
                    $name = trim((string) ($dev['first_name'] ?? '') . ' ' . (string) ($dev['surname'] ?? ''));
                    if ($name === '') {
                        $name = trim((string) ($dev['staff_email'] ?? ''));
                    }
                    if ($name === '') {
                        $name = '(not identified yet)';
                    }
                    $lastEv = trim((string) ($dev['last_event_name'] ?? ''));
                    if ($lastEv !== '' && !empty($dev['last_event_date'])) {
                        $lastEv .= ' — ' . formatSystemDate((string) $dev['last_event_date'], $pdo);
                    }
                    ?>
                    <tr>
                        <td>
                            <?= h($name) ?>
                            <?php if (!empty($dev['staff_email']) && $name !== (string) $dev['staff_email']): ?>
                                <div class="form-hint"><?= h((string) $dev['staff_email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($dev['downloaded']) ? 'Yes' : 'No' ?></td>
                        <td><?= !empty($dev['installed']) ? 'Yes' : 'No' ?></td>
                        <td><?= h((string) ($dev['channel'] ?? 'web')) ?></td>
                        <td>
                            <?= h((string) ($dev['device_type'] ?? '')) ?>
                            <div class="form-hint"><?= h(trim((string) ($dev['os_name'] ?? '') . ' · ' . (string) ($dev['browser_name'] ?? ''), ' ·')) ?></div>
                        </td>
                        <td><?= (int) ($dev['open_count'] ?? 0) ?></td>
                        <td><?= !empty($dev['first_seen_at']) ? h(formatSystemDateTime((string) $dev['first_seen_at'], $pdo)) : '—' ?></td>
                        <td><?= !empty($dev['last_seen_at']) ? h(formatSystemDateTime((string) $dev['last_seen_at'], $pdo)) : '—' ?></td>
                        <td><?= $lastEv !== '' ? h($lastEv) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Set geocode by event</h2>
        <p class="card__subtitle">
            Choose which event to update. Each event has its own venue pin and radius.
            Prefer locking from the phone Admin Geo Lock page at the venue — or use event form below.
            Staff select that event in the app when they arrive.
        </p>
    </div>
    <p class="form-hint" style="margin-bottom:1rem">
        <a href="<?= h($geoLockPhoneUrl) ?>" target="_blank" rel="noopener">Lock geo from phone (PIN)</a>
        · web form still works if you are already logged in here.
    </p>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Ends</th>
                    <th>Geocode</th>
                    <th>Radius</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($geoEvents === []): ?>
                <tr><td colspan="6">No events found.</td></tr>
            <?php else: ?>
                <?php foreach ($geoEvents as $gev): ?>
                    <tr<?= (int) $gev['id'] === $eventId ? ' class="is-selected"' : '' ?>>
                        <td><?= h((string) $gev['name']) ?></td>
                        <td><?= h(formatSystemDate((string) $gev['event_date'], $pdo)) ?></td>
                        <td><?= h((string) ($gev['end_time'] !== '' ? $gev['end_time'] : '—')) ?></td>
                        <td>
                            <?php if (!empty($gev['venue_configured'])): ?>
                                <span class="badge badge--small">Set</span>
                                <div class="form-hint"><?= h((string) $gev['venue_lat']) ?>, <?= h((string) $gev['venue_lng']) ?></div>
                            <?php else: ?>
                                <span class="badge badge--small">Missing</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $gev['signin_radius_m'] ?> m</td>
                        <td class="toolbar toolbar--compact">
                            <a href="event-form.php?id=<?= (int) $gev['id'] ?>" class="btn btn--primary btn--small">Set geocode</a>
                            <a href="staff-sign-hub.php?event_id=<?= (int) $gev['id'] ?>#hub-signins" class="btn btn--secondary btn--small">View sign-ins</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" id="hub-signins">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Hub sign-ins for one event</h2>
            <p class="card__subtitle">Optional filter — pick an event to see <strong>staff_hub</strong> rows and download that sheet.</p>
        </div>
        <form method="get" class="toolbar toolbar--compact">
            <select name="event_id" class="form-select" onchange="this.form.submit()">
                <option value="0">— Choose event —</option>
                <?php foreach ($events as $ev): ?>
                    <option value="<?= (int) $ev['id'] ?>"<?= (int) $ev['id'] === $eventId ? ' selected' : '' ?>>
                        <?= h((string) ($ev['name'] ?? '')) ?> — <?= h(formatSystemDate((string) ($ev['event_date'] ?? ''), $pdo)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($hub === null): ?>
        <p class="form-hint">Choose an event above to view Staff Sign Hub check-ins for that shift.</p>
    <?php else: ?>
        <?php $geoOk = !empty($hub['venue_configured']); ?>
        <div class="stat-grid">
            <div class="stat-card">
                <p class="stat-card__value"><?= (int) $hub['count_signed_in'] ?></p>
                <p class="stat-card__label">Hub sign-ins</p>
            </div>
            <div class="stat-card">
                <p class="stat-card__value"><?= (int) $hub['count_still_in'] ?></p>
                <p class="stat-card__label">Still on site</p>
            </div>
            <div class="stat-card">
                <p class="stat-card__value"><?= (int) $hub['count_signed_out'] ?></p>
                <p class="stat-card__label">Signed out</p>
            </div>
            <div class="stat-card">
                <p class="stat-card__value"><?= $geoOk ? 'Set' : 'Missing' ?></p>
                <p class="stat-card__label">Venue geocode</p>
            </div>
        </div>

        <?php if (!$geoOk): ?>
            <div class="alert alert--warning alert--visible">
                Geocode missing for this event.
                <a href="event-form.php?id=<?= (int) $eventId ?>">Set geocode now</a>.
            </div>
        <?php endif; ?>

        <div class="toolbar" style="margin-top:1rem;flex-wrap:wrap;gap:8px">
            <a href="event-form.php?id=<?= (int) $eventId ?>" class="btn btn--primary">Set / update geocode</a>
            <a href="export-attendance.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">Download sheet (CSV)</a>
            <a href="manual-signin.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">Manual sign-in (admin)</a>
            <a href="attendant.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">Attendant (admin)</a>
            <a href="work-hours.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">Work hours</a>
        </div>

        <p class="form-hint" style="margin-top:1rem">
            Staff Sign app: <strong>one phone = one person</strong>. Only <strong>admin</strong> can unlock a phone or sign someone else in (Manual / Attendant).
        </p>

        <h3 class="form-section-title" id="phone-locks" style="margin-top:1.5rem;">Phone locks (admin unlock)</h3>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Staff</th>
                        <th>Locked at</th>
                        <th>Device</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($phoneLocks === []): ?>
                    <tr><td colspan="4">No phone locks for this event yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($phoneLocks as $lock): ?>
                        <?php
                        $lockName = trim((string) ($lock['first_name'] ?? '') . ' ' . (string) ($lock['surname'] ?? ''));
                        $vk = (string) ($lock['visitor_key'] ?? '');
                        $vkShort = strlen($vk) > 10 ? substr($vk, 0, 8) . '…' : $vk;
                        ?>
                        <tr>
                            <td>
                                <?= h($lockName !== '' ? $lockName : 'Registration #' . (int) ($lock['registration_id'] ?? 0)) ?>
                                <div class="form-hint"><?= h((string) ($lock['staff_role'] ?? '')) ?></div>
                            </td>
                            <td><?= !empty($lock['locked_at']) ? h(formatSystemDateTime((string) $lock['locked_at'], $pdo)) : '—' ?></td>
                            <td><code><?= h($vkShort) ?></code></td>
                            <td>
                                <form method="post" style="margin:0" onsubmit="return confirm('Unlock this phone so they can sign in again?');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="unlock_device">
                                    <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                    <input type="hidden" name="lock_id" value="<?= (int) ($lock['id'] ?? 0) ?>">
                                    <button type="submit" class="btn btn--secondary btn--small">Unlock</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 class="form-section-title" style="margin-top:1.5rem;">staff_hub rows</h3>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Bib</th>
                        <th>PSA</th>
                        <th>Signed in</th>
                        <th>Gate start</th>
                        <th>Signed out</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($hub['hub_signins'] === []): ?>
                    <tr><td colspan="7">No Staff Sign Hub check-ins for this event yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($hub['hub_signins'] as $row): ?>
                        <?php
                        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));
                        $out = !empty($row['checked_out_at']);
                        ?>
                        <tr>
                            <td>
                                <?= h($name) ?>
                                <div class="form-hint"><?= h((string) ($row['staff_role'] ?? '')) ?></div>
                            </td>
                            <td><?= h(formatRegistrationBibDisplay($row['assigned_bib_number'] ?? null)) ?></td>
                            <td><?= h(trim((string) ($row['psa_licence'] ?? '')) !== '' ? (string) $row['psa_licence'] : '—') ?></td>
                            <td><?= !empty($row['checked_in_at']) ? h(formatSystemDateTime((string) $row['checked_in_at'], $pdo)) : '—' ?></td>
                            <td><?= h(trim((string) ($row['gate_start_time'] ?? '')) !== '' ? (string) $row['gate_start_time'] : '—') ?></td>
                            <td><?= $out ? h(formatSystemDateTime((string) $row['checked_out_at'], $pdo)) : '—' ?></td>
                            <td>
                                <?php if ($out): ?>
                                    <span class="badge badge--small">Signed out</span>
                                <?php else: ?>
                                    <span class="badge badge--small">On site</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
