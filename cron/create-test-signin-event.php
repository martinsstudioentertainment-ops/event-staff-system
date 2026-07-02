<?php

declare(strict_types=1);

/**
 * Create or delete a disposable sign-in test event (R14 WD68).
 *
 * Web create: /cron/create-test-signin-event.php?key=KEY
 * Web delete: /cron/create-test-signin-event.php?key=KEY&delete=1&confirm=1
 * Optional: &email=you@gmail.com — on create/refresh, schedule and approve that staff member; on delete, also purge their profile
 * Reset check-in: &reset_checkin=1&email=you@gmail.com — cancel attendance on test event(s) so staff can sign in again
 * CLI create: php cron/create-test-signin-event.php
 * CLI delete: php cron/create-test-signin-event.php --delete --confirm
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/maps.php';
require_once dirname(__DIR__) . '/includes/registrant-complete-purge.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';

const TEST_SIGNIN_EVENT_MARKER = '[TEST DELETE] Sign-in check';
const TEST_SIGNIN_EVENT_EIRCODE = 'R14 WD68';
const TEST_SIGNIN_CRON_FALLBACK_KEY = 'email-encoding-verify-20260606';

function test_signin_today_date(PDO $pdo): string
{
    if (function_exists('applySystemRuntimeSettings')) {
        applySystemRuntimeSettings($pdo);
    }

    return date('Y-m-d');
}

$isCli    = PHP_SAPI === 'cli' || defined('STDIN');
$opts     = $isCli ? getopt('', ['delete', 'confirm', 'key::', 'email::']) : [];
$delete   = $isCli ? array_key_exists('delete', $opts) : !empty($_GET['delete']);
$confirm  = $isCli ? array_key_exists('confirm', $opts) : !empty($_GET['confirm']);
$scan     = $isCli ? array_key_exists('scan', $opts) : !empty($_GET['scan']);
$resetCheckin = $isCli ? array_key_exists('reset-checkin', $opts) : !empty($_GET['reset_checkin']);
$key      = trim((string) ($opts['key'] ?? $_GET['key'] ?? ''));
$extraEmail = strtolower(trim((string) ($opts['email'] ?? $_GET['email'] ?? '')));
$expected = TEST_SIGNIN_CRON_FALLBACK_KEY;

/**
 * @return list<int>
 */
function test_signin_event_ids(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT id FROM events WHERE name LIKE :marker OR name LIKE :removed ORDER BY id ASC"
    );
    $stmt->execute([
        'marker'  => TEST_SIGNIN_EVENT_MARKER . '%',
        'removed' => TEST_SIGNIN_EVENT_MARKER . '% [removed]%',
    ]);

    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
}

/**
 * @param list<int> $eventIds
 * @return list<string>
 */
