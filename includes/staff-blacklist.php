<?php
/**
 * Event Staff System — No-show tracking and staff blacklist
 *
 * Approved staff who miss check-in for 3 consecutive events (in a row) are
 * blacklisted until an admin removes them.
 */

require_once __DIR__ . '/staff-blacklist-schema.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/validation.php';

const STAFF_NO_SHOW_BLACKLIST_THRESHOLD = 3;

function staffBlacklistReady(PDO $pdo): bool
{
    ensureStaffBlacklistSchema($pdo);

    return staffBlacklistTableExists($pdo);
}

function normalizeBlacklistEmail(string $email): string
{
    return normalizeRegistrationEmail($email);
}

/**
 * @return array<int, array<string, mixed>>
 */
function getApprovedRegistrationsWithAttendance(PDO $pdo, string $email): array
{
    $email = normalizeBlacklistEmail($email);
    if ($email === '') {
        return [];
    }

    $sql = 'SELECT sr.*,
                   e.name AS event_name, e.event_date, e.start_time, e.end_time,
                   a.id AS attendance_id, a.checked_in_at, a.checked_in_method, a.attendance_status
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE LOWER(sr.email) = LOWER(:email)
              AND sr.status = \'approved\'
            ORDER BY e.event_date ASC, sr.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => $email]);

    return $stmt->fetchAll() ?: [];
}

/**
 * Approved events whose check-in window has fully closed.
 *
 * @return array<int, array<string, mixed>>
 */
function getDecidedApprovedRegistrations(PDO $pdo, string $email): array
{
    $rows = getApprovedRegistrationsWithAttendance($pdo, $email);

    return array_values(array_filter($rows, static function (array $row): bool {
        $window = getEventCheckinWindow($row);

        return $window['status'] === 'after';
    }));
}

/**
 * Whether an approved registration row reflects a real venue check-in (not no-show).
 *
 * @param array<string, mixed> $row
 */
function registrationHadVenueCheckin(array $row): bool
{
    if (empty($row['attendance_id']) && trim((string) ($row['checked_in_at'] ?? '')) === '') {
        return false;
    }

    $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
    if ($status === 'no_show') {
        return false;
    }

    $method = strtolower(trim((string) ($row['checked_in_method'] ?? '')));
    if ($method === 'auto_no_show') {
        return false;
    }

    return true;
}

/**
 * Count consecutive no-shows ending at the most recent decided event.
 * A check-in resets the streak.
 */
function countConsecutiveNoShows(PDO $pdo, string $email): int
{
    $rows  = getDecidedApprovedRegistrations($pdo, $email);
    $streak = 0;

    foreach ($rows as $row) {
        if (registrationHadVenueCheckin($row)) {
            $streak = 0;
            continue;
        }

        $streak++;
    }

    return $streak;
}

