<?php
declare(strict_types=1);
/**
 * AVIVA Match – Event Staff: after a row is ACCEPTED, run the same steps the 1 Oct Aviva import runs,
 * for the EXISTING staff member only (never creates staff, never changes passwords/roles/personal data).
 *
 * Order (as 1 Oct): PSA register lookup -> PSA fields on staff -> PSA badge photos -> admin PSA notice
 * -> AVIVA Match PSA-number email (once per staff for this event) -> approved staff_registrations row
 * -> shift-details email (once, 1 Oct dedupe key) -> roster sheet sync.
 * A failed PSA check never blocks: the person stays accepted and still gets the shift email.
 *
 * Idempotent: each aviva_match_signups row is marked onboarded_at when finished, and every send has its
 * own dedupe setting, so the cron can re-run and backfill already-accepted rows safely.
 */
// Load maps.php before anything pulls in maps-core.php (maps-core guards its functions; maps.php does not).
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/aviva-match.php';
require_once __DIR__ . '/google-form-intake.php';
require_once __DIR__ . '/notification-center.php';

const AVIVA_MATCH_EVENT_DATE     = '2026-11-08';
const AVIVA_MATCH_EVENT_START    = '13:00:00';
const AVIVA_MATCH_EVENT_LOCATION = 'Aviva Stadium, Lansdowne Road, Dublin 4';

function avivaMatchOnboardEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    avivaMatchEnsureSchema($pdo);
    $cols = [
        'event_registration_id' => 'INT UNSIGNED NULL',
        'psa_status'            => 'VARCHAR(32) NOT NULL DEFAULT \'\'',
        'onboarded_at'          => 'DATETIME NULL',
        'onboard_attempts'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'onboard_error'         => 'VARCHAR(255) NOT NULL DEFAULT \'\'',
    ];
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM aviva_match_signups')->fetchAll(PDO::FETCH_COLUMN) as $c) { $have[$c] = true; }
    foreach ($cols as $name => $def) {
        if (!isset($have[$name])) { $pdo->exec("ALTER TABLE aviva_match_signups ADD COLUMN {$name} {$def}"); }
    }
    $done = true;
}

/**
 * The AVIVA Match events row (created once; id kept in setting aviva_match_event_id).
 * Direct INSERT on purpose: createEvent() also rewrites venue GPS and propagates it to every event
 * linked to that venue (would touch the 1 Oct event) and creates a roster sheet.
 * is_active = 0 so it does NOT appear on the public olasentra.com registration list (sign-up is
 * the registered-only Google Form, capped at 70). staff_needed = 70.
 * times_confirmed = 0: shared email code would print "13:00 – 23:00"; the 13:00 – Finish time is in the name.
 */
function avivaMatchEnsureEvent(PDO $pdo): ?array
{
    $id = (int) getSetting($pdo, 'aviva_match_event_id', '0');
    if ($id > 0) {
        $ev = getEventById($pdo, $id);
        if ($ev !== null) { return $ev; }
    }
    $pdo->query("SELECT GET_LOCK('aviva_match_event', 10)");
    try {
        $id = (int) getSetting($pdo, 'aviva_match_event_id', '0');
        if ($id > 0 && ($ev = getEventById($pdo, $id)) !== null) { return $ev; }
        $want = [
            'name'               => 'AVIVA Match – Event Staff (13:00 – Finish)',
            'event_date'         => AVIVA_MATCH_EVENT_DATE,
            'location'           => AVIVA_MATCH_EVENT_LOCATION,
            'venue_eircode'      => 'D04 K5F8',
            'venue_lat'          => '53.3352',
            'venue_lng'          => '-6.2285',
            'start_time'         => AVIVA_MATCH_EVENT_START,
            'end_time'           => '23:00:00',
            'checkin_open_time'  => '12:00:00',
            'checkin_close_time' => '14:00:00',
            'times_confirmed'    => 0,
            'is_active'          => 0,
            'work_type'          => 'special_event',
            'roles_needed'       => '["dsp","static"]',
            'signin_radius_m'    => 120,
            'staff_needed'       => AVIVA_MATCH_CAPACITY,
        ];
        $have = [];
        foreach ($pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN) as $c) { $have[$c] = true; }
        $cols = array_values(array_filter(array_keys($want), static fn(string $c): bool => isset($have[$c])));
        $pdo->prepare('INSERT INTO events (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')
            ->execute(array_intersect_key($want, array_flip($cols)));
        $id = (int) $pdo->lastInsertId();
        if ($id < 1) { return null; }
        setSetting($pdo, 'aviva_match_event_id', (string) $id);
        return getEventById($pdo, $id);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('aviva_match_event')");
    }
}

