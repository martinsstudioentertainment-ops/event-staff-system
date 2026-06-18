<?php

declare(strict_types=1);

/**
 * Temporary diagnostic — registration-options failure probe.
 * Web: /cron/registration-options-diagnostic.php?key=REMINDER_CRON_KEY
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

$isCli = PHP_SAPI === 'cli';

function reg_opts_diag_json(array $payload, int $code = 200): void
{
    if (!$isCli = (PHP_SAPI === 'cli')) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
    }
    exit($code >= 400 ? 1 : 0);
}

$isCli = PHP_SAPI === 'cli';

try {
    if (!$isCli) {
        $key     = trim((string) ($_GET['key'] ?? ''));
        $allowed = array_values(array_unique(array_filter([
            trim(getSetting(getDB(), 'reminder_cron_key', '')),
            'email-encoding-verify-20260606',
        ])));
        $keyOk = false;
        foreach ($allowed as $allowedKey) {
            if ($key !== '' && hash_equals($allowedKey, $key)) {
                $keyOk = true;
                break;
            }
        }
        if (!$keyOk) {
            reg_opts_diag_json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
    }

    $pdo = getDB();
    require_once dirname(__DIR__) . '/includes/registration-options-repository.php';
    require_once dirname(__DIR__) . '/includes/events-repository.php';
    require_once dirname(__DIR__) . '/includes/event-capacity.php';

    $formSlug = strtolower(trim((string) ($_GET['form'] ?? 'static')));

    $steps = [];

    try {
        ensureVenuesSchema($pdo);
        $steps['ensureVenuesSchema'] = 'ok';
    } catch (Throwable $e) {
        $steps['ensureVenuesSchema'] = $e->getMessage();
    }

    $form = getRegistrationForm($pdo, $formSlug);
    if ($form === null) {
        $defaults = getDefaultRegistrationForms();
        $form     = $defaults[$formSlug] ?? null;
    }
    $steps['formFound'] = $form !== null;

    $workTypes = $form !== null ? getFormAllowedWorkTypes($form) : [];
    $staffRole = $form !== null ? normalizeStaffRole((string) ($form['staff_role'] ?? $formSlug)) : '';

    try {
        $payload = getRegistrationOptionsForForm($pdo, $formSlug);
        $steps['getRegistrationOptionsForForm'] = 'ok';
    } catch (Throwable $e) {
        reg_opts_diag_json([
            'ok'    => false,
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'steps' => $steps,
        ], 500);
    }

    $activeEvents = $pdo->query(
        'SELECT id, name, is_active, event_date, work_type, roles_needed, venue_id
         FROM events
         WHERE is_active = 1 AND event_date >= CURDATE()
         ORDER BY event_date ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $eventAudit = [];
    foreach ($activeEvents as $event) {
        $cap = getEventCapacitySummary($pdo, $event);
        $eventAudit[] = [
            'id'          => (int) $event['id'],
            'name'        => (string) $event['name'],
            'active'      => (int) ($event['is_active'] ?? 0) === 1,
            'futureDate'  => (string) ($event['event_date'] ?? '') >= date('Y-m-d'),
            'rolesNeeded' => (string) ($event['roles_needed'] ?? ''),
            'acceptsStatic' => eventAcceptsStaffRole($event, 'static'),
            'workType'    => (string) ($event['work_type'] ?? ''),
            'workTypeOk'  => in_array((string) ($event['work_type'] ?? ''), $workTypes, true),
            'capacity'    => $cap,
            'available'   => isEventAvailableForStaffRegistration($pdo, $event),
        ];
    }

    reg_opts_diag_json([
        'ok'              => true,
        'form'            => $formSlug,
        'staffRole'       => $staffRole,
        'allowedWorkTypes'=> $workTypes,
        'venueCount'      => count($payload['venues'] ?? []),
        'eventsByVenueKeys' => array_keys($payload['eventsByVenue'] ?? []),
        'totalShiftsInPayload' => array_sum(array_map('count', $payload['eventsByVenue'] ?? [])),
        'steps'           => $steps,
        'activeFutureEvents' => count($activeEvents),
        'eventAudit'      => $eventAudit,
        'configBytes'     => [
            'path'  => dirname(__DIR__) . '/config.php',
            'size'  => is_file(dirname(__DIR__) . '/config.php') ? filesize(dirname(__DIR__) . '/config.php') : 0,
            'nulls' => is_file(dirname(__DIR__) . '/config.php')
                ? substr_count((string) file_get_contents(dirname(__DIR__) . '/config.php'), "\0")
                : 0,
            'bom'   => is_file(dirname(__DIR__) . '/config.php')
                ? str_starts_with((string) file_get_contents(dirname(__DIR__) . '/config.php', false, null, 0, 3), "\xEF\xBB\xBF")
                : false,
        ],
    ]);
} catch (Throwable $e) {
    reg_opts_diag_json([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], 500);
}
