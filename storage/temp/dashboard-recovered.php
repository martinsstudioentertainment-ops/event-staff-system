<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/staff-messages.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/admin/nav-icons.php';
require_once __DIR__ . '/../includes/notification-center.php';
require_once __DIR__ . '/../includes/admin/system-health.php';
require_once __DIR__ . '/../includes/feature-flags.php';
require_once __DIR__ . '/../includes/attendance-gps-phase1.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/registration-analytics.php';
require_once __DIR__ . '/../includes/pwa-install-analytics.php';
require_once __DIR__ . '/../includes/production-readiness.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/job-record-repository.php';
require_once __DIR__ . '/../includes/platform/payroll-intelligence.php';

requireAdmin();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'toggle_gps_attendance'
    && verifyCsrf($_POST['csrf_token'] ?? null)
) {
    if (!isAdminSuperUser()) {
        setAdminFlash('error', 'Only administrators can change GPS sign-in.');
        header('Location: dashboard.php');
        exit;
    }

    $enable = !empty($_POST['enabled']) && (string) $_POST['enabled'] === '1';
    setSetting($pdo, 'feature_gps_attendance_v2', $enable ? '1' : '0');
    logAdminAudit(
        $pdo,
        'feature_flags_update',
        'settings',
        null,
        'GPS attendance v2 ' . ($enable ? 'enabled' : 'disabled') . ' from dashboard'
    );
    setAdminFlash(
        'success',
        $enable
            ? 'GPS sign-in ON — 1 km geofence and GPS enforcement active.'
            : 'GPS sign-in OFF — legacy 100 m check-in mode.'
    );
    header('Location: dashboard.php#dash-gps-toggle');
    exit;
}

$stats     = getDashboardStats($pdo);
$flash     = getAdminFlash();
$adminUser = getAdminUser();
$health    = adminCan('settings') ? summarizeSystemHealth($pdo) : null;
$gpsFlagOn = isGpsAttendanceV2Enabled($pdo);
$gpsCanToggle = isAdminSuperUser();
$funnel    = adminCan('dashboard') ? getRegistrationFunnelMetrics($pdo) : null;

$messageThreads = adminCan('staff') ? listStaffInboxThreads($pdo, 5) : [];
$messageUnread  = adminCan('staff') ? countUnreadStaffMessagesForAdmin($pdo) : 0;
$notifUnread    = adminCan('dashboard') ? countUnreadAdminNotifications($pdo) : 0;
$recentNotifs   = adminCan('dashboard') ? getAdminNotifications($pdo, 5) : [];
$upcomingEvents = adminCan('events') ? getUpcomingEventsSummary($pdo, 8) : [];
$auditPool      = adminCan('audit') ? getAuditLogEntries($pdo, 50) : [];

$firstName = explode(' ', trim($adminUser['name'] ?? 'Admin'))[0];
$hour      = (int) date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$todayYmd  = date('Y-m-d');

$upcomingCount      = count($upcomingEvents);
$activeTodayCount   = count(array_filter(
    $upcomingEvents,
    static fn (array $e): bool => substr((string) ($e['event_date'] ?? ''), 0, 10) === $todayYmd
));
$staffGapTotal      = array_sum(array_map(static fn (array $e): int => (int) ($e['coverage_gap'] ?? 0), $upcomingEvents));
$staffNeededTotal   = array_sum(array_map(static fn (array $e): int => (int) ($e['staff_needed'] ?? 0), $upcomingEvents));
$healthScore        = $health !== null ? (int) ($health['score'] ?? 0) : null;
$understaffedEvents = array_values(array_filter(
    $upcomingEvents,
    static fn (array $e): bool => (int) ($e['coverage_gap'] ?? 0) > 0
));
$staffedEventsCount = max(0, $upcomingCount - count($understaffedEvents));
$approvedOnEvents   = array_sum(array_map(static fn (array $e): int => (int) ($e['approved_count'] ?? 0), $upcomingEvents));
$attendanceRate     = $staffNeededTotal > 0
    ? min(100, (int) round(((int) $stats['today_checkins'] / $staffNeededTotal) * 100))
    : 0;
$attendanceIntel    = adminCan('attendance')
    ? dash_today_attendance_intel($pdo, (int) $stats['today_checkins'])
    : null;
$healthCheckedAt    = $health !== null ? date('H:i') : null;
$financeSnap        = adminCan('invoices') ? dash_get_finance_snapshot($pdo, $todayYmd) : null;
$eventBillingMap    = adminCan('invoices') && adminCan('events')
    ? dash_event_billing_map($pdo, array_column($upcomingEvents, 'id'))
    : [];
$workforce          = adminCan('attendance')
    ? dash_get_workforce_analytics($pdo, $todayYmd, $upcomingEvents)
    : null;
if ($workforce !== null && isset($workforce['exec']['attendance_rate'])) {
    $attendanceRate = (int) $workforce['exec']['attendance_rate'];
}

function dash_event_status(array $event, string $todayYmd): array
{
    $gap  = (int) ($event['coverage_gap'] ?? 0);
    $date = substr((string) ($event['event_date'] ?? ''), 0, 10);
    if ($date === $todayYmd) {
        return $gap > 0
            ? [
                'label' => 'Check-in active',
                'class' => 'active-warn',
                'tip'   => 'Event is today — check-ins open but staffing gap remains',
            ]
            : [
                'label' => 'In progress',
                'class' => 'active',
                'tip'   => 'Event is today and fully staffed — monitor attendance',
            ];
    }
    if ($gap > 0) {
        return [
            'label' => 'Understaffed',
            'class' => 'urgent',
            'tip'   => 'Approved staff count is below required headcount',
        ];
    }

    return [
        'label' => 'Fully staffed',
        'class' => 'ok',
        'tip'   => 'Required staff count is met for this event',
    ];
}

/** @return array{checked_in: int, checked_out: int, late: int, exceptions: int, gps_failures: int, outside_radius: int, gps_issues: int, has_activity: bool} */
function dash_today_attendance_intel(PDO $pdo, int $fallbackCheckins = 0): array
{
    $intel = [
        'checked_in'     => $fallbackCheckins,
        'checked_out'    => 0,
        'late'           => 0,
        'exceptions'     => 0,
        'gps_failures'   => 0,
        'outside_radius' => 0,
        'gps_issues'     => 0,
        'has_activity'   => $fallbackCheckins > 0,
    ];

    $safeCount = static function (string $sql) use ($pdo): ?int {
        try {
            return (int) $pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
    };

    $checkedIn = $safeCount("SELECT COUNT(*) FROM attendance WHERE DATE(checked_in_at) = CURDATE()");
    if ($checkedIn !== null) {
        $intel['checked_in'] = $checkedIn;
    }

    $checkedOut = $safeCount(
        "SELECT COUNT(*) FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE DATE(e.event_date) = CURDATE()
           AND a.checked_in_at IS NOT NULL
           AND TIMESTAMP(e.event_date, COALESCE(NULLIF(e.end_time, ''), '23:59:59')) < NOW()"
    );
    if ($checkedOut !== null) {
        $intel['checked_out'] = $checkedOut;
    }

    $late = $safeCount(
        "SELECT COUNT(*) FROM attendance
         WHERE DATE(checked_in_at) = CURDATE() AND attendance_status = 'late'"
    );
    if ($late !== null) {
        $intel['late'] = $late;
    }

    $gpsFailures = $safeCount(
        "SELECT COUNT(*) FROM attendance
         WHERE DATE(checked_in_at) = CURDATE() AND attendance_status = 'gps_failed'"
    );
    if ($gpsFailures !== null) {
        $intel['gps_failures'] = $gpsFailures;
    }

    $outsideRadius = $safeCount(
        "SELECT COUNT(*) FROM attendance
         WHERE DATE(checked_in_at) = CURDATE() AND attendance_status = 'manual_review'"
    );
    if ($outsideRadius !== null) {
        $intel['outside_radius'] = $outsideRadius;
    }

    $exceptions = $safeCount(
        "SELECT COUNT(*) FROM attendance
         WHERE DATE(checked_in_at) = CURDATE()
           AND attendance_status IN ('no_show', 'late', 'manual_review', 'gps_failed')"
    );
    if ($exceptions !== null) {
        $intel['exceptions'] = $exceptions;
    }

    $intel['gps_issues']   = $intel['gps_failures'] + $intel['outside_radius'];
    $intel['has_activity'] = ($intel['checked_in'] + $intel['checked_out'] + $intel['late']
        + $intel['exceptions'] + $intel['gps_issues']) > 0;

    return $intel;
}

/** @return array<string, float|int> */
function dash_finance_aggregate_source(PDO $pdo, string $table, string $monthStart, string $monthEnd, string $weekStart): array
{
    $empty = [
        'invoice_count'      => 0,
        'total_value'        => 0.0,
        'paid_amount'        => 0.0,
        'paid_count'         => 0,
        'outstanding_amount' => 0.0,
        'outstanding_count'  => 0,
        'draft_amount'       => 0.0,
        'draft_count'        => 0,
        'revenue_month'      => 0.0,
        'revenue_week'       => 0.0,
        'missing_ref_count'  => 0,
    ];

    $jobFilter = $table === 'saved_job_records' ? " AND record_kind = 'invoice'" : '';

    try {
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(total_amount), 0) AS total_value,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) AS paid_amount,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) AS paid_count,
                COALESCE(SUM(CASE WHEN status = 'sent' THEN total_amount ELSE 0 END), 0) AS outstanding_amount,
                COUNT(CASE WHEN status = 'sent' THEN 1 END) AS outstanding_count,
                COALESCE(SUM(CASE WHEN status = 'draft' THEN total_amount ELSE 0 END), 0) AS draft_amount,
                COUNT(CASE WHEN status = 'draft' THEN 1 END) AS draft_count,
                COALESCE(SUM(CASE WHEN status = 'paid' AND invoice_date >= :month_start AND invoice_date <= :month_end THEN total_amount ELSE 0 END), 0) AS revenue_month,
                COALESCE(SUM(CASE WHEN status = 'paid' AND invoice_date >= :week_start THEN total_amount ELSE 0 END), 0) AS revenue_week,
                COUNT(CASE WHEN status <> 'void' AND (invoice_number IS NULL OR TRIM(invoice_number) = '') THEN 1 END) AS missing_ref_count
             FROM {$table}
             WHERE status <> 'void'{$jobFilter}"
        );
        $stmt->execute([
            'month_start' => $monthStart,
            'month_end'   => $monthEnd,
            'week_start'  => $weekStart,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ($empty as $key => $default) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $empty[$key] = str_contains($key, 'count') ? (int) $row[$key] : round((float) $row[$key], 2);
        }
    } catch (Throwable $e) {
        return $empty;
    }

    return $empty;
}

/** @return array<string, mixed> */
function dash_get_finance_snapshot(PDO $pdo, string $todayYmd): array
{
    $snap = [
        'available'           => false,
        'has_data'            => false,
        'revenue_month'       => 0.0,
        'revenue_week'        => 0.0,
        'outstanding_count'   => 0,
        'outstanding_amount'  => 0.0,
        'paid_count'          => 0,
        'paid_amount'         => 0.0,
        'overdue_count'       => 0,
        'overdue_amount'      => 0.0,
        'draft_count'         => 0,
        'draft_amount'        => 0.0,
        'total_invoice_value' => 0.0,
        'invoice_count'       => 0,
        'missing_ref_count'   => 0,
        'events_not_invoiced' => 0,
        'payroll_alerts'      => 0,
        'collection_rate'     => 0,
        'health_paid_pct'     => 0,
        'health_outstanding_pct' => 0,
        'health_overdue_pct'  => 0,
    ];

    if (!tableExists($pdo, 'commission_invoices')) {
        return $snap;
    }

    $snap['available'] = true;
    $monthStart        = date('Y-m-01');
    $monthEnd          = date('Y-m-t');
    $weekStart         = date('Y-m-d', strtotime('monday this week'));

    $merged = dash_finance_aggregate_source($pdo, 'commission_invoices', $monthStart, $monthEnd, $weekStart);
    if (tableExists($pdo, 'saved_job_records')) {
        $job = dash_finance_aggregate_source($pdo, 'saved_job_records', $monthStart, $monthEnd, $weekStart);
        foreach ($job as $key => $val) {
            if (str_contains($key, 'count') || $key === 'missing_ref_count') {
                $merged[$key] = (int) ($merged[$key] ?? 0) + (int) $val;
            } else {
                $merged[$key] = round((float) ($merged[$key] ?? 0) + (float) $val, 2);
            }
        }
    }

    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) AS overdue_count, COALESCE(SUM(ci.total_amount), 0) AS overdue_amount
             FROM commission_invoices ci
             INNER JOIN events e ON e.id = ci.event_id
             WHERE ci.status = 'sent' AND e.event_date < CURDATE()"
        );
        $overdue = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $snap['overdue_count']  = (int) ($overdue['overdue_count'] ?? 0);
        $snap['overdue_amount'] = round((float) ($overdue['overdue_amount'] ?? 0), 2);
    } catch (Throwable $e) {
        // optional join
    }

    try {
        $snap['events_not_invoiced'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM events e
             LEFT JOIN commission_invoices ci ON ci.event_id = e.id AND ci.status <> 'void'
             WHERE e.event_date < CURDATE() AND ci.id IS NULL"
        )->fetchColumn();
    } catch (Throwable $e) {
        $snap['events_not_invoiced'] = 0;
    }

    $snap['payroll_alerts'] = countPayrollAlerts($pdo, true);

    foreach ([
        'revenue_month'      => 'revenue_month',
        'revenue_week'       => 'revenue_week',
        'outstanding_count'  => 'outstanding_count',
        'outstanding_amount' => 'outstanding_amount',
        'paid_count'         => 'paid_count',
        'paid_amount'        => 'paid_amount',
        'draft_count'        => 'draft_count',
        'draft_amount'       => 'draft_amount',
        'total_invoice_value'=> 'total_value',
        'invoice_count'      => 'invoice_count',
        'missing_ref_count'  => 'missing_ref_count',
    ] as $snapKey => $mergeKey) {
        $snap[$snapKey] = $merged[$mergeKey] ?? ($snapKey === 'invoice_count' || str_contains($snapKey, 'count') ? 0 : 0.0);
    }

    $snap['has_data'] = $snap['invoice_count'] > 0
        || $snap['events_not_invoiced'] > 0
        || $snap['payroll_alerts'] > 0;

    $collectable = $snap['paid_amount'] + $snap['outstanding_amount'];
    $snap['collection_rate'] = $collectable > 0
        ? (int) round(($snap['paid_amount'] / $collectable) * 100)
        : ($snap['paid_count'] > 0 ? 100 : 0);

    $healthTotal = max(0.01, $snap['paid_amount'] + max(0, $snap['outstanding_amount'] - $snap['overdue_amount']) + $snap['overdue_amount']);
    $outstandingOnly = max(0, $snap['outstanding_amount'] - $snap['overdue_amount']);
    $snap['health_paid_pct']        = (int) round(($snap['paid_amount'] / $healthTotal) * 100);
    $snap['health_outstanding_pct'] = (int) round(($outstandingOnly / $healthTotal) * 100);
    $snap['health_overdue_pct']     = (int) round(($snap['overdue_amount'] / $healthTotal) * 100);

    return $snap;
}

