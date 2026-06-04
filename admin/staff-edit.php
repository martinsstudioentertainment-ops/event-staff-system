<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';

requireAdminCapability('staff');

$pdo = getDB();
$staffId = (int) ($_GET['id'] ?? 0);

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!csrfVerify($csrfToken)) {
        setAdminFlash('error', 'Invalid CSRF token');
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
                    is_blacklisted = :is_blacklisted,
                    blacklist_reason = :blacklist_reason,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id'
            );
            
            $stmt->execute([
                'surname' => trim((string) ($_POST['surname'] ?? '')),
                'first_name' => trim((string) ($_POST['first_name'] ?? '')),
                'full_address' => trim((string) ($_POST['full_address'] ?? '')),
                'eircode' => trim((string) ($_POST['eircode'] ?? '')),
                'location_lat' => !empty($_POST['location_lat']) ? (float) $_POST['location_lat'] : null,
                'location_lng' => !empty($_POST['location_lng']) ? (float) $_POST['location_lng'] : null,
                'mobile' => trim((string) ($_POST['mobile'] ?? '')),
                'gender' => trim((string) ($_POST['gender'] ?? 'prefer_not_to_say')),
                'pps_number' => trim((string) ($_POST['pps_number'] ?? '')),
                'bank_iban' => trim((string) ($_POST['bank_iban'] ?? '')),
                'staff_role' => trim((string) ($_POST['staff_role'] ?? 'steward')),
                'is_blacklisted' => isset($_POST['is_blacklisted']) ? 1 : 0,
                'blacklist_reason' => isset($_POST['is_blacklisted']) ? trim((string) ($_POST['blacklist_reason'] ?? '')) : null,
                'id' => $staffId,
            ]);
            
            setAdminFlash('success', 'Staff information updated successfully');
            header('Location: staff-directory.php');
            exit;
        } catch (PDOException $e) {
            setAdminFlash('error', 'Failed to update staff: ' . $e->getMessage());
        }
    }
}

