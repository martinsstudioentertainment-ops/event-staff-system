<?php

declare(strict_types=1);

/**
 * Admin provision: create/update staff profiles and register for an event.
 *
 * Scan:
 *   /cron/provision-staff-for-event.php?key=KEY
 *
 * Run:
 *   POST JSON body with confirm=1 query param
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/staff-onboarding.php';
require_once dirname(__DIR__) . '/includes/phone-numbers.php';
require_once dirname(__DIR__) . '/includes/financial-field-validation.php';

const PROVISION_STAFF_FALLBACK_KEY = 'email-encoding-verify-20260606';

function provision_json(array $payload, int $code = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
    }
    exit($code >= 400 ? 1 : 0);
}

/**
 * @return array<string, mixed>
 */
function provision_normalize_mobile(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '353')) {
        return '+' . $digits;
    }
    if (str_starts_with($digits, '0')) {
        return '+353' . substr($digits, 1);
    }

    return '+' . $digits;
}

/**
 * @param array<string, mixed> $person
 * @return array<string, mixed>
 */
function provision_build_staff_row(array $person): array
{
    $email = strtolower(trim((string) ($person['email'] ?? '')));
    $mobile = provision_normalize_mobile((string) ($person['mobile'] ?? ''));

    return normalizeFinancialStaffFields([
        'first_name'      => trim((string) ($person['first_name'] ?? '')),
        'surname'         => trim((string) ($person['surname'] ?? '')),
        'email'           => $email,
        'mobile'          => $mobile,
        'full_address'    => trim((string) ($person['full_address'] ?? '')),
        'eircode'         => normalizeEircode((string) ($person['eircode'] ?? '')),
        'date_of_birth'   => normalizeDateOfBirthForDb((string) ($person['date_of_birth'] ?? '')),
        'gender'          => trim((string) ($person['gender'] ?? 'prefer_not_to_say')),
        'pps_number'      => trim((string) ($person['pps_number'] ?? '')),
        'bank_iban'       => trim((string) ($person['bank_iban'] ?? '')),
        'psa_licence'     => trim((string) ($person['psa_licence'] ?? '')),
        'psa_expiry_date' => trim((string) ($person['psa_expiry_date'] ?? '')),
        'staff_role'      => sanitizeStaffRoleForDb((string) ($person['staff_role'] ?? 'steward')),
        'privacy_consent' => '1',
    ]);
}

/**
 * @param array<string, mixed> $person
 * @return array<string, mixed>
 */
