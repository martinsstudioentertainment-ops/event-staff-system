<?php
/**
 * Event Staff System — Staff self-service status portal
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/attendance-repository.php';

function generateStatusToken(): string
{
    return bin2hex(random_bytes(32));
}

function ensureStatusToken(PDO $pdo, int $registrationId): ?string
{
    require_once __DIR__ . '/staff-registration-schema.php';
    ensureStaffRegistrationSaveSchema($pdo);

    if (!staffRegistrationColumnExists($pdo, 'status_token')) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT status_token FROM staff_registrations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $registrationId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (!empty($row['status_token'])) {
        return (string) $row['status_token'];
    }

    $token  = generateStatusToken();
    $update = $pdo->prepare('UPDATE staff_registrations SET status_token = :token WHERE id = :id');
    $update->execute(['token' => $token, 'id' => $registrationId]);

    return $token;
}

function getStatusUrl(string $token, ?PDO $pdo = null): string
{
    return getRegistrationSiteUrl($pdo) . '/status.php?token=' . urlencode($token);
}

/**
 * Status page URL after a new registration (uses first saved row / email).
 *
 * @param int[] $registrationIds
 */
function getRegistrationStatusUrlAfterSave(PDO $pdo, array $registrationIds, string $email): string
{
    $token = null;
    if ($registrationIds !== []) {
        $token = ensureStatusToken($pdo, (int) $registrationIds[0]);
    }
    if ($token === null || $token === '') {
        $token = resolveStatusTokenByEmail($pdo, $email);
    }

    return $token !== null && $token !== ''
        ? getStatusUrl($token, $pdo)
        : getRegistrationSiteUrl($pdo) . '/status.php';
}

/**
 * Extract status token from a pasted link or raw token string.
 */
function parseStatusTokenFromInput(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }

    if (preg_match('/[?&]token=([^&\s#]+)/i', $input, $matches)) {
        return trim(urldecode($matches[1]));
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $input)) {
        return strtolower($input);
    }

    return '';
}

/**
 * Find or create a status token for the email used at registration.
 */
function resolveStatusTokenByEmail(PDO $pdo, string $email): ?string
{
    require_once __DIR__ . '/validation.php';

    $email = normalizeRegistrationEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM staff_registrations WHERE LOWER(email) = LOWER(:email) ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $registrationId = (int) ($stmt->fetchColumn() ?: 0);

    if ($registrationId < 1) {
        return null;
    }

    return ensureStatusToken($pdo, $registrationId);
}

/**
 * @return array<string, mixed>|null
 */
function getRegistrationByStatusToken(PDO $pdo, string $token): ?array
{
    $sql = 'SELECT sr.*, e.name AS event_name, e.event_date
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.status_token = :token
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function getStaffStatusRows(PDO $pdo, string $token): array
{
    $row = getRegistrationByStatusToken($pdo, $token);
    if (!$row) {
        return [];
    }

    $sql = 'SELECT sr.*, e.name AS event_name, e.event_date,
                   a.id AS attendance_id, a.checked_in_at, a.checked_out_at,
                   a.hours_worked, a.attendance_status,
                   CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS is_checked_in
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE LOWER(sr.email) = LOWER(:email)
            ORDER BY e.event_date ASC, sr.created_at ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => strtolower(trim((string) $row['email']))]);

    $rows = $stmt->fetchAll() ?: [];

    require_once __DIR__ . '/staff-repository.php';

    return array_map(static function (array $r) use ($pdo): array {
        $r = mergeRegistrationWithStaff($pdo, $r);
        $r = mergeRegistrationWithEvent($pdo, $r);

        return $r;
    }, $rows);
}
