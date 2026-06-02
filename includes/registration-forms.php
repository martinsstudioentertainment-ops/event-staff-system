<?php
/**
 * Role-specific registration forms — shareable URLs, managed from Admin → Registration Forms.
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/venues-repository.php';
require_once __DIR__ . '/work-types-repository.php';

/** @return array<string, array<string, mixed>> */
function getDefaultRegistrationForms(): array
{
    return [
        'dsp' => [
            'slug'               => 'dsp',
            'staff_role'         => 'dsp',
            'label'              => 'Door Supervisor (DSP)',
            'short_label'        => 'DSP',
            'role_hint'          => 'PSA door supervisor — events, clubs, festivals',
            'title'              => 'DSP Registration',
            'subtitle'           => 'For PSA-licensed Door Supervisors at events and venues.',
            'description'        => 'Choose your venue, then select DSP shifts you are available for.',
            'icon'               => 'shield',
            'enabled'            => true,
            'show_notice'        => true,
            'selection_mode'     => 'venue_first',
            'allowed_work_types' => ['nightclub', 'office', 'special_event', 'festival'],
        ],
        'static' => [
            'slug'               => 'static',
            'staff_role'         => 'static',
            'label'              => 'Static Security',
            'short_label'        => 'Static',
            'role_hint'          => 'Site / gate / ongoing static posts',
            'title'              => 'Static Guard Registration',
            'subtitle'           => 'For static and site security — not concert door work.',
            'description'        => 'Pick the site or venue, then choose static shifts.',
            'icon'               => 'shield',
            'enabled'            => true,
            'show_notice'        => true,
            'selection_mode'     => 'venue_first',
            'allowed_work_types' => ['nightclub', 'office', 'special_event', 'festival', 'static'],
        ],
        'both' => [
            'slug'               => 'both',
            'staff_role'         => 'both',
            'label'              => 'DSP & Static (Both)',
            'short_label'        => 'DSP + Static',
            'role_hint'          => 'Apply for DSP and static shifts on one form',
            'title'              => 'DSP & Static Registration',
            'subtitle'           => 'For staff who do both door supervisor and static/site work.',
            'description'        => 'Shows all DSP and static shifts. Choose venue, then tick what suits you.',
            'icon'               => 'shield',
            'enabled'            => true,
            'show_notice'        => true,
            'selection_mode'     => 'venue_first',
            'allowed_work_types' => ['nightclub', 'office', 'special_event', 'festival', 'static'],
        ],
        'steward' => [
            'slug'               => 'steward',
            'staff_role'         => 'steward',
            'label'              => 'Steward',
            'short_label'        => 'Steward',
            'title'              => 'Steward Registration',
            'subtitle'           => 'For crowd guidance and front-of-house at concerts, festivals, and venues.',
            'description'        => 'Select the venue, then tick the events or festival days you want to work as a steward.',
            'icon'               => 'steward',
            'enabled'            => true,
            'show_notice'        => true,
            'selection_mode'     => 'venue_first',
            'allowed_work_types' => ['special_event', 'festival'],
        ],
    ];
}

/** @return string[] */
function getStaffRoleValues(): array
{
    return ['dsp', 'static', 'both', 'steward', 'security'];
}

function normalizeStaffRole(string $role): string
{
    $role = strtolower(trim($role));

    if ($role === 'security' || $role === 'dsp-static' || $role === 'dsp_static') {
        return 'both';
    }

    return in_array($role, ['dsp', 'static', 'both', 'steward'], true) ? $role : 'dsp';
}

function staffRoleToFormSlug(string $role): string
{
    $role = normalizeStaffRole($role);

    return match ($role) {
        'static'  => 'static',
        'steward' => 'steward',
        'both'    => 'both',
        default   => 'dsp',
    };
}

/** @return string[] */
function getDefaultWorkTypesForFormSlug(string $slug): array
{
    return match ($slug) {
        'dsp'     => ['nightclub', 'office', 'special_event', 'festival'],
        'static'  => ['nightclub', 'office', 'special_event', 'festival', 'static'],
        'both'    => ['nightclub', 'office', 'special_event', 'festival', 'static'],
        'steward' => ['special_event', 'festival'],
        default   => ['special_event', 'nightclub', 'office', 'static', 'festival'],
    };
}

/**
 * @param array<string, mixed> $form
 * @return string[]
 */
function getFormAllowedWorkTypes(array $form): array
{
    $raw = $form['allowed_work_types'] ?? null;

    if (!is_array($raw) || $raw === []) {
        $slug = (string) ($form['slug'] ?? '');

        return getDefaultWorkTypesForFormSlug($slug);
    }

    $pdo = getDB();
    $includeSlugs = [];
    foreach ($raw as $type) {
        $type = trim((string) $type);
        if ($type !== '') {
            $includeSlugs[] = $type;
        }
    }
    $allowedKeys = array_keys(getWorkTypeOptionsForRegistrationForms($pdo, $includeSlugs));
    $filtered    = [];

    foreach ($raw as $type) {
        $type = trim((string) $type);
        if (in_array($type, $allowedKeys, true)) {
            $filtered[] = $type;
        }
    }

    if ($filtered === []) {
        $filtered = getDefaultWorkTypesForFormSlug((string) ($form['slug'] ?? ''));
    }

    $role = normalizeStaffRole((string) ($form['staff_role'] ?? $form['slug'] ?? ''));
    if (in_array($role, ['static', 'both'], true)) {
        $filtered = array_values(array_unique(array_merge(
            $filtered,
            ['nightclub', 'office', 'special_event', 'festival', 'static']
        )));
    }

    return $filtered;
}

