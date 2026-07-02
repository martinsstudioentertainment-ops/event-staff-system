<?php

declare(strict_types=1);

require_once __DIR__ . '/system-health.php';

/**
 * Compact checks for sidebar widget (max 5 items).
 *
 * @return list<array{label: string, ok: bool}>
 */
function getAdminSidebarHealthChecks(PDO $pdo): array
{
    $summary = summarizeSystemHealth($pdo);
    $compact = [];

    foreach ($summary['checks'] as $check) {
        if (count($compact) >= 4) {
            break;
        }
        $compact[] = [
            'label' => $check['label'],
            'ok'    => $check['status'] === 'pass',
        ];
    }

    $allOk = $summary['fail'] === 0;
    array_unshift($compact, [
        'label' => $allOk ? 'System healthy' : 'Needs attention',
        'ok'    => $allOk,
    ]);

    return $compact;
}
