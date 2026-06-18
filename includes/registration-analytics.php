<?php

declare(strict_types=1);

/**
 * Registration funnel analytics — file-based, non-destructive, no DB changes.
 * Active only when feature_registration_wizard_v2 is enabled.
 */

require_once __DIR__ . '/feature-flags.php';

const REGISTRATION_ANALYTICS_SUMMARY_FILE = 'summary.json';
const REGISTRATION_ANALYTICS_SESSION_TTL  = 604800; // 7 days

/** @return list<string> */
function getRegistrationAnalyticsEventTypes(): array
{
    return [
        'registration_started',
        'step_reached',
        'registration_submitted',
        'registration_abandoned',
        'event_selected',
        'returning_user_detected',
        'profile_prefilled',
        'resume_selected',
        'new_application_started',
        'duplicate_application_prevented',
    ];
}

function getRegistrationAnalyticsRoot(?string $root = null): string
{
    $root = $root ?? dirname(__DIR__);

    return $root . '/storage/analytics/registration-funnel';
}

function ensureRegistrationAnalyticsDirs(?string $root = null): void
{
    $base = getRegistrationAnalyticsRoot($root);
    foreach ([$base, $base . '/sessions', $base . '/daily'] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}

/** @return array<string, mixed> */
function getEmptyRegistrationAnalyticsSummary(): array
{
    $steps = [];
    for ($i = 1; $i <= 8; $i++) {
        $steps[(string) $i] = 0;
    }

    return [
        'updated_at' => gmdate('c'),
        'started'    => 0,
        'submitted'  => 0,
        'abandoned'  => 0,
        'step_reached' => $steps,
        'conversions' => [
            'step1_to_step2'  => 0,
            'step2_to_step8'  => 0,
            'step8_to_submit' => 0,
        ],
        'event_selections' => [],
        'returning_user_detected'           => 0,
        'profile_prefilled'                 => 0,
        'resume_selected'                   => 0,
        'new_application_started'           => 0,
        'duplicate_application_prevented'   => 0,
    ];
}

/** @return array<string, mixed> */
function readRegistrationAnalyticsSummary(?string $root = null): array
{
    ensureRegistrationAnalyticsDirs($root);
    $path = getRegistrationAnalyticsRoot($root) . '/' . REGISTRATION_ANALYTICS_SUMMARY_FILE;

    if (!is_file($path)) {
        return getEmptyRegistrationAnalyticsSummary();
    }

    $raw  = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($data)) {
        return getEmptyRegistrationAnalyticsSummary();
    }

    return array_merge(getEmptyRegistrationAnalyticsSummary(), $data);
}

/** @param array<string, mixed> $summary */
function writeRegistrationAnalyticsSummary(array $summary, ?string $root = null): void
{
    ensureRegistrationAnalyticsDirs($root);
    $summary['updated_at'] = gmdate('c');
    $path = getRegistrationAnalyticsRoot($root) . '/' . REGISTRATION_ANALYTICS_SUMMARY_FILE;
    $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Could not encode registration analytics summary.');
    }

    file_put_contents($path, $json, LOCK_EX);
}

/** @return array<string, mixed> */
function readRegistrationAnalyticsSession(string $sessionId, ?string $root = null): array
{
    $path = getRegistrationAnalyticsRoot($root) . '/sessions/' . $sessionId . '.json';

    if (!is_file($path)) {
        return [
            'session_id'   => $sessionId,
            'started_at'   => gmdate('c'),
            'max_step'     => 0,
            'visited_step2' => false,
            'visited_step8' => false,
            'submitted'    => false,
            'abandoned'    => false,
        ];
    }

    $raw  = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($data) ? $data : [];
}

/** @param array<string, mixed> $session */
function writeRegistrationAnalyticsSession(string $sessionId, array $session, ?string $root = null): void
{
    ensureRegistrationAnalyticsDirs($root);
    $path = getRegistrationAnalyticsRoot($root) . '/sessions/' . $sessionId . '.json';
    $json = json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return;
    }

    file_put_contents($path, $json, LOCK_EX);
}