/** @param array<int, int|string> $eventIds @return array<int, array<string, mixed>> */
function dash_event_billing_map(PDO $pdo, array $eventIds): array
{
    $eventIds = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $eventIds)));
    if ($eventIds === [] || !tableExists($pdo, 'commission_invoices')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    try {
        $stmt = $pdo->prepare(
            "SELECT event_id, status, total_amount, invoice_number
             FROM commission_invoices
             WHERE event_id IN ({$placeholders}) AND status <> 'void'"
        );
        $stmt->execute($eventIds);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) ($row['event_id'] ?? 0)] = $row;
        }

        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

/** @param array<string, mixed>|null $invoice @return array{label: string, class: string, tip: string} */
function dash_event_billing_status(?array $invoice, string $eventDate, string $todayYmd): array
{
    if ($invoice === null) {
        $past = $eventDate !== '' && $eventDate < $todayYmd;

        return [
            'label' => 'Not invoiced',
            'class' => $past ? 'urgent' : 'muted',
            'tip'   => $past ? 'Completed event without a commission invoice' : 'No invoice created for this event yet',
        ];
    }

    $status = (string) ($invoice['status'] ?? 'draft');

    return match ($status) {
        'paid'  => ['label' => 'Paid', 'class' => 'ok', 'tip' => 'Commission invoice marked paid'],
        'sent'  => ['label' => 'Outstanding', 'class' => 'urgent', 'tip' => 'Invoice sent — payment not yet recorded'],
        'draft' => ['label' => 'Invoiced', 'class' => 'active', 'tip' => 'Draft invoice saved — not yet sent'],
        default => ['label' => ucfirst($status), 'class' => 'muted', 'tip' => 'Invoice status: ' . $status],
    };
}

function dash_format_finance_amount(PDO $pdo, float $amount): string
{
    return formatSystemCurrencyAmount($amount, $pdo);
}

function dash_format_finance_kpi(PDO $pdo, float $amount): string
{
    return formatSystemCurrencyAmount($amount, $pdo);
}

function dash_attendance_status_available(PDO $pdo): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $cols      = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
        $available = in_array('attendance_status', $cols, true);
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

/** @return array{checkins: int, late: int, no_show: int, gps_failed: int, manual_review: int, outside_radius: int, absences: int} */
function dash_attendance_period_metrics(PDO $pdo, string $dateFrom, string $dateTo, bool $statusCol): array
{
    $metrics = [
        'checkins'       => 0,
        'late'           => 0,
        'no_show'        => 0,
        'gps_failed'     => 0,
        'manual_review'  => 0,
        'outside_radius' => 0,
        'absences'       => 0,
    ];

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM attendance WHERE DATE(checked_in_at) >= :from_date AND DATE(checked_in_at) <= :to_date'
        );
        $stmt->execute(['from_date' => $dateFrom, 'to_date' => $dateTo]);
        $metrics['checkins'] = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $metrics;
    }

    if ($statusCol) {
        try {
            $stmt = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN attendance_status = 'late' THEN 1 ELSE 0 END) AS late_cnt,
                    SUM(CASE WHEN attendance_status = 'no_show' THEN 1 ELSE 0 END) AS no_show_cnt,
                    SUM(CASE WHEN attendance_status = 'gps_failed' THEN 1 ELSE 0 END) AS gps_failed_cnt,
                    SUM(CASE WHEN attendance_status = 'manual_review' THEN 1 ELSE 0 END) AS manual_review_cnt
                 FROM attendance
                 WHERE DATE(checked_in_at) >= :from_date AND DATE(checked_in_at) <= :to_date"
            );
            $stmt->execute(['from_date' => $dateFrom, 'to_date' => $dateTo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $metrics['late']           = (int) ($row['late_cnt'] ?? 0);
            $metrics['no_show']         = (int) ($row['no_show_cnt'] ?? 0);
            $metrics['gps_failed']      = (int) ($row['gps_failed_cnt'] ?? 0);
            $metrics['manual_review']   = (int) ($row['manual_review_cnt'] ?? 0);
            $metrics['outside_radius'] = (int) ($row['manual_review_cnt'] ?? 0);
        } catch (Throwable $e) {
            // optional status column values
        }
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE sr.status = 'approved'
               AND e.event_date >= :from_date AND e.event_date <= :to_date
               AND e.event_date < CURDATE()
               AND a.id IS NULL"
        );
        $stmt->execute(['from_date' => $dateFrom, 'to_date' => $dateTo]);
        $metrics['absences'] = (int) $stmt->fetchColumn() + $metrics['no_show'];
    } catch (Throwable $e) {
        $metrics['absences'] = $metrics['no_show'];
    }

    return $metrics;
}

/** @param array<string, int|float> $row @return array{score: int, label: string, attendance_pct: int, completion_pct: int, gps_pct: int, punctuality_pct: int} */
function dash_compute_reliability_score(array $row): array
{
    $approved   = max(0, (int) ($row['approved'] ?? 0));
    $checkins   = max(0, (int) ($row['checkins'] ?? 0));
    $late       = max(0, (int) ($row['late_cnt'] ?? 0));
    $gpsIssues  = max(0, (int) ($row['gps_cnt'] ?? 0));
    $completed  = max(0, (int) ($row['completed_cnt'] ?? 0));

    $attendancePct   = $approved > 0 ? min(100, (int) round(($checkins / $approved) * 100)) : 0;
    $completionPct   = $checkins > 0 ? min(100, (int) round(($completed / $checkins) * 100)) : ($checkins > 0 ? 100 : 0);
    $gpsPct          = $checkins > 0 ? min(100, (int) round((($checkins - $gpsIssues) / $checkins) * 100)) : 100;
    $punctualityPct  = $checkins > 0 ? min(100, (int) round((($checkins - $late) / $checkins) * 100)) : 0;

    $score = (int) round(
        ($attendancePct * 0.30)
        + ($completionPct * 0.25)
        + ($gpsPct * 0.25)
        + ($punctualityPct * 0.20)
    );
    $score = max(0, min(100, $score));

    return [
        'score'            => $score,
        'label'            => dash_reliability_label($score),
        'attendance_pct'   => $attendancePct,
        'completion_pct'   => $completionPct,
        'gps_pct'          => $gpsPct,
        'punctuality_pct'  => $punctualityPct,
    ];
}

function dash_reliability_label(int $score): string
{
    return match (true) {
        $score >= 85 => 'Excellent',
        $score >= 70 => 'Good',
        $score >= 50 => 'Average',
        default      => 'Needs attention',
    };
}

function dash_staff_display_from_row(array $row): string
{
    $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['surname'] ?? '')));

    return $name !== '' ? $name : (string) ($row['email'] ?? 'Staff');
}

