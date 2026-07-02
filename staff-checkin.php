<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/staff-app-v3-pages.php';
require_once __DIR__ . '/includes/staff-venue-checkin.php';

try {
    $pdo         = getDB();
    $portalStaff = staffV3RequireSignIn($pdo);
    $ctx         = buildStaffV3Context($pdo, $portalStaff);
    $todayShift  = $ctx['today_shift'] ?? null;

    $checkinFlash = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_app_checkin'])) {
        if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
            $checkinFlash = ['ok' => false, 'message' => 'Session expired. Please try again.', 'type' => 'error'];
        } else {
            $checkinFlash = processStaffAppVenueCheckin($pdo, $portalStaff, $_POST, is_array($todayShift) ? $todayShift : null);
            if (!empty($checkinFlash['ok'])) {
                header('Location: staff-checkin.php?checked_in=1');
                exit;
            }
        }
    }

    if (isset($_GET['checked_in']) || isset($_GET['done'])) {
        $checkinFlash = [
            'ok'      => true,
            'message' => 'Check-in successful! Stay signed in on this phone during your shift.',
            'type'    => 'success',
        ];
    }

    $ctx['checkin_flash'] = $checkinFlash;
    $ctx['today_registration'] = getStaffTodayApprovedRegistration(
        $pdo,
        (string) ($portalStaff['email'] ?? ''),
        $portalStaff,
        is_array($todayShift) ? $todayShift : null
    );

    renderStaffV3PageStart($ctx, 'checkin', 'Check In');
    renderStaffV3CheckinPage($ctx);
    renderStaffV3PageEnd($ctx);
} catch (Throwable $e) {
    error_log('[EventStaff] staff-checkin.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
    }

    require_once __DIR__ . '/includes/staff-app-v3-public.php';
    $siteName = 'Olasentra';
    try {
        $siteName = getSiteName(getDB());
    } catch (Throwable $ignored) {
    }

    renderStaffV3ErrorScreen(
        'Check-in temporarily unavailable',
        'We could not complete check-in just now. Allow location, enter your BIB number, and try again. If it keeps failing, ask your supervisor to scan you in at the desk.',
        [
            ['label' => 'Try again', 'href' => 'staff-checkin.php', 'primary' => true],
            ['label' => 'Staff home', 'href' => 'staff-app.php'],
        ],
        $siteName
    );
}
