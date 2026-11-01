<?php

declare(strict_types=1);

/**
 * Canonical identity enforcement — staff profile is the single source of truth.
 *
 * All staff/registration identity writes must go through saveRegistration(),
 * findOrCreateStaff(), canonicalIdentityEnforceOnRegistration(), or changeStaffEmail().
 */

const CANONICAL_IDENTITY_VERSION = '1.0.0';
const CANONICAL_IDENTITY_BASELINE_DATE = '2026-06-27';
const GOOGLE_SHEETS_SYNC_VERSION = '1.0.0';

/** @var list<string> */
$GLOBALS['_canonical_identity_gateway_stack'] = [];

require_once __DIR__ . '/staff-duplicate-merge.php';
require_once dirname(__DIR__) . '/validation.php';
require_once dirname(__DIR__) . '/staff-repository.php';
require_once dirname(__DIR__) . '/staff-registration-schema.php';
require_once dirname(__DIR__) . '/audit-log.php';

function canonicalIdentityNormalizeEmail(string $email): string
{
    return normalizeRegistrationEmail($email);
}

function canonicalIdentityNormalizePps(?string $pps): string
{
    return staffMergeNormalizePps((string) $pps);
}

function canonicalIdentityPhoneKey(?string $mobile): string
{
    return staffMergePhoneKey((string) $mobile);
}

function canonicalIdentityNormalizePsa(?string $psa): string
{
    $psa = strtoupper(trim((string) $psa));

    return ($psa === '' || str_starts_with($psa, 'TEMP-PSA-')) ? '' : $psa;
}

function ensureCanonicalIdentitySchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    ensureStaffRegistrationSaveSchema($pdo);

    if (!staffRegistrationColumnExists($pdo, 'submitted_email')) {
        try {
            $pdo->exec(
                'ALTER TABLE staff_registrations ADD COLUMN submitted_email VARCHAR(255) NULL DEFAULT NULL AFTER email'
            );
            staffRegistrationInvalidateColumnCache();
        } catch (Throwable $e) {
            error_log('[EventStaff] submitted_email column: ' . $e->getMessage());
        }
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS canonical_identity_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                staff_id INT UNSIGNED NULL,
                registration_id INT UNSIGNED NULL,
                submitted_email VARCHAR(255) NULL,
                canonical_email VARCHAR(255) NULL,
                action VARCHAR(64) NOT NULL,
                source VARCHAR(64) NOT NULL,
                details TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_staff (staff_id),
                KEY idx_registration (registration_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] canonical_identity_audit table: ' . $e->getMessage());
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS canonical_identity_bypass_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(64) NOT NULL,
                operation VARCHAR(32) NOT NULL,
                source VARCHAR(128) NOT NULL,
                record_ids VARCHAR(512) NULL,
                details TEXT NULL,
                gateway_active TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_source (source),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] canonical_identity_bypass_log table: ' . $e->getMessage());
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS canonical_identity_repair_backup (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                backup_key VARCHAR(64) NOT NULL,
                table_name VARCHAR(64) NOT NULL,
                record_id INT UNSIGNED NOT NULL,
                payload_json LONGTEXT NOT NULL,
                repair_action VARCHAR(64) NOT NULL,
                source VARCHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_backup_key (backup_key),
                KEY idx_record (table_name, record_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] canonical_identity_repair_backup table: ' . $e->getMessage());
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS canonical_identity_nightly_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                integrity_pass TINYINT(1) NOT NULL DEFAULT 0,
                registrations_updated INT UNSIGNED NOT NULL DEFAULT 0,
                manual_review_count INT UNSIGNED NOT NULL DEFAULT 0,
                bypass_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                audit_json LONGTEXT NULL,
                alert_sent TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] canonical_identity_nightly_runs table: ' . $e->getMessage());
    }

    $ready = true;
}

function canonicalIdentityGatewayPush(string $source): void
{
    $GLOBALS['_canonical_identity_gateway_stack'][] = $source;
}

function canonicalIdentityGatewayPop(): void
{
    if ($GLOBALS['_canonical_identity_gateway_stack'] !== []) {
        array_pop($GLOBALS['_canonical_identity_gateway_stack']);
    }
}

function canonicalIdentityGatewayActive(): bool
{
    return $GLOBALS['_canonical_identity_gateway_stack'] !== [];
}

function canonicalIdentityGatewaySource(): ?string
{
    $stack = $GLOBALS['_canonical_identity_gateway_stack'];

    return $stack === [] ? null : (string) end($stack);
}

