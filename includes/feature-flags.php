<?php

declare(strict_types=1);

/**
 * Production feature flags — stored in system_settings.
 * All flags default OFF except where noted. Toggle via admin/feature-flags.php.
 * Rollback: set flag to 0 and redeploy, or restore pre-deploy backup.
 */

require_once __DIR__ . '/settings-repository.php';

/** @return array<string, array{label: string, desc: string, default: string, phase: string}> */
function getFeatureFlagDefinitions(): array
{
    return [
        'feature_ops_ui' => [
            'label'   => 'Ops UI design system',
            'desc'    => 'STUB — not wired. Planned ops-ui.css design system for admin. Leave OFF.',
            'default' => '0',
            'phase'   => 'P1',
        ],
        'feature_command_center_v2' => [
            'label'   => 'Command Center dashboard',
            'desc'    => 'ACTIVE — wired to admin/command-center.php. SOC-style ops dashboard. Leave OFF until ready.',
            'default' => '0',
            'phase'   => 'P2',
        ],
        'feature_unified_inbox' => [
            'label'   => 'Unified Inbox',
            'desc'    => 'ACTIVE — wired to admin/unified-inbox.php. Merges notifications, messages, payroll, GPS, sheets alerts.',
            'default' => '0',
            'phase'   => 'P5',
        ],
        'feature_event_hub' => [
            'label'   => 'Event Hub',
            'desc'    => 'ACTIVE — wired to admin/event-hub.php. Per-event operations control screen.',
            'default' => '0',
            'phase'   => 'P3',
        ],
        'feature_live_ops' => [
            'label'   => 'Live Ops mode',
            'desc'    => 'STUB — not wired. Planned fullscreen event-day scanner. Leave OFF.',
            'default' => '0',
            'phase'   => 'P4',
        ],
        'feature_public_premium_v2' => [
            'label'   => 'Premium public homepage',
            'desc'    => 'ACTIVE — wired to home.php and public layout. ON = dark premium theme on olasentra.com.',
            'default' => '1',
            'phase'   => 'P14',
        ],
        'feature_registration_wizard_v2' => [
            'label'   => 'Registration wizard v2',
            'desc'    => 'ACTIVE — wired to index.php, analytics API, and funnel widget. ON = 8-step wizard on register.olasentra.com.',
            'default' => '0',
            'phase'   => 'A',
        ],
        'feature_auto_approval' => [
            'label'   => 'Auto approval engine',
            'desc'    => 'ACTIVE — wired to admin/auto-approval.php + submit hook. 0=off, 1=shadow (log only), 2=live.',
            'default' => '0',
            'phase'   => 'P6',
        ],
        'feature_trust_scores' => [
            'label'   => 'Staff trust scores',
            'desc'    => 'ACTIVE — wired to admin/trust-scores.php. Bronze/Silver/Gold/Platinum badges.',
            'default' => '0',
            'phase'   => 'P7',
        ],
        'feature_system_health_center' => [
            'label'   => 'System Health Center',
            'desc'    => 'STUB — superseded by admin/system-health.php (always available). Safe to remove flag when cleaning roadmap.',
            'default' => '0',
            'phase'   => 'P10',
        ],
        'feature_client_portal' => [
            'label'   => 'Client portal',
            'desc'    => 'STUB — not wired. Planned client.* subdomain. Leave OFF.',
            'default' => '0',
            'phase'   => 'P9',
        ],
        'feature_staff_pwa_v2' => [
            'label'   => 'Staff PWA v2',
            'desc'    => 'ACTIVE — wired to staff-app.php + sw.js. Offline cache, background sync, 3-tab shell.',
            'default' => '0',
            'phase'   => 'P13',
        ],
        'feature_ai_ops' => [
            'label'   => 'AI Operations Assistant',
            'desc'    => 'ACTIVE — wired to admin/ai-ops.php. Rule-based ops recommendations (no external AI API).',
            'default' => '0',
            'phase'   => 'P12',
        ],
        'feature_gps_attendance_v2' => [
            'label'   => 'GPS attendance v2',
            'desc'    => 'ACTIVE — wired to check-in, maps radius, activation cron, and ping API. ON = 1 km geofence, pre-check-in hibernation, GPS enforcement. Pilot only until rollout signed off.',
            'default' => '0',
            'phase'   => 'GPS-1',
        ],
    ];
}

