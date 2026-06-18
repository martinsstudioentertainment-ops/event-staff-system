<?php

declare(strict_types=1);

require_once __DIR__ . '/workforce-analytics.php';
require_once __DIR__ . '/compliance-repository.php';
require_once __DIR__ . '/../production-readiness.php';
require_once __DIR__ . '/../platform/payroll-intelligence.php';

/** @return array<string, mixed> */
function wf_get_executive_snapshot(PDO $pdo): array
{
    $today = date('Y-m-d');
    $snap  = [
        'revenue_month'       => 0.0,
        'outstanding_amount'  => 0.0,
        'outstanding_count'   => 0,
        'attendance_rate'     => 0,
        'staff_utilization'   => 0,
        'upcoming_events'     => 0,
        'compliance_alerts'   => 0,
        'high_risk_staff'     => 0,
        'operational_alerts'  => [],
    ];

    if (tableExists($pdo, 'commission_invoices')) {
        try {
            $monthStart = date('Y-m-01');
            $stmt       = $pdo->prepare(
                "SELECT COALESCE(SUM(total_amount), 0) FROM commission_invoices
                 WHERE status = 'paid' AND DATE(updated_at) >= :ms"
            );
            $stmt->execute(['ms' => $monthStart]);
            $snap['revenue_month'] = round((float) $stmt->fetchColumn(), 2);

            $stmt = $pdo->query(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS amt
                 FROM commission_invoices WHERE status = 'sent'"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $snap['outstanding_count']  = (int) ($row['cnt'] ?? 0);
            $snap['outstanding_amount'] = round((float) ($row['amt'] ?? 0), 2);
        } catch (Throwable $e) {
            // optional
        }
    }

    $range   = wf_period_range('30d');
    $metrics = wf_attendance_period_metrics($pdo, $range['from'], $range['to'], wf_attendance_status_available($pdo));
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE sr.status = 'approved' AND e.event_date >= :from AND e.event_date <= :to"
        );
        $stmt->execute(['from' => $range['from'], 'to' => $range['to']]);
        $approved = (int) $stmt->fetchColumn();
        $snap['attendance_rate'] = $approved > 0
            ? (int) round(($metrics['checkins'] / $approved) * 100)
            : 0;
    } catch (Throwable $e) {
        $snap['attendance_rate'] = 0;
    }

    try {
        $snap['upcoming_events'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM events WHERE is_active = 1 AND event_date >= CURDATE()"
        )->fetchColumn();
    } catch (Throwable $e) {
        $snap['upcoming_events'] = 0;
    }

    try {
        $activeStaff = (int) $pdo->query('SELECT COUNT(*) FROM staff WHERE is_blacklisted = 0')->fetchColumn();
        $assigned    = (int) $pdo->query(
            "SELECT COUNT(DISTINCT sr.staff_id) FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE sr.status = 'approved' AND e.event_date >= CURDATE() AND sr.staff_id IS NOT NULL"
        )->fetchColumn();
        $snap['staff_utilization'] = $activeStaff > 0
            ? (int) round(($assigned / $activeStaff) * 100)
            : 0;
    } catch (Throwable $e) {
        $snap['staff_utilization'] = 0;
    }

    $compliance = wf_compliance_summary($pdo);
    $snap['compliance_alerts'] = (int) ($compliance['expiring'] ?? 0) + (int) ($compliance['expired'] ?? 0) + (int) ($compliance['missing'] ?? 0);

    $highRisk = wf_list_staff_by_risk($pdo, '30d', 'red', 500);
    $snap['high_risk_staff'] = count($highRisk);

    if ($snap['compliance_alerts'] > 0) {
        $snap['operational_alerts'][] = $snap['compliance_alerts'] . ' compliance issue(s) require attention';
    }
    if ($snap['high_risk_staff'] > 0) {
        $snap['operational_alerts'][] = $snap['high_risk_staff'] . ' high-risk staff member(s)';
    }
    if ($snap['outstanding_count'] > 0) {
        $snap['operational_alerts'][] = $snap['outstanding_count'] . ' outstanding invoice(s)';
    }

    $payrollAlerts = function_exists('countPayrollAlerts') ? countPayrollAlerts($pdo, true) : 0;
    if ($payrollAlerts > 0) {
        $snap['operational_alerts'][] = $payrollAlerts . ' payroll alert(s)';
    }

    return $snap;
}

/** @return array{checkins: int, late: int, no_show: int, gps_failed: int, manual_review: int, outside_radius: int, absences: int} */
function wf_attendance_period_metrics(PDO $pdo, string $dateFrom, string $dateTo, bool $statusCol): array
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
            $metrics['no_show']        = (int) ($row['no_show_cnt'] ?? 0);
            $metrics['gps_failed']     = (int) ($row['gps_failed_cnt'] ?? 0);
            $metrics['manual_review']  = (int) ($row['manual_review_cnt'] ?? 0);
            $metrics['outside_radius'] = (int) ($row['manual_review_cnt'] ?? 0);
        } catch (Throwable $e) {
            // optional
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
