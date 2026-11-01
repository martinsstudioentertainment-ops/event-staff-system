<?php

declare(strict_types=1);

/**
 * Admin PSA licence lookup and update (name or email → details → save).
 */

require_once __DIR__ . '/financial-field-validation.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-registration-schema.php';
require_once __DIR__ . '/staff-psa.php';
require_once __DIR__ . '/psa-licence-verify.php';
require_once __DIR__ . '/validation.php';

function googleFormPsaSelfUpdateSyncRegistrations(
    PDO $pdo,
    int $staffId,
    string $email,
    string $licence,
    string $expiry
): void {
    if ($staffId < 1) {
        return;
    }

    $sets = [];
    $params = [
        'staff_id' => $staffId,
        'email'    => strtolower(trim($email)),
    ];
    if (staffRegistrationColumnExists($pdo, 'psa_licence')) {
        $sets[] = 'psa_licence = :psa_licence';
        $params['psa_licence'] = $licence;
    }
    if ($expiry !== '' && staffRegistrationColumnExists($pdo, 'psa_expiry_date')) {
        $sets[] = 'psa_expiry_date = :psa_expiry_date';
        $params['psa_expiry_date'] = $expiry;
    }
    if ($sets === []) {
        return;
    }

    try {
        $pdo->prepare(
            'UPDATE staff_registrations SET ' . implode(', ', $sets) . '
             WHERE staff_id = :staff_id OR LOWER(email) = :email'
        )->execute($params);
    } catch (Throwable $e) {
        error_log('[EventStaff] PSA admin update registrations: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string, mixed>>
 */
function googleFormPsaAdminSearch(PDO $pdo, string $query, int $limit = 20): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $limit = max(1, min(40, $limit));
    $like = '%' . $query . '%';
    $emailExact = strtolower($query);

    $sql = 'SELECT s.id, s.first_name, s.surname, s.email, s.mobile, s.staff_role,
                   s.psa_licence, s.psa_expiry_date, s.psa_register_status, s.psa_register_name
            FROM staff s
            WHERE LOWER(s.email) = :email_exact
               OR LOWER(s.email) LIKE LOWER(:like1)
               OR LOWER(s.first_name) LIKE LOWER(:like2)
               OR LOWER(s.surname) LIKE LOWER(:like3)
               OR LOWER(CONCAT(TRIM(s.first_name), \' \', TRIM(s.surname))) LIKE LOWER(:like4)
               OR LOWER(CONCAT(TRIM(s.surname), \' \', TRIM(s.first_name))) LIKE LOWER(:like5)
            ORDER BY
                CASE WHEN LOWER(s.email) = :email_rank THEN 0 ELSE 1 END,
                s.surname, s.first_name
            LIMIT ' . $limit;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'email_exact' => $emailExact,
            'like1'       => $like,
            'like2'       => $like,
            'like3'       => $like,
            'like4'       => $like,
            'like5'       => $like,
            'email_rank'  => $emailExact,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] PSA admin search: ' . $e->getMessage());
        $rows = [];
    }

    foreach ($rows as $i => $row) {
        $rows[$i]['events'] = googleFormPsaAdminStaffEvents($pdo, (int) ($row['id'] ?? 0), (string) ($row['email'] ?? ''));
    }

    return $rows;
}

/**
 * @return list<array{name: string, date: string}>
 */
function googleFormPsaAdminStaffEvents(PDO $pdo, int $staffId, string $email): array
{
    $email = strtolower(trim($email));
    $events = [];
    try {
        $sql = 'SELECT e.name, e.event_date
                FROM staff_registrations sr
                INNER JOIN events e ON e.id = sr.event_id
                WHERE sr.staff_id = :staff_id';
        $params = ['staff_id' => $staffId];
        if ($email !== '') {
            $sql .= ' OR LOWER(sr.email) = :email';
            $params['email'] = $email;
        }
        $sql .= ' ORDER BY e.event_date DESC, e.id DESC LIMIT 8';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $events[] = [
                'name' => (string) ($row['name'] ?? ''),
                'date' => substr((string) ($row['event_date'] ?? ''), 0, 10),
            ];
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] PSA admin events: ' . $e->getMessage());
    }

    return $events;
}

/**
 * @return array{ok: bool, message: string, psa_status?: string}
 */
function googleFormPsaAdminUpdate(PDO $pdo, int $staffId, string $licence): array
{
    if ($staffId < 1) {
        return ['ok' => false, 'message' => 'Select a person first.'];
    }

    $licence = normalizePsaLicence($licence);
    $psaFormatError = validatePsaLicenceField($licence, true);
    if ($psaFormatError !== null) {
        return ['ok' => false, 'message' => $psaFormatError];
    }

    ensureStaffPsaSchema($pdo);
    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        return ['ok' => false, 'message' => 'That person was not found.'];
    }

    $holder = trim((string) ($staff['first_name'] ?? '') . ' ' . (string) ($staff['surname'] ?? ''));
    $verify = applyPsaRegisterCheckToStaff($pdo, $staffId, $licence, $holder);
    $expiry = trim((string) ($verify['expiry_date'] ?? ''));
    googleFormPsaSelfUpdateSyncRegistrations(
        $pdo,
        $staffId,
        (string) ($staff['email'] ?? ''),
        $licence,
        $expiry
    );

    $sheetPush = ['updated' => 0, 'failed' => 0];
    try {
        require_once __DIR__ . '/google-form-sheet-sync.php';
        $sheetPush = googleFormPushPsaAfterOlasentraUpdate($pdo, $staffId, $licence);
    } catch (Throwable $e) {
        error_log('[EventStaff] PSA sheet push: ' . $e->getMessage());
        $sheetPush['failed'] = 1;
    }

    $status = trim((string) ($verify['status'] ?? ''));
    $detail = trim((string) ($verify['message'] ?? ''));
    $name = trim($holder) !== '' ? $holder : (string) ($staff['email'] ?? 'Staff');
    if ($status === 'verified') {
        $message = $name . ' — PSA updated and verified on the official register.';
        try {
            require_once __DIR__ . '/google-form-intake.php';
            $staffAfter = getStaffById($pdo, $staffId) ?? $staff;
            googleFormSendShiftDetailsForStaff($pdo, $staffAfter);
        } catch (Throwable $e) {
            error_log('[EventStaff] PSA admin shift email: ' . $e->getMessage());
        }
    } elseif ($detail !== '') {
        $message = $name . ' — PSA updated. ' . $detail;
    } else {
        $message = $name . ' — PSA updated.';
    }
    if ((int) ($sheetPush['updated'] ?? 0) > 0) {
        $message .= ' Google Form sheet updated.';
    } elseif ((int) ($sheetPush['failed'] ?? 0) > 0) {
        $message .= ' Google Form sheet could not be written yet.';
    }

    return [
        'ok'         => true,
        'message'    => $message,
        'psa_status' => $status,
    ];
}
