<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-allocation.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/status-change-post-save.php';
require_once __DIR__ . '/../includes/attendance-repository.php';

requireAdminCapability('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: allocation-centre.php');
    exit;
}

$pdo    = getDB();
ensureStaffAllocationSchema($pdo);
$action = trim((string) ($_POST['action'] ?? ''));
$reason = trim((string) ($_POST['reason'] ?? ''));

$redirect = 'allocation-centre.php';
$eventIdFilter = (int) ($_POST['return_event_id'] ?? $_GET['event_id'] ?? 0);
if ($eventIdFilter > 0) {
    $redirect .= '?event_id=' . $eventIdFilter;
}

switch ($action) {
    case 'assign':
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $result  = adminAssignStaffToEvent(
            $pdo,
            $staffId,
            $eventId,
            $reason,
            !empty($_POST['confirm_duplicate']),
            !empty($_POST['confirm_same_day'])
        );
        if ($result['ok'] ?? false) {
            setAdminFlash('success', 'Staff assigned to shift successfully.');
            header('Location: view-staff.php?id=' . (int) ($result['registration_id'] ?? 0));
            exit;
        }
        setAdminFlash('error', (string) ($result['error'] ?? 'Assignment failed.'));
        break;

    case 'move':
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $eventId        = (int) ($_POST['event_id'] ?? 0);
        $result         = adminMoveStaffAssignment(
            $pdo,
            $registrationId,
            $eventId,
            $reason,
            !empty($_POST['confirm_duplicate']),
            !empty($_POST['confirm_same_day'])
        );
        if ($result['ok'] ?? false) {
            setAdminFlash('success', 'Assignment moved successfully.');
            header('Location: view-staff.php?id=' . $registrationId);
            exit;
        }
        setAdminFlash('error', (string) ($result['error'] ?? 'Move failed.'));
        break;

    case 'remove':
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $result         = adminRemoveStaffAssignment($pdo, $registrationId, $reason);
        if ($result['ok'] ?? false) {
            setAdminFlash('success', 'Assignment removed (registration rejected).');
            header('Location: view-staff.php?id=' . $registrationId);
            exit;
        }
        setAdminFlash('error', (string) ($result['error'] ?? 'Remove failed.'));
        break;

    case 'allocate_waitlist':
        $waitlistId = (int) ($_POST['waitlist_id'] ?? 0);
        $eventId    = (int) ($_POST['event_id'] ?? 0);
        $result     = adminAllocateWaitlistEntry($pdo, $waitlistId, $eventId, $reason);
        if ($result['ok'] ?? false) {
            setAdminFlash('success', 'Waiting list staff allocated to shift.');
            header('Location: view-staff.php?id=' . (int) ($result['registration_id'] ?? 0));
            exit;
        }
        setAdminFlash('error', (string) ($result['error'] ?? 'Allocation failed.'));
        break;

    case 'bulk_allocate_waitlist':
        $eventId     = (int) ($_POST['event_id'] ?? 0);
        $waitlistIds = $_POST['waitlist_ids'] ?? [];
        if (!is_array($waitlistIds)) {
            $waitlistIds = [];
        }
        $waitlistIds = array_values(array_filter(array_map('intval', $waitlistIds), static fn (int $id): bool => $id > 0));
        if ($waitlistIds === [] || $eventId < 1 || $reason === '') {
            setAdminFlash('error', 'Select waiting list entries, event, and reason.');
            break;
        }
        $ok = 0;
        $fail = 0;
        foreach ($waitlistIds as $waitlistId) {
            $result = adminAllocateWaitlistEntry($pdo, $waitlistId, $eventId, $reason);
            if ($result['ok'] ?? false) {
                $ok++;
            } else {
                $fail++;
            }
        }
        logStaffShiftAssignment($pdo, 'bulk_allocate_waitlist', [
            'to_event_id' => $eventId,
            'reason'      => $reason,
            'details'     => 'Allocated ' . $ok . ', failed ' . $fail,
        ]);
        setAdminFlash($fail > 0 ? 'warning' : 'success', 'Bulk allocate: ' . $ok . ' succeeded' . ($fail > 0 ? ', ' . $fail . ' failed' : '') . '.');
        break;

    case 'bulk_approve':
        $ids = $_POST['registration_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        $updated = 0;
        $approvedIds = [];
        foreach ($ids as $registrationId) {
            if (updateStaffStatus($pdo, $registrationId, 'approved', true)) {
                $updated++;
                $approvedIds[] = $registrationId;
                try {
                    ensureCheckinToken($pdo, $registrationId);
                } catch (Throwable $e) {
                    error_log('[EventStaff] bulk approve checkin token: ' . $e->getMessage());
                }
            }
        }
        logStaffShiftAssignment($pdo, 'bulk_approve', [
            'reason'  => $reason !== '' ? $reason : 'Bulk approve from allocation centre',
            'details' => 'Approved ' . $updated . ' registration(s)',
        ]);
        $flashMsg = 'Approved ' . $updated . ' registration(s).';
        if ($updated > 0) {
            $flashMsg .= ' Emails and Google Sheets sync run in the background.';
        }
        setAdminFlash('success', $flashMsg);
        if ($updated > 0 && $approvedIds !== []) {
            flushHttpResponse($redirect);
            runBulkStatusChangePostJobs($pdo, $approvedIds, 'approved');
            exit;
        }
        break;

    default:
        setAdminFlash('error', 'Unknown action.');
}

header('Location: ' . $redirect);
exit;
