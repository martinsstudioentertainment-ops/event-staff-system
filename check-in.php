<?php

require_once __DIR__ . '/config.php';

initSecureSession();

require_once __DIR__ . '/includes/staff-registration-schema.php';
require_once __DIR__ . '/includes/settings-repository.php';

require_once __DIR__ . '/includes/staff-repository.php';

require_once __DIR__ . '/includes/events-repository.php';

require_once __DIR__ . '/includes/attendance-repository.php';

require_once __DIR__ . '/includes/signin-display.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';

require_once __DIR__ . '/includes/staff-pass.php';

require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/staff-onboarding.php';
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
        if (!isStaffOnboardingCompleteByEmail($pdo, $checkinEmail)) {
            $statusToken = trim((string) ($row['status_token'] ?? ''));
            if ($statusToken === '') {
                $resolved = resolveStatusTokenByEmail($pdo, $checkinEmail);
                $statusToken = $resolved ?? '';
            }
            if ($statusToken !== '') {
                $_SESSION['registration_status_message'] =
                    'Please update your PSA licence details at the top of your status page before checking in.';
                header('Location: status.php?token=' . urlencode($statusToken));
                exit;
            }
        }

        $window = getEventCheckinWindow($row);



        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            if (!$window['is_open']) {

                $message = formatCheckinWindowMessage($window);

                $type    = 'warning';

            } else {

                $result = recordCheckin($pdo, (int) $row['id'], 'self');

                if ($result === true) {

                    $message   = t('check_in_success', ['name' => $row['first_name']]);

                    $type      = 'success';

                    $checkedIn = true;

                    $row       = getRegistrationByToken($pdo, $token);

                } elseif ($result === 'Already checked in.') {

                    $message   = t('already_checked_in');

                    $type      = 'warning';

                    $checkedIn = true;

                } else {

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
    <?php renderStaffPublicHeader($pdo, $siteName, ['language_switcher' => true, 'home_url' => 'staff-app.php']); ?>

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

                <?php renderVenueMapBlock($row, $pdo); ?>



                <?php if (!$checkedIn && $window && $window['is_open']): ?>

                    <form method="post" class="form-grid login-form">

                        <div class="form-actions">

                            <button type="submit" class="btn btn--primary btn--block"><?= h(t('check_in_now')) ?></button>

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
    <script src="assets/js/signin-countdown.js"></script>

</body>

</html>


