<?php

declare(strict_types=1);

/**
 * Master Staff Identity — administrator presentation layer.
 * Wraps canonical identity data with business-friendly labels (no logic changes).
 */

require_once __DIR__ . '/canonical-identity.php';

function masterStaffIdentityActionLabel(string $action): string
{
    return match ($action) {
        'email_normalized'     => 'Primary email updated',
        'duplicate_blocked'    => 'Duplicate prevented',
        'duplicate_rejected'   => 'Duplicate prevented',
        'mobile_alias_login'   => 'Alias email detected',
        'repair_backup'        => 'Backup created before repair',
        default                => ucwords(str_replace('_', ' ', $action)),
    };
}

function masterStaffIdentitySourceLabel(string $source): string
{
    $map = [
        'registration_save'       => 'Registration form',
        'registration_batch_save' => 'Registration form',
        'status_approve'          => 'Admin approval',
        'status_approve_final'    => 'Admin approval',
        'nightly_integrity'       => 'Nightly audit',
        'mobile_auth'             => 'Mobile app login',
        'change_staff_email'      => 'Admin staff edit',
    ];

    return $map[$source] ?? ucwords(str_replace('_', ' ', $source));
}

/** @return array<string, mixed> */
function masterStaffIdentityGetManagerData(PDO $pdo, ?string $search = null): array
{
    ensureCanonicalIdentitySchema($pdo);
    require_once dirname(__DIR__) . '/settings-repository.php';

    $monitoring = canonicalIdentityGetMonitoringDashboard($pdo);
    $audit      = $monitoring['integrity'] ?? [];

    $totalStaff = (int) $pdo->query(
        'SELECT COUNT(*) FROM staff WHERE COALESCE(is_blacklisted, 0) = 0'
    )->fetchColumn();

    $activeStaff = (int) $pdo->query(
        "SELECT COUNT(DISTINCT s.id) FROM staff s
         INNER JOIN staff_registrations sr ON sr.staff_id = s.id
         WHERE sr.status = 'approved' AND COALESCE(s.is_blacklisted, 0) = 0"
    )->fetchColumn();

    $applicants = (int) $pdo->query(
        "SELECT COUNT(DISTINCT s.id) FROM staff s
         WHERE COALESCE(s.is_blacklisted, 0) = 0
           AND EXISTS (
               SELECT 1 FROM staff_registrations sr
               WHERE sr.staff_id = s.id AND sr.status = 'pending'
           )
           AND NOT EXISTS (
               SELECT 1 FROM staff_registrations sr
               WHERE sr.staff_id = s.id AND sr.status = 'approved'
           )"
    )->fetchColumn();

    $duplicatePrevented = (int) $pdo->query(
        "SELECT COUNT(*) FROM canonical_identity_audit
         WHERE action IN ('duplicate_blocked', 'duplicate_rejected')"
    )->fetchColumn();

    $aliasEmailEvents = (int) $pdo->query(
        "SELECT COUNT(*) FROM canonical_identity_audit
         WHERE action IN ('email_normalized', 'mobile_alias_login')"
    )->fetchColumn();

    $dupPpsAttempts = (int) $pdo->query(
        "SELECT COUNT(*) FROM canonical_identity_bypass_log
         WHERE details LIKE '%pps%' OR source LIKE '%pps%'"
    )->fetchColumn();

    $identityConflicts = (int) ($audit['staff_with_multiple_approved_emails'] ?? 0)
        + (int) ($audit['duplicate_approved_staff_event'] ?? 0)
        + (int) ($audit['alias_approved_registrations'] ?? 0);

    $dupPpsGroups = 0;
    $dupMobileGroups = 0;
    $dupPsaGroups = 0;
    $dupEmailGroups = 0;
    try {
        $dupPpsGroups = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM staff WHERE TRIM(COALESCE(pps_number, '')) <> ''
                GROUP BY UPPER(REPLACE(TRIM(pps_number), ' ', '')) HAVING COUNT(*) > 1
             ) t"
        )->fetchColumn();
        $dupMobileGroups = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM staff WHERE TRIM(COALESCE(mobile, '')) <> ''
                GROUP BY REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '+', '') HAVING COUNT(*) > 1
             ) t"
        )->fetchColumn();
        $dupPsaGroups = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM staff
                WHERE TRIM(COALESCE(psa_licence, '')) <> ''
                  AND UPPER(TRIM(psa_licence)) NOT LIKE 'TEMP-PSA-%'
                GROUP BY UPPER(TRIM(psa_licence)) HAVING COUNT(*) > 1
             ) t"
        )->fetchColumn();
        $dupEmailGroups = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM staff WHERE TRIM(COALESCE(email, '')) <> ''
                GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1
             ) t"
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('[EventStaff] masterStaffIdentityGetManagerData dup counts: ' . $e->getMessage());
    }

    $protectionPass = $identityConflicts === 0
        && $dupPpsGroups === 0
        && $dupMobileGroups === 0
        && $dupPsaGroups === 0
        && $dupEmailGroups === 0;

    $staffRows = masterStaffIdentitySearchStaff($pdo, $search);

    $history = $pdo->query(
        "SELECT id, staff_id, registration_id, submitted_email, canonical_email,
                action, source, details, created_at
         FROM canonical_identity_audit
         ORDER BY id DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lastNightly = $monitoring['nightly_runs'][0] ?? null;

    return [
        'summary' => [
            'total_staff'                  => $totalStaff,
            'active_staff'                 => $activeStaff,
            'applicants'                   => $applicants,
            'identity_conflicts'           => $identityConflicts,
            'duplicate_attempts_prevented' => $duplicatePrevented,
            'alias_email_events'           => $aliasEmailEvents,
            'duplicate_pps_attempts'       => max($dupPpsAttempts, $dupPpsGroups),
            'duplicate_mobile_attempts'    => $dupMobileGroups,
            'duplicate_psa_attempts'       => $dupPsaGroups,
        ],
        'protection' => [
            'active'            => $protectionPass && !empty($audit['pass']),
            'duplicate_staff'   => (int) ($audit['duplicate_approved_staff_event'] ?? 0),
            'duplicate_emails'  => (int) ($audit['staff_with_multiple_approved_emails'] ?? 0),
            'duplicate_ppsn'    => $dupPpsGroups,
            'duplicate_mobile'  => $dupMobileGroups,
            'duplicate_psa'     => $dupPsaGroups,
            'identity_conflicts'=> $identityConflicts,
        ],
        'staff'          => $staffRows,
        'history'        => $history,
        'nightly'        => [
            'last_at'              => trim((string) ($monitoring['last_nightly_at'] ?? '')),
            'last_run'             => $lastNightly,
            'issues_found'         => $lastNightly !== null && empty($lastNightly['integrity_pass']) ? 1 : 0,
            'issues_fixed_auto'    => (int) ($lastNightly['registrations_updated'] ?? 0),
            'manual_review_required'=> (int) ($lastNightly['manual_review_count'] ?? 0),
        ],
        'version'        => $monitoring['version'] ?? [],
        'search'         => $search ?? '',
        'integrity'      => $audit,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function masterStaffIdentitySearchStaff(PDO $pdo, ?string $search = null, int $limit = 75): array
{
    $search = trim((string) $search);
    $params = [];
    $where  = 'WHERE COALESCE(s.is_blacklisted, 0) = 0';

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where .= ' AND (
            CAST(s.id AS CHAR) = :exact_id
            OR LOWER(CONCAT(s.first_name, \' \', s.surname)) LIKE LOWER(:name_like)
            OR LOWER(s.email) LIKE LOWER(:email_like)
            OR LOWER(s.mobile) LIKE LOWER(:mobile_like)
            OR LOWER(s.pps_number) LIKE LOWER(:pps_like)
            OR UPPER(s.psa_licence) LIKE UPPER(:psa_like)
            OR EXISTS (
                SELECT 1 FROM staff_registrations sr
                WHERE sr.staff_id = s.id
                  AND LOWER(COALESCE(sr.submitted_email, \'\')) LIKE LOWER(:alias_like)
            )
        )';
        $params = [
            'exact_id'   => $search,
            'name_like'  => $like,
            'email_like' => $like,
            'mobile_like'=> $like,
            'pps_like'   => $like,
            'psa_like'   => $like,
            'alias_like' => $like,
        ];
    }

    $sql = "SELECT s.id, s.first_name, s.surname, s.email, s.mobile, s.pps_number,
                   s.psa_licence, s.staff_role, s.created_at, s.updated_at,
                   s.is_blacklisted,
                   (SELECT COUNT(*) FROM staff_registrations sr WHERE sr.staff_id = s.id AND sr.status = 'approved') AS approved_count,
                   (SELECT COUNT(*) FROM staff_registrations sr WHERE sr.staff_id = s.id AND sr.status = 'pending') AS pending_count
            FROM staff s
            {$where}
            ORDER BY s.updated_at DESC, s.id DESC
            LIMIT " . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $i => $row) {
        $staffId = (int) ($row['id'] ?? 0);
        $rows[$i]['alias_emails'] = masterStaffIdentityAliasEmailsForStaff($pdo, $staffId, (string) ($row['email'] ?? ''));
        $rows[$i]['status_label']  = masterStaffIdentityStaffStatusLabel($row);
    }

    return $rows;
}

/** @return list<string> */
function masterStaffIdentityAliasEmailsForStaff(PDO $pdo, int $staffId, string $primaryEmail): array
{
    if ($staffId < 1) {
        return [];
    }

    $primaryEmail = strtolower(trim($primaryEmail));
    $aliases      = [];

    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT LOWER(TRIM(submitted_email)) AS alias
             FROM staff_registrations
             WHERE staff_id = :sid AND submitted_email IS NOT NULL AND TRIM(submitted_email) <> ''"
        );
        $stmt->execute(['sid' => $staffId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $alias) {
            $alias = strtolower(trim((string) $alias));
            if ($alias !== '' && $alias !== $primaryEmail) {
                $aliases[$alias] = $alias;
            }
        }

        $stmt = $pdo->prepare(
            "SELECT DISTINCT LOWER(TRIM(submitted_email)) AS alias
             FROM canonical_identity_audit
             WHERE staff_id = :sid AND submitted_email IS NOT NULL AND TRIM(submitted_email) <> ''"
        );
        $stmt->execute(['sid' => $staffId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $alias) {
            $alias = strtolower(trim((string) $alias));
            if ($alias !== '' && $alias !== $primaryEmail) {
                $aliases[$alias] = $alias;
            }
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] masterStaffIdentityAliasEmailsForStaff: ' . $e->getMessage());
    }

    return array_values($aliases);
}

/** @param array<string, mixed> $row */
function masterStaffIdentityStaffStatusLabel(array $row): string
{
    if (!empty($row['is_blacklisted'])) {
        return 'Blacklisted';
    }
    $approved = (int) ($row['approved_count'] ?? 0);
    $pending  = (int) ($row['pending_count'] ?? 0);
    if ($approved > 0) {
        return 'Active';
    }
    if ($pending > 0) {
        return 'Applicant';
    }

    return 'Registered';
}
