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
