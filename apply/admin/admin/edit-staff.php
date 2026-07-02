<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/main-admin-bridge.php';
require_once __DIR__ . '/../includes/psa-sync.php';
require_once __DIR__ . '/../includes/psa-images.php';

if (!apply_require_main_include('phone-numbers.php')) {
    require_once __DIR__ . '/../includes/phone-numbers.php';
}
require_once __DIR__ . '/../includes/components/phone-input.php';

$id = (int) ($_GET['id'] ?? 0);
$defaultPhoneCountry = resolvePhoneCountryIsoFromRequest($pdo);

if ($id <= 0) {
    http_response_code(400);
    die('Invalid staff ID.');
}

$stmt = $pdo->prepare('SELECT * FROM staff_master WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$staff = $stmt->fetch();

if (!$staff) {
    http_response_code(404);
    die('Staff not found.');
}

$eventPdo = getMainAdminPdo();
$staff    = apply_merge_vault_psa_images($staff, $eventPdo);
$psaFrontPreviewUrl = apply_psa_image_url((string) ($staff['psa_front_image'] ?? ''));
$psaBackPreviewUrl  = apply_psa_image_url((string) ($staff['psa_back_image'] ?? ''));

$success = '';
$error   = '';

$uploadsDir        = __DIR__ . '/../uploads/psa/';
$frontDir          = $uploadsDir . 'front/';
$backDir           = $uploadsDir . 'back/';
$maxFileSize       = 5 * 1024 * 1024;
$allowedMimes      = ['image/jpeg', 'image/png', 'image/webp'];
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

if (!is_dir($frontDir)) {
    mkdir($frontDir, 0755, true);
}
if (!is_dir($backDir)) {
    mkdir($backDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        prepareMobileFromRequest($_POST);
        $phoneNormalized = trim((string) ($_POST['phone'] ?? ''));
        if ($phoneNormalized !== '') {
            $phoneError = validateMobileNumber($phoneNormalized);
            if ($phoneError !== null) {
                throw new Exception($phoneError);
            }
        }

        $psaLicence = trim($_POST['psa_licence'] ?? '');

        if ($psaLicence !== '') {
            $checkPsa = $pdo->prepare('SELECT id FROM staff_master WHERE psa_licence = :psa AND id != :id LIMIT 1');
            $checkPsa->execute(['psa' => $psaLicence, 'id' => $id]);
            if ($checkPsa->fetch()) {
                throw new Exception('PSA licence already exists.');
            }
        }

        $psaFrontImage = $staff['psa_front_image'];
        $psaBackImage  = $staff['psa_back_image'];

        if (isset($_FILES['psa_front_image']) && $_FILES['psa_front_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['psa_front_image'];
            if ($file['size'] > $maxFileSize) {
                throw new Exception('PSA front image exceeds 5MB limit.');
            }
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mimeType, $allowedMimes, true)) {
                throw new Exception('PSA front image must be JPEG, PNG, or WebP.');
            }
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                throw new Exception('Invalid file extension for PSA front image.');
            }
            $filename = 'psa_front_' . $id . '_' . time() . '.' . $extension;
            $filepath = $frontDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('Failed to upload PSA front image.');
            }
            if ($psaFrontImage && file_exists(__DIR__ . '/../' . $psaFrontImage)) {
                unlink(__DIR__ . '/../' . $psaFrontImage);
            }
            $psaFrontImage = 'uploads/psa/front/' . $filename;
        }

        if (isset($_FILES['psa_back_image']) && $_FILES['psa_back_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['psa_back_image'];
            if ($file['size'] > $maxFileSize) {
                throw new Exception('PSA back image exceeds 5MB limit.');
            }
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mimeType, $allowedMimes, true)) {
                throw new Exception('PSA back image must be JPEG, PNG, or WebP.');
            }
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                throw new Exception('Invalid file extension for PSA back image.');
            }
            $filename = 'psa_back_' . $id . '_' . time() . '.' . $extension;
            $filepath = $backDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('Failed to upload PSA back image.');
            }
            if ($psaBackImage && file_exists(__DIR__ . '/../' . $psaBackImage)) {
                unlink(__DIR__ . '/../' . $psaBackImage);
            }
            $psaBackImage = 'uploads/psa/back/' . $filename;
        }

        $vaultRow = [
            'first_name'         => trim($_POST['first_name'] ?? ''),
            'last_name'          => trim($_POST['last_name'] ?? ''),
            'email'              => trim($_POST['email'] ?? ''),
            'phone'              => $phoneNormalized,
            'date_of_birth'      => (string) ($staff['date_of_birth'] ?? ''),
            'address'            => trim($_POST['address'] ?? ''),
            'postcode'           => trim($_POST['postcode'] ?? ''),
            'national_insurance' => (string) ($staff['national_insurance'] ?? ''),
            'bank_iban'          => (string) ($staff['bank_iban'] ?? ''),
            'psa_licence'        => $psaLicence,
            'psa_expiry_date'    => trim($_POST['psa_expiry_date'] ?? ''),
            'profile_status'     => (string) ($staff['profile_status'] ?? 'Incomplete'),
        ];
        $eventPdo       = getMainAdminPdo();
        $mainStaff      = null;
        $emailKey       = strtolower(trim((string) $vaultRow['email']));
        if ($eventPdo instanceof PDO && $emailKey !== '') {
            $mainMap   = apply_main_staff_by_email($eventPdo);
            $mainStaff = $mainMap[$emailKey] ?? null;
        }
        $profileStatus = apply_resolve_profile_status($vaultRow, $mainStaff);
        $verifiedBy    = $staff['verified_by'] ?? null;
        $verifiedAt    = $staff['verified_at'] ?? null;
        if ($profileStatus === 'Verified' && ($staff['profile_status'] ?? '') !== 'Verified') {
            $verifiedBy = 'Auto';
            $verifiedAt = date('Y-m-d H:i:s');
        } elseif ($profileStatus !== 'Verified') {
            $verifiedBy = null;
            $verifiedAt = null;
        }

        $update = $pdo->prepare("
            UPDATE staff_master SET
                first_name = :first_name,
                last_name = :last_name,
                email = :email,
                phone = :phone,
                address = :address,
                postcode = :postcode,
                gender = :gender,
                psa_licence = :psa_licence,
                psa_expiry_date = :psa_expiry_date,
                psa_front_image = :psa_front_image,
                psa_back_image = :psa_back_image,
                profile_status = :profile_status,
                verification_notes = :verification_notes,
                verified_by = :verified_by,
                verified_at = :verified_at
            WHERE id = :id
        ");

        $update->execute([
            'first_name'         => trim($_POST['first_name'] ?? ''),
            'last_name'          => trim($_POST['last_name'] ?? ''),
            'email'              => trim($_POST['email'] ?? ''),
            'phone'              => $phoneNormalized,
            'address'            => trim($_POST['address'] ?? ''),
            'postcode'           => trim($_POST['postcode'] ?? ''),
            'gender'             => trim($_POST['gender'] ?? ''),
            'psa_licence'        => $psaLicence,
            'psa_expiry_date'    => trim($_POST['psa_expiry_date'] ?? ''),
            'psa_front_image'    => $psaFrontImage,
            'psa_back_image'     => $psaBackImage,
            'profile_status'     => $profileStatus,
            'verification_notes' => trim($_POST['verification_notes'] ?? ''),
            'verified_by'        => $verifiedBy,
            'verified_at'        => $verifiedAt,
            'id'                 => $id,
        ]);

        apply_auto_refresh_vault_profile_statuses($pdo, $eventPdo, $id);

        $success = 'Staff record updated successfully.';
        $stmt->execute(['id' => $id]);
        $staff = $stmt->fetch();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$fullName = trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));

