<?php

declare(strict_types=1);

require_once __DIR__ . '/apply-vault-bridge.php';
require_once __DIR__ . '/data-integrity-schema.php';

/** @return list<string> */
function dataIntegrityTestEmailPatterns(): array
{
    return [
        '@olasentra-e2e.test',
        '@example.com',
        '@test.',
        'e2e@',
        'demo@',
        'qa@',
        'dev@',
        'fake@',
        'seed@',
    ];
}

/** @return list<string> */
function dataIntegrityTestPsaValues(): array
{
    return [
        'EM123456/78',
        'EM217046/26',
        'EM000000/00',
        'TEST',
        'TEMP',
        'PENDING',
    ];
}

function dataIntegrityNormalizePhone(string $phone): string
{
    return preg_replace('/[^\d+]/', '', trim($phone)) ?? '';
}

function dataIntegrityStaffLabel(?array $row): string
{
    if ($row === null || $row === []) {
        return 'Unknown';
    }

    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? $row['last_name'] ?? ''));

    return $name !== '' ? $name : (trim((string) ($row['email'] ?? '')) ?: 'Unknown');
}

function dataIntegrityVaultLabel(?array $row): string
{
    if ($row === null || $row === []) {
        return 'Unknown';
    }

    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));

    return $name !== '' ? $name : (trim((string) ($row['email'] ?? '')) ?: 'Unknown');
}

function dataIntegrityIsTestEmail(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    foreach (dataIntegrityTestEmailPatterns() as $pattern) {
        if (str_contains($email, strtolower($pattern))) {
            return true;
        }
    }

    return (bool) preg_match('/@(mailinator|yopmail|tempmail|guerrillamail)\./i', $email);
}

function dataIntegrityIsTestPsa(string $psa): bool
{
    $psa = strtoupper(trim($psa));
    if ($psa === '') {
        return false;
    }

    foreach (dataIntegrityTestPsaValues() as $test) {
        if (strtoupper($test) === $psa) {
            return true;
        }
    }

    return (bool) preg_match('/^(TEST|TEMP|FAKE|DEMO|EM123456)/i', $psa);
}

