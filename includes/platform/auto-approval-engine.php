<?php

declare(strict_types=1);

require_once __DIR__ . '/platform-schema.php';
require_once __DIR__ . '/../feature-flags.php';
require_once __DIR__ . '/../settings-repository.php';
require_once __DIR__ . '/../staff-repository.php';
require_once __DIR__ . '/../staff-onboarding.php';
require_once __DIR__ . '/../staff-blacklist.php';
require_once __DIR__ . '/../audit-log.php';
require_once __DIR__ . '/../google-sheets-sync.php';
require_once __DIR__ . '/../apply-remote-sync.php';
require_once __DIR__ . '/../notifications.php';
require_once __DIR__ . '/../attendance-repository.php';

/** @return array<string, mixed> */
function getAutoApprovalSettings(PDO $pdo): array
{
    $defaults = [
        'rule_returning_staff'       => '1',
        'rule_previously_approved'   => '1',
        'rule_complete_profile'      => '1',
        'rule_verified_psa'          => '1',
        'rule_reject_blacklist'      => '1',
        'rule_reject_duplicate'      => '1',
        'min_confidence'             => '35',
        'event_overrides_json'       => '{}',
    ];

    $out = [];
    foreach ($defaults as $key => $default) {
        $out[$key] = getSetting($pdo, 'auto_approval_' . $key, $default);
    }

    return $out;
}

/** @param array<string, string> $input */
function saveAutoApprovalSettings(PDO $pdo, array $input): void
{
    $keys = [
        'rule_returning_staff', 'rule_previously_approved', 'rule_complete_profile',
        'rule_verified_psa', 'rule_reject_blacklist', 'rule_reject_duplicate',
    ];
    foreach ($keys as $key) {
        setSetting($pdo, 'auto_approval_' . $key, !empty($input[$key]) ? '1' : '0');
    }

    $min = max(0, min(100, (int) ($input['min_confidence'] ?? 35)));
    setSetting($pdo, 'auto_approval_min_confidence', (string) $min);

    $overrides = trim((string) ($input['event_overrides_json'] ?? '{}'));
    if ($overrides === '' || json_decode($overrides, true) === null) {
        $overrides = '{}';
    }
    setSetting($pdo, 'auto_approval_event_overrides_json', $overrides);
}

