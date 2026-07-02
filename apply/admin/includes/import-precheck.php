<?php

declare(strict_types=1);

require_once __DIR__ . '/phone-numbers.php';

/**
 * Look up vault profile that owns a phone number.
 *
 * @return array{id: int, name: string, email: string}|null
 */
function apply_import_vault_owner_by_phone(PDO $applyPdo, ?string $phone, ?int $excludeVaultId = null): ?array
{
    $phone = trim((string) $phone);
    if ($phone === '') {
        return null;
    }

    $sql  = 'SELECT id, first_name, last_name, email FROM staff_master WHERE phone = :phone';
    $args = ['phone' => $phone];
    if ($excludeVaultId !== null && $excludeVaultId > 0) {
        $sql .= ' AND id <> :exclude';
        $args['exclude'] = $excludeVaultId;
    }
    $sql .= ' LIMIT 1';

    try {
        $stmt = $applyPdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id'    => (int) ($row['id'] ?? 0),
            'name'  => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
            'email' => (string) ($row['email'] ?? ''),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{id: int, name: string, email: string}|null
 */
function apply_import_vault_owner_by_psa(PDO $applyPdo, ?string $psa, ?int $excludeVaultId = null): ?array
{
    $psa = strtoupper(trim((string) $psa));
    if ($psa === '') {
        return null;
    }

    $sql  = 'SELECT id, first_name, last_name, email FROM staff_master WHERE UPPER(TRIM(psa_licence)) = :psa';
    $args = ['psa' => $psa];
    if ($excludeVaultId !== null && $excludeVaultId > 0) {
        $sql .= ' AND id <> :exclude';
        $args['exclude'] = $excludeVaultId;
    }
    $sql .= ' LIMIT 1';

    try {
        $stmt = $applyPdo->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id'    => (int) ($row['id'] ?? 0),
            'name'  => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
            'email' => (string) ($row['email'] ?? ''),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function apply_import_format_phone_skip_message(string $email, string $phone, ?array $owner, bool $keptExisting): string
{
    $ownerLabel = 'another profile';
    if ($owner !== null) {
        $name = trim((string) ($owner['name'] ?? ''));
        $ownerLabel = $name !== '' ? $name : (string) ($owner['email'] ?? 'another profile');
        $ownerLabel .= ' (vault ID ' . (int) ($owner['id'] ?? 0) . ')';
    }

    if ($keptExisting) {
        return $email . ': skipped phone update — ' . $phone . ' already belongs to ' . $ownerLabel . ' (kept existing number on this profile)';
    }

    return $email . ': skipped phone — ' . $phone . ' already belongs to ' . $ownerLabel . ' (imported without phone)';
}

function apply_import_format_psa_skip_message(string $email, string $psa, ?array $owner): string
{
    $ownerLabel = 'another profile';
    if ($owner !== null) {
        $name = trim((string) ($owner['name'] ?? ''));
        $ownerLabel = $name !== '' ? $name : (string) ($owner['email'] ?? 'another profile');
        $ownerLabel .= ' (vault ID ' . (int) ($owner['id'] ?? 0) . ')';
    }

    return $email . ': skipped PSA ' . $psa . ' — already belongs to ' . $ownerLabel;
}

function apply_import_human_error(PDO $applyPdo, string $email, Throwable $e, ?string $psa = null): string
{
    $msg = $e->getMessage();
    if (str_contains($msg, 'phone') || str_contains($msg, 'Duplicate entry') && str_contains($msg, 'phone')) {
        return $email . ': skipped — phone conflict (' . $msg . ')';
    }
    if ($psa !== null && (str_contains($msg, 'psa') || str_contains($msg, 'PSA'))) {
        $owner = apply_import_vault_owner_by_psa($applyPdo, $psa);

        return apply_import_format_psa_skip_message($email, $psa, $owner);
    }
    if (str_contains($msg, 'Duplicate entry')) {
        return $email . ': skipped — duplicate vault field (' . $msg . ')';
    }

    return $email . ': skipped — ' . $msg;
}

/**
 * Pre-import validation — warnings only, no data changes.
 *
 * @return array{
 *   warnings: list<array{email: string, field: string, severity: string, message: string}>,
 *   counts: array{ok: int, warn: int, block: int, total: int}
 * }
 */
function apply_run_import_precheck(PDO $eventPdo, PDO $applyPdo): array
{
    $warnings = [];
    $ok = $warn = $block = 0;

    $staffList = $eventPdo->query("
        SELECT * FROM staff_registrations WHERE status = 'approved' ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $uniqueByEmail = [];
    foreach ($staffList as $staff) {
        $email = strtolower(trim((string) ($staff['email'] ?? '')));
        if ($email === '') {
            continue;
        }
        $uniqueByEmail[$email] = $staff;
    }

    foreach ($uniqueByEmail as $email => $staff) {
        $rowWarn = false;
        $phoneRaw = trim((string) ($staff['mobile'] ?? ''));
        $phone    = $phoneRaw !== '' ? normalizeMobileNumber($phoneRaw) : '';

        $check = $applyPdo->prepare('SELECT id, phone, psa_licence FROM staff_master WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) LIMIT 1');
        $check->execute(['email' => $email]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        $vaultId  = $existing ? (int) ($existing['id'] ?? 0) : null;

        if ($phone !== '') {
            $owner = apply_import_vault_owner_by_phone($applyPdo, $phone, $vaultId);
            if ($owner !== null) {
                $warnings[] = [
                    'email'    => $email,
                    'field'    => 'phone',
                    'severity' => 'warn',
                    'message'  => 'Phone ' . $phone . ' belongs to ' . ($owner['name'] ?: $owner['email']) . ' (vault ID ' . $owner['id'] . ')',
                ];
                $rowWarn = true;
            }
        }

        $psa = strtoupper(trim((string) ($staff['psa_licence'] ?? '')));
        if ($psa === '' && function_exists('apply_main_staff_by_email')) {
            $mainMap = apply_main_staff_by_email($eventPdo);
            $main    = $mainMap[$email] ?? null;
            $psa     = strtoupper(trim((string) ($main['psa_licence'] ?? '')));
        }
        if ($psa !== '') {
            $psaOwner = apply_import_vault_owner_by_psa($applyPdo, $psa, $vaultId);
            if ($psaOwner !== null) {
                $warnings[] = [
                    'email'    => $email,
                    'field'    => 'psa',
                    'severity' => 'block',
                    'message'  => 'PSA ' . $psa . ' belongs to ' . ($psaOwner['name'] ?: $psaOwner['email']) . ' (vault ID ' . $psaOwner['id'] . ')',
                ];
                ++$block;
                $rowWarn = true;
            }
        }

        if (!$rowWarn) {
            ++$ok;
        } elseif ($block === 0 || !$rowWarn) {
            ++$warn;
        }
    }

    $warn = count(array_filter($warnings, static fn ($w) => ($w['severity'] ?? '') === 'warn'));
    $block = count(array_filter($warnings, static fn ($w) => ($w['severity'] ?? '') === 'block'));

    return [
        'warnings' => $warnings,
        'counts'   => [
            'ok'    => $ok,
            'warn'  => $warn,
            'block' => $block,
            'total' => count($uniqueByEmail),
        ],
    ];
}
