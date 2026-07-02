<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/staff-app-v3-data.php';

$failures = 0;

function assertTrue(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        $failures++;
    }
}

// Active shift with projected hours (Phase 3 bug scenario).
$activeRow = [
    'status'             => 'approved',
    'event_date'         => date('Y-m-d'),
    'event_start_time'   => date('H:i', strtotime('-2 hours')),
    'event_end_time'     => date('H:i', strtotime('+4 hours')),
    'checked_in_at'      => date('Y-m-d H:i:s', strtotime('-1 hour')),
    'checked_out_at'     => '',
    'hours_worked'       => 6.0,
    'attendance_status'  => 'active',
    'attendance_id'      => 1,
    'is_checked_in'      => 1,
];

$p = getStaffV3ShiftTimeProgress($activeRow);
echo "ACTIVE: state={$p['state']} pct={$p['percent']} label={$p['label']}\n";
assertTrue($p['state'] === 'live', 'active state should be live');
assertTrue($p['percent'] < 100, 'active progress must not be 100%');
assertTrue(str_contains((string) $p['label'], 'Active'), 'label should indicate active shift');
assertTrue(!str_contains((string) $p['label'], 'hrs worked'), 'must not show projected worked hours');

$doneRow                   = $activeRow;
$doneRow['checked_out_at'] = date('Y-m-d H:i:s');
$doneRow['hours_worked']   = 5.5;

$p2 = getStaffV3ShiftTimeProgress($doneRow);
echo "DONE: state={$p2['state']} pct={$p2['percent']} label={$p2['label']}\n";
assertTrue($p2['state'] === 'done', 'completed state should be done');
assertTrue(str_contains((string) $p2['label'], '5.5 hrs worked'), 'completed shows stored hours');

$hist = formatStaffV3HistoryHoursLabel($activeRow);
echo "HIST_ACTIVE: {$hist}\n";
assertTrue($hist === 'In progress', 'history active shows in progress');

$hist2 = formatStaffV3HistoryHoursLabel($doneRow);
echo "HIST_DONE: {$hist2}\n";
assertTrue($hist2 === '5.5 hrs', 'history done shows stored hours');

$overnight = [
    'event_date'       => date('Y-m-d', strtotime('-1 day')),
    'event_start_time' => '22:00',
    'event_end_time'   => '06:00',
];
$w = staffV3ResolveShiftScheduledWindow($overnight);
echo 'OVERNIGHT: start=' . $w['start']->format('Y-m-d H:i') . ' end=' . $w['end']->format('Y-m-d H:i') . "\n";
assertTrue($w['end'] > $w['start'], 'overnight end must be after start');

if ($failures === 0) {
    echo "ALL ASSERTIONS PASSED\n";
    exit(0);
}

echo "{$failures} ASSERTION(S) FAILED\n";
exit(1);
