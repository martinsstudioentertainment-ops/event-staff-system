<?php

/**
 * Force existing staff to complete / refresh profile (mobile app + portal).
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/staff-onboarding.php';
require_once __DIR__ . '/staff-portal-session.php';
require_once __DIR__ . '/staff-psa.php';

function isStaffProfileUpdateRequired(PDO $pdo): bool
{
    return getSetting($pdo, 'staff_profile_update_required', '0') === '1';
}

/**
 * @param array<string, mixed>|null $staff
 */
function staffMustUpdateProfile(PDO $pdo, ?array $staff): bool
{
    if ($staff === null || !isStaffProfileUpdateRequired($pdo)) {
        return false;
    }

    if (!isStaffOnboardingComplete($staff)) {
        return true;
    }

    $refreshAt = trim(getSetting($pdo, 'staff_profile_refresh_at', ''));
    if ($refreshAt === '') {
        return false;
    }

    if ((int) ($staff['profile_completed'] ?? 0) !== 1) {
        return true;
    }

    $updatedTs = strtotime((string) ($staff['updated_at'] ?? ''));
    $refreshTs = strtotime($refreshAt);

    return $refreshTs > 0 && ($updatedTs === false || $updatedTs < $refreshTs);
}

/**
 * Turn on mandatory profile update for all staff (admin action).
 */
function activateStaffProfileUpdateRequired(PDO $pdo): int
{
    saveSettings($pdo, [
        'staff_profile_update_required' => '1',
        'staff_profile_refresh_at'      => gmdate('Y-m-d H:i:s'),
    ]);

    try {
        $pdo->exec('UPDATE staff SET profile_completed = 0');
    } catch (PDOException $e) {
        error_log('[EventStaff] activateStaffProfileUpdateRequired: ' . $e->getMessage());
    }

    return countStaffNeedingProfileUpdate($pdo);
}

function deactivateStaffProfileUpdateRequired(PDO $pdo): void
{
    saveSettings($pdo, [
        'staff_profile_update_required' => '0',
        'staff_profile_refresh_at'      => '',
    ]);
}

function countStaffNeedingProfileUpdate(PDO $pdo): int
{
    try {
        $stmt = $pdo->query('SELECT id, profile_completed, updated_at FROM staff');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        return 0;
    }

    $count = 0;
    foreach ($rows as $row) {
        $staff = getStaffById($pdo, (int) $row['id']);
        if (staffMustUpdateProfile($pdo, $staff)) {
            $count++;
        }
    }

    return $count;
}

/**
 * Redirect signed-in portal users with incomplete profiles to the profile form.
 *
 * @param list<string> $allowedWithoutProfile basename paths e.g. staff-profile.php
 */
function enforceStaffProfileGate(PDO $pdo, array $allowedWithoutProfile = ['staff-profile.php', 'staff-portal.php']): void
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, $allowedWithoutProfile, true)) {
        return;
    }

    $staff = getStaffFromPortalSession($pdo);
    if ($staff !== null && staffNeedsProfileForm($pdo, $staff)) {
        $_SESSION['staff_profile_return'] = staffProfileReturnUrl();
        header('Location: staff-profile.php');
        exit;
    }
}

function staffProfileReturnUrl(): string
{
    $path = (string) ($_SERVER['REQUEST_URI'] ?? 'staff-app.php');

    return $path !== '' ? $path : 'staff-app.php';
}

/**
 * @return array<string, mixed>|null
 */
function handleStaffPortalVerifyPost(PDO $pdo): ?array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }

    if (!isset($_POST['staff_portal_verify'])) {
        return null;
    }

    require_once __DIR__ . '/staff-google-oauth.php';
    if (isStaffGoogleSigninRequired($pdo)) {
        return [
            'error' => 'Sign in with Google using the same Gmail you registered with.',
        ];
    }

    require_once __DIR__ . '/auth.php';
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        return ['error' => 'Your session expired. Please try again.'];
    }

    require_once __DIR__ . '/signin-display.php';

    $email    = trim((string) ($_POST['email'] ?? ''));
    $ppsLast4 = strtoupper(preg_replace('/\s+/', '', trim((string) ($_POST['pps_last4'] ?? ''))));
    $staff    = authenticateStaffPortal($pdo, $email, $ppsLast4);

    if ($staff === null) {
        return [
            'error' => getSigninMismatchMessage($pdo),
            'email' => $email,
            'pps_last4' => $ppsLast4,
        ];
    }

    require_once __DIR__ . '/staff-portal-remember.php';
    establishStaffPortalSessionWithRemember($pdo, $staff);
    $_SESSION['staff_profile_return'] = 'staff-app.php';

    header('Location: ' . staffPortalLandingUrl($pdo, $staff));
    exit;
}