function staffHasPreviouslyApprovedRegistration(PDO $pdo, string $email, int $excludeRegId = 0): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $sql = "SELECT COUNT(*) FROM staff_registrations
            WHERE LOWER(email) = LOWER(:email) AND status = 'approved'";
    $params = ['email' => $email];
    if ($excludeRegId > 0) {
        $sql .= ' AND id != :exclude';
        $params['exclude'] = $excludeRegId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

function staffIsReturningApplicant(PDO $pdo, string $email, int $excludeRegId = 0): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $sql = "SELECT COUNT(*) FROM staff_registrations WHERE LOWER(email) = LOWER(:email)";
    $params = ['email' => $email];
    if ($excludeRegId > 0) {
        $sql .= ' AND id != :exclude';
        $params['exclude'] = $excludeRegId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return array{decision: string, confidence: int, rules: list<string>, reason: string}
 */
function evaluateRegistrationForAutoApproval(PDO $pdo, int $registrationId): array
{
    $settings = getAutoApprovalSettings($pdo);
    $row      = getStaffRegistrationById($pdo, $registrationId);
    if ($row === null) {
        return ['decision' => 'skip', 'confidence' => 0, 'rules' => [], 'reason' => 'Registration not found'];
    }

    $email   = strtolower(trim((string) ($row['email'] ?? '')));
    $eventId = (int) ($row['event_id'] ?? 0);
    $rules   = [];
    $score   = 0;

    $overrides = json_decode((string) $settings['event_overrides_json'], true);
    if (is_array($overrides) && isset($overrides[(string) $eventId])) {
        $eventMode = (string) $overrides[(string) $eventId];
        if ($eventMode === 'off') {
            return ['decision' => 'skip', 'confidence' => 0, 'rules' => ['event_override_off'], 'reason' => 'Auto approval disabled for this event'];
        }
        if ($eventMode === 'strict') {
            $settings['min_confidence'] = '90';
        }
    }

    if ($settings['rule_reject_blacklist'] === '1' && isEmailBlacklisted($pdo, $email)) {
        return ['decision' => 'reject', 'confidence' => 100, 'rules' => ['blacklisted'], 'reason' => 'Staff is blacklisted'];
    }

    if ($settings['rule_reject_duplicate'] === '1') {
        $eventId = (int) ($row['event_id'] ?? 0);
        $stmt    = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_registrations
             WHERE LOWER(email) = LOWER(:email) AND event_id = :event_id
               AND id != :id AND status IN ('approved', 'pending')"
        );
        $stmt->execute(['email' => $email, 'event_id' => $eventId, 'id' => $registrationId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return ['decision' => 'reject', 'confidence' => 100, 'rules' => ['duplicate'], 'reason' => 'Duplicate registration for event'];
        }
    }

    if ($settings['rule_returning_staff'] === '1' && staffIsReturningApplicant($pdo, $email, $registrationId)) {
        $score += 35;
        $rules[] = 'returning_staff';
    }

    if ($settings['rule_previously_approved'] === '1' && staffHasPreviouslyApprovedRegistration($pdo, $email, $registrationId)) {
        $score += 30;
        $rules[] = 'previously_approved';
    }

    $merged = mergeRegistrationWithStaff($pdo, $row);
    if ($settings['rule_complete_profile'] === '1' && isStaffOnboardingComplete($merged)) {
        $score += 20;
        $rules[] = 'complete_profile';
    }

    if ($settings['rule_verified_psa'] === '1') {
        $psaOk = trim((string) ($merged['psa_licence'] ?? '')) !== ''
            && trim((string) ($merged['psa_front_image'] ?? '')) !== ''
            && trim((string) ($merged['psa_back_image'] ?? '')) !== '';
        if ($psaOk) {
            $score += 15;
            $rules[] = 'verified_psa';
        }
    }

    $minConfidence = max(0, min(100, (int) $settings['min_confidence']));
    if ($score >= $minConfidence) {
        return [
            'decision'   => 'approve',
            'confidence' => min(100, $score),
            'rules'      => $rules,
            'reason'     => 'Confidence ' . $score . '% meets threshold ' . $minConfidence . '%',
        ];
    }

    return [
        'decision'   => 'skip',
        'confidence' => $score,
        'rules'      => $rules,
        'reason'     => 'Confidence ' . $score . '% below threshold ' . $minConfidence . '%',
    ];
}

function logAutoApprovalDecision(PDO $pdo, int $registrationId, array $evaluation, int $mode, bool $applied): void
{
    ensurePlatformMaturitySchema($pdo);
    $row = getStaffRegistrationById($pdo, $registrationId);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO platform_auto_approval_log
                (registration_id, email, event_id, decision, confidence, mode, rules_json, applied)
            VALUES
                (:reg_id, :email, :event_id, :decision, :confidence, :mode, :rules_json, :applied)
        ");
        $stmt->execute([
            'reg_id'     => $registrationId,
            'email'      => strtolower(trim((string) ($row['email'] ?? ''))),
            'event_id'   => (int) ($row['event_id'] ?? 0) ?: null,
            'decision'   => $evaluation['decision'],
            'confidence' => (int) $evaluation['confidence'],
            'mode'       => match ($mode) {
                2       => 'live',
                1       => 'shadow',
                default => 'off',
            },
            'rules_json' => json_encode($evaluation['rules'] ?? [], JSON_THROW_ON_ERROR),
            'applied'    => $applied ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] auto approval log: ' . $e->getMessage());
    }
}

/**
 * @param int[] $registrationIds
 * @return array{processed: int, approved: int, rejected: int, shadow: int, skipped: int}
 */
function processAutoApprovalForRegistrations(PDO $pdo, array $registrationIds): array
{
    $mode = getAutoApprovalMode($pdo);
    $stats = ['processed' => 0, 'approved' => 0, 'rejected' => 0, 'shadow' => 0, 'skipped' => 0];

    if ($mode === 0) {
        return $stats;
    }

    foreach ($registrationIds as $regId) {
        $regId = (int) $regId;
        if ($regId < 1) {
            continue;
        }

        $stats['processed']++;
        $evaluation = evaluateRegistrationForAutoApproval($pdo, $regId);

        if ($evaluation['decision'] === 'skip') {
            $stats['skipped']++;
            if ($mode === 1) {
                logAutoApprovalDecision($pdo, $regId, $evaluation, $mode, false);
                $stats['shadow']++;
            }
            continue;
        }

        if ($mode === 1) {
            logAutoApprovalDecision($pdo, $regId, $evaluation, $mode, false);
            $stats['shadow']++;
            continue;
        }

        $status = $evaluation['decision'] === 'approve' ? 'approved' : 'rejected';
        if (!updateStaffStatus($pdo, $regId, $status, false)) {
            $stats['skipped']++;
            logAutoApprovalDecision($pdo, $regId, $evaluation, $mode, false);
            continue;
        }

        try {
            if ($status === 'approved') {
                ensureCheckinToken($pdo, $regId);
            }
            notifyStaffStatusChange($pdo, $regId, $status);
            syncRegistrationToGoogleSheetWithOutcome($pdo, $regId);
            triggerApplyPortalSyncAsync($pdo, true);
            logAdminAudit($pdo, 'auto_approval', 'registration', $regId, 'Auto ' . $status . ': ' . ($evaluation['reason'] ?? ''));
        } catch (Throwable $e) {
            error_log('[EventStaff] auto approval post-process id=' . $regId . ': ' . $e->getMessage());
        }

        logAutoApprovalDecision($pdo, $regId, $evaluation, $mode, true);
        if ($status === 'approved') {
            $stats['approved']++;
        } else {
            $stats['rejected']++;
        }
    }

    return $stats;
}

/** @return array<int, array<string, mixed>> */
function getRecentAutoApprovalLog(PDO $pdo, int $limit = 50): array
{
    ensurePlatformMaturitySchema($pdo);
    $limit = max(1, min($limit, 200));

    try {
        $stmt = $pdo->query("
            SELECT l.*, e.name AS event_name
            FROM platform_auto_approval_log l
            LEFT JOIN events e ON e.id = l.event_id
            ORDER BY l.created_at DESC
            LIMIT {$limit}
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<string, int> */
function summarizeAutoApprovalLog(PDO $pdo, int $days = 30): array
{
    ensurePlatformMaturitySchema($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT decision, mode, applied, COUNT(*) AS cnt
            FROM platform_auto_approval_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY decision, mode, applied
        ");
        $stmt->execute(['days' => $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = ['approve_live' => 0, 'reject_live' => 0, 'shadow' => 0, 'skip' => 0];
    foreach ($rows as $row) {
        $cnt = (int) ($row['cnt'] ?? 0);
        if (($row['mode'] ?? '') === 'shadow') {
            $out['shadow'] += $cnt;
            continue;
        }
        if (($row['decision'] ?? '') === 'approve' && (int) ($row['applied'] ?? 0) === 1) {
            $out['approve_live'] += $cnt;
        } elseif (($row['decision'] ?? '') === 'reject' && (int) ($row['applied'] ?? 0) === 1) {
            $out['reject_live'] += $cnt;
        } else {
            $out['skip'] += $cnt;
        }
    }

    return $out;
}

/**
 * Batch-evaluate all pending registrations (shadow/log unless $dryRunOnly is false and live mode).
 *
 * @return array{processed: int, approved: int, rejected: int, shadow: int, skipped: int}
 */
function evaluatePendingQueueBatch(PDO $pdo, bool $dryRunOnly = true): array
{
    ensurePlatformMaturitySchema($pdo);
    $stats = ['processed' => 0, 'approved' => 0, 'rejected' => 0, 'shadow' => 0, 'skipped' => 0];

    try {
        $ids = $pdo->query(
            "SELECT id FROM staff_registrations WHERE status = 'pending' ORDER BY created_at ASC LIMIT 500"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return $stats;
    }

    $liveMode = !$dryRunOnly && getAutoApprovalMode($pdo) === 2;

    foreach ($ids as $regId) {
        $regId = (int) $regId;
        if ($regId < 1) {
            continue;
        }

        $stats['processed']++;
        $evaluation = evaluateRegistrationForAutoApproval($pdo, $regId);

        if ($evaluation['decision'] === 'skip') {
            $stats['skipped']++;
            logAutoApprovalDecision($pdo, $regId, $evaluation, 1, false);
            $stats['shadow']++;
            continue;
        }

        if (!$liveMode) {
            logAutoApprovalDecision($pdo, $regId, $evaluation, 1, false);
            $stats['shadow']++;
            continue;
        }

        $status = $evaluation['decision'] === 'approve' ? 'approved' : 'rejected';
        if (!updateStaffStatus($pdo, $regId, $status, false)) {
            $stats['skipped']++;
            logAutoApprovalDecision($pdo, $regId, $evaluation, 2, false);
            continue;
        }

        try {
            if ($status === 'approved') {
                ensureCheckinToken($pdo, $regId);
            }
            notifyStaffStatusChange($pdo, $regId, $status);
            syncRegistrationToGoogleSheetWithOutcome($pdo, $regId);
            triggerApplyPortalSyncAsync($pdo, true);
            logAdminAudit($pdo, 'auto_approval', 'registration', $regId, 'Batch auto ' . $status . ': ' . ($evaluation['reason'] ?? ''));
        } catch (Throwable $e) {
            error_log('[EventStaff] batch auto approval post-process id=' . $regId . ': ' . $e->getMessage());
        }

        logAutoApprovalDecision($pdo, $regId, $evaluation, 2, true);
        if ($status === 'approved') {
            $stats['approved']++;
        } else {
            $stats['rejected']++;
        }
    }

    return $stats;
}
