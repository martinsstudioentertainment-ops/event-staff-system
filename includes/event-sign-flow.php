<?php
/**
 * Shared event sign-in flow (email + last-4 PPS lookup + check-in).
 */

require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/sensitive-data.php';
require_once __DIR__ . '/signin-display.php';
require_once __DIR__ . '/staff-pass.php';
require_once __DIR__ . '/checkin-bib.php';
require_once __DIR__ . '/i18n.php';

/**
 * @return array{
 *     message: string,
 *     type: string,
 *     event: ?array,
 *     row: ?array,
 *     checkedIn: bool,
 *     window: ?array,
 *     showEmailForm: bool,
 *     showStaffPanel: bool,
 *     showCheckinPanel: bool,
 *     eligibility: ?array,
 *     formEmail: string,
 *     formPpsLast4: string,
 *     formBibNumber: string
 * }
 */
function handleEventEmailSigninRequest(PDO $pdo, string $eventToken, bool $requireVenue): array
{
    try {
        return handleEventEmailSigninRequestImpl($pdo, $eventToken, $requireVenue);
    } catch (Throwable $e) {
        error_log('[EventStaff] handleEventEmailSigninRequest: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        return [
            'message'          => 'Something went wrong. Open the staff app on your phone → Check In (no shared QR needed).',
            'type'             => 'error',
            'event'            => null,
            'row'              => null,
            'checkedIn'        => false,
            'window'           => null,
            'showEmailForm'    => true,
            'showStaffPanel'   => false,
            'showCheckinPanel' => false,
            'eligibility'      => ['allowed' => false, 'message' => 'Please use the staff app on your own phone.'],
            'formEmail'        => trim((string) ($_POST['email'] ?? '')),
            'formPpsLast4'     => strtoupper(preg_replace('/\s+/', '', trim((string) ($_POST['pps_last4'] ?? '')))),
            'formBibNumber'    => normalizeCheckinBibNumber((string) ($_POST['bib_number'] ?? '')),
        ];
    }
}

