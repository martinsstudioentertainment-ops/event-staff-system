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
            'title'              => 'DSP Registration',
            'subtitle'           => 'For PSA-licensed Door Supervisors — nightclub, office, and special event work.',
            'description'        => 'Choose your venue first, then select nightclub shifts, office security, or special events you are available for.',
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
            'title'              => 'Static Guard Registration',
            'subtitle'           => 'For ongoing static / site security — not concert or gig events.',
            'description'        => 'Pick the venue or site, then choose available static shifts. This form does not list concerts or festival gigs.',
            'icon'               => 'shield',
            'enabled'            => true,
            'show_notice'        => true,
            'selection_mode'     => 'venue_first',
            'allowed_work_types' => ['static'],
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
    return ['dsp', 'static', 'steward', 'security'];
}

function normalizeStaffRole(string $role): string
{
    $role = strtolower(trim($role));

    return $role === 'security' ? 'dsp' : $role;
}

function staffRoleToFormSlug(string $role): string
{
    $role = normalizeStaffRole($role);

    return in_array($role, ['dsp', 'static', 'steward'], true) ? $role : 'dsp';
}

/** @return string[] */
function getDefaultWorkTypesForFormSlug(string $slug): array
{
    return match ($slug) {
        'dsp'     => ['nightclub', 'office', 'special_event', 'festival'],
        'static'  => ['static'],
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

    return $filtered !== [] ? $filtered : getDefaultWorkTypesForFormSlug((string) ($form['slug'] ?? ''));
}

function formatStaffRoleLabel(string $role): string
{
    return match (normalizeStaffRole($role)) {
        'dsp'     => 'Door Supervisor (DSP)',
        'static'  => 'Static Security',
        'steward' => 'Steward',
        default   => ucfirst($role),
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
        $merged[$slug]['staff_role'] = $slug === 'steward' ? 'steward' : ($slug === 'static' ? 'static' : 'dsp');
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
    return array_filter(
        getRegistrationForms($pdo),
        static fn(array $form): bool => !empty($form['enabled'])
    );
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
