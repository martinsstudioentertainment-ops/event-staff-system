<?php
/**
 * Production email encoding verification — sends 5 template types and returns JSON.
 *
 * Web (set reminder_cron_key in Admin → Email, or one-time verify key below):
 *   https://register.olasentra.com/cron/email-production-verify.php?key=KEY&to=you@example.com
 *
 * CLI on server:
 *   php cron/email-production-verify.php --to=you@example.com
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/email-copy.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_once dirname(__DIR__) . '/includes/access-pass-email.php';
require_once dirname(__DIR__) . '/includes/reminders.php';
require_once dirname(__DIR__) . '/includes/notification-center.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/status-repository.php';

/** One-time encoding audit key (June 2026) — remove after verification if desired. */
const EMAIL_ENCODING_VERIFY_FALLBACK_KEY = 'email-encoding-verify-20260606';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');
$opts  = $isCli ? getopt('', ['to:', 'key::']) : [];
$to    = trim((string) ($opts['to'] ?? $_GET['to'] ?? ''));
$key   = trim((string) ($opts['key'] ?? $_GET['key'] ?? ''));

function email_verify_json(array $payload, int $code = 200): void
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

function email_verify_bad_chars(string $text): array
{
    $patterns = [
        'mojibake_em_dash' => '/â€[""]/u',
        'mojibake_middle'  => '/Â·/u',
        'mojibake_stray'   => '/Â[^a-zA-Z]/u',
        'unicode_en_dash'  => '/\x{2013}/u',
        'unicode_em_dash'  => '/\x{2014}/u',
        'unicode_middle'   => '/\x{00B7}/u',
    ];
    $found = [];
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $text)) {
            $found[] = $name;
        }
    }

    return $found;
}

function email_verify_scan(string $subject, string $text, string $html = ''): array
{
    $combined = $subject . "\n" . $text . "\n" . $html;
    $bad      = email_verify_bad_chars($combined);

    return [
        'pass'       => $bad === [],
        'bad_tokens' => $bad,
        'sample'     => mb_substr($combined, 0, 400),
    ];
}

