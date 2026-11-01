<?php

declare(strict_types=1);

require_once __DIR__ . '/../staff-allocation.php';
require_once __DIR__ . '/../workforce/compliance-repository.php';

/** @param array<string, mixed> $ctx @return array<string, mixed> */
function dash_build_redesign_context(PDO $pdo, array $ctx): array
{
    $todayYmd        = (string) ($ctx['todayYmd'] ?? date('Y-m-d'));
    $stats           = is_array($ctx['stats'] ?? null) ? $ctx['stats'] : [];
    $finance         = is_array($ctx['financeSnap'] ?? null) ? $ctx['financeSnap'] : null;
    $attendanceIntel = is_array($ctx['attendanceIntel'] ?? null) ? $ctx['attendanceIntel'] : null;
    $upcomingEvents  = is_array($ctx['upcomingEvents'] ?? null) ? $ctx['upcomingEvents'] : [];
    $understaffed    = is_array($ctx['understaffedEvents'] ?? null) ? $ctx['understaffedEvents'] : [];
    $gpsFlagOn       = !empty($ctx['gpsFlagOn']);

    $waitingListCount   = dash_r_waiting_list_count($pdo);
    $awaitingAllocation = dash_r_awaiting_allocation_count($pdo);
    $compliance         = adminCan('staff') ? wf_compliance_summary($pdo) : ['expiring' => 0];
    $expiringPsa        = (int) ($compliance['expiring'] ?? 0);
    $failedEmails       = dash_r_failed_email_count($pdo);
    $revenueYear        = dash_r_revenue_year($pdo);
    $regStats           = dash_r_registration_stats($pdo, $stats);
    $gpsToday           = dash_r_gps_today($pdo, $todayYmd, $upcomingEvents, $attendanceIntel);
    $attendancePeriods  = dash_r_attendance_periods($pdo);
    $allocation         = dash_r_allocation_summary($pdo, $stats, $upcomingEvents, $waitingListCount, $awaitingAllocation);
    $messageThreads     = is_array($ctx['messageThreads'] ?? null) ? $ctx['messageThreads'] : [];
    $messageUnread      = (int) ($ctx['messageUnread'] ?? 0);

    $todayEvents      = [];
    $understaffedCount = count($understaffed);
    $completedCount   = max(0, count($upcomingEvents) - $understaffedCount);
    $attentionCount   = count(array_filter($upcomingEvents, static function (array $e) use ($todayYmd): bool {
        return substr((string) ($e['event_date'] ?? ''), 0, 10) === $todayYmd
            && (int) ($e['coverage_gap'] ?? 0) > 0;
    }));

    foreach ($upcomingEvents as $event) {
        $card = dash_r_event_card($event, $todayYmd);
        if ($card['is_today']) {
            $todayEvents[] = $card;
        }
    }

    $financeBlock = [
        'available'          => $finance !== null && !empty($finance['available']),
        'outstanding_count'  => $finance !== null ? (int) ($finance['outstanding_count'] ?? 0) : 0,
        'outstanding_amount' => $finance !== null ? (float) ($finance['outstanding_amount'] ?? 0) : 0.0,
        'overdue_count'      => $finance !== null ? (int) ($finance['overdue_count'] ?? 0) : 0,
        'overdue_amount'     => $finance !== null ? (float) ($finance['overdue_amount'] ?? 0) : 0.0,
        'revenue_month'      => $finance !== null ? (float) ($finance['revenue_month'] ?? 0) : 0.0,
        'revenue_year'       => $revenueYear,
    ];

    $attendanceRate = (float) ($attendancePeriods['daily']['rate'] ?? 0);
    $healthScore    = dash_r_operations_health_score(
        $understaffed,
        $attendanceRate,
        (float) ($gpsToday['compliance_pct'] ?? 100),
        (int) ($stats['pending'] ?? 0),
        $financeBlock,
        $messageUnread
    );
    $healthBars = dash_r_health_bars(
        $understaffed,
        count($upcomingEvents),
        $attendanceRate,
        (float) ($gpsToday['compliance_pct'] ?? 100),
        (int) ($stats['pending'] ?? 0),
        $failedEmails,
        $messageUnread
    );

    $awaitingReply = dash_r_conversations_awaiting_reply($messageThreads, $messageUnread);
    $broadcastsToday = dash_r_broadcasts_sent_today($pdo);

    return [
        'header' => [
            'upcoming_events'      => count($upcomingEvents),
            'staff_allocated'      => (int) ($allocation['total_allocated'] ?? 0),
            'staff_expected_today' => (int) ($gpsToday['expected'] ?? 0),
            'events_need_attention'=> $attentionCount > 0 ? $attentionCount : $understaffedCount,
            'gps_status'           => $gpsFlagOn ? 'Active' : 'Legacy mode',
            'gps_active'           => $gpsFlagOn,
            'attendance_status'    => dash_r_attendance_status_label($attendanceIntel, $attendanceRate),
        ],
        'today_events'   => $todayEvents,
        'pillars'        => [
            'workforce' => [
                'label'  => 'Workforce',
                'href'   => 'staff-directory.php',
                'items'  => [
                    ['label' => 'Total staff', 'value' => (int) ($stats['unique_staff'] ?? 0), 'href' => 'staff-directory.php'],
                    ['label' => 'Staff working today', 'value' => (int) ($stats['today_checkins'] ?? 0), 'href' => 'attendance.php'],
                    ['label' => 'Waiting list', 'value' => $waitingListCount, 'href' => 'allocation-centre.php'],
                ],
            ],
            'events' => [
                'label'  => 'Events',
                'href'   => 'events.php',
                'items'  => [
                    ['label' => 'Upcoming events', 'value' => count($upcomingEvents), 'href' => 'events.php'],
                    ['label' => 'Understaffed events', 'value' => $understaffedCount, 'href' => '#odv3-event-wall'],
                    ['label' => 'Completed events', 'value' => $completedCount, 'href' => 'events.php'],
                ],
            ],
            'attendance' => [
                'label'  => 'Attendance',
                'href'   => 'attendance.php',
                'items'  => [
                    ['label' => 'Expected today', 'value' => (int) ($gpsToday['expected'] ?? 0), 'href' => 'attendance.php'],
                    ['label' => 'Checked in', 'value' => (int) ($gpsToday['checked_in'] ?? 0), 'href' => 'attendance.php'],
                    ['label' => 'Attendance %', 'value' => dash_r_format_pct($attendanceRate), 'href' => 'attendance.php', 'is_text' => true],
                ],
            ],
            'communications' => [
                'label'  => 'Communications',
                'href'   => 'staff-inbox.php',
                'items'  => [
                    ['label' => 'Unread messages', 'value' => $messageUnread, 'href' => 'staff-inbox.php'],
                    ['label' => 'Failed emails', 'value' => $failedEmails, 'href' => 'communication-hub.php'],
                    ['label' => 'Active conversations', 'value' => count($messageThreads), 'href' => 'staff-inbox.php'],
                ],
            ],
        ],
        'health'             => $healthScore,
        'health_bars'        => $healthBars,
        'gps_today'          => $gpsToday,
        'gps_compliance'     => $gpsToday['compliance'] ?? [],
        'allocation'         => $allocation,
        'registration'       => $regStats,
        'operations'         => dash_r_action_required_items(
            $understaffed,
            $stats,
            $awaitingAllocation,
            $expiringPsa,
            $failedEmails,
            $attendanceIntel,
            $financeBlock
        ),
        'finance'            => $financeBlock,
        'communication'      => [
            'unread_messages'           => $messageUnread,
            'awaiting_reply'            => $awaitingReply,
            'failed_emails'             => $failedEmails,
            'broadcasts_sent_today'     => $broadcastsToday,
            'active_conversations'      => count($messageThreads),
        ],
        'recent_activity'    => dash_r_recent_activity($pdo, $ctx),
    ];
}

