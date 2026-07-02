<?php
require_once __DIR__ . '/config.php';
initSecureSession();
require_once __DIR__ . '/includes/staff-repository.php';
require_once __DIR__ . '/includes/staff-onboarding.php';
require_once __DIR__ . '/includes/staff-psa.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/staff-profile-gate.php';
require_once __DIR__ . '/includes/phone-numbers.php';
require_once __DIR__ . '/includes/components/phone-input.php';
require_once __DIR__ . '/includes/staff-messages.php';
require_once __DIR__ . '/includes/status-repository.php';
require_once __DIR__ . '/includes/status-change-post-save.php';

$pdo = getDB();
$defaultPhoneCountry = resolvePhoneCountryIsoFromRequest($pdo);
ensureStaffPsaSchema($pdo);
$token = trim((string) ($_GET['token'] ?? ''));
$staff = null;

if ($token !== '') {
    $staff = getStaffByProfileToken($pdo, $token);
    if ($staff === null) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Profile link invalid</title></head><body style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;">';
        echo '<h1>Invalid profile link</h1>';
        echo '<p>This link is invalid or has expired. Ask your coordinator to send a new profile update link.</p>';
        echo '<p><a href="staff-portal.php">Staff sign in</a></p></body></html>';
        exit;
    }
} else {
    $staff = getStaffFromPortalSession($pdo);
    if ($staff === null) {
        header('Location: staff-app.php');
        exit;
    }
}

if (isset($_GET['logout'])) {
    header('Location: staff-signout.php?return=' . urlencode(isStaffProfileUpdateRequired($pdo) ? 'staff-app.php' : 'staff-portal.php'));
    exit;
}

$profileComplete = !staffNeedsProfileForm($pdo, $staff);
$editMode        = isset($_GET['edit']) && $_GET['edit'] === '1';
$formOpen        = !$profileComplete
    || (isset($_GET['open']) && $_GET['open'] === '1')
    || $_SERVER['REQUEST_METHOD'] === 'POST';
$missingFields   = getStaffOnboardingMissingFields($staff);

if ($profileComplete && !$editMode && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff-app.php');
    exit;
}
$flash           = null;
$fieldErrors     = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
        $flash = [
            'type'    => 'error',
            'message' => 'Your session expired. Please try again.',
        ];
    } else {
    prepareMobileFromRequest($_POST);
    $validationErrors = validateStaffOnboardingPost($_POST, $staff, $_FILES);
    if ($validationErrors !== []) {
        $fieldErrors = $validationErrors;
        $flash = [
            'type'    => 'error',
            'message' => $validationErrors['form'] ?? reset($validationErrors),
        ];
    } else {
    try {
        $updateData = [
            'surname' => trim((string) ($_POST['surname'] ?? '')),
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'full_address' => trim((string) ($_POST['full_address'] ?? '')),
            'eircode' => trim((string) ($_POST['eircode'] ?? '')),
            'mobile' => trim((string) ($_POST['mobile'] ?? '')),
            'gender' => trim((string) ($_POST['gender'] ?? 'prefer_not_to_say')),
            'pps_number' => trim((string) ($_POST['pps_number'] ?? '')),
            'bank_iban' => trim((string) ($_POST['bank_iban'] ?? '')),
            'psa_licence' => trim((string) ($_POST['psa_licence'] ?? '')),
            'psa_expiry_date' => trim((string) ($_POST['psa_expiry_date'] ?? '')),
        ];

        if (trim((string) ($staff['date_of_birth'] ?? '')) === '' && !empty($_POST['date_of_birth'])) {
            $updateData['date_of_birth'] = trim((string) $_POST['date_of_birth']);
        }

        if (!empty($_POST['location_lat'])) {
            $updateData['location_lat'] = (float) $_POST['location_lat'];
        }
        if (!empty($_POST['location_lng'])) {
            $updateData['location_lng'] = (float) $_POST['location_lng'];
        }

        $psaUpload = processStaffPsaFileUploadsWithErrors((int) $staff['id'], $_FILES);
        if ($psaUpload['errors'] !== []) {
            $fieldErrors = $psaUpload['errors'];
            $flash = [
                'type'    => 'error',
                'message' => reset($psaUpload['errors']) ?: 'Could not save PSA photos.',
            ];
            throw new RuntimeException('PSA photo upload failed');
        }
        $updateData = array_merge($updateData, $psaUpload['paths']);

        $wasCompleteBefore = (int) ($staff['profile_completed'] ?? 0) === 1;

        if (updateStaffProfile($pdo, (int) $staff['id'], $updateData)) {
            $staff = getStaffById($pdo, (int) $staff['id']) ?? $staff;
            if (isStaffOnboardingComplete($staff)) {
                markStaffProfileCompleted($pdo, (int) $staff['id'], false);
                $staff = getStaffById($pdo, (int) $staff['id']) ?? $staff;
                $profileComplete = true;
                $missingFields   = [];

                if (!staffNeedsProfileForm($pdo, $staff)) {
                    $return = trim((string) ($_SESSION['staff_profile_return'] ?? 'staff-app.php'));
                    unset($_SESSION['staff_profile_return']);
                    if ($return === '' || str_contains($return, 'staff-profile.php')) {
                        $return = 'staff-app.php';
                    }
                    if (!$wasCompleteBefore) {
                        flushHttpResponse($return);
                        runProfileCompletionPostJobs($pdo, (int) $staff['id']);
                        exit;
                    }
                    header('Location: ' . $return);
                    exit;
                }

                $flash = [
                    'type'    => 'success',
                    'message' => 'Your profile is complete. You can register for events, view status, and check in when approved.',
                ];
            } else {
                $missingFields = getStaffOnboardingMissingFields($staff);
                $flash = [
                    'type'    => 'warning',
                    'message' => 'Saved. Still required: ' . implode(', ', $missingFields),
                ];
            }
        } else {
            $flash = ['type' => 'error', 'message' => 'Failed to update profile. Please try again.'];
        }
    } catch (RuntimeException $e) {
        if ($flash === null) {
            $flash = ['type' => 'error', 'message' => $e->getMessage()];
        }
    } catch (Exception $e) {
        $flash = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
    }
    }
    }
}