/** @param array<int, array<string, mixed>> $upcomingEvents @return array<string, mixed> */
function dash_get_workforce_analytics(PDO $pdo, string $todayYmd, array $upcomingEvents): array
{
    $snap = [
        'available'        => false,
        'has_data'         => false,
        'status_col'       => false,
        'exec'             => [],
        'trend'            => [],
        'event_attendance' => [],
        'workforce_health' => [
            'reliable' => [],
            'late'     => [],
            'missed'   => [],
            'gps'      => [],
        ],
        'leaderboard'      => [],
        'risk_events'      => [],
        'alert_meta'       => [
            'repeat_late'    => 0,
            'repeat_absence' => 0,
            'repeat_gps'     => 0,
            'low_attendance' => 0,
            'high_risk'      => 0,
        ],
    ];

    try {
        $pdo->query('SELECT 1 FROM attendance LIMIT 1');
        $snap['available'] = true;
    } catch (Throwable $e) {
        return $snap;
    }

    $statusCol            = dash_attendance_status_available($pdo);
    $snap['status_col']   = $statusCol;
    $monthStart           = date('Y-m-01');
    $monthEnd             = date('Y-m-t');
    $weekStart            = date('Y-m-d', strtotime('-6 days'));
    $monthAgo             = date('Y-m-d', strtotime('-29 days'));

    $todayMetrics = dash_attendance_period_metrics($pdo, $todayYmd, $todayYmd, $statusCol);
    $weekMetrics  = dash_attendance_period_metrics($pdo, $weekStart, $todayYmd, $statusCol);
    $monthMetrics = dash_attendance_period_metrics($pdo, $monthAgo, $todayYmd, $statusCol);

    $approvedToday = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE sr.status = 'approved' AND DATE(e.event_date) = :today"
        );
        $stmt->execute(['today' => $todayYmd]);
        $approvedToday = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $approvedToday = 0;
    }

    $checkedInToday = $todayMetrics['checkins'];
    $attendanceRate = $approvedToday > 0
        ? min(100, (int) round(($checkedInToday / $approvedToday) * 100))
        : ($monthMetrics['checkins'] + $monthMetrics['absences'] > 0
            ? min(100, (int) round(($monthMetrics['checkins'] / max(1, $monthMetrics['checkins'] + $monthMetrics['absences'])) * 100))
            : 0);

    $snap['exec'] = [
        'attendance_rate'  => $attendanceRate,
        'late'             => $todayMetrics['late'],
        'no_shows'         => $todayMetrics['no_show'] + ($approvedToday > $checkedInToday ? $approvedToday - $checkedInToday : 0),
        'gps_exceptions'   => $todayMetrics['gps_failed'],
        'outside_radius'   => $todayMetrics['outside_radius'],
        'manual_reviews'   => $todayMetrics['manual_review'],
        'checked_in'       => $checkedInToday,
        'approved_today'   => $approvedToday,
    ];

    $snap['trend'] = [
        'today' => [
            'label'    => 'Today',
            'checkins' => $todayMetrics['checkins'],
            'late'     => $todayMetrics['late'],
            'absences' => max($todayMetrics['absences'], max(0, $approvedToday - $checkedInToday)),
        ],
        '7d' => [
            'label'    => 'Last 7 days',
            'checkins' => $weekMetrics['checkins'],
            'late'     => $weekMetrics['late'],
            'absences' => $weekMetrics['absences'],
        ],
        '30d' => [
            'label'    => 'Last 30 days',
            'checkins' => $monthMetrics['checkins'],
            'late'     => $monthMetrics['late'],
            'absences' => $monthMetrics['absences'],
        ],
    ];

    $eventIds = array_values(array_filter(array_map(static fn (array $e): int => (int) ($e['id'] ?? 0), $upcomingEvents)));
    if ($eventIds !== []) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $statusSql    = $statusCol
            ? ", SUM(CASE WHEN a.attendance_status = 'late' THEN 1 ELSE 0 END) AS late_cnt"
            : ', 0 AS late_cnt';
        try {
            $stmt = $pdo->prepare(
                "SELECT sr.event_id,
                        COUNT(*) AS approved,
                        SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS checked_in,
                        SUM(CASE WHEN a.id IS NULL AND e.event_date <= CURDATE() THEN 1 ELSE 0 END) AS absent_cnt
                        {$statusSql}
                 FROM staff_registrations sr
                 INNER JOIN events e ON e.id = sr.event_id
                 LEFT JOIN attendance a ON a.registration_id = sr.id
                 WHERE sr.status = 'approved' AND sr.event_id IN ({$placeholders})
                 GROUP BY sr.event_id"
            );
            $stmt->execute($eventIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $eid = (int) ($row['event_id'] ?? 0);
                $approved = (int) ($row['approved'] ?? 0);
                $checkedIn = (int) ($row['checked_in'] ?? 0);
                $absent = (int) ($row['absent_cnt'] ?? 0);
                $late = (int) ($row['late_cnt'] ?? 0);
                $snap['event_attendance'][$eid] = [
                    'approved'   => $approved,
                    'checked_in' => $checkedIn,
                    'absent'     => $absent,
                    'late'       => $late,
                ];
            }
        } catch (Throwable $e) {
            // optional
        }
    }

    $staffRows = [];
    $hoursCol  = false;
    try {
        $cols     = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
        $hoursCol = in_array('hours_worked', $cols, true);
    } catch (Throwable $e) {
        $hoursCol = false;
    }

    $completedSql = $hoursCol
        ? 'SUM(CASE WHEN a.hours_worked IS NOT NULL THEN 1 ELSE 0 END) AS completed_cnt'
        : 'SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS completed_cnt';
    $statusJoinSql = $statusCol
        ? "SUM(CASE WHEN a.attendance_status = 'late' THEN 1 ELSE 0 END) AS late_cnt,
           SUM(CASE WHEN a.attendance_status = 'no_show' THEN 1 ELSE 0 END) AS noshow_cnt,
           SUM(CASE WHEN a.attendance_status IN ('gps_failed', 'manual_review') THEN 1 ELSE 0 END) AS gps_cnt"
        : '0 AS late_cnt, 0 AS noshow_cnt, 0 AS gps_cnt';

    try {
        $stmt = $pdo->query(
            "SELECT LOWER(TRIM(sr.email)) AS staff_key,
                    MAX(sr.first_name) AS first_name,
                    MAX(sr.surname) AS surname,
                    MAX(sr.staff_id) AS staff_id,
                    COUNT(*) AS approved,
                    SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS checkins,
                    SUM(CASE WHEN a.id IS NULL AND e.event_date < CURDATE() THEN 1 ELSE 0 END) AS missed_cnt,
                    {$statusJoinSql},
                    {$completedSql}
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE sr.status = 'approved' AND TRIM(sr.email) <> ''
               AND e.event_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
             GROUP BY LOWER(TRIM(sr.email))
             HAVING approved >= 1"
        );
        $staffRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $staffRows = [];
    }

    $staffScored = [];
    foreach ($staffRows as $row) {
        $reliability = dash_compute_reliability_score([
            'approved'      => (int) ($row['approved'] ?? 0),
            'checkins'      => (int) ($row['checkins'] ?? 0),
            'late_cnt'      => (int) ($row['late_cnt'] ?? 0),
            'gps_cnt'       => (int) ($row['gps_cnt'] ?? 0),
            'completed_cnt' => (int) ($row['completed_cnt'] ?? 0),
        ]);
        $staffScored[] = array_merge($row, $reliability, [
            'name'        => dash_staff_display_from_row($row),
            'missed_cnt'  => (int) ($row['missed_cnt'] ?? 0) + (int) ($row['noshow_cnt'] ?? 0),
        ]);
    }

    $byReliable = $staffScored;
    usort($byReliable, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $byLate = $staffScored;
    usort($byLate, static fn (array $a, array $b): int => ($b['late_cnt'] ?? 0) <=> ($a['late_cnt'] ?? 0));
    $byMissed = $staffScored;
    usort($byMissed, static fn (array $a, array $b): int => ($b['missed_cnt'] ?? 0) <=> ($a['missed_cnt'] ?? 0));
    $byGps = $staffScored;
    usort($byGps, static fn (array $a, array $b): int => ($b['gps_cnt'] ?? 0) <=> ($a['gps_cnt'] ?? 0));

    $snap['workforce_health']['reliable'] = array_slice(array_values(array_filter($byReliable, static fn (array $r): bool => (int) ($r['score'] ?? 0) > 0)), 0, 5);
    $snap['workforce_health']['late']     = array_slice(array_values(array_filter($byLate, static fn (array $r): bool => (int) ($r['late_cnt'] ?? 0) > 0)), 0, 5);
    $snap['workforce_health']['missed']   = array_slice(array_values(array_filter($byMissed, static fn (array $r): bool => (int) ($r['missed_cnt'] ?? 0) > 0)), 0, 5);
    $snap['workforce_health']['gps']      = array_slice(array_values(array_filter($byGps, static fn (array $r): bool => (int) ($r['gps_cnt'] ?? 0) > 0)), 0, 5);

    $leaderboard = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT LOWER(TRIM(sr.email)) AS staff_key,
                    MAX(sr.first_name) AS first_name,
                    MAX(sr.surname) AS surname,
                    COUNT(*) AS approved,
                    SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS checkins,
                    {$statusJoinSql},
                    {$completedSql}
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE sr.status = 'approved' AND TRIM(sr.email) <> ''
               AND e.event_date >= :month_start AND e.event_date <= :month_end
             GROUP BY LOWER(TRIM(sr.email))
             HAVING approved >= 1"
        );
        $stmt->execute(['month_start' => $monthStart, 'month_end' => $monthEnd]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reliability = dash_compute_reliability_score([
                'approved'      => (int) ($row['approved'] ?? 0),
                'checkins'      => (int) ($row['checkins'] ?? 0),
                'late_cnt'      => (int) ($row['late_cnt'] ?? 0),
                'gps_cnt'       => (int) ($row['gps_cnt'] ?? 0),
                'completed_cnt' => (int) ($row['completed_cnt'] ?? 0),
            ]);
            $leaderboard[] = array_merge($row, $reliability, ['name' => dash_staff_display_from_row($row)]);
        }
    } catch (Throwable $e) {
        $leaderboard = [];
    }
    usort($leaderboard, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $snap['leaderboard'] = array_slice($leaderboard, 0, 10);

    foreach ($upcomingEvents as $event) {
        $eid      = (int) ($event['id'] ?? 0);
        $eventDate = substr((string) ($event['event_date'] ?? ''), 0, 10);
        $att      = $snap['event_attendance'][$eid] ?? ['approved' => 0, 'checked_in' => 0, 'absent' => 0, 'late' => 0];
        $approved = (int) ($att['approved'] ?? 0);
        $checkedIn = (int) ($att['checked_in'] ?? 0);
        $risk     = 'low';
        $riskTip  = 'Attendance tracking normal';

        if ($eventDate === $todayYmd && $approved > 0) {
            $pct = (int) round(($checkedIn / max(1, $approved)) * 100);
            if ($pct < 50) {
                $risk    = 'high';
                $riskTip = 'Less than half of approved staff checked in';
            } elseif ($pct < 80) {
                $risk    = 'medium';
                $riskTip = 'Check-in rate below 80% for today\'s event';
            }
        } elseif ((int) ($event['coverage_gap'] ?? 0) > 0 && $eventDate === $todayYmd) {
            $risk    = 'medium';
            $riskTip = 'Understaffed and active today';
        } elseif ($eventDate === $todayYmd && $approved >= 5 && $checkedIn === 0) {
            $risk    = 'high';
            $riskTip = 'No check-ins yet for a large approved roster';
        }

        $snap['risk_events'][] = [
            'id'         => $eid,
            'name'       => (string) ($event['name'] ?? ''),
            'date'       => $eventDate,
            'risk'       => $risk,
            'tip'        => $riskTip,
            'approved'   => $approved,
            'checked_in' => $checkedIn,
        ];

        $snap['event_attendance'][$eid]['risk'] = $risk;
        $snap['event_attendance'][$eid]['risk_tip'] = $riskTip;
    }

    foreach ($staffScored as $row) {
        if ((int) ($row['late_cnt'] ?? 0) >= 3) {
            $snap['alert_meta']['repeat_late']++;
        }
        if ((int) ($row['missed_cnt'] ?? 0) >= 2) {
            $snap['alert_meta']['repeat_absence']++;
        }
        if ((int) ($row['gps_cnt'] ?? 0) >= 2) {
            $snap['alert_meta']['repeat_gps']++;
        }
    }

    foreach ($snap['risk_events'] as $riskEvent) {
        if (($riskEvent['risk'] ?? '') === 'high') {
            $snap['alert_meta']['high_risk']++;
        }
        if (($riskEvent['date'] ?? '') === $todayYmd && (int) ($riskEvent['approved'] ?? 0) > 0) {
            $pct = (int) round(((int) ($riskEvent['checked_in'] ?? 0) / max(1, (int) $riskEvent['approved'])) * 100);
            if ($pct < 60) {
                $snap['alert_meta']['low_attendance']++;
            }
        }
    }

    $snap['has_data'] = ($todayMetrics['checkins'] + $weekMetrics['checkins'] + $monthMetrics['checkins'] + count($staffScored)) > 0;

    return $snap;
}

function dash_event_attendance_risk_label(string $risk): string
{
    return match ($risk) {
        'high'   => 'High risk',
        'medium' => 'Medium risk',
        default  => 'Low risk',
    };
}

function dash_format_date_short(string $date): string
{
    $ts = strtotime($date);

    return $ts ? date('j M Y', $ts) : '—';
}

function dash_format_date_compact(string $date): string
{
    $ts = strtotime($date);

    return $ts ? date('j M Y', $ts) : '—';
}

function dash_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }

    return strtoupper(substr(trim($name) ?: '?', 0, 2));
}

/** Tab filter key for communication center feed items. */
function dash_comm_tab_kind(array $item): string
{
    $kind = (string) ($item['kind'] ?? '');
    if ($kind === 'message') {
        return 'msg';
    }
    if ($kind === 'registration') {
        return 'reg';
    }
    if ($kind === 'notification') {
        $rawType = strtolower(str_replace(' ', '_', (string) ($item['feed_type'] ?? $item['type'] ?? '')));
        if ($rawType === 'registration' || str_contains($rawType, 'registration')) {
            return 'reg';
        }

        return 'alert';
    }

    return 'alert';
}

function dash_health_grade(int $score): string
{
    return $score >= 90 ? 'Excellent' : ($score >= 75 ? 'Good' : ($score >= 50 ? 'Fair' : 'Review'));
}

function dash_event_name_from_list(array $events, ?int $id): string
{
    if ($id === null || $id <= 0) {
        return '';
    }
    foreach ($events as $event) {
        if ((int) ($event['id'] ?? 0) === $id) {
            return (string) ($event['name'] ?? '');
        }
    }

    return '';
}

function dash_is_operational_audit_action(string $action): bool
{
    static $skip = [
        'login', 'export_staff', 'export_attendance', 'export_backup', 'database_backup',
        'go_live_schema', 'user_create', 'user_update', 'user_deactivate', 'purge_demo_invoices',
        'phase16_deploy', 'feature_flags_update',
    ];

    return $action !== '' && !in_array($action, $skip, true);
}

/**
 * @param array<string, mixed> $entry
 * @param array<int, array<string, mixed>> $events
 * @return array{time: string, context: string, action: string, subject: string}|null
 */
function dash_format_activity_row(array $entry, array $events): ?array
{
    $action = (string) ($entry['action'] ?? '');
    if (!dash_is_operational_audit_action($action)) {
        return null;
    }

    $ts      = strtotime((string) ($entry['created_at'] ?? ''));
    $details = trim((string) ($entry['details'] ?? ''));
    $target  = (string) ($entry['target_type'] ?? '');
    $targetId = isset($entry['target_id']) ? (int) $entry['target_id'] : 0;

    $context = '';
    if ($target === 'event' && $targetId > 0) {
        $context = dash_event_name_from_list($events, $targetId);
        if ($context === '') {
            $context = 'Event #' . $targetId;
        }
    } elseif ($target === 'registration' && $targetId > 0) {
        $context = 'Registration #' . $targetId;
    } elseif ($target === 'attendance' && $targetId > 0) {
        $context = 'Attendance #' . $targetId;
    } elseif ($target !== '') {
        $context = ucfirst(str_replace('_', ' ', $target));
    }

    $label = formatAuditActionLabel($action);
    $subject = $details;

    switch ($action) {
        case 'status_change':
            $label = stripos($details, 'rejected') !== false ? 'Staff rejected' : (stripos($details, 'approved') !== false ? 'Staff approved' : 'Registration updated');
            break;
        case 'bulk_status_change':
            $label = 'Bulk approval update';
            break;
        case 'scan_checkin':
        case 'admin_checkin':
            $label = 'Check-in recorded';
            break;
        case 'event_save':
            $label = stripos($details, 'Created') !== false ? 'Event created' : 'Event updated';
            break;
        case 'sheets_resync':
        case 'event_sheet_create':
        case 'event_sheet_link':
        case 'event_sheet_unlink':
        case 'bulk_sheet_unlink':
            $label = 'Sheets sync';
            break;
        case 'work_hours_update':
            $label = 'Attendance updated';
            break;
        case 'staff_email':
            $label = 'Shift reminder sent';
            break;
    }

    if ($subject === '') {
        $subject = '—';
    }

    return [
        'time'    => $ts ? date('j M H:i', $ts) : '—',
        'context' => $context !== '' ? $context : '—',
        'action'  => $label,
        'subject' => $subject,
    ];
}

$recentActivity = [];
foreach ($auditPool as $entry) {
    $row = dash_format_activity_row($entry, $upcomingEvents);
    if ($row === null) {
        continue;
    }
    $recentActivity[] = $row;
    if (count($recentActivity) >= 10) {
        break;
    }
}

