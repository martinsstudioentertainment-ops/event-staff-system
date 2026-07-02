<?php
require_once __DIR__ . '/config.php';
initSecureSession();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/staff-repository.php';
require_once __DIR__ . '/includes/attendance-repository.php';
require_once __DIR__ . '/includes/status-repository.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/system-settings.php';
require_once __DIR__ . '/includes/staff-psa.php';
require_once __DIR__ . '/includes/staff-profile-gate.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/notification-center.php';
require_once __DIR__ . '/includes/staff-portal-dashboard.php';

$pdo      = getDB();
require_once __DIR__ . '/includes/staff-registration-schema.php';
ensureStaffRegistrationSaveSchema($pdo);
$siteName = getSiteName($pdo);
$token    = trim((string) ($_GET['token'] ?? ''));
$rows        = [];
$error       = '';
$successMsg  = '';
$showLookup  = false;
$staffRecord = null;
$psaErrors   = [];

if (!empty($_SESSION['registration_status_message'])) {
    $successMsg = (string) $_SESSION['registration_status_message'];
    unset($_SESSION['registration_status_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status_psa_update'])) {
    $token = trim((string) ($_POST['status_token'] ?? $_GET['token'] ?? ''));
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
        $showLookup = $token === '';
    } elseif ($token === '') {
        $error = 'Invalid status link.';
        $showLookup = true;
    } else {
        $rows = getStaffStatusRows($pdo, $token);
        if ($rows === []) {
            $error = 'Status link not found or expired.';
            $showLookup = true;
            $token = '';
        } else {
            $staffId = ensureStaffRecordForEmail($pdo, (string) ($rows[0]['email'] ?? ''));
            $staffRecord = $staffId !== null ? getStaffById($pdo, $staffId) : null;
            $psaErrors = staffContextRequiresPsa($staffRecord, $rows)
                ? validateRegistrationPsa($_POST, $staffRecord, $_FILES, $rows)
                : [];
            if ($psaErrors === [] && $staffId !== null) {
                ensureStaffPsaSchema($pdo);
                $saveErrors = saveStaffPsaFromForm($pdo, $staffId, $_POST, $_FILES);
                if ($saveErrors !== []) {
                    $psaErrors = array_merge($psaErrors, $saveErrors);
                } else {
                    $_SESSION['registration_status_message'] = 'PSA details saved.';
                    header('Location: status.php?token=' . urlencode($token));
                    exit;
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status_lookup'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
        $showLookup = true;
    } else {
        $emailInput = trim((string) ($_POST['email'] ?? ''));
        $linkInput  = trim((string) ($_POST['status_link'] ?? ''));
        $parsed     = parseStatusTokenFromInput($linkInput);

        if ($parsed !== '') {
            header('Location: status.php?token=' . urlencode($parsed));
            exit;
        }

        $resolved = resolveStatusTokenByEmail($pdo, $emailInput);
        if ($resolved !== null) {
            header('Location: status.php?token=' . urlencode($resolved));
            exit;
        }

        $error = 'No registration found for that email. Register first or paste your full status link from email.';
        $showLookup = true;
    }
} elseif ($token === '') {
    $showLookup = true;
} else {
    $rows = getStaffStatusRows($pdo, $token);
    if ($rows === []) {
        $error = 'Status link not found or expired. Try your email below or use a fresh link from email.';
        $showLookup = true;
        $token = '';
    } else {
        $staffId = ensureStaffRecordForEmail($pdo, (string) ($rows[0]['email'] ?? ''));
        if ($staffId !== null) {
            $staffRecord = getStaffById($pdo, $staffId);
        }

        if ($staffRecord !== null && staffNeedsProfileForm($pdo, $staffRecord)) {
            require_once __DIR__ . '/includes/staff-portal-remember.php';
            establishStaffPortalSessionWithRemember($pdo, $staffRecord);
            $_SESSION['staff_profile_return'] = 'status.php?token=' . urlencode($token);
            header('Location: staff-profile.php');
            exit;
        }
    }
}

enforceStaffProfileGate($pdo, ['staff-profile.php', 'staff-portal.php', 'status.php']);

$assetBase      = '';
$portalStaff    = getStaffFromPortalSession($pdo);
$statusFilter   = strtolower(trim((string) ($_GET['filter'] ?? '')));
if ($statusFilter === 'all' || $statusFilter === 'total') {
    $statusFilter = '';
}
$statusMetrics  = [];
$displayRows    = $rows;
if ($rows !== []) {
    $statusMetrics = computeStaffStatusMetricsFromRows($rows);
    $displayRows   = filterStaffStatusRows($rows, $statusFilter);
}
$profilePageUrl = $portalStaff !== null ? 'staff-profile.php?edit=1' : 'staff-portal.php';

require_once __DIR__ . '/includes/staff-app-v3-pages.php';

$ctx = buildStaffV3Context($pdo, $portalStaff);
$ctx['body_class'] = 'es-v3--status-page';
if ($token !== '' && ($ctx['status_token'] ?? '') === '') {
    $ctx['status_token'] = $token;
}
if (!$showLookup && $rows !== []) {
    $ctx['pwa_push_registration_id'] = (int) ($rows[0]['id'] ?? 0);
}

$showNav = $portalStaff !== null;
renderStaffV3PageStart($ctx, 'home', 'Application status', $showNav);
renderStaffV3StatusPage($ctx, [
    'token'            => $token,
    'rows'             => $rows,
    'error'            => $error,
    'success_msg'      => $successMsg,
    'show_lookup'      => $showLookup,
    'staff_record'     => $staffRecord,
    'status_filter'    => $statusFilter,
    'status_metrics'   => $statusMetrics,
    'display_rows'     => $displayRows,
    'profile_page_url' => $profilePageUrl,
]);
renderStaffV3PageEnd($ctx, $showNav);