function formatStaffRoleLabel(string $role): string
{
    return match (normalizeStaffRole($role)) {
        'dsp'     => 'Door Supervisor (DSP)',
        'static'  => 'Static Security',
        'both'    => 'DSP & Static (Both)',
        'steward' => 'Steward',
        default   => ucfirst($role),
    };
}

function registrationFormStaffRole(string $slug): string
{
    return match (strtolower(trim($slug))) {
        'steward' => 'steward',
        'static'  => 'static',
        'both'    => 'both',
        default   => 'dsp',
    };
}

/** @return array<string, array<string, mixed>> */
function getRegistrationForms(?PDO $pdo = null): array
{
    $defaults = getDefaultRegistrationForms();

    if ($pdo === null) {
        return $defaults;
    }

    $raw = trim(getSetting($pdo, 'registration_forms', ''));
    if ($raw === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $merged = [];
    foreach ($defaults as $slug => $default) {
        $saved = is_array($decoded[$slug] ?? null) ? $decoded[$slug] : [];
        $merged[$slug] = array_replace_recursive($default, $saved);
        $merged[$slug]['slug']       = $slug;
        $merged[$slug]['staff_role'] = registrationFormStaffRole($slug);
    }

    return $merged;
}

/** @param array<string, array<string, mixed>> $forms */
function saveRegistrationForms(PDO $pdo, array $forms): void
{
    $defaults = getDefaultRegistrationForms();
    $payload  = [];

    foreach ($defaults as $slug => $default) {
        $input = is_array($forms[$slug] ?? null) ? $forms[$slug] : [];
        $payload[$slug] = [
            'label'              => trim((string) ($input['label'] ?? $default['label'])),
            'short_label'        => trim((string) ($input['short_label'] ?? $default['short_label'])),
            'title'              => trim((string) ($input['title'] ?? $default['title'])),
            'subtitle'           => trim((string) ($input['subtitle'] ?? $default['subtitle'])),
            'description'        => trim((string) ($input['description'] ?? $default['description'])),
            'enabled'            => !empty($input['enabled']),
            'show_notice'        => !empty($input['show_notice']),
            'selection_mode'     => trim((string) ($input['selection_mode'] ?? $default['selection_mode'] ?? 'venue_first')),
            'allowed_work_types' => normalizeFormAllowedWorkTypes($input, $default),
        ];
    }

    setSetting($pdo, 'registration_forms', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** @return array<string, mixed>|null */
function getRegistrationForm(?PDO $pdo, string $slug): ?array
{
    $slug  = strtolower(trim($slug));
    $forms = getRegistrationForms($pdo);

    if (!isset($forms[$slug]) || empty($forms[$slug]['enabled'])) {
        return null;
    }

    return $forms[$slug];
}

/** @return array<string, array<string, mixed>> */
function getEnabledRegistrationForms(?PDO $pdo): array
{
    $order = ['dsp', 'static', 'both', 'steward'];
    $all   = getRegistrationForms($pdo);
    $out   = [];

    foreach ($order as $slug) {
        if (isset($all[$slug]) && !empty($all[$slug]['enabled'])) {
            $out[$slug] = $all[$slug];
        }
    }

    foreach ($all as $slug => $form) {
        if (!isset($out[$slug]) && !empty($form['enabled'])) {
            $out[$slug] = $form;
        }
    }

    return $out;
}

function registrationFormRedirectPath(array $query, ?string $formSlug = null): string
{
    $formSlug = strtolower(trim((string) $formSlug));
    if ($formSlug !== '') {
        $query['form'] = $formSlug;
    }

    $built = http_build_query($query);

    return 'index.php' . ($built !== '' ? '?' . $built : '');
}

/**
 * @param array<string, mixed> $data
 * @return array<string, string>
 */
function validateRegistrationFormContext(PDO $pdo, array $data): array
{
    $errors   = [];
    $formSlug = strtolower(trim((string) ($data['form_slug'] ?? '')));
    $staffRole = normalizeStaffRole(trim((string) ($data['staff_role'] ?? '')));

    if ($formSlug === '') {
        return $errors;
    }

    $form = getRegistrationForm($pdo, $formSlug);
    if ($form === null) {
        $errors['form_slug'] = 'This registration link is no longer available.';
        return $errors;
    }

    $expectedRole = normalizeStaffRole((string) $form['staff_role']);
    if ($staffRole !== '' && $staffRole !== $expectedRole) {
        $errors['staff_role'] = 'Role does not match this registration form.';
    }

    return $errors;
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed> $default
 * @return string[]
 */
function normalizeFormAllowedWorkTypes(array $input, array $default): array
{
    $raw = $input['allowed_work_types'] ?? $default['allowed_work_types'] ?? [];
    if (!is_array($raw)) {
        $raw = array_filter(array_map('trim', explode(',', (string) $raw)));
    }

    $pdo = getDB();
    $includeSlugs = [];
    if (is_array($raw)) {
        foreach ($raw as $type) {
            $type = trim((string) $type);
            if ($type !== '') {
                $includeSlugs[] = $type;
            }
        }
    }
    $allowedKeys = array_keys(getWorkTypeOptionsForRegistrationForms($pdo, $includeSlugs));
    $filtered    = [];

    foreach ($raw as $type) {
        $type = trim((string) $type);
        if (in_array($type, $allowedKeys, true)) {
            $filtered[] = $type;
        }
    }

    if ($filtered !== []) {
        return $filtered;
    }

    $slug = (string) ($default['slug'] ?? '');

    return getDefaultWorkTypesForFormSlug($slug);
}