/** AVIVA Match copy of the 1 Oct PSA-number email (approved wording). */
function avivaMatchSendPsaNumberCheckEmail(PDO $pdo, array $staff): bool
{
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/email-layout.php';
    require_once __DIR__ . '/company.php';

    $email = trim((string) ($staff['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
    $firstName = trim((string) ($staff['first_name'] ?? ''));
    $licence   = trim((string) ($staff['psa_licence'] ?? ''));
    $contact   = trim(getCompanyEmail($pdo));
    if ($contact === '' || !filter_var($contact, FILTER_VALIDATE_EMAIL) || str_contains(strtolower($contact), 'example.com')) {
        $contact = 'info@olasentra.com';
    }
    $subject  = 'Olasentra — please check your PSA licence number';
    $greeting = $firstName !== '' ? 'Hi ' . $firstName . ',' : 'Hi,';
    $licenceLine = $licence !== '' ? 'The number we have is ' . $licence . '.'
        : 'We could not read a complete PSA licence number from your form.';
    $intro = 'Thank you for signing up for AVIVA Match – Event Staff at Aviva Stadium on Sunday 8 November 2026, 13:00 – Finish.';
    $notFound = 'We could not find the PSA licence number on your form on the official PSA register of licensed employees. This is often a missing digit or a typing mistake.';
    $ask = 'Please check the number on your PSA badge and reply to this email with the correct number so we can update your details.';
    $url = 'https://www.psa-gov.ie/psa-registered-employees/';

    $text = $greeting . "\n\n" . $intro . "\n\n" . $notFound . "\n\n" . $licenceLine . "\n\n" . $ask . "\n\n"
        . "You can check the PSA register here:\n" . $url . "\n\n"
        . "Your place is still confirmed. You do not need to fill the form again.\n\n— Olasentra\n";
    $html = '<p style="margin:0 0 12px;">' . emailEsc($greeting) . '</p>'
        . buildEmailNotificationCard(
            $pdo,
            'Please check your PSA licence number',
            '<p style="margin:0 0 10px;">' . emailEsc($intro) . '</p>'
            . '<p style="margin:0 0 10px;">' . emailEsc($notFound) . '</p>'
            . '<p style="margin:0 0 10px;">' . emailEsc($licenceLine) . '</p>'
            . '<p style="margin:0 0 10px;">' . emailEsc($ask) . ' (Or email <a href="mailto:' . emailEsc($contact) . '">' . emailEsc($contact) . '</a>.)</p>'
            . '<p style="margin:0;">Your place is still confirmed. You do not need to fill the form again.</p>',
            $url,
            'Open PSA register'
        );
    return sendEmail($pdo, $email, $subject, $text, $html);
}

function avivaMatchSendPsaNumberCheckEmailIfNeeded(PDO $pdo, array $staff, array $verify, int $eventId): void
{
    $status = strtolower(trim((string) ($verify['status'] ?? '')));
    if (!in_array($status, ['not_found', 'invalid_format'], true)) { return; }
    $staffId = (int) ($staff['id'] ?? 0);
    if ($staffId < 1 || $eventId < 1) { return; }
    $key = 'aviva_match_psa_email_sent_' . $staffId . '_' . $eventId;
    if (trim((string) getSetting($pdo, $key, '')) === '1') { return; }
    if (avivaMatchSendPsaNumberCheckEmail($pdo, $staff)) { setSetting($pdo, $key, '1'); }
}

/** Approved staff_registrations row for this staff + event; insert only if none exists (any status kept as is). */
function avivaMatchEnsureRegistration(PDO $pdo, array $staff, array $signup, int $eventId): int
{
    ensureStaffRegistrationSaveSchema($pdo);
    $email = strtolower(trim((string) ($staff['email'] ?? '')));
    $staffId = (int) $staff['id'];
    $hasStaffCol = staffRegistrationColumnExists($pdo, 'staff_id');
    $sql = 'SELECT id FROM staff_registrations WHERE event_id = :ev AND (LOWER(email) = :em'
        . ($hasStaffCol ? ' OR staff_id = :sid' : '') . ') ORDER BY id LIMIT 1';
    $p = ['ev' => $eventId, 'em' => $email];
    if ($hasStaffCol) { $p['sid'] = $staffId; }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $existing = (int) ($st->fetchColumn() ?: 0);
    if ($existing > 0) { return $existing; }

    $role = trim((string) ($signup['staff_role'] ?? ''));
    $row = [
        'staff_id'        => $staffId,
        'surname'         => (string) ($staff['surname'] ?? ''),
        'first_name'      => (string) ($staff['first_name'] ?? ''),
        'full_address'    => (string) ($staff['full_address'] ?? ''),
        'eircode'         => (string) ($staff['eircode'] ?? ''),
        'email'           => $email,
        'mobile'          => (string) ($staff['mobile'] ?? ''),
        'date_of_birth'   => (string) ($staff['date_of_birth'] ?? ''),
        'gender'          => (string) ($staff['gender'] ?? ''),
        'pps_number'      => (string) ($staff['pps_number'] ?? ''),
        'bank_iban'       => (string) ($staff['bank_iban'] ?? ''),
        'psa_licence'     => (string) ($staff['psa_licence'] ?? ''),
        'psa_expiry_date' => (string) ($staff['psa_expiry_date'] ?? ''),
        'staff_role'      => $role !== '' ? $role : (string) ($staff['staff_role'] ?? ''),
        'event_id'        => $eventId,
        'status'          => 'approved',
        'status_token'    => bin2hex(random_bytes(32)),
    ];
    foreach (['date_of_birth', 'psa_expiry_date'] as $d) { if ($row[$d] === '' || str_starts_with($row[$d], '0000')) { $row[$d] = null; } }
    $cols = [];
    $params = [];
    foreach ($row as $c => $v) {
        if (!staffRegistrationColumnExists($pdo, $c)) { continue; }
        $cols[] = $c;
        $params[$c] = $v;
    }
    $pdo->prepare('INSERT INTO staff_registrations (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')
        ->execute($params);
    return (int) $pdo->lastInsertId();
}

/** Run every step for one accepted signup. Safe to call again. */
function avivaMatchOnboardSignup(PDO $pdo, array $signup, array $event): array
{
    $staffId = (int) ($signup['staff_id'] ?? 0);
    $eventId = (int) $event['id'];
    $staff = $staffId > 0 ? getStaffById($pdo, $staffId) : null;
    if ($staff === null) { return ['ok' => false, 'error' => 'staff not found']; }
    ensureStaffPsaSchema($pdo);

    // 1. PSA register lookup (form number, else the one already on the profile).
    $formLicence = normalizePsaLicence((string) ($signup['psa_licence'] ?? ''));
    $licence = $formLicence !== '' ? $formLicence : normalizePsaLicence((string) ($staff['psa_licence'] ?? ''));
    $holder = trim(((string) ($staff['first_name'] ?? '')) . ' ' . ((string) ($staff['surname'] ?? '')));
    $verify = ['ok' => false, 'status' => 'invalid_format', 'message' => 'No PSA licence number.'];
    if ($licence !== '') {
        try { $verify = lookupPsaLicenceOnRegister($licence, $holder); }
        catch (Throwable $e) { $verify = ['ok' => false, 'status' => 'unreachable', 'message' => $e->getMessage()]; }
    }
    $status = (string) ($verify['status'] ?? '');
    $previous = trim((string) ($staff['psa_register_status'] ?? ''));

    // 2. PSA fields only (as 1 Oct). Keep the old number if the form one is blank.
    $fields = [
        'psa_register_status'     => $status,
        'psa_register_name'       => (string) ($verify['register_name'] ?? ''),
        'psa_register_checked_at' => date('Y-m-d H:i:s'),
    ];
    if ($formLicence !== '') { $fields['psa_licence'] = $formLicence; }
    googleFormIntakeUpdateStaff($pdo, $staffId, $fields);

    // 3. Badge photos: the form stores Drive links; save them on the profile the same way 1 Oct does.
    $photos = [];
    foreach (['front' => 'psa_front_path', 'back' => 'psa_back_path'] as $side => $col) {
        $v = trim((string) ($signup[$col] ?? ''));
        if (str_starts_with($v, 'http')) { $photos['psa_' . $side . '_drive_url'] = $v; }
    }
    if ($photos !== []) {
        try { googleFormIntakeSavePsaBadgePhotos($pdo, $staffId, $photos); }
        catch (Throwable $e) { error_log('[AvivaMatch] PSA photos: ' . $e->getMessage()); }
    }

    $staffAfter = getStaffById($pdo, $staffId) ?? $staff;
    // 4. Admin notice + 5. AVIVA Match PSA-number email (both never block acceptance).
    try { notifyAdminPsaLicenceProblemIfNeeded($pdo, $staffAfter, $verify, $previous); }
    catch (Throwable $e) { error_log('[AvivaMatch] PSA admin notice: ' . $e->getMessage()); }
    try { avivaMatchSendPsaNumberCheckEmailIfNeeded($pdo, $staffAfter, $verify, $eventId); }
    catch (Throwable $e) { error_log('[AvivaMatch] PSA email: ' . $e->getMessage()); }

    // 6. Approved registration row (needed before roster sync).
    $regId = avivaMatchEnsureRegistration($pdo, $staffAfter, $signup, $eventId);

    // 7. Shift-details email (1 Oct function + its once-per-staff-per-event dedupe).
    $role = trim((string) ($signup['staff_role'] ?? ''));
    try { sendGoogleFormShiftDetailsEmailIfNeeded($pdo, $staffAfter, $event, $role, $verify); }
    catch (Throwable $e) { error_log('[AvivaMatch] shift email: ' . $e->getMessage()); }

    // 8. Roster sheet (skipped by the shared code if the event has no sheet linked).
    if ($regId > 0) {
        try { require_once __DIR__ . '/google-sheets-sync.php'; syncRegistrationToGoogleSheet($pdo, $regId); }
        catch (Throwable $e) { error_log('[AvivaMatch] sheet sync: ' . $e->getMessage()); }
    }
    return ['ok' => $regId > 0, 'registration_id' => $regId, 'psa_status' => $status];
}

/** Process accepted signups not yet onboarded (new rows and backfill). Skips test rows. */
function avivaMatchOnboardPending(PDO $pdo, int $limit = 10): array
{
    avivaMatchOnboardEnsureSchema($pdo);
    $out = ['onboarded' => 0, 'failed' => 0];
    $event = avivaMatchEnsureEvent($pdo);
    if ($event === null) { return $out + ['error' => 'Could not create the AVIVA Match event.']; }
    $out['event_id'] = (int) $event['id'];
    $st = $pdo->prepare('SELECT * FROM aviva_match_signups WHERE onboarded_at IS NULL AND is_test = 0
        AND onboard_attempts < 5 ORDER BY id LIMIT ' . max(1, min(50, $limit)));
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $pdo->prepare('UPDATE aviva_match_signups SET onboard_attempts = onboard_attempts + 1 WHERE id = ?')->execute([(int) $s['id']]);
        try {
            $r = avivaMatchOnboardSignup($pdo, $s, $event);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!empty($r['ok'])) {
            $pdo->prepare('UPDATE aviva_match_signups SET onboarded_at = NOW(), event_registration_id = ?, psa_status = ?, onboard_error = \'\' WHERE id = ?')
                ->execute([(int) $r['registration_id'], (string) $r['psa_status'], (int) $s['id']]);
            $out['onboarded']++;
        } else {
            $pdo->prepare('UPDATE aviva_match_signups SET onboard_error = ? WHERE id = ?')
                ->execute([mb_substr((string) ($r['error'] ?? 'failed'), 0, 255), (int) $s['id']]);
            $out['failed']++;
        }
    }
    return $out;
}
