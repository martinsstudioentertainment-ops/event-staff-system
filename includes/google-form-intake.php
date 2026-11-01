<?php

declare(strict_types=1);

/**
 * Google Form → Olasentra staff intake for AVIVA Stadium (1 Oct 2026, 16:00–23:00).
 * Collects staff details for the Aviva event, including PSA badge front and back photos.
 * Does not collect PPS or date of birth.
 */
require_once __DIR__ . '/financial-field-validation.php';
require_once __DIR__ . '/date-format.php';

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-registration-schema.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/phone-numbers.php';
require_once __DIR__ . '/venues-repository.php';
require_once __DIR__ . '/psa-licence-verify.php';
require_once __DIR__ . '/staff-psa.php';

const GOOGLE_FORM_AVIVA_EVENT_NAME = 'AVIVA Stadium — 1 October 2026';
const GOOGLE_FORM_AVIVA_EVENT_DATE = '2026-10-01';
const GOOGLE_FORM_AVIVA_START = '16:00:00';
const GOOGLE_FORM_AVIVA_END = '23:00:00';
define('GOOGLE_FORM_INTAKE_BUILTIN_KEY', (defined('OLASENTRA_GOOGLE_FORM_INTAKE_KEY') ? OLASENTRA_GOOGLE_FORM_INTAKE_KEY : ''));
const GOOGLE_FORM_AVIVA_SHEET_ID = '1obP7yExDfg4V62xVx1kGj94nPMxut_k1HICECdi1AF4';
// Share this sheet as Viewer with the live SA email from storage/google/service-account.json (currently event-staff-sheets@...).
const GOOGLE_FORM_AVIVA_SHEET_TAB = 'Form Responses 1';

require_once __DIR__ . '/google-form-event-integrations.php';