$siteName = getSiteName($pdo);
require_once __DIR__ . '/includes/staff-portal-dashboard.php';

$staffEmail      = strtolower(trim((string) ($staff['email'] ?? '')));
$profileMetrics  = getStaffPortalDashboardMetrics($pdo, $staffEmail);
$profileStatus   = getStaffPortalStatusBadge($staff, $profileMetrics);
$profileRole     = getStaffPortalRoleLabel($pdo, $staff, $staffEmail);
$profileStaffId  = formatStaffPortalStaffId($staff);
$profileAvatar   = getStaffPortalAvatarInitials($staff);
$profileFullName = getStaffPortalDisplayName($staff, $pdo);
$staffStatusToken = $staffEmail !== '' ? (resolveStatusTokenByEmail($pdo, $staffEmail) ?? '') : '';
$messagesPageUrl  = $staffStatusToken !== ''
    ? 'staff-messages.php?token=' . urlencode($staffStatusToken)
    : 'staff-messages.php';
$staffMsgUnread   = $staffEmail !== '' ? countUnreadAdminRepliesForStaff($pdo, $staffEmail) : 0;

require_once __DIR__ . '/includes/staff-app-v3-pages.php';

$portalStaffForCtx = $token === '' ? $staff : null;
$ctx = buildStaffV3Context($pdo, $portalStaffForCtx);
$ctx['load_profile_form_js'] = true;

$showNav = $token === '' && $portalStaffForCtx !== null;
renderStaffV3PageStart($ctx, 'profile', 'Profile', $showNav);
renderStaffV3ProfileEditPage($ctx, [
    'staff'                  => $staff,
    'token'                  => $token,
    'profile_complete'       => $profileComplete,
    'edit_mode'              => $editMode,
    'form_open'              => $formOpen,
    'missing_fields'         => $missingFields,
    'flash'                  => $flash,
    'field_errors'           => $fieldErrors,
    'profile_metrics'        => $profileMetrics,
    'profile_status'         => $profileStatus,
    'profile_role'           => $profileRole,
    'profile_staff_id'       => $profileStaffId,
    'profile_avatar'         => $profileAvatar,
    'profile_full_name'      => $profileFullName,
    'messages_page_url'      => $messagesPageUrl,
    'staff_msg_unread'       => $staffMsgUnread,
    'default_phone_country'  => $defaultPhoneCountry,
]);
renderStaffV3PageEnd($ctx, $showNav);
