<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
initSecureSession();

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/staff-portal-session.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileEventsService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: ../staff-apply-shifts.php?error=' . urlencode('Invalid request.'));
    exit;
}

if (!verifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: ../staff-apply-shifts.php?error=' . urlencode('Your session expired. Refresh the page and try again.'));
    exit;
}

try {
    $pdo   = getDB();
    $staff = getStaffFromPortalSession($pdo);
    if ($staff === null) {
        header('Location: ../staff-app.php');
        exit;
    }

    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($eventId < 1) {
        header('Location: ../staff-apply-shifts.php?error=' . urlencode('Please select an event.'));
        exit;
    }

    $result = mobileEventsServiceRegister($pdo, $staff, ['event_ids' => [$eventId]]);

    if (empty($result['ok'])) {
        $message = (string) ($result['message'] ?? 'Could not apply for this shift.');
        header('Location: ../staff-apply-shifts.php?error=' . urlencode($message));
        exit;
    }

    header('Location: ../staff-shifts.php?applied=1');
    exit;
} catch (Throwable $e) {
    error_log('[EventStaff] staff-apply-events: ' . $e->getMessage());
    header('Location: ../staff-apply-shifts.php?error=' . urlencode('Could not apply. Please try again.'));
    exit;
}
