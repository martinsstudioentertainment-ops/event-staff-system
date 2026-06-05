<?php declare(strict_types=1);

header('Content-Type: application/json');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| MAIN DATABASE CONFIG
|--------------------------------------------------------------------------
*/

$host = 'localhost';

$db   = 'olastofx_eventstaff';

$user = 'olastofx_dbuser';

$pass = 'Bodmas2508@';

/*
|--------------------------------------------------------------------------
| CONNECT TO MAIN DATABASE
|--------------------------------------------------------------------------
*/

try {

    $pdo = new PDO(

        "mysql:host={$host};dbname={$db};charset=utf8mb4",

        $user,

        $pass,

        [

            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false,

        ]
    );

} catch (Throwable $e) {

    echo json_encode([

        'success' => false,

        'message' => $e->getMessage()

    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| GET SEARCH VALUES
|--------------------------------------------------------------------------
*/

$email =
    trim($_GET['email'] ?? '');

$mobile =
    trim($_GET['mobile'] ?? '');

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if ($email === '' && $mobile === '') {

    echo json_encode([

        'success' => false,

        'message' =>
            'Email or mobile required.'

    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| SEARCH STAFF
|--------------------------------------------------------------------------
*/

$sql = "

    SELECT

        surname,
        first_name,
        full_address,
        eircode,
        email,
        mobile,
        date_of_birth,
        gender,
        pps_number,
        bank_iban

    FROM staff_registrations

    WHERE

        email = :email

        OR mobile = :mobile

    LIMIT 1

";

$stmt = $pdo->prepare($sql);

$stmt->execute([

    ':email'  => $email,

    ':mobile' => $mobile

]);

$staff = $stmt->fetch();

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

if ($staff) {

    echo json_encode([

        'success' => true,

        'exists'  => true,

        'staff'   => $staff

    ]);

} else {

    echo json_encode([

        'success' => true,

        'exists'  => false

    ]);
}