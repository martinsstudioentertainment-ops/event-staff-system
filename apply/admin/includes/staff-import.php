<?php



declare(strict_types=1);



require_once __DIR__ . '/psa-sync.php';
require_once __DIR__ . '/import-precheck.php';

/**
 * Avoid unique `phone` violations when two ERP registrations share one mobile.
 */
function apply_import_phone_for_vault(PDO $applyPdo, ?string $phone, ?int $vaultId, ?string $currentPhone = null): ?string
{
    if ($phone === null || $phone === '') {
        return null;
    }

    $stmt = $applyPdo->prepare('SELECT id FROM staff_master WHERE phone = :phone LIMIT 1');
    $stmt->execute(['phone' => $phone]);
    $ownerId = $stmt->fetchColumn();

    if ($ownerId === false) {
        return $phone;
    }

    if ($vaultId !== null && (int) $ownerId === $vaultId) {
        return $phone;
    }

    if ($vaultId !== null && $currentPhone !== null && trim($currentPhone) !== '') {
        return trim($currentPhone);
    }

    return null;
}

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
            require_once __DIR__ . '/phone-numbers.php';
        }
        $phoneRaw = trim((string) ($staff['mobile'] ?? ''));
        $phone    = $phoneRaw !== '' ? normalizeMobileNumber($phoneRaw) : '';

        $phoneDb = $phone !== '' ? $phone : null;

        $emailKey = strtolower($email);

        $mainStaff = $mainStaffByEmail[$emailKey] ?? null;
        if ($mainStaff === null) {
            ++$skipped;
            continue;
        }



        $check = $applyPdo->prepare('SELECT id, psa_licence, profile_status, phone FROM staff_master WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1');

        $check->execute(['email' => $email]);

        $existing = $check->fetch(PDO::FETCH_ASSOC);

        $vaultId   = $existing ? (int) $existing['id'] : null;
        $phoneSave = apply_import_phone_for_vault(
            $applyPdo,
            $phoneDb,
            $vaultId,
            $existing ? (string) ($existing['phone'] ?? '') : null
        );
        if ($phoneDb !== null && $phoneSave !== $phoneDb) {
            $phoneOwner = apply_import_vault_owner_by_phone($applyPdo, $phoneDb, $vaultId);
            $errors[]   = apply_import_format_phone_skip_message(
                $email,
                $phoneDb,
                $phoneOwner,
                $vaultId !== null
            );
        }

        $resolvedPsa = apply_resolve_psa_from_main(

            $staff,

            $mainStaff,

            (string) ($existing['psa_licence'] ?? '')

        );

        $psaLicence = apply_normalize_vault_psa_licence($resolvedPsa['licence']);

        if (is_string($psaLicence) && $psaLicence !== '') {
            $psaOwner = apply_import_vault_owner_by_psa($applyPdo, $psaLicence, $vaultId);
            if ($psaOwner !== null && $vaultId === null) {
                $errors[] = apply_import_format_psa_skip_message($email, $psaLicence, $psaOwner);
                ++$skipped;
                continue;
            }
        }



        if ($existing) {

            $vaultRow = [
                'first_name'          => (string) ($staff['first_name'] ?? ''),
                'last_name'           => (string) ($staff['surname'] ?? ''),
                'email'               => $email,
                'phone'               => $phoneSave ?? '',
                'date_of_birth'       => (string) ($staff['date_of_birth'] ?? ''),
                'address'             => (string) ($staff['full_address'] ?? ''),
                'postcode'            => (string) ($staff['eircode'] ?? ''),
                'national_insurance'  => (string) ($staff['pps_number'] ?? ''),
                'bank_iban'           => (string) ($staff['bank_iban'] ?? ''),
                'psa_licence'         => apply_export_psa_licence($psaLicence),
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

                    profile_status = :profile_status,

                    psa_front_image = COALESCE(NULLIF(:psa_front_image, ''), psa_front_image),

                    psa_back_image = COALESCE(NULLIF(:psa_back_image, ''), psa_back_image)

                WHERE id = :id

            ");



            try {
                $update->execute([

                    'first_name'     => (string) ($staff['first_name'] ?? ''),

                    'last_name'      => (string) ($staff['surname'] ?? ''),

                    'address'        => (string) ($staff['full_address'] ?? ''),

                    'postcode'       => (string) ($staff['eircode'] ?? ''),

                    'phone'          => $phoneSave,

                    'dob'            => (string) ($staff['date_of_birth'] ?? ''),

                    'gender'         => (string) ($staff['gender'] ?? ''),

                    'ni'             => (string) ($staff['pps_number'] ?? ''),

                    'iban'           => (string) ($staff['bank_iban'] ?? ''),

                    'psa'            => $psaLicence,

                    'psa_expiry'     => $resolvedPsa['expiry'],

                    'profile_status' => $status,

                    'psa_front_image' => trim((string) ($mainStaff['psa_front_image'] ?? '')),

                    'psa_back_image'  => trim((string) ($mainStaff['psa_back_image'] ?? '')),

                    'id'             => (int) $existing['id'],

                ]);

                ++$updated;
            } catch (Throwable $e) {
                $errors[] = apply_import_human_error($applyPdo, $email, $e, $psaLicence);
                ++$skipped;
            }

            continue;

        }



        $vaultRow = [
            'first_name'         => (string) ($staff['first_name'] ?? ''),
            'last_name'          => (string) ($staff['surname'] ?? ''),
            'email'              => $email,
            'phone'              => $phoneSave ?? '',
            'date_of_birth'      => (string) ($staff['date_of_birth'] ?? ''),
            'address'            => (string) ($staff['full_address'] ?? ''),
            'postcode'           => (string) ($staff['eircode'] ?? ''),
            'national_insurance' => (string) ($staff['pps_number'] ?? ''),
            'bank_iban'          => (string) ($staff['bank_iban'] ?? ''),
            'psa_licence'        => apply_export_psa_licence($psaLicence),
            'psa_expiry_date'    => $resolvedPsa['expiry'],
            'profile_status'     => 'Pending Review',
        ];
        $status = apply_resolve_profile_status($vaultRow, $mainStaff);



        $insert = $applyPdo->prepare("

            INSERT INTO staff_master (

                first_name, last_name, address, postcode, email, phone,

                date_of_birth, gender, national_insurance, bank_iban,

                psa_licence, psa_expiry_date, profile_status,

                psa_front_image, psa_back_image

            ) VALUES (

                :first_name, :last_name, :address, :postcode, :email, :phone,

                :dob, :gender, :ni, :iban, :psa, :psa_expiry, :profile_status,

                :psa_front_image, :psa_back_image

            )

        ");



        try {

            $insert->execute([

                'first_name'     => (string) ($staff['first_name'] ?? ''),

                'last_name'      => (string) ($staff['surname'] ?? ''),

                'address'        => (string) ($staff['full_address'] ?? ''),

                'postcode'       => (string) ($staff['eircode'] ?? ''),

                'email'          => $email,

                'phone'          => $phoneSave,

                'dob'            => (string) ($staff['date_of_birth'] ?? ''),

                'gender'         => (string) ($staff['gender'] ?? ''),

                'ni'             => (string) ($staff['pps_number'] ?? ''),

                'iban'           => (string) ($staff['bank_iban'] ?? ''),

                'psa'            => $psaLicence,

                'psa_expiry'     => $resolvedPsa['expiry'],

                'profile_status' => $status,

                'psa_front_image' => trim((string) ($mainStaff['psa_front_image'] ?? '')),

                'psa_back_image'  => trim((string) ($mainStaff['psa_back_image'] ?? '')),

            ]);

            ++$imported;

        } catch (Throwable $e) {

            $errors[] = apply_import_human_error($applyPdo, $email, $e, $psaLicence);

            ++$skipped;

        }

    }



    try {
        apply_clear_temp_psa_licences($applyPdo);
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

