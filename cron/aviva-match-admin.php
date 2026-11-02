<?php
declare(strict_types=1);
/**
 * AVIVA Match helper — COMMAND LINE ONLY (web denied here and in cron/.htaccess). Prints no secrets.
 *   php -q cron/aviva-match-admin.php setup <rosterSheetId> [tab]   create/keep the INACTIVE event + link roster (no onboarding)
 *   php -q cron/aviva-match-admin.php list                           accepted sign-ups + onboard status
 *   php -q cron/aviva-match-admin.php import                         read new form rows + column P, NO onboarding
 *   php -q cron/aviva-match-admin.php find <email>                   show signup id / staff id for an email
 *   php -q cron/aviva-match-admin.php onboard-one <signupId>         onboard just that sign-up (test), safe to repeat
 *   php -q cron/aviva-match-admin.php cleanup-test <signupId> <staffId> [--delete-staff]
 */
$cli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg'
    || (!isset($_SERVER['REQUEST_METHOD']) && !isset($_SERVER['SERVER_SOFTWARE']) && !isset($_SERVER['HTTP_HOST']));
if (!$cli) { http_response_code(403); echo "Forbidden\n"; exit; }
@set_time_limit(600);
chdir(dirname(__DIR__));
foreach (['HTTP_HOST' => 'olasentra.com', 'SERVER_NAME' => 'olasentra.com', 'HTTPS' => 'on',
          'DOCUMENT_ROOT' => dirname(__DIR__), 'REQUEST_URI' => '/cron/aviva-match-admin.php', 'REMOTE_ADDR' => '127.0.0.1'] as $k => $v) {
    $_SERVER[$k] = $_SERVER[$k] ?? $v;
}
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/aviva-match-sheet-sync.php';
require_once dirname(__DIR__) . '/includes/aviva-match-onboard.php';

$pdo = getDB();
avivaMatchOnboardEnsureSchema($pdo);
$args = array_slice($argv ?? [], 1);
$cmd = $args[0] ?? '';
$pr = static function ($x): void { echo json_encode($x, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; };

switch ($cmd) {
case 'setup':
    $sid = trim((string) ($args[1] ?? ''));
    $tab = trim((string) ($args[2] ?? 'Registrations'));
    if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $sid)) { fwrite(STDERR, "Give the roster sheet ID\n"); exit(1); }
    $ev = avivaMatchEnsureEvent($pdo);
    if ($ev === null) { fwrite(STDERR, "Could not create event\n"); exit(1); }
    $pdo->prepare('UPDATE events SET google_sheet_url = ?, google_sheet_tab = ?, is_active = 0 WHERE id = ?')
        ->execute(['https://docs.google.com/spreadsheets/d/' . $sid . '/edit', $tab, (int) $ev['id']]);
    $ev = getEventById($pdo, (int) $ev['id']);
    $pr(array_intersect_key($ev, array_flip(['id', 'name', 'event_date', 'start_time', 'location', 'is_active', 'times_confirmed', 'staff_needed', 'google_sheet_url', 'google_sheet_tab'])));
    break;
