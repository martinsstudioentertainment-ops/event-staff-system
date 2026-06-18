<?php
/**
 * Verify email copy uses ASCII-safe punctuation (no en/em dash or middle dot in outbound email strings).
 *
 * Usage: php scripts/verify-email-encoding.php [--json]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/events-repository.php';
require_once $root . '/includes/staff-repository.php';
require_once $root . '/includes/email-copy.php';
require_once $root . '/includes/mailer.php';
require_once $root . '/includes/access-pass-email.php';
require_once $root . '/includes/notifications.php';
require_once $root . '/includes/reminders.php';

$badPattern = '/[\x{2013}\x{2014}\x{00B7}\x{2022}]/u';

$event = [
    'start_time'     => '15:00:00',
    'end_time'       => '23:00:00',
    'event_name'     => 'Longitude 2026',
    'event_date'     => '2026-07-05',
    'location'       => 'Aviva Stadium',
    'venue_eircode'  => 'D04 K5F9',
    'staff_role'     => 'door',
    'first_name'     => 'Test',
    'status'         => 'approved',
    'id'             => 1,
];

$checks = [];

function assertAsciiEmail(string $label, string $value, string $pattern, array &$checks): void
{
    $isUiCheck = str_contains($label, '(unchanged)');
    $ok        = $isUiCheck
        ? preg_match($pattern, $value) === 1
        : preg_match($pattern, $value) !== 1;
    $checks[] = [
        'check'  => $label,
        'value'  => $value,
        'pass'   => $ok,
        'hex'    => bin2hex($value),
    ];
}

assertAsciiEmail('UI formatEventTimeRangeLabel (unchanged)', formatEventTimeRangeLabel($event), $badPattern, $checks);
assertAsciiEmail('Email formatEventTimeRangeLabelForEmail', formatEventTimeRangeLabelForEmail($event), $badPattern, $checks);
assertAsciiEmail('UI formatEventLabel (unchanged)', formatEventLabel($event), $badPattern, $checks);
assertAsciiEmail('Email formatEventLabelForEmail', formatEventLabelForEmail($event), $badPattern, $checks);
assertAsciiEmail('UI formatEventLocationLabel (unchanged)', formatEventLocationLabel($event), $badPattern, $checks);
assertAsciiEmail('Email formatEventLocationLabelForEmail', formatEventLocationLabelForEmail($event), $badPattern, $checks);
assertAsciiEmail('formatEmailEventNameDateCell', formatEmailEventNameDateCell('Longitude 2026', '05/07/2026'), $badPattern, $checks);

$mime = buildEmailMimePayload('Reporting time: ' . formatEventTimeRangeLabelForEmail($event), null);
assertAsciiEmail('MIME plain body', $mime['body'], $badPattern, $checks);

$htmlTimes = formatEventTimeRangeLabelForEmail($event);
$mimeHtml  = buildEmailMimePayload('', '<td>' . htmlspecialchars($htmlTimes, ENT_QUOTES, 'UTF-8') . '</td>');
$checks[] = [
    'check' => 'MIME HTML QP contains no raw en-dash bytes',
    'value' => $mimeHtml['body'],
    'pass'  => !str_contains($mimeHtml['body'], '=E2=80=93') && str_contains($mimeHtml['body'], '15:00 - 23:00')
        && !str_contains($mimeHtml['body'], "\xE2\x80\x93"),
    'hex'   => '',
];

$mimeDual = buildEmailMimePayload(
    'Reporting time: ' . formatEventTimeRangeLabelForEmail($event),
    '<p>Reporting time: ' . htmlspecialchars($htmlTimes, ENT_QUOTES, 'UTF-8') . '</p>'
);
$checks[] = [
    'check' => 'MIME multipart/alternative when plain+html provided',
    'value' => $mimeDual['content_type'],
    'pass'  => str_starts_with($mimeDual['content_type'], 'multipart/alternative')
        && str_contains($mimeDual['body'], 'Content-Type: text/plain')
        && str_contains($mimeDual['body'], 'Content-Type: text/html'),
    'hex'   => '',
];

$allPass = true;
foreach ($checks as $c) {
    if (!$c['pass']) {
        $allPass = false;
        break;
    }
}

$report = [
    'generated_at' => gmdate('c'),
    'all_pass'     => $allPass,
    'expected_time_range' => '15:00 - 23:00',
    'checks'       => $checks,
];

$outDir = $root . '/storage/reports';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
file_put_contents($outDir . '/email-encoding-verify-latest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if (in_array('--json', $argv ?? [], true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($allPass ? 0 : 1);
}

echo ($allPass ? 'PASS' : 'FAIL') . ' — email encoding verification' . PHP_EOL;
echo 'Time range (email): ' . formatEventTimeRangeLabelForEmail($event) . PHP_EOL;
echo 'Time range (UI):    ' . formatEventTimeRangeLabel($event) . PHP_EOL;
echo 'Report: storage/reports/email-encoding-verify-latest.json' . PHP_EOL;
exit($allPass ? 0 : 1);