function handleEventEmailSigninRequestImpl(PDO $pdo, string $eventToken, bool $requireVenue): array
{
    $state = [
        'message'          => '',
        'type'             => '',
        'event'            => null,
        'row'              => null,
        'checkedIn'        => false,
        'window'           => null,
        'showEmailForm'    => false,
        'showStaffPanel'   => false,
        'showCheckinPanel' => false,
        'eligibility'      => null,
        'formEmail'        => '',
        'formPpsLast4'     => '',
        'formBibNumber'    => '',
    ];

    if ($eventToken === '') {
        $state['message'] = $requireVenue
            ? 'Invalid link. Please scan the QR code at the event entrance.'
            : 'Invalid sign-in link.';
        $state['type'] = 'error';

        return $state;
    }

    $event = getEventBySigninToken($pdo, $eventToken);

    if (!$event) {
        $state['message'] = 'Event sign-in link not found.';
        $state['type']    = 'error';

        return $state;
    }

    $state['event']  = $event;
    $state['window'] = getEventCheckinWindow($event);

    $coords = $_SERVER['REQUEST_METHOD'] === 'POST' ? parseSigninCoordinates($_POST) : null;
    $state['eligibility'] = getEventSigninEligibility(
        $event,
        $coords['lat'] ?? null,
        $coords['lng'] ?? null,
        $requireVenue,
        $pdo,
        $coords['accuracy_m'] ?? null
    );

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($requireVenue) {
            // Venue QR: email/PPS form exists in markup but stays hidden until GPS passes (event-sign-location.js).
            $venueOk  = eventVenueIsConfigured($event);
            $timeOpen = (bool) ($state['window']['is_open'] ?? false);

            if ($venueOk && $timeOpen) {
                $state['showEmailForm'] = true;
                $state['message']       = '';
                $state['type']          = '';
            } else {
                $state['message'] = $state['eligibility']['message'];
                $state['type']    = 'warning';
            }
        } elseif ($state['eligibility']['allowed']) {
            $state['showEmailForm'] = true;
        } else {
            $state['message'] = $state['eligibility']['message'];
            $state['type']    = 'warning';
        }

        return $state;
    }

    $action    = trim((string) ($_POST['action'] ?? 'lookup'));
    $email     = trim((string) ($_POST['email'] ?? ''));
    $ppsLast4  = strtoupper(preg_replace('/\s+/', '', trim((string) ($_POST['pps_last4'] ?? ''))));
    $state['formEmail']    = $email;
    $state['formPpsLast4'] = $ppsLast4;

    if ($requireVenue && $coords === null) {
        require_once __DIR__ . '/attendance-gps-phase15.php';
        $state['message']       = getGpsRequiredMessage();
        $state['type']          = 'warning';
        $state['showEmailForm'] = true;

        return $state;
    }

    if (!$state['eligibility']['allowed']) {
        $state['message'] = $state['eligibility']['message'];
        $state['type']    = 'warning';

        return $state;
    }

    if ($action === 'checkin') {
        $regId = (int) ($_POST['registration_id'] ?? 0);
        $row   = $regId > 0 ? getStaffRegistrationById($pdo, $regId) : null;

        if (!$row || (int) $row['event_id'] !== (int) $event['id'] || $row['status'] !== 'approved') {
            $state['message'] = getSigninMismatchMessage($pdo);
            $state['type']    = 'error';
            $state['showEmailForm'] = true;

            return $state;
        }

        if (!signinIdentityMatches($row, $ppsLast4, $pdo)) {
            $state['message'] = getSigninMismatchMessage($pdo);
            $state['type']    = 'error';
            $state['showEmailForm'] = true;

            return $state;
        }

        $state['row'] = $row;
        maybeLinkSigninLocationVerification($pdo, (int) $row['id'], (string) ($row['email'] ?? ''));

        if (hasCheckedIn($pdo, (int) $row['id'])) {
            applyExistingCheckinStateToSigninFlow($pdo, $state, (int) $row['id']);

            return $state;
        }

        $gps    = parseSigninCoordinates($_POST);
        $bib    = normalizeCheckinBibNumber((string) ($_POST['bib_number'] ?? ''));
        $result = recordCheckin($pdo, (int) $row['id'], 'self', $gps, $bib !== '' ? $bib : null);
        if ($result === true) {
            $state['message']        = 'Check-in successful! Welcome, ' . $row['first_name'] . '.';
            $state['type']           = 'success';
            $state['checkedIn']      = true;
            $state['showStaffPanel'] = true;
        } elseif ($result === 'pre_checked_in') {
            require_once __DIR__ . '/attendance-gps-phase1.php';
            $state['message']        = getHibernationCheckinMessage();
            $state['type']           = 'success';
            $state['checkedIn']      = true;
            $state['showStaffPanel'] = true;
        } elseif ($result === 'Already checked in.') {
            $state['message']        = 'You are already checked in for this event.';
            $state['type']           = 'warning';
            $state['checkedIn']      = true;
            $state['showStaffPanel'] = true;
        } else {
            $state['message']           = (string) $result;
            $state['type']              = 'error';
            $state['showStaffPanel']    = true;
            $state['showCheckinPanel']  = true;
        }

        return $state;
    }

    if ($email === '') {
        $state['message']       = 'Please enter the email address you used when registering.';
        $state['type']          = 'error';
        $state['showEmailForm'] = true;

        return $state;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $state['message']       = 'Please enter a valid email address.';
        $state['type']          = 'error';
        $state['showEmailForm'] = true;

        return $state;
    }

    if (!isSigninPpsRequired($pdo)) {
        // Email-only sign-in mode.
    } elseif (!isValidPpsLastFourInput($ppsLast4)) {
        $state['message']       = 'Enter the last 4 characters of your PPS number (letters or digits).';
        $state['type']          = 'error';
        $state['showEmailForm'] = true;

        return $state;
    }

    $row = getApprovedRegistrationByEmailForEvent($pdo, $email, (int) $event['id']);

    if (!$row || !signinIdentityMatches($row, $ppsLast4, $pdo)) {
        $state['message']       = getSigninMismatchMessage($pdo);
        $state['type']          = 'error';
        $state['showEmailForm'] = true;
        $state['row']           = null;

        return $state;
    }

    $state['row'] = $row;
    maybeLinkSigninLocationVerification($pdo, (int) $row['id'], $email);

    if (hasCheckedIn($pdo, (int) $row['id'])) {
        applyExistingCheckinStateToSigninFlow($pdo, $state, (int) $row['id']);

        return $state;
    }

    $state['showStaffPanel']   = true;
    $state['showCheckinPanel'] = true;

    return $state;
}