try {
    $pdo = getDB();

    if (!$isCli) {
        $allowedKeys = array_values(array_unique(array_filter([
            trim(getSetting($pdo, 'reminder_cron_key', '')),
            EMAIL_ENCODING_VERIFY_FALLBACK_KEY,
        ])));
        $keyOk = false;
        foreach ($allowedKeys as $allowed) {
            if ($key !== '' && hash_equals($allowed, $key)) {
                $keyOk = true;
                break;
            }
        }
        if (!$keyOk) {
            email_verify_json(['ok' => false, 'error' => 'Forbidden — invalid or missing key'], 403);
        }
    }

    if ($to === '') {
        $to = trim(getSetting($pdo, 'notify_admin_email', ''));
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $to = trim(getSetting($pdo, 'company_email', ''));
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        email_verify_json(['ok' => false, 'error' => 'Valid --to= or notify_admin_email required'], 400);
    }

    $regSql = "SELECT sr.*, e.name AS event_name, e.event_date, e.location, e.start_time, e.end_time
               FROM staff_registrations sr
               INNER JOIN events e ON e.id = sr.event_id
               WHERE sr.status = :status
               ORDER BY sr.id DESC
               LIMIT 1";
    $stmt = $pdo->prepare($regSql);
    $stmt->execute(['status' => 'approved']);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($reg)) {
        $stmt->execute(['status' => 'pending']);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!is_array($reg)) {
        email_verify_json(['ok' => false, 'error' => 'No registration row in database for sample emails'], 500);
    }

    $siteName    = getSiteName($pdo);
    $transport   = getMailTransport($pdo);
    $results     = [];
    $allPass     = true;

    // 1. Registration confirmation
    $data = [
        'email'      => $to,
        'first_name' => (string) ($reg['first_name'] ?? 'Verify'),
        'staff_role' => (string) ($reg['staff_role'] ?? 'door'),
    ];
    $subject1 = $siteName . ' - Registration Received';
    $body1    = [
        'Dear ' . $data['first_name'] . ',',
        '',
        'We have received your staff registration for the following event(s):',
        '',
        '* ' . formatEventLabelForEmail($reg),
        '',
        'Role: ' . formatRoleLabel($data['staff_role']),
        'Status: Pending approval',
        'Reporting time: ' . formatEventTimeRangeLabelForEmail($reg),
        'Venue: ' . formatEventLocationLabelForEmail($reg),
    ];
    $text1 = implode("\n", $body1);
    $html1 = buildStaffEmailHtmlFromLines($body1);
    $sent1 = sendEmail($pdo, $to, $subject1, $text1, $html1);
    $scan1 = email_verify_scan($subject1, $text1, $html1);
    $results['registration_confirmation'] = ['sent' => $sent1, 'subject' => $subject1, 'scan' => $scan1, 'html' => $html1];
    $allPass = $allPass && $scan1['pass'];

    // 2 + 3. Registration approved / access pass (same template)
    $reg['status'] = 'approved';
    $pass          = buildConsolidatedAccessPassEmail($pdo, [$reg]);
    $sent2         = $pass ? sendEmail($pdo, $to, $pass['subject'], $pass['text'], $pass['html']) : false;
    $scan2         = $pass ? email_verify_scan($pass['subject'], $pass['text'], $pass['html']) : ['pass' => false, 'bad_tokens' => ['build_failed'], 'sample' => ''];
    $results['registration_approved'] = ['sent' => $sent2, 'subject' => $pass['subject'] ?? '', 'scan' => $scan2, 'html' => $pass['html'] ?? ''];
    $results['access_pass']           = $results['registration_approved'];
    $allPass = $allPass && ($scan2['pass'] ?? false);

    // 4. Event reminder (plain)
    $subject4 = $siteName . ' - Reminder: ' . (string) ($reg['event_name'] ?? 'Your event');
    $text4    = implode("\n", [
        'Dear ' . ($reg['first_name'] ?? 'there') . ',',
        '',
        'Time: ' . formatEventTimeRangeLabelForEmail($reg),
        'Location: ' . formatEventLocationLabelForEmail($reg),
        'Event: ' . formatEventLabelForEmail($reg),
        'Check-in window: 05/07/2026 14:00 - 05/07/2026 23:00',
    ]);
    $sent4 = sendEmail($pdo, $to, $subject4, $text4);
    $scan4 = email_verify_scan($subject4, $text4);
    $results['event_reminder'] = ['sent' => $sent4, 'subject' => $subject4, 'scan' => $scan4, 'text' => $text4];
    $allPass = $allPass && $scan4['pass'];

    // 5. Admin alert
    $adminSubject = 'New staff registration';
    $adminHtml    = '<p><strong>' . htmlspecialchars((string) ($reg['first_name'] ?? 'Staff'), ENT_QUOTES, 'UTF-8') . '</strong> registered for '
        . htmlspecialchars(formatEventLabelForEmail($reg), ENT_QUOTES, 'UTF-8') . '.</p>'
        . '<p>Reporting time: ' . htmlspecialchars(formatEventTimeRangeLabelForEmail($reg), ENT_QUOTES, 'UTF-8') . '</p>';
    $adminPlain = strip_tags(str_replace(['<p>', '</p>'], ["\n", "\n"], $adminHtml));
    $sent5      = sendEmail($pdo, $to, '[' . $siteName . '] ' . $adminSubject, $adminPlain, $adminHtml);
    $scan5      = email_verify_scan('[' . $siteName . '] ' . $adminSubject, $adminPlain, $adminHtml);
    $results['admin_alert'] = ['sent' => $sent5, 'subject' => '[' . $siteName . '] ' . $adminSubject, 'scan' => $scan5, 'html' => $adminHtml];
    $allPass = $allPass && $scan5['pass'];

    // 6. Rejection email (same template path as sendConsolidatedRejectionEmail)
    $subject6 = $siteName . ' - Registration update';
    $body6    = [
        'Dear ' . (string) ($reg['first_name'] ?? 'Verify') . ',',
        '',
        'Thank you for your interest. Your staff registration was not approved at this time.',
        '',
        '* ' . formatEventLabelForEmail($reg) . ' - ' . formatRoleLabel((string) ($reg['staff_role'] ?? 'door')),
    ];
    $statusUrl = '';
    if (function_exists('ensureStatusToken') && function_exists('getStatusUrl')) {
        $statusToken = ensureStatusToken($pdo, (int) $reg['id']);
        if ($statusToken) {
            $statusUrl = getStatusUrl($statusToken, $pdo);
            $body6[] = '';
            $body6[] = 'View your registration status anytime:';
            $body6[] = $statusUrl;
        }
    }
    $body6[] = '';
    $body6[] = 'If you have questions, please contact us using the contact details on the website.';
    if (function_exists('appendEmailPortalContext')) {
        $body6 = appendEmailPortalContext($pdo, $body6);
    }
    $body6[] = '';
    $body6[] = 'Regards,';
    $body6[] = $siteName;
    $text6 = implode("\n", $body6);
    $html6 = buildStaffEmailHtmlFromLines(
        $body6,
        $statusUrl !== '' ? $statusUrl : null,
        $statusUrl !== '' ? 'View my status' : null
    );
    $sent6 = sendEmail($pdo, $to, $subject6, $text6, $html6);
    $scan6 = email_verify_scan($subject6, $text6, $html6);
    $results['rejection_email'] = ['sent' => $sent6, 'subject' => $subject6, 'scan' => $scan6, 'html' => $html6];
    $allPass = $allPass && $scan6['pass'];

    $report = [
        'ok'           => true,
        'generated_at' => gmdate('c'),
        'to'           => $to,
        'transport'    => $transport,
        'site_name'    => $siteName,
        'all_pass'     => $allPass,
        'templates'    => $results,
    ];

    $outDir = dirname(__DIR__) . '/storage/reports';
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }
    file_put_contents($outDir . '/email-production-verify-latest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    email_verify_json($report);
} catch (Throwable $e) {
    email_verify_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
