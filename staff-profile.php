<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/staff-repository.php';

$pdo = getDB();
$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '') {
    die('Invalid or missing profile token. Please contact admin.');
}

$staff = getStaffByProfileToken($pdo, $token);
if (!$staff) {
    die('Invalid profile token. Please contact admin.');
}

$profileComplete = isStaffProfileComplete($pdo, (int) $staff['id']);
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            // Check if profile is now complete
            if (isStaffProfileComplete($pdo, (int) $staff['id'])) {
                markStaffProfileCompleted($pdo, (int) $staff['id']);
                $flash = ['type' => 'success', 'message' => 'Your profile has been updated successfully and is now complete!'];
            } else {
                $flash = ['type' => 'success', 'message' => 'Your profile has been updated successfully.'];
            }
            $staff = getStaffByProfileToken($pdo, $token); // Refresh data
        } else {
            $flash = ['type' => 'error', 'message' => 'Failed to update profile. Please try again.'];
        }
    } catch (Exception $e) {
        $flash = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
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
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        body {
            background: #f5f5f5;
            padding: 20px;
        }
        .profile-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        .profile-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        .profile-header h1 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert--success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert--error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .form__section {
            margin-bottom: 30px;
        }
        .form__section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #555;
        }
        .form__group {
            margin-bottom: 20px;
        }
        .form__label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        .form__input, .form__select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .form__input:focus, .form__select:focus {
            outline: none;
            border-color: #007bff;
        }
        .form__input:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        .form__hint {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        .form__actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        .btn--primary {
            background: #007bff;
            color: white;
        }
        .btn--primary:hover {
            background: #0056b3;
        }
        .btn--secondary {
            background: #6c757d;
            color: white;
        }
        .btn--secondary:hover {
            background: #545b62;
        }
    </style>
</head>
<body>
    <div class="profile-container">
        <div class="profile-header">
            <h1>Staff Profile</h1>
            <p>Update your personal information</p>
        </div>

        <?php if (!$profileComplete): ?>
            <div class="alert alert--error">
                <strong>⚠️ Profile Incomplete</strong><br>
                Please complete your PSA licence information to continue registering for events.
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="alert alert--<?= h($flash['type']) ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <form method="post">
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
                    <p class="form__hint">Email is used for identification and cannot be changed.</p>
                </div>
                
                <div class="form__group">
                    <label class="form__label">Mobile</label>
                    <input type="tel" name="mobile" class="form__input" value="<?= h((string) $staff['mobile']) ?>" required>
                </div>
                
                <div class="form__group">
                    <label class="form__label">Date of Birth (cannot be changed)</label>
                    <input type="date" class="form__input" value="<?= h((string) $staff['date_of_birth']) ?>" disabled>
                    <p class="form__hint">Date of birth cannot be changed.</p>
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
                <h3 class="form__section-title">PSA Licence Information <span class="badge badge--pending">Required</span></h3>

                <div class="form__group">
                    <label class="form__label">PSA Licence Number</label>
                    <input type="text" name="psa_licence" class="form__input" value="<?= h((string) ($staff['psa_licence'] ?? '')) ?>" required>
                    <p class="form__hint">Your PSA licence number is required for security work.</p>
                </div>

                <div class="form__group">
                    <label class="form__label">PSA Expiry Date</label>
                    <input type="date" name="psa_expiry_date" class="form__input" value="<?= h((string) ($staff['psa_expiry_date'] ?? '')) ?>" required>
                    <p class="form__hint">When does your PSA licence expire?</p>
                </div>

                <div class="form__group">
                    <label class="form__label">PSA Licence Front Image</label>
                    <input type="file" name="psa_front_image" class="form__input" accept="image/*" <?= empty($staff['psa_front_image']) ? 'required' : '' ?>>
                    <?php if (!empty($staff['psa_front_image'])): ?>
                        <p class="form__hint">Current: <a href="<?= h($staff['psa_front_image']) ?>" target="_blank">View image</a></p>
                    <?php endif; ?>
                </div>

                <div class="form__group">
                    <label class="form__label">PSA Licence Back Image</label>
                    <input type="file" name="psa_back_image" class="form__input" accept="image/*" <?= empty($staff['psa_back_image']) ? 'required' : '' ?>>
                    <?php if (!empty($staff['psa_back_image'])): ?>
                        <p class="form__hint">Current: <a href="<?= h($staff['psa_back_image']) ?>" target="_blank">View image</a></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form__actions">
                <button type="submit" class="btn btn--primary">Save Changes</button>
            </div>
        </form>
    </div>
</body>
</html>