$flash = getAdminFlash();
$pageTitle = 'Edit Staff';
$activePage = 'staff-directory';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Edit Staff</h2>
            <p class="card__subtitle">
                Update staff information. Changes will apply to all future registrations.
            </p>
        </div>
        <a href="staff-directory.php" class="btn btn--secondary">← Staff Directory</a>
    </div>

    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        
        <div class="form__section">
            <h3 class="form__section-title">Personal Information</h3>
            
            <div class="form__group">
                <label class="form__label">First Name</label>
                <input type="text" name="first_name" class="form__input" value="<?= h((string) $staff['first_name']) ?>" required>
            </div>
            
            <div class="form__group">
                <label class="form__label">Surname</label>
                <input type="text" name="surname" class="form__input" value="<?= h((string) $staff['surname']) ?>" required>
            </div>
            
            <div class="form__group">
                <label class="form__label">Email (cannot be changed)</label>
                <input type="email" class="form__input" value="<?= h((string) $staff['email']) ?>" disabled>
                <p class="form-hint">Email is used for login and cannot be changed.</p>
            </div>
            
            <div class="form__group">
                <label class="form__label">Mobile</label>
                <input type="tel" name="mobile" class="form__input" value="<?= h((string) $staff['mobile']) ?>" required>
            </div>
            
            <div class="form__group">
                <label class="form__label">Date of Birth (cannot be changed)</label>
                <input type="date" class="form__input" value="<?= h((string) $staff['date_of_birth']) ?>" disabled>
                <p class="form-hint">Date of birth cannot be changed.</p>
            </div>
            
            <div class="form__group">
                <label class="form__label">Gender</label>
                <select name="gender" class="form__select" required>
                    <option value="male" <?= $staff['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                    <option value="female" <?= $staff['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                    <option value="other" <?= $staff['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                    <option value="prefer_not_to_say" <?= $staff['gender'] === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
                </select>
            </div>
        </div>
        
        <div class="form__section">
            <h3 class="form__section-title">Address</h3>
            
            <div class="form__group">
                <label class="form__label">Full Address</label>
                <textarea name="full_address" class="form__input" rows="3" required><?= h((string) $staff['full_address']) ?></textarea>
            </div>
            
            <div class="form__group">
                <label class="form__label">Eircode</label>
                <input type="text" name="eircode" class="form__input" value="<?= h((string) $staff['eircode']) ?>" required>
            </div>
            
            <div class="form__group">
                <label class="form__label">Latitude (optional)</label>
                <input type="number" step="any" name="location_lat" class="form__input" value="<?= h((string) ($staff['location_lat'] ?? '')) ?>">
            </div>
            
            <div class="form__group">
                <label class="form__label">Longitude (optional)</label>
                <input type="number" step="any" name="location_lng" class="form__input" value="<?= h((string) ($staff['location_lng'] ?? '')) ?>">
            </div>
        </div>
        
        <div class="form__section">
            <h3 class="form__section-title">Work Information</h3>
            
            <div class="form__group">
                <label class="form__label">Staff Role</label>
                <select name="staff_role" class="form__select" required>
                    <option value="dsp" <?= $staff['staff_role'] === 'dsp' ? 'selected' : '' ?>>DSP</option>
                    <option value="static" <?= $staff['staff_role'] === 'static' ? 'selected' : '' ?>>Static</option>
                    <option value="steward" <?= $staff['staff_role'] === 'steward' ? 'selected' : '' ?>>Steward</option>
                </select>
            </div>
        </div>
        
        <div class="form__section">
            <h3 class="form__section-title">Financial Information</h3>
            
            <div class="form__group">
                <label class="form__label">PPS Number</label>
                <input type="text" name="pps_number" class="form__input" value="<?= h((string) $staff['pps_number']) ?>" required>
            </div>
            
            <div class="form__group">
                <label class="form__label">Bank IBAN</label>
                <input type="text" name="bank_iban" class="form__input" value="<?= h((string) $staff['bank_iban']) ?>" required>
            </div>
        </div>
        
        <div class="form__section">
            <h3 class="form__section-title">Status</h3>
            
            <div class="form__group">
                <label class="form__checkbox">
                    <input type="checkbox" name="is_blacklisted" value="1" <?= (int) ($staff['is_blacklisted'] ?? 0) === 1 ? 'checked' : '' ?>>
                    Blacklisted
                </label>
                <p class="form-hint">Blacklisted staff cannot register for events.</p>
            </div>
            
            <div class="form__group">
                <label class="form__label">Blacklist Reason</label>
                <textarea name="blacklist_reason" class="form__input" rows="2"><?= h((string) ($staff['blacklist_reason'] ?? '')) ?></textarea>
            </div>
        </div>
        
        <div class="form__section">
            <h3 class="form__section-title">Public Profile Link</h3>
            
            <div class="form__group">
                <label class="form__label">Profile Token</label>
                <div class="form__input-group">
                    <input type="text" class="form__input" value="<?= h((string) ($staff['profile_token'] ?? 'Not generated')) ?>" readonly>
                    <button type="button" class="btn btn--small btn--secondary" onclick="regenerateToken()">Regenerate</button>
                </div>
                <p class="form-hint">Share this link with staff so they can update their information.</p>
            </div>
            
            <div class="form__group">
                <label class="form__label">Profile Link</label>
                <?php if ($staff['profile_token']): ?>
                    <input type="text" class="form__input" value="<?= h(getRegistrationFormUrl($pdo) . '/staff-profile.php?token=' . $staff['profile_token']) ?>" readonly>
                <?php else: ?>
                    <p class="form-hint">Generate a profile token first to create a public link.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="form__actions">
            <button type="submit" class="btn btn--primary">Save Changes</button>
            <a href="staff-directory.php" class="btn btn--secondary">Cancel</a>
        </div>
    </form>
</section>

<script>
function regenerateToken() {
    if (confirm('Are you sure you want to regenerate the profile token? The old link will no longer work.')) {
        window.location.href = 'staff-regenerate-token.php?id=<?= (int) $staff['id'] ?>';
    }
}
</script>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
