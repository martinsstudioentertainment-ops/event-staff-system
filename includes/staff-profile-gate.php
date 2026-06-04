<?php

/**
 * Force existing staff to complete / refresh profile (mobile app + portal).
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/staff-onboarding.php';
require_once __DIR__ . '/staff-portal-session.php';

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
    if (!isStaffProfileUpdateRequired($pdo)) {
        return;
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, $allowedWithoutProfile, true)) {
        return;
    }

    $staff = getStaffFromPortalSession($pdo);
    if ($staff !== null && staffMustUpdateProfile($pdo, $staff)) {
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

    require_once __DIR__ . '/auth.php';
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        return ['error' => 'Your session expired. Please try again.'];
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $dob   = trim((string) ($_POST['date_of_birth'] ?? ''));
    $staff = authenticateStaffPortal($pdo, $email, $dob);

    if ($staff === null) {
        return [
            'error' => 'We could not verify your email and date of birth. Use the same details as your registration.',
            'email' => $email,
            'date_of_birth' => $dob,
        ];
    }

    establishStaffPortalSession($staff);
    $_SESSION['staff_profile_return'] = 'staff-app.php';

    if (!isStaffOnboardingComplete($staff) || staffMustUpdateProfile($pdo, $staff)) {
        header('Location: staff-profile.php');
        exit;
    }

    header('Location: staff-app.php');
    exit;
}

function staffNeedsProfileForm(PDO $pdo, ?array $staff): bool
{
    if ($staff === null) {
        return false;
    }

    return !isStaffOnboardingComplete($staff) || staffMustUpdateProfile($pdo, $staff);
}

/**
 * @param array{error?: string, email?: string, date_of_birth?: string} $state
 * @param 'signin'|'update' $mode signin = returning staff; update = rollout still in progress
 */
function renderStaffProfileVerifyForm(PDO $pdo, array $state = [], string $mode = 'signin'): void
{
    require_once __DIR__ . '/auth.php';
    $siteName = getSiteName($pdo);
    $missing  = getStaffOnboardingRequiredFields();
    $isUpdate = $mode === 'update';
    ?>
    <section class="staff-app-gate card staff-public-card<?= $isUpdate ? ' staff-app-gate--update' : ' staff-app-gate--signin' ?>" aria-labelledby="staff-app-gate-title">
        <div class="staff-app-gate__badge"><?= $isUpdate ? 'Profile update required' : 'Staff sign-in' ?></div>
        <h2 id="staff-app-gate-title" class="staff-app-gate__title"><?= $isUpdate ? 'Update your staff details' : 'Sign in to your shifts' ?></h2>
        <p class="staff-app-gate__lead">
            <?php if ($isUpdate): ?>
                <?= h($siteName) ?> needs every staff member to confirm address, bank details, and PSA licence photos once before shifts can be approved.
            <?php else: ?>
                Enter the <strong>same email and date of birth</strong> you used when registering to view your shifts, status, and check-in links.
            <?php endif; ?>
        </p>
        <?php if ($isUpdate): ?>
            <p class="staff-app-gate__hint">Already updated? Sign in below — you will go straight to your shifts.</p>
        <?php endif; ?>

        <?php if (!empty($state['error'])): ?>
            <div class="alert alert--error alert--visible"><?= h((string) $state['error']) ?></div>
        <?php endif; ?>

        <form method="post" class="staff-app-gate__form" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="staff_portal_verify" value="1">
            <div class="form-group form-group--full">
                <label class="form-label form-label--required" for="gate-email">Email</label>
                <input type="email" id="gate-email" name="email" class="form-input" required autocomplete="email"
                    value="<?= h((string) ($state['email'] ?? '')) ?>" placeholder="you@example.com">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label form-label--required" for="gate-dob">Date of birth</label>
                <input type="date" id="gate-dob" name="date_of_birth" class="form-input" required
                    value="<?= h((string) ($state['date_of_birth'] ?? '')) ?>">
            </div>
            <button type="submit" class="btn btn--primary btn--block staff-app-gate__submit"><?= $isUpdate ? 'Continue' : 'View my shifts' ?></button>
        </form>

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