/**
 * @param array<string, mixed>|null $staff
 */
function staffRequiresProfileReverify(?array $staff): bool
{
    return $staff !== null && (int) ($staff['profile_reverify_required'] ?? 0) === 1;
}

/**
 * Admin action — staff must complete the profile form again on next sign-in.
 */
function resetStaffProfileVerification(PDO $pdo, int $staffId): bool
{
    if ($staffId < 1) {
        return false;
    }

    ensureStaffPsaSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            'UPDATE staff SET profile_completed = 0, profile_reverify_required = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['id' => $staffId]);

        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('[EventStaff] resetStaffProfileVerification: ' . $e->getMessage());

        return false;
    }
}

function staffNeedsProfileForm(PDO $pdo, ?array $staff): bool
{
    if ($staff === null) {
        return false;
    }

    if (staffRequiresProfileReverify($staff)) {
        return true;
    }

    return !isStaffOnboardingComplete($staff) || staffMustUpdateProfile($pdo, $staff);
}

/**
 * Staff may pick / register for new shifts only when onboarding is fully complete.
 */
function staffCanRegisterForShifts(PDO $pdo, string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $staff = getStaffByEmail($pdo, $email);
    if ($staff === null) {
        $staffId = ensureStaffRecordForEmail($pdo, $email);
        $staff   = $staffId > 0 ? getStaffById($pdo, $staffId) : null;
    }

    if ($staff === null) {
        return false;
    }

    return !staffNeedsProfileForm($pdo, $staff);
}

/**
 * Redirect path after portal sign-in.
 */
function staffPortalLandingUrl(PDO $pdo, array $staff): string
{
    return 'staff-app.php';
}

/**
 * @param array<string, mixed> $data Registration POST data
 * @param array<string, mixed> $files $_FILES
 * @param array<string, mixed>|null $existingStaff
 */
