<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/attendance-roster-helpers.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';
require_once __DIR__ . '/../includes/staff-pass.php';
require_once __DIR__ . '/../includes/maps.php';

requireAdminCapability('events');

$pdo     = getDB();
$eventId = (int) ($_GET['event_id'] ?? 0);
$event   = $eventId > 0 ? getEventById($pdo, $eventId) : null;

if ($eventId <= 0 || !$event) {
    setAdminFlash('error', 'Please select an event to print the roster.');
    header('Location: attendance.php');
    exit;
}

$list      = getAttendanceList($pdo, $eventId);
$rosterGroups = groupAttendanceRosterRows($list);
$group     = $rosterGroups[0] ?? ['checked_in' => [], 'waiting' => []];
$stats     = getAttendanceStats($pdo, $eventId);
$roleCounts = countRolesInList($list);
$siteName  = getSiteName($pdo);
$venue     = getEventVenueCoordinates($event);
$mapsUrl   = $venue
    ? 'https://www.google.com/maps?q=' . rawurlencode($venue['lat'] . ',' . $venue['lng'])
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Roster — <?= h($event['name']) ?></title>
    <style>
        body { font-family:Segoe UI,Arial,sans-serif; color:#111; padding:1.25rem; max-width:1100px; margin:0 auto; }
        .print-toolbar { display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:1.25rem; }
        .roster-header { border-bottom:2px solid #111; padding-bottom:1rem; margin-bottom:1rem; }
        .roster-header h1 { margin:0 0 0.35rem; font-size:1.6rem; }
        .roster-meta { color:#475569; line-height:1.6; margin:0; }
        .roster-summary { display:flex; flex-wrap:wrap; gap:1rem; margin:1rem 0; }
        .roster-summary span { background:#f1f5f9; border-radius:8px; padding:0.45rem 0.75rem; font-size:0.875rem; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th, td { border:1px solid #cbd5e1; padding:0.45rem 0.5rem; text-align:left; vertical-align:top; }
        th { background:#f8fafc; }
        .check-box { width:16px; height:16px; border:1px solid #64748b; display:inline-block; }
        .btn { display:inline-block; padding:0.55rem 1rem; border-radius:8px; text-decoration:none; border:1px solid #cbd5e1; background:#fff; color:#111; cursor:pointer; font-size:14px; }
        .btn--primary { background:#2563eb; color:#fff; border-color:#2563eb; }
        @media print {
            .print-toolbar, .no-print { display:none !important; }
            body { padding:0; }
            tr { page-break-inside:avoid; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar no-print">
        <button type="button" onclick="window.print()" class="btn btn--primary">Print / Save as PDF</button>
        <a href="attendance.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">← Attendance</a>
        <a href="print-qr.php?event_id=<?= (int) $eventId ?>" class="btn btn--secondary">Staff QR passes</a>
    </div>

    <header class="roster-header">
        <p style="margin:0 0 0.25rem;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;"><?= h($siteName) ?></p>
        <h1><?= h($event['name']) ?> — Event roster</h1>
        <p class="roster-meta">
            <?= h(formatEventDateLabel($event['event_date'])) ?> · <?= h(formatEventTimeRangeLabel($event)) ?><br>
            <?= h(formatEventLocationLabel($event)) ?>
            <?php if ($mapsUrl !== ''): ?><br><a href="<?= h($mapsUrl) ?>"><?= h($mapsUrl) ?></a><?php endif; ?>
        </p>
    </header>

    <div class="roster-summary">
        <span><strong>Approved:</strong> <?= (int) $stats['approved'] ?></span>
        <span><strong>Checked in:</strong> <?= (int) $stats['checked_in'] ?></span>
        <span><strong>Waiting:</strong> <?= (int) $stats['missing'] ?></span>
        <span><strong>No show:</strong> <?= (int) ($stats['no_show'] ?? 0) ?></span>
        <?php if ($stats['staff_needed'] !== null): ?>
            <span><strong>Staff needed:</strong> <?= (int) $stats['staff_needed'] ?> (<?= (int) $stats['spaces_remaining'] ?> left)</span>
        <?php endif; ?>
        <span><strong>DSP:</strong> <?= (int) $roleCounts['dsp'] ?></span>
        <span><strong>Static:</strong> <?= (int) $roleCounts['static'] ?></span>
        <span><strong>Steward:</strong> <?= (int) $roleCounts['steward'] ?></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Pass ID</th>
                <th>Name</th>
                <th>Role</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>Status</th>
                <th>Check-in</th>
                <th>Time</th>
                <th>Hours</th>
                <th>In</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($list === []): ?>
                <tr><td colspan="11">No approved staff for this event.</td></tr>
            <?php else: ?>
                <?php
                $rowNum = 0;
                $renderRosterPrintRow = static function (array $row) use (&$rowNum, $event): void {
                    $rowNum++;
                    ?>
                    <tr>
                        <td><?= $rowNum ?></td>
                        <td><?= h(formatStaffPassId((int) $row['id'], (string) $event['event_date'])) ?></td>
                        <td><?= h($row['first_name'] . ' ' . $row['surname']) ?></td>
                        <td><?= h(formatRoleLabel($row['staff_role'])) ?></td>
                        <td><?= h($row['email']) ?></td>
                        <td><?= h($row['mobile']) ?></td>
                        <td><?php
                            if (isAttendanceRosterCheckedIn($row)) {
                                echo 'Signed in';
                            } elseif (isAttendanceMarkedNoShow($row)) {
                                echo 'No show';
                            } else {
                                echo 'Waiting';
                            }
                        ?></td>
                        <td><?= (int) $row['is_checked_in'] === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= $row['checked_in_at'] ? h(date('H:i', strtotime($row['checked_in_at']))) : '—' ?></td>
                        <td><?= h(formatAttendanceRosterHours($row)) ?></td>
                        <td><span class="check-box" aria-hidden="true"></span></td>
                    </tr>
                    <?php
                };
                ?>

                <?php if ($group['checked_in'] !== []): ?>
                    <?php if ($group['waiting'] !== []): ?>
                        <tr><td colspan="11" style="background:#f8fafc;font-weight:600;">Checked in</td></tr>
                    <?php endif; ?>
                    <?php foreach ($group['checked_in'] as $row): ?>
                        <?php $renderRosterPrintRow($row); ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($group['waiting'] !== []): ?>
                    <tr><td colspan="11" style="background:#f8fafc;font-weight:600;border-top:1px dashed #cbd5e1;">Not yet arrived</td></tr>
                    <?php foreach ($group['waiting'] as $row): ?>
                        <?php $renderRosterPrintRow($row); ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (($group['no_show'] ?? []) !== []): ?>
                    <tr><td colspan="11" style="background:#fef2f2;font-weight:600;border-top:1px dashed #cbd5e1;">No show</td></tr>
                    <?php foreach ($group['no_show'] as $row): ?>
                        <?php $renderRosterPrintRow($row); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <p class="roster-meta no-print" style="margin-top:1rem;">Use <strong>Print / Save as PDF</strong> in your browser for a PDF copy.</p>
</body>
</html>
