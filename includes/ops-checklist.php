<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/google-sheets-sync.php';
require_once __DIR__ . '/apply-remote-sync.php';

/**
 * @return list<array{key: string, label: string, hint: string, fix_url?: string}>
 */
function getOpsManualChecklistItems(): array
{
    return [
        [
            'key'     => 'pending_cleared',
            'label'   => 'Pending registrations reviewed and approved/declined',
            'hint'    => 'Dashboard → Review pending. Approve only when profile + PSA are complete.',
            'fix_url' => 'staff.php?status=pending&page=1',
        ],
        [
            'key'     => 'apply_sync',
            'label'   => 'Apply vault + Google Sheets synced after approvals',
            'hint'    => 'Run sync on apply admin so Payroll, Master, and PSA Compliance tabs match.',
            'fix_url' => 'apply-portal.php',
        ],
        [
            'key'     => 'psa_expiry',
            'label'   => 'PSA expiry checked (expired / expiring soon)',
            'hint'    => 'Apply admin → PSA compliance. Chase staff with missing or expired licences.',
            'fix_url' => 'apply-portal.php',
        ],
        [
            'key'     => 'sheets_spot_check',
            'label'   => 'Google Sheets spot-check (row counts + Irish dates)',
            'hint'    => 'Confirm event tabs and Master Staff Database PSA columns look correct.',
            'fix_url' => 'google-sheets-diagnostic.php',
        ],
        [
            'key'     => 'backup_weekly',
            'label'   => 'Database backup completed this week',
            'hint'    => 'Run weekly backup from Go live or confirm server cron.',
            'fix_url' => 'go-live.php',
        ],
        [
            'key'     => 'logs_cleared',
            'label'   => 'Log files cleared (daily cron or System cleanup)',
            'hint'    => 'Safe: cron/daily-cleanup.php once per day. Do NOT auto-login or clear cache every 5 minutes.',
            'fix_url' => 'system-cleanup.php',
        ],
        [
            'key'     => 'profile_links',
            'label'   => 'Incomplete profiles sent update link where needed',
            'hint'    => 'Staff Directory → filter Profile incomplete → send profile link.',
            'fix_url' => 'staff-directory.php?profile=incomplete',
        ],
        [
            'key'     => 'content_employer_wording',
            'label'   => 'Content review: employer / payroll wording (Phase A)',
            'hint'    => 'Audit complete — docs/CONTENT-EMPLOYER-WORDING-AUDIT.html. P0: privacy.php + terms.php. P1: emails + wizard copy. Do not imply Olasentra employs staff or pays wages.',
            'fix_url' => 'feature-flags.php',
        ],
    ];
}

/**
 * @return array<string, bool>
 */
function getOpsManualProgress(PDO $pdo): array
{
    $raw = getSetting($pdo, 'ops_manual_checks', '');
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? array_map(static fn ($v): bool => (bool) $v, $decoded) : [];
}

function setOpsManualCheck(PDO $pdo, string $key, bool $done): void
{
    $progress         = getOpsManualProgress($pdo);
    $progress[$key]   = $done;
    setSetting($pdo, 'ops_manual_checks', json_encode($progress, JSON_THROW_ON_ERROR));
}

/**
 * @return array{done: int, total: int}
 */
function countOpsManualDone(PDO $pdo): array
{
    $items    = getOpsManualChecklistItems();
    $progress = getOpsManualProgress($pdo);
    $done     = 0;

    foreach ($items as $item) {
        if (!empty($progress[$item['key']])) {
            ++$done;
        }
    }

    return ['done' => $done, 'total' => count($items)];
}

/**
 * Live operational metrics for the ops hub.
 *
 * @return array<string, mixed>
 */
function getOpsLiveMetrics(PDO $pdo): array
{
    $stats = getDashboardStats($pdo);

    $expiringSoon = 0;
    $applyUrl     = getApplySiteUrl($pdo);
    $sheetsOn     = isGoogleSheetsSyncEnabled($pdo);

    return [
        'pending'         => (int) ($stats['pending'] ?? 0),
        'approved'        => (int) ($stats['approved'] ?? 0),
        'today_checkins'  => (int) ($stats['today_checkins'] ?? 0),
        'active_events'   => (int) ($stats['events'] ?? 0),
        'apply_url'       => $applyUrl,
        'sheets_enabled'  => $sheetsOn,
        'cron_apply_hint' => getApplyPortalCronSyncUrl($pdo),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function getOpsChecklistView(PDO $pdo): array
{
    $progress = getOpsManualProgress($pdo);
    $items    = [];

    foreach (getOpsManualChecklistItems() as $item) {
        $done     = !empty($progress[$item['key']]);
        $items[]  = array_merge($item, [
            'done'   => $done,
            'status' => $done ? 'pass' : 'pending',
        ]);
    }

    return $items;
}