function dash_r_safe_count(PDO $pdo, string $sql): int
{
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dash_r_waiting_list_count(PDO $pdo): int
{
    if (!adminCan('staff')) {
        return 0;
    }
    ensureStaffAllocationSchema($pdo);
    if (!staffAllocationTableExists($pdo, 'staff_waitlist')) {
        return 0;
    }

    return dash_r_safe_count($pdo, "SELECT COUNT(*) FROM staff_waitlist WHERE status = 'active'");
}

function dash_r_awaiting_allocation_count(PDO $pdo): int
{
    if (!adminCan('staff')) {
        return 0;
    }
    $count = count(getPendingRegistrationsForAllocation($pdo, null, 200));
    if (staffAllocationTableExists($pdo, 'staff_waitlist')) {
        $count += dash_r_safe_count($pdo,
            "SELECT COUNT(*) FROM staff_waitlist WHERE status = 'active' AND allocation_type = 'pending_allocation'"
        );
    }

    return $count;
}

function dash_r_failed_email_count(PDO $pdo): int
{
    if (!adminCan('staff') || !tableExists($pdo, 'staff_messages')) {
        return 0;
    }

    return dash_r_safe_count($pdo,
        "SELECT COUNT(*) FROM staff_messages WHERE delivery_status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
}

function dash_r_revenue_year(PDO $pdo): float
{
    if (!adminCan('invoices') || !tableExists($pdo, 'commission_invoices')) {
        return 0.0;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM commission_invoices
             WHERE status = 'paid' AND DATE(updated_at) >= :ys"
        );
        $stmt->execute(['ys' => date('Y-01-01')]);

        return round((float) $stmt->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** @param array<string, mixed> $stats @return array<string, int> */
function dash_r_registration_stats(PDO $pdo, array $stats): array
{
    return [
        'today'           => dash_r_safe_count($pdo,
            "SELECT COUNT(*) FROM staff_registrations WHERE DATE(created_at) = CURDATE()"
        ),
        'pending'         => (int) ($stats['pending'] ?? 0),
        'approved_today'  => dash_r_safe_count($pdo,
            "SELECT COUNT(*) FROM staff_registrations WHERE status = 'approved' AND DATE(updated_at) = CURDATE()"
        ),
        'rejected_today'  => dash_r_safe_count($pdo,
            "SELECT COUNT(*) FROM staff_registrations WHERE status = 'rejected' AND DATE(updated_at) = CURDATE()"
        ),
    ];
}

/** @param list<array<string, mixed>> $threads */
function dash_r_conversations_awaiting_reply(array $threads, int $fallbackUnread): int
{
    $awaiting = 0;
    foreach ($threads as $thread) {
        $awaiting += (int) ($thread['unread_count'] ?? 0);
    }

    return $awaiting > 0 ? $awaiting : $fallbackUnread;
}

function dash_r_broadcasts_sent_today(PDO $pdo): int
{
    if (!tableExists($pdo, 'admin_audit_log')) {
        return 0;
    }

    return dash_r_safe_count($pdo,
        "SELECT COUNT(*) FROM admin_audit_log
         WHERE action IN ('staff_email', 'open_shift_broadcast')
           AND DATE(created_at) = CURDATE()"
    );
}

/** @param array<string, mixed>|null $intel */
function dash_r_attendance_status_label(?array $intel, float $rate): string
{
    if ($intel === null) {
        return 'Monitoring';
    }
    $issues = (int) ($intel['exceptions'] ?? 0) + (int) ($intel['gps_issues'] ?? 0);
    if ($issues > 0) {
        return $issues . ' issue' . ($issues === 1 ? '' : 's') . ' today';
    }
    if ($rate >= 90) {
        return 'On track';
    }
    if ($rate >= 70) {
        return 'Monitoring';
    }

    return 'Needs attention';
}

/**
 * @param list<array<string, mixed>> $upcomingEvents
 * @param array<string, mixed>|null $intel
 * @return array<string, mixed>
 */
function dash_r_gps_today(PDO $pdo, string $todayYmd, array $upcomingEvents, ?array $intel): array
{
    $expected = 0;
    foreach ($upcomingEvents as $event) {
        if (substr((string) ($event['event_date'] ?? ''), 0, 10) === $todayYmd) {
            $expected += (int) ($event['approved_count'] ?? 0);
        }
    }

    $checkedIn     = (int) ($intel['checked_in'] ?? 0);
    $gpsFailed     = (int) ($intel['gps_failures'] ?? 0);
    $manualReview  = (int) ($intel['outside_radius'] ?? 0);
    $gpsVerified   = max(0, $checkedIn - $gpsFailed - $manualReview);
    $totalAttempts = max(1, $checkedIn + $gpsFailed);
    $compliancePct = $checkedIn > 0 ? round(($gpsVerified / max(1, $checkedIn)) * 100, 1) : 100.0;

    return [
        'expected'        => $expected,
        'checked_in'      => $checkedIn,
        'gps_verified'    => $gpsVerified,
        'manual_override' => $manualReview,
        'failed_gps'      => $gpsFailed,
        'outside_venue'   => $manualReview,
        'compliance_pct'  => $compliancePct,
        'compliance'      => [
            'success_rate'  => round((($checkedIn - $gpsFailed) / $totalAttempts) * 100, 1),
            'failure_rate'  => round(($gpsFailed / $totalAttempts) * 100, 1),
            'override_rate' => $checkedIn > 0 ? round(($manualReview / $checkedIn) * 100, 1) : 0.0,
            'outside_venue' => $manualReview,
        ],
    ];
}

/** @return array{daily: array, weekly: array, monthly: array} */
function dash_r_attendance_periods(PDO $pdo): array
{
    if (!adminCan('attendance') || !function_exists('dash_attendance_period_metrics') || !function_exists('dash_attendance_status_available')) {
        return [
            'daily'   => dash_r_empty_attendance_period(),
            'weekly'  => dash_r_empty_attendance_period(),
            'monthly' => dash_r_empty_attendance_period(),
        ];
    }

    $statusCol  = dash_attendance_status_available($pdo);
    $today      = date('Y-m-d');
    $weekStart  = date('Y-m-d', strtotime('monday this week'));
    $monthStart = date('Y-m-01');

    return [
        'daily'   => dash_r_attendance_period_row($pdo, $today, $today, $statusCol),
        'weekly'  => dash_r_attendance_period_row($pdo, $weekStart, $today, $statusCol),
        'monthly' => dash_r_attendance_period_row($pdo, $monthStart, $today, $statusCol),
    ];
}

function dash_r_attendance_period_row(PDO $pdo, string $from, string $to, bool $statusCol): array
{
    $m = dash_attendance_period_metrics($pdo, $from, $to, $statusCol);
    $approved = dash_r_safe_count($pdo,
        "SELECT COUNT(*) FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         WHERE sr.status = 'approved' AND e.event_date >= " . $pdo->quote($from) . "
           AND e.event_date <= " . $pdo->quote($to)
    );
    $rate = $approved > 0 ? round(($m['checkins'] / $approved) * 100, 1) : 0.0;

    return [
        'rate'     => $rate,
        'attended' => (int) $m['checkins'],
        'no_show'  => (int) $m['no_show'],
        'late'     => (int) $m['late'],
        'absences' => (int) $m['absences'],
    ];
}

function dash_r_empty_attendance_period(): array
{
    return ['rate' => 0.0, 'attended' => 0, 'no_show' => 0, 'late' => 0, 'absences' => 0];
}

/** @param list<array<string, mixed>> $upcomingEvents @return array<string, int> */
function dash_r_allocation_summary(PDO $pdo, array $stats, array $upcomingEvents, int $waitingList, int $pendingAlloc): array
{
    return [
        'total_allocated'    => array_sum(array_map(static fn (array $e): int => (int) ($e['approved_count'] ?? 0), $upcomingEvents)),
        'waiting_list'       => $waitingList,
        'pending_allocation' => $pendingAlloc,
        'pending_approvals'  => (int) ($stats['pending'] ?? 0),
    ];
}

/** @return array{score: int, tone: string, label: string} */
function dash_r_operations_health_score(
    array $understaffed,
    float $attendanceRate,
    float $gpsCompliance,
    int $pendingRegs,
    array $finance,
    int $unreadMessages
): array {
    $score = 100;
    $score -= min(25, count($understaffed) * 5);
    $score -= min(20, max(0, 90 - (int) $attendanceRate));
    $score -= min(15, max(0, 95 - (int) $gpsCompliance));
    $score -= min(15, (int) ($finance['overdue_count'] ?? 0) * 4);
    $score -= min(10, (int) ($finance['outstanding_count'] ?? 0) * 2);
    $score -= min(10, min(10, (int) ($pendingRegs / 5)));
    $score -= min(5, min(5, (int) ($unreadMessages / 3)));
    $score = max(0, min(100, $score));

    $tone = $score >= 80 ? 'ok' : ($score >= 55 ? 'warn' : 'critical');

    return [
        'score' => $score,
        'tone'  => $tone,
        'label' => match ($tone) {
            'ok'       => 'Healthy',
            'warn'     => 'Warning',
            default    => 'Critical',
        },
    ];
}

/** @return list<array{label: string, score: int, tone: string}> */
function dash_r_health_bars(
    array $understaffed,
    int $upcomingCount,
    float $attendanceRate,
    float $gpsCompliance,
    int $pendingRegs,
    int $failedEmails,
    int $unreadMessages
): array {
    $staffingScore = $upcomingCount > 0
        ? max(0, min(100, (int) round((($upcomingCount - count($understaffed)) / $upcomingCount) * 100)))
        : 100;
    $regScore = max(0, min(100, 100 - min(100, $pendingRegs * 4)));
    $commScore = max(0, min(100, 100 - min(40, $failedEmails * 8) - min(30, $unreadMessages * 3)));

    return [
        ['label' => 'Attendance', 'score' => (int) round($attendanceRate), 'tone' => dash_r_score_tone((int) round($attendanceRate))],
        ['label' => 'GPS compliance', 'score' => (int) round($gpsCompliance), 'tone' => dash_r_score_tone((int) round($gpsCompliance))],
        ['label' => 'Staffing', 'score' => $staffingScore, 'tone' => dash_r_score_tone($staffingScore)],
        ['label' => 'Registrations', 'score' => $regScore, 'tone' => dash_r_score_tone($regScore)],
        ['label' => 'Communications', 'score' => $commScore, 'tone' => dash_r_score_tone($commScore)],
    ];
}

function dash_r_score_tone(int $score): string
{
    if ($score >= 80) {
        return 'ok';
    }
    if ($score >= 55) {
        return 'warn';
    }

    return 'critical';
}

/** @param array<string, mixed> $event */
function dash_r_event_card(array $event, string $todayYmd): array
{
    $needed    = (int) ($event['staff_needed'] ?? 0);
    $allocated = (int) ($event['approved_count'] ?? 0);
    $remaining = max(0, $needed - $allocated);
    $pct       = $needed > 0 ? min(100, (int) round(($allocated / $needed) * 100)) : 100;
    $date      = substr((string) ($event['event_date'] ?? ''), 0, 10);

    $tone = 'ok';
    $status = 'Complete';
    if ($remaining > 0) {
        $tone = $remaining <= 3 ? 'warn' : 'critical';
        $status = 'Understaffed';
    } elseif ($allocated > $needed && $needed > 0) {
        $tone = 'warn';
        $status = 'Overstaffed';
    }

    return [
        'id'         => (int) ($event['id'] ?? 0),
        'name'       => (string) ($event['name'] ?? ''),
        'venue'      => trim((string) ($event['location'] ?? '')) ?: 'Venue TBC',
        'needed'     => $needed,
        'allocated'  => $allocated,
        'remaining'  => $remaining,
        'progress'   => $pct,
        'status'     => $status,
        'status_tone'=> $tone,
        'href'       => 'event-staffing.php?event_id=' . (int) ($event['id'] ?? 0),
        'is_today'   => $date === $todayYmd,
    ];
}

/**
 * @param list<array<string, mixed>> $understaffed
 * @param array<string, mixed> $finance
 * @return list<array<string, string>>
 */
function dash_r_action_required_items(
    array $understaffed,
    array $stats,
    int $awaitingAllocation,
    int $expiringPsa,
    int $failedEmails,
    ?array $intel,
    array $finance
): array {
    $items = [];

    if (adminCan('events')) {
        foreach (array_slice($understaffed, 0, 6) as $event) {
            $gap = (int) ($event['coverage_gap'] ?? 0);
            $items[] = [
                'severity' => 'critical',
                'priority' => 'Critical',
                'title'    => (string) ($event['name'] ?? 'Event') . ' needs ' . $gap . ' staff',
                'detail'   => 'Assign staff before event day',
                'action'   => 'Assign staff',
                'href'     => 'event-staffing.php?event_id=' . (int) ($event['id'] ?? 0),
            ];
        }
    }
    if ($expiringPsa > 0 && adminCan('staff')) {
        $items[] = [
            'severity' => 'warn',
            'priority' => 'Warning',
            'title'    => $expiringPsa . ' PSA licence(s) expiring soon',
            'detail'   => 'Review compliance before shifts',
            'action'   => 'Review compliance',
            'href'     => 'compliance-centre.php',
        ];
    }
    if ($intel !== null && adminCan('attendance')) {
        $issues = (int) ($intel['exceptions'] ?? 0) + (int) ($intel['gps_issues'] ?? 0);
        if ($issues > 0) {
            $items[] = [
                'severity' => 'critical',
                'priority' => 'Critical',
                'title'    => $issues . ' attendance issue(s) today',
                'detail'   => 'Late arrivals, no-shows, or GPS exceptions',
                'action'   => 'Open attendance',
                'href'     => 'attendance.php',
            ];
        }
    }
    if ($failedEmails > 0 && adminCan('staff')) {
        $items[] = [
            'severity' => 'warn',
            'priority' => 'Warning',
            'title'    => $failedEmails . ' failed email delivery(s)',
            'detail'   => 'Resend or update contact details',
            'action'   => 'Open comms hub',
            'href'     => 'communication-hub.php',
        ];
    }
    if (adminCan('staff') && (int) ($stats['pending'] ?? 0) > 0) {
        $items[] = [
            'severity' => 'warn',
            'priority' => 'Warning',
            'title'    => (int) $stats['pending'] . ' registration(s) pending approval',
            'detail'   => 'Review new applicants',
            'action'   => 'Review queue',
            'href'     => 'staff.php?status=pending&page=1',
        ];
    }
    if ($awaitingAllocation > 0 && adminCan('staff')) {
        $items[] = [
            'severity' => 'warn',
            'priority' => 'Warning',
            'title'    => $awaitingAllocation . ' staff awaiting allocation',
            'detail'   => 'Move staff to open shifts',
            'action'   => 'Allocate staff',
            'href'     => 'allocation-centre.php',
        ];
    }
    if ((int) ($finance['overdue_count'] ?? 0) > 0 && adminCan('invoices')) {
        $items[] = [
            'severity' => 'critical',
            'priority' => 'Critical',
            'title'    => (int) $finance['overdue_count'] . ' overdue invoice(s)',
            'detail'   => 'Follow up on outstanding payments',
            'action'   => 'Open invoices',
            'href'     => 'invoices.php?status=sent',
        ];
    }

    return $items;
}

/** @param array<string, mixed> $ctx @return list<array<string, string>> */
function dash_r_recent_activity(PDO $pdo, array $ctx): array
{
    $items    = [];
    $upcoming = is_array($ctx['upcomingEvents'] ?? null) ? $ctx['upcomingEvents'] : [];
    $auditPool = is_array($ctx['auditPool'] ?? null) ? $ctx['auditPool'] : [];

    foreach ($auditPool as $entry) {
        $human = dash_r_humanize_audit_entry($pdo, $entry, $upcoming);
        if ($human === null) {
            continue;
        }
        $items[] = $human;
        if (count($items) >= 10) {
            return $items;
        }
    }

    foreach (dash_r_allocation_activity_rows($pdo) as $row) {
        $items[] = $row;
        if (count($items) >= 10) {
            return $items;
        }
    }

    return array_slice($items, 0, 10);
}

/** @param list<array<string, mixed>> $events */
function dash_r_humanize_audit_entry(PDO $pdo, array $entry, array $events): ?array
{
    if (!function_exists('dash_format_activity_row')) {
        return null;
    }

    $row = dash_format_activity_row($entry, $events);
    if ($row === null) {
        return null;
    }

    $action = (string) ($entry['action'] ?? '');
    $details = trim((string) ($entry['details'] ?? ''));
    $ts = strtotime((string) ($entry['created_at'] ?? ''));
    $time = $ts ? date('j M H:i', $ts) : '—';
    $kind = dash_r_activity_kind($action, $details);

    if ($action === 'shift_allocation') {
        $parsed = dash_r_parse_json_details($details);
        if ($parsed !== null) {
            return [
                'time'    => $time,
                'message' => dash_r_format_shift_allocation_message($pdo, $parsed, $events),
                'kind'    => 'allocation',
            ];
        }
    }

    $message = dash_r_build_activity_sentence(
        (string) ($row['action'] ?? 'Activity'),
        (string) ($row['context'] ?? ''),
        $details
    );

    if (dash_r_looks_technical($message) || preg_match('/Registration #\d+/i', $message)) {
        $message = dash_r_fallback_activity_message($action, $row, $events);
    }

    return ['time' => $time, 'message' => $message, 'kind' => $kind];
}

/** @return list<array<string, string>> */
function dash_r_allocation_activity_rows(PDO $pdo): array
{
    if (!staffAllocationTableExists($pdo, 'staff_shift_assignment_log')) {
        return [];
    }

    try {
        $rows = $pdo->query(
            'SELECT l.created_at, l.action, l.email, l.details, l.staff_id,
                    fe.name AS from_event_name, te.name AS to_event_name,
                    s.first_name, s.surname
             FROM staff_shift_assignment_log l
             LEFT JOIN events fe ON fe.id = l.from_event_id
             LEFT JOIN events te ON te.id = l.to_event_id
             LEFT JOIN staff s ON s.id = l.staff_id
             ORDER BY l.created_at DESC
             LIMIT 8'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $items = [];
    foreach ($rows as $row) {
        $ts = strtotime((string) ($row['created_at'] ?? ''));
        $name = trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['surname'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($row['email'] ?? '')) ?: 'Staff member';
        }

        $from = trim((string) ($row['from_event_name'] ?? ''));
        $to   = trim((string) ($row['to_event_name'] ?? ''));
        $act  = strtolower(trim((string) ($row['action'] ?? 'assign')));

        if ($act === 'move' && $from !== '' && $to !== '') {
            $message = $name . ' moved from ' . $from . ' to ' . $to;
        } elseif ($to !== '') {
            $message = $name . ' assigned to ' . $to;
        } elseif ($from !== '') {
            $message = $name . ' removed from ' . $from;
        } else {
            $message = $name . ' allocation updated';
        }

        $items[] = [
            'time'    => $ts ? date('j M H:i', $ts) : '—',
            'message' => $message,
            'kind'    => 'allocation',
        ];
    }

    return $items;
}

function dash_r_activity_kind(string $action, string $details): string
{
    $hay = strtolower($action . ' ' . $details);
    if (str_contains($hay, 'check-in') || str_contains($hay, 'attendance') || str_contains($hay, 'gps')) {
        return 'gps';
    }
    if (str_contains($hay, 'approved') || str_contains($hay, 'registration') || str_contains($hay, 'status_change')) {
        return 'registration';
    }
    if (str_contains($hay, 'alloc') || str_contains($hay, 'assign') || str_contains($hay, 'shift')) {
        return 'allocation';
    }
    if (str_contains($hay, 'invoice') || str_contains($hay, 'finance')) {
        return 'invoice';
    }
    if (str_contains($hay, 'message') || str_contains($hay, 'email') || str_contains($hay, 'broadcast')) {
        return 'message';
    }
    if (str_contains($hay, 'event')) {
        return 'event';
    }

    return 'update';
}

function dash_r_build_activity_sentence(string $label, string $context, string $details): string
{
    if (dash_r_looks_technical($details)) {
        $details = dash_r_clean_technical_detail($details, $label);
    }
    if (dash_r_looks_technical($context)) {
        $context = '';
    }

    $parts = [$label];
    if ($context !== '' && $context !== '—' && !preg_match('/^Registration #\d+$/i', $context)) {
        $parts[] = $context;
    }
    if ($details !== '' && $details !== '—') {
        $parts[] = $details;
    }

    return implode(' · ', $parts);
}

function dash_r_clean_technical_detail(string $details, string $label): string
{
    $parsed = dash_r_parse_json_details($details);
    if ($parsed !== null) {
        return '';
    }
    if (preg_match('/Registration #\d+/i', $details)) {
        return trim(preg_replace('/\s*[-–—·|]\s*Registration #\d+/i', '', $details)) ?: $label;
    }

    return '';
}

/** @param list<array<string, mixed>> $events */
function dash_r_fallback_activity_message(string $action, array $row, array $events): string
{
    return match ($action) {
        'status_change'      => stripos((string) ($row['subject'] ?? ''), 'approved') !== false
            ? 'Admin approved a staff registration'
            : (stripos((string) ($row['subject'] ?? ''), 'rejected') !== false
                ? 'Admin rejected a staff registration'
                : 'Registration status updated'),
        'admin_checkin', 'scan_checkin' => 'Staff check-in recorded',
        'staff_email'        => 'Attendance reminder sent',
        'open_shift_broadcast' => 'Shift broadcast sent to staff',
        'shift_allocation'   => 'Staff allocation updated',
        'event_save'         => stripos((string) ($row['subject'] ?? ''), 'Created') !== false
            ? 'New event created'
            : 'Event details updated',
        'work_hours_update'  => 'Attendance record updated',
        default              => (string) ($row['action'] ?? 'System activity'),
    };
}

/** @param list<array<string, mixed>> $events */
function dash_r_format_shift_allocation_message(PDO $pdo, array $data, array $events): string
{
    $name = dash_r_staff_display_name($pdo, isset($data['staff_id']) ? (int) $data['staff_id'] : null, (string) ($data['email'] ?? ''));
    $from = dash_r_event_name_from_list($events, (int) ($data['from_event_id'] ?? 0));
    $to   = dash_r_event_name_from_list($events, (int) ($data['to_event_id'] ?? 0));
    $act  = strtolower((string) ($data['action'] ?? 'move'));

    if ($act === 'move' && $from !== '' && $to !== '') {
        return $name . ' moved from ' . $from . ' to ' . $to;
    }
    if ($to !== '') {
        return $name . ' assigned to ' . $to;
    }

    return $name . ' allocation updated';
}

function dash_r_staff_display_name(PDO $pdo, ?int $staffId, string $email): string
{
    if ($staffId !== null && $staffId > 0 && function_exists('getStaffById')) {
        $staff = getStaffById($pdo, $staffId);
        if (is_array($staff)) {
            $name = trim(trim((string) ($staff['first_name'] ?? '')) . ' ' . trim((string) ($staff['surname'] ?? '')));
            if ($name !== '') {
                return $name;
            }
        }
    }

    $email = trim($email);

    return $email !== '' ? $email : 'Staff member';
}

/** @param list<array<string, mixed>> $events */
function dash_r_event_name_from_list(array $events, int $eventId): string
{
    if ($eventId < 1) {
        return '';
    }
    if (function_exists('dash_event_name_from_list')) {
        return dash_event_name_from_list($events, $eventId);
    }
    foreach ($events as $event) {
        if ((int) ($event['id'] ?? 0) === $eventId) {
            return (string) ($event['name'] ?? '');
        }
    }

    return '';
}

/** @return array<string, mixed>|null */
function dash_r_parse_json_details(string $details): ?array
{
    $trim = trim($details);
    if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
        return null;
    }
    $decoded = json_decode($trim, true);

    return is_array($decoded) ? $decoded : null;
}

function dash_r_looks_technical(string $text): bool
{
    $trim = trim($text);
    if ($trim === '') {
        return false;
    }
    if ($trim[0] === '{' || $trim[0] === '[') {
        return true;
    }
    if (preg_match('/staff_id|registration_id|event_id|"action"\s*:/', $trim)) {
        return true;
    }

    return false;
}

function dash_r_ring_color(string $tone): string
{
    return match ($tone) {
        'ok'       => '#34d399',
        'warn'     => '#fbbf24',
        'critical' => '#f87171',
        default    => '#818cf8',
    };
}

function dash_r_format_pct(float $value): string
{
    return number_format($value, 1) . '%';
}