/**
 * Audit metadata for admin feature-flag review.
 *
 * @return array<string, array{tier: string, wired: bool, safe_remove: bool, note?: string}>
 */
function getFeatureFlagAuditMetadata(): array
{
    return [
        'feature_ops_ui'                 => ['tier' => 'stub', 'wired' => false, 'safe_remove' => true,  'note' => 'No runtime references'],
        'feature_command_center_v2'      => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
        'feature_unified_inbox'          => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
        'feature_event_hub'              => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
        'feature_live_ops'               => ['tier' => 'stub', 'wired' => false, 'safe_remove' => true],
        'feature_public_premium_v2'      => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
        'feature_registration_wizard_v2' => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
        'feature_auto_approval'          => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
        'feature_trust_scores'           => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
        'feature_system_health_center'   => ['tier' => 'stub', 'wired' => false, 'safe_remove' => true,  'note' => 'Use system-health.php instead'],
        'feature_client_portal'          => ['tier' => 'stub', 'wired' => false, 'safe_remove' => true],
        'feature_staff_pwa_v2'           => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
        'feature_ai_ops'                 => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
        'feature_gps_attendance_v2'      => ['tier' => 'active', 'wired' => true,  'safe_remove' => false],
    ];
}

function getFeatureFlagKey(string $shortName): string
{
    $shortName = trim($shortName);
    if (str_starts_with($shortName, 'feature_')) {
        return $shortName;
    }

    return 'feature_' . $shortName;
}

function getFeatureFlagValue(?PDO $pdo, string $key): string
{
    $defs = getFeatureFlagDefinitions();
    $key  = getFeatureFlagKey($key);

    if (!isset($defs[$key])) {
        return '0';
    }

    if ($pdo === null) {
        return $defs[$key]['default'];
    }

    $stored = getSetting($pdo, $key, $defs[$key]['default']);

    return $stored !== '' ? $stored : $defs[$key]['default'];
}

/**
 * @param bool|null $defaultWhenMissing used when PDO unavailable
 */
function isFeatureEnabled(?PDO $pdo, string $key, ?bool $defaultWhenMissing = null): bool
{
    $defs = getFeatureFlagDefinitions();
    $key  = getFeatureFlagKey($key);

    if (!isset($defs[$key])) {
        return false;
    }

    if ($pdo === null) {
        if ($defaultWhenMissing !== null) {
            return $defaultWhenMissing;
        }

        return $defs[$key]['default'] === '1' || $defs[$key]['default'] === '2';
    }

    $value = getFeatureFlagValue($pdo, $key);

    return $value === '1' || $value === '2' || $value === 'on' || $value === 'true';
}

/** Auto-approval: 0=off, 1=shadow, 2=live */
function getAutoApprovalMode(?PDO $pdo): int
{
    $value = getFeatureFlagValue($pdo, 'feature_auto_approval');

    return match ($value) {
        '2', 'live'   => 2,
        '1', 'shadow' => 1,
        default       => 0,
    };
}

/** @return array<string, string> */
function getAllFeatureFlagValues(?PDO $pdo): array
{
    $out  = [];
    $defs = getFeatureFlagDefinitions();

    foreach (array_keys($defs) as $key) {
        $out[$key] = getFeatureFlagValue($pdo, $key);
    }

    return $out;
}

/**
 * @param array<string, string> $input raw POST values (0/1/2)
 * @return array{ok: bool, message: string}
 */
function saveFeatureFlagsFromInput(PDO $pdo, array $input): array
{
    $defs = getFeatureFlagDefinitions();

    foreach ($defs as $key => $meta) {
        if ($key === 'feature_auto_approval') {
            $raw = (string) ($input[$key] ?? $meta['default']);
            if (!in_array($raw, ['0', '1', '2'], true)) {
                $raw = $meta['default'];
            }
            setSetting($pdo, $key, $raw);
            continue;
        }

        $enabled = !empty($input[$key]) && (string) $input[$key] !== '0';
        setSetting($pdo, $key, $enabled ? '1' : '0');
    }

    return ['ok' => true, 'message' => 'Feature flags saved. Changes apply on next page load — no deploy required.'];
}