$actionShortages = [];
$actionOther     = [];
if (adminCan('events')) {
    foreach ($understaffedEvents as $event) {
        $gap     = (int) ($event['coverage_gap'] ?? 0);
        $daysOut = max(0, (int) floor((strtotime((string) ($event['event_date'] ?? '')) - strtotime($todayYmd)) / 86400));
        $actionShortages[] = [
            'severity' => $daysOut <= 3 ? 'urgent' : 'warn',
            'name'     => (string) ($event['name'] ?? 'Event'),
            'gap'      => $gap,
            'date'     => dash_format_date_compact((string) ($event['event_date'] ?? '')),
            'venue'    => trim((string) ($event['location'] ?? '')),
            'desc'     => $gap . ' staff short — review assignments before event day',
            'href'     => 'event-form.php?id=' . (int) ($event['id'] ?? 0),
        ];
    }
}
if (adminCan('staff') && (int) $stats['pending'] > 0) {
    $actionOther[] = [
        'severity' => 'urgent',
        'title'    => (int) $stats['pending'] . ' pending approval' . ((int) $stats['pending'] === 1 ? '' : 's'),
        'detail'   => 'Review staff queue',
        'href'     => 'staff.php?status=pending&page=1',
        'cta'      => 'Review queue →',
    ];
}
if ($notifUnread > 0 && adminCan('dashboard')) {
    $actionOther[] = [
        'severity' => 'warn',
        'title'    => $notifUnread . ' unread notification' . ($notifUnread === 1 ? '' : 's'),
        'detail'   => 'New alerts for your team',
        'href'     => 'notifications.php',
        'cta'      => 'Open alerts →',
    ];
}
if ($messageUnread > 0 && adminCan('staff')) {
    $actionOther[] = [
        'severity' => 'warn',
        'title'    => $messageUnread . ' unread message' . ($messageUnread === 1 ? '' : 's'),
        'detail'   => 'Staff inbox needs attention',
        'href'     => 'staff-inbox.php',
        'cta'      => 'Open inbox →',
    ];
}
if ($health !== null) {
    foreach ($health['checks'] as $check) {
        $status = (string) ($check['status'] ?? '');
        if (!in_array($status, ['fail', 'warn'], true) || ($check['key'] ?? '') === 'admin_notifications') {
            continue;
        }
        $actionOther[] = [
            'severity' => $status === 'fail' ? 'urgent' : 'warn',
            'title'    => (string) ($check['label'] ?? 'System check'),
            'detail'   => (string) ($check['detail'] ?? ''),
            'href'     => (string) ($check['fix_url'] ?? 'system-health.php'),
            'cta'      => 'Fix issue →',
        ];
    }
}
if ($financeSnap !== null && !empty($financeSnap['available'])) {
    if ((int) ($financeSnap['overdue_count'] ?? 0) > 0) {
        $actionOther[] = [
            'severity' => 'urgent',
            'title'    => (int) $financeSnap['overdue_count'] . ' overdue invoice' . ((int) $financeSnap['overdue_count'] === 1 ? '' : 's'),
            'detail'   => dash_format_finance_amount($pdo, (float) ($financeSnap['overdue_amount'] ?? 0)) . ' · sent, event completed',
            'href'     => 'invoices.php?status=sent',
            'cta'      => 'Review invoices →',
        ];
    }
    if ((int) ($financeSnap['outstanding_count'] ?? 0) > (int) ($financeSnap['overdue_count'] ?? 0)) {
        $actionOther[] = [
            'severity' => 'warn',
            'title'    => ((int) $financeSnap['outstanding_count'] - (int) $financeSnap['overdue_count']) . ' sent invoice' . (((int) $financeSnap['outstanding_count'] - (int) $financeSnap['overdue_count']) === 1 ? '' : 's') . ' awaiting payment',
            'detail'   => dash_format_finance_amount($pdo, max(0, (float) ($financeSnap['outstanding_amount'] ?? 0) - (float) ($financeSnap['overdue_amount'] ?? 0))) . ' outstanding balance',
            'href'     => 'invoices.php?status=sent',
            'cta'      => 'View balances →',
        ];
    }
    if ((int) ($financeSnap['missing_ref_count'] ?? 0) > 0) {
        $actionOther[] = [
            'severity' => 'warn',
            'title'    => (int) $financeSnap['missing_ref_count'] . ' missing invoice reference' . ((int) $financeSnap['missing_ref_count'] === 1 ? '' : 's'),
            'detail'   => 'Invoice number not set on saved records',
            'href'     => 'invoices.php',
            'cta'      => 'Fix references →',
        ];
    }
    if ((int) ($financeSnap['events_not_invoiced'] ?? 0) > 0) {
        $actionOther[] = [
            'severity' => 'warn',
            'title'    => (int) $financeSnap['events_not_invoiced'] . ' event' . ((int) $financeSnap['events_not_invoiced'] === 1 ? '' : 's') . ' need invoicing',
            'detail'   => 'Past events without a commission invoice',
            'href'     => 'invoice-form.php',
            'cta'      => 'Create invoice →',
        ];
    }
}
if ($financeSnap !== null && (int) ($financeSnap['payroll_alerts'] ?? 0) > 0 && adminCan('invoices')) {
    $actionOther[] = [
        'severity' => 'warn',
        'title'    => (int) $financeSnap['payroll_alerts'] . ' payroll alert' . ((int) $financeSnap['payroll_alerts'] === 1 ? '' : 's'),
        'detail'   => 'Payroll intelligence items need review',
        'href'     => 'payroll-intelligence.php',
        'cta'      => 'Review payroll →',
    ];
}
if ($workforce !== null && !empty($workforce['available'])) {
    $attAlerts = $workforce['alert_meta'] ?? [];
    if ((int) ($attAlerts['high_risk'] ?? 0) > 0) {
        $actionOther[] = [
            'severity' => 'urgent',
            'title'    => (int) $attAlerts['high_risk'] . ' high-risk event' . ((int) $attAlerts['high_risk'] === 1 ? '' : 's'),
            'detail'   => 'Attendance check-in rate critical for today',
            'href'     => 'attendance.php',
            'cta'      => 'Monitor attendance →',
        ];
    }
    if ((int) ($attAlerts['low_attendance'] ?? 0) > 0) {
        $actionOther[] = [
            'severity' => 'urgent',
            'title'    => 'Attendance below 60% today',
            'detail'   => (int) $attAlerts['low_attendance'] . ' event' . ((int) $attAlerts['low_attendance'] === 1 ? '' : 's') . ' need immediate attention',
            'href'     => 'attendance.php',
            'cta'      => 'View attendance →',
        ];
    }
    if ((int) ($attAlerts['repeat_late'] ?? 0) > 0) {
        $actionOther[] = [
            'severity' => 'warn',
            'title'    => (int) $attAlerts['repeat_late'] . ' staff with repeated lateness',
            'detail'   => '3+ late arrivals in the last 90 days',
            'href'     => 'attendance.php',
            'cta'      => 'Review staff →',
        ];
    }
    if ((int) ($attAlerts['repeat_absence'] ?? 0) > 0) {
        $actionOther[] = [
            'severity' => 'warn',
            'title'    => (int) $attAlerts['repeat_absence'] . ' staff with repeated absences',
            'detail'   => 'Multiple missed shifts in the last 90 days',
            'href'     => 'attendance.php',
            'cta'      => 'Review absences →',
        ];
    }
    if ((int) ($attAlerts['repeat_gps'] ?? 0) > 0) {
        $actionOther[] = [
            'severity' => 'warn',
            'title'    => (int) $attAlerts['repeat_gps'] . ' staff with repeated GPS exceptions',
            'detail'   => 'GPS compliance issues in historical records',
            'href'     => 'geo-audits.php',
            'cta'      => 'GPS audits →',
        ];
    }
}
$actionShortages = array_slice($actionShortages, 0, 6);
$actionOther     = array_slice($actionOther, 0, 10);
$actionInfo      = [];
if (adminCan('dashboard')) {
    foreach ($recentNotifs as $notif) {
        if ((int) ($notif['is_read'] ?? 1) === 0) {
            continue;
        }
        $actionInfo[] = [
            'severity' => 'info',
            'title'    => (string) ($notif['title'] ?? 'Notification'),
            'detail'   => trim((string) ($notif['body'] ?? '')) !== ''
                ? (string) $notif['body']
                : 'Read operational notification',
            'href'     => trim((string) ($notif['action_url'] ?? '')) !== '' && ($notif['action_url'] ?? '') !== '#'
                ? (string) $notif['action_url']
                : 'notifications.php',
            'cta'      => 'View →',
        ];
        if (count($actionInfo) >= 3) {
            break;
        }
    }
}
$hasActionItems  = $actionShortages !== [] || $actionOther !== [];
$openIncidents   = count($actionShortages) + count($actionOther);
$priorityCritical = 0;
$priorityWarning  = 0;
$priorityInfo     = count($actionInfo);
foreach ($actionShortages as $item) {
    if (($item['severity'] ?? '') === 'urgent') {
        $priorityCritical++;
    } else {
        $priorityWarning++;
    }
}
foreach ($actionOther as $item) {
    match ($item['severity'] ?? '') {
        'urgent' => $priorityCritical++,
        'warn'   => $priorityWarning++,
        'info'   => $priorityInfo++,
        default  => $priorityInfo++,
    };
}

$commFeed = [];
foreach ($messageThreads as $thread) {
    $ts = strtotime((string) ($thread['last_at'] ?? $thread['updated_at'] ?? ''));
    $commFeed[] = [
        'kind'    => 'message',
        'ts'      => $ts ?: 0,
        'href'    => 'staff-inbox-thread.php?staff_id=' . (int) ($thread['staff_id'] ?? 0),
        'title'   => getStaffDisplayNameFromRow($thread),
        'preview' => (string) ($thread['last_body'] ?? ''),
        'time'    => $ts ? date('j M H:i', $ts) : '',
        'initials'=> dash_initials(getStaffDisplayNameFromRow($thread)),
        'unread'  => false,
    ];
}
foreach ($recentNotifs as $notif) {
    $ts    = strtotime((string) ($notif['created_at'] ?? ''));
    $nUrl  = trim((string) ($notif['action_url'] ?? ''));
    $type  = trim((string) ($notif['type'] ?? 'alert'));
    $commFeed[] = [
        'kind'      => 'notification',
        'feed_type' => $type,
        'ts'        => $ts ?: 0,
        'href'      => $nUrl !== '' && $nUrl !== '#' ? $nUrl : 'notifications.php',
        'title'     => (string) ($notif['title'] ?? ''),
        'preview'   => (string) ($notif['body'] ?? ''),
        'time'      => $ts ? date('j M H:i', $ts) : '',
        'type'      => ucwords(str_replace('_', ' ', $type)),
        'unread'    => (int) ($notif['is_read'] ?? 1) === 0,
    ];
}
if (adminCan('staff')) {
    foreach (getRecentPendingRegistrations($pdo, 3) as $reg) {
        $ts = strtotime((string) ($reg['created_at'] ?? $reg['submitted_at'] ?? ''));
        $name = trim(getStaffDisplayNameFromRow($reg));
        $commFeed[] = [
            'kind'    => 'registration',
            'ts'      => $ts ?: 0,
            'href'    => 'view-staff.php?id=' . (int) ($reg['id'] ?? 0),
            'title'   => $name !== '' ? $name : 'New registration',
            'preview' => 'Pending approval · ' . trim((string) ($reg['email'] ?? '')),
            'time'    => $ts ? date('j M H:i', $ts) : '',
            'type'    => 'Registration',
            'unread'  => true,
        ];
    }
}
$commKindOrder = ['notification' => 0, 'message' => 1, 'registration' => 2];
usort($commFeed, static function (array $a, array $b) use ($commKindOrder): int {
    $ka = $commKindOrder[$a['kind'] ?? ''] ?? 9;
    $kb = $commKindOrder[$b['kind'] ?? ''] ?? 9;
    if ($ka !== $kb) {
        return $ka <=> $kb;
    }

    return ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0);
});
$commFeed = array_slice($commFeed, 0, 8);

$commTabs = [
    'all'   => ['label' => 'All', 'count' => count($commFeed)],
    'alert' => ['label' => 'Alerts', 'count' => count(array_filter($commFeed, static fn (array $i): bool => dash_comm_tab_kind($i) === 'alert'))],
    'msg'   => ['label' => 'Messages', 'count' => count(array_filter($commFeed, static fn (array $i): bool => dash_comm_tab_kind($i) === 'msg'))],
    'reg'   => ['label' => 'Registrations', 'count' => count(array_filter($commFeed, static fn (array $i): bool => dash_comm_tab_kind($i) === 'reg'))],
];

$pageTitle           = 'Dashboard';
$activePage          = 'dashboard';
$erpPageContentClass = 'page-content--dash-s7';

include __DIR__ . '/../includes/admin/layout-top.php';

$dashCss = dirname(__DIR__) . '/assets/css/dashboard-s7-mockup.css';
$dashVer = is_file($dashCss) ? (string) filemtime($dashCss) : '1';
?>