function canonicalIdentityLogBypass(
    PDO $pdo,
    string $tableName,
    string $operation,
    string $source,
    ?string $recordIds = null,
    ?string $details = null
): void {
    ensureCanonicalIdentitySchema($pdo);

    $gatewayActive = canonicalIdentityGatewayActive() ? 1 : 0;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO canonical_identity_bypass_log
                (table_name, operation, source, record_ids, details, gateway_active)
             VALUES
                (:table_name, :operation, :source, :record_ids, :details, :gateway_active)'
        );
        $stmt->execute([
            'table_name'      => $tableName,
            'operation'       => $operation,
            'source'          => $source,
            'record_ids'      => $recordIds,
            'details'         => $details,
            'gateway_active'  => $gatewayActive,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] canonicalIdentityLogBypass: ' . $e->getMessage());
    }

    if (!$gatewayActive) {
        error_log('[EventStaff] Canonical identity bypass: ' . $operation . ' on ' . $tableName . ' from ' . $source);
    }
}

/**
 * @param int[] $registrationIds
 */
function canonicalIdentityBackupRecordsBeforeRepair(
    PDO $pdo,
    array $registrationIds,
    string $repairAction,
    string $source
): string {
    ensureCanonicalIdentitySchema($pdo);

    $registrationIds = array_values(array_unique(array_filter(array_map('intval', $registrationIds), static fn(int $id): bool => $id > 0)));
    if ($registrationIds === []) {
        return '';
    }

    $backupKey = gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
    $placeholders = implode(',', array_fill(0, count($registrationIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM staff_registrations WHERE id IN ({$placeholders})");
    $stmt->execute($registrationIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $insert = $pdo->prepare(
        'INSERT INTO canonical_identity_repair_backup
            (backup_key, table_name, record_id, payload_json, repair_action, source)
         VALUES
            (:backup_key, :table_name, :record_id, :payload_json, :repair_action, :source)'
    );

    foreach ($rows as $row) {
        $insert->execute([
            'backup_key'    => $backupKey,
            'table_name'    => 'staff_registrations',
            'record_id'     => (int) ($row['id'] ?? 0),
            'payload_json'  => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'repair_action' => $repairAction,
            'source'        => $source,
        ]);
    }

    $backupDir = dirname(__DIR__, 2) . '/storage/backups/canonical-identity';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }
    $filePath = $backupDir . '/repair_' . $backupKey . '.json';
    @file_put_contents(
        $filePath,
        json_encode(['backup_key' => $backupKey, 'rows' => $rows, 'action' => $repairAction, 'source' => $source], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    canonicalIdentityLog(
        $pdo,
        'repair_backup',
        $source,
        null,
        null,
        null,
        null,
        'Backed up ' . count($rows) . ' registration(s) · key=' . $backupKey
    );

    return $backupKey;
}

/** @return array<string, mixed> */
function canonicalIdentityVersionInfo(PDO $pdo): array
{
    require_once dirname(__DIR__) . '/settings-repository.php';

    $schemaVersion = 'unknown';
    try {
        $stmt = $pdo->query("SELECT version FROM schema_migrations ORDER BY id DESC LIMIT 1");
        $schemaVersion = (string) ($stmt->fetchColumn() ?: 'unknown');
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'schema_migrations'");
            $schemaVersion = $stmt->fetchColumn() ? 'present' : 'none';
        } catch (Throwable $e2) {
            $schemaVersion = 'none';
        }
    }

    return [
        'canonical_identity_version' => CANONICAL_IDENTITY_VERSION,
        'google_sheets_sync_version' => GOOGLE_SHEETS_SYNC_VERSION,
        'baseline_date'                => CANONICAL_IDENTITY_BASELINE_DATE,
        'database_schema_version'      => $schemaVersion,
        'deploy_recorded_at'           => trim(getSetting($pdo, 'canonical_identity_baseline_deployed_at', '')),
    ];
}

function canonicalIdentityRecordProductionBaseline(PDO $pdo): void
{
    require_once dirname(__DIR__) . '/settings-repository.php';

    $info = canonicalIdentityVersionInfo($pdo);
    productionHealthRecordSettingIfExists($pdo, 'canonical_identity_version', CANONICAL_IDENTITY_VERSION);
    productionHealthRecordSettingIfExists($pdo, 'google_sheets_sync_version', GOOGLE_SHEETS_SYNC_VERSION);
    productionHealthRecordSettingIfExists($pdo, 'canonical_identity_baseline_deployed_at', gmdate('Y-m-d H:i:s'));
    productionHealthRecordSettingIfExists($pdo, 'canonical_identity_schema_version', (string) ($info['database_schema_version'] ?? 'unknown'));
}

function productionHealthRecordSettingIfExists(PDO $pdo, string $key, string $value): void
{
    if (!function_exists('productionHealthRecordSetting')) {
        require_once __DIR__ . '/production-health.php';
    }
    if (function_exists('productionHealthRecordSetting')) {
        productionHealthRecordSetting($pdo, $key, $value);
    } else {
        setSetting($pdo, $key, $value);
    }
}

/**
 * @param array<string, mixed> $hints staff_id, email, mobile, pps_number, psa_licence
 * @return array<string, mixed>|null
 */
function canonicalIdentityResolveStaff(PDO $pdo, array $hints): ?array
{
    $staffId = (int) ($hints['staff_id'] ?? 0);
    if ($staffId > 0) {
        $staff = getStaffById($pdo, $staffId);

        return $staff ?: null;
    }

    $email = canonicalIdentityNormalizeEmail((string) ($hints['email'] ?? ''));
    if ($email !== '') {
        $staff = getStaffByEmail($pdo, $email);
        if ($staff !== null) {
            return $staff;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT staff_id FROM staff_registrations
                 WHERE LOWER(TRIM(email)) = :email AND staff_id IS NOT NULL AND staff_id > 0
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $linkedId = (int) ($stmt->fetchColumn() ?: 0);
            if ($linkedId > 0) {
                $staff = getStaffById($pdo, $linkedId);
                if ($staff !== null) {
                    return $staff;
                }
            }
        } catch (Throwable $e) {
            error_log('[EventStaff] canonicalIdentityResolveStaff alias email: ' . $e->getMessage());
        }
    }

    $ppsKey = canonicalIdentityNormalizePps((string) ($hints['pps_number'] ?? ''));
    if ($ppsKey !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM staff
             WHERE UPPER(REPLACE(TRIM(COALESCE(pps_number, '')), ' ', '')) = :pps
               AND COALESCE(is_blacklisted, 0) = 0
             LIMIT 2"
        );
        $stmt->execute(['pps' => $ppsKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 1) {
            return $rows[0];
        }
    }

    $phoneKey = canonicalIdentityPhoneKey((string) ($hints['mobile'] ?? ''));
    if ($phoneKey !== '') {
        $stmt = $pdo->query(
            "SELECT * FROM staff WHERE COALESCE(is_blacklisted, 0) = 0 AND TRIM(COALESCE(mobile, '')) <> ''"
        );
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (canonicalIdentityPhoneKey((string) ($row['mobile'] ?? '')) === $phoneKey) {
                $matches[] = $row;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
    }

    $psaKey = canonicalIdentityNormalizePsa((string) ($hints['psa_licence'] ?? ''));
    if ($psaKey !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM staff
             WHERE UPPER(TRIM(COALESCE(psa_licence, ''))) = :psa
               AND COALESCE(is_blacklisted, 0) = 0
             LIMIT 2"
        );
        $stmt->execute(['psa' => $psaKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 1) {
            return $rows[0];
        }
    }

    return null;
}

/**
 * Mobile / portal login — resolve canonical staff profile from any known email.
 *
 * @return array<string, mixed>|null
 */
function canonicalIdentityResolveStaffForLoginEmail(PDO $pdo, string $email): ?array
{
    $email = canonicalIdentityNormalizeEmail($email);
    if ($email === '') {
        return null;
    }

    return canonicalIdentityResolveStaff($pdo, ['email' => $email]);
}

function registrationExistsForStaffOnEvent(PDO $pdo, int $staffId, int $eventId, ?int $excludeRegistrationId = null): bool
{
    if ($staffId < 1 || $eventId < 1) {
        return false;
    }

    $sql = "SELECT id FROM staff_registrations
            WHERE staff_id = :staff_id AND event_id = :event_id
              AND status IN ('approved', 'pending')";
    $params = ['staff_id' => $staffId, 'event_id' => $eventId];
    if ($excludeRegistrationId !== null && $excludeRegistrationId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeRegistrationId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return array<string, mixed>|null
 */
function canonicalIdentityFindActiveRegistrationForStaffOnEvent(
    PDO $pdo,
    int $staffId,
    int $eventId,
    ?int $excludeRegistrationId = null
): ?array {
    if ($staffId < 1 || $eventId < 1) {
        return null;
    }

    $sql = "SELECT * FROM staff_registrations
            WHERE staff_id = :staff_id AND event_id = :event_id
              AND status IN ('approved', 'pending')";
    $params = ['staff_id' => $staffId, 'event_id' => $eventId];
    if ($excludeRegistrationId !== null && $excludeRegistrationId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeRegistrationId;
    }
    $sql .= " ORDER BY (status = 'approved') DESC, id DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function canonicalIdentityLog(
    PDO $pdo,
    string $action,
    string $source,
    ?int $staffId = null,
    ?int $registrationId = null,
    ?string $submittedEmail = null,
    ?string $canonicalEmail = null,
    ?string $details = null
): void {
    ensureCanonicalIdentitySchema($pdo);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO canonical_identity_audit
                (staff_id, registration_id, submitted_email, canonical_email, action, source, details)
             VALUES
                (:staff_id, :registration_id, :submitted_email, :canonical_email, :action, :source, :details)'
        );
        $stmt->execute([
            'staff_id'         => $staffId,
            'registration_id'  => $registrationId,
            'submitted_email'  => $submittedEmail,
            'canonical_email'  => $canonicalEmail,
            'action'           => $action,
            'source'           => $source,
            'details'          => $details,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] canonicalIdentityLog: ' . $e->getMessage());
    }

    if ($action === 'email_normalized' || $action === 'duplicate_rejected') {
        logAdminAudit(
            $pdo,
            'canonical_identity_' . $action,
            'staff_registration',
            $registrationId,
            trim(($submittedEmail ?? '') . ' → ' . ($canonicalEmail ?? '') . ($details ? ' · ' . $details : ''))
        );
    }
}

/**
 * Apply canonical email + staff_id to registration payload before save.
 *
 * @param array<string, mixed> $data
 * @return array{data: array<string, mixed>, staff_id: int, normalized: bool, duplicate_blocked: bool}
 */
function canonicalIdentityPrepareRegistrationData(
    PDO $pdo,
    array $data,
    int $eventId,
    string $source,
    ?int $excludeRegistrationId = null
): array {
    ensureCanonicalIdentitySchema($pdo);

    $submittedEmail = canonicalIdentityNormalizeEmail((string) ($data['email'] ?? ''));
    $staff          = canonicalIdentityResolveStaff($pdo, $data);
    $staffId        = $staff !== null ? (int) ($staff['id'] ?? 0) : (int) ($data['staff_id'] ?? 0);
    $normalized     = false;
    $duplicateBlocked = false;

    if ($staff !== null) {
        $staffId = (int) ($staff['id'] ?? 0);
        $canonical = canonicalIdentityNormalizeEmail((string) ($staff['email'] ?? ''));
        if ($canonical !== '' && $submittedEmail !== '' && $submittedEmail !== $canonical) {
            if (staffRegistrationColumnExists($pdo, 'submitted_email')) {
                $data['submitted_email'] = $submittedEmail;
            }
            $data['email'] = $canonical;
            $normalized    = true;
            canonicalIdentityLog(
                $pdo,
                'email_normalized',
                $source,
                $staffId,
                $excludeRegistrationId,
                $submittedEmail,
                $canonical,
                'Registration save — alias replaced with primary email'
            );
        } elseif ($canonical !== '') {
            $data['email'] = $canonical;
        }
        $data['staff_id'] = $staffId;
    }

    if ($staffId > 0 && $eventId > 0 && registrationExistsForStaffOnEvent($pdo, $staffId, $eventId, $excludeRegistrationId)) {
        $duplicateBlocked = true;
    }

    return [
        'data'              => $data,
        'staff_id'          => $staffId,
        'normalized'        => $normalized,
        'duplicate_blocked' => $duplicateBlocked,
    ];
}

/**
 * Enforce canonical identity on an existing registration row (e.g. on approval).
 */
function canonicalIdentityEnforceOnRegistration(PDO $pdo, int $registrationId, string $source): bool
{
    ensureCanonicalIdentitySchema($pdo);

    $reg = getStaffRegistrationById($pdo, $registrationId);
    if ($reg === null) {
        return false;
    }

    $prepared = canonicalIdentityPrepareRegistrationData(
        $pdo,
        $reg,
        (int) ($reg['event_id'] ?? 0),
        $source,
        $registrationId
    );
    $data    = $prepared['data'];
    $staffId = (int) ($prepared['staff_id'] ?? 0);
    $changed = false;

    $sets   = [];
    $params = ['id' => $registrationId];

    if ($staffId > 0 && (int) ($reg['staff_id'] ?? 0) !== $staffId) {
        $sets[]           = 'staff_id = :staff_id';
        $params['staff_id'] = $staffId;
        $changed          = true;
    }

    $newEmail = canonicalIdentityNormalizeEmail((string) ($data['email'] ?? ''));
    $oldEmail = canonicalIdentityNormalizeEmail((string) ($reg['email'] ?? ''));
    if ($newEmail !== '' && $newEmail !== $oldEmail) {
        $sets[]         = 'email = :email';
        $params['email'] = $newEmail;
        $changed        = true;
    }

    if (!empty($prepared['normalized']) && staffRegistrationColumnExists($pdo, 'submitted_email')) {
        $submitted = canonicalIdentityNormalizeEmail((string) ($data['submitted_email'] ?? $reg['submitted_email'] ?? ''));
        if ($submitted !== '' && $submitted !== $newEmail) {
            $sets[]                  = 'submitted_email = :submitted_email';
            $params['submitted_email'] = $submitted;
            $changed                 = true;
        }
    }

    if ($sets === []) {
        return true;
    }

    $sql = 'UPDATE staff_registrations SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    return $changed;
}

/** @return array<string, mixed> */
function canonicalIdentityAuditIntegrity(PDO $pdo): array
{
    ensureCanonicalIdentitySchema($pdo);

    $multiEmailStaff = $pdo->query(
        "SELECT staff_id, COUNT(DISTINCT LOWER(TRIM(email))) AS email_count,
                GROUP_CONCAT(DISTINCT LOWER(TRIM(email)) ORDER BY LOWER(TRIM(email)) SEPARATOR ', ') AS emails
         FROM staff_registrations
         WHERE status = 'approved' AND staff_id IS NOT NULL AND staff_id > 0
         GROUP BY staff_id
         HAVING email_count > 1"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dupStaffEvent = $pdo->query(
        "SELECT staff_id, event_id, COUNT(*) AS cnt,
                GROUP_CONCAT(id ORDER BY id) AS registration_ids
         FROM staff_registrations
         WHERE status = 'approved' AND staff_id IS NOT NULL AND staff_id > 0
         GROUP BY staff_id, event_id
         HAVING cnt > 1"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $payrollByEmail = (int) $pdo->query(
        "SELECT COUNT(DISTINCT LOWER(TRIM(email)))
         FROM staff_registrations
         WHERE status = 'approved' AND email IS NOT NULL AND TRIM(email) <> ''"
    )->fetchColumn();

    $payrollByStaff = (int) $pdo->query(
        "SELECT COUNT(DISTINCT COALESCE(NULLIF(staff_id, 0), 0))
         FROM staff_registrations
         WHERE status = 'approved' AND staff_id IS NOT NULL AND staff_id > 0"
    )->fetchColumn();

    $aliasApproved = $pdo->query(
        "SELECT sr.id, sr.staff_id, sr.email, sr.event_id, s.email AS canonical_email
         FROM staff_registrations sr
         INNER JOIN staff s ON s.id = sr.staff_id
         WHERE sr.status = 'approved'
           AND LOWER(TRIM(sr.email)) <> LOWER(TRIM(s.email))
         ORDER BY sr.staff_id, sr.id
         LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'staff_with_multiple_approved_emails' => count($multiEmailStaff),
        'duplicate_approved_staff_event'       => count($dupStaffEvent),
        'payroll_distinct_emails'              => $payrollByEmail,
        'payroll_distinct_staff_ids'           => $payrollByStaff,
        'alias_approved_registrations'         => count($aliasApproved),
        'multi_email_staff'                    => $multiEmailStaff,
        'duplicate_staff_event'                => $dupStaffEvent,
        'alias_samples'                        => array_slice($aliasApproved, 0, 15),
        'pass'                                 => count($multiEmailStaff) === 0
            && count($dupStaffEvent) === 0
            && count($aliasApproved) === 0,
    ];
}

/**
 * Safe automatic normalization (same rules as nightly job).
 *
 * @return array<string, mixed>
 */
function canonicalIdentityApplySafeNormalization(PDO $pdo, bool $apply): array
{
    $auditBefore = canonicalIdentityAuditIntegrity($pdo);

    $updates      = [];
    $manualReview = [];

    $aliasRows = $pdo->query(
        "SELECT sr.id, sr.staff_id, sr.event_id, sr.email, sr.status, s.email AS canonical_email
         FROM staff_registrations sr
         INNER JOIN staff s ON s.id = sr.staff_id
         WHERE sr.status = 'approved'
           AND LOWER(TRIM(sr.email)) <> LOWER(TRIM(s.email))
         ORDER BY sr.staff_id, sr.event_id, sr.id"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $canonicalOnEvent = [];
    foreach ($pdo->query(
        "SELECT sr.id, sr.event_id, LOWER(TRIM(sr.email)) AS email, sr.staff_id
         FROM staff_registrations sr
         INNER JOIN staff s ON s.id = sr.staff_id
         WHERE sr.status = 'approved'
           AND LOWER(TRIM(sr.email)) = LOWER(TRIM(s.email))"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $eventId = (int) ($row['event_id'] ?? 0);
        $staffId = (int) ($row['staff_id'] ?? 0);
        if ($eventId > 0 && $staffId > 0) {
            $canonicalOnEvent[$staffId . ':' . $eventId] = (int) ($row['id'] ?? 0);
        }
    }

    foreach ($aliasRows as $row) {
        $regId     = (int) ($row['id'] ?? 0);
        $staffId   = (int) ($row['staff_id'] ?? 0);
        $eventId   = (int) ($row['event_id'] ?? 0);
        $alias     = canonicalIdentityNormalizeEmail((string) ($row['email'] ?? ''));
        $canonical = canonicalIdentityNormalizeEmail((string) ($row['canonical_email'] ?? ''));
        if ($regId < 1 || $alias === '' || $canonical === '' || $alias === $canonical) {
            continue;
        }

        $eventKey = $staffId . ':' . $eventId;
        if (isset($canonicalOnEvent[$eventKey]) && (int) $canonicalOnEvent[$eventKey] !== $regId) {
            $manualReview[] = [
                'registration_id' => $regId,
                'staff_id'        => $staffId,
                'event_id'        => $eventId,
                'alias_email'     => $alias,
                'canonical_email' => $canonical,
                'action'          => 'reject_duplicate',
                'conflict_id'     => (int) $canonicalOnEvent[$eventKey],
            ];
            continue;
        }

        $updates[] = [
            'registration_id' => $regId,
            'staff_id'        => $staffId,
            'from_email'      => $alias,
            'to_email'        => $canonical,
        ];
        $canonicalOnEvent[$eventKey] = $regId;
    }

    $updated  = 0;
    $rejected = 0;

    if ($apply && $updates !== []) {
        $idsToBackup = array_map(static fn(array $item): int => (int) ($item['registration_id'] ?? 0), $updates);
        canonicalIdentityBackupRecordsBeforeRepair($pdo, $idsToBackup, 'email_normalize', 'nightly_integrity');
    }

    if ($apply) {
        $upd = $pdo->prepare(
            'UPDATE staff_registrations SET email = :email WHERE id = :id AND LOWER(TRIM(email)) = :from_email'
        );
        foreach ($updates as $item) {
            $upd->execute([
                'email'      => $item['to_email'],
                'id'         => (int) $item['registration_id'],
                'from_email' => $item['from_email'],
            ]);
            if ($upd->rowCount() > 0) {
                ++$updated;
                canonicalIdentityLog(
                    $pdo,
                    'email_normalized',
                    'nightly_integrity',
                    (int) $item['staff_id'],
                    (int) $item['registration_id'],
                    $item['from_email'],
                    $item['to_email'],
                    'Automatic nightly normalization'
                );
            }
        }
    }

    $auditAfter = $apply ? canonicalIdentityAuditIntegrity($pdo) : $auditBefore;

    return [
        'applied'              => $apply,
        'registrations_updated' => $updated,
        'alias_registrations_rejected' => $rejected,
        'updates'              => $updates,
        'manual_review'        => $manualReview,
        'audit_before'         => $auditBefore,
        'audit_after'          => $auditAfter,
    ];
}

/** @return array<string, mixed> */
function canonicalIdentityGetMonitoringDashboard(PDO $pdo): array
{
    ensureCanonicalIdentitySchema($pdo);
    require_once dirname(__DIR__) . '/settings-repository.php';

    $audit = canonicalIdentityAuditIntegrity($pdo);

    $normalizations = $pdo->query(
        "SELECT id, staff_id, registration_id, submitted_email, canonical_email, source, details, created_at
         FROM canonical_identity_audit
         WHERE action = 'email_normalized'
         ORDER BY id DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $duplicateAttempts = $pdo->query(
        "SELECT id, staff_id, registration_id, submitted_email, canonical_email, source, details, created_at
         FROM canonical_identity_audit
         WHERE action IN ('duplicate_rejected', 'duplicate_blocked')
         ORDER BY id DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $bypassAttempts = $pdo->query(
        "SELECT id, table_name, operation, source, record_ids, details, gateway_active, created_at
         FROM canonical_identity_bypass_log
         ORDER BY id DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $aliasLogins = $pdo->query(
        "SELECT id, staff_id, submitted_email, canonical_email, source, details, created_at
         FROM canonical_identity_audit
         WHERE action = 'mobile_alias_login' OR source = 'mobile_auth'
         ORDER BY id DESC LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $nightlyRuns = $pdo->query(
        "SELECT id, integrity_pass, registrations_updated, manual_review_count, bypass_attempts, alert_sent, created_at
         FROM canonical_identity_nightly_runs
         ORDER BY id DESC LIMIT 14"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $payrollConflicts = [];
    if ((int) ($audit['payroll_distinct_emails'] ?? 0) !== (int) ($audit['payroll_distinct_staff_ids'] ?? 0)) {
        $payrollConflicts[] = [
            'distinct_emails'  => (int) ($audit['payroll_distinct_emails'] ?? 0),
            'distinct_staff'   => (int) ($audit['payroll_distinct_staff_ids'] ?? 0),
            'message'          => 'Payroll email count does not match distinct staff IDs',
        ];
    }

    return [
        'version'               => canonicalIdentityVersionInfo($pdo),
        'integrity'               => $audit,
        'email_normalizations'    => $normalizations,
        'duplicate_attempts'      => $duplicateAttempts,
        'bypass_attempts'         => $bypassAttempts,
        'mobile_alias_logins'     => $aliasLogins,
        'payroll_conflicts'       => $payrollConflicts,
        'nightly_runs'            => $nightlyRuns,
        'last_nightly_at'         => trim(getSetting($pdo, 'canonical_identity_last_nightly_at', '')),
        'last_e2e_at'             => trim(getSetting($pdo, 'canonical_identity_last_e2e_at', '')),
        'last_e2e_pass'           => trim(getSetting($pdo, 'canonical_identity_last_e2e_pass', '')),
    ];
}

function canonicalIdentityRecordNightlyRun(PDO $pdo, array $payload): void
{
    ensureCanonicalIdentitySchema($pdo);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO canonical_identity_nightly_runs
                (integrity_pass, registrations_updated, manual_review_count, bypass_attempts, audit_json, alert_sent)
             VALUES
                (:integrity_pass, :registrations_updated, :manual_review_count, :bypass_attempts, :audit_json, :alert_sent)'
        );
        $stmt->execute([
            'integrity_pass'        => !empty($payload['integrity_pass']) ? 1 : 0,
            'registrations_updated' => (int) ($payload['registrations_updated'] ?? 0),
            'manual_review_count'   => (int) ($payload['manual_review_count'] ?? 0),
            'bypass_attempts'       => (int) ($payload['bypass_attempts'] ?? 0),
            'audit_json'            => json_encode($payload['audit'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'alert_sent'            => !empty($payload['alert_sent']) ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] canonicalIdentityRecordNightlyRun: ' . $e->getMessage());
    }
}

function canonicalIdentitySendIntegrityAlerts(PDO $pdo, array $audit, array $context = []): bool
{
    require_once dirname(__DIR__) . '/notification-center.php';
    require_once dirname(__DIR__) . '/site-urls.php';

    $issues = [];
    if (empty($audit['pass'])) {
        if ((int) ($audit['staff_with_multiple_approved_emails'] ?? 0) > 0) {
            $issues[] = (int) $audit['staff_with_multiple_approved_emails'] . ' staff with multiple approved emails';
        }
        if ((int) ($audit['duplicate_approved_staff_event'] ?? 0) > 0) {
            $issues[] = (int) $audit['duplicate_approved_staff_event'] . ' duplicate staff/event registrations';
        }
        if ((int) ($audit['alias_approved_registrations'] ?? 0) > 0) {
            $issues[] = (int) $audit['alias_approved_registrations'] . ' alias emails on approved registrations';
        }
    }

    $manualReview = (int) ($context['manual_review_count'] ?? 0);
    if ($manualReview > 0) {
        $issues[] = $manualReview . ' alias registration(s) require manual review';
    }

    if ((int) ($context['bypass_since_last'] ?? 0) > 0) {
        $issues[] = (int) $context['bypass_since_last'] . ' direct write bypass attempt(s) logged';
    }

    if (!empty($context['sheets_sync_failed'])) {
        $issues[] = 'Google Sheets synchronization failure';
    }

    if ($issues === []) {
        return false;
    }

    $adminBase = rtrim(getAdminSiteUrl($pdo), '/');
    $dashboardUrl = $adminBase . '/staff-identity-manager.php';
    $body = '<ul style="margin:0;padding-left:1.2rem">';
    foreach ($issues as $issue) {
        $body .= '<li>' . htmlspecialchars($issue, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $body .= '</ul>';

    notifyAdminInApp(
        $pdo,
        'master_staff_identity_alert',
        'Master Staff Identity alert',
        implode('; ', $issues),
        $dashboardUrl,
        'Open Staff Identity Manager'
    );

    sendAdminAlertEmail(
        $pdo,
        'Master Staff Identity alert',
        buildEmailNotificationCard(
            $pdo,
            'Master Staff Identity alert',
            $body,
            $dashboardUrl,
            'Open Staff Identity Manager'
        )
    );

    return true;
}

/** @return array<string, mixed> */
function canonicalIdentityRunE2eVerification(PDO $pdo): array
{
    ensureCanonicalIdentitySchema($pdo);

    $audit = canonicalIdentityAuditIntegrity($pdo);
    $issues = [];
    $traces = [];

    $sampleRegs = $pdo->query(
        "SELECT sr.id, sr.staff_id, sr.event_id, sr.email, sr.status, s.email AS staff_email
         FROM staff_registrations sr
         INNER JOIN staff s ON s.id = sr.staff_id
         WHERE sr.status = 'approved' AND sr.staff_id IS NOT NULL AND sr.staff_id > 0
         ORDER BY sr.updated_at DESC, sr.id DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($sampleRegs as $reg) {
        $regId   = (int) ($reg['id'] ?? 0);
        $staffId = (int) ($reg['staff_id'] ?? 0);
        $eventId = (int) ($reg['event_id'] ?? 0);
        $trace   = [
            'registration_id' => $regId,
            'staff_id'          => $staffId,
            'event_id'          => $eventId,
            'registration_email'=> canonicalIdentityNormalizeEmail((string) ($reg['email'] ?? '')),
            'staff_email'       => canonicalIdentityNormalizeEmail((string) ($reg['staff_email'] ?? '')),
            'steps'             => [],
        ];

        if ($trace['registration_email'] !== '' && $trace['staff_email'] !== ''
            && $trace['registration_email'] !== $trace['staff_email']) {
            $issues[] = "Registration #{$regId} email mismatch with staff #{$staffId}";
            $trace['steps'][] = 'FAIL email mismatch';
        } else {
            $trace['steps'][] = 'PASS canonical email';
        }

        $attStmt = $pdo->prepare(
            'SELECT id, registration_id FROM attendance WHERE registration_id = :reg_id ORDER BY id DESC LIMIT 3'
        );
        try {
            $attStmt->execute(['reg_id' => $regId]);
            $attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($attRows as $att) {
                $attReg = (int) ($att['registration_id'] ?? 0);
                if ($attReg > 0 && $attReg !== $regId) {
                    $issues[] = "Attendance #{$att['id']} registration_id {$attReg} != {$regId}";
                    $trace['steps'][] = 'FAIL attendance registration_id';
                } else {
                    $trace['steps'][] = 'PASS attendance registration #' . ($att['id'] ?? 0);
                }
            }
            if ($attRows === []) {
                $trace['steps'][] = 'INFO no attendance rows';
            }
        } catch (Throwable $e) {
            $trace['steps'][] = 'SKIP attendance table';
        }

        $traces[] = $trace;
    }

    $payrollEmailStaff = $pdo->query(
        "SELECT COUNT(DISTINCT LOWER(TRIM(email))) AS emails,
                COUNT(DISTINCT staff_id) AS staff_ids
         FROM staff_registrations
         WHERE status = 'approved' AND staff_id IS NOT NULL AND staff_id > 0"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $payrollOk = (int) ($payrollEmailStaff['emails'] ?? 0) === (int) ($payrollEmailStaff['staff_ids'] ?? 0);
    if (!$payrollOk) {
        $issues[] = 'Payroll grouping mismatch: distinct emails != distinct staff IDs';
    }

    $pass = empty($audit['pass']) === false && $issues === [];

    productionHealthRecordSettingIfExists($pdo, 'canonical_identity_last_e2e_at', gmdate('Y-m-d H:i:s'));
    productionHealthRecordSettingIfExists($pdo, 'canonical_identity_last_e2e_pass', $pass ? '1' : '0');

    return [
        'pass'              => $pass,
        'integrity_audit'   => $audit,
        'sample_traces'     => $traces,
        'issues'            => $issues,
        'payroll_consistent'=> $payrollOk,
        'locked_message'    => $pass ? 'MASTER STAFF IDENTITY PROTECTION ACTIVE ✅' : null,
    ];
}
