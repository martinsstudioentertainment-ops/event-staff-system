<?php
require_once __DIR__ . '/config.php';
initSecureSession();
require_once __DIR__ . '/includes/staff-repository.php';
require_once __DIR__ . '/includes/staff-onboarding.php';
require_once __DIR__ . '/includes/staff-psa.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/staff-profile-gate.php';
require_once __DIR__ . '/includes/phone-numbers.php';
require_once __DIR__ . '/includes/components/phone-input.php';

$pdo = getDB();
$defaultPhoneCountry = resolvePhoneCountryIsoFromRequest($pdo);
ensureStaffPsaSchema($pdo);
$token = trim((string) ($_GET['token'] ?? ''));
$staff = null;

if ($token !== '') {
    $staff = getStaffByProfileToken($pdo, $token);
    if ($staff === null) {
        die('Invalid profile link. Ask your coordinator to send a new profile update link.');
    }
} else {
    $staff = getStaffFromPortalSession($pdo);
    if ($staff === null) {
        header('Location: staff-portal.php');
        exit;
    }
}

if (isset($_GET['logout'])) {
    clearStaffPortalSession();
    header('Location: ' . (isStaffProfileUpdateRequired($pdo) ? 'staff-app.php' : 'staff-portal.php'));
    exit;
}

$profileComplete = !staffNeedsProfileForm($pdo, $staff);
$missingFields   = getStaffOnboardingMissingFields($staff);
$flash           = null;
$fieldErrors     = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        if (updateStaffProfile($pdo, (int) $staff['id'], $updateData)) {
            $staff = getStaffById($pdo, (int) $staff['id']) ?? $staff;
            if (isStaffOnboardingComplete($staff)) {
                markStaffProfileCompleted($pdo, (int) $staff['id']);
                $staff = getStaffById($pdo, (int) $staff['id']) ?? $staff;
                $profileComplete = true;
                $missingFields   = [];

                if (!staffNeedsProfileForm($pdo, $staff)) {
                    $return = trim((string) ($_SESSION['staff_profile_return'] ?? 'staff-app.php'));
                    unset($_SESSION['staff_profile_return']);
                    if ($return === '' || str_contains($return, 'staff-profile.php')) {
                        $return = 'staff-app.php';
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

$siteName = getSiteName($pdo);
require_once __DIR__ . '/includes/public/staff-public-shell.php';
require_once __DIR__ . '/includes/theme.php';
$themeColor = getThemeColor($pdo);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Staff Profile | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
</head>
<body class="staff-public-shell staff-public-shell--event-ops staff-public-shell--narrow login-page staff-mobile-page staff-profile-shell">
    <?php renderStaffPublicBackground(true); ?>
    <?php renderStaffPublicHeader($pdo, $siteName, ['home_url' => 'staff-app.php']); ?>

    <main class="login-page__wrap staff-public-main">
        <section class="card login-card staff-public-card staff-public-card--profile">
            <div class="card__header staff-profile-card__header">
                <div>
                    <h1 class="card__title">Staff profile</h1>
                    <p class="card__subtitle"><?= h((string) $staff['email']) ?></p>
                </div>
                <a href="staff-profile.php?logout=1" class="btn btn--small btn--secondary">Sign out</a>
            </div>

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

            <form method="post" enctype="multipart/form-data" class="form-grid staff-profile-form">
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

                <h3 class="form-section-title form-group--full">Financial information</h3>

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
                    <?php if (!empty($staff['psa_front_image'])): ?>
                        <p class="form-hint"><a href="<?= h($staff['psa_front_image']) ?>" target="_blank" rel="noopener">View current image</a></p>
                    <?php endif; ?>
                    <?php if (!empty($fieldErrors['psa_front_image'])): ?>
                        <span class="form-error form-error--visible"><?= h($fieldErrors['psa_front_image']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">PSA card — back photo</label>
                    <input type="file" name="psa_back_image" class="form-input form-input--file" accept="<?= h(psaImageFileAcceptAttribute()) ?>" <?= empty($staff['psa_back_image']) ? 'required' : '' ?>>
                    <p class="form-hint">JPG, PNG, or photo from your phone (max 8 MB).</p>
                    <?php if (!empty($staff['psa_back_image'])): ?>
                        <p class="form-hint"><a href="<?= h($staff['psa_back_image']) ?>" target="_blank" rel="noopener">View current image</a></p>
                    <?php endif; ?>
                    <?php if (!empty($fieldErrors['psa_back_image'])): ?>
                        <span class="form-error form-error--visible"><?= h($fieldErrors['psa_back_image']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group form-group--full form-actions staff-profile-form__submit">
                    <button type="submit" class="btn btn--primary btn--block">Save changes</button>
                </div>
            </form>

            <p class="login-card__hint"><a href="staff-app.php">← Staff app home</a></p>
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
</body>
</html>
