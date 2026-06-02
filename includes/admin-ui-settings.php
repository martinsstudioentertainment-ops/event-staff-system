<?php

require_once __DIR__ . '/settings-repository.php';

/** @return array<string, string> */
function getAdminUiScaleOptions(): array
{
    return [
        'default'        => 'Default',
        'normal-compact' => 'Normal Compact',
        'compact'        => 'Compact',
        'ultra-compact'  => 'Ultra Compact',
    ];
}

/** @return array<string, string> */
function getAdminTableDensityOptions(): array
{
    return [
        'comfortable' => 'Comfortable',
        'normal'      => 'Normal',
        'compact'     => 'Compact',
    ];
}

/** @return array{ui_scale: string, card_padding: string, input_height: string, table_density: string, border_radius: string} */
function getAdminUiSettings(?PDO $pdo = null): array
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    $defaults = [
        'ui_scale'       => 'default',
        'card_padding'   => '20',
        'input_height'   => '40',
        'table_density'  => 'normal',
        'border_radius'  => '12',
    ];

    if ($pdo === null) {
        return $defaults;
    }

    return [
        'ui_scale'      => normalizeAdminUiScale(getSetting($pdo, 'admin_ui_scale', $defaults['ui_scale'])),
        'card_padding'  => normalizeAdminUiPx(getSetting($pdo, 'admin_ui_card_padding', $defaults['card_padding']), 12, 32, 20),
        'input_height'  => normalizeAdminUiPx(getSetting($pdo, 'admin_ui_input_height', $defaults['input_height']), 32, 56, 40),
        'table_density' => normalizeAdminTableDensity(getSetting($pdo, 'admin_ui_table_density', $defaults['table_density'])),
        'border_radius' => normalizeAdminUiPx(getSetting($pdo, 'admin_ui_border_radius', $defaults['border_radius']), 4, 24, 12),
    ];
}

function normalizeAdminUiScale(string $value): string
{
    return array_key_exists($value, getAdminUiScaleOptions()) ? $value : 'default';
}

function normalizeAdminTableDensity(string $value): string
{
    return array_key_exists($value, getAdminTableDensityOptions()) ? $value : 'normal';
}

function normalizeAdminUiPx(string $value, int $min, int $max, int $fallback): string
{
    $n = (int) preg_replace('/\D/', '', $value);

    if ($n < $min || $n > $max) {
        return (string) $fallback;
    }

    return (string) $n;
}

/** @param array<string, string> $input */
function validateAdminUiSettingsInput(array $input): ?string
{
    $scaleRaw = trim((string) ($input['ui_scale'] ?? ''));
    if (!array_key_exists($scaleRaw, getAdminUiScaleOptions())) {
        return 'Invalid UI scale selected.';
    }

    $densityRaw = trim((string) ($input['table_density'] ?? ''));
    if (!array_key_exists($densityRaw, getAdminTableDensityOptions())) {
        return 'Invalid table density selected.';
    }

    foreach (['card_padding' => [12, 32], 'input_height' => [32, 56], 'border_radius' => [4, 24]] as $field => [$min, $max]) {
        $n = (int) preg_replace('/\D/', '', trim((string) ($input[$field] ?? '')));
        if ($n < $min || $n > $max) {
            return ucfirst(str_replace('_', ' ', $field)) . " must be between {$min}px and {$max}px.";
        }
    }

    return null;
}

/** @param array<string, string> $input */
function saveAdminUiSettings(PDO $pdo, array $input): void
{
    saveSettings($pdo, [
        'admin_ui_scale'          => normalizeAdminUiScale(trim((string) ($input['ui_scale'] ?? 'default'))),
        'admin_ui_card_padding'   => normalizeAdminUiPx((string) ($input['card_padding'] ?? '20'), 12, 32, 20),
        'admin_ui_input_height'   => normalizeAdminUiPx((string) ($input['input_height'] ?? '40'), 32, 56, 40),
        'admin_ui_table_density'  => normalizeAdminTableDensity(trim((string) ($input['table_density'] ?? 'normal'))),
        'admin_ui_border_radius'  => normalizeAdminUiPx((string) ($input['border_radius'] ?? '12'), 4, 24, 12),
    ]);
}

function renderAdminUiBodyAttributes(?PDO $pdo = null, string $extraClass = ''): string
{
    $ui = getAdminUiSettings($pdo);
    $classes = trim('admin-shell erp-ui-scale-' . $ui['ui_scale'] . ' erp-table-density-' . $ui['table_density'] . ' ' . $extraClass);

    return sprintf(
        'class="%s" style="--erp-card-padding:%spx;--erp-input-height:%spx;--erp-border-radius:%spx;"',
        htmlspecialchars($classes, ENT_QUOTES, 'UTF-8'),
        (int) $ui['card_padding'],
        (int) $ui['input_height'],
        (int) $ui['border_radius']
    );
}
