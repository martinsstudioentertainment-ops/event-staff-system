<?php

declare(strict_types=1);

require_once __DIR__ . '/platform-schema.php';
require_once __DIR__ . '/../staff-repository.php';
require_once __DIR__ . '/../staff-blacklist.php';
require_once __DIR__ . '/../staff-onboarding.php';

/** @return array{score: int, tier: string, factors: array<string, int>} */
function computeStaffTrustScore(PDO $pdo, int $staffId): array
{
    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        return ['score' => 0, 'tier' => 'bronze', 'factors' => []];
    }

    $email   = strtolower(trim((string) ($staff['email'] ?? '')));
    $factors = [];
    $score   = 40;

    if (isStaffOnboardingComplete($staff)) {
        $factors['profile_complete'] = 15;
        $score += 15;
    }

    $approvedCount = 0;
    $attendedCount = 0;
    $lateCount     = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_registrations WHERE staff_id = :sid AND status = 'approved'"
        );
        $stmt->execute(['sid' => $staffId]);
        $approvedCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE sr.staff_id = :sid AND a.checked_in_at IS NOT NULL"
        );
        $stmt->execute(['sid' => $staffId]);
        $attendedCount = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // optional
    }

    if ($approvedCount > 0) {
        $attendanceRate = min(100, (int) round(($attendedCount / max(1, $approvedCount)) * 100));
        $attendancePts  = (int) round($attendanceRate * 0.25);
        $factors['attendance'] = $attendancePts;
        $score += $attendancePts;
    }

    if ($approvedCount >= 5) {
        $factors['event_completion'] = 10;
        $score += 10;
    } elseif ($approvedCount >= 2) {
        $factors['event_completion'] = 5;
        $score += 5;
    }

    if (isEmailBlacklisted($pdo, $email)) {
        $factors['blacklist'] = -50;
        $score -= 50;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_messages
             WHERE staff_id = :sid AND direction = 'staff_to_admin' AND is_read = 0"
        );
        $stmt->execute(['sid' => $staffId]);
        if ((int) $stmt->fetchColumn() === 0 && $approvedCount >= 1) {
            $factors['responsiveness'] = 5;
            $score += 5;
        }
    } catch (Throwable $e) {
        // optional
    }

    $score = max(0, min(100, $score));
    $tier  = trustScoreTierFromScore($score);

    return ['score' => $score, 'tier' => $tier, 'factors' => $factors];
}

function trustScoreTierFromScore(int $score): string
{
    return match (true) {
        $score >= 85 => 'platinum',
        $score >= 70 => 'gold',
        $score >= 50 => 'silver',
        default      => 'bronze',
    };
}

function trustScoreTierLabel(string $tier): string
{
    return ucfirst($tier);
}

function saveStaffTrustScore(PDO $pdo, int $staffId): array
{
    ensurePlatformMaturitySchema($pdo);
    $computed = computeStaffTrustScore($pdo, $staffId);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO platform_trust_scores (staff_id, score, tier, factors_json)
            VALUES (:sid, :score, :tier, :factors)
            ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                tier = VALUES(tier),
                factors_json = VALUES(factors_json),
                computed_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'sid'     => $staffId,
            'score'   => $computed['score'],
            'tier'    => $computed['tier'],
            'factors' => json_encode($computed['factors'], JSON_THROW_ON_ERROR),
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] trust score save: ' . $e->getMessage());
    }

    return $computed;
}

/** @return array<int, array<string, mixed>> */
function listStaffTrustScores(PDO $pdo, ?string $tierFilter = null, int $limit = 100): array
{
    ensurePlatformMaturitySchema($pdo);
    $limit = max(1, min($limit, 500));

    $sql = "
        SELECT ts.*, s.first_name, s.surname, s.email
        FROM platform_trust_scores ts
        INNER JOIN staff s ON s.id = ts.staff_id
    ";
    $params = [];
    if ($tierFilter !== null && $tierFilter !== '') {
        $sql .= ' WHERE ts.tier = :tier';
        $params['tier'] = $tierFilter;
    }
    $sql .= ' ORDER BY ts.score DESC, s.surname ASC LIMIT ' . $limit;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Recompute trust scores for all staff with registrations. */
function recomputeAllTrustScores(PDO $pdo, int $batchLimit = 500): int
{
    try {
        $ids = $pdo->query(
            'SELECT DISTINCT staff_id FROM staff_registrations WHERE staff_id IS NOT NULL LIMIT ' . max(1, $batchLimit)
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return 0;
    }

    $count = 0;
    foreach ($ids as $id) {
        saveStaffTrustScore($pdo, (int) $id);
        $count++;
    }

    return $count;
}

/** @return array<string, int> */
function summarizeTrustScoreTiers(PDO $pdo): array
{
    ensurePlatformMaturitySchema($pdo);
    $out = ['bronze' => 0, 'silver' => 0, 'gold' => 0, 'platinum' => 0];

    try {
        $rows = $pdo->query('SELECT tier, COUNT(*) AS cnt FROM platform_trust_scores GROUP BY tier')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $tier = (string) ($row['tier'] ?? 'bronze');
            if (isset($out[$tier])) {
                $out[$tier] = (int) ($row['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // empty
    }

    return $out;
}

function getStaffTrustScoreCached(PDO $pdo, int $staffId): ?array
{
    ensurePlatformMaturitySchema($pdo);
    try {
        $stmt = $pdo->prepare('SELECT * FROM platform_trust_scores WHERE staff_id = :sid LIMIT 1');
        $stmt->execute(['sid' => $staffId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Recompute and persist trust score after a staff lifecycle event. */
function refreshStaffTrustScoreOnEvent(PDO $pdo, int $staffId): void
{
    if ($staffId < 1) {
        return;
    }

    saveStaffTrustScore($pdo, $staffId);
}
