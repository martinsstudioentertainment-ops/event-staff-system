<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/staff-onboarding.php';
require_once __DIR__ . '/../includes/financial-field-validation.php';
require_once __DIR__ . '/../includes/site-urls.php';

requireAdminCapability('staff');

$pdo = getDB();
$staffId = (int) ($_GET['id'] ?? 0);
$canEdit = isAdminSuperUser();

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
$profileComplete = isStaffOnboardingComplete($staff);
$missingFields   = getStaffOnboardingMissingFields($staff);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($csrfToken)) {
        setAdminFlash('error', 'Invalid CSRF token');
    } else {
        $fieldErrors = validateFinancialStaffFields($_POST, true);
        if ($fieldErrors !== []) {
            setAdminFlash('error', implode(' ', $fieldErrors));
        } else {
        try {
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
                'location_lat'  => !empty($_POST['location_lat']) ? (float) $_POST['location_lat'] : null,
                'location_lng'  => !empty($_POST['location_lng']) ? (float) $_POST['location_lng'] : null,
                'mobile'        => trim((string) ($_POST['mobile'] ?? '')),
                'gender'        => trim((string) ($_POST['gender'] ?? 'prefer_not_to_say')),
                'pps_number'    => trim((string) ($_POST['pps_number'] ?? '')),
                'bank_iban'     => normalizeBankIban((string) ($_POST['bank_iban'] ?? '')),
                'staff_role'    => trim((string) ($_POST['staff_role'] ?? 'steward')),
            ];

            $stmt->execute([
                ...$syncData,
                'psa_licence'      => normalizePsaLicence((string) ($_POST['psa_licence'] ?? '')),
                'psa_expiry_date'  => trim((string) ($_POST['psa_expiry_date'] ?? '')) ?: null,
                'is_blacklisted'   => isset($_POST['is_blacklisted']) ? 1 : 0,
                'blacklist_reason' => isset($_POST['is_blacklisted']) ? trim((string) ($_POST['blacklist_reason'] ?? '')) : null,
                'id'               => $staffId,
            ]);

            syncStaffPersonalDataToRegistrations($pdo, $staffId, $syncData);

            try {
                require_once __DIR__ . '/../includes/google-sheets-sync.php';
                syncStaffProfileToLinkedGoogleSheets($pdo, $staffId);
            } catch (Throwable $e) {
                error_log('[EventStaff] Google Sheets sync after admin staff edit: ' . $e->getMessage());
            }

            if (isStaffOnboardingComplete(getStaffById($pdo, $staffId) ?? [])) {
                markStaffProfileCompleted($pdo, $staffId);
            }

            setAdminFlash('success', 'Staff information updated successfully');
            header('Location: staff-directory.php');
            exit;
        } catch (PDOException $e) {
            setAdminFlash('error', 'Failed to update staff: ' . $e->getMessage());
        }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setAdminFlash('error', 'Only administrators can edit staff records. Managers can send the profile update link below.');
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

<?php if (!$canEdit): ?>
    <div class="alert alert--warning alert--visible">
        View only — only <strong>administrators</strong> can change staff data here. Use the button below to email the staff member a profile update link.
    </div>
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
            <a href="<?= h(buildStaffRegistrationsAdminUrl((string) $staff['email'])) ?>" class="btn btn--secondary">All registrations</a>
            <a href="<?= h(buildStaffRegistrationsAdminUrl((string) $staff['email'], 'pending')) ?>" class="btn btn--secondary">Pending shifts</a>
            <a href="<?= h(buildStaffRegistrationsAdminUrl((string) $staff['email'], 'approved')) ?>" class="btn btn--secondary">Approved shifts</a>
            <a href="staff-directory.php" class="btn btn--secondary">← Directory</a>
        </div>
    </div>

    <div class="detail-list" style="margin-bottom:1rem;">
        <div class="detail-list__row">
            <dt>Profile status</dt>
            <dd>
                <?php if ($profileComplete): ?>
                    <span class="badge badge--approved">Complete</span>
                <?php else: ?>
                    <span class="badge badge--pending">Incomplete</span>
                    <?php if ($missingFields !== []): ?>
                        <span class="form-hint">Missing: <?= h(implode(', ', $missingFields)) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </dd>
        </div>
    </div>

    <form method="post" class="form-grid settings-form staff-edit-form<?= h($readonlyClass) ?>">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="form-group form-group--full">
            <h3 class="form-section-title">Personal information</h3>
        </div>

        <div class="form-group">
            <label class="form-label">First name</label>
            <input type="text" name="first_name" class="form-input" value="<?= h((string) $staff['first_name']) ?>" required<?= $readonlyAttr ?>>
        </div>

        <div class="form-group">
            <label class="form-label">Last name</label>
            <input type="text" name="surname" class="form-input" value="<?= h((string) $staff['surname']) ?>" required<?= $readonlyAttr ?>>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label">Email</label>
            <input type="email" class="form-input" value="<?= h((string) $staff['email']) ?>" disabled>
            <p class="form-hint">Email cannot be changed. Staff sign in to the portal with this email and their date of birth.</p>
        </div>

        <div class="form-group">
            <label class="form-label">Mobile</label>
            <input type="tel" name="mobile" class="form-input" value="<?= h((string) $staff['mobile']) ?>" required<?= $readonlyAttr ?>>
        </div>

        <div class="form-group">
            <label class="form-label">Date of birth</label>
            <input type="date" class="form-input" value="<?= h((string) $staff['date_of_birth']) ?>" disabled>
        </div>

        <div class="form-group">
            <label class="form-label">Gender</label>
            <select name="gender" class="form-select" required<?= $readonlyAttr ?>>
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
            <textarea name="full_address" class="form-input" rows="3" required<?= $readonlyAttr ?>><?= h((string) $staff['full_address']) ?></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Eircode</label>
            <input type="text" name="eircode" class="form-input" value="<?= h((string) $staff['eircode']) ?>" required<?= $readonlyAttr ?>>
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
            <select name="staff_role" class="form-select" required<?= $readonlyAttr ?>>
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
            <input type="text" name="pps_number" class="form-input" value="<?= h((string) $staff['pps_number']) ?>" required<?= $readonlyAttr ?>>
        </div>

        <div class="form-group">
            <label class="form-label">Bank IBAN</label>
            <input type="text" name="bank_iban" id="bank_iban" class="form-input" value="<?= h((string) $staff['bank_iban']) ?>" placeholder="IE29AIBK93115212345678" maxlength="34" required<?= $readonlyAttr ?>>
            <p class="form-hint">IBAN with country code — not a bank name.</p>
        </div>

        <div class="form-group form-group--full">
            <h3 class="form-section-title">PSA licence</h3>
        </div>

        <div class="form-group">
            <label class="form-label">PSA licence number</label>
            <input type="text" name="psa_licence" id="psa_licence" class="form-input" value="<?= h((string) ($staff['psa_licence'] ?? '')) ?>" placeholder="EM12345/67" pattern="EM[0-9]{5}/[0-9]{2}"<?= $readonlyAttr ?>>
            <p class="form-hint">Format EM00000/00</p>
        </div>

        <div class="form-group">
            <label class="form-label">PSA expiry date</label>
            <input type="date" name="psa_expiry_date" class="form-input" value="<?= h((string) ($staff['psa_expiry_date'] ?? '')) ?>"<?= $readonlyAttr ?>>
        </div>

        <div class="form-group form-group--full">
            <h3 class="form-section-title">Status</h3>
        </div>

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
        <?php if (!$canEdit): ?>
            <a href="staff-directory.php" class="btn btn--secondary">Back to directory</a>
        <?php endif; ?>
    </div>
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

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
