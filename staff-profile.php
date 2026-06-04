<?php
require_once __DIR__ . '/config.php';
initSecureSession();
require_once __DIR__ . '/includes/staff-repository.php';
require_once __DIR__ . '/includes/staff-onboarding.php';
require_once __DIR__ . '/includes/staff-portal-session.php';

$pdo = getDB();
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
    header('Location: staff-portal.php');
    exit;
}

$profileComplete = isStaffOnboardingComplete($staff);
$missingFields   = getStaffOnboardingMissingFields($staff);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $validationErrors = validateStaffOnboardingPost($_POST, $staff);
    if ($validationErrors !== []) {
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

        // Handle image uploads
        if (isset($_FILES['psa_front_image']) && $_FILES['psa_front_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/psa/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = pathinfo($_FILES['psa_front_image']['name'], PATHINFO_EXTENSION);
            $filename = 'psa_front_' . $staff['id'] . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['psa_front_image']['tmp_name'], $uploadDir . $filename)) {
                $updateData['psa_front_image'] = '/uploads/psa/' . $filename;
            }
        }

        if (isset($_FILES['psa_back_image']) && $_FILES['psa_back_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/psa/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $ext = pathinfo($_FILES['psa_back_image']['name'], PATHINFO_EXTENSION);
            $filename = 'psa_back_' . $staff['id'] . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['psa_back_image']['tmp_name'], $uploadDir . $filename)) {
                $updateData['psa_back_image'] = '/uploads/psa/' . $filename;
            }
        }

        if (updateStaffProfile($pdo, (int) $staff['id'], $updateData)) {
            $staff = getStaffById($pdo, (int) $staff['id']) ?? $staff;
            if (isStaffOnboardingComplete($staff)) {
                markStaffProfileCompleted($pdo, (int) $staff['id']);
                $flash = [
                    'type'    => 'success',
                    'message' => 'Your profile is complete. You can register for events, view status, and check in when approved.',
                ];
                $profileComplete = true;
                $missingFields   = [];
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
    } catch (Exception $e) {
        $flash = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
    }
    }
}

$siteName = getSiteName($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Profile | <?= h($siteName) ?></title>
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="staff-profile-page">
    <div class="staff-profile-page__wrap">
        <div class="card">
            <div class="card__header card__header--row">
                <div>
                    <h2 class="card__title">Staff Profile</h2>
                    <p class="card__subtitle"><?= h((string) $staff['email']) ?> — update your personal information</p>
                </div>
                <a href="staff-profile.php?logout=1" class="btn btn--small btn--secondary">Sign out</a>
            </div>

            <?php if (!$profileComplete): ?>
                <div class="alert alert--error alert--visible">
                    <strong>Profile incomplete</strong><br>
                    Complete all fields below before you can view registration status or check in.
                    <?php if ($missingFields !== []): ?>
                        <br><br>Still needed: <?= h(implode(', ', $missingFields)) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="alert alert--<?= h($flash['type']) ?> alert--visible">
                    <?= h($flash['message']) ?>
                </div>
            <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="form-grid">
            <div class="form-group form-group--full">
                <h3 class="form-section-title">Personal Information</h3>

                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-input" value="<?= h((string) $staff['first_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Last name</label>
                    <input type="text" name="surname" class="form-input" value="<?= h((string) $staff['surname']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Email (cannot be changed)</label>
                    <input type="email" class="form-input" value="<?= h((string) $staff['email']) ?>" disabled>
                    <p class="form-hint">Email is used for identification and cannot be changed.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Mobile</label>
                    <input type="tel" name="mobile" class="form-input" value="<?= h((string) $staff['mobile']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Date of birth</label>
                    <?php if (trim((string) ($staff['date_of_birth'] ?? '')) === ''): ?>
                        <input type="date" name="date_of_birth" class="form-input" required>
                    <?php else: ?>
                        <input type="date" class="form-input" value="<?= h((string) $staff['date_of_birth']) ?>" disabled>
                        <p class="form-hint">Date of birth cannot be changed after it is saved.</p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select" required>
                        <option value="male" <?= $staff['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= $staff['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="other" <?= $staff['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                        <option value="prefer_not_to_say" <?= $staff['gender'] === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group form-group--full">
                <h3 class="form-section-title">Address</h3>
                
                <div class="form-group">
                    <label class="form-label">Full Address</label>
                    <textarea name="full_address" class="form-input" rows="3" required><?= h((string) $staff['full_address']) ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Eircode</label>
                    <input type="text" name="eircode" class="form-input" value="<?= h((string) $staff['eircode']) ?>" required>
                </div>
            </div>
            
            <div class="form-group form-group--full">
                <h3 class="form-section-title">Financial Information</h3>

                <div class="form-group">
                    <label class="form-label">PPS Number</label>
                    <input type="text" name="pps_number" class="form-input" value="<?= h((string) $staff['pps_number']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Bank IBAN</label>
                    <input type="text" name="bank_iban" class="form-input" value="<?= h((string) $staff['bank_iban']) ?>" required>
                </div>
            </div>

            <div class="form-group form-group--full">
                <h3 class="form-section-title">PSA Licence Information <span class="badge badge--pending">Required</span></h3>

                <div class="form-group">
                    <label class="form-label">PSA Licence Number</label>
                    <input type="text" name="psa_licence" class="form-input" value="<?= h((string) ($staff['psa_licence'] ?? '')) ?>" required>
                    <p class="form-hint">Your PSA licence number is required for security work.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">PSA Expiry Date</label>
                    <input type="date" name="psa_expiry_date" class="form-input" value="<?= h((string) ($staff['psa_expiry_date'] ?? '')) ?>" required>
                    <p class="form-hint">When does your PSA licence expire?</p>
                </div>

                <div class="form-group">
                    <label class="form-label">PSA Licence Front Image</label>
                    <input type="file" name="psa_front_image" class="form-input" accept="image/*" <?= empty($staff['psa_front_image']) ? 'required' : '' ?>>
                    <?php if (!empty($staff['psa_front_image'])): ?>
                        <p class="form-hint">Current: <a href="<?= h($staff['psa_front_image']) ?>" target="_blank">View image</a></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">PSA Licence Back Image</label>
                    <input type="file" name="psa_back_image" class="form-input" accept="image/*" <?= empty($staff['psa_back_image']) ? 'required' : '' ?>>
                    <?php if (!empty($staff['psa_back_image'])): ?>
                        <p class="form-hint">Current: <a href="<?= h($staff['psa_back_image']) ?>" target="_blank">View image</a></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group form-group--full form-actions form-actions--end">
                <button type="submit" class="btn btn--primary">Save changes</button>
            </div>
        </form>
        </div>
    </div>
</body>
</html>
