<?php

declare(strict_types=1);

require_once __DIR__ . '/../production-readiness.php';
require_once __DIR__ . '/../staff-repository.php';
require_once __DIR__ . '/../staff-blacklist.php';
require_once __DIR__ . '/compliance-repository.php';
require_once __DIR__ . '/staff-availability.php';

/** @return array{from: string, to: string, label: string} */
function wf_period_range(string $period): array
{
    $to = date('Y-m-d');

    return match ($period) {
        '90d'   => ['from' => date('Y-m-d', strtotime('-89 days')), 'to' => $to, 'label' => 'Last 90 days'],
        '12m'   => ['from' => date('Y-m-d', strtotime('-364 days')), 'to' => $to, 'label' => 'Last 12 months'],
        default => ['from' => date('Y-m-d', strtotime('-29 days')), 'to' => $to, 'label' => 'Last 30 days'],
    };
}

function wf_attendance_status_available(PDO $pdo): bool
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

function wf_hours_worked_available(PDO $pdo): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $cols      = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
        $available = in_array('hours_worked', $cols, true);
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

/** @param array<string, int|float> $row @return array{score: int, label: string, attendance_pct: int, completion_pct: int, gps_pct: int, punctuality_pct: int} */
function wf_compute_reliability_score(array $row): array
{
    $approved  = max(0, (int) ($row['approved'] ?? 0));
    $checkins  = max(0, (int) ($row['checkins'] ?? 0));
    $late      = max(0, (int) ($row['late_cnt'] ?? 0));
    $gpsIssues = max(0, (int) ($row['gps_cnt'] ?? 0));
    $completed = max(0, (int) ($row['completed_cnt'] ?? 0));

    $attendancePct  = $approved > 0 ? min(100, (int) round(($checkins / $approved) * 100)) : 0;
    $completionPct  = $checkins > 0 ? min(100, (int) round(($completed / $checkins) * 100)) : 0;
    $gpsPct         = $checkins > 0 ? min(100, (int) round((($checkins - $gpsIssues) / $checkins) * 100)) : 100;
    $punctualityPct = $checkins > 0 ? min(100, (int) round((($checkins - $late) / $checkins) * 100)) : 0;

    $score = (int) round(
        ($attendancePct * 0.30)
        + ($completionPct * 0.25)
        + ($gpsPct * 0.25)
        + ($punctualityPct * 0.20)
    );

    return [
        'score'           => max(0, min(100, $score)),
        'label'           => wf_reliability_label(max(0, min(100, $score))),
        'attendance_pct'  => $attendancePct,
        'completion_pct'  => $completionPct,
        'gps_pct'         => $gpsPct,
        'punctuality_pct' => $punctualityPct,
    ];
}

function wf_reliability_label(int $score): string
{
    return match (true) {
        $score >= 85 => 'Excellent',
        $score >= 70 => 'Good',
        $score >= 50 => 'Average',
        default      => 'Needs attention',
    };
}

function wf_staff_display(array $row): string
{
    $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['surname'] ?? '')));

    return $name !== '' ? $name : (string) ($row['email'] ?? 'Staff');
}

/** @return 'red'|'amber'|'green' */
function wf_classify_staff_risk(array $row): string
{
    if (!empty($row['is_blacklisted']) || (int) ($row['is_blacklisted'] ?? 0) === 1) {
        return 'red';
    }

    $score  = (int) ($row['score'] ?? 0);
    $late   = (int) ($row['late_cnt'] ?? 0);
    $missed = (int) ($row['missed_cnt'] ?? 0);
    $gps    = (int) ($row['gps_cnt'] ?? 0);

    if ($score < 50 || $missed >= 3 || $gps >= 3 || $late >= 5) {
        return 'red';
    }
    if ($score < 70 || $missed >= 2 || $gps >= 2 || $late >= 3) {
        return 'amber';
    }

    return 'green';
}

function wf_risk_label(string $risk): string
{
    return match ($risk) {
        'red'   => 'High risk',
        'amber' => 'Medium risk',
        default => 'Low risk',
    };
}

