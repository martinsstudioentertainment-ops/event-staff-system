<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/apply-urls.php';
require_once __DIR__ . '/includes/google-sheets-sync.php';
require_once __DIR__ . '/includes/main-admin-bridge.php';

$staff = null;
$success = '';
$error = '';

/*
|--------------------------------------------------------------------------
| PROFILE LOOKUP
|--------------------------------------------------------------------------
*/

if (isset($_POST['lookup_profile'])) {

    $email = trim($_POST['email'] ?? '');
    $dob = trim($_POST['date_of_birth'] ?? '');

    $stmt = $pdo->prepare("
        SELECT *
        FROM staff_master
        WHERE email = :email
        AND date_of_birth = :dob
        LIMIT 1
    ");

    $stmt->execute([
        ':email' => $email,
        ':dob' => $dob
    ]);

    $staff = $stmt->fetch();

    if (!$staff) {
        $error = 'Profile not found. Please check your Email Address and Date of Birth.';
    }
}

/*
|--------------------------------------------------------------------------
| SAVE PROFILE
|--------------------------------------------------------------------------
*/

if (isset($_POST['save_profile'])) {

    $staffId = (int)($_POST['staff_id'] ?? 0);

    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $postcode = trim($_POST['postcode'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $iban = trim($_POST['bank_iban'] ?? '');
    $psaLicence = trim($_POST['psa_licence'] ?? '');
    $psaExpiry = trim($_POST['psa_expiry_date'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | DUPLICATE EMAIL CHECK
    |--------------------------------------------------------------------------
    */

    $checkEmail = $pdo->prepare("
        SELECT id
        FROM staff_master
        WHERE email = :email
        AND id != :id
        LIMIT 1
    ");

    $checkEmail->execute([
        ':email' => $email,
        ':id' => $staffId
    ]);

    if ($checkEmail->fetch()) {
        $error = 'Email address already exists.';
    }

    /*
    |--------------------------------------------------------------------------
    | DUPLICATE PHONE CHECK
    |--------------------------------------------------------------------------
    */

    if (!$error) {

        $checkPhone = $pdo->prepare("
            SELECT id
            FROM staff_master
            WHERE phone = :phone
            AND id != :id
            LIMIT 1
        ");

        $checkPhone->execute([
            ':phone' => $phone,
            ':id' => $staffId
        ]);

        if ($checkPhone->fetch()) {
            $error = 'Phone number already exists.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DUPLICATE PSA CHECK
    |--------------------------------------------------------------------------
    */

    if (!$error && !empty($psaLicence)) {

        $checkPsa = $pdo->prepare("
            SELECT id
            FROM staff_master
            WHERE psa_licence = :psa
            AND id != :id
            LIMIT 1
        ");

        $checkPsa->execute([
            ':psa' => $psaLicence,
            ':id' => $staffId
        ]);

        if ($checkPsa->fetch()) {
            $error = 'PSA Licence already exists.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PSA FRONT IMAGE
    |--------------------------------------------------------------------------
    */

    $frontImage = '';

    $uploadDir = __DIR__ . '/uploads/psa/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (
        !$error &&
        !empty($_FILES['psa_front']['name'])
    ) {

        $frontName =
            'front_' .
            time() .
            '_' .
            basename($_FILES['psa_front']['name']);

        $frontPath =
            __DIR__ .
            '/uploads/psa/' .
            $frontName;

        move_uploaded_file(
            $_FILES['psa_front']['tmp_name'],
            $frontPath
        );

        $frontImage =
            'uploads/psa/' .
            $frontName;
    }

    /*
    |--------------------------------------------------------------------------
    | PSA BACK IMAGE
    |--------------------------------------------------------------------------
    */

    $backImage = '';

    if (
        !$error &&
        !empty($_FILES['psa_back']['name'])
    ) {

        $backName =
            'back_' .
            time() .
            '_' .
            basename($_FILES['psa_back']['name']);

        $backPath =
            __DIR__ .
            '/uploads/psa/' .
            $backName;

        move_uploaded_file(
            $_FILES['psa_back']['tmp_name'],
            $backPath
        );

        $backImage =
            'uploads/psa/' .
            $backName;
    }

    if (!$error) {

        $profileStatus = 'Pending Review';

        if (
            empty($psaLicence)
            ||
            empty($psaExpiry)
        ) {
            $profileStatus = 'Incomplete';
        }

        if (
            !empty($psaExpiry)
            &&
            strtotime($psaExpiry) < time()
        ) {
            $profileStatus = 'Expired PSA';
        }
                $update = $pdo->prepare("
            UPDATE staff_master
            SET

                email = :email,
                phone = :phone,
                address = :address,
                postcode = :postcode,
                nationality = :nationality,
                bank_iban = :iban,
                psa_licence = :psa_licence,
                psa_expiry_date = :psa_expiry,

                psa_front_image =
                    CASE
                        WHEN :front_image != ''
                        THEN :front_image
                        ELSE psa_front_image
                    END,

                psa_back_image =
                    CASE
                        WHEN :back_image != ''
                        THEN :back_image
                        ELSE psa_back_image
                    END,

                profile_status = :profile_status

            WHERE id = :id
        ");

        $update->execute([
            ':email' => $email,
            ':phone' => $phone,
            ':address' => $address,
            ':postcode' => $postcode,
            ':nationality' => $nationality,
            ':iban' => $iban,
            ':psa_licence' => $psaLicence,
            ':psa_expiry' => $psaExpiry,
            ':front_image' => $frontImage,
            ':back_image' => $backImage,
            ':profile_status' => $profileStatus,
            ':id' => $staffId,
        ]);

        run_apply_google_sheets_sync($pdo, getMainAdminPdo());

        $success =
            'Thank you. Your profile has been updated successfully and is awaiting review.';

    }
}

/*
|--------------------------------------------------------------------------
| RELOAD STAFF RECORD
|--------------------------------------------------------------------------
*/

if (isset($_POST['staff_id'])) {

    $reload = $pdo->prepare("
        SELECT *
        FROM staff_master
        WHERE id = :id
        LIMIT 1
    ");

    $reload->execute([
        ':id' => (int)$_POST['staff_id']
    ]);

    $staff = $reload->fetch();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Update My Profile</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#020617;
    color:#fff;
}

.card-box{
    background:#0f172a;
    border-radius:20px;
    padding:25px;
    margin-bottom:20px;
    border:1px solid #1e293b;
}

.form-control{
    background:#1e293b;
    border:1px solid #334155;
    color:#fff;
}

.form-control:focus{
    background:#1e293b;
    color:#fff;
}

.readonly{
    background:#111827 !important;
    opacity:.8;
}

.section-title{
    font-size:20px;
    font-weight:700;
    margin-bottom:20px;
}

.status-badge{
    padding:12px 18px;
    border-radius:10px;
    display:inline-block;
    font-weight:bold;
}

.status-pending{
    background:#92400e;
}

.status-verified{
    background:#065f46;
}

.status-expired{
    background:#991b1b;
}

.status-incomplete{
    background:#374151;
}

</style>

</head>

<body>

<div class="container py-5">

<div class="row justify-content-center">

<div class="col-lg-9">

<h1 class="text-center mb-4">
Update My Profile
</h1>

<?php if($success): ?>

<div class="alert alert-success">
<?= htmlspecialchars($success) ?>
</div>

<?php endif; ?>

<?php if($error): ?>

<div class="alert alert-danger">
<?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>

<?php if(!$staff): ?>

<div class="card-box">

<form method="POST">

<div class="section-title">
Find My Profile
</div>

<div class="mb-3">
<label>Email Address</label>
<input
type="email"
name="email"
class="form-control"
required>
</div>

<div class="mb-3">
<label>Date Of Birth</label>
<input
type="date"
name="date_of_birth"
class="form-control"
required>
</div>

<button
type="submit"
name="lookup_profile"
class="btn btn-primary w-100">
Find My Profile
</button>

</form>

</div>

<?php endif; ?>

<?php if($staff): ?>

<form
method="POST"
enctype="multipart/form-data">

<input
type="hidden"
name="staff_id"
value="<?= (int)$staff['id']; ?>">
<div class="card-box">

<div class="section-title">
Profile Status
</div>

<?php

$status = $staff['profile_status'] ?? 'Incomplete';

$class = 'status-incomplete';

if ($status === 'Pending Review') {
    $class = 'status-pending';
}

if ($status === 'Verified') {
    $class = 'status-verified';
}

if ($status === 'Expired PSA') {
    $class = 'status-expired';
}

?>

<div class="status-badge <?= $class ?>">
<?= htmlspecialchars($status) ?>
</div>

</div>

<div class="card-box">

<div class="section-title">
Personal Information
</div>

<div class="row">

<div class="col-md-6 mb-3">
<label>First Name</label>
<input
type="text"
class="form-control readonly"
readonly
value="<?= htmlspecialchars($staff['first_name'] ?? '') ?>">
</div>

<div class="col-md-6 mb-3">
<label>Surname</label>
<input
type="text"
class="form-control readonly"
readonly
value="<?= htmlspecialchars($staff['last_name'] ?? '') ?>">
</div>

<div class="col-md-6 mb-3">
<label>Date Of Birth</label>
<input
type="text"
class="form-control readonly"
readonly
value="<?= htmlspecialchars($staff['date_of_birth'] ?? '') ?>">
</div>

<div class="col-md-6 mb-3">
<label>PPS Number</label>
<input
type="text"
class="form-control readonly"
readonly
value="<?= htmlspecialchars($staff['national_insurance'] ?? '') ?>">
</div>

</div>

</div>

<div class="card-box">

<div class="section-title">
Contact Information
</div>

<div class="row">

<div class="col-md-6 mb-3">
<label>Email Address</label>
<input
type="email"
name="email"
class="form-control"
value="<?= htmlspecialchars($staff['email'] ?? '') ?>">
</div>

<div class="col-md-6 mb-3">
<label>Mobile Number</label>
<input
type="text"
name="phone"
class="form-control"
value="<?= htmlspecialchars($staff['phone'] ?? '') ?>">
</div>

<div class="col-md-12 mb-3">
<label>Full Address</label>
<input
type="text"
name="address"
class="form-control"
value="<?= htmlspecialchars($staff['address'] ?? '') ?>">
</div>

<div class="col-md-6 mb-3">
<label>Eircode</label>
<input
type="text"
name="postcode"
class="form-control"
value="<?= htmlspecialchars($staff['postcode'] ?? '') ?>">
</div>

</div>

</div>

<div class="card-box">

<div class="section-title">
Payroll Information
</div>

<div class="mb-3">
<label>IBAN</label>
<input
type="text"
name="bank_iban"
class="form-control"
value="<?= htmlspecialchars($staff['bank_iban'] ?? '') ?>">
</div>

</div>

<div class="card-box">

<div class="section-title">
PSA Compliance
</div>

<div class="row">

<div class="col-md-6 mb-3">
<label>PSA Licence Number</label>
<input
type="text"
name="psa_licence"
class="form-control"
value="<?= htmlspecialchars($staff['psa_licence'] ?? '') ?>">
</div>

<div class="col-md-6 mb-3">
<label>PSA Expiry Date</label>
<input
type="date"
name="psa_expiry_date"
class="form-control"
value="<?= htmlspecialchars($staff['psa_expiry_date'] ?? '') ?>">
</div>

<div class="col-md-6 mb-3">
<label>PSA Front Badge</label>
<input
type="file"
name="psa_front"
class="form-control">
</div>

<div class="col-md-6 mb-3">
<label>PSA Back Badge</label>
<input
type="file"
name="psa_back"
class="form-control">
</div>

<?php if(!empty($staff['psa_front_image'])): ?>

<div class="col-md-6 mb-3">
<a
href="<?= htmlspecialchars(apply_asset_url((string) $staff['psa_front_image'])) ?>"
target="_blank"
class="btn btn-outline-light w-100">
View Current PSA Front
</a>
</div>

<?php endif; ?>

<?php if(!empty($staff['psa_back_image'])): ?>

<div class="col-md-6 mb-3">
<a
href="<?= htmlspecialchars(apply_asset_url((string) $staff['psa_back_image'])) ?>"
target="_blank"
class="btn btn-outline-light w-100">
View Current PSA Back
</a>
</div>

<?php endif; ?>

</div>

</div>

<div class="card-box">

<button
type="submit"
name="save_profile"
class="btn btn-success btn-lg w-100">

Update My Profile

</button>

</div>

</form>

<?php endif; ?>

</div>

</div>

</div>

</body>

</html>