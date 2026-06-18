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
require_once __DIR__ . '/includes/public/staff-public-shell.php';
require_once __DIR__ . '/includes/theme.php';
$themeColor = getThemeColor($pdo);

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
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Staff Profile | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <link rel="stylesheet" href="assets/css/staff-status-dashboard.css">
    <link rel="stylesheet" href="assets/css/staff-profile-v2.css">
</head>
<body class="staff-public-shell staff-public-shell--event-ops staff-public-shell--narrow login-page staff-mobile-page staff-profile-shell" <?= renderStaffPortalBodyAttributes($token === '' ? $staff : null, $pdo) ?>>
    <?php renderStaffPublicBackground(true); ?>
    <?php renderStaffPublicHeader($pdo, $siteName, ['home_url' => 'staff-app.php', 'portal_staff' => $token === '' ? $staff : null]); ?>

    <main class="login-page__wrap staff-public-main">
        <section class="card login-card staff-public-card staff-public-card--profile">
            <div class="card__header staff-profile-card__header">
                <div>
                    <h1 class="card__title">My profile</h1>
                    <p class="card__subtitle">Account &amp; personal details</p>
                </div>
                <?php if ($editMode): ?>
                    <a href="staff-app.php" class="btn btn--small btn--secondary">← Back to app</a>
                <?php endif; ?>
            </div>

            <div class="staff-profile-hero">
                <div class="staff-profile-hero__avatar" aria-hidden="true"><?= h($profileAvatar) ?></div>
                <div class="staff-profile-hero__body">
                    <h2 class="staff-profile-hero__name"><?= h($profileFullName) ?></h2>
                    <p class="staff-profile-hero__role"><?= h($profileRole) ?></p>
                    <div class="staff-profile-hero__badges">
                        <?php if ($profileStaffId !== ''): ?>
                            <span class="staff-profile-hero__id"><?= h($profileStaffId) ?></span>
                        <?php endif; ?>
                        <span class="staff-profile-hero__status staff-profile-hero__status--<?= h($profileStatus['tone']) ?>"><?= h($profileStatus['label']) ?></span>
                    </div>
                </div>
            </div>

            <section class="staff-profile-account" aria-label="Account details">
                <h3 class="staff-profile-account__title">Account details</h3>
                <dl class="staff-profile-account__list">
                    <div><dt>Email</dt><dd><?= h((string) $staff['email']) ?></dd></div>
                    <div><dt>Mobile</dt><dd><?= h(trim((string) ($staff['mobile'] ?? '')) !== '' ? (string) $staff['mobile'] : '—') ?></dd></div>
                    <div><dt>Registration</dt><dd><?= $profileComplete ? 'Profile complete' : 'Profile incomplete' ?></dd></div>
                    <?php if ($profileMetrics['has_data']): ?>
                        <div><dt>Applications</dt><dd><?= (int) $profileMetrics['total'] ?> total · <?= (int) $profileMetrics['approved'] ?> approved</dd></div>
                    <?php endif; ?>
                </dl>
            </section>

            <?php if ($staffEmail !== ''): ?>
                <section class="staff-profile-quick-links" aria-label="Communication">
                    <h3 class="staff-profile-account__title">Messages</h3>
                    <p class="form-hint">View replies from your coordinator and send updates about shifts.</p>
                    <a href="<?= h($messagesPageUrl) ?>" class="btn btn--primary btn--block staff-profile-quick-links__btn">
                        Open messages<?= $staffMsgUnread > 0 ? ' (' . (int) $staffMsgUnread . ' unread)' : '' ?>
                    </a>
                </section>
            <?php endif; ?>

            <?php if (!$profileComplete): ?>
                <div class="alert alert--error alert--visible staff-profile-alert">
                    <strong>Profile incomplete</strong>
                    <p>Complete all fields before you can view registration status or check in.</p>
                    <?php if ($missingFields !== []): ?>
                        <p class="staff-profile-alert__missing"><strong>Still needed:</strong> <?= h(implode(', ', $missingFields)) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="alert alert--<?= h($flash['type']) ?> alert--visible staff-profile-alert">
                    <?= h($flash['message']) ?>
                </div>
            <?php endif; ?>

            <?php if ($editMode && $profileComplete && !$formOpen): ?>
                <section class="staff-profile-summary" aria-label="Profile summary">
                    <h3 class="staff-profile-summary__heading">Personal details</h3>
                    <dl class="staff-profile-summary__list">
                        <div><dt>Address</dt><dd><?= h((string) ($staff['full_address'] ?? '—')) ?></dd></div>
                        <div><dt>PSA licence</dt><dd><?= h((string) ($staff['psa_licence'] ?? '—')) ?></dd></div>
                    </dl>
                    <a href="staff-profile.php?edit=1&amp;open=1" class="btn btn--primary btn--block staff-profile-summary__edit">Edit my details</a>
                </section>
            <?php else: ?>

            <form method="post" enctype="multipart/form-data" class="form-grid staff-profile-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <h3 class="form-section-title form-group--full">Personal information</h3>

                <div class="form-group">
                    <label class="form-label">First name</label>
                    <input type="text" name="first_name" class="form-input" value="<?= h((string) $staff['first_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Last name</label>
                    <input type="text" name="surname" class="form-input" value="<?= h((string) $staff['surname']) ?>" required>
                </div>

                <div class="form-group form-group--full">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-input" value="<?= h((string) $staff['email']) ?>" disabled>
                    <p class="form-hint">Cannot be changed — used when you sign in.</p>
                </div>

                <div class="form-group">
                    <label class="form-label" for="mobile_national">Mobile</label>
                    <?php renderPhoneInputField([
                        'id'         => 'mobile',
                        'value'      => (string) ($staff['mobile'] ?? ''),
                        'defaultIso' => $defaultPhoneCountry,
                        'required'   => true,
                    ]); ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Date of birth</label>
                    <?php if (trim((string) ($staff['date_of_birth'] ?? '')) === ''): ?>
                        <input type="date" name="date_of_birth" class="form-input" required>
                    <?php else: ?>
                        <input type="date" class="form-input" value="<?= h((string) $staff['date_of_birth']) ?>" disabled>
                        <p class="form-hint">Locked after first save.</p>
                    <?php endif; ?>
                </div>

                <div class="form-group form-group--full">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select" required>
                        <option value="male" <?= $staff['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= $staff['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="other" <?= $staff['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                        <option value="prefer_not_to_say" <?= $staff['gender'] === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
                    </select>
                </div>

                <h3 class="form-section-title form-group--full">Address</h3>

                <div class="form-group form-group--full">
                    <label class="form-label">Full address</label>
                    <textarea name="full_address" class="form-input" rows="3" required><?= h((string) $staff['full_address']) ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Eircode</label>
                    <input type="text" name="eircode" class="form-input" value="<?= h((string) $staff['eircode']) ?>" required>
                </div>

                <p class="form-hint form-group--full" style="margin:0 0 0.5rem;padding:0.75rem 1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;color:#166534;">
                    <strong>Signed in securely.</strong> Payroll details below are only shown on this official profile page after you sign in — never on the public sign-in screen.
                </p>

                <h3 class="form-section-title form-group--full">Payroll details</h3>

                <div class="form-group">
                    <label class="form-label">PPS number</label>
                    <input type="text" name="pps_number" class="form-input" value="<?= h((string) $staff['pps_number']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Bank IBAN</label>
                    <input type="text" name="bank_iban" id="bank_iban" class="form-input" value="<?= h((string) $staff['bank_iban']) ?>" placeholder="IE29AIBK93115212345678" autocomplete="off" autocapitalize="characters" maxlength="34" required>
                    <p class="form-hint">IBAN with country code only — not a bank name.</p>
                </div>

                <h3 class="form-section-title form-group--full">PSA licence <span class="staff-profile-badge">Required</span></h3>

                <div class="form-group">
                    <label class="form-label">PSA licence number</label>
                    <input type="text" name="psa_licence" id="psa_licence" class="form-input" value="<?= h((string) ($staff['psa_licence'] ?? '')) ?>" placeholder="EM123456/00" autocomplete="off" autocapitalize="characters" pattern="EM[0-9]{6}/[0-9]{2}" required>
                    <p class="form-hint">Format EM123456/00 as on your PSA card.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">PSA expiry date</label>
                    <input type="date" name="psa_expiry_date" class="form-input" value="<?= h((string) ($staff['psa_expiry_date'] ?? '')) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">PSA card — front photo</label>
                    <input type="file" name="psa_front_image" class="form-input form-input--file" accept="<?= h(psaImageFileAcceptAttribute()) ?>" <?= empty($staff['psa_front_image']) ? 'required' : '' ?>>
                    <p class="form-hint">JPG, PNG, or photo from your phone (max 8 MB).</p>
                    <?php if (isStoredPsaImagePath($staff['psa_front_image'] ?? null)): ?>
                        <?php $profilePsaFrontUrl = psaImagePublicUrl((string) $staff['psa_front_image'], $pdo); ?>
                        <p class="form-hint"><a href="<?= h($profilePsaFrontUrl) ?>" target="_blank" rel="noopener">View current image</a></p>
                    <?php endif; ?>
                    <?php if (!empty($fieldErrors['psa_front_image'])): ?>
                        <span class="form-error form-error--visible"><?= h($fieldErrors['psa_front_image']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">PSA card — back photo</label>
                    <input type="file" name="psa_back_image" class="form-input form-input--file" accept="<?= h(psaImageFileAcceptAttribute()) ?>" <?= empty($staff['psa_back_image']) ? 'required' : '' ?>>
                    <p class="form-hint">JPG, PNG, or photo from your phone (max 8 MB).</p>
                    <?php if (isStoredPsaImagePath($staff['psa_back_image'] ?? null)): ?>
                        <?php $profilePsaBackUrl = psaImagePublicUrl((string) $staff['psa_back_image'], $pdo); ?>
                        <p class="form-hint"><a href="<?= h($profilePsaBackUrl) ?>" target="_blank" rel="noopener">View current image</a></p>
                    <?php endif; ?>
                    <?php if (!empty($fieldErrors['psa_back_image'])): ?>
                        <span class="form-error form-error--visible"><?= h($fieldErrors['psa_back_image']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group form-group--full form-actions staff-profile-form__submit">
                    <button type="submit" class="btn btn--primary btn--block">Save changes</button>
                    <?php if ($editMode && $profileComplete): ?>
                        <a href="staff-profile.php?edit=1" class="btn btn--secondary btn--block">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php endif; ?>

            <?php if ($token === ''): ?>
                <div class="staff-profile-signout">
                    <a href="<?= h(staffPortalSignOutUrl('staff-app.php?signed_out=1')) ?>"
                       class="staff-profile-signout__btn"
                       id="staff-profile-signout-btn"
                       aria-label="Sign out of your staff account">
                        <span class="staff-profile-signout__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                        </span>
                        Sign out
                    </a>
                </div>
            <?php endif; ?>

            <p class="login-card__hint">
                <a href="staff-app.php">← Staff app home</a>
                <?php if ($staffEmail !== ''): ?>
                    · <a href="<?= h($messagesPageUrl) ?>">Messages<?= $staffMsgUnread > 0 ? ' (' . (int) $staffMsgUnread . ')' : '' ?></a>
                <?php endif; ?>
            </p>
        </section>
    </main>
<?php
$assetBase = '';
$finJsPath = __DIR__ . '/assets/js/financial-field-validation.js';
$finJsVer  = is_file($finJsPath) ? (string) filemtime($finJsPath) : '1';
?>
<script src="assets/js/financial-field-validation.js?v=<?= h($finJsVer) ?>"></script>
<?php
$phoneJsPath = __DIR__ . '/assets/js/phone-input.js';
$phoneJsVer  = is_file($phoneJsPath) ? (string) filemtime($phoneJsPath) : '1';
?>
<script src="assets/js/phone-input.js?v=<?= h($phoneJsVer) ?>"></script>
<script>
(function () {
    var btn = document.getElementById('staff-profile-signout-btn');
    if (!btn) return;
    btn.addEventListener('click', function (e) {
        if (!window.confirm('Sign out of your staff account?')) {
            e.preventDefault();
        }
    });
})();
</script>
<?php if ($token === '') {
    renderStaffPortalSessionIdleScript($pdo, $token === '' ? $staff : null);
} ?>
</body>
</html>
