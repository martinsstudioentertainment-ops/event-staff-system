<?php

declare(strict_types=1);

require_once __DIR__ . '/attendance-bib-schema.php';

/**
 * Web self/scan check-in must capture contractor bib at confirm.
 */
function isBibRequiredForCheckinMethod(string $method): bool
{
    return in_array(strtolower(trim($method)), ['self', 'scan'], true);
}

function normalizeCheckinBibNumber(?string $raw): string
{
    return strtoupper(preg_replace('/\s+/', '', trim((string) $raw)));
}

/**
 * @return array{ok: bool, bib: ?string, error: string}
 */
function parseCheckinBibNumber(?string $raw, bool $required): array
{
    $bib = normalizeCheckinBibNumber($raw);

    if ($bib === '') {
        if ($required) {
            return [
                'ok'    => false,
                'bib'   => null,
                'error' => 'Enter the bib number you were given today.',
            ];
        }

        return ['ok' => true, 'bib' => null, 'error' => ''];
    }

    if (!preg_match('/^[A-Z0-9-]{1,20}$/', $bib)) {
        return [
            'ok'    => false,
            'bib'   => null,
            'error' => 'Enter a valid bib number (letters, numbers, optional dash, up to 20 characters).',
        ];
    }

    return ['ok' => true, 'bib' => $bib, 'error' => ''];
}

function saveAttendanceBibNumber(PDO $pdo, int $registrationId, ?string $bibNumber): void
{
    ensureAttendanceBibSchema($pdo);

    $bib = $bibNumber !== null ? normalizeCheckinBibNumber($bibNumber) : '';
    if ($bib === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE attendance SET bib_number = :bib_number WHERE registration_id = :registration_id'
    );
    $stmt->execute([
        'bib_number'       => $bib,
        'registration_id'  => $registrationId,
    ]);

    assignStaffRegistrationBibNumber($pdo, $registrationId, $bib);
}

function assignStaffRegistrationBibNumber(PDO $pdo, int $registrationId, ?string $bibNumber): void
{
    ensureStaffRegistrationBibSchema($pdo);

    $bib = $bibNumber !== null ? normalizeCheckinBibNumber($bibNumber) : '';
    if ($bib === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE staff_registrations SET assigned_bib_number = :bib WHERE id = :id'
    );
    $stmt->execute([
        'bib' => $bib,
        'id'  => $registrationId,
    ]);
}

/**
 * Bib shown to staff: checked-in bib wins, else assigned bib on registration.
 */
function resolveStaffDisplayBibNumber(array $row): string
{
    $checkedIn = normalizeCheckinBibNumber((string) ($row['bib_number'] ?? ''));
    if ($checkedIn !== '') {
        return $checkedIn;
    }

    return normalizeCheckinBibNumber((string) ($row['assigned_bib_number'] ?? ''));
}

/**
 * Resolve bib for self/scan check-in (POST value or pre-assigned registration bib).
 *
 * @return array{ok: bool, bib: ?string, error: string}
 */
function resolveCheckinBibForRegistration(array $row, ?string $postBib, bool $required): array
{
    $posted = normalizeCheckinBibNumber($postBib);
    if ($posted !== '') {
        return parseCheckinBibNumber($posted, $required);
    }

    $assigned = normalizeCheckinBibNumber((string) ($row['assigned_bib_number'] ?? ''));
    if ($assigned !== '') {
        return parseCheckinBibNumber($assigned, $required);
    }

    return parseCheckinBibNumber('', $required);
}