/**
 * @param array<string, mixed> $state
 */
function applyExistingCheckinStateToSigninFlow(PDO $pdo, array &$state, int $registrationId): void
{
    try {
        require_once __DIR__ . '/attendance-gps-phase1.php';
        require_once __DIR__ . '/attendance-gps-signout.php';

        maybeActivateHibernatedAttendanceForRegistration($pdo, $registrationId);
        $attendance = getAttendanceByRegistration($pdo, $registrationId);

        if (isGpsAttendanceV2Enabled($pdo) && isAttendanceAutoSignedOut($attendance)) {
            $state['message'] = getAutoSignoutMessage((string) ($attendance['signout_reason'] ?? 'left_geofence'));
            $state['type']    = 'warning';
        } elseif (isGpsAttendanceV2Enabled($pdo) && isAttendancePreCheckedIn($attendance)) {
            $state['message'] = getHibernationCheckinMessage();
            $state['type']    = 'success';
        } elseif (isGpsAttendanceV2Enabled($pdo) && isAttendanceActive($attendance)) {
            $radius = (int) getEventSigninRadiusMeters($state['event'] ?? [], $pdo);
            $state['message'] = 'You are signed in and on shift. Open the staff app on this phone (register.olasentra.com/staff-app.php) and stay signed in — GPS tracks you inside the ' . $radius . ' m zone. You can close this page.';
            $state['type']    = 'success';
        } else {
            $state['message'] = 'You are already checked in for this event.';
            $state['type']    = 'warning';
        }

        $state['checkedIn']      = true;
        $state['showStaffPanel'] = true;
    } catch (Throwable $e) {
        error_log('[EventStaff] applyExistingCheckinStateToSigninFlow: ' . $e->getMessage());
        $state['message']        = 'You are already checked in for this event.';
        $state['type']           = 'warning';
        $state['checkedIn']      = true;
        $state['showStaffPanel'] = true;
    }
}

function maybeLinkSigninLocationVerification(PDO $pdo, int $registrationId, string $email): void
{
    $verificationId = (int) ($_POST['location_verification_id'] ?? 0);
    if ($verificationId < 1) {
        return;
    }

    try {
        require_once __DIR__ . '/signin-location-log.php';
        linkSigninLocationVerification($pdo, $verificationId, $registrationId, $email);
    } catch (Throwable $e) {
        error_log('[EventStaff] maybeLinkSigninLocationVerification: ' . $e->getMessage());
    }
}

/**
 * @param array<string, mixed> $state
 */
