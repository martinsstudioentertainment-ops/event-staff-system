<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/staff-onboarding.php';
require_once __DIR__ . '/../includes/staff-profile-gate.php';
require_once __DIR__ . '/../includes/financial-field-validation.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/staff-profile-email.php';
require_once __DIR__ . '/../includes/staff-psa.php';
require_once __DIR__ . '/../includes/phone-numbers.php';
require_once __DIR__ . '/../includes/components/phone-input.php';

requireAdminCapability('staff');

$pdo = getDB();
$defaultPhoneCountry = resolvePhoneCountryIsoFromRequest($pdo);
$staffId = (int) ($_GET['id'] ?? 0);
$canEdit = adminCan('staff');
$canDangerZone = isAdminSuperUser();

if ($staffId < 1) {
    setAdminFlash('error', 'Invalid staff ID');
    header('Location: staff-directory.php');
    exit;
}

$staff = getStaffById($pdo, $staffId);
if (!$staff) {
    setAdminFlash('error', 'Staff member not found');
    header('Location: staff-directory.php');
    exit;
}

$profileUrl = getStaffProfileUrl($pdo, $staffId);
$portalUrl  = getStaffPortalUrl($pdo);
ensureStaffPsaSchema($pdo);
$staff = getStaffById($pdo, $staffId) ?? $staff;
$reverifyRequired = staffRequiresProfileReverify($staff);
$profileComplete  = isStaffOnboardingComplete($staff) && !$reverifyRequired;
$missingFields    = getStaffOnboardingMissingFields($staff);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_profile_verification']) && $canEdit) {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        setAdminFlash('error', 'Invalid CSRF token');
    } elseif (resetStaffProfileVerification($pdo, $staffId)) {
        $staffEmail = (string) ($staff['email'] ?? '');
        $emailed    = sendStaffProfileReverifyEmail($pdo, $staffId);
        logAdminAudit(
            $pdo,
            'staff_profile_reverify_reset',
            'staff',
            $staffId,
            'Admin required staff to redo profile verification: ' . $staffEmail
                . ($emailed ? ' (email sent)' : ' (email failed)')
        );
        if ($emailed) {
            setAdminFlash('success', 'Verification cancelled. An email was sent to ' . $staffEmail . ' with links to update their profile.');
        } else {
            setAdminFlash(
                'warning',
                'Verification cancelled, but the email could not be sent. Use “Email profile update link” below or check SMTP settings.'
            );
        }
    } else {
        setAdminFlash('error', 'Could not reset profile verification.');
    }
    header('Location: staff-edit.php?id=' . $staffId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && !isset($_POST['reset_profile_verification'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($csrfToken)) {
        setAdminFlash('error', 'Invalid CSRF token');
    } else {
        $fieldErrors = validateFinancialStaffFields($_POST, false);
        if ($fieldErrors !== []) {
            setAdminFlash('error', implode(' ', $fieldErrors));
        } else {
        try {
            $newEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
            $oldEmail = strtolower(trim((string) ($staff['email'] ?? '')));
            if ($newEmail !== $oldEmail) {
                $oldEmail = changeStaffEmail($pdo, $staffId, $newEmail);
                $staff = getStaffById($pdo, $staffId) ?? $staff;
                logAdminAudit(
                    $pdo,
                    'staff_email_changed',
                    'staff',
                    $staffId,
                    'Email corrected from ' . $oldEmail . ' to ' . $newEmail
                );
            }

            prepareMobileFromRequest($_POST);
            $mobileNormalized = trim((string) ($_POST['mobile'] ?? ''));
            if ($mobileNormalized !== '') {
                $mobileError = validateMobileNumber($mobileNormalized);
                if ($mobileError !== null) {
                    throw new InvalidArgumentException($mobileError);
                }
            }

            $dob = trim((string) ($_POST['date_of_birth'] ?? ''));
            if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                throw new InvalidArgumentException('Enter a valid date of birth.');
            }

            $stmt = $pdo->prepare(
                'UPDATE staff SET
                    surname = :surname,
                    first_name = :first_name,
                    full_address = :full_address,
                    eircode = :eircode,
                    location_lat = :location_lat,
                    location_lng = :location_lng,
                    mobile = :mobile,
                    gender = :gender,
                    date_of_birth = :date_of_birth,
                    pps_number = :pps_number,
                    bank_iban = :bank_iban,
                    staff_role = :staff_role,
                    psa_licence = :psa_licence,
                    psa_expiry_date = :psa_expiry_date,
                    is_blacklisted = :is_blacklisted,
                    blacklist_reason = :blacklist_reason,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id'
            );

            $syncData = [
                'surname'       => trim((string) ($_POST['surname'] ?? '')),
                'first_name'    => trim((string) ($_POST['first_name'] ?? '')),
                'full_address'  => trim((string) ($_POST['full_address'] ?? '')),
                'eircode'       => trim((string) ($_POST['eircode'] ?? '')),
                'location_lat'  => trim((string) ($_POST['location_lat'] ?? '')) !== '' ? (float) $_POST['location_lat'] : null,
                'location_lng'  => trim((string) ($_POST['location_lng'] ?? '')) !== '' ? (float) $_POST['location_lng'] : null,
                'mobile'        => $mobileNormalized,
                'gender'        => trim((string) ($_POST['gender'] ?? 'prefer_not_to_say')),
                'pps_number'    => trim((string) ($_POST['pps_number'] ?? '')),
                'bank_iban'     => normalizeBankIban((string) ($_POST['bank_iban'] ?? '')),
                'staff_role'    => trim((string) ($_POST['staff_role'] ?? 'steward')),
            ];

            $stmt->execute([
                ...$syncData,
                'date_of_birth'    => $dob !== '' ? $dob : null,
                'psa_licence'      => normalizePsaLicence((string) ($_POST['psa_licence'] ?? '')),
                'psa_expiry_date'  => trim((string) ($_POST['psa_expiry_date'] ?? '')) ?: null,
                'is_blacklisted'   => isset($_POST['is_blacklisted']) ? 1 : 0,
                'blacklist_reason' => isset($_POST['is_blacklisted']) ? trim((string) ($_POST['blacklist_reason'] ?? '')) : null,
                'id'               => $staffId,
            ]);

            $psaUpload = processStaffPsaFileUploadsWithErrors($staffId, $_FILES);
            if ($psaUpload['errors'] !== []) {
                setAdminFlash('error', reset($psaUpload['errors']) ?: 'Could not save PSA photos.');
                header('Location: staff-edit.php?id=' . $staffId);
                exit;
            }
            if ($psaUpload['paths'] !== []) {
                updateStaffProfile($pdo, $staffId, $psaUpload['paths']);
            }

            syncStaffPersonalDataToRegistrations($pdo, $staffId, array_merge($syncData, [
                'date_of_birth' => $dob,
            ]));

            $runProfileJobs = false;
            $staffBeforeComplete = (int) ($staff['profile_completed'] ?? 0) === 1;

            if (!empty($_POST['mark_profile_verified'])) {
                markStaffProfileCompleted($pdo, $staffId, false);
                $runProfileJobs = true;
            } elseif (isStaffOnboardingComplete(getStaffById($pdo, $staffId) ?? [])) {
                markStaffProfileCompleted($pdo, $staffId, false);
                $runProfileJobs = !$staffBeforeComplete;
            } else {
                $pdo->prepare('UPDATE staff SET profile_completed = 0 WHERE id = :id')->execute(['id' => $staffId]);
            }

            logAdminAudit(
                $pdo,
                'staff_profile_updated',
                'staff',
                $staffId,
                'Admin updated staff profile: ' . (string) ($staff['email'] ?? '')
            );

            setAdminFlash('success', 'Staff information updated successfully. Sheet sync runs in the background.');

            $redirect = 'staff-edit.php?id=' . $staffId;
            require_once __DIR__ . '/../includes/status-change-post-save.php';
            flushHttpResponse($redirect);
            runStaffProfileSheetsPostJobs($pdo, $staffId);
            if ($runProfileJobs) {
                runProfileCompletionPostJobs($pdo, $staffId);
            }
            exit;
        } catch (InvalidArgumentException $e) {
            setAdminFlash('error', $e->getMessage());
        } catch (PDOException $e) {
            setAdminFlash('error', 'Failed to update staff: ' . $e->getMessage());
        }
        }
    }
}

