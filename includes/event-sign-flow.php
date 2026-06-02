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
 *     formPpsLast4: string
 * }
 */
function handleEventEmailSigninRequest(PDO $pdo, string $eventToken, bool $requireVenue): array
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
        $requireVenue
    );

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($state['eligibility']['allowed']) {
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

        if (hasCheckedIn($pdo, (int) $row['id'])) {
            $state['message']        = 'You are already checked in for this event.';
            $state['type']           = 'warning';
            $state['checkedIn']      = true;
            $state['showStaffPanel'] = true;

            return $state;
        }

        $result = recordCheckin($pdo, (int) $row['id'], 'self');
        if ($result === true) {
            $state['message']        = 'Check-in successful! Welcome, ' . $row['first_name'] . '.';
            $state['type']           = 'success';
            $state['checkedIn']      = true;
            $state['showStaffPanel'] = true;
        } elseif ($result === 'Already checked in.') {
            $state['message']        = 'You are already checked in for this event.';
            $state['type']           = 'warning';
            $state['checkedIn']      = true;
            $state['showStaffPanel'] = true;
        } else {
            $state['message'] = (string) $result;
            $state['type']    = 'error';
            $state['row']     = null;
            $state['showEmailForm'] = true;
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

    if (hasCheckedIn($pdo, (int) $row['id'])) {
        $state['message']        = 'You are already checked in for this event.';
        $state['type']           = 'warning';
        $state['checkedIn']      = true;
        $state['showStaffPanel'] = true;

        return $state;
    }

    $state['showStaffPanel']   = true;
    $state['showCheckinPanel'] = true;

    return $state;
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

    $venueCoords = $event ? getEventVenueCoordinates($event) : null;
    $pdo         = getDB();
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
    ?>
<!DOCTYPE html>
<html lang="<?= h(getAppLocale()) ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php renderShareMeta([
        'title'       => $shareTitle,
        'description' => $shareDesc,
        'url'         => $shareUrl,
        'site_name'   => $siteName,
    ], $pdo); ?>
    <title><?= h($shareTitle) ?> | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/pwa-head.php'; ?>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body
    class="login-page"
    <?php if ($requireVenue): ?>
    data-event-sign-page="true"
    data-require-venue="1"
    data-venue-configured="<?= $venueCoords ? '1' : '0' ?>"
    data-venue-lat="<?= $venueCoords ? h((string) $venueCoords['lat']) : '' ?>"
    data-venue-lng="<?= $venueCoords ? h((string) $venueCoords['lng']) : '' ?>"
    data-signin-radius-m="<?= $event ? (int) getEventSigninRadiusMeters($event) : 100 ?>"
    data-time-open="<?= $window && $window['is_open'] ? '1' : '0' ?>"
    data-time-message="<?= $window ? h(formatCheckinWindowMessage($window)) : '' ?>"
    data-staff-ready="<?= $showStaffPanel ? '1' : '0' ?>"
    data-checkin-ready="<?= $showCheckinPanel && !$checkedIn ? '1' : '0' ?>"
    data-already-checked-in="<?= $checkedIn ? '1' : '0' ?>"
    data-email-ready="<?= $showEmailForm ? '1' : '0' ?>"
    <?php endif; ?>
>
    <main class="login-page__wrap">
        <section class="card login-card">
            <div class="card__header card__header--row">
                <?php renderSigninPageHeading($event, $window, $title, $subtitle); ?>
                <?php renderLanguageSwitcher('e=' . urlencode($eventToken)); ?>
            </div>

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
                <div id="signin-location-status" class="alert alert--warning alert--visible" hidden></div>
                <input type="hidden" id="sign_lat" value="">
                <input type="hidden" id="sign_lng" value="">
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
                            <input type="hidden" name="sign_lat" value="">
                            <input type="hidden" name="sign_lng" value="">
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
                            <button type="submit" class="btn btn--primary btn--block">Find My Registration</button>
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
                                <input type="hidden" name="sign_lat" value="">
                                <input type="hidden" name="sign_lng" value="">
                            <?php endif; ?>
                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary btn--block"><?= h(t('check_in_now')) ?></button>
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
