<?php

/**
 * Employee payroll spreadsheet columns (Google Sheets + admin CSV).
 * Matches Staff Template.ods row 1 (A–J). Sync adds K–O: Registration ID, Status, Event date, Event name, Role.
 * Postcode column uses eircode from registration.
 */

require_once __DIR__ . '/staff-labels.php';

/**
 * @return list<string>
 */
function getEmployeeSpreadsheetHeaders(): array
{
    return [
        'Surname',
        'First Name',
        'Full Address',
        'Postcode',
        'Email',
        'Mobile Number',
        'Date Of Birth',
        'Gender',
        'National Insurance/PPS',
        'Bank Account/IBAN',
    ];
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function buildEmployeeSpreadsheetRow(array $row): array
{
    return [
        (string) ($row['surname'] ?? ''),
        (string) ($row['first_name'] ?? ''),
        (string) ($row['full_address'] ?? ''),
        (string) ($row['eircode'] ?? ''),
        (string) ($row['email'] ?? ''),
        (string) ($row['mobile'] ?? ''),
        (string) ($row['date_of_birth'] ?? ''),
        formatGenderLabel((string) ($row['gender'] ?? '')),
        (string) ($row['pps_number'] ?? ''),
        (string) ($row['bank_iban'] ?? ''),
    ];
}

/**
 * Full admin CSV download (payroll + PSA + event metadata).
 *
 * @return list<string>
 */
function getFullStaffExportHeaders(): array
{
    return array_merge(
        getEmployeeSpreadsheetHeaders(),
        [
            'PSA Licence',
            'PSA Expiry',
            'PSA Front Image',
            'PSA Back Image',
            'Profile complete',
            'Registration ID',
            'Status',
            'Event date',
            'Event name',
            'Role',
        ]
    );
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function buildFullStaffExportRow(array $row): array
{
    require_once __DIR__ . '/events-repository.php';
    require_once __DIR__ . '/staff-onboarding.php';

    $eventDate = !empty($row['event_date'])
        ? formatEventDateLabel((string) $row['event_date'])
        : '';

    return array_merge(
        buildEmployeeSpreadsheetRow($row),
        [
            (string) ($row['psa_licence'] ?? ''),
            (string) ($row['psa_expiry_date'] ?? ''),
            (string) ($row['psa_front_image'] ?? ''),
            (string) ($row['psa_back_image'] ?? ''),
            isStaffOnboardingComplete($row) ? 'Yes' : 'No',
            (string) ($row['id'] ?? ''),
            formatStatusLabel((string) ($row['status'] ?? '')),
            $eventDate,
            trim((string) ($row['event_name'] ?? '')),
            formatRoleLabel((string) ($row['staff_role'] ?? '')),
        ]
    );
}
