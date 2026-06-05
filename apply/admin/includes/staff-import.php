<?php



declare(strict_types=1);



require_once __DIR__ . '/psa-sync.php';



/**

 * Import / refresh approved main ERP registrations into apply staff_master.

 *

 * @return array{imported: int, updated: int, skipped: int, errors: list<string>, psa_synced?: int}

 */

function apply_import_approved_from_main(PDO $eventPdo, PDO $applyPdo): array

{

    $imported = 0;

    $updated  = 0;

    $skipped  = 0;

    $errors   = [];



    $staffList = $eventPdo->query("

        SELECT * FROM staff_registrations

        WHERE status = 'approved'

        ORDER BY id ASC

    ")->fetchAll(PDO::FETCH_ASSOC);



    $registrationCount = count($staffList);

    $mainStaffByEmail  = apply_main_staff_by_email($eventPdo);



    // One vault row per person — keep the latest approved registration per email.

    $uniqueByEmail = [];

    foreach ($staffList as $staff) {

        $email = trim((string) ($staff['email'] ?? ''));

        if ($email === '') {

            ++$skipped;

            continue;

        }

        $uniqueByEmail[strtolower($email)] = $staff;

    }



    foreach ($uniqueByEmail as $staff) {

        $email = trim((string) ($staff['email'] ?? ''));

        if (!function_exists('normalizeMobileNumber')) {
            $phoneLib = dirname(__DIR__, 3) . '/includes/phone-numbers.php';
            if (!is_readable($phoneLib)) {
                $phoneLib = __DIR__ . '/phone-numbers.php';
            }
            require_once $phoneLib;
        }
        $phoneRaw = trim((string) ($staff['mobile'] ?? ''));
        $phone    = $phoneRaw !== '' ? normalizeMobileNumber($phoneRaw) : '';

        $phoneDb = $phone !== '' ? $phone : null;

        $emailKey = strtolower($email);

        $mainStaff = $mainStaffByEmail[$emailKey] ?? null;



        $check = $applyPdo->prepare('SELECT id, psa_licence, profile_status FROM staff_master WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1');

        $check->execute(['email' => $email]);

        $existing = $check->fetch(PDO::FETCH_ASSOC);



        $resolvedPsa = apply_resolve_psa_from_main(

            $staff,

            $mainStaff,

            (string) ($existing['psa_licence'] ?? '')

        );

        $psaLicence = $resolvedPsa['licence'] !== ''

            ? $resolvedPsa['licence']

            : ('TEMP-PSA-' . uniqid('', true));



        if ($existing) {

            $vaultRow = [
                'first_name'          => (string) ($staff['first_name'] ?? ''),
                'last_name'           => (string) ($staff['surname'] ?? ''),
                'email'               => $email,
                'phone'               => $phoneDb ?? '',
                'date_of_birth'       => (string) ($staff['date_of_birth'] ?? ''),
                'address'             => (string) ($staff['full_address'] ?? ''),
                'postcode'            => (string) ($staff['eircode'] ?? ''),
                'national_insurance'  => (string) ($staff['pps_number'] ?? ''),
                'bank_iban'           => (string) ($staff['bank_iban'] ?? ''),
                'psa_licence'         => $psaLicence,
                'psa_expiry_date'     => $resolvedPsa['expiry'],
                'profile_status'      => (string) ($existing['profile_status'] ?? 'Pending Review'),
            ];
            $status = apply_resolve_profile_status($vaultRow, $mainStaff);



            $update = $applyPdo->prepare("

                UPDATE staff_master SET

                    first_name = :first_name,

                    last_name = :last_name,

                    address = :address,

                    postcode = :postcode,

                    phone = :phone,

                    date_of_birth = :dob,

                    gender = :gender,

                    national_insurance = :ni,

                    bank_iban = :iban,

                    psa_licence = :psa,

                    psa_expiry_date = :psa_expiry,

                    profile_status = :profile_status

                WHERE id = :id

            ");



            $update->execute([

                'first_name'     => (string) ($staff['first_name'] ?? ''),

                'last_name'      => (string) ($staff['surname'] ?? ''),

                'address'        => (string) ($staff['full_address'] ?? ''),

                'postcode'       => (string) ($staff['eircode'] ?? ''),

                'phone'          => $phoneDb,

                'dob'            => (string) ($staff['date_of_birth'] ?? ''),

                'gender'         => (string) ($staff['gender'] ?? ''),

                'ni'             => (string) ($staff['pps_number'] ?? ''),

                'iban'           => (string) ($staff['bank_iban'] ?? ''),

                'psa'            => $psaLicence,

                'psa_expiry'     => $resolvedPsa['expiry'],

                'profile_status' => $status,

                'id'             => (int) $existing['id'],

            ]);



            ++$updated;

            continue;

        }



        $vaultRow = [
            'first_name'         => (string) ($staff['first_name'] ?? ''),
            'last_name'          => (string) ($staff['surname'] ?? ''),
            'email'              => $email,
            'phone'              => $phoneDb ?? '',
            'date_of_birth'      => (string) ($staff['date_of_birth'] ?? ''),
            'address'            => (string) ($staff['full_address'] ?? ''),
            'postcode'           => (string) ($staff['eircode'] ?? ''),
            'national_insurance' => (string) ($staff['pps_number'] ?? ''),
            'bank_iban'          => (string) ($staff['bank_iban'] ?? ''),
            'psa_licence'        => $psaLicence,
            'psa_expiry_date'    => $resolvedPsa['expiry'],
            'profile_status'     => 'Pending Review',
        ];
        $status = apply_resolve_profile_status($vaultRow, $mainStaff);



        $insert = $applyPdo->prepare("

            INSERT INTO staff_master (

                first_name, last_name, address, postcode, email, phone,

                date_of_birth, gender, national_insurance, bank_iban,

                psa_licence, psa_expiry_date, profile_status

            ) VALUES (

                :first_name, :last_name, :address, :postcode, :email, :phone,

                :dob, :gender, :ni, :iban, :psa, :psa_expiry, :profile_status

            )

        ");



        try {

            $insert->execute([

                'first_name'     => (string) ($staff['first_name'] ?? ''),

                'last_name'      => (string) ($staff['surname'] ?? ''),

                'address'        => (string) ($staff['full_address'] ?? ''),

                'postcode'       => (string) ($staff['eircode'] ?? ''),

                'email'          => $email,

                'phone'          => $phoneDb,

                'dob'            => (string) ($staff['date_of_birth'] ?? ''),

                'gender'         => (string) ($staff['gender'] ?? ''),

                'ni'             => (string) ($staff['pps_number'] ?? ''),

                'iban'           => (string) ($staff['bank_iban'] ?? ''),

                'psa'            => $psaLicence,

                'psa_expiry'     => $resolvedPsa['expiry'],

                'profile_status' => $status,

            ]);

            ++$imported;

        } catch (Throwable $e) {

            $errors[] = $email . ': ' . $e->getMessage();

            ++$skipped;

        }

    }



    try {
        $psaSynced = apply_auto_refresh_vault_profile_statuses($applyPdo, $eventPdo);
    } catch (Throwable $e) {
        error_log('[ApplySync] apply_auto_refresh after import: ' . $e->getMessage());
        $psaSynced = 0;
    }



    return [

        'imported'               => $imported,

        'updated'                => $updated,

        'skipped'                => $skipped,

        'errors'                 => $errors,

        'approved_registrations' => $registrationCount,

        'unique_touched'         => $imported + $updated,

        'unique_people'          => count($uniqueByEmail),

        'psa_synced'             => $psaSynced,

    ];

}