/**
 * @return array{join: string, params: array<string, mixed>, status_sql: string, completed_sql: string}
 */
function wf_staff_stats_sql_parts(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $statusCol    = wf_attendance_status_available($pdo);
    $hoursCol     = wf_hours_worked_available($pdo);
    $statusSql    = $statusCol
        ? "SUM(CASE WHEN a.attendance_status = 'late' THEN 1 ELSE 0 END) AS late_cnt,
           SUM(CASE WHEN a.attendance_status = 'no_show' THEN 1 ELSE 0 END) AS noshow_cnt,
           SUM(CASE WHEN a.attendance_status IN ('gps_failed', 'manual_review') THEN 1 ELSE 0 END) AS gps_cnt"
        : '0 AS late_cnt, 0 AS noshow_cnt, 0 AS gps_cnt';
    $completedSql = $hoursCol
        ? 'SUM(CASE WHEN a.hours_worked IS NOT NULL THEN 1 ELSE 0 END) AS completed_cnt'
        : 'SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS completed_cnt';

    return [
        'join'          => '',
        'params'        => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        'status_sql'    => $statusSql,
        'completed_sql' => $completedSql,
        'date_filter'   => 'AND e.event_date >= :date_from AND e.event_date <= :date_to',
    ];
}

/**
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function wf_list_staff_performance(PDO $pdo, string $period, array $filters = [], int $limit = 50, int $offset = 0): array
{
    if (!tableExists($pdo, 'staff')) {
        return [];
    }

    $range = wf_period_range($period);
    $parts = wf_staff_stats_sql_parts($pdo, $range['from'], $range['to']);

    $where  = ['1=1'];
    $params = $parts['params'];

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[]       = '(s.first_name LIKE :q OR s.surname LIKE :q OR s.email LIKE :q OR s.mobile LIKE :q)';
        $params['q']   = '%' . $q . '%';
    }

    $role = trim((string) ($filters['role'] ?? ''));
    if ($role !== '') {
        $where[]         = 's.staff_role = :role';
        $params['role']  = $role;
    }

    if (($filters['blacklisted'] ?? '') === '1') {
        $where[] = 's.is_blacklisted = 1';
    } elseif (($filters['blacklisted'] ?? '') === '0') {
        $where[] = 's.is_blacklisted = 0';
    }

    $sql = "SELECT s.id, s.first_name, s.surname, s.email, s.mobile, s.staff_role,
                   s.is_blacklisted, s.location_lat, s.location_lng,
                   COUNT(DISTINCT sr.id) AS approved,
                   SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS checkins,
                   SUM(CASE WHEN a.id IS NULL AND e.event_date < CURDATE() THEN 1 ELSE 0 END) AS missed_cnt,
                   {$parts['status_sql']},
                   {$parts['completed_sql']}
            FROM staff s
            LEFT JOIN staff_registrations sr ON sr.staff_id = s.id AND sr.status = 'approved'
            LEFT JOIN events e ON e.id = sr.event_id {$parts['date_filter']}
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY s.id
            HAVING approved > 0
            ORDER BY s.surname ASC, s.first_name ASC
            LIMIT " . max(1, min($limit, 200)) . ' OFFSET ' . max(0, $offset);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $reliability = wf_compute_reliability_score($row);
        $merged      = array_merge($row, $reliability, [
            'name'       => wf_staff_display($row),
            'missed_cnt' => (int) ($row['missed_cnt'] ?? 0) + (int) ($row['noshow_cnt'] ?? 0),
        ]);
        $merged['risk'] = wf_classify_staff_risk($merged);
        if (($filters['risk'] ?? '') !== '' && $merged['risk'] !== $filters['risk']) {
            continue;
        }
        if (($filters['min_score'] ?? '') !== '' && $merged['score'] < (int) $filters['min_score']) {
            continue;
        }
        $out[] = $merged;
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function wf_list_staff_by_risk(PDO $pdo, string $period, string $riskLevel, int $limit = 50): array
{
    $all = wf_list_staff_performance($pdo, $period, [], 300, 0);

    return array_slice(array_values(array_filter(
        $all,
        static fn (array $r): bool => ($r['risk'] ?? '') === $riskLevel
    )), 0, max(1, min($limit, 100)));
}

/** @return list<array<string, mixed>> */
function wf_get_staff_event_history(PDO $pdo, int $staffId, int $limit = 20): array
{
    if ($staffId < 1) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT sr.id AS registration_id, e.id AS event_id, e.name AS event_name, e.event_date,
                    e.location, sr.status,
                    a.checked_in_at, a.attendance_status
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE sr.staff_id = :sid
             ORDER BY e.event_date DESC, e.name ASC
             LIMIT " . max(1, min($limit, 50))
        );
        $stmt->execute(['sid' => $staffId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function wf_get_event_staffing_analysis(PDO $pdo, ?int $eventId = null, int $limit = 50): array
{
    $statusCol = wf_attendance_status_available($pdo);
    $lateSql   = $statusCol ? "SUM(CASE WHEN a.attendance_status = 'late' THEN 1 ELSE 0 END)" : '0';

    $where  = 'e.is_active = 1';
    $params = [];
    if ($eventId !== null && $eventId > 0) {
        $where           = 'e.id = :event_id';
        $params['event_id'] = $eventId;
    } else {
        $where .= ' AND e.event_date >= CURDATE()';
    }

    $sql = "SELECT e.id, e.name, e.event_date, e.location, e.staff_needed,
                   COUNT(DISTINCT CASE WHEN sr.status = 'approved' THEN sr.id END) AS approved,
                   SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS checked_in,
                   SUM(CASE WHEN sr.status = 'approved' AND a.id IS NULL AND e.event_date <= CURDATE() THEN 1 ELSE 0 END) AS absent,
                   {$lateSql} AS late_cnt
            FROM events e
            LEFT JOIN staff_registrations sr ON sr.event_id = e.id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE {$where}
            GROUP BY e.id
            ORDER BY e.event_date ASC, e.name ASC
            LIMIT " . max(1, min($limit, 100));

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $today = date('Y-m-d');
    $out   = [];
    foreach ($rows as $row) {
        $needed    = (int) ($row['staff_needed'] ?? 0);
        $approved  = (int) ($row['approved'] ?? 0);
        $checkedIn = (int) ($row['checked_in'] ?? 0);
        $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
        $gap       = $needed > 0 ? max(0, $needed - $approved) : 0;

        $staffScores = wf_event_staff_reliability_avg($pdo, (int) ($row['id'] ?? 0));
        $attRisk     = 'low';
        if ($eventDate === $today && $approved > 0) {
            $pct = (int) round(($checkedIn / max(1, $approved)) * 100);
            if ($pct < 50) {
                $attRisk = 'high';
            } elseif ($pct < 80) {
                $attRisk = 'medium';
            }
        } elseif ($gap > 0 && $eventDate === $today) {
            $attRisk = 'medium';
        }

        $staffingScore = 100;
        if ($needed > 0) {
            $staffingScore -= min(40, $gap * 8);
        }
        if ($approved > 0 && $eventDate <= $today) {
            $staffingScore -= min(30, (int) round((1 - ($checkedIn / max(1, $approved))) * 30));
        }
        $staffingScore = max(0, min(100, $staffingScore - (int) round((100 - $staffScores) * 0.3)));

        $out[] = array_merge($row, [
            'gap'              => $gap,
            'reliability_avg'  => $staffScores,
            'attendance_risk'  => $attRisk,
            'staffing_score'   => $staffingScore,
        ]);
    }

    return $out;
}

function wf_event_staff_reliability_avg(PDO $pdo, int $eventId): int
{
    if ($eventId < 1 || !tableExists($pdo, 'staff')) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT s.id FROM staff_registrations sr
             INNER JOIN staff s ON s.id = sr.staff_id
             WHERE sr.event_id = :eid AND sr.status = 'approved' AND sr.staff_id IS NOT NULL"
        );
        $stmt->execute(['eid' => $eventId]);
        $ids = array_map(static fn ($id): int => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return 0;
    }

    if ($ids === []) {
        return 0;
    }

    $range = wf_period_range('12m');
    $all   = wf_list_staff_performance($pdo, '12m', [], 500, 0);
    $byId  = [];
    foreach ($all as $row) {
        $byId[(int) ($row['id'] ?? 0)] = (int) ($row['score'] ?? 0);
    }

    $total = 0;
    $count = 0;
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $total += $byId[$id];
            $count++;
        }
    }

    return $count > 0 ? (int) round($total / $count) : 0;
}