$flash = getAdminFlash();
$pageTitle = 'Edit Staff';
$activePage = 'staff-directory';
$readonlyAttr = $canEdit ? '' : ' disabled';
$readonlyClass = $canEdit ? '' : ' is-readonly';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<div class="admin-form-compact staff-edit-layout">
<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Edit Staff</h2>
            <p class="card__subtitle">
                <?= h(trim((string) $staff['first_name'] . ' ' . (string) $staff['surname'])) ?>
                · <?= h((string) $staff['email']) ?>
            </p>
        </div>
        <div class="card__header-actions" style="display:flex;flex-wrap:wrap;gap:0.5rem;">
            <a href="staff-inbox-thread.php?staff_id=<?= (int) ($staff['id'] ?? 0) ?>" class="btn btn--secondary">Message</a>
            <a href="<?= h(buildStaffRegistrationsAdminUrl((string) $staff['email'])) ?>" class="btn btn--secondary">All registrations</a>
            <a href="<?= h(buildStaffRegistrationsAdminUrl((string) $staff['email'], 'pending')) ?>" class="btn btn--secondary">Pending shifts</a>
            <a href="<?= h(buildStaffRegistrationsAdminUrl((string) $staff['email'], 'approved')) ?>" class="btn btn--secondary">Approved shifts</a>
            <a href="staff-directory.php" class="btn btn--secondary">← Directory</a>
        </div>
    </div>

    <div class="detail-list staff-edit-profile-status" style="margin-bottom:1rem;">
        <div class="detail-list__row">
            <dt>Profile status</dt>
            <dd>
                <?php if ($reverifyRequired): ?>
                    <span class="badge badge--pending">Re-verification required</span>
                    <p class="form-hint">Waiting for staff to confirm their details and PSA photos again.</p>
                <?php elseif ($profileComplete): ?>
                    <span class="badge badge--approved">Complete</span>
                <?php else: ?>
                    <span class="badge badge--pending">Incomplete</span>
                    <?php if ($missingFields !== []): ?>
                        <span class="form-hint">Missing: <?= h(implode(', ', $missingFields)) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($canEdit && !$reverifyRequired && $profileComplete): ?>
                    <form method="post" class="staff-edit-reverify-form" style="margin-top:0.75rem;"
                          onsubmit="return confirm('Require this staff member to update their profile again? They will receive an email with sign-in links.');">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="reset_profile_verification" value="1">
                        <button type="submit" class="btn btn--small btn--secondary">Cancel verification — require update</button>
                    </form>
                <?php endif; ?>
            </dd>
        </div>
    </div>

    <?php if ($canEdit): ?>
        <p class="form-hint form-group--full" style="margin-bottom:1rem;">You can save partial details to correct mistakes. Fields left blank are allowed. Staff must still complete their own profile unless you mark it verified below.</p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid settings-form staff-edit-form<?= h($readonlyClass) ?>" data-admin-staff-edit="1">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="form-group form-group--full">
            <h3 class="form-section-title">Personal information</h3>
        </div>

        <div class="form-group">
            <label class="form-label">First name</label>
            <input type="text" name="first_name" class="form-input" value="<?= h((string) $staff['first_name']) ?>"<?= $readonlyAttr ?>>
        </div>

        <div class="form-group">
            <label class="form-label">Last name</label>
            <input type="text" name="surname" class="form-input" value="<?= h((string) $staff['surname']) ?>"<?= $readonlyAttr ?>>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-input" value="<?= h((string) $staff['email']) ?>" required autocomplete="off"<?= $readonlyAttr ?>>
            <p class="form-hint">Staff sign in to the portal with this email and their date of birth. Changing it updates all event registrations.</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="mobile_national">Mobile</label>
            <?php renderPhoneInputField([
                'id'         => 'mobile',
                'value'      => (string) ($staff['mobile'] ?? ''),
                'defaultIso' => $defaultPhoneCountry,
                'required'   => false,
                'readonly'   => !$canEdit,
                'hint'       => '',
            ]); ?>
        </div>

        <div class="form-group">
            <label class="form-label">Date of birth</label>
            <input type="date" name="date_of_birth" class="form-input" value="<?= h((string) $staff['date_of_birth']) ?>"<?= $readonlyAttr ?>>
        </div>

        <div class="form-group">
            <label class="form-label">Gender</label>
            <select name="gender" class="form-select"<?= $readonlyAttr ?>>
                <option value="male" <?= $staff['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= $staff['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="other" <?= $staff['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                <option value="prefer_not_to_say" <?= $staff['gender'] === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
            </select>
        </div>

        <div class="form-group form-group--full">
            <h3 class="form-section-title">Address</h3>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label">Full address</label>
            <textarea name="full_address" class="form-input" rows="3"<?= $readonlyAttr ?>><?= h((string) $staff['full_address']) ?></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Eircode</label>
            <input type="text" name="eircode" class="form-input" value="<?= h((string) $staff['eircode']) ?>"<?= $readonlyAttr ?>>
        </div>

        <div class="form-group">
            <label class="form-label">Latitude (optional)</label>
            <input type="number" step="any" name="location_lat" class="form-input" value="<?= h((string) ($staff['location_lat'] ?? '')) ?>"<?= $readonlyAttr ?>>
        </div>

        <div class="form-group">
            <label class="form-label">Longitude (optional)</label>
            <input type="number" step="any" name="location_lng" class="form-input" value="<?= h((string) ($staff['location_lng'] ?? '')) ?>"<?= $readonlyAttr ?>>
        </div>

        <div class="form-group form-group--full">
            <h3 class="form-section-title">Work information</h3>
        </div>

        <div class="form-group">
            <label class="form-label">Staff role</label>
            <select name="staff_role" class="form-select"<?= $readonlyAttr ?>>
                <option value="dsp" <?= $staff['staff_role'] === 'dsp' ? 'selected' : '' ?>>DSP</option>
                <option value="static" <?= $staff['staff_role'] === 'static' ? 'selected' : '' ?>>Static</option>
                <option value="steward" <?= $staff['staff_role'] === 'steward' ? 'selected' : '' ?>>Steward</option>
            </select>
        </div>

        <div class="form-group form-group--full">
            <h3 class="form-section-title">Financial information</h3>
        </div>

        <div class="form-group">
            <label class="form-label">PPS number</label>
            <input type="text" name="pps_number" class="form-input" value="<?= h((string) $staff['pps_number']) ?>"<?= $readonlyAttr ?>>
        </div>

        <div class="form-group">
            <label class="form-label">Bank IBAN</label>
            <input type="text" name="bank_iban" id="bank_iban" class="form-input" value="<?= h((string) $staff['bank_iban']) ?>" placeholder="IE29AIBK93115212345678" maxlength="34"<?= $readonlyAttr ?>>
            <p class="form-hint">IBAN with country code — not a bank name.</p>
        </div>

        <div class="form-group form-group--full">
            <h3 class="form-section-title">PSA licence</h3>
        </div>

        <div class="form-group">
            <label class="form-label">PSA licence number</label>
            <input type="text" name="psa_licence" id="psa_licence" class="form-input" value="<?= h((string) ($staff['psa_licence'] ?? '')) ?>" placeholder="EM123456/00"<?= $readonlyAttr ?>>
            <p class="form-hint">Format EM123456/00 (optional until staff completes profile)</p>
        </div>

        <div class="form-group">
            <label class="form-label">PSA expiry date</label>
            <input type="date" name="psa_expiry_date" class="form-input" value="<?= h((string) ($staff['psa_expiry_date'] ?? '')) ?>"<?= $readonlyAttr ?>>
        </div>

        <?php
        $psaFrontUrl = isStoredPsaImagePath($staff['psa_front_image'] ?? null)
            ? psaImagePublicUrl((string) $staff['psa_front_image'], $pdo)
            : '';
        $psaBackUrl = isStoredPsaImagePath($staff['psa_back_image'] ?? null)
            ? psaImagePublicUrl((string) $staff['psa_back_image'], $pdo)
            : '';
        ?>
        <div class="form-group">
            <label class="form-label">PSA card — front photo</label>
            <input type="file" name="psa_front_image" class="form-input form-input--file" accept="<?= h(psaImageFileAcceptAttribute()) ?>"<?= $readonlyAttr ?>>
            <?php if ($psaFrontUrl !== ''): ?>
                <p class="form-hint"><a href="<?= h($psaFrontUrl) ?>" target="_blank" rel="noopener">View current front photo</a></p>
                <p class="form-hint"><img src="<?= h($psaFrontUrl) ?>" alt="PSA card front" style="max-width:240px;max-height:160px;border-radius:6px;border:1px solid var(--border, #ddd);"></p>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label">PSA card — back photo</label>
            <input type="file" name="psa_back_image" class="form-input form-input--file" accept="<?= h(psaImageFileAcceptAttribute()) ?>"<?= $readonlyAttr ?>>
            <?php if ($psaBackUrl !== ''): ?>
                <p class="form-hint"><a href="<?= h($psaBackUrl) ?>" target="_blank" rel="noopener">View current back photo</a></p>
                <p class="form-hint"><img src="<?= h($psaBackUrl) ?>" alt="PSA card back" style="max-width:240px;max-height:160px;border-radius:6px;border:1px solid var(--border, #ddd);"></p>
            <?php endif; ?>
        </div>

        <div class="form-group form-group--full">
            <h3 class="form-section-title">Status</h3>
        </div>

        <?php if ($canEdit): ?>
            <div class="form-group form-group--full">
                <label class="form-checkbox">
                    <input type="checkbox" name="mark_profile_verified" value="1"<?= $profileComplete ? ' checked' : '' ?>>
                    Mark profile as verified (admin override — staff not forced to complete missing fields)
                </label>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-checkbox">
                <input type="checkbox" name="is_blacklisted" value="1" <?= (int) ($staff['is_blacklisted'] ?? 0) === 1 ? 'checked' : '' ?><?= $readonlyAttr ?>>
                Blacklisted
            </label>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label">Blacklist reason</label>
            <textarea name="blacklist_reason" class="form-input" rows="2"<?= $readonlyAttr ?>><?= h((string) ($staff['blacklist_reason'] ?? '')) ?></textarea>
        </div>

        <div class="form-group form-group--full">
            <h3 class="form-section-title">Staff profile portal</h3>
            <p class="form-hint">Staff use email + date of birth at the portal, or the personal link. Sending the link does not approve their shift and is not a verification email.</p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label">Portal sign-in URL</label>
            <div class="copy-field">
                <input type="text" id="staff-portal-url" class="form-input copy-field__input" value="<?= h($portalUrl) ?>" readonly>
                <button type="button" class="btn btn--small btn--secondary copy-field__btn" data-copy-target="staff-portal-url">Copy</button>
            </div>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label">Personal profile link</label>
            <div class="copy-field">
                <input type="text" id="staff-profile-url" class="form-input copy-field__input" value="<?= h($profileUrl) ?>" readonly>
                <button type="button" class="btn btn--small btn--secondary copy-field__btn" data-copy-target="staff-profile-url">Copy</button>
            </div>
        </div>

        <?php if ($canEdit): ?>
            <div class="form-group">
                <label class="form-label">Profile token</label>
                <div class="form-inline-group staff-edit-token-row">
                    <input type="text" class="form-input" value="<?= h((string) ($staff['profile_token'] ?? 'Not generated')) ?>" readonly>
                    <button type="button" class="btn btn--small btn--secondary" onclick="regenerateToken()">Regenerate</button>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
            <div class="form-group form-group--full form-actions form-actions--end">
                <button type="submit" class="btn btn--primary">Save changes</button>
                <a href="staff-directory.php" class="btn btn--secondary">Cancel</a>
            </div>
        <?php endif; ?>
    </form>

    <div class="staff-edit-actions-bar">
        <form method="post" action="staff-send-profile-link.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="staff_id" value="<?= (int) $staffId ?>">
            <button type="submit" class="btn btn--secondary">Email profile update link</button>
        </form>
    </div>

    <?php if ($canDangerZone): ?>
    <section class="card staff-edit-danger-zone" style="margin-top:1.5rem;border-color:var(--danger, #dc2626);">
        <div class="card__header">
            <h2 class="card__title" style="color:var(--danger, #dc2626);">Danger zone</h2>
            <p class="card__subtitle">Permanently delete this staff profile, all event registrations, attendance, and reminder history. This cannot be undone.</p>
        </div>
        <form method="post" action="staff-delete.php" class="form-grid"
              onsubmit="return confirm('Delete this staff member permanently? All registrations and attendance will be removed.');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="staff_id" value="<?= (int) $staffId ?>">
            <div class="form-group form-group--full">
                <label class="form-label" for="confirm_email">Type <strong><?= h((string) $staff['email']) ?></strong> to confirm</label>
                <input type="email" id="confirm_email" name="confirm_email" class="form-input" required autocomplete="off"
                       placeholder="<?= h((string) $staff['email']) ?>">
            </div>
            <div class="form-group form-group--full form-actions">
                <button type="submit" class="btn btn--secondary" style="border-color:#dc2626;color:#dc2626;">Delete staff profile permanently</button>
            </div>
        </form>
    </section>
    <?php endif; ?>
</section>
</div>

<script>
function regenerateToken() {
    if (confirm('Regenerate the profile token? The old personal link will stop working.')) {
        window.location.href = 'staff-regenerate-token.php?id=<?= (int) $staff['id'] ?>';
    }
}

document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-copy-target');
        var el = id ? document.getElementById(id) : null;
        if (!el) {
            return;
        }
        el.select();
        el.setSelectionRange(0, 99999);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(el.value).catch(function () {});
        }
    });
});
</script>
<?php
$phoneJsPath = dirname(__DIR__) . '/assets/js/phone-input.js';
$phoneJsVer  = is_file($phoneJsPath) ? (string) filemtime($phoneJsPath) : '1';
?>
<script src="../assets/js/phone-input.js?v=<?= h($phoneJsVer) ?>"></script>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
