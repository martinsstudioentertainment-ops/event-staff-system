<?php

require_once __DIR__ . '/config.php';

initSecureSession();

require_once __DIR__ . '/includes/staff-registration-schema.php';
require_once __DIR__ . '/includes/settings-repository.php';

require_once __DIR__ . '/includes/staff-repository.php';

require_once __DIR__ . '/includes/events-repository.php';

require_once __DIR__ . '/includes/attendance-repository.php';
require_once __DIR__ . '/includes/attendance-gps-phase1.php';
require_once __DIR__ . '/includes/attendance-gps-phase15.php';

require_once __DIR__ . '/includes/signin-display.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';

require_once __DIR__ . '/includes/staff-pass.php';

require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/staff-onboarding.php';
require_once __DIR__ . '/includes/staff-profile-gate.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/status-repository.php';



$pdo = getDB();

try {
    ensureStaffRegistrationCheckinSchema($pdo);
} catch (Throwable $e) {
    error_log('[EventStaff] check-in schema: ' . $e->getMessage());
}

bootstrapAppLocale($pdo);



$token     = trim((string) ($_GET['token'] ?? ''));

$siteName  = getSiteName($pdo);

$message   = '';

$type      = '';

$row       = null;

$checkedIn = false;

$window    = null;

$gpsV2On = isGpsAttendanceV2Enabled($pdo);



if ($token === '') {

    $message = t('check_in_invalid_link');

    $type    = 'error';

} else {

    $row = getRegistrationByToken($pdo, $token);



    if (!$row) {

        $message = t('check_in_not_found');

        $type    = 'error';

    } elseif ($row['status'] !== 'approved') {

        $message = t('check_in_not_approved');

        $type    = 'error';

    } else {
        $checkinEmail = (string) ($row['email'] ?? '');
        $staffId      = ensureStaffRecordForEmail($pdo, $checkinEmail);
        $staffRow     = $staffId !== null ? getStaffById($pdo, $staffId) : null;

        if ($staffRow !== null && staffNeedsProfileForm($pdo, $staffRow)) {
            require_once __DIR__ . '/includes/staff-portal-remember.php';
            establishStaffPortalSessionWithRemember($pdo, $staffRow);
            $statusToken = trim((string) ($row['status_token'] ?? ''));
            if ($statusToken === '') {
                $resolved = resolveStatusTokenByEmail($pdo, $checkinEmail);
                $statusToken = $resolved ?? '';
            }
            $_SESSION['staff_profile_return'] = $statusToken !== ''
                ? 'status.php?token=' . urlencode($statusToken)
                : 'staff-app.php';
            $_SESSION['registration_status_message'] =
                'Please complete your staff profile before checking in.';
            header('Location: staff-profile.php');
            exit;
        }

        $window = getEventCheckinWindow($row);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            if (!$window['is_open']) {

                $message = formatCheckinWindowMessage($window);

                $type    = 'warning';

            } else {
                $gps = $gpsV2On ? parseSigninCoordinates($_POST) : null;
                if ($gpsV2On) {
                    require_once __DIR__ . '/includes/attendance-gps-phase15.php';
                    $eventRow = mergeRegistrationWithEvent($pdo, $row);
                    if ($gps === null) {
                        $message = getGpsRequiredMessage();
                        $type    = 'error';
                    } else {
                        $gpsCheck = validateGpsForCheckin($pdo, $eventRow, $gps);
                        if (!$gpsCheck['ok']) {
                            $message = $gpsCheck['message'];
                            $type    = 'error';
                        } else {
                            $result = recordCheckin($pdo, (int) $row['id'], 'self', $gps);
                            $message = '';
                        }
                    }
                } else {
                    $result = recordCheckin($pdo, (int) $row['id'], 'self');
                    $message = '';
                }

                if ($message === '' && isset($result) && $result === true) {

                    $message   = t('check_in_success', ['name' => $row['first_name']]);

                    $type      = 'success';

                    $checkedIn = true;

                    $row       = getRegistrationByToken($pdo, $token);

                } elseif ($message === '' && isset($result) && $result === 'pre_checked_in') {

                    require_once __DIR__ . '/includes/attendance-gps-phase1.php';

                    $message   = getHibernationCheckinMessage();

                    $type      = 'success';

                    $checkedIn = true;

                    $row       = getRegistrationByToken($pdo, $token);

                } elseif ($message === '' && isset($result) && $result === 'Already checked in.') {

                    $message   = t('already_checked_in');

                    $type      = 'warning';

                    $checkedIn = true;

                } elseif ($message === '' && isset($result)) {

                    $message = (string) $result;

                    $type    = 'error';

                }

            }

        } elseif (hasCheckedIn($pdo, (int) $row['id'])) {

            $message   = t('already_checked_in');

            $type      = 'warning';

            $checkedIn = true;

        } elseif (!$window['is_open']) {

            $message = formatCheckinWindowMessage($window);

            $type    = 'warning';

        }

    }

}



