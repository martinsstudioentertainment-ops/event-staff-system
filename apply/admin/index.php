<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/apply-urls.php';
require_once __DIR__ . '/includes/google-sheets-sync.php';
require_once __DIR__ . '/includes/main-admin-bridge.php';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $postcode  = trim($_POST['postcode'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $dob       = trim($_POST['date_of_birth'] ?? '');
    $gender    = trim($_POST['gender'] ?? '');
    $ni        = trim($_POST['national_insurance'] ?? '');
    $iban      = trim($_POST['bank_iban'] ?? '');
    $psa       = trim($_POST['psa_licence'] ?? '');

    if (

        $firstName === '' ||
        $lastName === '' ||
        $email === ''

    ) {

        $error = 'Please complete all required fields.';

    } else {

        $check = $pdo->prepare(

            "
            SELECT id
            FROM staff_master
            WHERE
                email = :email
                OR phone = :phone
                OR psa_licence = :psa
            LIMIT 1
            "
        );

        $check->execute([

            ':email' => $email,
            ':phone' => $phone,
            ':psa'   => $psa,

        ]);

        $existing = $check->fetch();

        if ($existing) {

            $update = $pdo->prepare(

                "
                UPDATE staff_master
                SET

                    first_name = :first_name,
                    last_name = :last_name,
                    address = :address,
                    postcode = :postcode,
                    email = :email,
                    phone = :phone,
                    date_of_birth = :dob,
                    gender = :gender,
                    national_insurance = :ni,
                    bank_iban = :iban,
                    psa_licence = :psa

                WHERE id = :id
                "
            );

            $update->execute([

                ':first_name' => $firstName,
                ':last_name'  => $lastName,
                ':address'    => $address,
                ':postcode'   => $postcode,
                ':email'      => $email,
                ':phone'      => $phone,
                ':dob'        => $dob,
                ':gender'     => $gender,
                ':ni'         => $ni,
                ':iban'       => $iban,
                ':psa'        => $psa,
                ':id'         => $existing['id'],

            ]);

        } else {

            $insert = $pdo->prepare(

                "
                INSERT INTO staff_master (

                    first_name,
                    last_name,
                    address,
                    postcode,
                    email,
                    phone,
                    date_of_birth,
                    gender,
                    national_insurance,
                    bank_iban,
                    psa_licence

                ) VALUES (

                    :first_name,
                    :last_name,
                    :address,
                    :postcode,
                    :email,
                    :phone,
                    :dob,
                    :gender,
                    :ni,
                    :iban,
                    :psa

                )
                "
            );

            $insert->execute([

                ':first_name' => $firstName,
                ':last_name'  => $lastName,
                ':address'    => $address,
                ':postcode'   => $postcode,
                ':email'      => $email,
                ':phone'      => $phone,
                ':dob'        => $dob,
                ':gender'     => $gender,
                ':ni'         => $ni,
                ':iban'       => $iban,
                ':psa'        => $psa,

            ]);
        }

        run_apply_google_sheets_sync($pdo, getMainAdminPdo());

        header(

            'Location: index.php?success=1'
        );

        exit;
    }
}

if (isset($_GET['success'])) {

    $success =
        'Staff registration submitted successfully.';
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Apply Staff System</title>

<style>

body{

    font-family:Arial,sans-serif;

    background:#020617;

    color:#fff;

    padding:40px;
}

.container{

    max-width:700px;

    margin:auto;

    background:#0f172a;

    padding:30px;

    border-radius:20px;
}

input,
select{

    width:100%;

    padding:14px;

    margin-bottom:15px;

    border:none;

    border-radius:12px;

    background:#1e293b;

    color:#fff;
}

button{

    width:100%;

    padding:16px;

    border:none;

    border-radius:12px;

    background:#2563eb;

    color:#fff;

    font-weight:bold;

    cursor:pointer;
}

.success{

    background:#065f46;

    padding:12px;

    border-radius:10px;

    margin-bottom:15px;
}

.error{

    background:#7f1d1d;

    padding:12px;

    border-radius:10px;

    margin-bottom:15px;
}

.lookup{

    display:none;

    background:#1d4ed8;

    padding:12px;

    border-radius:10px;

    margin-bottom:15px;
}

.locked{

    opacity:0.7;

    cursor:not-allowed;
}

h1{

    margin-bottom:25px;
}

</style>

</head>

<body>

<div class="container">

<h1>Staff Registration</h1>

<?php if ($success): ?>

<div class="success">

<?= htmlspecialchars($success) ?>

</div>

<?php endif; ?>

<?php if ($error): ?>

<div class="error">

<?= htmlspecialchars($error) ?>

</div>

<?php endif; ?>

<div id="staffLookupMessage"
     class="lookup">
</div>

<form method="POST">

<input type="text"
       id="last_name"
       name="last_name"
       placeholder="Surname"
       required>

<input type="text"
       id="first_name"
       name="first_name"
       placeholder="First Name"
       required>

<input type="text"
       id="address"
       name="address"
       placeholder="Full Address">

<input type="text"
       id="postcode"
       name="postcode"
       placeholder="Postcode">

<input type="email"
       id="email"
       name="email"
       placeholder="Email Address"
       required>

<input type="text"
       id="phone"
       name="phone"
       placeholder="Mobile Number">

<input type="date"
       id="date_of_birth"
       name="date_of_birth">

<select id="gender"
        name="gender">

<option value="">Select Gender</option>

<option value="male">Male</option>

<option value="female">Female</option>

<option value="other">Other</option>

</select>

<input type="text"
       id="national_insurance"
       name="national_insurance"
       placeholder="National Insurance / PPS">

<input type="text"
       id="bank_iban"
       name="bank_iban"
       placeholder="Bank Account / IBAN">

<input type="text"
       id="psa_licence"
       name="psa_licence"
       placeholder="PSA Licence Number">

<button type="submit">

Submit Registration

</button>

</form>

</div>

<script>

async function checkExistingStaff() {

    const email =
        document.getElementById('email').value.trim();

    const phone =
        document.getElementById('phone').value.trim();

    if (email === '' && phone === '') {

        return;
    }

    try {

        const response = await fetch(

            <?= json_encode(apply_url('api/check-staff.php'), JSON_THROW_ON_ERROR) ?> + '?email='
            + encodeURIComponent(email)
            + '&mobile='
            + encodeURIComponent(phone)
        );

        const data = await response.json();

        if (
            data.success &&
            data.exists
        ) {

            const s = data.staff;

            const message =
                document.getElementById(
                    'staffLookupMessage'
                );

            message.style.display = 'block';

            message.innerHTML =
                'Existing staff found. Details auto-filled and verified fields locked.';

            document.getElementById(
                'last_name'
            ).value = s.surname || '';

            document.getElementById(
                'first_name'
            ).value = s.first_name || '';

            document.getElementById(
                'address'
            ).value = s.full_address || '';

            document.getElementById(
                'postcode'
            ).value = s.eircode || '';

            document.getElementById(
                'email'
            ).value = s.email || '';

            document.getElementById(
                'phone'
            ).value = s.mobile || '';

            document.getElementById(
                'date_of_birth'
            ).value = s.date_of_birth || '';

            document.getElementById(
                'gender'
            ).value = s.gender || '';

            document.getElementById(
                'national_insurance'
            ).value = s.pps_number || '';

            document.getElementById(
                'bank_iban'
            ).value = s.bank_iban || '';

            /*
            |--------------------------------------------------------------------------
            | LOCK VERIFIED FIELDS
            |--------------------------------------------------------------------------
            */

            const lockFields = [

                'last_name',
                'first_name',
                'address',
                'postcode',
                'date_of_birth',
                'gender',
                'national_insurance'

            ];

            lockFields.forEach(function(fieldId){

                const field =
                    document.getElementById(fieldId);

                if (
                    field &&
                    field.value.trim() !== ''
                ) {

                    field.readOnly = true;

                    field.classList.add('locked');

                    if (
                        field.tagName === 'SELECT'
                    ) {

                        field.disabled = true;
                    }
                }
            });
        }

    } catch (e) {

        console.log(e);
    }
}

document
    .getElementById('email')
    .addEventListener(

        'blur',

        checkExistingStaff
    );

document
    .getElementById('phone')
    .addEventListener(

        'blur',

        checkExistingStaff
    );

</script>

</body>

</html>