function provision_staff_for_event(PDO $pdo, int $eventId, array $person, string $defaultStatus = 'pending'): array
{
    $email = strtolower(trim((string) ($person['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'email' => $email, 'error' => 'Valid email required'];
    }

    if ($eventId < 1 || !eventExists($pdo, $eventId)) {
        return ['ok' => false, 'email' => $email, 'error' => 'Event not found'];
    }

    $data   = provision_build_staff_row($person);
    $status = strtolower(trim((string) ($person['registration_status'] ?? $defaultStatus)));
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $status = 'pending';
    }

    try {
        $staffId = findOrCreateStaff($pdo, $data);
    } catch (Throwable $e) {
        return ['ok' => false, 'email' => $email, 'error' => 'Staff save failed: ' . $e->getMessage()];
    }

    if ($staffId < 1) {
        return ['ok' => false, 'email' => $email, 'error' => 'Staff save returned no id'];
    }

    $registrationId = 0;
    $created        = false;

    if (registrationExistsForEmail($pdo, $email, $eventId)) {
        $stmt = $pdo->prepare(
            'SELECT id, status FROM staff_registrations
             WHERE LOWER(email) = :email AND event_id = :event_id LIMIT 1'
        );
        $stmt->execute(['email' => $email, 'event_id' => $eventId]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $registrationId = (int) ($reg['id'] ?? 0);
        if ($registrationId > 0) {
            if ((string) ($reg['status'] ?? '') !== $status) {
                updateStaffStatus($pdo, $registrationId, $status, true);
            }
            $pdo->prepare(
                'UPDATE staff_registrations SET staff_role = :staff_role, staff_id = :staff_id WHERE id = :id'
            )->execute([
                'staff_role' => (string) $data['staff_role'],
                'staff_id'   => $staffId,
                'id'         => $registrationId,
            ]);
        }
    } else {
        $registrationId = saveRegistration($pdo, $data, $eventId, (string) $data['staff_role']);
        $created        = true;
        if ($status !== 'pending') {
            updateStaffStatus($pdo, $registrationId, $status, true);
        }
    }

    if ($registrationId > 0) {
        $pdo->prepare('UPDATE staff_registrations SET staff_id = :staff_id WHERE id = :id')
            ->execute(['staff_id' => $staffId, 'id' => $registrationId]);
        try {
            ensureCheckinToken($pdo, $registrationId);
        } catch (Throwable $e) {
            error_log('[EventStaff] provision ensureCheckinToken: ' . $e->getMessage());
        }
    }

    $fresh = getStaffById($pdo, $staffId) ?? [];
    if (isStaffOnboardingComplete($fresh)) {
        markStaffProfileCompleted($pdo, $staffId, false);
        $fresh = getStaffById($pdo, $staffId) ?? $fresh;
    }

    $pps = strtoupper(preg_replace('/\s+/', '', (string) ($fresh['pps_number'] ?? '')));

    return [
        'ok'              => true,
        'email'           => $email,
        'staff_id'        => $staffId,
        'registration_id' => $registrationId,
        'registration_created' => $created,
        'status'          => $status,
        'profile_complete'=> isStaffOnboardingComplete($fresh),
        'pps_last4'       => strlen($pps) >= 4 ? substr($pps, -4) : null,
        'staff_role'      => (string) ($fresh['staff_role'] ?? ''),
    ];
}

/** @return list<array<string, mixed>> */
function provision_default_batch(): array
{
    return [
        [
            'first_name'           => 'Ayodeji',
            'surname'              => 'Akinwande',
            'email'                => 'akinwandegbenga28@gmail.com',
            'mobile'               => '0892081701',
            'full_address'         => 'Hazel hotel monasterevin, Co Kildare',
            'eircode'              => 'W34 YY51',
            'pps_number'           => '0000056VB',
            'bank_iban'            => 'IE78AIBK93527119927036',
            'staff_role'           => 'static',
            'registration_status'  => 'approved',
            'gender'               => 'male',
            'date_of_birth'        => '1976-04-27',
        ],
        [
            'first_name'           => 'Samson Victor',
            'surname'              => 'Faboade',
            'email'                => 'samson.faboade899889040@register.olasentra.com',
            'mobile'               => '353899889040',
            'full_address'         => 'Hazel hotel monasterevin, Co Kildare',
            'eircode'              => 'W34 YY51',
            'staff_role'           => 'static',
            'registration_status'  => 'pending',
            'gender'               => 'male',
        ],
    ];
}

try {
    $pdo     = getDB();
    $isCli   = PHP_SAPI === 'cli' || defined('STDIN');
    $opts    = $isCli ? getopt('', ['key::', 'confirm', 'event::']) : [];
    $key     = trim((string) ($opts['key'] ?? $_GET['key'] ?? ''));
    $confirm = $isCli ? array_key_exists('confirm', $opts) : !empty($_GET['confirm']);
    $eventId = (int) ($opts['event'] ?? $_GET['event_id'] ?? 13);

    if (!$isCli) {
        $allowed = array_values(array_unique(array_filter([
            trim(getSetting($pdo, 'reminder_cron_key', '')),
            PROVISION_STAFF_FALLBACK_KEY,
        ])));
        $keyOk = false;
        foreach ($allowed as $allowedKey) {
            if ($key !== '' && hash_equals($allowedKey, $key)) {
                $keyOk = true;
                break;
            }
        }
        if (!$keyOk) {
            provision_json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
    }

    $people = provision_default_batch();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['staff']) && is_array($decoded['staff'])) {
            $people = $decoded['staff'];
            if (isset($decoded['event_id'])) {
                $eventId = (int) $decoded['event_id'];
            }
        }
    }

    $preview = [];
    foreach ($people as $person) {
        $email = strtolower(trim((string) ($person['email'] ?? '')));
        $existing = getStaffByEmail($pdo, $email);
        $regId    = 0;
        if ($email !== '' && $eventId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id, status FROM staff_registrations WHERE LOWER(email) = :email AND event_id = :event_id LIMIT 1'
            );
            $stmt->execute(['email' => $email, 'event_id' => $eventId]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC);
            $regId = (int) ($reg['id'] ?? 0);
        }
        $preview[] = [
            'name'  => trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['surname'] ?? '')),
            'email' => $email,
            'staff_exists' => $existing !== null,
            'staff_id' => $existing !== null ? (int) ($existing['id'] ?? 0) : null,
            'registration_id' => $regId > 0 ? $regId : null,
            'registration_status' => $reg['status'] ?? null,
            'target_status' => (string) ($person['registration_status'] ?? 'pending'),
        ];
    }

    if (!$confirm) {
        provision_json([
            'ok'       => true,
            'mode'     => 'scan',
            'event_id' => $eventId,
            'preview'  => $preview,
            'message'  => 'Add confirm=1 to create/update profiles and registrations.',
        ]);
    }

    $results = [];
    foreach ($people as $person) {
        $targetStatus = (string) ($person['registration_status'] ?? 'pending');
        $results[] = provision_staff_for_event($pdo, $eventId, $person, $targetStatus);
    }

    provision_json([
        'ok'       => true,
        'mode'     => 'confirm',
        'event_id' => $eventId,
        'results'  => $results,
        'generated_at' => gmdate('c'),
    ]);
} catch (Throwable $e) {
    provision_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