secure_layout_start('Edit staff', 'staff', $fullName . ' · authorized edits only');

if ($success !== '') {
    echo '<div class="secure-alert secure-alert--success">' . secure_h($success) . '</div>';
}
if ($error !== '') {
    echo '<div class="secure-alert secure-alert--error">' . secure_h($error) . '</div>';
}
?>

<div class="secure-card" style="display:flex;flex-wrap:wrap;gap:0.5rem;">
    <a href="show-blade.php?id=<?= $id ?>" class="secure-btn secure-btn--ghost">Back to review</a>
    <a href="view-staff.php?id=<?= $id ?>" class="secure-btn secure-btn--ghost">View profile</a>
</div>

<form method="post" enctype="multipart/form-data">
    <div class="secure-card secure-card--danger-top">
        <h2 style="margin:0 0 1rem;font-size:1rem;">Editable fields</h2>
        <div class="secure-grid">
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="first_name">First name</label>
                <input class="secure-input" type="text" id="first_name" name="first_name" value="<?= secure_h((string) ($staff['first_name'] ?? '')) ?>">
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="last_name">Last name</label>
                <input class="secure-input" type="text" id="last_name" name="last_name" value="<?= secure_h((string) ($staff['last_name'] ?? '')) ?>">
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="email">Email</label>
                <input class="secure-input" type="email" id="email" name="email" value="<?= secure_h((string) ($staff['email'] ?? '')) ?>">
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="phone_national">Phone</label>
                <?php renderPhoneInputField([
                    'variant'    => 'secure',
                    'id'         => 'phone',
                    'name'       => 'phone',
                    'value'      => (string) ($staff['phone'] ?? ''),
                    'defaultIso' => $defaultPhoneCountry,
                    'required'   => false,
                ]); ?>
            </div>
        </div>
        <div class="secure-field">
            <label class="secure-label" for="address">Address</label>
            <textarea class="secure-textarea" id="address" name="address"><?= secure_h((string) ($staff['address'] ?? '')) ?></textarea>
        </div>
        <div class="secure-grid">
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="postcode">Postcode</label>
                <input class="secure-input" type="text" id="postcode" name="postcode" value="<?= secure_h((string) ($staff['postcode'] ?? '')) ?>">
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="gender">Gender</label>
                <input class="secure-input" type="text" id="gender" name="gender" value="<?= secure_h((string) ($staff['gender'] ?? '')) ?>">
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="psa_licence">PSA licence</label>
                <input class="secure-input" type="text" id="psa_licence" name="psa_licence" value="<?= secure_h((string) ($staff['psa_licence'] ?? '')) ?>">
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="psa_expiry_date">PSA expiry date</label>
                <input class="secure-input" type="date" id="psa_expiry_date" name="psa_expiry_date" value="<?= secure_h((string) ($staff['psa_expiry_date'] ?? '')) ?>">
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label">Profile status</label>
                <div style="padding-top:0.5rem;"><?= secure_status_badge((string) ($staff['profile_status'] ?? 'Incomplete')) ?></div>
                <p class="secure-label" style="margin-top:0.35rem;font-weight:400;opacity:0.85;">Set automatically from PSA and payroll fields.</p>
            </div>
        </div>
        <div class="secure-field">
            <label class="secure-label" for="verification_notes">Verification notes</label>
            <textarea class="secure-textarea" id="verification_notes" name="verification_notes"><?= secure_h((string) ($staff['verification_notes'] ?? '')) ?></textarea>
        </div>
    </div>

    <div class="secure-card">
        <h2 style="margin:0 0 1rem;font-size:1rem;">PSA document images</h2>
        <div class="secure-grid">
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="psa_front_image">PSA front</label>
                <div class="secure-upload">
                    <input type="file" id="psa_front_image" name="psa_front_image" accept="image/jpeg,image/png,image/webp">
                    <div class="secure-upload__hint">JPG, PNG, WebP — max 5MB</div>
                    <?php if ($psaFrontPreviewUrl !== ''): ?>
                        <div class="secure-upload__preview">
                            <strong style="font-size:0.8rem;">Current image</strong>
                            <a href="<?= secure_h($psaFrontPreviewUrl) ?>" target="_blank" rel="noopener">
                                <img src="<?= secure_h($psaFrontPreviewUrl) ?>" alt="PSA front">
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label" for="psa_back_image">PSA back</label>
                <div class="secure-upload">
                    <input type="file" id="psa_back_image" name="psa_back_image" accept="image/jpeg,image/png,image/webp">
                    <div class="secure-upload__hint">JPG, PNG, WebP — max 5MB</div>
                    <?php if ($psaBackPreviewUrl !== ''): ?>
                        <div class="secure-upload__preview">
                            <strong style="font-size:0.8rem;">Current image</strong>
                            <a href="<?= secure_h($psaBackPreviewUrl) ?>" target="_blank" rel="noopener">
                                <img src="<?= secure_h($psaBackPreviewUrl) ?>" alt="PSA back">
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="secure-card">
        <h2 style="margin:0 0 1rem;font-size:1rem;">Protected fields (read-only)</h2>
        <div class="secure-grid">
            <div class="secure-grid__col secure-field">
                <label class="secure-label">PPS number</label>
                <input class="secure-input secure-input--locked" type="text" value="<?= secure_h((string) ($staff['national_insurance'] ?? '')) ?>" readonly>
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label">Date of birth</label>
                <input class="secure-input secure-input--locked" type="text" value="<?= secure_h(secure_format_date((string) ($staff['date_of_birth'] ?? ''))) ?>" readonly>
            </div>
            <div class="secure-grid__col secure-field">
                <label class="secure-label">IBAN</label>
                <input class="secure-input secure-input--locked" type="text" value="<?= secure_h((string) ($staff['bank_iban'] ?? '')) ?>" readonly>
            </div>
        </div>
    </div>

    <button type="submit" class="secure-btn secure-btn--success">Save changes</button>
</form>

<script src="../assets/js/phone-input.js"></script>

<?php secure_layout_end(); ?>
