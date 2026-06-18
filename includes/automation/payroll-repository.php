<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';
require_once __DIR__ . '/../work-hours-repository.php';
require_once __DIR__ . '/../staff-repository.php';

function payroll_default_hourly_rate(PDO $pdo, int $staffId): float
{
    if (!tableExists($pdo, 'staff_rate_cards') || $staffId < 1) {
        return 0.0;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT hourly_rate FROM staff_rate_cards
             WHERE staff_id = :sid
               AND effective_from <= CURDATE()
               AND (effective_to IS NULL OR effective_to >= CURDATE())
             ORDER BY effective_from DESC LIMIT 1"
        );
        $stmt->execute(['sid' => $staffId]);
        $rate = $stmt->fetchColumn();

        return $rate !== false ? round((float) $rate, 2) : 0.0;
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** @return list<array<string, mixed>> */
function payroll_build_preview(PDO $pdo, int $eventId = 0, string $workDate = ''): array
{
    ensureWorkHoursSchema($pdo);
    $rows   = getWorkHoursList($pdo, $eventId, $workDate);
    $output = [];

    foreach ($rows as $row) {
        $staffId = 0;
        try {
            $sidStmt = $pdo->prepare('SELECT staff_id FROM staff_registrations WHERE id = :rid LIMIT 1');
            $sidStmt->execute(['rid' => (int) ($row['registration_id'] ?? 0)]);
            $staffId = (int) ($sidStmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $staffId = 0;
        }

        $rate    = payroll_default_hourly_rate($pdo, $staffId);

        if ($rate <= 0 && tableExists($pdo, 'commission_invoice_lines')) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT hourly_rate FROM commission_invoice_lines
                     WHERE registration_id = :rid ORDER BY id DESC LIMIT 1'
                );
                $stmt->execute(['rid' => (int) ($row['registration_id'] ?? 0)]);
                $lineRate = $stmt->fetchColumn();
                if ($lineRate !== false) {
                    $rate = round((float) $lineRate, 2);
                }
            } catch (Throwable $e) {
                // optional
            }
        }

        $hours       = round((float) ($row['hours_paid'] ?? $row['hours_worked'] ?? 0), 2);
        $gross       = round($hours * $rate, 2);
        $adjustment  = payroll_adjustments_for($pdo, $staffId, (int) ($row['event_id'] ?? 0), (string) ($row['event_date'] ?? $workDate));
        $deductions  = $adjustment < 0 ? abs($adjustment) : 0.0;
        $bonus       = $adjustment > 0 ? $adjustment : 0.0;
        $gross      += $bonus;
        $net         = $gross - $deductions;

        $output[] = array_merge($row, [
            'hourly_rate'  => $rate,
            'gross_pay'    => $gross,
            'adjustments'  => $adjustment,
            'deductions'   => $deductions,
            'net_pay'      => $net,
        ]);
    }

    return $output;
}

/** @param list<array<string, mixed>> $rows @return array{hours: float, gross: float, deductions: float, net: float} */
function payroll_totals(array $rows): array
{
    $hours = $gross = $deductions = $net = 0.0;
    foreach ($rows as $row) {
        $hours      += (float) ($row['hours_paid'] ?? $row['hours_worked'] ?? 0);
        $gross      += (float) ($row['gross_pay'] ?? 0);
        $deductions += (float) ($row['deductions'] ?? 0);
        $net        += (float) ($row['net_pay'] ?? 0);
    }

    return [
        'hours'      => round($hours, 2),
        'gross'      => round($gross, 2),
        'deductions' => round($deductions, 2),
        'net'        => round($net, 2),
    ];
}

function payroll_save_rate(PDO $pdo, int $staffId, float $rate, ?string $role = null): bool
{
    if (!tableExists($pdo, 'staff_rate_cards') || $staffId < 1) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO staff_rate_cards (staff_id, hourly_rate, role_name, effective_from)
             VALUES (:sid, :rate, :role, CURDATE())'
        );

        return $stmt->execute([
            'sid'  => $staffId,
            'rate' => max(0, round($rate, 2)),
            'role' => $role,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function payroll_adjustments_for(PDO $pdo, int $staffId, int $eventId = 0, string $workDate = ''): float
{
    if (!tableExists($pdo, 'staff_payroll_adjustments') || $staffId < 1) {
        return 0.0;
    }

    $where  = ['staff_id = :sid'];
    $params = ['sid' => $staffId];

    if ($eventId > 0) {
        $where[]       = '(event_id = :eid OR event_id IS NULL)';
        $params['eid'] = $eventId;
    }
    if ($workDate !== '') {
        $where[]     = '(work_date = :wd OR work_date IS NULL)';
        $params['wd'] = $workDate;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(adjustment_amount), 0) FROM staff_payroll_adjustments WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return round((float) ($stmt->fetchColumn() ?: 0), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}