function pruneOldRegistrationAnalyticsSessions(?string $root = null): void
{
    $dir = getRegistrationAnalyticsRoot($root) . '/sessions';
    if (!is_dir($dir)) {
        return;
    }

    $cutoff = time() - REGISTRATION_ANALYTICS_SESSION_TTL;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (@filemtime($file) !== false && filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok: bool, message: string}
 */
function recordRegistrationAnalyticsEvent(
    ?PDO $pdo,
    string $eventType,
    array $payload,
    ?string $root = null
): array {
    if (!isFeatureEnabled($pdo, 'feature_registration_wizard_v2')) {
        return ['ok' => false, 'message' => 'Analytics disabled (wizard flag off).'];
    }

    $eventType = trim($eventType);
    if (!in_array($eventType, getRegistrationAnalyticsEventTypes(), true)) {
        return ['ok' => false, 'message' => 'Unknown event type.'];
    }

    $sessionId = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($payload['session_id'] ?? '')));
    if ($sessionId === '' || strlen($sessionId) < 16) {
        return ['ok' => false, 'message' => 'Invalid session id.'];
    }

    $root = $root ?? dirname(__DIR__);
    ensureRegistrationAnalyticsDirs($root);

    $summary = readRegistrationAnalyticsSummary($root);
    $session = readRegistrationAnalyticsSession($sessionId, $root);

    if ($eventType === 'registration_started') {
        if (empty($session['counted_start'])) {
            $summary['started'] = (int) ($summary['started'] ?? 0) + 1;
            $session['counted_start'] = true;
            $session['max_step'] = max((int) ($session['max_step'] ?? 0), 1);
        }
    }

    if ($eventType === 'step_reached') {
        $step = max(1, min(8, (int) ($payload['step'] ?? 0)));
        $key  = (string) $step;
        $summary['step_reached'][$key] = (int) ($summary['step_reached'][$key] ?? 0) + 1;

        $prevMax = (int) ($session['max_step'] ?? 0);
        if ($step === 2 && $prevMax >= 1 && empty($session['converted_1_2'])) {
            $summary['conversions']['step1_to_step2'] = (int) ($summary['conversions']['step1_to_step2'] ?? 0) + 1;
            $session['converted_1_2'] = true;
        }
        if ($step === 8 && !empty($session['visited_step2']) && empty($session['converted_2_8'])) {
            $summary['conversions']['step2_to_step8'] = (int) ($summary['conversions']['step2_to_step8'] ?? 0) + 1;
            $session['converted_2_8'] = true;
        }

        if ($step === 2) {
            $session['visited_step2'] = true;
        }
        if ($step === 8) {
            $session['visited_step8'] = true;
        }
        $session['max_step'] = max($prevMax, $step);
    }

    if ($eventType === 'registration_submitted') {
        if (empty($session['submitted'])) {
            $summary['submitted'] = (int) ($summary['submitted'] ?? 0) + 1;
            $session['submitted'] = true;
            $reachedReview = !empty($session['visited_step8'])
                || (int) ($session['max_step'] ?? 0) >= 8;
            if ($reachedReview && empty($session['converted_8_submit'])) {
                $summary['conversions']['step8_to_submit'] = (int) ($summary['conversions']['step8_to_submit'] ?? 0) + 1;
                $session['converted_8_submit'] = true;
            }
        }
    }

    if ($eventType === 'registration_abandoned') {
        if (empty($session['submitted']) && empty($session['abandoned'])) {
            $summary['abandoned'] = (int) ($summary['abandoned'] ?? 0) + 1;
            $session['abandoned'] = true;
            $session['abandon_step'] = max(1, min(8, (int) ($payload['last_step'] ?? ($session['max_step'] ?? 1))));
        }
    }

    if ($eventType === 'event_selected') {
        $eventId = (int) ($payload['event_id'] ?? 0);
        if ($eventId > 0) {
            $key = (string) $eventId;
            $summary['event_selections'][$key] = (int) ($summary['event_selections'][$key] ?? 0) + 1;
        }
    }

    $counterAliases = [
        'returning_user_detected'         => ['returning_user_detected'],
        'profile_prefilled'               => ['profile_prefilled', 'prefill_used'],
        'resume_selected'                 => ['resume_selected', 'resume_used'],
        'new_application_started'         => ['new_application_started'],
        'duplicate_application_prevented' => ['duplicate_application_prevented'],
    ];
    foreach ($counterAliases as $counterKey => $aliases) {
        if (in_array($eventType, $aliases, true)) {
            $summary[$counterKey] = (int) ($summary[$counterKey] ?? 0) + 1;
        }
    }

    writeRegistrationAnalyticsSession($sessionId, $session, $root);
    writeRegistrationAnalyticsSummary($summary, $root);

    $dayFile = getRegistrationAnalyticsRoot($root) . '/daily/' . gmdate('Y-m-d') . '.jsonl';
    $line    = json_encode([
        'ts'         => gmdate('c'),
        'event'      => $eventType,
        'session_id' => $sessionId,
        'payload'    => $payload,
    ], JSON_UNESCAPED_SLASHES);

    if ($line !== false) {
        file_put_contents($dayFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    if (random_int(1, 50) === 1) {
        pruneOldRegistrationAnalyticsSessions($root);
    }

    return ['ok' => true, 'message' => 'Recorded.'];
}

/**
 * @return array<string, mixed>
 */
function getRegistrationFunnelMetrics(?PDO $pdo, ?string $root = null): array
{
    $summary = readRegistrationAnalyticsSummary($root);
    $started = max(1, (int) ($summary['started'] ?? 0));

    $conv = $summary['conversions'] ?? [];
    $pct  = static function (int $count, int $base): float {
        return $base > 0 ? round(($count / $base) * 100, 1) : 0.0;
    };

    $eventSelections = $summary['event_selections'] ?? [];
    arsort($eventSelections);
    $topEvents = [];
    foreach (array_slice($eventSelections, 0, 8, true) as $eventId => $count) {
        $name = 'Event #' . $eventId;
        if ($pdo !== null) {
            try {
                require_once __DIR__ . '/events-repository.php';
                $event = getEventById($pdo, (int) $eventId);
                if ($event !== null && trim((string) ($event['name'] ?? '')) !== '') {
                    $name = (string) $event['name'];
                }
            } catch (Throwable $e) {
                // keep fallback label
            }
        }
        $topEvents[] = [
            'event_id' => (int) $eventId,
            'name'     => $name,
            'count'    => (int) $count,
        ];
    }

    return [
        'flag_enabled'  => isFeatureEnabled($pdo, 'feature_registration_wizard_v2'),
        'updated_at'    => (string) ($summary['updated_at'] ?? ''),
        'started'       => (int) ($summary['started'] ?? 0),
        'submitted'     => (int) ($summary['submitted'] ?? 0),
        'abandoned'     => (int) ($summary['abandoned'] ?? 0),
        'step_reached'  => $summary['step_reached'] ?? [],
        'conversions'   => [
            'step1_to_step2'  => [
                'count' => (int) ($conv['step1_to_step2'] ?? 0),
                'rate'  => $pct((int) ($conv['step1_to_step2'] ?? 0), $started),
            ],
            'step2_to_step8'  => [
                'count' => (int) ($conv['step2_to_step8'] ?? 0),
                'rate'  => $pct((int) ($conv['step2_to_step8'] ?? 0), max(1, (int) ($summary['step_reached']['2'] ?? 0))),
            ],
            'step8_to_submit' => [
                'count' => (int) ($conv['step8_to_submit'] ?? 0),
                'rate'  => $pct((int) ($conv['step8_to_submit'] ?? 0), max(1, (int) ($summary['step_reached']['8'] ?? 0))),
            ],
        ],
        'completion_rate' => $pct((int) ($summary['submitted'] ?? 0), $started),
        'top_events'      => $topEvents,
        'returning'       => [
            'returning_user_detected'         => (int) ($summary['returning_user_detected'] ?? 0),
            'profile_prefilled'               => (int) ($summary['profile_prefilled'] ?? 0),
            'resume_selected'                 => (int) ($summary['resume_selected'] ?? 0),
            'new_application_started'         => (int) ($summary['new_application_started'] ?? 0),
            'duplicate_application_prevented' => (int) ($summary['duplicate_application_prevented'] ?? 0),
        ],
    ];
}

function createRegistrationAnalyticsSessionId(): string
{
    return bin2hex(random_bytes(16));
}