case 'list':
    $rows = $pdo->query('SELECT s.id signup_id, s.staff_id, s.email, s.full_name, s.is_test, s.source, s.psa_status, s.onboarded_at,
        s.event_registration_id, s.onboard_attempts, s.onboard_error FROM aviva_match_signups s ORDER BY s.id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { echo implode(' | ', array_map(static fn($v) => (string) $v, $r)), "\n"; }
    echo 'count=' . count($rows) . ' onboarded=' . count(array_filter($rows, static fn($r) => $r['onboarded_at'] !== null))
        . ' event_id=' . getSetting($pdo, 'aviva_match_event_id', '-') . "\n";
    break;
case 'import':
    $pr(avivaMatchSheetSync($pdo, 200, false));
    break;
case 'find':
    $st = $pdo->prepare('SELECT id signup_id, staff_id, email, is_test, onboarded_at FROM aviva_match_signups WHERE LOWER(email) = LOWER(?) OR LOWER(form_email) = LOWER(?)');
    $st->execute([(string) ($args[1] ?? ''), (string) ($args[1] ?? '')]);
    $pr($st->fetchAll(PDO::FETCH_ASSOC));
    break;
case 'onboard-one':
    $id = (int) ($args[1] ?? 0);
    $st = $pdo->prepare('SELECT * FROM aviva_match_signups WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) { fwrite(STDERR, "No such signup\n"); exit(1); }
    $pdo->prepare('UPDATE aviva_match_signups SET is_test = 1 WHERE id = ?')->execute([$id]); // keep it out of the cron batch
    $ev = avivaMatchEnsureEvent($pdo);
    $r = avivaMatchOnboardSignup($pdo, $s, $ev);
    if (!empty($r['ok'])) {
        $pdo->prepare('UPDATE aviva_match_signups SET onboarded_at = NOW(), event_registration_id = ?, psa_status = ? WHERE id = ?')
            ->execute([(int) $r['registration_id'], (string) $r['psa_status'], $id]);
    }
    $sid = (int) $s['staff_id']; $eid = (int) $ev['id'];
    $r['dedupe'] = [
        'psa_email_sent'   => getSetting($pdo, 'aviva_match_psa_email_sent_' . $sid . '_' . $eid, ''),
        'shift_email_sent' => getSetting($pdo, googleFormShiftDetailsDedupeKey($sid, $eid, strtolower((string) $s['email'])), ''),
    ];
    $reg = $pdo->prepare('SELECT id, status, event_id, staff_id FROM staff_registrations WHERE id = ?');
    $reg->execute([(int) ($r['registration_id'] ?? 0)]);
    $r['registration'] = $reg->fetch(PDO::FETCH_ASSOC) ?: null;
    $n = $pdo->prepare("SELECT COUNT(*) FROM app_notifications WHERE related_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
    try { $n->execute([$sid]); $r['admin_notices_last_hour'] = (int) $n->fetchColumn(); } catch (Throwable $e) {}
    $pr($r);
    break;
case 'cleanup-test':
    $id = (int) ($args[1] ?? 0); $sid = (int) ($args[2] ?? 0);
    $st = $pdo->prepare('SELECT * FROM aviva_match_signups WHERE id = ? AND staff_id = ? AND is_test = 1');
    $st->execute([$id, $sid]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) { fwrite(STDERR, "Refused: signup must exist, match the staff id and be is_test=1 (run onboard-one first)\n"); exit(1); }
    $ev = avivaMatchEnsureEvent($pdo); $eid = (int) $ev['id'];
    $out = [];
    $regId = (int) ($s['event_registration_id'] ?? 0);
    if ($regId > 0) {
        $sheetUrl = (string) ($ev['google_sheet_url'] ?? '');
        if (preg_match('#/d/([A-Za-z0-9_-]+)#', $sheetUrl, $m)) {
            $auth = googleSheetsResolveApiAuth($pdo);
            $out['roster_row_deleted'] = $auth ? googleSheetsDeleteRegistrationRow($auth, $m[1], (string) ($ev['google_sheet_tab'] ?? 'Registrations'), $regId) : 'no auth';
        }
        $out['registrations_deleted'] = $pdo->prepare('DELETE FROM staff_registrations WHERE id = ? AND event_id = ?')->execute([$regId, $eid]);
    }
    foreach (['aviva_match_psa_email_sent_' . $sid . '_' . $eid, googleFormShiftDetailsDedupeKey($sid, $eid, strtolower((string) $s['email']))] as $k) {
        $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$k]);
    }
    $pdo->prepare('DELETE FROM aviva_match_sheet_rows WHERE staff_id = ?')->execute([$sid]); // row stays "processed" via the row counter
    $pdo->prepare('DELETE FROM aviva_match_signups WHERE id = ?')->execute([$id]);
    try { $pdo->prepare('DELETE FROM app_notifications WHERE related_id = ? AND type LIKE ?')->execute([$sid, '%psa%']); } catch (Throwable $e) {}
    if (in_array('--delete-staff', $args, true)) {
        $pdo->prepare('DELETE FROM staff WHERE id = ? AND LOWER(email) = LOWER(?)')->execute([$sid, (string) $s['email']]);
        $out['staff_deleted'] = $sid;
    }
    $out['count_now'] = avivaMatchCount($pdo);
    $pr($out);
    break;
default:
    fwrite(STDERR, "Commands: setup | list | import | find | onboard-one | cleanup-test (see file header)\n");
    exit(1);
}