function registrationFormReadyForShiftSelection(array $data, array $files, ?array $existingStaff): bool
{
    $required = [
        'email', 'surname', 'first_name', 'full_address', 'eircode',
        'date_of_birth', 'gender', 'mobile', 'pps_number', 'bank_iban',
    ];

    if (registrationRoleRequiresPsa($data)) {
        $required[] = 'psa_licence';
        $required[] = 'psa_expiry_date';
    }

    foreach ($required as $field) {
        if (trim((string) ($data[$field] ?? '')) === '') {
            return false;
        }
    }

    if (!filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (!registrationRoleRequiresPsa($data)) {
        return true;
    }

    $hasFront = !empty($files['psa_front_image']['tmp_name'])
        || (!empty($existingStaff['psa_front_image']) && trim((string) $existingStaff['psa_front_image']) !== '' && trim((string) $existingStaff['psa_front_image']) !== 'pending-upload');
    $hasBack  = !empty($files['psa_back_image']['tmp_name'])
        || (!empty($existingStaff['psa_back_image']) && trim((string) $existingStaff['psa_back_image']) !== '' && trim((string) $existingStaff['psa_back_image']) !== 'pending-upload');

    return $hasFront && $hasBack;
}

/**
 * @param array{error?: string, email?: string, date_of_birth?: string} $state
 * @param 'signin'|'update' $mode signin = returning staff; update = rollout still in progress
 */
function renderStaffProfileVerifyForm(PDO $pdo, array $state = [], string $mode = 'signin'): void
{
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/company.php';
    require_once __DIR__ . '/site-urls.php';
    $companyName = getCompanyName($pdo);
    $registerHost = parse_url(getRegistrationSiteUrl($pdo), PHP_URL_HOST) ?: 'register.olasentra.com';
    $missing  = array_values(getStaffBaseProfileRequiredFields());
    $missing[] = 'PSA licence (security roles only)';
    $isUpdate = $mode === 'update';
    require_once __DIR__ . '/staff-google-oauth.php';
    $googleEnabled  = isStaffGoogleSigninEnabled($pdo);
    $googleRequired = isStaffGoogleSigninRequired($pdo);
    $profileRollout = isStaffProfileUpdateRequired($pdo);
    ?>
    <section class="staff-app-gate card staff-public-card<?= $isUpdate ? ' staff-app-gate--update' : ' staff-app-gate--signin' ?>" aria-labelledby="staff-app-gate-title">
        <p class="staff-app-gate__trust">Official <?= h($companyName) ?> staff app · <?= h($registerHost) ?></p>
        <div class="staff-app-gate__badge"><?= $isUpdate ? 'Profile update' : 'Staff sign-in' ?></div>
        <h2 id="staff-app-gate-title" class="staff-app-gate__title"><?= $isUpdate ? 'Confirm your profile' : 'Sign in to your shifts' ?></h2>
        <p class="staff-app-gate__lead">
            <?php if ($isUpdate): ?>
                You are signed in. Please confirm your contact and payroll details on the next screen — we never ask for bank details before you sign in.
            <?php elseif ($googleEnabled && $googleRequired): ?>
                Tap <strong>Continue with Google</strong> with the same Gmail you used when registering. No passwords or bank details on this screen.
            <?php elseif ($googleEnabled): ?>
                Tap <strong>Continue with Google</strong> (recommended) to view shifts and check-in links.
            <?php else: ?>
                Enter the <strong>email and last 4 of your PPS</strong> you use for venue sign-in. Bank details are only on your profile after sign-in.
            <?php endif; ?>
        </p>
        <?php if ($isUpdate): ?>
            <p class="staff-app-gate__hint">Already updated? Sign in below — you will go straight to your shifts.</p>
        <?php elseif ($profileRollout && !$googleRequired): ?>
            <p class="staff-app-gate__hint">After sign-in you may be asked to confirm your profile once for payroll.</p>
        <?php endif; ?>

        <?php if (!empty($state['error'])): ?>
            <div class="alert alert--error alert--visible"><?= h((string) $state['error']) ?></div>
        <?php endif; ?>

        <?php if ($googleEnabled && !$isUpdate): ?>
            <div class="staff-app-gate__google" style="margin-bottom:1rem;">
                <?php renderStaffGoogleSignInButton($pdo, 'staff-app.php', true); ?>
            </div>
            <p class="form-hint">Same Gmail as your event registration. Stay signed in on this phone for shift tracking.</p>
            <?php if ($googleRequired): ?>
                <p class="form-hint" style="margin-top:0.75rem;">Venue QR sign-in still uses email + last 4 of PPS at the event.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($googleRequired && !$isUpdate): ?>
            <?php // Email + PPS form hidden when Google is required. ?>
        <?php else: ?>
        <form method="post" class="staff-app-gate__form" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="staff_portal_verify" value="1">
            <div class="form-group form-group--full">
                <label class="form-label form-label--required" for="gate-email">Email</label>
                <input type="email" id="gate-email" name="email" class="form-input" required autocomplete="email"
                    value="<?= h((string) ($state['email'] ?? '')) ?>" placeholder="you@example.com">
            </div>
            <?php
            require_once __DIR__ . '/signin-display.php';
            $ppsRequired = isSigninPpsRequired($pdo);
            ?>
            <?php if ($ppsRequired): ?>
            <div class="form-group form-group--full">
                <label class="form-label form-label--required" for="gate-pps">Last 4 of PPS number</label>
                <input type="text" id="gate-pps" name="pps_last4" class="form-input" required autocomplete="off"
                    maxlength="4" inputmode="text" pattern="[A-Za-z0-9]{4}" placeholder="e.g. 567A"
                    value="<?= h((string) ($state['pps_last4'] ?? '')) ?>">
                <p class="form-hint">Same as venue QR sign-in — last 4 characters only (letters and digits).</p>
            </div>
            <?php else: ?>
            <p class="form-hint form-group--full">Sign in with your registered email only.</p>
            <?php endif; ?>
            <?php if (!$isUpdate): ?>
            <div class="form-group form-group--full">
                <label class="form-check">
                    <input type="checkbox" name="remember_device" value="1" checked>
                    <span>Keep me signed in on this phone (90 days)</span>
                </label>
                <p class="form-hint">Uses a secure cookie on this phone. GPS shift tracking runs from the staff app.</p>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary btn--block staff-app-gate__submit"><?= $isUpdate ? 'Continue' : 'View my shifts' ?></button>
        </form>
        <?php endif; ?>

        <p class="staff-app-gate__new" style="margin:1rem 0 0;font-size:0.9rem;text-align:center;">
            <strong>New staff?</strong>
            <a href="index.php">Register with Gmail</a> first, then sign in here with the same account.
        </p>

        <?php if ($isUpdate): ?>
        <details class="staff-app-gate__details">
            <summary>What you will need (first time only)</summary>
            <ul>
                <?php foreach ($missing as $label): ?>
                    <li><?= h($label) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php endif; ?>
    </section>
    <?php
}