/**
 * Smart staff search — directory filters plus workforce metrics.
 *
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function wf_smart_staff_search(PDO $pdo, array $filters, string $period = '30d', int $limit = 50): array
{
    $dirFilters = [];
    foreach (['q', 'role', 'blacklisted', 'location'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            $dirFilters[$key] = $filters[$key];
        }
    }

    $staff = getStaffWithFilters($pdo, $dirFilters, min($limit * 3, 300), 0);
    if ($staff === []) {
        return [];
    }

    $performance = wf_list_staff_performance($pdo, $period, $dirFilters, 500, 0);
    $perfById    = [];
    foreach ($performance as $row) {
        $perfById[(int) ($row['id'] ?? 0)] = $row;
    }

    $out = [];
    foreach ($staff as $row) {
        $id   = (int) ($row['id'] ?? 0);
        $perf = $perfById[$id] ?? null;

        $score = (int) ($perf['score'] ?? 0);
        if (($filters['min_reliability'] ?? '') !== '' && $score < (int) $filters['min_reliability']) {
            continue;
        }
        if (($filters['max_reliability'] ?? '') !== '' && $score > (int) $filters['max_reliability']) {
            continue;
        }
        if (($filters['risk'] ?? '') !== '' && ($perf['risk'] ?? 'green') !== $filters['risk']) {
            continue;
        }
        if (($filters['min_attendance'] ?? '') !== '' && (int) ($perf['attendance_pct'] ?? 0) < (int) $filters['min_attendance']) {
            continue;
        }
        if (($filters['min_gps'] ?? '') !== '' && (int) ($perf['gps_pct'] ?? 0) < (int) $filters['min_gps']) {
            continue;
        }

        if (($filters['compliance'] ?? '') !== '') {
            $psaStatus = wf_psa_compliance_status(
                (string) ($row['psa_expiry_date'] ?? ''),
                (string) ($row['psa_licence'] ?? '')
            );
            if ($filters['compliance'] !== $psaStatus) {
                continue;
            }
        }

        if (($filters['has_events'] ?? '') === '1') {
            $history = wf_get_staff_event_history($pdo, $id, 1);
            if ($history === []) {
                continue;
            }
        }

        $merged = array_merge($row, $perf ?? [
            'score'          => 0,
            'attendance_pct' => 0,
            'gps_pct'        => 0,
            'risk'           => 'green',
        ], ['name' => wf_staff_display($row)]);

        $availStatus = trim((string) ($filters['availability'] ?? ''));
        if ($availStatus !== '' && wf_availability_table_exists($pdo)) {
            $today = date('Y-m-d');
            try {
                $stmt = $pdo->prepare(
                    'SELECT status FROM staff_availability WHERE staff_id = :sid AND avail_date = :d LIMIT 1'
                );
                $stmt->execute(['sid' => $id, 'd' => $today]);
                $aStatus = (string) ($stmt->fetchColumn() ?: 'available');
                if ($availStatus === 'available' && !in_array($aStatus, ['available', 'leave_approved', 'holiday_approved'], true)) {
                    continue;
                }
                if ($availStatus === 'unavailable' && in_array($aStatus, ['available', 'leave_approved', 'holiday_approved'], true)) {
                    continue;
                }
                $merged['availability_today'] = $aStatus;
            } catch (Throwable $e) {
                // skip filter on error
            }
        }

        $out[] = $merged;
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}