function test_signin_emails_for_events(PDO $pdo, array $eventIds): array
{
    if ($eventIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT LOWER(TRIM(email)) AS email
         FROM staff_registrations
         WHERE event_id IN ({$placeholders})
           AND TRIM(email) <> ''"
    );
    $stmt->execute($eventIds);
    $emails = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
        $email = strtolower(trim((string) $email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

/**
 * @param list<int> $eventIds
 * @return array<string, mixed>
 */
function purge_test_signin_events(PDO $pdo, array $eventIds, array $extraEmails = []): array
{
    $purgedEmails = [];
    $eventsCleaned = [];
    $errors = [];

    $emails = test_signin_emails_for_events($pdo, $eventIds);
    foreach ($extraEmails as $email) {
        $email = strtolower(trim($email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }
    $emails = array_values(array_unique($emails));

    foreach ($emails as $email) {
        $result = purgeRegistrantCompletely($pdo, $email, false);
        $purgedEmails[] = [
            'email'   => $email,
            'ok'      => (bool) ($result['ok'] ?? false),
            'deleted' => $result['deleted'] ?? [],
            'remaining_rows' => (int) ($result['remaining_rows'] ?? 0),
            'error'   => $result['error'] ?? null,
        ];
        if (!($result['ok'] ?? false)) {
            $errors[] = $email . ': ' . (string) ($result['error'] ?? 'purge failed');
        }
    }

    foreach ($eventIds as $eventId) {
        if ($eventId < 1) {
            continue;
        }
        try {
            $invStmt = $pdo->prepare('SELECT id FROM commission_invoices WHERE event_id = :event_id');
            $invStmt->execute(['event_id' => $eventId]);
            foreach ($invStmt->fetchAll(PDO::FETCH_COLUMN) as $invoiceId) {
                $invoiceId = (int) $invoiceId;
                if ($invoiceId < 1) {
                    continue;
                }
                $pdo->prepare('DELETE FROM commission_invoice_lines WHERE invoice_id = :id')
                    ->execute(['id' => $invoiceId]);
                $pdo->prepare('DELETE FROM commission_invoices WHERE id = :id')
                    ->execute(['id' => $invoiceId]);
            }

            $tablesByEvent = [
                'attendance'                => 'event_id',
                'staff_registrations'     => 'event_id',
                'platform_offline_checkins' => 'event_id',
                'staff_waitlist'          => 'preferred_event_id',
            ];
            foreach ($tablesByEvent as $table => $column) {
                if (!registrantPurgeTableExists($pdo, $table)) {
                    continue;
                }
                if (!registrantPurgeColumnExists($pdo, $table, $column)) {
                    continue;
                }
                $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` = :event_id")
                    ->execute(['event_id' => $eventId]);
            }

            $pdo->prepare(
                'UPDATE events SET is_active = 0, name = CONCAT(name, \' [removed]\') WHERE id = :id'
            )->execute(['id' => $eventId]);

            $eventsCleaned[] = $eventId;
        } catch (Throwable $e) {
            $errors[] = 'event ' . $eventId . ': ' . $e->getMessage();
        }
    }

    return [
        'event_ids'      => $eventIds,
        'events_cleaned' => $eventsCleaned,
        'emails_found'   => $emails,
        'emails_purged'  => $purgedEmails,
        'errors'         => $errors,
    ];
}

/**
 * @return array<string, mixed>
 */
function schedule_test_signin_staff(PDO $pdo, int $eventId, string $email): array
{
    $email = normalizeRegistrationEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address.'];
    }

    $source = getStaffByEmail($pdo, $email);
    if ($source === null) {
        $source = getLatestRegistrationByEmail($pdo, $email);
    }
    if ($source === null) {
        return ['ok' => false, 'error' => 'No staff profile found for ' . $email . '. Register once on the site first.'];
    }

    $registrationId = 0;
    if (registrationExistsForEmail($pdo, $email, $eventId)) {
        $stmt = $pdo->prepare(
            'SELECT id, status FROM staff_registrations
             WHERE LOWER(email) = :email AND event_id = :event_id LIMIT 1'
        );
        $stmt->execute(['email' => $email, 'event_id' => $eventId]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);
        $registrationId = (int) ($reg['id'] ?? 0);
        if ($registrationId > 0 && (string) ($reg['status'] ?? '') !== 'approved') {
            updateStaffStatus($pdo, $registrationId, 'approved', true);
        }
    } else {
        $data = array_merge($source, [
            'email'           => $email,
            'privacy_consent' => '1',
        ]);
        $role = (string) ($source['staff_role'] ?? 'dsp');
        $registrationId = saveRegistration($pdo, $data, $eventId, $role);
        updateStaffStatus($pdo, $registrationId, 'approved', true);
    }

    ensureStaffRecordForEmail($pdo, $email);
    $staffRow = getStaffByEmail($pdo, $email);
    if ($staffRow !== null && $registrationId > 0) {
        $staffId = (int) ($staffRow['id'] ?? 0);
        if ($staffId > 0) {
            $link = $pdo->prepare(
                'UPDATE staff_registrations SET staff_id = :staff_id WHERE id = :id'
            );
            $link->execute(['staff_id' => $staffId, 'id' => $registrationId]);
        }
    }

    $pps = strtoupper(preg_replace('/\s+/', '', trim((string) ($source['pps_number'] ?? ''))));
    $ppsLast4 = strlen($pps) >= 4 ? substr($pps, -4) : null;

    return [
        'ok'              => true,
        'registration_id' => $registrationId,
        'email'           => $email,
        'status'          => 'approved',
        'pps_last4_hint'  => $ppsLast4,
        'staff_id'        => isset($staffRow['id']) ? (int) $staffRow['id'] : 0,
    ];
}

/**
 * @return array<string, mixed>
 */
function test_signin_response_payload(
    PDO $pdo,
    int $eventId,
    string $action,
    string $eircode,
    array $coords,
    string $extraEmail = ''
): array {
    $event = getEventById($pdo, $eventId);
    $token = ensureEventSigninToken($pdo, $eventId);
    if (function_exists('applySystemRuntimeSettings')) {
        applySystemRuntimeSettings($pdo);
    }
    $operationalToday = date('Y-m-d');
    $window           = $event !== null ? getEventCheckinWindow($event) : null;

    $payload = [
        'ok'           => true,
        'action'       => $action,
        'event_id'     => $eventId,
        'event'        => $event,
        'operational_today' => $operationalToday,
        'checkin_window_open' => $window !== null ? (bool) ($window['is_open'] ?? false) : null,
        'eircode'      => $eircode,
        'coordinates'  => $coords,
        'signin_token' => $token,
        'venue_signin_url' => $token !== null ? getEventVenueSigninUrl($token, $pdo) : null,
        'email_signin_url' => $token !== null ? getEventEmailSigninUrl($token, $pdo) : null,
        'register_url' => 'https://register.olasentra.com/',
        'admin_url'    => 'https://admin.olasentra.com/admin/event-form.php?id=' . $eventId,
    ];

    if ($extraEmail !== '') {
        $schedule = schedule_test_signin_staff($pdo, $eventId, $extraEmail);
        $payload['schedule'] = $schedule;
        if (!($schedule['ok'] ?? false)) {
            $payload['ok'] = false;
            $payload['message'] = 'Event ready but scheduling failed: ' . (string) ($schedule['error'] ?? 'unknown error');
        } else {
            $payload['message'] = 'Test event ready. ' . $extraEmail . ' is approved for sign-in.';
        }
    } else {
        $payload['message'] = $action === 'refreshed'
            ? 'Test event refreshed for today. Pick it in registration — not promoted on the public site.'
            : 'Test event created. It appears only in the registration shift picker (not promoted on the site). Delete later with ?delete=1';
    }

    return $payload;
}

function test_signin_json(array $payload, int $code = 200): void
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

if (!$isCli) {
    if ($key === '' || !hash_equals($expected, $key)) {
        test_signin_json(['ok' => false, 'error' => 'Forbidden — invalid cron key'], 403);
    }
}

try {
    $pdo = getDB();

    if ($scan) {
        $eventIds = test_signin_event_ids($pdo);
        $details  = [];
        foreach ($eventIds as $eventId) {
            $event = getEventById($pdo, $eventId);
            $regs  = $pdo->prepare(
                'SELECT id, email, first_name, surname, status, created_at
                 FROM staff_registrations WHERE event_id = :event_id ORDER BY id DESC'
            );
            $regs->execute(['event_id' => $eventId]);
            $att = $pdo->prepare(
                'SELECT a.id, a.registration_id, a.checked_in_at, sr.email
                 FROM attendance a
                 LEFT JOIN staff_registrations sr ON sr.id = a.registration_id
                 WHERE a.event_id = :event_id ORDER BY a.id DESC'
            );
            $att->execute(['event_id' => $eventId]);
            $details[] = [
                'event_id'       => $eventId,
                'event'          => $event,
                'registrations'  => $regs->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'attendance'     => $att->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ];
        }
        test_signin_json([
            'ok'        => true,
            'action'    => 'scan',
            'event_ids' => $eventIds,
            'details'   => $details,
        ]);
    }

    if ($resetCheckin) {
        $eventIds = test_signin_event_ids($pdo);
        $cleared  = [];
        $notFound = [];

        foreach ($eventIds as $eventId) {
            if ($eventId < 1) {
                continue;
            }
            $sql    = 'SELECT id, email FROM staff_registrations WHERE event_id = :event_id';
            $params = ['event_id' => $eventId];
            if ($extraEmail !== '') {
                $sql .= ' AND LOWER(email) = :email';
                $params['email'] = $extraEmail;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $reg) {
                $regId = (int) ($reg['id'] ?? 0);
                if ($regId < 1) {
                    continue;
                }
                if (resetCheckinForRegistration($pdo, $regId)) {
                    $cleared[] = [
                        'registration_id' => $regId,
                        'email'           => (string) ($reg['email'] ?? ''),
                        'event_id'        => $eventId,
                    ];
                } else {
                    $notFound[] = [
                        'registration_id' => $regId,
                        'email'           => (string) ($reg['email'] ?? ''),
                        'event_id'        => $eventId,
                    ];
                }
            }
        }

        test_signin_json([
            'ok'         => true,
            'action'     => 'reset_checkin',
            'cleared'    => $cleared,
            'no_checkin' => $notFound,
            'message'    => $cleared === []
                ? 'No check-in found to cancel on test event(s). You can sign in now.'
                : 'Check-in cancelled — sign in again when ready.',
        ]);
    }

    if ($delete) {
        $eventIds = test_signin_event_ids($pdo);
        if (!$confirm) {
            test_signin_json([
                'ok'           => false,
                'action'       => 'confirm_required',
                'event_ids'    => $eventIds,
                'emails_found' => test_signin_emails_for_events($pdo, $eventIds),
                'message'      => 'Add confirm=1 to permanently remove test events and purge all registrants on them.',
            ], 400);
        }

        $extraEmails = $extraEmail !== '' ? [$extraEmail] : [];
        $result = purge_test_signin_events($pdo, $eventIds, $extraEmails);

        test_signin_json([
            'ok'      => empty($result['errors']),
            'action'  => 'purge',
            'result'  => $result,
            'message' => $result['events_cleaned'] === [] && $result['emails_found'] === []
                ? 'No test sign-in events or registrants found.'
                : 'Test events removed and registrant data purged.',
        ], empty($result['errors']) ? 200 : 500);
    }

    $existing = $pdo->prepare(
        "SELECT id, name, event_date FROM events
         WHERE name LIKE :marker AND name NOT LIKE '%[removed]%'
         ORDER BY id DESC LIMIT 1"
    );
    $existing->execute(['marker' => TEST_SIGNIN_EVENT_MARKER . '%']);
    $found = $existing->fetch(PDO::FETCH_ASSOC);
    if (is_array($found) && (int) ($found['id'] ?? 0) > 0) {
        $eventId = (int) $found['id'];
        $eircode = normalizeEircode(TEST_SIGNIN_EVENT_EIRCODE);
        $coords  = geocodeVenueEircode($eircode, $pdo) ?? ['lat' => 53.1692, 'lng' => -6.5325];
        updateEvent($pdo, $eventId, [
            'name'              => TEST_SIGNIN_EVENT_MARKER . ' — ' . $eircode,
            'event_date'        => test_signin_today_date($pdo),
            'location'          => 'Private test venue — not listed publicly',
            'work_type'         => 'special_event',
            'roles_needed'      => ['static', 'dsp'],
            'venue_eircode'     => $eircode,
            'venue_lat'         => (string) $coords['lat'],
            'venue_lng'         => (string) $coords['lng'],
            'signin_radius_m'   => '500',
            'staff_needed'      => '10',
            'start_time'        => '01:00',
            'end_time'          => '06:00',
            'checkin_open_time' => '00:00',
            'checkin_close_time' => '23:59',
            'times_confirmed'   => '1',
            'is_active'         => '1',
            'reporting_point'   => 'Main entrance (test only)',
        ]);
        test_signin_json(test_signin_response_payload($pdo, $eventId, 'refreshed', $eircode, $coords, $extraEmail));
    }

    $eircode = normalizeEircode(TEST_SIGNIN_EVENT_EIRCODE);
    $coords  = geocodeVenueEircode($eircode, $pdo);
    if ($coords === null) {
        // Blessington / R14 area fallback if geocode unavailable
        $coords = ['lat' => 53.1692, 'lng' => -6.5325];
    }

    $today = test_signin_today_date($pdo);
    $start = '01:00';
    $end   = '06:00';

    $eventId = createEvent($pdo, [
        'name'              => TEST_SIGNIN_EVENT_MARKER . ' — ' . $eircode,
        'event_date'        => $today,
        'location'          => 'Private test venue — not listed publicly',
        'work_type'         => 'special_event',
        'roles_needed'      => ['static', 'dsp'],
        'venue_eircode'     => $eircode,
        'venue_lat'         => (string) $coords['lat'],
        'venue_lng'         => (string) $coords['lng'],
        'signin_radius_m'   => '500',
        'staff_needed'      => '10',
        'start_time'        => $start,
        'end_time'          => $end,
        'checkin_open_time' => '00:00',
        'checkin_close_time' => '23:59',
        'times_confirmed'   => '1',
        'is_active'         => '1',
        'reporting_point'   => 'Main entrance (test only)',
    ]);

    test_signin_json(test_signin_response_payload($pdo, $eventId, 'create', $eircode, $coords, $extraEmail));
} catch (Throwable $e) {
    test_signin_json([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], 500);
}