$assetBase  = '';

$themeColor = '#2563eb';

require_once __DIR__ . '/includes/theme.php';

$themeColor = getThemeColor($pdo);

$headerPortalStaff = getStaffFromPortalSession($pdo);
if ($headerPortalStaff === null && is_array($row)) {
    $headerPortalStaff = [
        'first_name' => $row['first_name'] ?? '',
        'surname'    => $row['surname'] ?? '',
        'email'      => $row['email'] ?? '',
    ];
}

?>

<!DOCTYPE html>

<html lang="<?= h(getAppLocale()) ?>" data-theme="light">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <title><?= h(t('event_check_in')) ?> | <?= h($siteName) ?></title>

    <?php include __DIR__ . '/includes/pwa-head.php'; ?>

</head>

<body class="staff-public-shell staff-public-shell--event-ops staff-public-shell--narrow login-page staff-mobile-page" data-pwa-install="1">
    <?php renderStaffPublicBackground(true); ?>
    <?php renderStaffPublicHeader($pdo, $siteName, ['language_switcher' => true, 'home_url' => 'staff-app.php', 'portal_staff' => $headerPortalStaff]); ?>

    <main class="login-page__wrap staff-public-main">
        <section class="card login-card staff-public-card">

            <div class="card__header card__header--row">
                <?php
                if ($row && $row['status'] === 'approved') {
                    renderSigninPageHeading($row, $window, t('event_check_in'), $siteName);
                } else {
                    ?>
                    <div class="signin-page-heading">
                        <h1 class="card__title signin-page-heading__title"><?= h(t('event_check_in')) ?></h1>
                        <p class="card__subtitle"><?= h($siteName) ?></p>
                    </div>
                    <?php
                }
                ?>
            </div>



            <?php if ($message !== ''): ?>

                <div class="alert alert--<?= h($type) ?> alert--visible"><?= h($message) ?></div>

            <?php endif; ?>



            <?php if ($row && $row['status'] === 'approved'): ?>

                <?php if ($window): ?>

                    <?php renderSigninCountdown($window, (string) ($row['created_at'] ?? '')); ?>

                <?php endif; ?>

                <?php renderEventMainSecurityEmployerBlock($row); ?>

                <dl class="detail-list detail-list--compact">

                    <div class="detail-list__row"><dt><?= h(t('pass_id')) ?></dt><dd><?= h(formatStaffPassId((int) $row['id'], (string) ($row['event_date'] ?? ''))) ?></dd></div>

                    <div class="detail-list__row"><dt><?= h(t('name')) ?></dt><dd><?= h($row['first_name'] . ' ' . $row['surname']) ?></dd></div>

                    <div class="detail-list__row"><dt><?= h(t('event')) ?></dt><dd><?= h(formatEventLabel($row)) ?></dd></div>

                    <div class="detail-list__row"><dt><?= h(t('location')) ?></dt><dd><?= h(formatEventLocationLabel($row)) ?></dd></div>

                    <?php if (formatEventReportingLabel($row) !== ''): ?>

                        <div class="detail-list__row"><dt><?= h(t('reporting_point')) ?></dt><dd><?= h(formatEventReportingLabel($row)) ?></dd></div>

                    <?php endif; ?>

                    <div class="detail-list__row"><dt><?= h(t('event_time')) ?></dt><dd><?= h(formatEventTimeRangeLabel($row)) ?></dd></div>

                    <div class="detail-list__row"><dt><?= h(t('role')) ?></dt><dd><?= h(formatRoleLabel($row['staff_role'])) ?></dd></div>

                    <?php if ($window): ?>

                        <div class="detail-list__row">

                            <dt><?= h(t('check_in_window')) ?></dt>

                            <dd><?= h(formatCheckinWindowMessage($window)) ?></dd>

                        </div>

                    <?php endif; ?>

                </dl>

                <?php if ($gpsV2On && !$checkedIn): ?>
                    <div class="alert alert--info alert--visible" role="note">
                        Signed in to the staff app? Use <a href="staff-checkin.php">Check In</a> on your own phone — no shared venue barcode.
                    </div>
                <?php endif; ?>

                <?php if (!$checkedIn && $window && $window['is_open']): ?>

                    <form method="post" class="form-grid login-form" id="token-checkin-form"<?= $gpsV2On ? ' data-requires-gps="1"' : '' ?>>

                        <?php if ($gpsV2On): ?>
                            <input type="hidden" name="sign_lat" id="token-sign-lat" value="">
                            <input type="hidden" name="sign_lng" id="token-sign-lng" value="">
                            <input type="hidden" name="sign_accuracy_m" id="token-sign-accuracy" value="">
                            <p class="form-hint" id="token-gps-hint">Allow location on this phone to check in at the venue.</p>
                        <?php endif; ?>

                        <div class="form-actions">

                            <button type="submit" class="btn btn--primary btn--block" id="token-checkin-btn"<?= $gpsV2On ? ' disabled aria-disabled="true"' : '' ?>><?= h(t('check_in_now')) ?></button>

                        </div>

                    </form>

                <?php endif; ?>

            <?php endif; ?>



            <p class="login-card__hint"><a href="index.php"><?= h(t('back_to_registration')) ?></a></p>

        </section>

    </main>

    <?php
    $enablePwaInstall = true;
    include __DIR__ . '/includes/pwa-scripts.php';
    ?>
    <?php if ($gpsV2On && $row && $row['status'] === 'approved' && !$checkedIn): ?>
    <script>
    (function () {
        var form = document.getElementById('token-checkin-form');
        var btn = document.getElementById('token-checkin-btn');
        var latInput = document.getElementById('token-sign-lat');
        var lngInput = document.getElementById('token-sign-lng');
        var accInput = document.getElementById('token-sign-accuracy');
        var hint = document.getElementById('token-gps-hint');
        if (!form || !navigator.geolocation) return;
        function ready(lat, lng, acc) {
            if (latInput) latInput.value = String(lat);
            if (lngInput) lngInput.value = String(lng);
            if (accInput && acc != null) accInput.value = String(Math.round(acc));
            if (btn) { btn.disabled = false; btn.setAttribute('aria-disabled', 'false'); }
            if (hint) hint.textContent = 'GPS ready — tap to check in at the venue.';
        }
        navigator.geolocation.getCurrentPosition(
            function (pos) { ready(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy); },
            function () { if (hint) hint.textContent = 'Enable location in phone settings to check in.'; },
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
        );
        form.addEventListener('submit', function (e) {
            if (!latInput || latInput.value === '') {
                e.preventDefault();
                navigator.geolocation.getCurrentPosition(
                    function (pos) { ready(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy); form.submit(); },
                    function () { alert('Location is required to check in at the venue.'); },
                    { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
                );
            }
        });
    })();
    </script>
    <?php endif; ?>
    <script src="assets/js/signin-countdown.js"></script>

</body>

</html>