function isEmailBlacklisted(PDO $pdo, string $email): bool
{
    if (!staffBlacklistReady($pdo)) {
        return false;
    }

    $email = normalizeBlacklistEmail($email);
    if ($email === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM staff_blacklist
         WHERE LOWER(email) = LOWER(:email) AND removed_at IS NULL
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return array<string, mixed>|null
 */
function getActiveBlacklistEntry(PDO $pdo, string $email): ?array
{
    if (!staffBlacklistReady($pdo)) {
        return null;
    }

    $email = normalizeBlacklistEmail($email);
    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM staff_blacklist
         WHERE LOWER(email) = LOWER(:email) AND removed_at IS NULL
         ORDER BY blacklisted_at DESC
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return array<string, mixed>|null New blacklist row when created
 */
function evaluateStaffBlacklist(PDO $pdo, string $email): ?array
{
    if (!staffBlacklistReady($pdo)) {
        return null;
    }

    $email = normalizeBlacklistEmail($email);
    if ($email === '' || isEmailBlacklisted($pdo, $email)) {
        return null;
    }

    $streak = countConsecutiveNoShows($pdo, $email);
    if ($streak < STAFF_NO_SHOW_BLACKLIST_THRESHOLD) {
        return null;
    }

    return blacklistEmail(
        $pdo,
        $email,
        $streak . ' consecutive approved no-shows',
        true,
        $streak
    );
}

/**
 * @return array<string, mixed>|null
 */
function blacklistEmail(
    PDO $pdo,
    string $email,
    string $reason,
    bool $auto = false,
    int $consecutiveNoShows = 0
): ?array {
    if (!staffBlacklistReady($pdo)) {
        return null;
    }

    $email = normalizeBlacklistEmail($email);
    if ($email === '' || isEmailBlacklisted($pdo, $email)) {
        return null;
    }

    if ($consecutiveNoShows < 1) {
        $consecutiveNoShows = countConsecutiveNoShows($pdo, $email);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO staff_blacklist (email, consecutive_no_shows, reason, auto_blacklisted)
         VALUES (:email, :consecutive_no_shows, :reason, :auto_blacklisted)'
    );
    $stmt->execute([
        'email'                 => $email,
        'consecutive_no_shows'  => max(1, $consecutiveNoShows),
        'reason'                => $reason !== '' ? $reason : STAFF_NO_SHOW_BLACKLIST_THRESHOLD . ' consecutive approved no-shows',
        'auto_blacklisted'      => $auto ? 1 : 0,
    ]);

    $id = (int) $pdo->lastInsertId();

    if ($auto) {
        require_once __DIR__ . '/audit-log.php';
        logAdminAudit($pdo, 'staff_blacklist', 'staff_email', $id, $email . ' (auto, ' . max(1, $consecutiveNoShows) . ' no-shows)');
    }

    return getBlacklistEntryById($pdo, $id);
}

function removeFromBlacklist(PDO $pdo, string $email, ?int $adminId = null): bool
{
    if (!staffBlacklistReady($pdo)) {
        return false;
    }

    $email = normalizeBlacklistEmail($email);
    if ($email === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        'UPDATE staff_blacklist
         SET removed_at = NOW(), removed_by_admin_id = :admin_id
         WHERE LOWER(email) = LOWER(:email) AND removed_at IS NULL'
    );
    $stmt->execute([
        'email'    => $email,
        'admin_id' => $adminId,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * @return array<string, mixed>|null
 */
function getBlacklistEntryById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM staff_blacklist WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function getActiveBlacklist(PDO $pdo): array
{
    if (!staffBlacklistReady($pdo)) {
        return [];
    }

    $sql = 'SELECT b.*,
                   sr.first_name, sr.surname, sr.mobile,
                   (SELECT COUNT(*) FROM staff_registrations s WHERE LOWER(s.email) = LOWER(b.email)) AS registration_count
            FROM staff_blacklist b
            LEFT JOIN staff_registrations sr ON sr.id = (
                SELECT s2.id FROM staff_registrations s2
                WHERE LOWER(s2.email) = LOWER(b.email)
                ORDER BY s2.created_at DESC
                LIMIT 1
            )
            WHERE b.removed_at IS NULL
            ORDER BY b.blacklisted_at DESC, b.id DESC';

    return $pdo->query($sql)->fetchAll() ?: [];
}

/**
 * Process one email after an event check-in window closes.
 *
 * @return array<string, mixed>|null
 */
function processNoShowBlacklistForEmail(PDO $pdo, string $email): ?array
{
    return evaluateStaffBlacklist($pdo, $email);
}

/**
 * Daily batch: scan approved staff with closed check-in windows.
 *
 * @return array{blacklisted: int, scanned: int}
 */
function processAllNoShowBlacklists(PDO $pdo): array
{
    $stats = ['blacklisted' => 0, 'scanned' => 0];

    if (!staffBlacklistReady($pdo)) {
        return $stats;
    }

    $sql = 'SELECT DISTINCT sr.email
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE sr.status = \'approved\'
              AND (
                    a.id IS NULL
                    OR LOWER(COALESCE(a.attendance_status, \'\')) = \'no_show\'
                  )';

    $emails = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($emails as $email) {
        $stats['scanned']++;
        if (processNoShowBlacklistForEmail($pdo, (string) $email) !== null) {
            $stats['blacklisted']++;
        }
    }

    return $stats;
}

function validateStaffNotBlacklisted(PDO $pdo, string $email): ?string
{
    if (isEmailBlacklisted($pdo, $email)) {
        return 'You are not currently able to register for events. Please contact the organisers if you believe this is a mistake.';
    }

    return null;
}