function googleFormIntakeExpectedKeys(PDO $pdo): array
{
    $keys = [];
    foreach ([
        trim((string) getSetting($pdo, 'google_form_intake_key', '')),
        trim((string) getSetting($pdo, 'reminder_cron_key', '')),
        GOOGLE_FORM_INTAKE_BUILTIN_KEY,
    ] as $key) {
        if ($key !== '') {
            $keys[] = $key;
        }
    }

    $file = dirname(__DIR__) . '/storage/google/form-intake-key.txt';
    if (is_file($file)) {
        $fromFile = trim((string) file_get_contents($file));
        if ($fromFile !== '') {
            $keys[] = $fromFile;
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Re-check every stored PSA licence number against psa-gov.ie.
 *
 * @return array<string, mixed>
 */
function googleFormIntakeReverifyExistingLicences(PDO $pdo, int $limit = 80, int $offset = 0): array
{
    ensureStaffPsaSchema($pdo);
    $limit = max(1, min(120, $limit));
    $offset = max(0, $offset);

    $total = 0;
    try {
        $total = (int) $pdo->query(
            "SELECT COUNT(*) FROM staff
             WHERE psa_licence IS NOT NULL
               AND TRIM(psa_licence) <> ''
               AND psa_licence NOT LIKE 'TEMP-PSA-%'"
        )->fetchColumn();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not read staff licences: ' . $e->getMessage()];
    }

    $stmt = $pdo->prepare(
        "SELECT id, first_name, surname, psa_licence, psa_expiry_date, psa_register_status
         FROM staff
         WHERE psa_licence IS NOT NULL
           AND TRIM(psa_licence) <> ''
           AND psa_licence NOT LIKE 'TEMP-PSA-%'
         ORDER BY id ASC
         LIMIT :lim OFFSET :off"
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $counts = [
        'verified' => 0,
        'expired' => 0,
        'not_found' => 0,
        'name_mismatch' => 0,
        'invalid_format' => 0,
        'unreachable' => 0,
        'other' => 0,
    ];
    $results = [];

    foreach ($rows as $row) {
        $staffId = (int) ($row['id'] ?? 0);
        $licence = normalizePsaLicence((string) ($row['psa_licence'] ?? ''));
        $holder = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));
        $verify = lookupPsaLicenceOnRegister($licence, $holder !== '' ? $holder : null);
        $status = (string) ($verify['status'] ?? 'unreachable');
        if (isset($counts[$status])) {
            $counts[$status]++;
        } else {
            $counts['other']++;
        }

        $fields = [
            'psa_licence' => $licence,
            'psa_register_status' => $status,
            'psa_register_name' => (string) ($verify['register_name'] ?? ''),
            'psa_register_checked_at' => date('Y-m-d H:i:s'),
        ];
        $expiry = (string) ($verify['expiry_date'] ?? '');
        if ($expiry !== '') {
            $fields['psa_expiry_date'] = $expiry;
        }
        if ($staffId > 0) {
            googleFormIntakeUpdateStaff($pdo, $staffId, $fields);
        }

        $results[] = [
            'staff_id' => $staffId,
            'name' => $holder,
            'licence' => $licence,
            'status' => $status,
            'register_name' => (string) ($verify['register_name'] ?? ''),
            'expiry_date' => $expiry,
            'message' => (string) ($verify['message'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'action' => 'psa_reverify',
        'total_with_licence' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'checked' => count($results),
        'counts' => $counts,
        'results' => $results,
        'next_offset' => ($offset + count($results) < $total) ? $offset + count($results) : null,
    ];
}

/**
 * Live staff totals: whole table vs licences vs AVIVA registrations.
 *
 * @return array<string, mixed>
 */
function googleFormIntakeStaffCensus(PDO $pdo): array
{
    ensureStaffPsaSchema($pdo);
    require_once __DIR__ . '/mailer.php';
    ensureMailBccCopySetting($pdo);
    $event = googleFormIntakeEnsureAvivaEvent($pdo);
    $eventId = (int) ($event['id'] ?? 0);

    $count = static function (PDO $pdo, string $sql, array $params = []): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    };

    $registerStatus = [];
    try {
        $rows = $pdo->query(
            "SELECT COALESCE(NULLIF(TRIM(psa_register_status), ''), '(unchecked)') AS status, COUNT(*) AS n
             FROM staff
             WHERE psa_licence IS NOT NULL
               AND TRIM(psa_licence) <> ''
               AND psa_licence NOT LIKE 'TEMP-PSA-%'
             GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $registerStatus[(string) $row['status']] = (int) $row['n'];
        }
    } catch (Throwable $e) {
        $registerStatus = ['error' => $e->getMessage()];
    }

    $avivaByStatus = [];
    $avivaPsaStatus = [];
    $avivaPsaPeople = [];
    if ($eventId > 0) {
        try {
            $rows = $pdo->prepare(
                'SELECT COALESCE(NULLIF(TRIM(status), ""), "(none)") AS status, COUNT(*) AS n
                 FROM staff_registrations
                 WHERE event_id = :event_id
                 GROUP BY status'
            );
            $rows->execute(['event_id' => $eventId]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $avivaByStatus[(string) $row['status']] = (int) $row['n'];
            }
        } catch (Throwable $e) {
            $avivaByStatus = ['error' => $e->getMessage()];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT s.first_name, s.surname, s.email, s.psa_licence,
                        COALESCE(NULLIF(TRIM(s.psa_register_status), ''), '(unchecked)') AS psa_status,
                        s.psa_register_name
                 FROM staff_registrations sr
                 INNER JOIN staff s ON s.id = sr.staff_id
                 WHERE sr.event_id = :event_id
                 ORDER BY s.surname ASC, s.first_name ASC"
            );
            $stmt->execute(['event_id' => $eventId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $status = (string) ($row['psa_status'] ?? '(unchecked)');
                $avivaPsaStatus[$status] = (int) ($avivaPsaStatus[$status] ?? 0) + 1;
                $avivaPsaPeople[] = [
                    'name'          => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? '')),
                    'email'         => (string) ($row['email'] ?? ''),
                    'psa_licence'   => (string) ($row['psa_licence'] ?? ''),
                    'psa_status'    => $status,
                    'register_name' => (string) ($row['psa_register_name'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $avivaPsaStatus = ['error' => $e->getMessage()];
        }
    }

    return [
        'ok' => true,
        'action' => 'staff_census',
        'staff_total' => $count($pdo, 'SELECT COUNT(*) FROM staff'),
        'staff_active' => $count($pdo, 'SELECT COUNT(*) FROM staff WHERE is_blacklisted = 0'),
        'staff_blacklisted' => $count($pdo, 'SELECT COUNT(*) FROM staff WHERE is_blacklisted = 1'),
        'with_real_licence' => $count(
            $pdo,
            "SELECT COUNT(*) FROM staff
             WHERE psa_licence IS NOT NULL
               AND TRIM(psa_licence) <> ''
               AND psa_licence NOT LIKE 'TEMP-PSA-%'"
        ),
        'with_temp_licence' => $count($pdo, "SELECT COUNT(*) FROM staff WHERE psa_licence LIKE 'TEMP-PSA-%'"),
        'without_licence' => $count(
            $pdo,
            "SELECT COUNT(*) FROM staff
             WHERE psa_licence IS NULL OR TRIM(psa_licence) = ''"
        ),
        'register_status' => $registerStatus,
        'aviva_event_id' => $eventId,
        'aviva_event' => (string) ($event['name'] ?? ''),
        'aviva_registrations' => $eventId > 0
            ? $count($pdo, 'SELECT COUNT(*) FROM staff_registrations WHERE event_id = :event_id', ['event_id' => $eventId])
            : 0,
        'aviva_by_status' => $avivaByStatus,
        'aviva_psa_status' => $avivaPsaStatus,
        'aviva_psa_people' => $avivaPsaPeople,
        'registrations_total' => $count($pdo, 'SELECT COUNT(*) FROM staff_registrations'),
    ];
}

function googleFormIntakeKeyValid(PDO $pdo, string $provided): bool
{
    $provided = trim($provided);
    if ($provided === '') {
        return false;
    }

    foreach (googleFormIntakeExpectedKeys($pdo) as $expected) {
        if (hash_equals($expected, $provided)) {
            return true;
        }
    }

    return false;
}

function googleFormIntakePick(array $data, array $aliases): string
{
    foreach ($aliases as $alias) {
        if (!array_key_exists($alias, $data)) {
            continue;
        }
        $value = $data[$alias];
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function googleFormIntakeNormalizeGender(string $raw): string
{
    $n = strtolower(trim($raw));
    $n = str_replace(['_', '-'], ' ', $n);

    return match (true) {
        $n === 'male' || $n === 'm' => 'male',
        $n === 'female' || $n === 'f' => 'female',
        default => '',
    };
}

function googleFormIntakeNormalizeRole(string $raw): string
{
    $n = strtolower(trim($raw));
    $n = str_replace(['_', '-'], ' ', $n);

    if ($n === '') {
        return '';
    }

    if (str_contains($n, 'static')) {
        return 'static';
    }

    if (str_contains($n, 'dsp') || str_contains($n, 'door') || str_contains($n, 'supervisor')) {
        return 'dsp';
    }

    return '';
}

/**
 * @return array<string, mixed>|null
 */
function googleFormIntakeFindAvivaEvent(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM events
         WHERE event_date = :event_date
           AND (
                LOWER(name) LIKE '%aviva%'
                OR LOWER(IFNULL(location, '')) LIKE '%aviva%'
           )
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute(['event_date' => GOOGLE_FORM_AVIVA_EVENT_DATE]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array<string, mixed>
 */
function googleFormIntakeEnsureAvivaEvent(PDO $pdo): array
{
    $existing = googleFormIntakeFindAvivaEvent($pdo);
    if ($existing !== null) {
        $needsTime = (string) ($existing['start_time'] ?? '') !== GOOGLE_FORM_AVIVA_START
            || (string) ($existing['end_time'] ?? '') !== GOOGLE_FORM_AVIVA_END;
        $needsName = trim((string) ($existing['name'] ?? '')) !== GOOGLE_FORM_AVIVA_EVENT_NAME;
        if ($needsTime || $needsName || (int) ($existing['is_active'] ?? 0) !== 1) {
            try {
                $pdo->prepare(
                    'UPDATE events
                     SET name = :name, start_time = :start_time, end_time = :end_time,
                         checkin_open_time = :checkin_open_time, checkin_close_time = :checkin_close_time,
                         times_confirmed = 1, is_active = 1
                     WHERE id = :id'
                )->execute([
                    'name'              => GOOGLE_FORM_AVIVA_EVENT_NAME,
                    'start_time'        => GOOGLE_FORM_AVIVA_START,
                    'end_time'          => GOOGLE_FORM_AVIVA_END,
                    'checkin_open_time' => GOOGLE_FORM_AVIVA_START,
                    'checkin_close_time' => GOOGLE_FORM_AVIVA_END,
                    'id'                => (int) $existing['id'],
                ]);
            } catch (Throwable $e) {
                $pdo->prepare(
                    'UPDATE events
                     SET name = :name, start_time = :start_time, end_time = :end_time, is_active = 1
                     WHERE id = :id'
                )->execute([
                    'name'       => GOOGLE_FORM_AVIVA_EVENT_NAME,
                    'start_time' => GOOGLE_FORM_AVIVA_START,
                    'end_time'   => GOOGLE_FORM_AVIVA_END,
                    'id'         => (int) $existing['id'],
                ]);
            }
            $existing = getEventById($pdo, (int) $existing['id']) ?? $existing;
        }

        return $existing;
    }

    $payload = [
        'name'                => GOOGLE_FORM_AVIVA_EVENT_NAME,
        'event_date'          => GOOGLE_FORM_AVIVA_EVENT_DATE,
        'location'            => 'AVIVA Stadium, Lansdowne Road, Dublin 4',
        'venue_eircode'       => 'D04 K5F8',
        'venue_lat'           => '53.3352',
        'venue_lng'           => '-6.2285',
        'start_time'          => '16:00',
        'end_time'            => '23:00',
        'checkin_open_time'   => '16:00',
        'checkin_close_time'  => '23:00',
        'times_confirmed'     => 1,
        'is_active'           => 1,
        'work_type'           => 'special_event',
        'roles_needed'        => ['dsp', 'static'],
        'signin_radius_m'     => 120,
    ];

    try {
        $id = createEvent($pdo, $payload);
    } catch (Throwable $e) {
        error_log('[EventStaff] Aviva createEvent: ' . $e->getMessage());
        $stmt = $pdo->prepare(
            'INSERT INTO events (name, event_date, location, venue_eircode, venue_lat, venue_lng, start_time, end_time, is_active)
             VALUES (:name, :event_date, :location, :venue_eircode, :venue_lat, :venue_lng, :start_time, :end_time, 1)'
        );
        $stmt->execute([
            'name'          => GOOGLE_FORM_AVIVA_EVENT_NAME,
            'event_date'    => GOOGLE_FORM_AVIVA_EVENT_DATE,
            'location'      => 'AVIVA Stadium, Lansdowne Road, Dublin 4',
            'venue_eircode' => 'D04 K5F8',
            'venue_lat'     => '53.3352',
            'venue_lng'     => '-6.2285',
            'start_time'    => GOOGLE_FORM_AVIVA_START,
            'end_time'      => GOOGLE_FORM_AVIVA_END,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    $created = getEventById($pdo, $id);
    if ($created === null) {
        throw new RuntimeException('Could not create the AVIVA Stadium event.');
    }

    return $created;
}

/**
 * Keep the live Aviva sheet mapped to AVIVA Stadium — 1 October 2026.
 *
 * @return array<string, mixed>|null
 */
function googleFormIntakeSeedAvivaIntegration(PDO $pdo): ?array
{
    ensureGoogleFormEventIntegrationsSchema($pdo);
    $existing = googleFormEventIntegrationBySheetId($pdo, GOOGLE_FORM_AVIVA_SHEET_ID);
    $event = googleFormIntakeFindAvivaEvent($pdo);
    if ($event === null) {
        return $existing;
    }
    $eventId = (int) $event['id'];
    $processed = (int) getSetting($pdo, 'google_form_aviva_sheet_rows', '0');
    if ($processed < 0) {
        $processed = 0;
    }
    $formUrl = trim((string) getSetting($pdo, 'google_form_aviva_url', ''));

    if ($existing === null) {
        $pdo->prepare(
            'INSERT INTO google_form_event_integrations (
                event_id, google_form_id, google_form_url, google_sheet_id, google_sheet_url,
                google_sheet_tab, enabled, processed_rows, last_permission_ok, is_locked, source
            ) VALUES (
                :event_id, :google_form_id, :google_form_url, :google_sheet_id, :google_sheet_url,
                :google_sheet_tab, 1, :processed_rows, 1, 1, :source
            )'
        )->execute([
            'event_id'         => $eventId,
            'google_form_id'   => '1mg5FS1yh5WvN9AYjap7ArJFUDaAp8O_yNC1fLRZF3qw',
            'google_form_url'  => $formUrl !== '' ? $formUrl : 'https://docs.google.com/forms/d/1mg5FS1yh5WvN9AYjap7ArJFUDaAp8O_yNC1fLRZF3qw/edit',
            'google_sheet_id'  => GOOGLE_FORM_AVIVA_SHEET_ID,
            'google_sheet_url' => googleFormEventIntegrationSheetUrl(GOOGLE_FORM_AVIVA_SHEET_ID),
            'google_sheet_tab' => GOOGLE_FORM_AVIVA_SHEET_TAB,
            'processed_rows'   => $processed,
            'source'           => 'aviva_legacy',
        ]);

        return googleFormEventIntegrationBySheetId($pdo, GOOGLE_FORM_AVIVA_SHEET_ID);
    }

    $pdo->prepare(
        'UPDATE google_form_event_integrations
         SET event_id = :event_id,
             google_sheet_id = :google_sheet_id,
             google_sheet_url = :google_sheet_url,
             google_sheet_tab = :google_sheet_tab,
             enabled = 1,
             is_locked = 1,
             source = :source,
             processed_rows = GREATEST(processed_rows, :processed_rows)
         WHERE id = :id'
    )->execute([
        'event_id'         => $eventId,
        'google_sheet_id'  => GOOGLE_FORM_AVIVA_SHEET_ID,
        'google_sheet_url' => googleFormEventIntegrationSheetUrl(GOOGLE_FORM_AVIVA_SHEET_ID),
        'google_sheet_tab' => GOOGLE_FORM_AVIVA_SHEET_TAB,
        'source'           => 'aviva_legacy',
        'processed_rows'   => $processed,
        'id'               => (int) $existing['id'],
    ]);

    return googleFormEventIntegrationById($pdo, (int) $existing['id']);
}

/**
 * Direct API posts without event_id stay on Aviva. Sheet sync always passes event_id.
 *
 * @param array<string, mixed> $payload
 * @return array{ok: bool, event?: array<string, mixed>, error?: string}
 */
function googleFormIntakeResolveTargetEvent(PDO $pdo, array $payload): array
{
    $eventId = (int) ($payload['event_id'] ?? 0);
    if ($eventId > 0) {
        $event = getEventById($pdo, $eventId);
        if ($event === null) {
            return ['ok' => false, 'error' => 'That event was not found.'];
        }

        return ['ok' => true, 'event' => $event];
    }

    return ['ok' => true, 'event' => googleFormIntakeEnsureAvivaEvent($pdo)];
}

/**
 * Politely ask staff to re-check a PSA number that did not match the register.
 * Does not say the licence is fake.
 */
function sendStaffPsaNumberCheckEmail(PDO $pdo, array $staff): bool
{
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/email-layout.php';
    require_once __DIR__ . '/company.php';

    $email = trim((string) ($staff['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $firstName = trim((string) ($staff['first_name'] ?? ''));
    $licence   = trim((string) ($staff['psa_licence'] ?? ''));
    $siteName  = getSiteName($pdo);
    $contact   = trim(getCompanyEmail($pdo));
    if ($contact === '' || !filter_var($contact, FILTER_VALIDATE_EMAIL) || str_contains(strtolower($contact), 'example.com')) {
        $contact = 'info@olasentra.com';
    }

    $subject = $siteName . ' — please check your PSA licence number';
    $greeting = $firstName !== '' ? 'Hi ' . $firstName . ',' : 'Hi,';
    $licenceLine = $licence !== '' ? 'The number we have is ' . $licence . '.' : 'We could not read a complete PSA licence number from your form.';

    $text = $greeting . "\n\n"
        . "Thank you for applying for AVIVA Stadium on 1 October 2026.\n\n"
        . "We could not match the PSA licence number on your form with the official PSA registered employees list. This is often a missing digit or a typing mistake.\n\n"
        . $licenceLine . "\n\n"
        . "Please check the number on your PSA badge. If it looks right, contact the Private Security Authority (PSA) to confirm it is on the registered employees list:\n"
        . "https://www.psa-gov.ie/psa-registered-employees/\n\n"
        . "Or email Olasentra at {$contact} with the correct number so we can update your application.\n\n"
        . "You do not need to fill the Google Form again.\n\n"
        . "— {$siteName}\n";

    $html = '<p style="margin:0 0 12px;">' . emailEsc($greeting) . '</p>'
        . buildEmailNotificationCard(
            $pdo,
            'Please check your PSA licence number',
            '<p style="margin:0 0 10px;">Thank you for applying for AVIVA Stadium on 1 October 2026.</p>'
            . '<p style="margin:0 0 10px;">We could not match the PSA licence number on your form with the official PSA registered employees list. This is often a missing digit or a typing mistake.</p>'
            . '<p style="margin:0 0 10px;">' . emailEsc($licenceLine) . '</p>'
            . '<p style="margin:0 0 10px;">Please check the number on your PSA badge. If it looks right, contact the Private Security Authority (PSA) to confirm it is on the registered employees list.</p>'
            . '<p style="margin:0;">Or email Olasentra at <a href="mailto:' . emailEsc($contact) . '">' . emailEsc($contact) . '</a> with the correct number so we can update your application. You do not need to fill the Google Form again.</p>',
            'https://www.psa-gov.ie/psa-registered-employees/',
            'Open PSA registered employees list'
        );

    return sendEmail($pdo, $email, $subject, $text, $html);
}

/**
 * @param array<string, mixed> $staff
 * @param array<string, mixed> $verify
 */
function sendStaffPsaNumberCheckEmailIfNeeded(PDO $pdo, array $staff, array $verify): void
{
    $status = strtolower(trim((string) ($verify['status'] ?? $staff['psa_register_status'] ?? '')));
    if (!in_array($status, ['not_found', 'invalid_format'], true)) {
        return;
    }

    $staffId = (int) ($staff['id'] ?? 0);
    if ($staffId > 0) {
        $sentKey = 'psa_number_check_email_sent_' . $staffId;
        if (trim((string) getSetting($pdo, $sentKey, '')) === '1') {
            return;
        }
        if (sendStaffPsaNumberCheckEmail($pdo, $staff)) {
            setSetting($pdo, $sentKey, '1');
        }
        return;
    }

    sendStaffPsaNumberCheckEmail($pdo, $staff);
}

function googleFormShiftDetailsEmailSentKey(int $staffId, int $eventId): string
{
    return 'google_form_shift_email_' . $staffId . '_' . $eventId;
}

/**
 * Shift-details email after form import + official PSA verify.
 * Role comes from the form (DSP or Static). Does not assume Door Supervisor.
 *
 * @param array<string, mixed> $staff
 * @param array<string, mixed> $event
 */
function sendGoogleFormShiftDetailsEmail(PDO $pdo, array $staff, array $event, string $role = ''): bool
{
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/email-layout.php';
    require_once __DIR__ . '/email-copy.php';
    require_once __DIR__ . '/company.php';
    require_once __DIR__ . '/registration-forms.php';

    $email = trim((string) ($staff['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $firstName = trim((string) ($staff['first_name'] ?? ''));
    $siteName  = getSiteName($pdo);
    $eventName = trim((string) ($event['name'] ?? ''));
    if ($eventName === '') {
        $eventName = GOOGLE_FORM_AVIVA_EVENT_NAME;
    }
    $dateLabel = formatEventDateLabel(substr((string) ($event['event_date'] ?? ''), 0, 10));
    $timeLabel = '';
    if (eventShowsTimeOnRegistration($event)) {
        $timeLabel = formatEventTimeRangeLabelForEmail($event);
    }
    $location = trim((string) ($event['location'] ?? $event['event_location'] ?? ''));
    $roleCode = $role !== '' ? $role : (string) ($staff['staff_role'] ?? '');
    $roleLabel = formatStaffRoleLabel($roleCode, $pdo);
    if (strtolower(trim($roleLabel)) === 'staff' || $roleLabel === '') {
        $roleLabel = '';
    }

    $subject = emailAsciiSafe('Your shift details - ' . $eventName);
    $greeting = $firstName !== '' ? 'Hi ' . $firstName . ',' : 'Hi,';
    $portalLine = $siteName . ' is a registration portal only - not your employer.';

    $lines = [
        $greeting,
        '',
        'You are approved. Here are your shift details:',
        '',
        'Event: ' . $eventName,
    ];
    if ($dateLabel !== '') {
        $lines[] = 'Date: ' . $dateLabel;
    }
    if ($timeLabel !== '') {
        $lines[] = 'Time: ' . $timeLabel;
    }
    if ($location !== '' && strcasecmp($location, 'Location to be confirmed') !== 0) {
        $lines[] = 'Location: ' . $location;
    }
    if ($roleLabel !== '') {
        $lines[] = 'Role: ' . $roleLabel;
    }
    $lines[] = '';
    $lines[] = $portalLine;
    $lines[] = '';
    $lines[] = '- ' . $siteName;

    $text = emailAsciiSafe(implode("\n", $lines));

    $detailHtml = '<p style="margin:0 0 10px;">You are approved. Here are your shift details:</p>'
        . '<p style="margin:0 0 4px;"><strong>Event:</strong> ' . emailEsc($eventName) . '</p>';
    if ($dateLabel !== '') {
        $detailHtml .= '<p style="margin:0 0 4px;"><strong>Date:</strong> ' . emailEsc($dateLabel) . '</p>';
    }
    if ($timeLabel !== '') {
        $detailHtml .= '<p style="margin:0 0 4px;"><strong>Time:</strong> ' . emailEsc(emailAsciiSafe($timeLabel)) . '</p>';
    }
    if ($location !== '' && strcasecmp($location, 'Location to be confirmed') !== 0) {
        $detailHtml .= '<p style="margin:0 0 4px;"><strong>Location:</strong> ' . emailEsc($location) . '</p>';
    }
    if ($roleLabel !== '') {
        $detailHtml .= '<p style="margin:0 0 10px;"><strong>Role:</strong> ' . emailEsc($roleLabel) . '</p>';
    } else {
        $detailHtml .= '<p style="margin:0 0 10px;"></p>';
    }
    $detailHtml .= '<p style="margin:0;">' . emailEsc($portalLine) . '</p>';

    $html = wrapEmailHtmlFragment(
        $pdo,
        $subject,
        '<p style="margin:0 0 12px;">' . emailEsc($greeting) . '</p>'
        . buildEmailNotificationCard($pdo, 'Your shift details', $detailHtml),
        [
            'preheader'   => 'You are approved. Shift details for ' . $eventName . '.',
            'footer_note' => $portalLine,
        ]
    );

    return sendEmail($pdo, $email, $subject, $text, $html);
}

function googleFormShiftDetailsDedupeKey(int $staffId, int $eventId, string $email = ''): string
{
    if ($staffId > 0 && $eventId > 0) {
        return googleFormShiftDetailsEmailSentKey($staffId, $eventId);
    }
    $email = strtolower(trim($email));
    if ($email !== '' && $eventId > 0) {
        return 'google_form_shift_email_' . sha1($email) . '_' . $eventId;
    }

    return '';
}

/**
 * @param array<string, mixed> $staff
 * @param array<string, mixed> $event
 * @param array<string, mixed> $verify
 */
function sendGoogleFormShiftDetailsEmailIfNeeded(PDO $pdo, array $staff, array $event, string $role, array $verify = []): void
{
    unset($verify);
    $staffId = (int) ($staff['id'] ?? 0);
    $eventId = (int) ($event['id'] ?? 0);
    $email = strtolower(trim((string) ($staff['email'] ?? '')));
    $sentKey = googleFormShiftDetailsDedupeKey($staffId, $eventId, $email);
    if ($sentKey !== '' && trim((string) getSetting($pdo, $sentKey, '')) === '1') {
        return;
    }
    if (sendGoogleFormShiftDetailsEmail($pdo, $staff, $event, $role) && $sentKey !== '') {
        setSetting($pdo, $sentKey, '1');
    }
}

/**
 * Send shift details after a registration is approved (any event).
 */
function googleFormSendShiftDetailsForRegistration(PDO $pdo, int $registrationId): void
{
    if ($registrationId < 1) {
        return;
    }
    require_once __DIR__ . '/staff-repository.php';
    require_once __DIR__ . '/events-repository.php';

    $reg = getStaffRegistrationById($pdo, $registrationId);
    if ($reg === null || strtolower(trim((string) ($reg['status'] ?? ''))) !== 'approved') {
        return;
    }
    $eventId = (int) ($reg['event_id'] ?? 0);
    $event = $eventId > 0 ? getEventById($pdo, $eventId) : null;
    if ($event === null) {
        return;
    }
    $staffId = (int) ($reg['staff_id'] ?? 0);
    $staff = $staffId > 0 ? getStaffById($pdo, $staffId) : null;
    if ($staff === null) {
        $staff = getStaffByEmail($pdo, (string) ($reg['email'] ?? ''));
    }
    if ($staff === null) {
        $staff = [
            'id'         => $staffId,
            'email'      => (string) ($reg['email'] ?? ''),
            'first_name' => (string) ($reg['first_name'] ?? ''),
            'surname'    => (string) ($reg['surname'] ?? ''),
            'staff_role' => (string) ($reg['staff_role'] ?? ''),
        ];
    }
    $role = trim((string) ($reg['staff_role'] ?? ''));
    if ($role === '') {
        $role = (string) ($staff['staff_role'] ?? '');
    }
    sendGoogleFormShiftDetailsEmailIfNeeded($pdo, $staff, $event, $role);
}

/**
 * @return array{ok: bool, event_id: int, event: string, total: int, sent: int, skipped: int, failed: int, failed_emails: list<string>}
 */
function googleFormSendShiftDetailsToApprovedForEvent(PDO $pdo, int $eventId): array
{
    require_once __DIR__ . '/staff-repository.php';
    require_once __DIR__ . '/events-repository.php';

    $event = $eventId > 0 ? getEventById($pdo, $eventId) : null;
    $result = [
        'ok'            => false,
        'event_id'      => $eventId,
        'event'         => (string) ($event['name'] ?? ''),
        'total'         => 0,
        'sent'          => 0,
        'skipped'       => 0,
        'failed'        => 0,
        'failed_emails' => [],
    ];
    if ($event === null) {
        return $result;
    }

    $stmt = $pdo->prepare(
        "SELECT sr.id
         FROM staff_registrations sr
         WHERE sr.event_id = :event_id
           AND LOWER(TRIM(IFNULL(sr.status, ''))) = 'approved'
           AND sr.email IS NOT NULL
           AND TRIM(sr.email) <> ''
         ORDER BY sr.id ASC"
    );
    $stmt->execute(['event_id' => $eventId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $result['total'] = count($ids);

    foreach ($ids as $registrationId) {
        $reg = getStaffRegistrationById($pdo, $registrationId);
        if ($reg === null) {
            $result['failed']++;
            continue;
        }
        $staffId = (int) ($reg['staff_id'] ?? 0);
        $email = strtolower(trim((string) ($reg['email'] ?? '')));
        $sentKey = googleFormShiftDetailsDedupeKey($staffId, $eventId, $email);
        if ($sentKey !== '' && trim((string) getSetting($pdo, $sentKey, '')) === '1') {
            $result['skipped']++;
            continue;
        }
        googleFormSendShiftDetailsForRegistration($pdo, $registrationId);
        $after = $sentKey !== '' ? trim((string) getSetting($pdo, $sentKey, '')) : '';
        if ($after === '1') {
            $result['sent']++;
        } else {
            $result['failed']++;
            if ($email !== '') {
                $result['failed_emails'][] = $email;
            }
        }
    }

    $result['ok'] = $result['failed'] === 0;
    return $result;
}

/**
 * After PSA is confirmed, send shift details for each approved event they are on.
 *
 * @param array<string, mixed> $staff
 */
function googleFormSendShiftDetailsForStaff(PDO $pdo, array $staff): void
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email = strtolower(trim((string) ($staff['email'] ?? '')));
    if ($staffId < 1) {
        return;
    }

    $sql = "SELECT e.*, sr.staff_role AS registration_role
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE LOWER(TRIM(IFNULL(sr.status, ''))) = 'approved'
              AND (sr.staff_id = :staff_id";
    $params = ['staff_id' => $staffId];
    if ($email !== '') {
        $sql .= ' OR LOWER(sr.email) = :email';
        $params['email'] = $email;
    }
    $sql .= ') ORDER BY e.event_date DESC, e.id DESC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] Shift details events: ' . $e->getMessage());
        return;
    }

    $seen = [];
    foreach ($rows as $row) {
        $eventId = (int) ($row['id'] ?? 0);
        if ($eventId < 1 || isset($seen[$eventId])) {
            continue;
        }
        $seen[$eventId] = true;
        $role = trim((string) ($row['registration_role'] ?? ''));
        if ($role === '') {
            $role = (string) ($staff['staff_role'] ?? '');
        }
        sendGoogleFormShiftDetailsEmailIfNeeded($pdo, $staff, $row, $role);
    }
}

/**
 * Email Aviva applicants whose stored PSA number was not found on the register.
 *
 * @return array<string, mixed>
 */
function googleFormIntakeEmailNotFoundLicences(PDO $pdo): array
{
    require_once __DIR__ . '/mailer.php';
    $event = googleFormIntakeEnsureAvivaEvent($pdo);
    $eventId = (int) ($event['id'] ?? 0);
    if ($eventId < 1) {
        return ['ok' => false, 'error' => 'Aviva event not found.'];
    }

    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.id, s.first_name, s.surname, s.email, s.psa_licence, s.psa_register_status
         FROM staff s
         INNER JOIN staff_registrations sr ON sr.staff_id = s.id
         WHERE sr.event_id = :event_id
           AND LOWER(TRIM(IFNULL(s.psa_register_status, ''))) IN ('not_found', 'invalid_format')
           AND s.email IS NOT NULL
           AND TRIM(s.email) <> ''"
    );
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sent = [];
    $skipped = [];
    foreach ($rows as $row) {
        $before = trim((string) getSetting($pdo, 'psa_number_check_email_sent_' . (int) $row['id'], ''));
        sendStaffPsaNumberCheckEmailIfNeeded($pdo, $row, ['status' => (string) ($row['psa_register_status'] ?? '')]);
        $after = trim((string) getSetting($pdo, 'psa_number_check_email_sent_' . (int) $row['id'], ''));
        $entry = [
            'staff_id' => (int) $row['id'],
            'email'    => (string) ($row['email'] ?? ''),
            'name'     => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? '')),
            'licence'  => (string) ($row['psa_licence'] ?? ''),
            'status'   => (string) ($row['psa_register_status'] ?? ''),
        ];
        if ($after === '1' && $before !== '1') {
            $sent[] = $entry;
        } else {
            $entry['reason'] = $after === '1' ? 'already_sent' : 'send_failed';
            $skipped[] = $entry;
        }
    }

    return [
        'ok'       => true,
        'action'   => 'psa_not_found_staff_email',
        'event_id' => $eventId,
        'found'    => count($rows),
        'sent'     => $sent,
        'skipped'  => $skipped,
    ];
}

function googleFormIntakeStaffTableExists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM staff LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Update existing staff without touching PPS or date of birth.
 *
 * @param array<string, string> $fields
 */
function googleFormIntakeUpdateStaff(PDO $pdo, int $staffId, array $fields): void
{
    $allowed = [
        'surname', 'first_name', 'full_address', 'eircode', 'mobile',
        'gender', 'bank_iban', 'staff_role',
        'psa_licence', 'psa_expiry_date',
        'psa_register_status', 'psa_register_name', 'psa_register_checked_at',
    ];
    $sets = [];
    $params = ['id' => $staffId];

    foreach ($allowed as $column) {
        if (!array_key_exists($column, $fields)) {
            continue;
        }
        $sets[] = "{$column} = :{$column}";
        $params[$column] = $fields[$column];
    }

    if ($sets === []) {
        return;
    }

    $sql = 'UPDATE staff SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
    $pdo->prepare($sql)->execute($params);
    ensureStaffProfileTokenAfterSave($pdo, $staffId);
}

/**
 * @return array{binary: string, mime: string}|null
 */
function googleFormIntakeDecodePsaPhoto(array $payload, string $side): ?array
{
    $side = $side === 'back' ? 'back' : 'front';
    $b64 = googleFormIntakePick($payload, [
        'psa_' . $side . '_image_b64',
        'psa_' . $side . '_b64',
    ]);
    if ($b64 !== '') {
        $binary = base64_decode($b64, true);
        if (is_string($binary) && $binary !== '' && strlen($binary) <= 100 * 1024 * 1024) {
            $mime = strtolower(googleFormIntakePick($payload, [
                'psa_' . $side . '_mime',
                'psa_' . $side . '_image_mime',
            ]));

            return ['binary' => $binary, 'mime' => $mime];
        }
    }

    $driveId = googleFormIntakeExtractDriveId(googleFormIntakePick($payload, [
        'psa_' . $side . '_drive_id',
        'psa_' . $side . '_file_id',
        'psa_' . $side . '_drive_url',
        'psa_' . $side . '_url',
    ]));
    $driveUrl = googleFormIntakePick($payload, [
        'psa_' . $side . '_drive_url',
        'psa_' . $side . '_url',
    ]);
    $binary = googleFormIntakeDownloadDrivePhoto($driveId, $driveUrl);
    if ($binary === '') {
        return null;
    }

    $mime = strtolower(googleFormIntakePick($payload, [
        'psa_' . $side . '_mime',
        'psa_' . $side . '_image_mime',
    ]));

    return ['binary' => $binary, 'mime' => $mime];
}

function googleFormIntakeExtractDriveId(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#/d/([a-zA-Z0-9_-]{20,})#', $value, $m)) {
        return $m[1];
    }
    if (preg_match('#[?&]id=([a-zA-Z0-9_-]{20,})#', $value, $m)) {
        return $m[1];
    }
    if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value)) {
        return $value;
    }

    return '';
}

function googleFormIntakeDownloadDrivePhoto(string $fileId, string $url = ''): string
{
    $candidates = [];
    if ($url !== '' && preg_match('#^https://#i', $url)) {
        $candidates[] = $url;
    }
    if ($fileId !== '') {
        $candidates[] = 'https://drive.google.com/uc?export=download&id=' . rawurlencode($fileId);
        $candidates[] = 'https://drive.google.com/thumbnail?id=' . rawurlencode($fileId) . '&sz=w2000';
        $candidates[] = 'https://lh3.googleusercontent.com/d/' . rawurlencode($fileId);
    }

    foreach ($candidates as $try) {
        if (!googleFormIntakeDriveUrlAllowed($try)) {
            continue;
        }
        $body = googleFormIntakeHttpGetBinary($try);
        if ($body !== '' && googleFormIntakeLooksLikePhoto($body)) {
            return $body;
        }
    }

    return '';
}

function googleFormIntakeDriveUrlAllowed(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

    return $host === 'drive.google.com'
        || $host === 'docs.google.com'
        || $host === 'lh3.googleusercontent.com'
        || str_ends_with($host, '.googleusercontent.com');
}

function googleFormIntakeLooksLikePhoto(string $binary): bool
{
    if (strlen($binary) < 12 || strlen($binary) > 100 * 1024 * 1024) {
        return false;
    }
    if (str_starts_with(ltrim($binary), '<')) {
        return false;
    }
    $head = substr($binary, 0, 12);

    return str_starts_with($head, "\xFF\xD8\xFF")
        || str_starts_with($head, "\x89PNG")
        || str_starts_with($head, 'GIF8')
        || str_starts_with($head, 'RIFF')
        || str_starts_with($head, 'ftyp')
        || (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp');
}

function googleFormIntakeHttpGetBinary(string $url): string
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300 || !is_string($raw)) {
            return '';
        }

        return $raw;
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 20,
            'ignore_errors' => true,
            'user_agent'    => $ua,
            'follow_location' => 1,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);

    return is_string($raw) ? $raw : '';
}

/**
 * @return array<string, string>
 */
function googleFormIntakeSavePsaBadgePhotos(PDO $pdo, int $staffId, array $payload): array
{
    if ($staffId < 1) {
        return [];
    }

    require_once __DIR__ . '/staff-psa.php';
    ensureStaffPsaSchema($pdo);

    $uploadDir = dirname(__DIR__) . '/uploads/psa/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return [];
    }

    $saved = [];
    foreach (['front' => 'psa_front_image', 'back' => 'psa_back_image'] as $side => $field) {
        $decoded = googleFormIntakeDecodePsaPhoto($payload, $side);
        if ($decoded === null) {
            $driveId = googleFormIntakeExtractDriveId(googleFormIntakePick($payload, [
                'psa_' . $side . '_drive_id',
                'psa_' . $side . '_file_id',
                'psa_' . $side . '_drive_url',
            ]));
            if ($driveId !== '') {
                $saved[$field] = 'https://drive.google.com/file/d/' . $driveId . '/view';
            }
            continue;
        }

        $mime = $decoded['mime'];
        $ext = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'heic'), str_contains($mime, 'heif') => 'heic',
            default => 'jpg',
        };
        $filename = 'psa_' . $side . '_' . $staffId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents($uploadDir . $filename, $decoded['binary']) === false) {
            continue;
        }
        $saved[$field] = '/uploads/psa/' . $filename;
    }

    if ($saved !== []) {
        updateStaffProfile($pdo, $staffId, $saved);
    }

    return $saved;
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok: bool, action?: string, event?: string, staff_id?: int, registration_id?: int, error?: string}
 */
function processGoogleFormStaffIntake(PDO $pdo, array $payload): array
{
    $firstName = googleFormIntakePick($payload, ['first_name', 'First name', 'First Name', 'firstname']);
    $surname   = googleFormIntakePick($payload, ['surname', 'Surname', 'Last name', 'Last Name', 'last_name']);
    $email     = normalizeRegistrationEmail(googleFormIntakePick($payload, [
        'email', 'Email', 'E-mail', 'Email address', 'Email Address', 'respondent_email',
    ]));
    $mobile    = normalizeMobileFromPost([
        'mobile' => googleFormIntakePick($payload, ['mobile', 'Mobile', 'Phone', 'phone', 'Mobile number']),
    ]);
    $address   = googleFormIntakePick($payload, ['full_address', 'Full address', 'Address', 'address']);
    $eircode   = normalizeEircode(googleFormIntakePick($payload, ['eircode', 'Eircode', 'Postcode', 'postcode']));
    $role      = googleFormIntakeNormalizeRole(googleFormIntakePick($payload, [
        'staff_role', 'Role', 'role', 'PSA role', 'Position',
    ]));
    $gender    = googleFormIntakeNormalizeGender(googleFormIntakePick($payload, [
        'gender', 'Gender',
    ]));
    $iban      = normalizeBankIban(googleFormIntakePick($payload, [
        'bank_iban', 'IBAN', 'Bank IBAN', 'iban',
        'Bank IBAN (Irish example IE29AIBK93115212345678)',
    ]));
    $psaLicence = normalizePsaLicence(googleFormIntakePick($payload, [
        'psa_licence', 'PSA licence number', 'PSA licence', 'Licence number',
        'PSA license number', 'psa_license',
    ]));
    $ppsIn = googleFormIntakePick($payload, ['pps_number', 'PPS number', 'PPS', 'PPS No']);
    $dobIn = googleFormIntakePick($payload, ['date_of_birth', 'Date of birth', 'DOB', 'Date of Birth']);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'A valid email is required.'];
    }
    if ($firstName === '' || $surname === '') {
        return ['ok' => false, 'error' => 'First name and surname are required.'];
    }
    if ($mobile === '') {
        return ['ok' => false, 'error' => 'Mobile number is required.'];
    }
    if ($address === '') {
        return ['ok' => false, 'error' => 'Full address is required.'];
    }
    if ($eircode === '') {
        return ['ok' => false, 'error' => 'Eircode is required.'];
    }
    if ($gender === '') {
        return ['ok' => false, 'error' => 'Gender is required.'];
    }
    if ($role !== 'dsp' && $role !== 'static') {
        return ['ok' => false, 'error' => 'Role must be DSP or Static.'];
    }
    $ibanError = validateBankIbanField($iban, false);
    if ($ibanError !== null) {
        error_log('[EventStaff] Google Form intake IBAN: ' . $ibanError);
    }
    $psaFormatError = validatePsaLicenceField($psaLicence, false);
    if ($psaFormatError !== null) {
        error_log('[EventStaff] Google Form intake PSA: ' . $psaFormatError);
    }

    $holderName = trim($firstName . ' ' . $surname);
    try {
        $psaVerify = lookupPsaLicenceOnRegister($psaLicence, $holderName);
    } catch (Throwable $e) {
        error_log('[EventStaff] PSA register lookup: ' . $e->getMessage());
        $psaVerify = [
            'ok'           => true,
            'format_ok'    => true,
            'status'       => 'unreachable',
            'message'      => 'Licence format is valid. Could not reach the PSA register just now.',
            'register_name'=> '',
            'expiry_date'  => '',
        ];
    }
    $psaExpiry = (string) ($psaVerify['expiry_date'] ?? '');

    ensureStaffPsaSchema($pdo);
    ensureStaffRegistrationSaveSchema($pdo);
    $resolved = googleFormIntakeResolveTargetEvent($pdo, $payload);
    if (empty($resolved['ok'])) {
        return ['ok' => false, 'error' => (string) ($resolved['error'] ?? 'Event not found.')];
    }
    $event = $resolved['event'];
    $eventId = (int) $event['id'];

    $fields = [
        'surname'         => $surname,
        'first_name'      => $firstName,
        'full_address'    => $address,
        'eircode'         => $eircode,
        'mobile'          => $mobile,
        'gender'       => $gender,
        'bank_iban'    => $iban,
        'staff_role'   => $role,
        'psa_licence'  => $psaLicence,
    ];
    if ($ppsIn !== '') {
        $fields['pps_number'] = $ppsIn;
    }
    if ($dobIn !== '') {
        $fields['date_of_birth'] = $dobIn;
    }
    if ($psaExpiry !== '') {
        $fields['psa_expiry_date'] = $psaExpiry;
    }
    $fields['psa_register_status'] = (string) ($psaVerify['status'] ?? '');
    $fields['psa_register_name'] = (string) ($psaVerify['register_name'] ?? '');
    $fields['psa_register_checked_at'] = date('Y-m-d H:i:s');

    $action = 'created';
    $staffId = 0;
    $existingStaff = googleFormIntakeStaffTableExists($pdo) ? getStaffByEmail($pdo, $email) : null;

    if ($existingStaff !== null) {
        $staffId = (int) $existingStaff['id'];
        googleFormIntakeUpdateStaff($pdo, $staffId, $fields);
        linkStaffIdToRegistrationsByEmail($pdo, $email, $staffId);
        $action = 'updated';
    } elseif (googleFormIntakeStaffTableExists($pdo)) {
        $staffId = findOrCreateStaff($pdo, array_merge($fields, [
            'email'         => $email,
            'date_of_birth' => $dobIn,
            'pps_number'    => $ppsIn,
            'psa_licence'   => $psaLicence,
            'psa_expiry_date' => $psaExpiry,
        ]));
        if ($staffId > 0) {
            googleFormIntakeUpdateStaff($pdo, $staffId, $fields);
        }
        $action = 'created';
    }

    $regStmt = $pdo->prepare(
        'SELECT id FROM staff_registrations WHERE LOWER(email) = :email AND event_id = :event_id LIMIT 1'
    );
    $regStmt->execute(['email' => $email, 'event_id' => $eventId]);
    $registrationId = (int) ($regStmt->fetchColumn() ?: 0);

    $pps = $ppsIn !== '' ? $ppsIn : ($existingStaff !== null ? trim((string) ($existingStaff['pps_number'] ?? '')) : '');
    $dob = $dobIn !== '' ? $dobIn : ($existingStaff !== null ? trim((string) ($existingStaff['date_of_birth'] ?? '')) : '');
    $psaKeep = $psaLicence !== '' ? $psaLicence : ($existingStaff !== null ? trim((string) ($existingStaff['psa_licence'] ?? '')) : '');
    $psaExpiryKeep = $psaExpiry !== '' ? $psaExpiry : ($existingStaff !== null ? trim((string) ($existingStaff['psa_expiry_date'] ?? '')) : '');

    if ($registrationId > 0) {
        $sets = [
            'surname = :surname',
            'first_name = :first_name',
            'full_address = :full_address',
            'eircode = :eircode',
            'mobile = :mobile',
            'gender = :gender',
            'bank_iban = :bank_iban',
            'staff_role = :staff_role',
        ];
        $params = [
            'surname'      => $surname,
            'first_name'   => $firstName,
            'full_address' => $address,
            'eircode'      => $eircode,
            'mobile'       => $mobile,
            'gender'       => $gender,
            'bank_iban'    => $iban,
            'staff_role'   => $role,
            'id'           => $registrationId,
        ];
        if (staffRegistrationColumnExists($pdo, 'psa_licence')) {
            $sets[] = 'psa_licence = :psa_licence';
            $params['psa_licence'] = $psaKeep;
        }
        if (staffRegistrationColumnExists($pdo, 'psa_expiry_date') && $psaExpiryKeep !== '') {
            $sets[] = 'psa_expiry_date = :psa_expiry_date';
            $params['psa_expiry_date'] = $psaExpiryKeep;
        }
        if (staffRegistrationColumnExists($pdo, 'status')) {
            $sets[] = 'status = :status';
            $params['status'] = 'approved';
        }
        if (staffRegistrationColumnExists($pdo, 'staff_id') && $staffId > 0) {
            $sets[] = 'staff_id = :staff_id';
            $params['staff_id'] = $staffId;
        }
        $pdo->prepare(
            'UPDATE staff_registrations SET ' . implode(', ', $sets) . ' WHERE id = :id'
        )->execute($params);
        $action = 'updated';
    } else {
        $row = [
            'staff_id'        => $staffId > 0 ? $staffId : null,
            'surname'         => $surname,
            'first_name'      => $firstName,
            'full_address'    => $address,
            'eircode'         => $eircode,
            'email'           => $email,
            'mobile'          => $mobile,
            'date_of_birth'   => $dob,
            'gender'          => $gender,
            'pps_number'      => $pps,
            'bank_iban'       => $iban,
            'psa_licence'     => $psaKeep,
            'psa_expiry_date' => $psaExpiryKeep,
            'staff_role'      => $role,
            'event_id'        => $eventId,
            'status'          => 'approved',
            'status_token'    => bin2hex(random_bytes(32)),
        ];
        $columns = [];
        $params = [];
        foreach ($row as $column => $value) {
            if (!staffRegistrationColumnExists($pdo, $column)) {
                continue;
            }
            $columns[] = $column;
            $params[$column] = $value;
        }
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
        $pdo->prepare(
            'INSERT INTO staff_registrations (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        )->execute($params);
        $registrationId = (int) $pdo->lastInsertId();
        if ($action !== 'updated') {
            $action = 'created';
        }
    }

    if ($staffId > 0) {
        try {
            syncStaffPersonalDataToRegistrations($pdo, $staffId, $fields);
        } catch (Throwable $e) {
            error_log('[EventStaff] Google Form intake sync registrations: ' . $e->getMessage());
        }
        try {
            googleFormIntakeSavePsaBadgePhotos($pdo, $staffId, $payload);
        } catch (Throwable $e) {
            error_log('[EventStaff] Google Form intake PSA photos: ' . $e->getMessage());
        }
        try {
            $staffAfter = getStaffById($pdo, $staffId);
            if ($staffAfter !== null) {
                require_once __DIR__ . '/notification-center.php';
                notifyAdminPsaLicenceProblemIfNeeded(
                    $pdo,
                    $staffAfter,
                    $psaVerify,
                    $existingStaff !== null ? trim((string) ($existingStaff['psa_register_status'] ?? '')) : ''
                );
                sendStaffPsaNumberCheckEmailIfNeeded($pdo, $staffAfter, $psaVerify);
                sendGoogleFormShiftDetailsEmailIfNeeded($pdo, $staffAfter, $event, $role, $psaVerify);
            }
        } catch (Throwable $e) {
            error_log('[EventStaff] Google Form intake PSA admin alert: ' . $e->getMessage());
        }
    }

    if ($registrationId > 0) {
        try {
            require_once __DIR__ . '/google-sheets-sync.php';
            syncRegistrationToGoogleSheet($pdo, $registrationId);
        } catch (Throwable $e) {
            error_log('[EventStaff] Google Form intake sheet sync: ' . $e->getMessage());
        }
    }

    return [
        'ok'              => true,
        'action'          => $action,
        'event'           => (string) ($event['name'] ?? GOOGLE_FORM_AVIVA_EVENT_NAME),
        'event_date'      => (string) ($event['event_date'] ?? GOOGLE_FORM_AVIVA_EVENT_DATE),
        'event_id'        => $eventId,
        'staff_id'        => $staffId,
        'registration_id' => $registrationId,
        'role'            => $role,
        'email'           => $email,
        'psa_licence'     => $psaLicence,
        'psa_verified'    => !empty($psaVerify['ok']) && ($psaVerify['status'] ?? '') === 'verified',
        'psa_status'      => (string) ($psaVerify['status'] ?? ''),
        'psa_message'     => (string) ($psaVerify['message'] ?? ''),
    ];
}

require_once __DIR__ . '/google-form-sheet-sync.php';
require_once __DIR__ . '/google-form-create.php';