/** @return list<string> */
function dataIntegrityDismissedKeys(PDO $pdo, string $type = ''): array
{
    ensureDataIntegritySchema($pdo);
    $sql  = 'SELECT duplicate_key FROM platform_data_integrity_dismissals';
    $args = [];
    if ($type !== '') {
        $sql .= ' WHERE duplicate_type = :type';
        $args['type'] = $type;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<string, mixed> */
function runFullDataIntegrityAudit(PDO $pdo, ?PDO $applyPdo = null): array
{
    $applyPdo ??= getApplyVaultPdo();

    return [
        'duplicate_phones_main'    => auditDuplicatePhonesMain($pdo),
        'duplicate_phones_vault'   => $applyPdo ? auditDuplicatePhonesVault($applyPdo) : [],
        'duplicate_emails_main'    => auditDuplicateEmailsMain($pdo),
        'duplicate_mobile_main'    => auditDuplicatePhonesMain($pdo),
        'duplicate_staff_profiles' => auditDuplicateStaffProfiles($pdo),
        'duplicate_psa_vault'      => $applyPdo ? auditDuplicatePsaVault($applyPdo) : [],
        'orphaned'                 => auditOrphanedRecords($pdo),
        'inactive'                 => auditInactiveRecords($pdo),
        'blank_phones_vault'       => $applyPdo ? countVaultBlankPhones($applyPdo) : 0,
        'invalid_phones_vault'     => $applyPdo ? countVaultInvalidPhones($applyPdo) : 0,
    ];
}

/** @return list<array<string, mixed>> */
function auditDuplicatePhonesMain(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
            SELECT mobile, GROUP_CONCAT(id ORDER BY id) AS staff_ids,
                   GROUP_CONCAT(CONCAT(first_name, ' ', surname) ORDER BY id SEPARATOR ' | ') AS names,
                   GROUP_CONCAT(email ORDER BY id SEPARATOR ' | ') AS emails,
                   COUNT(*) AS cnt
            FROM staff
            WHERE TRIM(mobile) <> ''
            GROUP BY mobile
            HAVING cnt > 1
            ORDER BY cnt DESC, mobile
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $ids = array_map('intval', explode(',', (string) ($row['staff_ids'] ?? '')));
        $out[] = [
            'phone'       => (string) ($row['mobile'] ?? ''),
            'count'       => (int) ($row['cnt'] ?? 0),
            'staff_ids'   => $ids,
            'names'       => (string) ($row['names'] ?? ''),
            'emails'      => (string) ($row['emails'] ?? ''),
            'recommended' => recommendCanonicalStaffId($pdo, $ids),
        ];
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function auditDuplicateEmailsMain(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
            SELECT LOWER(TRIM(email)) AS email_key, COUNT(*) AS cnt,
                   GROUP_CONCAT(id ORDER BY id) AS staff_ids
            FROM staff
            GROUP BY email_key
            HAVING cnt > 1
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $ids = array_map('intval', explode(',', (string) ($row['staff_ids'] ?? '')));
        $out[] = [
            'email'       => (string) ($row['email_key'] ?? ''),
            'count'       => (int) ($row['cnt'] ?? 0),
            'staff_ids'   => $ids,
            'recommended' => recommendCanonicalStaffId($pdo, $ids),
        ];
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function auditDuplicateStaffProfiles(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
            SELECT LOWER(TRIM(first_name)) AS fn, LOWER(TRIM(surname)) AS sn, date_of_birth,
                   COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS staff_ids,
                   GROUP_CONCAT(email ORDER BY id SEPARATOR ' | ') AS emails
            FROM staff
            WHERE TRIM(first_name) <> '' AND TRIM(surname) <> '' AND date_of_birth IS NOT NULL
            GROUP BY fn, sn, date_of_birth
            HAVING cnt > 1
            ORDER BY cnt DESC
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $ids = array_map('intval', explode(',', (string) ($row['staff_ids'] ?? '')));
        $out[] = [
            'name'        => trim((string) ($row['fn'] ?? '') . ' ' . (string) ($row['sn'] ?? '')),
            'dob'         => (string) ($row['date_of_birth'] ?? ''),
            'count'       => (int) ($row['cnt'] ?? 0),
            'staff_ids'   => $ids,
            'emails'      => (string) ($row['emails'] ?? ''),
            'recommended' => recommendCanonicalStaffId($pdo, $ids),
        ];
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function auditDuplicatePhonesVault(PDO $applyPdo): array
{
    try {
        $rows = $applyPdo->query("
            SELECT phone, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS vault_ids,
                   GROUP_CONCAT(CONCAT(first_name, ' ', last_name) ORDER BY id SEPARATOR ' | ') AS names,
                   GROUP_CONCAT(email ORDER BY id SEPARATOR ' | ') AS emails
            FROM staff_master
            WHERE phone IS NOT NULL AND TRIM(phone) <> ''
            GROUP BY phone
            HAVING cnt > 1
            ORDER BY cnt DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $ids = array_map('intval', explode(',', (string) ($row['vault_ids'] ?? '')));
        $out[] = [
            'phone'       => (string) ($row['phone'] ?? ''),
            'count'       => (int) ($row['cnt'] ?? 0),
            'vault_ids'   => $ids,
            'names'       => (string) ($row['names'] ?? ''),
            'emails'      => (string) ($row['emails'] ?? ''),
            'recommended' => $ids[0] ?? 0,
        ];
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function auditDuplicatePsaVault(PDO $applyPdo): array
{
    try {
        $rows = $applyPdo->query("
            SELECT UPPER(TRIM(psa_licence)) AS psa_key, psa_licence,
                   COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS vault_ids,
                   GROUP_CONCAT(CONCAT(first_name, ' ', last_name) ORDER BY id SEPARATOR ' | ') AS names,
                   GROUP_CONCAT(email ORDER BY id SEPARATOR ' | ') AS emails
            FROM staff_master
            WHERE psa_licence IS NOT NULL AND TRIM(psa_licence) <> ''
            GROUP BY psa_key
            HAVING cnt > 1
            ORDER BY cnt DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $ids = array_map('intval', explode(',', (string) ($row['vault_ids'] ?? '')));
        $out[] = [
            'psa'         => (string) ($row['psa_licence'] ?? ''),
            'is_test'     => dataIntegrityIsTestPsa((string) ($row['psa_licence'] ?? '')),
            'count'       => (int) ($row['cnt'] ?? 0),
            'vault_ids'   => $ids,
            'names'       => (string) ($row['names'] ?? ''),
            'emails'      => (string) ($row['emails'] ?? ''),
            'recommended' => $ids[0] ?? 0,
        ];
    }

    return $out;
}

function countVaultBlankPhones(PDO $applyPdo): int
{
    try {
        return (int) $applyPdo->query("SELECT COUNT(*) FROM staff_master WHERE phone IS NULL OR TRIM(phone) = ''")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function countVaultInvalidPhones(PDO $applyPdo): int
{
    try {
        $rows = $applyPdo->query("SELECT phone FROM staff_master WHERE phone IS NOT NULL AND TRIM(phone) <> ''")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return 0;
    }

    $bad = 0;
    foreach ($rows as $phone) {
        $norm = dataIntegrityNormalizePhone((string) $phone);
        if ($norm === '' || strlen(preg_replace('/\D/', '', $norm) ?? '') < 8) {
            ++$bad;
        }
    }

    return $bad;
}

/** @return list<array<string, mixed>> */
function auditOrphanedRecords(PDO $pdo): array
{
    $issues = [];
    $checks = [
        [
            'label' => 'Registrations with missing staff_id link',
            'sql'   => 'SELECT sr.id, sr.email FROM staff_registrations sr
                        LEFT JOIN staff s ON s.id = sr.staff_id
                        WHERE sr.staff_id IS NOT NULL AND s.id IS NULL LIMIT 100',
        ],
        [
            'label' => 'Attendance with missing registration',
            'sql'   => 'SELECT a.id, a.registration_id FROM attendance a
                        LEFT JOIN staff_registrations sr ON sr.id = a.registration_id
                        WHERE sr.id IS NULL LIMIT 100',
        ],
        [
            'label' => 'Staff messages with missing staff',
            'sql'   => 'SELECT sm.id, sm.staff_id FROM staff_messages sm
                        LEFT JOIN staff s ON s.id = sm.staff_id
                        WHERE sm.staff_id IS NOT NULL AND s.id IS NULL LIMIT 100',
        ],
    ];

    foreach ($checks as $check) {
        try {
            $rows = $pdo->query($check['sql'])->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows !== []) {
                $issues[] = ['type' => $check['label'], 'count' => count($rows), 'sample' => array_slice($rows, 0, 5)];
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return $issues;
}

/** @return list<array<string, mixed>> */
function auditInactiveRecords(PDO $pdo): array
{
    $out = [];

    try {
        $cnt = (int) $pdo->query("
            SELECT COUNT(*) FROM staff s
            WHERE NOT EXISTS (
                SELECT 1 FROM staff_registrations sr
                WHERE LOWER(sr.email) = LOWER(s.email)
                  AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 18 MONTH)
            )
        ")->fetchColumn();
        if ($cnt > 0) {
            $out[] = ['type' => 'Staff with no registration in 18 months', 'count' => $cnt];
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $cnt = (int) $pdo->query("
            SELECT COUNT(*) FROM staff_registrations
            WHERE status = 'rejected' AND created_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)
        ")->fetchColumn();
        if ($cnt > 0) {
            $out[] = ['type' => 'Old rejected registrations (12+ months)', 'count' => $cnt];
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $out;
}

/** @param list<int> $staffIds */
function recommendCanonicalStaffId(PDO $pdo, array $staffIds): int
{
    $staffIds = array_values(array_filter(array_map('intval', $staffIds), static fn (int $id): bool => $id > 0));
    if ($staffIds === []) {
        return 0;
    }
    if (count($staffIds) === 1) {
        return $staffIds[0];
    }

    try {
        $in   = implode(',', $staffIds);
        $stmt = $pdo->query("
            SELECT s.id FROM staff s
            LEFT JOIN (
                SELECT staff_id, COUNT(*) AS reg_cnt
                FROM staff_registrations
                WHERE staff_id IN ({$in})
                GROUP BY staff_id
            ) r ON r.staff_id = s.id
            WHERE s.id IN ({$in})
            ORDER BY
                CASE WHEN s.psa_register_status = 'verified' THEN 1 ELSE 0 END DESC,
                CASE WHEN s.profile_completed = 1 THEN 1 ELSE 0 END DESC,
                CASE WHEN s.psa_licence IS NOT NULL AND TRIM(s.psa_licence) <> '' AND s.psa_licence NOT LIKE 'TEMP-PSA-%' THEN 1 ELSE 0 END DESC,
                COALESCE(r.reg_cnt, 0) DESC,
                COALESCE(s.updated_at, s.created_at) DESC,
                s.id ASC
            LIMIT 1
        ");

        return (int) ($stmt?->fetchColumn() ?: $staffIds[0]);
    } catch (Throwable $e) {
        return $staffIds[0];
    }
}

/** @return array<string, mixed> */
function detectTestAccounts(PDO $pdo, ?PDO $applyPdo = null): array
{
    $applyPdo ??= getApplyVaultPdo();
    $accounts = [];

    try {
        $staffRows = $pdo->query('SELECT id, first_name, surname, email, mobile, created_at FROM staff ORDER BY id DESC LIMIT 5000')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $staffRows = [];
    }

    foreach ($staffRows as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if (!dataIntegrityIsTestEmail($email)) {
            continue;
        }
        $staffId = (int) ($row['id'] ?? 0);
        $accounts[] = [
            'source'        => 'main_staff',
            'staff_id'      => $staffId,
            'email'         => $email,
            'name'          => dataIntegrityStaffLabel($row),
            'registrations' => countRelatedRegistrations($pdo, $email),
            'attendance'    => countRelatedAttendance($pdo, $email),
            'notifications' => countRelatedNotifications($pdo, $email),
            'messages'      => countRelatedMessages($pdo, $staffId),
        ];
    }

    if ($applyPdo instanceof PDO) {
        try {
            $vaultRows = $applyPdo->query('SELECT id, first_name, last_name, email, phone, psa_licence FROM staff_master')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $vaultRows = [];
        }
        foreach ($vaultRows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $psa   = (string) ($row['psa_licence'] ?? '');
            if (!dataIntegrityIsTestEmail($email) && !dataIntegrityIsTestPsa($psa)) {
                continue;
            }
            $accounts[] = [
                'source'   => 'apply_vault',
                'vault_id' => (int) ($row['id'] ?? 0),
                'email'    => $email,
                'name'     => dataIntegrityVaultLabel($row),
                'psa'      => $psa,
            ];
        }
    }

    return [
        'accounts'           => $accounts,
        'main_registrations' => array_sum(array_column(array_filter($accounts, static fn ($a) => ($a['source'] ?? '') === 'main_staff'), 'registrations')),
        'vault_rows'         => count(array_filter($accounts, static fn ($a) => ($a['source'] ?? '') === 'apply_vault')),
        'attendance_rows'    => array_sum(array_column(array_filter($accounts, static fn ($a) => ($a['source'] ?? '') === 'main_staff'), 'attendance')),
        'notification_rows'  => array_sum(array_column(array_filter($accounts, static fn ($a) => ($a['source'] ?? '') === 'main_staff'), 'notifications')),
    ];
}

function countRelatedRegistrations(PDO $pdo, string $email): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM staff_registrations WHERE LOWER(email) = :email');
        $stmt->execute(['email' => strtolower($email)]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function countRelatedAttendance(PDO $pdo, string $email): int
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM attendance a
            INNER JOIN staff_registrations sr ON sr.id = a.registration_id
            WHERE LOWER(sr.email) = :email
        ");
        $stmt->execute(['email' => strtolower($email)]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function countRelatedNotifications(PDO $pdo, string $email): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM app_notifications WHERE LOWER(staff_email) = :email');
        $stmt->execute(['email' => strtolower($email)]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function countRelatedMessages(PDO $pdo, int $staffId): int
{
    if ($staffId < 1) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM staff_messages WHERE staff_id = :id');
        $stmt->execute(['id' => $staffId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array{score: int, issues: list<string>, metrics: array<string, int>} */
function computeVaultHealthScore(?PDO $applyPdo): array
{
    if (!$applyPdo instanceof PDO) {
        return ['score' => 0, 'issues' => ['Apply vault database not connected'], 'metrics' => []];
    }

    $metrics = [];
    $penalty = 0;
    $issues  = [];

    try {
        $metrics['total'] = (int) $applyPdo->query('SELECT COUNT(*) FROM staff_master')->fetchColumn();
    } catch (Throwable $e) {
        return ['score' => 0, 'issues' => ['Cannot read staff_master'], 'metrics' => []];
    }

    $metrics['missing_email']   = (int) $applyPdo->query("SELECT COUNT(*) FROM staff_master WHERE email IS NULL OR TRIM(email) = ''")->fetchColumn();
    $metrics['missing_phone']   = countVaultBlankPhones($applyPdo);
    $metrics['missing_psa']     = (int) $applyPdo->query("SELECT COUNT(*) FROM staff_master WHERE psa_licence IS NULL OR TRIM(psa_licence) = ''")->fetchColumn();
    $metrics['duplicate_phone'] = count(auditDuplicatePhonesVault($applyPdo));
    $metrics['duplicate_psa']   = count(auditDuplicatePsaVault($applyPdo));
    $metrics['invalid_phone']   = countVaultInvalidPhones($applyPdo);

    if ($metrics['missing_email'] > 0) {
        $penalty += min(15, $metrics['missing_email']);
        $issues[] = $metrics['missing_email'] . ' vault rows missing email';
    }
    if ($metrics['missing_phone'] > 0) {
        $penalty += min(10, (int) floor($metrics['missing_phone'] / max(1, $metrics['total']) * 20));
        $issues[] = $metrics['missing_phone'] . ' vault rows missing phone';
    }
    if ($metrics['duplicate_phone'] > 0) {
        $penalty += min(25, $metrics['duplicate_phone'] * 5);
        $issues[] = $metrics['duplicate_phone'] . ' duplicate phone groups in vault';
    }
    if ($metrics['duplicate_psa'] > 0) {
        $penalty += min(25, $metrics['duplicate_psa'] * 5);
        $issues[] = $metrics['duplicate_psa'] . ' duplicate PSA groups in vault';
    }

    $score = max(0, min(100, 100 - $penalty));

    return ['score' => $score, 'issues' => $issues, 'metrics' => $metrics];
}

/** @return array{score: int, grade: string, factors: list<string>} */
function computeDataIntegrityScore(array $audit, ?array $vaultHealth = null): array
{
    $penalty = 0;
    $factors = [];
    $dupMainPhone  = count($audit['duplicate_phones_main'] ?? []);
    $dupVaultPhone = count($audit['duplicate_phones_vault'] ?? []);
    $dupPsa        = count($audit['duplicate_psa_vault'] ?? []);
    $dupProfiles   = count($audit['duplicate_staff_profiles'] ?? []);
    $orphans       = count($audit['orphaned'] ?? []);

    if ($dupMainPhone > 0) {
        $penalty += min(20, $dupMainPhone * 3);
        $factors[] = "{$dupMainPhone} duplicate phone groups (main ERP)";
    }
    if ($dupVaultPhone > 0) {
        $penalty += min(25, $dupVaultPhone * 4);
        $factors[] = "{$dupVaultPhone} duplicate phone groups (Apply vault)";
    }
    if ($dupPsa > 0) {
        $penalty += min(25, $dupPsa * 4);
        $factors[] = "{$dupPsa} duplicate PSA groups";
    }
    if ($dupProfiles > 0) {
        $penalty += min(15, $dupProfiles * 2);
        $factors[] = "{$dupProfiles} duplicate name+DOB profiles";
    }
    if ($orphans > 0) {
        $penalty += min(15, $orphans * 5);
        $factors[] = "{$orphans} orphan record types";
    }
    if ($vaultHealth !== null && (int) ($vaultHealth['score'] ?? 100) < 100) {
        $vh = (int) $vaultHealth['score'];
        $penalty += (int) floor((100 - $vh) / 4);
        $factors[] = 'Vault health ' . $vh . '%';
    }

    $score = max(0, min(100, 100 - $penalty));
    $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 45 ? 'D' : 'F')));

    return ['score' => $score, 'grade' => $grade, 'factors' => $factors];
}

/** @return list<array<string, mixed>> */
function buildMergeRecommendations(PDO $pdo, ?PDO $applyPdo = null): array
{
    $applyPdo ??= getApplyVaultPdo();
    $recs     = [];

    foreach (auditDuplicatePhonesMain($pdo) as $dup) {
        $ids = $dup['staff_ids'] ?? [];
        if (count($ids) < 2) {
            continue;
        }
        $recs[] = [
            'key'         => 'main_phone_' . md5((string) ($dup['phone'] ?? '')),
            'type'        => 'main_phone',
            'label'       => 'Duplicate phone (main): ' . ($dup['phone'] ?? ''),
            'record_a'    => staffRecordSummary($pdo, (int) $ids[0]),
            'record_b'    => staffRecordSummary($pdo, (int) $ids[1]),
            'recommended' => (int) ($dup['recommended'] ?? $ids[0]),
            'context'     => getStaffMergeContext($pdo, $ids),
        ];
    }

    if ($applyPdo instanceof PDO) {
        foreach (auditDuplicatePhonesVault($applyPdo) as $dup) {
            $ids = $dup['vault_ids'] ?? [];
            if (count($ids) < 2) {
                continue;
            }
            $recs[] = [
                'key'         => 'vault_phone_' . md5((string) ($dup['phone'] ?? '')),
                'type'        => 'vault_phone',
                'label'       => 'Duplicate phone (vault): ' . ($dup['phone'] ?? ''),
                'record_a'    => vaultRecordSummary($applyPdo, (int) $ids[0]),
                'record_b'    => vaultRecordSummary($applyPdo, (int) $ids[1]),
                'recommended' => (int) ($dup['recommended'] ?? $ids[0]),
                'context'     => [],
            ];
        }
    }

    return $recs;
}

/** @return array<string, mixed> */
function staffRecordSummary(PDO $pdo, int $staffId): array
{
    if ($staffId < 1) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT id, first_name, surname, email, mobile, psa_licence, created_at FROM staff WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $staffId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<string, mixed> */
function vaultRecordSummary(PDO $applyPdo, int $vaultId): array
{
    if ($vaultId < 1) {
        return [];
    }
    try {
        $stmt = $applyPdo->prepare('SELECT id, first_name, last_name, email, phone, psa_licence, profile_status FROM staff_master WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $vaultId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @param list<int> $staffIds @return array<string, int> */
function getStaffMergeContext(PDO $pdo, array $staffIds): array
{
    $ctx = ['registrations' => 0, 'attendance' => 0, 'messages' => 0, 'notifications' => 0];
    foreach ($staffIds as $sid) {
        $sid = (int) $sid;
        if ($sid < 1) {
            continue;
        }
        try {
            $stmt = $pdo->prepare('SELECT email FROM staff WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $sid]);
            $email = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
            if ($email === '') {
                continue;
            }
            $ctx['registrations'] += countRelatedRegistrations($pdo, $email);
            $ctx['attendance']    += countRelatedAttendance($pdo, $email);
            $ctx['notifications'] += countRelatedNotifications($pdo, $email);
            $ctx['messages']      += countRelatedMessages($pdo, $sid);
        } catch (Throwable $e) {
            continue;
        }
    }

    return $ctx;
}

/** @return array{registrations: int, attendance: int, messages: int, notifications: int} */
function getStaffMergeContextForId(PDO $pdo, int $staffId): array
{
    return getStaffMergeContext($pdo, [$staffId]);
}

/** @return array{distorted: bool, issues: list<string>, duplicate_staff_groups: int, multi_reg_same_event: int} */
function auditTrustScoreDataQuality(PDO $pdo): array
{
    $issues      = [];
    $dupProfiles = count(auditDuplicateStaffProfiles($pdo));
    if ($dupProfiles > 0) {
        $issues[] = "{$dupProfiles} duplicate name+DOB staff profiles may split trust history";
    }

    $multiEvent = 0;
    try {
        $multiEvent = (int) $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT email, event_id, COUNT(*) AS c FROM staff_registrations
                GROUP BY LOWER(email), event_id HAVING c > 1
            ) x
        ")->fetchColumn();
        if ($multiEvent > 0) {
            $issues[] = "{$multiEvent} duplicate email+event registration groups";
        }
    } catch (Throwable $e) {
        // ignore
    }

    return [
        'distorted'              => $issues !== [],
        'issues'                 => $issues,
        'duplicate_staff_groups' => $dupProfiles,
        'multi_reg_same_event'   => $multiEvent,
    ];
}

/** @return array<string, mixed> */
function buildProductionCleanupPlan(array $audit, array $testData, array $mergeRecs): array
{
    return [
        'phase1' => [
            'title' => 'Phase 1 — Safe fixes (no deletions)',
            'items' => [
                'Normalize phone numbers on import',
                'Use import pre-check before sync',
                'Improve skip messages with owner names',
                'Fix orphaned staff_id links (manual)',
            ],
        ],
        'phase2' => [
            'title' => 'Phase 2 — Recommended merges (admin approval required)',
            'items' => array_map(
                static fn ($r) => ($r['label'] ?? 'Merge') . ' → keep ID ' . ($r['recommended'] ?? '?'),
                array_slice($mergeRecs, 0, 25)
            ),
        ],
        'phase3' => [
            'title' => 'Phase 3 — Test account removal (explicit approval)',
            'items' => array_map(
                static fn ($a) => ($a['email'] ?? '') . ' (' . ($a['source'] ?? '') . ')',
                array_slice($testData['accounts'] ?? [], 0, 30)
            ),
        ],
        'phase4' => [
            'title' => 'Phase 4 — Data normalization',
            'items' => [
                'Reconcile vault phone conflicts with main ERP canonical mobile',
                'Clear test PSA placeholders in vault',
                'Re-run trust score refresh after approved merges',
            ],
        ],
    ];
}

function dataIntegrityReportShell(string $title, string $subtitle, string $bodyHtml, int $score = -1): string
{
    $generated = gmdate('Y-m-d H:i:s') . ' UTC';
    $scoreHtml = $score >= 0 ? '<p class="score">Score: <strong>' . $score . '%</strong></p>' : '';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;margin:2rem;background:#0f172a;color:#e2e8f0;line-height:1.5}'
        . 'h1,h2{color:#f8fafc}.meta{color:#94a3b8;font-size:.85rem}.card{background:#1e293b;border:1px solid rgba(148,163,184,.2);border-radius:12px;padding:1.25rem;margin:1rem 0}'
        . 'table{width:100%;border-collapse:collapse;font-size:.9rem}th,td{padding:.5rem;border-bottom:1px solid rgba(148,163,184,.15);text-align:left;vertical-align:top}'
        . '.score{font-size:1.1rem}</style></head><body>'
        . '<h1>' . htmlspecialchars($title) . '</h1><p class="meta">' . htmlspecialchars($subtitle) . ' · ' . htmlspecialchars($generated) . '</p>'
        . $scoreHtml . $bodyHtml . '</body></html>';
}

function dataIntegrityTable(array $headers, array $rows): string
{
    $head = '<tr>' . implode('', array_map(static fn ($h) => '<th>' . htmlspecialchars((string) $h) . '</th>', $headers)) . '</tr>';
    $body = '';
    foreach ($rows as $row) {
        $body .= '<tr>';
        foreach ($row as $cell) {
            $body .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
        }
        $body .= '</tr>';
    }

    return '<div class="card"><table><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table></div>';
}

function dismissDataIntegrityDuplicate(PDO $pdo, string $key, string $type, ?int $adminUserId, string $notes = ''): bool
{
    ensureDataIntegritySchema($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO platform_data_integrity_dismissals (duplicate_key, duplicate_type, action, admin_user_id, notes)
            VALUES (:k, :t, 'ignore', :uid, :notes)
            ON DUPLICATE KEY UPDATE action = 'ignore', notes = VALUES(notes)
        ");
        $stmt->execute(['k' => substr($key, 0, 120), 't' => substr($type, 0, 40), 'uid' => $adminUserId, 'notes' => $notes !== '' ? substr($notes, 0, 500) : null]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array{ok: bool, message: string, plan: list<string>} */
function previewStaffMergePlan(PDO $pdo, int $keepId, int $mergeId): array
{
    if ($keepId < 1 || $mergeId < 1 || $keepId === $mergeId) {
        return ['ok' => false, 'message' => 'Invalid staff IDs', 'plan' => []];
    }
    $keep = staffRecordSummary($pdo, $keepId);
    $lose = staffRecordSummary($pdo, $mergeId);
    if ($keep === [] || $lose === []) {
        return ['ok' => false, 'message' => 'Staff record not found', 'plan' => []];
    }

    return [
        'ok'      => true,
        'message' => 'Preview — execute via merge-duplicate-staff cron with confirm=1',
        'plan'    => [
            'Keep staff #' . $keepId . ' (' . dataIntegrityStaffLabel($keep) . ')',
            'Copy missing/newer profile fields from #' . $mergeId . ' onto #' . $keepId,
            'Reassign all staff_id foreign keys from #' . $mergeId . ' → #' . $keepId,
            'Delete staff row #' . $mergeId,
        ],
    ];
}

/**
 * @return list<array{reason: string, key: string, staff_ids: list<int>, recommended: int}>
 */
function findMergeableStaffDuplicateGroups(PDO $pdo): array
{
    require_once dirname(__DIR__) . '/staff-repository.php';

    $groups = [];
    $seen   = [];

    $addGroup = static function (string $reason, string $key, array $ids) use ($pdo, &$groups, &$seen): void {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids);
        if (count($ids) < 2) {
            return;
        }
        $sig = implode('-', $ids);
        if (isset($seen[$sig])) {
            return;
        }
        $seen[$sig] = true;
        $groups[] = [
            'reason'      => $reason,
            'key'         => $key,
            'staff_ids'   => $ids,
            'recommended' => recommendCanonicalStaffId($pdo, $ids),
        ];
    };

    foreach (auditDuplicateEmailsMain($pdo) as $dup) {
        $email = strtolower(trim((string) ($dup['email'] ?? '')));
        if ($email === '' || $email === 'null') {
            continue;
        }
        $addGroup('same_email', 'email:' . $email, $dup['staff_ids'] ?? []);
    }

    try {
        $psaRows = $pdo->query("
            SELECT UPPER(TRIM(psa_licence)) AS psa_key,
                   GROUP_CONCAT(id ORDER BY id) AS staff_ids,
                   COUNT(*) AS cnt
            FROM staff
            WHERE psa_licence IS NOT NULL
              AND TRIM(psa_licence) <> ''
              AND psa_licence NOT LIKE 'TEMP-PSA-%'
              AND psa_licence NOT LIKE 'EM000000/%'
            GROUP BY psa_key
            HAVING cnt > 1
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $psaRows = [];
    }
    foreach ($psaRows as $row) {
        $ids = array_map('intval', explode(',', (string) ($row['staff_ids'] ?? '')));
        if (!staffDuplicateGroupNamesCompatible($pdo, $ids)) {
            continue;
        }
        $addGroup('same_psa', 'psa:' . strtolower((string) ($row['psa_key'] ?? '')), $ids);
    }

    foreach (auditDuplicatePhonesMain($pdo) as $dup) {
        $ids = $dup['staff_ids'] ?? [];
        if (count($ids) < 2) {
            continue;
        }
        if (!staffDuplicateGroupSafeForPhoneMerge($pdo, $ids)) {
            continue;
        }
        $addGroup('same_phone_same_person', 'phone:' . preg_replace('/\D+/', '', (string) ($dup['phone'] ?? '')), $ids);
    }

    // Exact same first+surname (different emails/phones) — merge only when clearly same person.
    foreach (auditDuplicateStaffNamesMain($pdo) as $dup) {
        $ids = $dup['staff_ids'] ?? [];
        if (count($ids) < 2) {
            continue;
        }
        if (!staffDuplicateGroupSafeForNameMerge($pdo, $ids)) {
            continue;
        }
        $addGroup(
            'same_name',
            'name:' . strtolower(trim((string) ($dup['fn'] ?? '') . '|' . (string) ($dup['sn'] ?? ''))),
            $ids
        );
    }

    return $groups;
}

/** @return list<array<string, mixed>> */
function auditDuplicateStaffNamesMain(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
            SELECT LOWER(TRIM(first_name)) AS fn, LOWER(TRIM(surname)) AS sn,
                   COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS staff_ids
            FROM staff
            WHERE TRIM(first_name) <> '' AND TRIM(surname) <> ''
              AND LOWER(TRIM(surname)) NOT LIKE '%(duplicate)%'
            GROUP BY fn, sn
            HAVING cnt > 1
            ORDER BY cnt DESC
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $ids = array_map('intval', explode(',', (string) ($row['staff_ids'] ?? '')));
        $out[] = [
            'fn'          => (string) ($row['fn'] ?? ''),
            'sn'          => (string) ($row['sn'] ?? ''),
            'count'       => (int) ($row['cnt'] ?? 0),
            'staff_ids'   => $ids,
            'recommended' => recommendCanonicalStaffId($pdo, $ids),
        ];
    }

    return $out;
}

/** @param list<int> $staffIds */
function staffDuplicateGroupSafeForNameMerge(PDO $pdo, array $staffIds): bool
{
    $emails = [];
    $phones = [];
    $psas = [];
    $hasDobMatch = false;
    $dobs = [];

    foreach ($staffIds as $id) {
        $row = getStaffById($pdo, (int) $id);
        if ($row === null) {
            continue;
        }
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($row['mobile'] ?? '')) ?? '';
        $psa = strtoupper(trim((string) ($row['psa_licence'] ?? '')));
        $dob = substr(trim((string) ($row['date_of_birth'] ?? '')), 0, 10);
        if ($email !== '') {
            $emails[] = $email;
        }
        if ($phone !== '') {
            $phones[] = $phone;
        }
        if ($psa !== '' && !str_starts_with($psa, 'TEMP-PSA-') && !str_starts_with($psa, 'EM000000/')) {
            $psas[] = $psa;
        }
        if ($dob !== '') {
            $dobs[] = $dob;
        }
    }

    $emails = array_values(array_unique($emails));
    $phones = array_values(array_unique($phones));
    $psas = array_values(array_unique($psas));
    $dobs = array_values(array_unique($dobs));

    // Same DOB → same person
    if (count($dobs) === 1 && count($staffIds) >= 2) {
        $hasDobMatch = true;
    }

    // Shared phone or PSA → same person
    $sharedContact = count($phones) === 1 || count($psas) === 1;

    // Only one real email among the set (others blank) → safe to merge onto that profile
    $oneEmail = count($emails) <= 1;

    $markedDuplicate = false;
    foreach ($staffIds as $id) {
        $row = getStaffById($pdo, (int) $id);
        if ($row !== null && stripos((string) ($row['surname'] ?? ''), '(duplicate)') !== false) {
            $markedDuplicate = true;
            break;
        }
    }

    // Same phone + same name already required by caller for phone groups;
    // for name groups, also allow merge when they share a phone.
    return $hasDobMatch || $sharedContact || $oneEmail || $markedDuplicate;
}

/** @param list<int> $staffIds */
function staffDuplicateGroupNamesCompatible(PDO $pdo, array $staffIds): bool
{
    $names = [];
    foreach ($staffIds as $id) {
        $row = staffRecordSummary($pdo, (int) $id);
        if ($row === []) {
            continue;
        }
        $raw = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));
        $names[] = staffNormalizeCompareName($raw);
    }
    $names = array_values(array_filter($names, static fn (string $n): bool => $n !== ''));
    if (count($names) < 2) {
        return true;
    }
    $base = $names[0];
    foreach ($names as $n) {
        similar_text($base, $n, $pct);
        if ($pct < 70.0) {
            return false;
        }
    }

    return true;
}

function staffNormalizeCompareName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s*\(\s*duplicate\s*\)\s*/i', ' ', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;

    return trim($name);
}

/** @param list<int> $staffIds */
function staffDuplicateGroupSafeForPhoneMerge(PDO $pdo, array $staffIds): bool
{
    if (!staffDuplicateGroupNamesCompatible($pdo, $staffIds)) {
        return false;
    }
    $emails = [];
    $markedDuplicate = false;
    foreach ($staffIds as $id) {
        $row = staffRecordSummary($pdo, (int) $id);
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $surname = (string) ($row['surname'] ?? '');
        if (stripos($surname, '(duplicate)') !== false) {
            $markedDuplicate = true;
        }
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    $emails = array_values(array_unique($emails));

    // Explicit "(duplicate)" surname → merge even if emails differ.
    if ($markedDuplicate) {
        return true;
    }

    // Different real emails + same phone → likely different people; skip.
    return count($emails) <= 1;
}

/**
 * Prefer keep row; fill blanks from merge; prefer verified PSA / newer timestamps.
 *
 * @param array<string, mixed> $keep
 * @param array<string, mixed> $merge
 * @return array<string, mixed>
 */
function buildMergedStaffProfileFields(array $keep, array $merge): array
{
    $fields = [
        'surname', 'first_name', 'full_address', 'eircode', 'mobile', 'gender',
        'pps_number', 'bank_iban', 'staff_role', 'date_of_birth',
        'psa_licence', 'psa_expiry_date', 'psa_front_image', 'psa_back_image',
        'psa_register_status', 'psa_register_name', 'psa_register_checked_at',
        'email',
    ];
    $out = [];

    foreach ($fields as $field) {
        $keepVal  = trim((string) ($keep[$field] ?? ''));
        $mergeVal = trim((string) ($merge[$field] ?? ''));
        if ($field === 'email') {
            if ($keepVal === '' && $mergeVal !== '') {
                $out[$field] = $mergeVal;
            }
            continue;
        }
        if ($field === 'psa_register_status') {
            $keepRank  = $keepVal === 'verified' ? 2 : ($keepVal !== '' ? 1 : 0);
            $mergeRank = $mergeVal === 'verified' ? 2 : ($mergeVal !== '' ? 1 : 0);
            if ($mergeRank > $keepRank) {
                $out[$field] = $mergeVal;
                if ($mergeVal !== '') {
                    foreach (['psa_register_name', 'psa_register_checked_at', 'psa_licence', 'psa_expiry_date'] as $psaField) {
                        $mv = trim((string) ($merge[$psaField] ?? ''));
                        if ($mv !== '') {
                            $out[$psaField] = $mv;
                        }
                    }
                }
            }
            continue;
        }
        if ($keepVal === '' && $mergeVal !== '') {
            $out[$field] = $mergeVal;
        }
    }

    if ((int) ($keep['is_blacklisted'] ?? 0) !== 1 && (int) ($merge['is_blacklisted'] ?? 0) === 1) {
        $out['is_blacklisted'] = 1;
        $out['blacklist_reason'] = trim((string) ($merge['blacklist_reason'] ?? 'Merged from duplicate profile'));
    }

    if ((int) ($keep['profile_completed'] ?? 0) !== 1 && (int) ($merge['profile_completed'] ?? 0) === 1) {
        $out['profile_completed'] = 1;
    }

    return $out;
}

/**
 * @return array{ok: bool, keep_id: int, merge_id: int, fields_updated: list<string>, reassigned: array<string, int>, deleted: bool, error?: string}
 */
function executeStaffMerge(PDO $pdo, int $keepId, int $mergeId): array
{
    require_once dirname(__DIR__) . '/staff-repository.php';
    require_once dirname(__DIR__) . '/staff-registration-schema.php';

    if ($keepId < 1 || $mergeId < 1 || $keepId === $mergeId) {
        return ['ok' => false, 'keep_id' => $keepId, 'merge_id' => $mergeId, 'fields_updated' => [], 'reassigned' => [], 'deleted' => false, 'error' => 'Invalid IDs'];
    }

    $keep  = getStaffById($pdo, $keepId);
    $merge = getStaffById($pdo, $mergeId);
    if ($keep === null || $merge === null) {
        return ['ok' => false, 'keep_id' => $keepId, 'merge_id' => $mergeId, 'fields_updated' => [], 'reassigned' => [], 'deleted' => false, 'error' => 'Staff not found'];
    }

    $fields = buildMergedStaffProfileFields($keep, $merge);
    $reassigned = [];

    try {
        $pdo->beginTransaction();

        if ($fields !== []) {
            $sets = [];
            $params = ['id' => $keepId];
            foreach ($fields as $col => $val) {
                if (!preg_match('/^[a-z_]+$/', $col)) {
                    continue;
                }
                $sets[] = "`{$col}` = :{$col}";
                $params[$col] = $val;
            }
            if ($sets !== []) {
                $pdo->prepare('UPDATE staff SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
            }
        }

        $tableStmt = $pdo->query("
            SELECT DISTINCT TABLE_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME = 'staff_id'
              AND TABLE_NAME <> 'staff'
        ");
        foreach ($tableStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $table) {
            $table = (string) $table;
            if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
                continue;
            }
            try {
                $upd = $pdo->prepare("UPDATE `{$table}` SET staff_id = :keep WHERE staff_id = :merge");
                $upd->execute(['keep' => $keepId, 'merge' => $mergeId]);
                $reassigned[$table] = $upd->rowCount();
            } catch (Throwable $e) {
                // Unique constraint clashes — leave and continue; delete of merge may fail if still referenced.
                $reassigned[$table] = -1;
            }
        }

        $keepEmail  = strtolower(trim((string) ($fields['email'] ?? $keep['email'] ?? '')));
        $mergeEmail = strtolower(trim((string) ($merge['email'] ?? '')));
        if ($keepEmail !== '' && $mergeEmail !== '' && $keepEmail === $mergeEmail) {
            // already same
        } elseif ($keepEmail !== '' && staffRegistrationColumnExists($pdo, 'staff_id')) {
            $pdo->prepare(
                'UPDATE staff_registrations SET staff_id = :keep
                 WHERE staff_id = :merge OR (LOWER(TRIM(email)) = :email AND (staff_id IS NULL OR staff_id = 0 OR staff_id = :merge2))'
            )->execute([
                'keep' => $keepId,
                'merge' => $mergeId,
                'email' => $mergeEmail !== '' ? $mergeEmail : $keepEmail,
                'merge2' => $mergeId,
            ]);
        }

        $pdo->prepare('DELETE FROM staff WHERE id = :id')->execute(['id' => $mergeId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'keep_id' => $keepId,
            'merge_id' => $mergeId,
            'fields_updated' => array_keys($fields),
            'reassigned' => $reassigned,
            'deleted' => false,
            'error' => $e->getMessage(),
        ];
    }

    return [
        'ok' => true,
        'keep_id' => $keepId,
        'merge_id' => $mergeId,
        'fields_updated' => array_keys($fields),
        'reassigned' => $reassigned,
        'deleted' => true,
    ];
}

/**
 * @return array{ok: bool, dry_run: bool, before_staff: int, after_staff: int, groups: int, merges: list<array<string, mixed>>, errors: list<string>}
 */
function mergeAllDuplicateStaff(PDO $pdo, bool $dryRun = true): array
{
    require_once dirname(__DIR__) . '/staff-repository.php';

    $before = (int) $pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn();
    $groups = findMergeableStaffDuplicateGroups($pdo);
    $merges = [];
    $errors = [];

    foreach ($groups as $group) {
        $ids = $group['staff_ids'];
        $keepId = (int) ($group['recommended'] ?? 0);
        if ($keepId < 1 || !in_array($keepId, $ids, true)) {
            $keepId = $ids[0];
        }
        $toMerge = array_values(array_filter($ids, static fn (int $id): bool => $id !== $keepId));
        foreach ($toMerge as $mergeId) {
            if ($dryRun) {
                $keep = staffRecordSummary($pdo, $keepId);
                $lose = staffRecordSummary($pdo, $mergeId);
                $merges[] = [
                    'dry_run' => true,
                    'reason'  => $group['reason'],
                    'keep_id' => $keepId,
                    'merge_id'=> $mergeId,
                    'keep'    => dataIntegrityStaffLabel($keep),
                    'merge'   => dataIntegrityStaffLabel($lose),
                    'keep_email' => (string) ($keep['email'] ?? ''),
                    'merge_email'=> (string) ($lose['email'] ?? ''),
                ];
                continue;
            }

            // Re-resolve keep in case earlier merge already deleted an id.
            if (getStaffById($pdo, $keepId) === null) {
                $alive = [];
                foreach ($ids as $id) {
                    if (getStaffById($pdo, (int) $id) !== null) {
                        $alive[] = (int) $id;
                    }
                }
                if (count($alive) < 2) {
                    continue;
                }
                $keepId = recommendCanonicalStaffId($pdo, $alive);
                if ($mergeId === $keepId || getStaffById($pdo, $mergeId) === null) {
                    continue;
                }
            }

            $result = executeStaffMerge($pdo, $keepId, $mergeId);
            $result['reason'] = $group['reason'];
            $merges[] = $result;
            if (!$result['ok']) {
                $errors[] = 'merge #' . $mergeId . '→#' . $keepId . ': ' . (string) ($result['error'] ?? 'failed');
            }
        }
    }

    $after = (int) $pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn();

    return [
        'ok'           => $errors === [],
        'dry_run'      => $dryRun,
        'before_staff' => $before,
        'after_staff'  => $after,
        'groups'       => count($groups),
        'merges'       => $merges,
        'errors'       => $errors,
    ];
}