<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/dashboard-s7-mockup.css?v=<?= h($dashVer) ?>">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="dash dash--ops dash--s71 dash--enterprise">

    <div class="dash-cmd" aria-label="Operations command context">
        <div class="dash-cmd__status">
            <span class="dash-cmd__live" aria-hidden="true"></span>
            <span class="dash-cmd__status-text">Operations live</span>
            <?php if ($pwaMetrics !== null): ?>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events exec-kpi--<?= !empty($pwaMetrics['available']) && (int) ($pwaMetrics['installed_total'] ?? 0) > 0 ? 'ok' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($pwaMetrics['installed_total'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Staff app installs</span>
            </a>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= (int) ($pwaMetrics['active_installed_week'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Installed · active 7d</span>
            </a>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= (int) ($pwaMetrics['browser_users_week'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Browser only · 7d</span>
            </a>
                <?php
                $iphoneCount = 0;
                $androidCount = 0;
                foreach (($pwaMetrics['devices'] ?? []) as $devRow) {
                    if (($devRow['label'] ?? '') === 'iPhone') {
                        $iphoneCount = (int) ($devRow['count'] ?? 0);
                    }
                    if (($devRow['label'] ?? '') === 'Android') {
                        $androidCount = (int) ($devRow['count'] ?? 0);
                    }
                }
                ?>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= $iphoneCount ?></span>
                <span class="exec-kpi__lbl">iPhone · 30d</span>
            </a>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= $androidCount ?></span>
                <span class="exec-kpi__lbl">Android · 30d</span>
            </a>
            <?php endif; ?>
            <?php if ($healthScore !== null): ?>
                <span class="dash-cmd__pill dash-cmd__pill--<?= $healthScore >= 90 ? 'ok' : 'warn' ?>">System <?= (int) $healthScore ?>%</span>
            <?php endif; ?>
            <a href="#dash-gps-toggle" class="dash-cmd__pill dash-cmd__pill--<?= $gpsFlagOn ? 'info' : 'muted' ?>" title="Global geo sign-in (GPS attendance v2)">
                GPS <?= $gpsFlagOn ? 'ON' : 'OFF' ?>
            </a>
        </div>
        <div class="dash-cmd__meta">
            <time class="dash-cmd__clock" id="dash-live-clock" datetime="<?= h(date('c')) ?>"><?= h(date('D j M Y · H:i')) ?></time>
            <?php if (adminCan('events')): ?>
                <span class="dash-cmd__stat"><strong><?= $activeTodayCount ?></strong> events today</span>
            <?php endif; ?>
            <?php if (adminCan('attendance')): ?>
                <span class="dash-cmd__stat"><strong><?= (int) $stats['today_checkins'] ?></strong> checked in</span>
            <?php endif; ?>
        </div>
        <p class="dash-cmd__hint">Global search, notifications &amp; messages are in the top bar.</p>
    </div>

    <div class="dash-gps-bar" id="dash-gps-toggle" aria-label="Geo location sign-in">
        <div class="dash-gps-bar__lead">
            <span class="dash-gps-bar__icon" aria-hidden="true">📍</span>
            <div class="dash-gps-bar__copy">
                <p class="dash-gps-bar__title">Geo location sign-in</p>
                <p class="dash-gps-bar__hint">
                    <?= $gpsFlagOn
                        ? 'ON for all events — 1 km venue geofence, GPS required at check-in.'
                        : 'OFF — legacy 100 m check-in. Turn ON when ready to enforce GPS at venues.' ?>
                </p>
            </div>
        </div>
        <div class="dash-gps-bar__actions">
            <?php if ($gpsCanToggle): ?>
                <form method="post" action="dashboard.php#dash-gps-toggle" class="dash-gps-bar__form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle_gps_attendance">
                    <input type="hidden" name="enabled" value="<?= $gpsFlagOn ? '0' : '1' ?>">
                    <button
                        type="submit"
                        class="dash-gps-switch dash-gps-switch--lg dash-gps-switch--<?= $gpsFlagOn ? 'on' : 'off' ?>"
                        aria-pressed="<?= $gpsFlagOn ? 'true' : 'false' ?>"
                        aria-label="<?= $gpsFlagOn ? 'Turn geo sign-in off' : 'Turn geo sign-in on' ?>"
                    >
                        <span class="dash-gps-switch__track" aria-hidden="true">
                            <span class="dash-gps-switch__thumb"></span>
                        </span>
                        <span class="dash-gps-switch__label"><?= $gpsFlagOn ? 'ON' : 'OFF' ?></span>
                    </button>
                </form>
                <a href="feature-flags.php" class="dash-gps-bar__link">Feature flags</a>
            <?php else: ?>
                <span class="dash-gps-bar__status dash-gps-bar__status--<?= $gpsFlagOn ? 'on' : 'off' ?>">
                    GPS <?= $gpsFlagOn ? 'ON' : 'OFF' ?>
                </span>
                <span class="dash-gps-bar__note">Admin only</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-priority" aria-label="Operations priority summary">
        <a href="#dash-actions-title" class="dash-priority__item dash-priority__item--critical">
            <span class="dash-priority__lbl">Critical</span>
            <span class="dash-priority__val"><?= $priorityCritical ?></span>
        </a>
        <a href="#dash-actions-title" class="dash-priority__item dash-priority__item--warning">
            <span class="dash-priority__lbl">Warning</span>
            <span class="dash-priority__val"><?= $priorityWarning ?></span>
        </a>
        <a href="#dash-comm-title" class="dash-priority__item dash-priority__item--info">
            <span class="dash-priority__lbl">Information</span>
            <span class="dash-priority__val"><?= $priorityInfo ?></span>
        </a>
    </div>

    <header class="dash__head dash__head--compact">
        <div>
            <h1 class="dash__title"><?= h($greeting) ?>, <?= h($firstName) ?></h1>
            <p class="dash__sub">Event staffing operations · <?= h(formatAdminRoleLabel(getAdminRole())) ?></p>
        </div>
        <div class="dash__head-actions">
            <?php if (adminCan('staff')): ?>
                <a href="staff.php?status=pending&amp;page=1" class="dash-head-btn">Approvals</a>
            <?php endif; ?>
            <?php if (adminCan('events')): ?>
                <a href="events.php" class="dash-head-btn">Events</a>
            <?php endif; ?>
            <?php if (adminCan('attendance')): ?>
                <a href="scan-checkin.php" class="dash-head-btn dash-head-btn--primary">Check-in</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="dash-exec" aria-label="Executive KPIs">
        <div class="dash-exec__grid">
            <?php if (adminCan('events')): ?>
            <a href="events.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= (int) ($stats['events'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Total events</span>
            </a>
            <a href="events.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-events exec-kpi--<?= $activeTodayCount > 0 ? 'active' : '' ?>">
                <span class="exec-kpi__val"><?= $activeTodayCount ?></span>
                <span class="exec-kpi__lbl">Active today</span>
            </a>
            <a href="events.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= $staffNeededTotal ?></span>
                <span class="exec-kpi__lbl">Staff required</span>
            </a>
            <?php endif; ?>
            <?php if (adminCan('staff')): ?>
            <a href="staff.php?status=approved&amp;page=1" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= number_format((int) ($stats['approved'] ?? 0)) ?></span>
                <span class="exec-kpi__lbl">Staff approved</span>
            </a>
            <a href="staff.php?status=pending&amp;page=1" class="exec-kpi exec-kpi--glass exec-kpi--cat-events exec-kpi--<?= (int) $stats['pending'] > 0 ? 'urgent' : '' ?>">
                <span class="exec-kpi__val"><?= (int) $stats['pending'] ?></span>
                <span class="exec-kpi__lbl">Pending approval</span>
            </a>
            <?php endif; ?>
            <?php if (adminCan('attendance')): ?>
            <a href="attendance.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-attendance">
                <span class="exec-kpi__val"><?= (int) $stats['today_checkins'] ?></span>
                <span class="exec-kpi__lbl">Checked in</span>
            </a>
            <a href="attendance.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-attendance exec-kpi--<?= $attendanceRate < 60 ? 'warn' : 'ok' ?>">
                <span class="exec-kpi__val"><?= $attendanceRate ?>%</span>
                <span class="exec-kpi__lbl">Attendance rate</span>
            </a>
                <?php if ($workforce !== null && !empty($workforce['available'])): ?>
            <a href="attendance.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-attendance exec-kpi--<?= (int) ($workforce['exec']['late'] ?? 0) > 0 ? 'warn' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($workforce['exec']['late'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Late arrivals</span>
            </a>
            <a href="attendance.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-attendance exec-kpi--<?= (int) ($workforce['exec']['no_shows'] ?? 0) > 0 ? 'urgent' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($workforce['exec']['no_shows'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">No shows</span>
            </a>
            <a href="geo-audits.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-attendance exec-kpi--<?= (int) ($workforce['exec']['gps_exceptions'] ?? 0) > 0 ? 'warn' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($workforce['exec']['gps_exceptions'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">GPS exceptions</span>
            </a>
            <a href="geo-audits.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-attendance exec-kpi--<?= (int) ($workforce['exec']['outside_radius'] ?? 0) > 0 ? 'warn' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($workforce['exec']['outside_radius'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Outside radius</span>
            </a>
            <a href="geo-audits.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-attendance exec-kpi--<?= (int) ($workforce['exec']['manual_reviews'] ?? 0) > 0 ? 'warn' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($workforce['exec']['manual_reviews'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Manual reviews</span>
            </a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (adminCan('invoices')): ?>
                <?php if ($financeSnap !== null && !empty($financeSnap['available']) && !empty($financeSnap['has_data'])): ?>
            <a href="invoices.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-finance">
                <span class="exec-kpi__val exec-kpi__val--money"><?= h(dash_format_finance_kpi($pdo, (float) ($financeSnap['revenue_month'] ?? 0))) ?></span>
                <span class="exec-kpi__lbl">Revenue this month</span>
            </a>
            <a href="invoices.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-finance">
                <span class="exec-kpi__val exec-kpi__val--money"><?= h(dash_format_finance_kpi($pdo, (float) ($financeSnap['revenue_week'] ?? 0))) ?></span>
                <span class="exec-kpi__lbl">Revenue this week</span>
            </a>
            <a href="invoices.php?status=sent" class="exec-kpi exec-kpi--glass exec-kpi--cat-finance exec-kpi--<?= (int) ($financeSnap['outstanding_count'] ?? 0) > 0 ? 'warn' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($financeSnap['outstanding_count'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Outstanding invoices</span>
            </a>
            <a href="invoices.php?status=paid" class="exec-kpi exec-kpi--glass exec-kpi--cat-finance exec-kpi--ok">
                <span class="exec-kpi__val"><?= (int) ($financeSnap['paid_count'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Paid invoices</span>
            </a>
            <a href="invoices.php?status=sent" class="exec-kpi exec-kpi--glass exec-kpi--cat-finance exec-kpi--<?= (int) ($financeSnap['overdue_count'] ?? 0) > 0 ? 'urgent' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($financeSnap['overdue_count'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Overdue invoices</span>
            </a>
            <a href="invoices.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-finance">
                <span class="exec-kpi__val"><?= (int) ($financeSnap['collection_rate'] ?? 0) ?>%</span>
                <span class="exec-kpi__lbl">Collection rate</span>
            </a>
                <?php else: ?>
            <a href="invoices.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-finance exec-kpi--finance">
                <span class="exec-kpi__val exec-kpi__val--text">Finance centre</span>
                <span class="exec-kpi__lbl">Invoices &amp; reports</span>
                <span class="exec-kpi__cta">View financial reports →</span>
            </a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="dashboard.php#dash-actions-title" class="exec-kpi exec-kpi--glass exec-kpi--cat-incidents exec-kpi--<?= $openIncidents > 0 ? 'warn' : 'ok' ?>">
                <span class="exec-kpi__val"><?= $openIncidents ?></span>
                <span class="exec-kpi__lbl">Open incidents</span>
            </a>
            <?php if ($healthScore !== null): ?>
            <a href="system-health.php" class="exec-kpi exec-kpi--glass exec-kpi--cat-health exec-kpi--<?= $healthScore >= 90 ? 'ok' : 'warn' ?>">
                <span class="exec-kpi__val"><?= $healthScore ?>%</span>
                <span class="exec-kpi__lbl">System health</span>
            </a>
            <?php endif; ?>
        </div>
    </section>

    <section class="dash-pipeline" aria-label="Staffing pipeline">
        <span class="dash-pipeline__label">Staffing pipeline</span>
        <div class="dash-pipeline__track">
            <div class="dash-pipeline__stage">
                <span class="dash-pipeline__num"><?= $staffNeededTotal ?></span>
                <span class="dash-pipeline__name">Required</span>
            </div>
            <div class="dash-pipeline__stage">
                <span class="dash-pipeline__num"><?= (int) $stats['pending'] ?></span>
                <span class="dash-pipeline__name">Pending</span>
            </div>
            <div class="dash-pipeline__stage">
                <span class="dash-pipeline__num"><?= $approvedOnEvents ?></span>
                <span class="dash-pipeline__name">Approved</span>
            </div>
            <div class="dash-pipeline__stage dash-pipeline__stage--<?= $staffGapTotal > 0 ? 'warn' : 'ok' ?>">
                <span class="dash-pipeline__num"><?= $staffGapTotal ?></span>
                <span class="dash-pipeline__name">Gap</span>
            </div>
            <div class="dash-pipeline__stage">
                <span class="dash-pipeline__num"><?= (int) $stats['today_checkins'] ?></span>
                <span class="dash-pipeline__name">Checked in</span>
            </div>
        </div>
    </section>

    <div class="dash__main">
        <div class="dash__primary">
            <section class="dash-panel dash-panel--actions-req<?= $actionShortages !== [] ? ' dash-panel--actions-hot' : '' ?>" aria-labelledby="dash-actions-title">
                <div class="dash-panel__head dash-panel__head--tight">
                    <div>
                        <h2 id="dash-actions-title" class="dash-panel__title">Critical action center</h2>
                        <p class="dash-panel__sub">
                            <?php if ($actionShortages !== []): ?>
                                <?= count($understaffedEvents) ?> event<?= count($understaffedEvents) === 1 ? '' : 's' ?> understaffed · <?= $staffGapTotal ?> staff short
                            <?php elseif (!$hasActionItems): ?>
                                All operations on track
                            <?php else: ?>
                                Non-staffing items need review
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($actionShortages !== []): ?>
                        <span class="dash-action-badge dash-action-badge--urgent"><?= count($understaffedEvents) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!$hasActionItems): ?>
                    <div class="dash-action-ok">
                        <span class="dash-action-ok__icon" aria-hidden="true">✓</span>
                        <span class="dash-action-ok__text">All clear — no urgent items</span>
                    </div>
                <?php else: ?>
                    <?php if ($actionShortages !== []): ?>
                    <ul class="dash-shortage-list">
                        <?php foreach ($actionShortages as $item): ?>
                        <li>
                            <a href="<?= h($item['href']) ?>" class="dash-shortage dash-shortage--<?= h($item['severity']) ?>">
                                <span class="dash-shortage__priority"><?= $item['severity'] === 'urgent' ? 'Critical' : 'Warning' ?></span>
                                <span class="dash-shortage__main">
                                    <span class="dash-shortage__name"><?= h($item['name']) ?></span>
                                    <span class="dash-shortage__gap"><?= (int) $item['gap'] ?> staff short</span>
                                    <?php if (($item['venue'] ?? '') !== ''): ?>
                                        <span class="dash-shortage__venue"><?= h($item['venue']) ?></span>
                                    <?php endif; ?>
                                    <span class="dash-shortage__desc"><?= h($item['desc'] ?? '') ?></span>
                                </span>
                                <span class="dash-shortage__date"><?= h($item['date']) ?></span>
                                <span class="dash-shortage__cta">Review event →</span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if ($actionOther !== []): ?>
                    <ul class="dash-action-list<?= $actionShortages !== [] ? ' dash-action-list--secondary' : '' ?>">
                        <?php foreach ($actionOther as $item): ?>
                        <li>
                            <a href="<?= h($item['href']) ?>" class="dash-action-item dash-action-item--<?= h($item['severity']) ?>">
                                <span class="dash-action-item__priority"><?= $item['severity'] === 'urgent' ? 'Critical' : 'Warning' ?></span>
                                <span class="dash-action-item__dot" aria-hidden="true"></span>
                                <span class="dash-action-item__body">
                                    <span class="dash-action-item__title"><?= h($item['title']) ?></span>
                                    <span class="dash-action-item__detail"><?= h($item['detail']) ?></span>
                                </span>
                                <span class="dash-action-item__go"><?= h($item['cta']) ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if (adminCan('events')): ?>
            <section class="dash-panel dash-panel--events-table" aria-labelledby="dash-events-title">
                <div class="dash-panel__head dash-panel__head--tight">
                    <div>
                        <h2 id="dash-events-title" class="dash-panel__title">Upcoming events</h2>
                        <p class="dash-panel__sub"><?= $staffNeededTotal ?> required · <?= $staffGapTotal ?> gap · <?= $staffedEventsCount ?> fully staffed</p>
                    </div>
                    <a href="events.php" class="dash-panel__link">View all →</a>
                </div>
                <?php if ($upcomingEvents === []): ?>
                    <p class="dash-empty">No upcoming events scheduled.</p>
                <?php else: ?>
                    <div class="dash-table-wrap">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th scope="col">Event</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Venue</th>
                                    <th scope="col" class="dash-table__num">Required</th>
                                    <th scope="col" class="dash-table__num">Approved</th>
                                    <?php if ($workforce !== null && !empty($workforce['available'])): ?>
                                    <th scope="col" class="dash-table__num">In</th>
                                    <th scope="col" class="dash-table__num">Absent</th>
                                    <th scope="col" class="dash-table__num">Late</th>
                                    <th scope="col">Att. risk</th>
                                    <?php endif; ?>
                                    <th scope="col">Status</th>
                                    <?php if ($financeSnap !== null && !empty($financeSnap['available'])): ?>
                                    <th scope="col">Invoice</th>
                                    <?php endif; ?>
                                    <th scope="col" class="dash-table__num">Gap</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcomingEvents as $event):
                                    $gap      = (int) ($event['coverage_gap'] ?? 0);
                                    $needed   = (int) ($event['staff_needed'] ?? 0);
                                    $approved = (int) ($event['approved_count'] ?? 0);
                                    $venue    = trim((string) ($event['location'] ?? ''));
                                    $rowClass = $gap > 0 ? 'dash-table__row--short' : '';
                                    $status   = dash_event_status($event, $todayYmd);
                                    $eventId  = (int) ($event['id'] ?? 0);
                                    $billing  = $eventBillingMap !== [] ? dash_event_billing_status($eventBillingMap[$eventId] ?? null, substr((string) ($event['event_date'] ?? ''), 0, 10), $todayYmd) : null;
                                    $eventAtt = $workforce['event_attendance'][$eventId] ?? null;
                                    $attRisk  = is_array($eventAtt) ? (string) ($eventAtt['risk'] ?? 'low') : 'low';
                                    ?>
                                <tr class="dash-table__row <?= h($rowClass) ?><?= $attRisk === 'high' ? ' dash-table__row--att-risk' : '' ?>" data-href="event-form.php?id=<?= $eventId ?>" tabindex="0" role="link">
                                    <td class="dash-table__event"><?= h((string) ($event['name'] ?? '')) ?></td>
                                    <td><?= h(dash_format_date_short((string) ($event['event_date'] ?? ''))) ?></td>
                                    <td class="dash-table__venue"><?= h($venue !== '' ? $venue : '—') ?></td>
                                    <td class="dash-table__num"><?= $needed ?></td>
                                    <td class="dash-table__num"><?= $approved ?></td>
                                    <?php if ($workforce !== null && !empty($workforce['available'])): ?>
                                    <td class="dash-table__num"><?= (int) ($eventAtt['checked_in'] ?? 0) ?></td>
                                    <td class="dash-table__num"><?= (int) ($eventAtt['absent'] ?? 0) ?></td>
                                    <td class="dash-table__num"><?= (int) ($eventAtt['late'] ?? 0) ?></td>
                                    <td><span class="dash-att-risk dash-att-risk--<?= h($attRisk) ?>" title="<?= h(is_array($eventAtt) ? (string) ($eventAtt['risk_tip'] ?? '') : '') ?>"><?= h(dash_event_attendance_risk_label($attRisk)) ?></span></td>
                                    <?php endif; ?>
                                    <td><span class="dash-ev-status dash-ev-status--<?= h($status['class']) ?>" title="<?= h($status['tip'] ?? '') ?>"><?= h($status['label']) ?></span></td>
                                    <?php if ($financeSnap !== null && !empty($financeSnap['available'])): ?>
                                    <td><span class="dash-bill-status dash-bill-status--<?= h($billing['class'] ?? 'muted') ?>" title="<?= h($billing['tip'] ?? '') ?>"><?= h($billing['label'] ?? '—') ?></span></td>
                                    <?php endif; ?>
                                    <td class="dash-table__num">
                                        <?php if ($gap > 0): ?>
                                            <span class="dash-gap dash-gap--bad"><?= $gap ?></span>
                                        <?php else: ?>
                                            <span class="dash-gap dash-gap--ok">0</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <div class="dash__bottom">
                <div class="dash__bottom-grid">
        <?php if (adminCan('events')): ?>
        <section class="dash-panel dash-panel--coverage dash-panel--analytics" aria-labelledby="dash-coverage-title">
            <div class="dash-panel__head dash-panel__head--tight">
                <div>
                    <h2 id="dash-coverage-title" class="dash-panel__title">Shift coverage</h2>
                    <p class="dash-panel__sub">Upcoming schedule readiness</p>
                </div>
            </div>
            <div class="dash-coverage-stats">
                <div class="dash-coverage-stat dash-coverage-stat--ok">
                    <span class="dash-coverage-stat__val"><?= $staffedEventsCount ?></span>
                    <span class="dash-coverage-stat__lbl">Fully staffed events</span>
                </div>
                <div class="dash-coverage-stat<?= count($understaffedEvents) > 0 ? ' dash-coverage-stat--hot' : '' ?>">
                    <span class="dash-coverage-stat__val"><?= count($understaffedEvents) ?></span>
                    <span class="dash-coverage-stat__lbl">Understaffed events</span>
                </div>
                <div class="dash-coverage-stat dash-coverage-stat--warn">
                    <span class="dash-coverage-stat__val"><?= $staffGapTotal ?></span>
                    <span class="dash-coverage-stat__lbl">Total staffing gap</span>
                </div>
            </div>
            <?php if ($understaffedEvents !== []): ?>
            <ul class="dash-coverage-list dash-coverage-list--compact">
                <?php foreach (array_slice($understaffedEvents, 0, 4) as $event):
                    $gap = (int) ($event['coverage_gap'] ?? 0);
                    $daysOut = max(0, (int) floor((strtotime((string) ($event['event_date'] ?? '')) - strtotime($todayYmd)) / 86400));
                    ?>
                <li>
                    <a href="event-form.php?id=<?= (int) ($event['id'] ?? 0) ?>" class="dash-coverage-row">
                        <span class="dash-coverage-row__name"><?= h((string) ($event['name'] ?? '')) ?></span>
                        <span class="dash-coverage-row__meta"><?= $gap ?> short · <?= $daysOut === 0 ? 'Today' : ($daysOut . 'd') ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="dash-state-ok dash-state-ok--compact">All upcoming events meet staffing targets.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (adminCan('attendance') && $workforce !== null && !empty($workforce['available'])): ?>
        <?php
            $riskEvents = array_values(array_filter(
                $workforce['risk_events'] ?? [],
                static fn (array $e): bool => ($e['risk'] ?? 'low') !== 'low'
            ));
        ?>
        <section class="dash-panel dash-panel--att-risk dash-panel--analytics" aria-labelledby="dash-att-risk-title">
            <div class="dash-panel__head dash-panel__head--tight">
                <div>
                    <h2 id="dash-att-risk-title" class="dash-panel__title">Attendance risk</h2>
                    <p class="dash-panel__sub">Upcoming events at risk</p>
                </div>
            </div>
            <?php if ($riskEvents === []): ?>
                <p class="dash-state-ok dash-state-ok--compact">No elevated attendance risks</p>
            <?php else: ?>
                <ul class="dash-risk-list dash-risk-list--compact dash-risk-list--fill">
                    <?php foreach (array_slice($riskEvents, 0, 6) as $riskEvent): ?>
                    <li>
                        <a href="event-form.php?id=<?= (int) ($riskEvent['id'] ?? 0) ?>" class="dash-risk-row dash-risk-row--<?= h((string) ($riskEvent['risk'] ?? 'low')) ?>">
                            <span class="dash-risk-row__name"><?= h((string) ($riskEvent['name'] ?? '')) ?></span>
                            <span class="dash-risk-row__meta"><?= h(dash_event_attendance_risk_label((string) ($riskEvent['risk'] ?? 'low'))) ?> · <?= (int) ($riskEvent['checked_in'] ?? 0) ?>/<?= (int) ($riskEvent['approved'] ?? 0) ?> in</span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dash-panel dash-panel--workforce dash-panel--analytics" aria-labelledby="dash-workforce-title">
            <div class="dash-panel__head dash-panel__head--tight">
                <div>
                    <h2 id="dash-workforce-title" class="dash-panel__title">Workforce health</h2>
                    <p class="dash-panel__sub">Top 5 · reliable · late · missed · GPS</p>
                </div>
            </div>
            <?php if (empty($workforce['has_data'])): ?>
                <p class="dash-empty dash-empty--compact">No workforce attendance history yet.</p>
            <?php else: ?>
            <div class="dash-wf-grid">
                <?php
                $wfSections = [
                    'reliable' => 'Most reliable',
                    'late'     => 'Most late',
                    'missed'   => 'Most missed shifts',
                    'gps'      => 'Most GPS exceptions',
                ];
                foreach ($wfSections as $key => $label):
                    $items = $workforce['workforce_health'][$key] ?? [];
                    ?>
                <div class="dash-wf-col">
                    <span class="dash-wf-col__label"><?= h($label) ?></span>
                    <?php if ($items === []): ?>
                        <p class="dash-wf-empty">—</p>
                    <?php else: ?>
                        <ol class="dash-wf-list">
                            <?php foreach ($items as $item): ?>
                            <li>
                                <span class="dash-wf-list__name"><?= h((string) ($item['name'] ?? '')) ?></span>
                                <span class="dash-wf-list__meta">
                                    <?php if ($key === 'reliable'): ?>
                                        <?= (int) ($item['score'] ?? 0) ?> · <?= h((string) ($item['label'] ?? '')) ?>
                                    <?php elseif ($key === 'late'): ?>
                                        <?= (int) ($item['late_cnt'] ?? 0) ?> late
                                    <?php elseif ($key === 'missed'): ?>
                                        <?= (int) ($item['missed_cnt'] ?? 0) ?> missed
                                    <?php else: ?>
                                        <?= (int) ($item['gps_cnt'] ?? 0) ?> GPS
                                    <?php endif; ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="dash-panel dash-panel--leaderboard dash-panel--analytics" aria-labelledby="dash-leaderboard-title">
            <div class="dash-panel__head dash-panel__head--tight">
                <div>
                    <h2 id="dash-leaderboard-title" class="dash-panel__title">Top staff this month</h2>
                    <p class="dash-panel__sub">Top 10 · reliability</p>
                </div>
                <a href="attendance.php" class="dash-panel__link">Open →</a>
            </div>
            <?php if (($workforce['leaderboard'] ?? []) === []): ?>
                <p class="dash-empty dash-empty--compact">No staff attendance records this month.</p>
            <?php else: ?>
                <div class="dash-analytics-scroll dash-lb-wrap">
                    <table class="dash-lb-table dash-lb-table--compact">
                        <thead>
                            <tr>
                                <th scope="col">Staff</th>
                                <th scope="col">Score</th>
                                <th scope="col">Rating</th>
                                <th scope="col" class="dash-table__num">Att.</th>
                                <th scope="col" class="dash-table__num">GPS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workforce['leaderboard'] as $row): ?>
                            <tr>
                                <td><?= h((string) ($row['name'] ?? '')) ?></td>
                                <td><strong><?= (int) ($row['score'] ?? 0) ?></strong></td>
                                <td><span class="dash-rel dash-rel--<?= (int) ($row['score'] ?? 0) >= 85 ? 'excellent' : ((int) ($row['score'] ?? 0) >= 70 ? 'good' : ((int) ($row['score'] ?? 0) >= 50 ? 'avg' : 'bad')) ?>"><?= h((string) ($row['label'] ?? '')) ?></span></td>
                                <td class="dash-table__num"><?= (int) ($row['attendance_pct'] ?? 0) ?>%</td>
                                <td class="dash-table__num"><?= (int) ($row['gps_pct'] ?? 0) ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
                </div>

                <?php if (adminCan('audit')): ?>
                <section class="dash-panel dash-panel--activity dash-panel--activity-feed" aria-labelledby="dash-activity-title">
                    <div class="dash-panel__head dash-panel__head--tight dash-panel__head--sticky">
                        <div>
                            <h2 id="dash-activity-title" class="dash-panel__title">Real-time activity feed</h2>
                            <p class="dash-panel__sub">Operational events only</p>
                        </div>
                        <a href="audit-log.php" class="dash-panel__link">View all →</a>
                    </div>
                    <?php if ($recentActivity === []): ?>
                        <p class="dash-empty dash-empty--compact">No operational activity logged yet.</p>
                    <?php else: ?>
                        <div class="dash-act-table-wrap dash-act-table-wrap--scroll">
                            <table class="dash-act-table dash-act-table--compact">
                                <thead>
                                    <tr>
                                        <th scope="col">Time</th>
                                        <th scope="col">Event</th>
                                        <th scope="col">Action</th>
                                        <th scope="col">Detail</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentActivity as $row): ?>
                                    <tr>
                                        <td class="dash-act-table__time"><?= h($row['time']) ?></td>
                                        <td><?= h($row['context']) ?></td>
                                        <td class="dash-act-table__action"><?= h($row['action']) ?></td>
                                        <td class="dash-act-table__subject"><?= h($row['subject']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>
            </div>
        </div>

        <aside class="dash__side">
            <?php if (adminCan('attendance')): ?>
            <section class="dash-panel dash-panel--attendance" aria-labelledby="dash-att-title">
                <div class="dash-panel__head dash-panel__head--tight">
                    <div>
                        <h2 id="dash-att-title" class="dash-panel__title">Attendance command</h2>
                        <p class="dash-panel__sub">Today's attendance intelligence</p>
                    </div>
                    <a href="attendance.php" class="dash-panel__link">Open →</a>
                </div>
                <?php if ($attendanceIntel !== null && !$attendanceIntel['has_activity']): ?>
                    <p class="dash-att-empty">No attendance activity today</p>
                <?php elseif ($attendanceIntel !== null): ?>
                    <div class="dash-att-intel">
                        <span class="dash-att-intel__heading">Today's attendance</span>
                        <dl class="dash-att-intel__grid">
                            <div class="dash-att-intel__row">
                                <dt>Checked in</dt>
                                <dd><?= (int) $attendanceIntel['checked_in'] ?></dd>
                            </div>
                            <div class="dash-att-intel__row">
                                <dt>Checked out</dt>
                                <dd><?= (int) $attendanceIntel['checked_out'] ?></dd>
                            </div>
                            <div class="dash-att-intel__row<?= (int) $attendanceIntel['late'] > 0 ? ' dash-att-intel__row--warn' : '' ?>">
                                <dt>Late</dt>
                                <dd><?= (int) $attendanceIntel['late'] ?></dd>
                            </div>
                            <div class="dash-att-intel__row<?= (int) $attendanceIntel['exceptions'] > 0 ? ' dash-att-intel__row--warn' : '' ?>">
                                <dt>Exceptions</dt>
                                <dd><?= (int) $attendanceIntel['exceptions'] ?></dd>
                            </div>
                            <div class="dash-att-intel__row<?= (int) $attendanceIntel['gps_failures'] > 0 ? ' dash-att-intel__row--bad' : '' ?>">
                                <dt>GPS failures</dt>
                                <dd><?= (int) $attendanceIntel['gps_failures'] ?></dd>
                            </div>
                            <div class="dash-att-intel__row<?= (int) $attendanceIntel['outside_radius'] > 0 ? ' dash-att-intel__row--bad' : '' ?>">
                                <dt>Outside radius</dt>
                                <dd><?= (int) $attendanceIntel['outside_radius'] ?></dd>
                            </div>
                        </dl>
                    </div>
                <?php endif; ?>
                <?php if ($workforce !== null && !empty($workforce['available']) && !empty($workforce['trend'])): ?>
                    <div class="dash-att-trend">
                        <span class="dash-att-trend__heading">Attendance trend</span>
                        <div class="dash-att-trend__grid">
                            <?php foreach ($workforce['trend'] as $period): ?>
                            <div class="dash-att-trend__col">
                                <span class="dash-att-trend__label"><?= h((string) ($period['label'] ?? '')) ?></span>
                                <ul class="dash-att-trend__list">
                                    <li><span>Check-ins</span><strong><?= (int) ($period['checkins'] ?? 0) ?></strong></li>
                                    <li><span>Late</span><strong><?= (int) ($period['late'] ?? 0) ?></strong></li>
                                    <li><span>Absences</span><strong><?= (int) ($period['absences'] ?? 0) ?></strong></li>
                                </ul>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="dash-att-actions">
                    <a href="scan-checkin.php" class="dash-att-btn dash-att-btn--primary">Scan check-in</a>
                    <a href="attendance.php" class="dash-att-btn">Attendance log</a>
                    <?php if (adminCan('audit')): ?>
                        <a href="geo-audits.php" class="dash-att-btn">GPS audits</a>
                    <?php endif; ?>
                </div>
                <?php if (adminCan('export')): ?>
                <div class="dash-att-exports">
                    <a href="export-attendance.php" class="dash-att-export">Attendance report</a>
                    <a href="export-work-hours.php" class="dash-att-export">Reliability report</a>
                    <a href="geo-audits.php" class="dash-att-export">GPS compliance</a>
                    <a href="export-attendance.php" class="dash-att-export">Late arrivals</a>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if (adminCan('invoices') && $financeSnap !== null && !empty($financeSnap['available'])): ?>
            <section class="dash-panel dash-panel--finance" aria-labelledby="dash-finance-title">
                <div class="dash-panel__head dash-panel__head--tight">
                    <div>
                        <h2 id="dash-finance-title" class="dash-panel__title">Finance summary</h2>
                        <p class="dash-panel__sub">Commission &amp; invoice records</p>
                    </div>
                    <a href="invoices.php" class="dash-panel__link">Finance centre →</a>
                </div>
                <?php if (empty($financeSnap['has_data'])): ?>
                    <p class="dash-fin-empty">No invoice records yet. Create a commission invoice from a completed event.</p>
                <?php else: ?>
                    <dl class="dash-fin-summary">
                        <div class="dash-fin-summary__row">
                            <dt>Total invoice value</dt>
                            <dd><?= h(dash_format_finance_amount($pdo, (float) ($financeSnap['total_invoice_value'] ?? 0))) ?></dd>
                        </div>
                        <div class="dash-fin-summary__row dash-fin-summary__row--warn">
                            <dt>Outstanding balance</dt>
                            <dd><?= h(dash_format_finance_amount($pdo, (float) ($financeSnap['outstanding_amount'] ?? 0))) ?></dd>
                        </div>
                        <div class="dash-fin-summary__row dash-fin-summary__row--ok">
                            <dt>Paid amount</dt>
                            <dd><?= h(dash_format_finance_amount($pdo, (float) ($financeSnap['paid_amount'] ?? 0))) ?></dd>
                        </div>
                        <div class="dash-fin-summary__row<?= (int) ($financeSnap['overdue_count'] ?? 0) > 0 ? ' dash-fin-summary__row--bad' : '' ?>">
                            <dt>Overdue amount</dt>
                            <dd><?= h(dash_format_finance_amount($pdo, (float) ($financeSnap['overdue_amount'] ?? 0))) ?></dd>
                        </div>
                        <div class="dash-fin-summary__row">
                            <dt>Invoice count</dt>
                            <dd><?= (int) ($financeSnap['invoice_count'] ?? 0) ?></dd>
                        </div>
                    </dl>
                    <div class="dash-fin-health" aria-label="Invoice health">
                        <span class="dash-fin-health__label">Invoice health</span>
                        <div class="dash-fin-health__bar">
                            <span class="dash-fin-health__seg dash-fin-health__seg--paid" style="width: <?= (int) ($financeSnap['health_paid_pct'] ?? 0) ?>%" title="Paid"></span>
                            <span class="dash-fin-health__seg dash-fin-health__seg--out" style="width: <?= (int) ($financeSnap['health_outstanding_pct'] ?? 0) ?>%" title="Outstanding"></span>
                            <span class="dash-fin-health__seg dash-fin-health__seg--due" style="width: <?= (int) ($financeSnap['health_overdue_pct'] ?? 0) ?>%" title="Overdue"></span>
                        </div>
                        <ul class="dash-fin-health__legend">
                            <li><span class="dash-fin-health__dot dash-fin-health__dot--paid"></span> Paid <?= (int) ($financeSnap['health_paid_pct'] ?? 0) ?>%</li>
                            <li><span class="dash-fin-health__dot dash-fin-health__dot--out"></span> Outstanding <?= (int) ($financeSnap['health_outstanding_pct'] ?? 0) ?>%</li>
                            <li><span class="dash-fin-health__dot dash-fin-health__dot--due"></span> Overdue <?= (int) ($financeSnap['health_overdue_pct'] ?? 0) ?>%</li>
                        </ul>
                    </div>
                <?php endif; ?>
                <div class="dash-fin-actions">
                    <a href="invoices.php" class="dash-fin-btn">View invoices</a>
                    <a href="invoice-form.php" class="dash-fin-btn dash-fin-btn--primary">Create invoice</a>
                    <a href="export-invoices-month.php" class="dash-fin-btn">Export finance report</a>
                    <a href="invoices.php" class="dash-fin-btn">Open finance centre</a>
                </div>
            </section>
            <?php endif; ?>

            <section class="dash-panel dash-panel--comm" aria-labelledby="dash-comm-title">
                <div class="dash-panel__head dash-panel__head--tight">
                    <div>
                        <h2 id="dash-comm-title" class="dash-panel__title">Communication center</h2>
                        <p class="dash-panel__sub">Operations alerts &amp; messages</p>
                    </div>
                    <a href="staff-inbox.php" class="dash-panel__link">View all →</a>
                </div>
                <div class="dash-comm-tabs" role="tablist" aria-label="Communication filters">
                    <?php $tabIdx = 0; foreach ($commTabs as $tabKey => $tab): ?>
                    <button type="button" class="dash-comm-tab<?= $tabIdx === 0 ? ' dash-comm-tab--active' : '' ?>" role="tab" aria-selected="<?= $tabIdx === 0 ? 'true' : 'false' ?>" data-comm-tab="<?= h($tabKey) ?>">
                        <?= h($tab['label']) ?> <span class="dash-comm-tab__count"><?= (int) $tab['count'] ?></span>
                    </button>
                    <?php $tabIdx++; endforeach; ?>
                </div>
                <?php if ($commFeed === []): ?>
                    <p class="dash-empty dash-empty--compact">No messages or notifications yet.</p>
                <?php else: ?>
                    <ul class="dash-comm-list" id="dash-comm-feed">
                        <?php $commIdx = 0; foreach ($commFeed as $item):
                            $tabKind = dash_comm_tab_kind($item);
                            $badgeLabel = match ($tabKind) {
                                'reg'   => 'Registration',
                                'msg'   => 'Message',
                                default => 'Alert',
                            };
                            ?>
                        <li class="dash-comm__feed-item<?= $commIdx >= 3 ? ' dash-comm__feed-item--more' : '' ?>" data-comm-kind="<?= h($tabKind) ?>"<?= $commIdx >= 3 ? ' hidden' : '' ?>>
                            <a href="<?= h($item['href']) ?>" class="dash-comm-row<?= !empty($item['unread']) ? ' dash-comm-row--unread' : '' ?>">
                                <div class="dash-comm-row__top">
                                    <span class="dash-comm-row__badge dash-comm-row__badge--<?= h($tabKind) ?>">
                                        <?php if ($item['kind'] === 'message'): ?>
                                            <span class="dash-comm-row__av" aria-hidden="true"><?= h($item['initials'] ?? '?') ?></span>
                                        <?php endif; ?>
                                        <?= h($badgeLabel) ?>
                                    </span>
                                    <?php if (($item['time'] ?? '') !== ''): ?>
                                        <time class="dash-comm-row__time" datetime=""><?= h((string) $item['time']) ?></time>
                                    <?php endif; ?>
                                </div>
                                <span class="dash-comm-row__title"><?= h((string) ($item['title'] ?? '')) ?></span>
                                <?php if (trim((string) ($item['preview'] ?? '')) !== ''): ?>
                                    <span class="dash-comm-row__preview"><?= h((string) $item['preview']) ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php $commIdx++; endforeach; ?>
                    </ul>
                    <?php if (count($commFeed) > 3): ?>
                        <button type="button" class="dash-comm-more" id="dash-comm-more" aria-expanded="false">View more</button>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if ($health !== null): ?>
            <section class="dash-panel dash-panel--health-compact" aria-labelledby="dash-health-title">
                <div class="dash-panel__head dash-panel__head--tight dash-panel__head--health">
                    <div class="dash-health-head">
                        <div class="dash-health-head__title-row">
                            <h2 id="dash-health-title" class="dash-panel__title">System health</h2>
                            <span class="dash-gps dash-gps--<?= $gpsFlagOn ? 'on' : 'off' ?>" aria-hidden="true">GPS <?= $gpsFlagOn ? 'ON' : 'OFF' ?></span>
                        </div>
                        <p class="dash-panel__sub"><?= h(dash_health_grade($healthScore ?? 0)) ?></p>
                    </div>
                    <a href="system-health.php" class="dash-panel__link">Details →</a>
                </div>
                <div class="dash-health-compact">
                    <div class="dash-health-compact__aside">
                        <div class="dash-health-compact__score" style="--score: <?= (int) $healthScore ?>">
                            <span class="dash-health-compact__pct"><?= (int) $healthScore ?>%</span>
                        </div>
                        <?php if ($healthCheckedAt !== null): ?>
                            <p class="dash-health-compact__refresh">Last refresh <?= h($healthCheckedAt) ?></p>
                        <?php endif; ?>
                    </div>
                    <ul class="dash-health-compact__list">
                        <?php foreach (array_slice($health['checks'], 0, 4) as $check):
                            $st = (string) ($check['status'] ?? 'pass');
                            ?>
                        <li class="dash-health-compact__row dash-health-compact__row--<?= h($st) ?>">
                            <span><?= h((string) ($check['label'] ?? '')) ?></span>
                            <span class="dash-health-compact__tag"><?= $st === 'pass' ? 'OK' : ($st === 'warn' ? 'Review' : 'Issue') ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
            <?php endif; ?>
        </aside>
    </div>

    <?php if ($pwaMetrics !== null): ?>
    <details class="dash-analytics" id="dash-pwa-analytics" open>
        <summary class="dash-analytics__toggle">
            <span class="dash-analytics__title">Staff app installs &amp; devices</span>
            <span class="dash-analytics__hint"><?= !empty($pwaMetrics['available']) ? ((int) ($pwaMetrics['installed_total'] ?? 0) . ' installed · ' . (float) ($pwaMetrics['install_rate'] ?? 0) . '% on home screen (7d)') : 'Tracking will start when staff open the app' ?></span>
        </summary>
        <div class="dash-analytics__body">
            <?php if (empty($pwaMetrics['available'])): ?>
            <p class="form-hint" style="margin:0 0 1rem;padding:0.75rem 1rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                Install tracking is being set up. Counts appear here after staff open <strong>staff-app.php</strong> on their phone (browser or home-screen install). Refresh this page in a minute if you just deployed.
            </p>
            <?php endif; ?>
            <?php
            $pwaHistorical = is_array($pwaMetrics['historical'] ?? null) ? $pwaMetrics['historical'] : [];
            $pwaLaunchYmd = (string) ($pwaHistorical['launch_date'] ?? '2026-05-28');
            $pwaLaunchLabel = date('j M Y', strtotime($pwaLaunchYmd));
            if (!empty($pwaHistorical['available'])):
                $pwaFirstLog = !empty($pwaHistorical['first_visit_at'])
                    ? date('j M Y', strtotime((string) $pwaHistorical['first_visit_at']))
                    : '—';
            ?>
            <div class="dash-funnel-kpis" style="margin-bottom:1rem;">
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= h($pwaLaunchLabel) ?></div><div class="dash-funnel-kpi__l">Staff app launch date</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= $pwaFirstLog ?></div><div class="dash-funnel-kpi__l">First visit logged (on/after launch)</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) ($pwaHistorical['unique_devices'] ?? 0) ?></div><div class="dash-funnel-kpi__l">Unique phones since launch</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) ($pwaHistorical['total_page_views'] ?? 0) ?></div><div class="dash-funnel-kpi__l">Page views since launch</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) ($pwaHistorical['unique_devices_7d'] ?? 0) ?></div><div class="dash-funnel-kpi__l">Unique phones · 7d</div></div>
            </div>
            <p class="form-hint" style="margin:0 0 1rem;">
                <strong>Historical data</strong> is from server visit logs since <strong><?= h($pwaLaunchLabel) ?></strong> (staff app launch).
                Home-screen <em>install</em> counts below only apply after a phone reports an install event — earlier opens count as browser visits.
            </p>
            <?php elseif ($pwaLaunchLabel !== ''): ?>
            <p class="form-hint" style="margin:0 0 1rem;padding:0.75rem 1rem;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;">
                No staff-app visit logs found since launch (<strong><?= h($pwaLaunchLabel) ?></strong>). Counts will appear after staff open
                <strong>register.olasentra.com/staff-app.php</strong> on their phones.
            </p>
            <?php endif; ?>
            <div class="dash-funnel-kpis">
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) ($pwaMetrics['installed_total'] ?? 0) ?></div><div class="dash-funnel-kpi__l">Total installs</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) ($pwaMetrics['installed_week'] ?? 0) ?></div><div class="dash-funnel-kpi__l">New installs · 7d</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) ($pwaMetrics['active_installed_week'] ?? 0) ?></div><div class="dash-funnel-kpi__l">Active installed · 7d</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) ($pwaMetrics['browser_users_week'] ?? 0) ?></div><div class="dash-funnel-kpi__l">Browser only · 7d</div></div>
            </div>
            <p class="form-hint" style="margin:0 0 1rem;">Counts unique phones that opened the staff app from the home screen (<strong>installed</strong>) vs mobile browser only. iOS manual “Add to Home Screen” is detected when they reopen the app.</p>
            <?php if (!empty($pwaMetrics['devices'])): ?>
            <h3 style="font-size:0.95rem;margin:0 0 0.5rem;">Devices (last 30 days)</h3>
            <div class="dash-funnel-bar">
                <?php
                $maxDev = max(1, ...array_column($pwaMetrics['devices'], 'count'));
                foreach ($pwaMetrics['devices'] as $devRow):
                    $cnt = (int) ($devRow['count'] ?? 0);
                    $w   = min(100, max(5, (int) round($cnt / $maxDev * 100)));
                    ?>
                <div class="dash-funnel-bar__top"><span><?= h((string) ($devRow['label'] ?? '')) ?></span><span><?= $cnt ?></span></div>
                <div class="dash-funnel-bar__track"><div class="dash-funnel-bar__fill" style="width:<?= $w ?>%"></div></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($pwaMetrics['browsers'])): ?>
            <h3 style="font-size:0.95rem;margin:1rem 0 0.5rem;">Browsers (last 30 days)</h3>
            <ul style="margin:0;padding-left:1.25rem;line-height:1.7;">
                <?php foreach ($pwaMetrics['browsers'] as $browserRow): ?>
                    <li><?= h((string) ($browserRow['label'] ?? '')) ?> — <?= (int) ($browserRow['count'] ?? 0) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if (!empty($pwaMetrics['recent'])): ?>
            <h3 style="font-size:0.95rem;margin:1rem 0 0.5rem;">Recent installs</h3>
            <ul style="margin:0;padding-left:1.25rem;line-height:1.7;font-size:0.9rem;">
                <?php foreach ($pwaMetrics['recent'] as $row): ?>
                    <li>
                        <?= h((string) ($row['staff_email'] ?? 'Unknown staff')) ?>
                        · <?= h((string) ($row['os_name'] ?? '')) ?>
                        · <?= h((string) ($row['browser_name'] ?? '')) ?>
                        <?php if (!empty($row['installed_at'])): ?>
                            · <?= h(date('j M Y', strtotime((string) $row['installed_at']))) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; ?>

    <?php if ($funnel !== null && !empty($funnel['flag_enabled']) && (int) ($funnel['started'] ?? 0) > 0): ?>
    <details class="dash-analytics">
        <summary class="dash-analytics__toggle">
            <span class="dash-analytics__title">Registration analytics</span>
            <span class="dash-analytics__hint">Wizard funnel · collapsed by default</span>
        </summary>
        <div class="dash-analytics__body">
            <div class="dash-funnel-kpis">
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) $funnel['started'] ?></div><div class="dash-funnel-kpi__l">Started</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) $funnel['submitted'] ?></div><div class="dash-funnel-kpi__l">Submitted</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (float) ($funnel['completion_rate'] ?? 0) ?>%</div><div class="dash-funnel-kpi__l">Conversion</div></div>
                <div class="dash-funnel-kpi"><div class="dash-funnel-kpi__v"><?= (int) ($funnel['abandoned'] ?? 0) ?></div><div class="dash-funnel-kpi__l">Abandoned</div></div>
            </div>
            <?php
            $conv = $funnel['conversions'] ?? [];
            $bars = [
                ['Started', (int) $funnel['started'], 100.0],
                ['Step 1 → 2', (int) (($conv['step1_to_step2']['count'] ?? 0)), (float) (($conv['step1_to_step2']['rate'] ?? 0))],
                ['Step 2 → 8', (int) (($conv['step2_to_step8']['count'] ?? 0)), (float) (($conv['step2_to_step8']['rate'] ?? 0))],
                ['Submit', (int) (($conv['step8_to_submit']['count'] ?? 0)), (float) (($conv['step8_to_submit']['rate'] ?? 0))],
            ];
            $maxC = max(1, ...array_column($bars, 1));
            foreach ($bars as [$label, $count, $rate]):
                $w = min(100, max(5, (int) round($count / $maxC * 100)));
                ?>
            <div class="dash-funnel-bar">
                <div class="dash-funnel-bar__top"><span><?= h($label) ?></span><span><?= $count ?></span></div>
                <div class="dash-funnel-bar__track"><div class="dash-funnel-bar__fill" style="width:<?= $w ?>%"></div></div>
                <div class="dash-funnel-bar__rate"><?= number_format($rate, 1) ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>