/**
 * Short label for dashboard flag corner pills.
 */
function getDashboardFeatureFlagShortLabel(string $key): string
{
    $defs = getFeatureFlagDefinitions();
    $key  = getFeatureFlagKey($key);
    if (!isset($defs[$key])) {
        return $key;
    }

    $short = [
        'feature_gps_attendance_v2'        => 'GPS',
        'feature_auto_approval'          => 'Auto approve',
        'feature_registration_wizard_v2' => 'Reg wizard',
        'feature_staff_pwa_v2'           => 'Staff PWA',
        'feature_public_premium_v2'      => 'Premium site',
        'feature_command_center_v2'      => 'Command ctr',
        'feature_unified_inbox'          => 'Inbox',
        'feature_event_hub'              => 'Event hub',
        'feature_trust_scores'           => 'Trust scores',
        'feature_ai_ops'                 => 'AI ops',
        'feature_ops_ui'                 => 'Ops UI',
        'feature_live_ops'               => 'Live ops',
        'feature_system_health_center'   => 'Health ctr',
        'feature_client_portal'          => 'Client portal',
    ];

    return $short[$key] ?? $defs[$key]['label'];
}

function getDashboardFeatureFlagStatusLabel(?PDO $pdo, string $key): string
{
    $key = getFeatureFlagKey($key);
    if ($key === 'feature_auto_approval') {
        return match (getAutoApprovalMode($pdo)) {
            2       => 'Live',
            1       => 'Shadow',
            default => 'Off',
        };
    }

    return isFeatureEnabled($pdo, $key) ? 'ON' : 'OFF';
}

function isDashboardFeatureFlagActive(?PDO $pdo, string $key): bool
{
    $key = getFeatureFlagKey($key);
    if ($key === 'feature_auto_approval') {
        return getAutoApprovalMode($pdo) > 0;
    }

    return isFeatureEnabled($pdo, $key);
}

/**
 * Toggle one flag from the dashboard corner (binary flip, auto-approval cycles Off → Shadow → Live).
 *
 * @return array{ok: bool, message: string}
 */
function toggleDashboardFeatureFlag(PDO $pdo, string $key): array
{
    $defs = getFeatureFlagDefinitions();
    $key  = getFeatureFlagKey($key);
    if (!isset($defs[$key])) {
        return ['ok' => false, 'message' => 'Unknown feature flag.'];
    }

    if ($key === 'feature_auto_approval') {
        $current = getFeatureFlagValue($pdo, $key);
        $next    = match ($current) {
            '0'     => '1',
            '1'     => '2',
            default => '0',
        };
        setSetting($pdo, $key, $next);
        $status = getDashboardFeatureFlagStatusLabel($pdo, $key);

        return [
            'ok'      => true,
            'message' => getDashboardFeatureFlagShortLabel($key) . ' set to ' . $status . '.',
        ];
    }

    $enabled = isFeatureEnabled($pdo, $key);
    setSetting($pdo, $key, $enabled ? '0' : '1');

    return [
        'ok'      => true,
        'message' => getDashboardFeatureFlagShortLabel($key) . ' ' . ($enabled ? 'OFF' : 'ON') . '.',
    ];
}

/**
 * Dashboard corner order — wired / high-touch flags first.
 *
 * @return list<string>
 */
function getDashboardFeatureFlagKeys(): array
{
    $preferred = [
        'feature_gps_attendance_v2',
        'feature_auto_approval',
        'feature_registration_wizard_v2',
        'feature_staff_pwa_v2',
        'feature_public_premium_v2',
        'feature_command_center_v2',
        'feature_unified_inbox',
        'feature_event_hub',
        'feature_trust_scores',
        'feature_ai_ops',
        'feature_ops_ui',
        'feature_live_ops',
        'feature_system_health_center',
        'feature_client_portal',
    ];
    $defs = getFeatureFlagDefinitions();
    $keys = [];
    foreach ($preferred as $key) {
        if (isset($defs[$key])) {
            $keys[] = $key;
        }
    }
    foreach (array_keys($defs) as $key) {
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }

    return $keys;
}