function renderEventSigninPage(array $state, string $eventToken, bool $requireVenue, string $siteName): void
{
    $event            = $state['event'];
    $window           = $state['window'];
    $row              = $state['row'];
    $checkedIn        = $state['checkedIn'];
    $showEmailForm    = $state['showEmailForm'];
    $showStaffPanel   = $state['showStaffPanel'];
    $showCheckinPanel = $state['showCheckinPanel'];
    $message          = $state['message'];
    $type             = $state['type'];
    $formEmail        = (string) ($state['formEmail'] ?? '');
    $formPpsLast4     = (string) ($state['formPpsLast4'] ?? '');
    $formBibNumber    = (string) ($state['formBibNumber'] ?? '');
    $checkedInBib     = '';

    $venueCoords = $event ? getEventVenueCoordinates($event) : null;
    $pdo         = getDB();
    require_once __DIR__ . '/attendance-gps-phase1.php';
    require_once __DIR__ . '/attendance-gps-phase15.php';
    $gpsV2On          = isGpsAttendanceV2Enabled($pdo);
    $maxAccuracyM     = $gpsV2On ? getGpsMaxAccuracyMeters($pdo) : 0;
    $preCheckedIn     = false;
    $attendanceActive = false;
    $autoSignedOut    = false;
    $signoutMessage   = '';
    $registrationId   = $row ? (int) $row['id'] : 0;
    $checkinToken     = '';
    if ($registrationId > 0) {
        $checkinToken = (string) (ensureCheckinToken($pdo, $registrationId) ?? '');
    }
    if ($checkedIn && $row && $gpsV2On) {
        try {
            require_once __DIR__ . '/attendance-gps-signout.php';
            $attRow = getAttendanceByRegistration($pdo, (int) $row['id']);
            $checkedInBib     = normalizeCheckinBibNumber((string) ($attRow['bib_number'] ?? ''));
            $preCheckedIn     = isAttendancePreCheckedIn($attRow);
            $autoSignedOut    = isAttendanceAutoSignedOut($attRow);
            $attendanceActive = isAttendanceActive($attRow) && !$autoSignedOut;
            if ($autoSignedOut) {
                $signoutMessage = getAutoSignoutMessage((string) ($attRow['signout_reason'] ?? 'left_geofence'));
            }
        } catch (Throwable $e) {
            error_log('[EventStaff] renderEventSigninPage attendance state: ' . $e->getMessage());
        }
    } elseif ($checkedIn && $row) {
        $attRow = getAttendanceByRegistration($pdo, (int) $row['id']);
        if (is_array($attRow)) {
            $checkedInBib = normalizeCheckinBibNumber((string) ($attRow['bib_number'] ?? ''));
        }
    }
    bootstrapAppLocale($pdo);
    $ppsRequired = isSigninPpsRequired($pdo);
    $title       = $requireVenue ? t('venue_sign_in') : t('event_sign_in');
    if ($requireVenue) {
        $subtitle = $ppsRequired
            ? t('venue_sign_in_subtitle_pps')
            : t('venue_sign_in_subtitle');
    } else {
        $subtitle = $ppsRequired
            ? t('sign_in_subtitle_pps')
            : t('sign_in_subtitle_email');
    }

    $assetBase  = '';
    $themeColor = '#2563eb';
    require_once __DIR__ . '/theme.php';
    $themeColor = getThemeColor($pdo);

    require_once __DIR__ . '/share-meta.php';
    require_once __DIR__ . '/rich-text.php';

    $shareTitle = $event
        ? ($event['name'] . ' — ' . $title)
        : ($title . ' | ' . $siteName);
    $shareDesc = $event
        ? ('Sign in for ' . $event['name'] . ' on ' . formatEventDateLabel((string) $event['event_date']) . ' at ' . formatEventLocationLabel($event))
        : $subtitle;
    $shareUrl = $event && $eventToken !== ''
        ? ($requireVenue ? getEventVenueSigninUrl($eventToken, $pdo) : getEventEmailSigninUrl($eventToken, $pdo))
        : '';
    require_once __DIR__ . '/staff-app-v3-public.php';
    $v3CssVer = staffV3PublicCssVersion();
    ?>
<!DOCTYPE html>
<html lang="<?= h(getAppLocale()) ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0F172A">
    <?php renderShareMeta([
        'title'       => $shareTitle,
        'description' => $shareDesc,
        'url'         => $shareUrl,
        'site_name'   => $siteName,
    ], $pdo); ?>
    <title><?= h($shareTitle) ?> | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/pwa-head.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/staff-app-v3.css?v=<?= h($v3CssVer) ?>">
</head>
<body
    class="es-v3 es-v3--guest es-v3--signin login-page"
    <?php if ($requireVenue): ?>
    data-event-sign-page="true"
    data-require-venue="1"
    data-venue-configured="<?= $venueCoords ? '1' : '0' ?>"
    data-venue-lat="<?= $venueCoords ? h((string) $venueCoords['lat']) : '' ?>"
    data-venue-lng="<?= $venueCoords ? h((string) $venueCoords['lng']) : '' ?>"
    data-signin-radius-m="<?= $event ? (int) getEventSigninRadiusMeters($event, $pdo) : (int) EVENT_SIGNIN_RADIUS_LEGACY_M ?>"
    data-time-open="<?= $window && $window['is_open'] ? '1' : '0' ?>"
    data-time-message="<?= $window ? h(formatCheckinWindowMessage($window)) : '' ?>"
    data-staff-ready="<?= $showStaffPanel ? '1' : '0' ?>"
    data-checkin-ready="<?= $showCheckinPanel && !$checkedIn ? '1' : '0' ?>"
    data-already-checked-in="<?= $checkedIn ? '1' : '0' ?>"
    data-email-ready="<?= $showEmailForm ? '1' : '0' ?>"
    data-gps-v2-on="<?= $gpsV2On ? '1' : '0' ?>"
    data-max-accuracy-m="<?= (int) $maxAccuracyM ?>"
    data-pre-checked-in="<?= $preCheckedIn ? '1' : '0' ?>"
    data-attendance-active="<?= $attendanceActive ? '1' : '0' ?>"
    data-auto-signed-out="<?= $autoSignedOut ? '1' : '0' ?>"
    data-signout-message="<?= h($signoutMessage) ?>"
    data-registration-id="<?= $registrationId ?>"
    data-event-id="<?= $event ? (int) $event['id'] : 0 ?>"
    data-checkin-token="<?= h($checkinToken) ?>"
    data-event-token="<?= h($eventToken) ?>"
    data-gps-required-msg="<?= h(getGpsRequiredMessage()) ?>"
    <?php endif; ?>
>
    <div class="es-v3__ambient" aria-hidden="true"></div>
    <main class="es-v3__main login-page__wrap">
        <section class="card login-card">
            <div class="card__header card__header--row">
                <?php renderSigninPageHeading($event, $window, $title, $subtitle); ?>
                <?php renderLanguageSwitcher('e=' . urlencode($eventToken)); ?>
            </div>

            <?php if ($requireVenue): ?>
                <div class="alert alert--info alert--visible" role="note">
                    <strong>On your own phone?</strong> Open the staff app, sign in with Google, and tap <strong>Check In</strong> — no shared barcode or email form needed.
                    <p class="login-card__hint" style="margin-top:0.75rem;">
                        <a href="staff-app.php" class="btn btn--primary btn--block" style="display:inline-block;text-align:center;width:100%;">Open staff app</a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($event): ?>
                <?php if ($window): ?>
                    <?php renderSigninCountdown($window, $row ? (string) ($row['created_at'] ?? '') : null); ?>
                <?php endif; ?>
                <?php renderEventMainSecurityEmployerBlock($event); ?>
                <dl class="detail-list detail-list--compact">
                    <div class="detail-list__row"><dt><?= h(t('event')) ?></dt><dd><?= h($event['name']) ?></dd></div>
                    <div class="detail-list__row"><dt><?= h(t('date')) ?></dt><dd><?= h(formatEventDateLabel($event['event_date'])) ?></dd></div>
                    <div class="detail-list__row"><dt><?= h(t('location')) ?></dt><dd><?= h(formatEventLocationLabel($event)) ?></dd></div>
                    <?php if (formatEventReportingLabel($event) !== ''): ?>
                        <div class="detail-list__row"><dt><?= h(t('reporting_point')) ?></dt><dd><?= h(formatEventReportingLabel($event)) ?></dd></div>
                    <?php endif; ?>
                    <div class="detail-list__row"><dt><?= h(t('event_time')) ?></dt><dd><?= h(formatEventTimeRangeLabel($event)) ?></dd></div>
                    <?php if ($window): ?>
                        <div class="detail-list__row">
                            <dt><?= h(t('check_in_window')) ?></dt>
                            <dd><?= h(formatCheckinWindowMessage($window)) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
                <?php renderVenueMapBlock($event, $pdo); ?>
            <?php endif; ?>

            <?php if ($requireVenue): ?>
                <div id="signin-location-status" class="alert alert--warning alert--visible" role="status">
                    Checking your location… Allow GPS access on your phone to continue.
                </div>
                <input type="hidden" id="sign_lat" value="">
                <input type="hidden" id="sign_lng" value="">
                <input type="hidden" id="sign_accuracy_m" value="">
            <?php endif; ?>

            <?php if ($message !== ''): ?>
                <div class="alert alert--<?= h($type) ?> alert--visible"><?= h($message) ?></div>
            <?php endif; ?>

            <?php if ($event && !$checkedIn && $showEmailForm): ?>
                <div id="signin-email-panel" class="signin-panel"<?= $requireVenue ? ' hidden' : '' ?>>
                    <form method="post" class="form-grid login-form"<?= $requireVenue ? ' data-requires-location="true"' : '' ?>>
                        <input type="hidden" name="e" value="<?= h($eventToken) ?>">
                        <input type="hidden" name="action" value="lookup">
                        <?php if ($requireVenue): ?>
                            <input type="hidden" name="location_verification_id" value="">
                            <input type="hidden" name="sign_lat" value="">
                            <input type="hidden" name="sign_lng" value="">
                            <input type="hidden" name="sign_accuracy_m" value="">
                        <?php endif; ?>
                        <div class="form-group form-group--full">
                            <label class="form-label form-label--required" for="email"><?= h(t('your_email')) ?></label>
                            <input class="form-input" type="email" id="email" name="email" autocomplete="email" placeholder="you@example.com" value="<?= h($formEmail) ?>" required>
                        </div>
                        <?php if ($ppsRequired): ?>
                        <div class="form-group form-group--full">
                            <label class="form-label form-label--required" for="pps_last4"><?= h(t('pps_last4')) ?></label>
                            <input class="form-input" type="text" id="pps_last4" name="pps_last4" autocomplete="off" maxlength="4" inputmode="text" pattern="[A-Za-z0-9]{4}" placeholder="e.g. 567A" value="<?= h($formPpsLast4) ?>" required>
                            <p class="form-hint">Last 4 characters only (letters and digits), as on your registration.</p>
                        </div>
                        <?php endif; ?>
                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary btn--block"<?= $requireVenue ? ' disabled aria-disabled="true"' : '' ?>>Find My Registration</button>
                            </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($row && $row['status'] === 'approved'): ?>
                <div id="signin-staff-panel" class="signin-panel"<?= $showStaffPanel ? '' : ' hidden' ?>>
                    <dl class="detail-list detail-list--compact">
                        <div class="detail-list__row"><dt>Pass ID</dt><dd><?= h(formatStaffPassId((int) $row['id'], (string) ($event['event_date'] ?? ''))) ?></dd></div>
                        <div class="detail-list__row"><dt>Name</dt><dd><?= h($row['first_name'] . ' ' . $row['surname']) ?></dd></div>
                        <div class="detail-list__row"><dt>Role</dt><dd><?= h(formatRoleLabel($row['staff_role'])) ?></dd></div>
                        <?php if ($checkedInBib !== ''): ?>
                        <div class="detail-list__row"><dt>BIB number</dt><dd><?= h($checkedInBib) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </div>

                <?php if (!$checkedIn && $showCheckinPanel): ?>
                    <div id="signin-checkin-panel" class="signin-panel"<?= $requireVenue ? ' hidden' : '' ?>>
                        <form method="post" class="form-grid login-form"<?= $requireVenue ? ' data-requires-location="true"' : '' ?>>
                            <input type="hidden" name="e" value="<?= h($eventToken) ?>">
                            <input type="hidden" name="action" value="checkin">
                            <input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="email" value="<?= h($formEmail !== '' ? $formEmail : (string) $row['email']) ?>">
                            <input type="hidden" name="pps_last4" value="<?= h($formPpsLast4) ?>">
                            <?php if ($requireVenue): ?>
                                <input type="hidden" name="location_verification_id" value="">
                                <input type="hidden" name="sign_lat" value="">
                                <input type="hidden" name="sign_lng" value="">
                                <input type="hidden" name="sign_accuracy_m" value="">
                            <?php endif; ?>
                            <div class="form-group form-group--full">
                                <label class="form-label form-label--required" for="bib_number">BIB number</label>
                                <input
                                    class="form-input"
                                    type="text"
                                    id="bib_number"
                                    name="bib_number"
                                    autocomplete="off"
                                    inputmode="text"
                                    maxlength="20"
                                    placeholder="Enter the number on your vest"
                                    value="<?= h($formBibNumber) ?>"
                                    required
                                >
                                <p class="form-hint">Enter the bib number the contractor or security gave you today. This is recorded for shift proof.</p>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary btn--block"<?= $requireVenue ? ' disabled aria-disabled="true"' : '' ?>><?= h(t('check_in_now')) ?></button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$requireVenue && $event): ?>
                <p class="login-card__hint">At the venue? Ask staff for the QR code sign-in instead.</p>
            <?php endif; ?>

            <p class="login-card__hint"><a href="index.php"><?= h(t('back_to_registration')) ?></a></p>
        </section>
    </main>
    <script src="assets/js/mobile.js"></script>
    <?php
    $enablePwaInstall = true;
    include __DIR__ . '/pwa-scripts.php';
    ?>
    <script src="assets/js/signin-countdown.js"></script>
    <?php if ($requireVenue): ?>
        <script src="assets/js/event-sign-location.js"></script>
    <?php endif; ?>
</body>
</html>
    <?php
}