</div>

<script>
(function () {
    document.querySelectorAll('.dash-table__row[data-href]').forEach(function (row) {
        var href = row.getAttribute('data-href');
        if (!href) return;
        row.addEventListener('click', function () { window.location.href = href; });
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                window.location.href = href;
            }
        });
    });

    var clock = document.getElementById('dash-live-clock');
    if (clock) {
        setInterval(function () {
            var now = new Date();
            clock.textContent = now.toLocaleString(undefined, {
                weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
            clock.setAttribute('datetime', now.toISOString());
        }, 30000);
    }

    function applyCommFeedFilter() {
        var feed = document.getElementById('dash-comm-feed');
        if (!feed) return;
        var activeTab = document.querySelector('.dash-comm-tab--active');
        var filter = activeTab ? activeTab.getAttribute('data-comm-tab') : 'all';
        var moreBtn = document.getElementById('dash-comm-more');
        var expanded = moreBtn && moreBtn.getAttribute('aria-expanded') === 'true';
        var visibleIdx = 0;
        feed.querySelectorAll('.dash-comm__feed-item').forEach(function (item) {
            var kind = item.getAttribute('data-comm-kind');
            var match = filter === 'all' || kind === filter;
            if (!match) {
                item.hidden = true;
                return;
            }
            item.hidden = !expanded && visibleIdx >= 3;
            visibleIdx++;
        });
    }

    document.querySelectorAll('.dash-comm-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.dash-comm-tab').forEach(function (t) {
                t.classList.toggle('dash-comm-tab--active', t === tab);
                t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
            });
            applyCommFeedFilter();
        });
    });

    var commMore = document.getElementById('dash-comm-more');
    if (commMore) {
        commMore.addEventListener('click', function () {
            var expanded = commMore.getAttribute('aria-expanded') === 'true';
            expanded = !expanded;
            commMore.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            commMore.textContent = expanded ? 'Show less' : 'View more';
            applyCommFeedFilter();
        });
    }
})();
</script>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
