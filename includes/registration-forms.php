<?php
/**
 * Registration forms and staff roles — managed from Admin → Registration Forms.
 * Custom forms are stored in system_settings.registration_forms (JSON).
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/work-types-repository.php';

/** Built-in slugs (cannot delete; can edit/disable). */
function isBuiltinRegistrationFormSlug(string $slug): bool
{
    return in_array(normalizeRegistrationFormSlug($slug), ['dsp', 'static', 'both', 'steward'], true);
}

/** @return string[] */
function getReservedRegistrationFormSlugs(): array
{
    return [
        'admin', 'api', 'assets', 'check-in', 'checkin', 'cron', 'database', 'docs', 'home',
        'includes', 'index', 'lang', 'login', 'privacy', 'register', 'status', 'staff-app',
        'submit', 'vendor', 'storage',
    ];
}

function normalizeRegistrationFormSlug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = str_replace([' ', '-'], '_', $slug);
    $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?? '';

    return substr($slug, 0, 48);
}

function commissionRateSettingKey(string $role): string
{
    $role = normalizeStaffRole($role);
    if ($role === 'both') {
        return 'commission_rate_default';
    }

    return 'commission_rate_' . $role;
}

/** @return array<string, array<string, mixed>> */
function getDefaultRegistrationForms(): array
{
    return [
        'dsp' => blankRegistrationFormTemplate('dsp', [
            'label'              => 'Door Supervisor (DSP)',
            'short_label'        => 'DSP',
            'role_hint'          => 'PSA door supervisor — events, clubs, festivals',
            'title'              => 'DSP Registration',
            'subtitle'           => 'For PSA-licensed Door Supervisors at events and venues.',
            'description'        => 'Choose your venue, then select DSP shifts you are available for.',
            'allowed_work_types' => ['nightclub', 'office', 'special_event', 'festival'],
        ]),
        'static' => blankRegistrationFormTemplate('static', [
            'label'              => 'Static Security',
            'short_label'        => 'Static',
            'role_hint'          => 'PSA static / site security — gates, perimeter, posts',
            'title'              => 'Static Guard Registration',
            'subtitle'           => 'For PSA-licensed static and site security at events and venues.',
            'description'        => 'Pick the site or venue, then choose static shifts.',
            'allowed_work_types' => ['nightclub', 'office', 'special_event', 'festival', 'static'],
        ]),
        'both' => blankRegistrationFormTemplate('both', [
            'label'              => 'DSP & Static (Both)',
            'short_label'        => 'DSP + Static',
            'staff_role'         => 'both',
            'role_hint'          => 'PSA DSP and static — one form for all security shifts',
            'title'              => 'DSP & Static Registration',
            'subtitle'           => 'For PSA-licensed staff who do both door supervisor and static/site work.',
            'description'        => 'Shows all DSP and static shifts. Choose venue, then tick what suits you.',
            'allowed_work_types' => ['nightclub', 'office', 'special_event', 'festival', 'static'],
        ]),
        'steward' => blankRegistrationFormTemplate('steward', [
            'label'              => 'Steward',
            'short_label'        => 'Steward',
            'title'              => 'Steward Registration',
            'subtitle'           => 'Crowd and front-of-house roles at events.',
            'description'        => 'Choose your venue, then select steward shifts.',
            'icon'               => 'steward',
            'enabled'            => false,
            'allowed_work_types' => ['special_event', 'festival'],
        ]),
        'fire_marshal' => blankRegistrationFormTemplate('fire_marshal', [
            'label'              => 'Fire Marshal',
            'short_label'        => 'Fire Marshal',
            'role_hint'          => 'Fire safety and evacuation — events and festivals',
            'title'              => 'Fire Marshal Registration',
            'subtitle'           => 'For trained fire marshals at events and large venues.',
            'description'        => 'Choose your venue, then select fire marshal shifts you are available for.',
            'allowed_work_types' => ['special_event', 'festival'],
        ]),
    ];
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function blankRegistrationFormTemplate(string $slug, array $overrides = []): array
{
    $slug = normalizeRegistrationFormSlug($slug);

    return array_merge([
        'slug'               => $slug,
        'staff_role'         => $slug,
        'label'              => ucfirst(str_replace('_', ' ', $slug)),
        'short_label'        => ucfirst(str_replace('_', ' ', $slug)),
        'role_hint'          => '',
        'title'              => ucfirst(str_replace('_', ' ', $slug)) . ' Registration',
        'subtitle'           => '',
        'description'        => '',
        'icon'               => 'shield',
        'enabled'            => true,
        'show_notice'        => true,
        'selection_mode'     => 'venue_first',
        'allowed_work_types' => ['special_event', 'festival'],
        'sort_order'         => 100,
    ], $overrides, ['slug' => $slug]);
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed> $fallback
 * @return array<string, mixed>
 */
function normalizeRegistrationFormRecord(string $slug, array $input, array $fallback = []): array
{
    $slug = normalizeRegistrationFormSlug($slug);
    $base = blankRegistrationFormTemplate($slug, $fallback);

    $staffRole = normalizeRegistrationFormSlug((string) ($input['staff_role'] ?? $base['staff_role'] ?? $slug));
    if ($staffRole === '') {
        $staffRole = $slug;
    }

    return [
        'slug'               => $slug,
        'staff_role'         => $staffRole,
        'label'              => trim((string) ($input['label'] ?? $base['label'])),
        'short_label'        => trim((string) ($input['short_label'] ?? $base['short_label'])),
        'role_hint'          => trim((string) ($input['role_hint'] ?? $base['role_hint'] ?? '')),
        'title'              => trim((string) ($input['title'] ?? $base['title'])),
        'subtitle'           => trim((string) ($input['subtitle'] ?? $base['subtitle'])),
        'description'        => trim((string) ($input['description'] ?? $base['description'])),
        'icon'               => trim((string) ($input['icon'] ?? $base['icon'] ?? 'shield')),
        'enabled'            => array_key_exists('enabled', $input) ? !empty($input['enabled']) : !empty($base['enabled']),
        'show_notice'        => array_key_exists('show_notice', $input) ? !empty($input['show_notice']) : !empty($base['show_notice']),
        'selection_mode'     => 'venue_first',
        'allowed_work_types' => normalizeFormAllowedWorkTypes(
            $input,
            array_merge($base, ['slug' => $slug, 'staff_role' => $staffRole])
        ),
        'sort_order'         => (int) ($input['sort_order'] ?? $base['sort_order'] ?? 100),
    ];
}

function ensureRegistrationFormsSeeded(PDO $pdo): void
{
    if (trim(getSetting($pdo, 'registration_forms', '')) !== '') {
        return;
    }

    $payload = [];
    foreach (getDefaultRegistrationForms() as $slug => $form) {
        $payload[$slug] = registrationFormToStorage($form);
    }

    setSetting($pdo, 'registration_forms', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    clearSettingsCache();
}

/**
 * @param array<string, mixed> $form
 * @return array<string, mixed>
 */
function registrationFormToStorage(array $form): array
{
    return [
        'label'              => (string) ($form['label'] ?? ''),
        'short_label'        => (string) ($form['short_label'] ?? ''),
        'role_hint'          => (string) ($form['role_hint'] ?? ''),
        'title'              => (string) ($form['title'] ?? ''),
        'subtitle'           => (string) ($form['subtitle'] ?? ''),
        'description'        => (string) ($form['description'] ?? ''),
        'staff_role'         => (string) ($form['staff_role'] ?? $form['slug'] ?? ''),
        'enabled'            => !empty($form['enabled']),
        'show_notice'        => !empty($form['show_notice']),
        'selection_mode'     => (string) ($form['selection_mode'] ?? 'venue_first'),
        'allowed_work_types' => is_array($form['allowed_work_types'] ?? null) ? $form['allowed_work_types'] : [],
        'sort_order'         => (int) ($form['sort_order'] ?? 100),
    ];
}

/** @return array<string, array<string, mixed>> */
function getRegistrationForms(?PDO $pdo = null): array
{
    $defaults = getDefaultRegistrationForms();

    if ($pdo === null) {
        return $defaults;
    }

    ensureRegistrationFormsSeeded($pdo);

    $raw = trim(getSetting($pdo, 'registration_forms', ''));
    $stored = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($stored)) {
        $stored = [];
    }

    $slugs = array_unique(array_merge(array_keys($defaults), array_keys($stored)));
    $merged = [];

    foreach ($slugs as $slug) {
        $slug = normalizeRegistrationFormSlug((string) $slug);
        if ($slug === '') {
            continue;
        }
        $default = $defaults[$slug] ?? blankRegistrationFormTemplate($slug);
        $saved   = is_array($stored[$slug] ?? null) ? $stored[$slug] : [];
        $merged[$slug] = normalizeRegistrationFormRecord($slug, array_replace_recursive($default, $saved), $default);
    }

    uasort($merged, static function (array $a, array $b): int {
        $order = ((int) ($a['sort_order'] ?? 100)) <=> ((int) ($b['sort_order'] ?? 100));
        if ($order !== 0) {
            return $order;
        }

        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    return $merged;
}

/** Normalize role string only (no DB — safe inside getKnownStaffRoles). */
function normalizeStaffRoleString(string $role): string
{
    $role = strtolower(trim($role));
    $role = str_replace([' ', '-'], '_', $role);

    if ($role === 'security' || $role === 'dsp_static') {
        return 'both';
    }

    return $role;
}

/** @return string[] */
function getKnownStaffRoles(?PDO $pdo = null): array
{
    $pdo   = $pdo ?? getDB();
    $roles = ['both', 'security'];

    foreach (getRegistrationForms($pdo) as $slug => $form) {
        $role = normalizeStaffRoleString((string) ($form['staff_role'] ?? $slug));
        if ($role !== '') {
            $roles[] = $role;
        }
        $slugRole = normalizeStaffRoleString((string) $slug);
        if ($slugRole !== '') {
            $roles[] = $slugRole;
        }
    }

    return array_values(array_unique($roles));
}

/** Roles stored on each registration row and used on events (excludes "both"). */
/** @return string[] */
function getStaffRolesForEvents(?PDO $pdo = null): array
{
    $pdo   = $pdo ?? getDB();
    $roles = [];

    foreach (getRegistrationForms($pdo) as $slug => $form) {
        $role = normalizeStaffRoleString((string) ($form['staff_role'] ?? $slug));
        if ($role === '' || $role === 'both') {
            continue;
        }
        $roles[] = $role;
    }

    if ($roles === []) {
        return ['dsp', 'static'];
    }

    return array_values(array_unique($roles));
}

/** @return string[] */
function getStaffRoleValues(): array
{
    return getKnownStaffRoles();
}

function normalizeStaffRole(string $role, ?PDO $pdo = null): string
{
    $pdo  = $pdo ?? getDB();
    $role = normalizeStaffRoleString($role);

    if ($role === '') {
        return 'dsp';
    }

    if (in_array($role, getKnownStaffRoles($pdo), true)) {
        return $role;
    }

    foreach (getRegistrationForms($pdo) as $slug => $form) {
        $formRole = normalizeStaffRoleString((string) ($form['staff_role'] ?? $slug));
        if ($slug === $role || $formRole === $role) {
            return $formRole !== '' ? $formRole : 'dsp';
        }
    }

    return 'dsp';
}

function staffRoleToFormSlug(string $role, ?PDO $pdo = null): string
{
    $pdo  = $pdo ?? getDB();
    $role = normalizeStaffRole($role, $pdo);

    foreach (getRegistrationForms($pdo) as $slug => $form) {
        if (normalizeStaffRole((string) ($form['staff_role'] ?? ''), $pdo) === $role) {
            return $slug;
        }
    }

    return $role;
}

/** @return string[] */
function getDefaultWorkTypesForFormSlug(string $slug): array
{
    return ['special_event', 'festival', 'nightclub', 'office', 'static'];
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

function formatStaffRoleLabel(?string $role, ?PDO $pdo = null): string
{
    static $labelByRole = null;
    static $labelMapPdo = null;

    $pdo  = $pdo ?? getDB();
    $role = normalizeStaffRole((string) ($role ?? ''), $pdo);
    $pdoKey = spl_object_id($pdo);

    if ($labelByRole === null || $labelMapPdo !== $pdoKey) {
        $labelByRole = [];
        foreach (getRegistrationForms($pdo) as $form) {
            $formRole = normalizeStaffRole((string) ($form['staff_role'] ?? ''), $pdo);
            $label    = trim((string) ($form['label'] ?? ''));
            $labelByRole[$formRole] = $label !== '' ? $label : ucfirst(str_replace('_', ' ', $formRole));
        }
        $labelMapPdo = $pdoKey;
    }

    return $labelByRole[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

function registrationFormStaffRole(string $slug, ?PDO $pdo = null): string
{
    $pdo  = $pdo ?? getDB();
    $slug = normalizeRegistrationFormSlug($slug);
    $forms = getRegistrationForms($pdo);

    if (isset($forms[$slug])) {
        return normalizeStaffRole((string) ($forms[$slug]['staff_role'] ?? $slug), $pdo);
    }

    return normalizeStaffRole($slug, $pdo);
}

/** @param array<string, array<string, mixed>> $forms */
function saveRegistrationForms(PDO $pdo, array $forms): void
{
    $payload = [];

    foreach ($forms as $slug => $input) {
        $slug = normalizeRegistrationFormSlug((string) $slug);
        if ($slug === '') {
            continue;
        }
        if (!is_array($input)) {
            continue;
        }
        $existing = getRegistrationForms($pdo)[$slug] ?? blankRegistrationFormTemplate($slug);
        $record   = normalizeRegistrationFormRecord($slug, $input, $existing);
        $payload[$slug] = registrationFormToStorage($record);
    }

    setSetting($pdo, 'registration_forms', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    clearSettingsCache();
}

/**
 * @return array{ok: bool, message: string, slug?: string}
 */
function createRegistrationForm(PDO $pdo, string $slug, array $input): array
{
    $slug = normalizeRegistrationFormSlug($slug);
    if ($slug === '') {
        return ['ok' => false, 'message' => 'Enter a form ID using letters, numbers, and underscores (e.g. fire_marshal).'];
    }

    if (in_array($slug, getReservedRegistrationFormSlugs(), true)) {
        return ['ok' => false, 'message' => 'That form ID is reserved. Choose another (e.g. fire_marshal, first_aider).'];
    }

    $forms = getRegistrationForms($pdo);
    if (isset($forms[$slug])) {
        return ['ok' => false, 'message' => 'A form with that ID already exists.'];
    }

    $label = trim((string) ($input['label'] ?? ''));
    if ($label === '') {
        return ['ok' => false, 'message' => 'Role label is required.'];
    }

    $record = normalizeRegistrationFormRecord($slug, array_merge($input, [
        'label'       => $label,
        'short_label' => trim((string) ($input['short_label'] ?? '')) ?: $label,
        'title'       => trim((string) ($input['title'] ?? '')) ?: ($label . ' Registration'),
        'enabled'     => !empty($input['enabled']),
    ]), blankRegistrationFormTemplate($slug));

    $forms[$slug] = $record;
    saveRegistrationForms($pdo, $forms);

    return ['ok' => true, 'message' => 'Registration form created.', 'slug' => $slug];
}

/**
 * @return array{ok: bool, message: string}
 */
function deleteRegistrationForm(PDO $pdo, string $slug): array
{
    $slug = normalizeRegistrationFormSlug($slug);
    if ($slug === '' || isBuiltinRegistrationFormSlug($slug)) {
        return ['ok' => false, 'message' => 'Built-in forms cannot be deleted — disable them instead.'];
    }

    $forms = getRegistrationForms($pdo);
    if (!isset($forms[$slug])) {
        return ['ok' => false, 'message' => 'Form not found.'];
    }

    unset($forms[$slug]);
    saveRegistrationForms($pdo, $forms);

    return ['ok' => true, 'message' => 'Form removed.'];
}

/** @return array<string, mixed>|null */
function getRegistrationForm(?PDO $pdo, string $slug): ?array
{
    $slug  = normalizeRegistrationFormSlug($slug);
    $forms = getRegistrationForms($pdo);

    if (!isset($forms[$slug]) || empty($forms[$slug]['enabled'])) {
        return null;
    }

    return $forms[$slug];
}

/** @return array<string, array<string, mixed>> */
function getEnabledRegistrationForms(?PDO $pdo): array
{
    $all = getRegistrationForms($pdo);
    $out = [];

    foreach ($all as $slug => $form) {
        if (!empty($form['enabled'])) {
            $out[$slug] = $form;
        }
    }

    return $out;
}

function registrationFormRedirectPath(array $query, ?string $formSlug = null): string
{
    $formSlug = normalizeRegistrationFormSlug((string) $formSlug);
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
    $errors    = [];
    $formSlug  = normalizeRegistrationFormSlug((string) ($data['form_slug'] ?? ''));
    $staffRole = normalizeStaffRole(trim((string) ($data['staff_role'] ?? '')), $pdo);

    if ($formSlug === '') {
        return $errors;
    }

    $form = getRegistrationForm($pdo, $formSlug);
    if ($form === null) {
        $errors['form_slug'] = 'This registration link is no longer available.';

        return $errors;
    }

    $expectedRole = normalizeStaffRole((string) $form['staff_role'], $pdo);
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

/** Build save payload from current forms (for partial edits). */
/** @return array<string, array<string, mixed>> */
function registrationFormsToSavePayload(array $forms): array
{
    $payload = [];
    foreach ($forms as $slug => $form) {
        $payload[$slug] = registrationFormToStorage($form);
    }

    return $payload;
}
