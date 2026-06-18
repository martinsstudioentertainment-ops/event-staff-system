<?php

declare(strict_types=1);

require_once __DIR__ . '/command-center.php';
require_once __DIR__ . '/payroll-intelligence.php';
require_once __DIR__ . '/trust-scores.php';
require_once __DIR__ . '/auto-approval-engine.php';
require_once __DIR__ . '/../events-repository.php';

/** @return array<int, array<string, mixed>> */
function getAiOpsRecommendations(PDO $pdo): array
{
    $recs = [];
    $cc   = getCommandCenterSnapshot($pdo);

    if ((int) ($cc['pending_registrations'] ?? 0) > 10) {
        $recs[] = [
            'priority' => 'high',
            'category' => 'registrations',
            'title'    => 'High pending registration queue',
            'detail'   => (int) $cc['pending_registrations'] . ' applications awaiting review.',
            'action'   => 'staff.php?status=pending',
            'action_label' => 'Review queue',
        ];
    }

    if (getAutoApprovalMode($pdo) === 0 && (int) ($cc['pending_registrations'] ?? 0) > 5) {
        $recs[] = [
            'priority' => 'medium',
            'category' => 'automation',
            'title'    => 'Enable auto-approval shadow mode',
            'detail'   => 'Test rules without changing status — set feature_auto_approval to shadow (1).',
            'action'   => 'auto-approval.php',
            'action_label' => 'Auto approval settings',
        ];
    }

    if ((int) ($cc['attendance_issues'] ?? 0) > 0) {
        $recs[] = [
            'priority' => 'high',
            'category' => 'attendance',
            'title'    => 'Attendance issues need review',
            'detail'   => (int) $cc['attendance_issues'] . ' records flagged (no-show, GPS, manual review).',
            'action'   => 'attendance.php',
            'action_label' => 'Open attendance',
        ];
    }

    if ((int) ($cc['payroll_alerts'] ?? 0) > 0) {
        $recs[] = [
            'priority' => 'high',
            'category' => 'payroll',
            'title'    => 'Hours reconciliation alerts',
            'detail'   => (int) $cc['payroll_alerts'] . ' open hours alerts.',
            'action'   => 'payroll-intelligence.php',
            'action_label' => 'Hours reconciliation',
        ];
    }

    if ((int) ($cc['sheets_failed_24h'] ?? 0) > 0) {
        $recs[] = [
            'priority' => 'critical',
            'category' => 'sheets',
            'title'    => 'Google Sheets sync failures',
            'detail'   => (int) $cc['sheets_failed_24h'] . ' failed syncs in the last 24 hours.',
            'action'   => 'google-sheets-control.php',
            'action_label' => 'Sheets control center',
        ];
    }

    foreach ($cc['upcoming_events'] ?? [] as $event) {
        $eventId  = (int) ($event['id'] ?? 0);
        $approved = (int) ($event['approved_count'] ?? 0);
        $needed   = 0;
        if ($eventId > 0) {
            $evRow = getEventById($pdo, $eventId);
            $needed = (int) ($evRow['staff_needed'] ?? 0);
        }
        if ($needed > 0 && $approved < $needed) {
            $recs[] = [
                'priority' => 'medium',
                'category' => 'staffing',
                'title'    => 'Event understaffed: ' . (string) ($event['name'] ?? ''),
                'detail'   => $approved . ' / ' . $needed . ' staff approved.',
                'action'   => 'event-hub.php?event_id=' . (int) ($event['id'] ?? 0),
                'action_label' => 'Event hub',
            ];
            if (count($recs) >= 12) {
                break;
            }
        }
    }

    $tiers = summarizeTrustScoreTiers($pdo);
    if (($tiers['bronze'] ?? 0) > ($tiers['gold'] ?? 0) + ($tiers['platinum'] ?? 0)) {
        $recs[] = [
            'priority' => 'low',
            'category' => 'trust',
            'title'    => 'Many bronze-tier staff on roster',
            'detail'   => 'Consider prioritising gold/platinum staff for premium events.',
            'action'   => 'trust-scores.php',
            'action_label' => 'Trust scores',
        ];
    }

    usort($recs, static function (array $a, array $b): int {
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        return ($order[$a['priority'] ?? 'low'] ?? 9) <=> ($order[$b['priority'] ?? 'low'] ?? 9);
    });

    return $recs;
}

/** @return array<string, mixed> */
function getAiOpsSummary(PDO $pdo): array
{
    $cc = getCommandCenterSnapshot($pdo);

    return [
        'generated_at'          => gmdate('Y-m-d H:i:s') . ' UTC',
        'pending_registrations' => (int) ($cc['pending_registrations'] ?? 0),
        'attendance_issues'     => (int) ($cc['attendance_issues'] ?? 0),
        'payroll_alerts'        => (int) ($cc['payroll_alerts'] ?? 0),
        'active_events'         => (int) ($cc['active_events'] ?? 0),
        'recommendations'       => getAiOpsRecommendations($pdo),
    ];
}
