<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-registration-schema.php';

function registrationBibColumnEnabled(PDO $pdo): bool
{
    return staffRegistrationColumnExists($pdo, 'assigned_bib_number');
}

function formatRegistrationBibDisplay(?string $bib): string
{
    $bib = trim((string) $bib);

    return $bib !== '' ? $bib : '—';
}

/**
 * @return true|string
 */
function updateStaffRegistrationBibNumber(PDO $pdo, int $registrationId, string $bib): bool|string
{
    if ($registrationId < 1) {
        return 'Registration is required.';
    }

    if (!registrationBibColumnEnabled($pdo)) {
        return 'Bib number is not enabled on this system.';
    }

    $bib = preg_replace('/\s+/', '', trim($bib)) ?? '';
    if ($bib !== '' && !preg_match('/^[A-Za-z0-9#\-]+$/', $bib)) {
        return 'Bib number can only contain letters, numbers, # and hyphens.';
    }

    $setSql = staffRegistrationColumnExists($pdo, 'updated_at')
        ? 'assigned_bib_number = :bib, updated_at = NOW()'
        : 'assigned_bib_number = :bib';

    $stmt = $pdo->prepare(
        "UPDATE staff_registrations
         SET {$setSql}
         WHERE id = :id"
    );
    $stmt->execute([
        'bib' => $bib !== '' ? $bib : null,
        'id'  => $registrationId,
    ]);

    if ($stmt->rowCount() < 1) {
        $check = $pdo->prepare('SELECT assigned_bib_number FROM staff_registrations WHERE id = :id LIMIT 1');
        $check->execute(['id' => $registrationId]);
        $current = trim((string) ($check->fetchColumn() ?: ''));
        if ($current !== $bib) {
            return 'Registration not found or bib could not be saved.';
        }
    }

    return true;
}
