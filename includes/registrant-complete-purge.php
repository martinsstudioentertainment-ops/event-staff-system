<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-repository.php';

/**
 * Tables/columns scanned and purged for a registrant email.
 *
 * @return list<array{table: string, column: string, via: string}>
 */
function registrantPurgeScanTargets(): array
{
    return [
        ['table' => 'staff', 'column' => 'email', 'via' => 'email'],
        ['table' => 'staff_registrations', 'column' => 'email', 'via' => 'email'],
        ['table' => 'staff_blacklist', 'column' => 'email', 'via' => 'email'],
        ['table' => 'email_reminder_log', 'column' => 'email', 'via' => 'email'],
        ['table' => 'pwa_app_devices', 'column' => 'staff_email', 'via' => 'email'],
        ['table' => 'staff_messages', 'column' => 'staff_email', 'via' => 'email'],
        ['table' => 'app_notifications', 'column' => 'staff_email', 'via' => 'email'],
        ['table' => 'platform_auto_approval_log', 'column' => 'email', 'via' => 'email'],
        ['table' => 'platform_offline_checkins', 'column' => 'staff_email', 'via' => 'email'],
        ['table' => 'attendance', 'column' => 'staff_email', 'via' => 'email'],
        ['table' => 'signin_location_verifications', 'column' => 'staff_email', 'via' => 'email'],
        ['table' => 'staff_messages', 'column' => 'staff_id', 'via' => 'staff_id'],
        ['table' => 'platform_trust_scores', 'column' => 'staff_id', 'via' => 'staff_id'],
        ['table' => 'platform_auto_approval_log', 'column' => 'registration_id', 'via' => 'registration_id'],
        ['table' => 'platform_offline_checkins', 'column' => 'registration_id', 'via' => 'registration_id'],
        ['table' => 'platform_sheets_sync_log', 'column' => 'registration_id', 'via' => 'registration_id'],
        ['table' => 'commission_invoice_lines', 'column' => 'registration_id', 'via' => 'registration_id'],
        ['table' => 'attendance', 'column' => 'registration_id', 'via' => 'registration_id'],
        ['table' => 'email_reminder_log', 'column' => 'registration_id', 'via' => 'registration_id'],
        ['table' => 'work_hours', 'column' => 'registration_id', 'via' => 'registration_id'],
        ['table' => 'push_subscriptions', 'column' => 'registration_id', 'via' => 'registration_id'],
        ['table' => 'platform_payroll_alerts', 'column' => 'related_id', 'via' => 'registration_id'],
        ['table' => 'admin_audit_log', 'column' => 'details', 'via' => 'audit_like'],
        ['table' => 'admin_audit_log', 'column' => 'target_id', 'via' => 'audit_target'],
    ];
}

function registrantPurgeTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $cache[$table] = (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();

    return $cache[$table];
}

function registrantPurgeColumnExists(PDO $pdo, string $table, string $column): bool
{
    if (!registrantPurgeTableExists($pdo, $table)) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @return array{email: string, staff_id: int|null, registration_ids: list<int>, hits: list<array<string, mixed>>}
 */
function collectRegistrantPurgeContext(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    $staffId = null;
    $staff   = getStaffByEmail($pdo, $email);
    if ($staff !== null) {
        $staffId = (int) $staff['id'];
    }

    $registrationIds = [];
    $stmt = $pdo->prepare('SELECT id FROM staff_registrations WHERE LOWER(TRIM(email)) = :email');
    $stmt->execute(['email' => $email]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $registrationIds[] = (int) $id;
    }

    if ($staffId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM staff_registrations WHERE staff_id = :sid');
        $stmt->execute(['sid' => $staffId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $registrationIds[] = (int) $id;
        }
    }

    $registrationIds = array_values(array_unique(array_filter($registrationIds, static fn (int $id): bool => $id > 0)));

    return [
        'email'             => $email,
        'staff_id'          => $staffId,
        'registration_ids'  => $registrationIds,
        'staff_row'         => $staff,
    ];
}

/**
 * Scan DB for any rows tied to an email (dry run).
 *
 * @return array{ok: bool, email: string, staff_id: int|null, registration_ids: list<int>, hits: list<array<string, mixed>>, total_rows: int}
 */
function scanRegistrantEverywhere(PDO $pdo, string $email): array
{
    $ctx   = collectRegistrantPurgeContext($pdo, $email);
    $hits  = [];
    $total = 0;

    foreach (registrantPurgeScanTargets() as $target) {
        $table  = $target['table'];
        $column = $target['column'];
        $via    = $target['via'];

        if (!registrantPurgeColumnExists($pdo, $table, $column)) {
            continue;
        }

        $count = 0;
        $sample = [];

        try {
            if ($via === 'email') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE LOWER(TRIM(`{$column}`)) = :email"
                );
                $stmt->execute(['email' => $ctx['email']]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $sampleStmt = $pdo->prepare(
                        "SELECT * FROM `{$table}` WHERE LOWER(TRIM(`{$column}`)) = :email LIMIT 3"
                    );
                    $sampleStmt->execute(['email' => $ctx['email']]);
                    $sample = $sampleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } elseif ($via === 'staff_id' && $ctx['staff_id'] !== null) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :sid");
                $stmt->execute(['sid' => $ctx['staff_id']]);
                $count = (int) $stmt->fetchColumn();
            } elseif ($via === 'registration_id' && $ctx['registration_ids'] !== []) {
                $placeholders = implode(',', array_fill(0, count($ctx['registration_ids']), '?'));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
                $stmt->execute($ctx['registration_ids']);
                $count = (int) $stmt->fetchColumn();
            } elseif ($via === 'audit_like') {
                $like = '%' . $ctx['email'] . '%';
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` LIKE :like");
                $stmt->execute(['like' => $like]);
                $count = (int) $stmt->fetchColumn();
            } elseif ($via === 'audit_target') {
                $countStaff = 0;
                $countRegs  = 0;
                if ($ctx['staff_id'] !== null) {
                    $stmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM `{$table}` WHERE target_type = 'staff' AND target_id = :id"
                    );
                    $stmt->execute(['id' => $ctx['staff_id']]);
                    $countStaff = (int) $stmt->fetchColumn();
                }
                if ($ctx['registration_ids'] !== []) {
                    $placeholders = implode(',', array_fill(0, count($ctx['registration_ids']), '?'));
                    $stmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM `{$table}` WHERE target_type = 'registration' AND target_id IN ({$placeholders})"
                    );
                    $stmt->execute($ctx['registration_ids']);
                    $countRegs = (int) $stmt->fetchColumn();
                }
                $count = $countStaff + $countRegs;
            }
        } catch (Throwable $e) {
            $hits[] = [
                'table'  => $table,
                'column' => $column,
                'via'    => $via,
                'count'  => 0,
                'error'  => $e->getMessage(),
            ];
            continue;
        }

        if ($count > 0) {
            $hits[] = [
                'table'   => $table,
                'column'  => $column,
                'via'     => $via,
                'count'   => $count,
                'sample'  => redactRegistrantSampleRows($sample),
            ];
            $total += $count;
        }
    }

    // Name-based scan on staff_registrations when staff profile exists
    if ($ctx['staff_row'] !== null) {
        $first = trim((string) ($ctx['staff_row']['first_name'] ?? ''));
        $last  = trim((string) ($ctx['staff_row']['surname'] ?? ''));
        if ($first !== '' || $last !== '') {
            $nameHits = scanRegistrantNameMatches($pdo, $first, $last, $ctx['email']);
            foreach ($nameHits as $hit) {
                $hits[] = $hit;
                $total += (int) ($hit['count'] ?? 0);
            }
        }
    }

    return [
        'ok'                => true,
        'email'             => $ctx['email'],
        'staff_id'          => $ctx['staff_id'],
        'registration_ids'  => $ctx['registration_ids'],
        'hits'              => $hits,
        'total_rows'        => $total,
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function redactRegistrantSampleRows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $clean = [];
        foreach ($row as $key => $value) {
            if (is_string($value) && strlen($value) > 120) {
                $clean[$key] = substr($value, 0, 120) . '…';
            } else {
                $clean[$key] = $value;
            }
        }
        $out[] = $clean;
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function scanRegistrantNameMatches(PDO $pdo, string $first, string $last, string $excludeEmail): array
{
    $hits = [];
    if (!registrantPurgeTableExists($pdo, 'staff_registrations')) {
        return $hits;
    }

    $sql = 'SELECT COUNT(*) FROM staff_registrations
            WHERE LOWER(TRIM(email)) != :email
              AND LOWER(TRIM(first_name)) = LOWER(:first)
              AND LOWER(TRIM(surname)) = LOWER(:last)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'email' => strtolower(trim($excludeEmail)),
        'first' => trim($first),
        'last'  => trim($last),
    ]);
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        $hits[] = [
            'table'  => 'staff_registrations',
            'column' => 'first_name,surname',
            'via'    => 'name_match_other_email',
            'count'  => $count,
            'note'   => 'Same name on different email — review manually',
        ];
    }

    return $hits;
}

/**
 * @return list<string>
 */
function scanRegistrantInFilesystem(?string $root, string $email, ?string $firstName = null, ?string $lastName = null): array
{
    $root = $root ?? dirname(__DIR__);
    $paths = [];
    $needles = array_values(array_filter([
        strtolower(trim($email)),
        $firstName !== null ? strtolower(trim($firstName)) : '',
        $lastName !== null ? strtolower(trim($lastName)) : '',
    ]));

    $scanDirs = [
        $root . '/storage/logs',
        $root . '/uploads',
        $root . '/docs',
    ];

    foreach ($scanDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['log', 'txt', 'json', 'md', 'html', 'csv', 'php'], true)) {
                continue;
            }
            if ($file->getSize() > 5_000_000) {
                continue;
            }
            $content = @file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }
            $lower = strtolower($content);
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($lower, $needle)) {
                    $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    break;
                }
            }
        }
    }

    return array_values(array_unique($paths));
}

/**
 * Redact email (and optional name tokens) from previously matched files.
 *
 * @param list<string> $relativePaths paths under project root
 * @return array{files: int, replacements: int, errors: list<string>}
 */
function redactRegistrantInFilesystem(?string $root, string $email, array $relativePaths, ?string $firstName = null, ?string $lastName = null): array
{
    $root = $root ?? dirname(__DIR__);
    $needles = array_values(array_unique(array_filter([
        strtolower(trim($email)),
        $firstName !== null ? strtolower(trim($firstName)) : '',
        $lastName !== null ? strtolower(trim($lastName)) : '',
    ])));

    $files = 0;
    $replacements = 0;
    $errors = [];

    foreach ($relativePaths as $rel) {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $path = $root . '/' . $rel;
        if (!is_file($path) || !is_writable($path)) {
            $errors[] = $rel . ' (not writable)';
            continue;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            $errors[] = $rel . ' (read failed)';
            continue;
        }

        $original = $content;
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            $pattern = '/' . preg_quote($needle, '/') . '/i';
            $content = preg_replace($pattern, '[REDACTED]', $content) ?? $content;
        }

        if ($content !== $original) {
            if (file_put_contents($path, $content) === false) {
                $errors[] = $rel . ' (write failed)';
                continue;
            }
            $files++;
            $replacements += substr_count(strtolower($original), strtolower(trim($email)));
        }
    }

    return ['files' => $files, 'replacements' => $replacements, 'errors' => $errors];
}

/**
 * Permanently remove registrant data from all known tables.
 *
 * @return array<string, mixed>
 */
function purgeRegistrantCompletely(PDO $pdo, string $email, bool $dryRun = false): array
{
    $scanBefore = scanRegistrantEverywhere($pdo, $email);
    if ($dryRun) {
        return [
            'ok'        => true,
            'dry_run'   => true,
            'email'     => $scanBefore['email'],
            'scan'      => $scanBefore,
        ];
    }

    $ctx = collectRegistrantPurgeContext($pdo, $email);
    $deleted = [];
    $staffRow = $ctx['staff_row'];

    if ($ctx['staff_id'] === null && $ctx['registration_ids'] === []) {
        $deleted = array_merge($deleted, purgeRegistrantEmailColumns($pdo, $ctx['email']));
        if (registrantPurgeTableExists($pdo, 'admin_audit_log')) {
            $stmt = $pdo->prepare('DELETE FROM admin_audit_log WHERE details LIKE :like');
            $stmt->execute(['like' => '%' . $ctx['email'] . '%']);
            $deleted['admin_audit_log_by_email'] = $stmt->rowCount();
        }

        $fileHits = scanRegistrantInFilesystem(null, $ctx['email'], '', '');
        $fileRedact = $fileHits !== []
            ? redactRegistrantInFilesystem(null, $ctx['email'], $fileHits, '', '')
            : ['files' => 0, 'replacements' => 0, 'errors' => []];

        $scanAfter = scanRegistrantEverywhere($pdo, $email);

        return [
            'ok'                 => true,
            'email'              => $ctx['email'],
            'deleted'            => $deleted,
            'scan_before'        => $scanBefore,
            'scan_after'         => $scanAfter,
            'remaining_rows'     => (int) ($scanAfter['total_rows'] ?? 0),
            'filesystem_redacted'=> $fileRedact,
            'lookup_still_found' => false,
            'message'            => 'No staff or registrations found; cleaned orphan log rows only.',
        ];
    }

    try {
        $pdo->beginTransaction();

        if ($ctx['staff_id'] !== null && registrantPurgeTableExists($pdo, 'platform_trust_scores')) {
            $stmt = $pdo->prepare('DELETE FROM platform_trust_scores WHERE staff_id = :sid');
            $stmt->execute(['sid' => $ctx['staff_id']]);
            $deleted['platform_trust_scores'] = $stmt->rowCount();
        }

        if ($ctx['staff_id'] !== null && registrantPurgeTableExists($pdo, 'staff_messages')) {
            $stmt = $pdo->prepare('DELETE FROM staff_messages WHERE staff_id = :sid');
            $stmt->execute(['sid' => $ctx['staff_id']]);
            $deleted['staff_messages'] = $stmt->rowCount();
        }

        if ($ctx['registration_ids'] !== []) {
            purgeRegistrantRegistrationIds($pdo, $ctx['registration_ids'], $deleted);
        }

        $deleted = array_merge($deleted, purgeRegistrantEmailColumns($pdo, $ctx['email']));

        if ($ctx['staff_id'] !== null) {
            $stmt = $pdo->prepare('DELETE FROM staff WHERE id = :id');
            $stmt->execute(['id' => $ctx['staff_id']]);
            $deleted['staff'] = $stmt->rowCount();
        }

        if (registrantPurgeTableExists($pdo, 'admin_audit_log')) {
            $stmt = $pdo->prepare('DELETE FROM admin_audit_log WHERE details LIKE :like');
            $stmt->execute(['like' => '%' . $ctx['email'] . '%']);
            $deleted['admin_audit_log_by_email'] = $stmt->rowCount();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok'          => false,
            'error'       => $e->getMessage(),
            'scan_before' => $scanBefore,
        ];
    }

    if ($staffRow !== null) {
        $deleted['psa_files_removed'] = purgeRegistrantPsaFiles($staffRow);
    }

    $scanAfter = scanRegistrantEverywhere($pdo, $email);
    $fileHits  = scanRegistrantInFilesystem(
        null,
        $ctx['email'],
        (string) ($staffRow['first_name'] ?? ''),
        (string) ($staffRow['surname'] ?? '')
    );
    $fileRedact = ['files' => 0, 'replacements' => 0, 'errors' => []];
    if ($fileHits !== []) {
        $fileRedact = redactRegistrantInFilesystem(
            null,
            $ctx['email'],
            $fileHits,
            (string) ($staffRow['first_name'] ?? ''),
            (string) ($staffRow['surname'] ?? '')
        );
    }

    return [
        'ok'                 => true,
        'email'              => $ctx['email'],
        'deleted'            => $deleted,
        'scan_before'        => $scanBefore,
        'scan_after'         => $scanAfter,
        'remaining_rows'     => (int) ($scanAfter['total_rows'] ?? 0),
        'filesystem_hits'    => $fileHits,
        'filesystem_redacted'=> $fileRedact,
        'lookup_still_found' => getLatestRegistrationByEmail($pdo, $ctx['email']) !== null
            || getStaffByEmail($pdo, $ctx['email']) !== null,
    ];
}

/**
 * @param list<int> $registrationIds
 * @param array<string, int|bool> $deleted
 */
function purgeRegistrantRegistrationIds(PDO $pdo, array $registrationIds, array &$deleted): void
{
    if ($registrationIds === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($registrationIds), '?'));

    $tablesByRegistration = [
        'work_hours'               => 'registration_id',
        'commission_invoice_lines' => 'registration_id',
        'attendance'               => 'registration_id',
        'email_reminder_log'       => 'registration_id',
        'push_subscriptions'       => 'registration_id',
        'platform_offline_checkins'=> 'registration_id',
        'platform_sheets_sync_log' => 'registration_id',
        'platform_auto_approval_log' => 'registration_id',
        'platform_payroll_alerts'  => 'related_id',
    ];

    foreach ($tablesByRegistration as $table => $column) {
        if (!registrantPurgeTableExists($pdo, $table)) {
            continue;
        }
        if (!registrantPurgeColumnExists($pdo, $table, $column)) {
            continue;
        }
        $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
        $stmt->execute($registrationIds);
        $deleted[$table] = ($deleted[$table] ?? 0) + $stmt->rowCount();
    }

    $stmt = $pdo->prepare("DELETE FROM staff_registrations WHERE id IN ({$placeholders})");
    $stmt->execute($registrationIds);
    $deleted['staff_registrations'] = ($deleted['staff_registrations'] ?? 0) + $stmt->rowCount();
}

/** @return array<string, int> */
function purgeRegistrantEmailColumns(PDO $pdo, string $email): array
{
    $deleted = [];
    $emailTargets = [
        'staff_blacklist'              => 'email',
        'email_reminder_log'           => 'email',
        'pwa_app_devices'              => 'staff_email',
        'staff_messages'               => 'staff_email',
        'app_notifications'            => 'staff_email',
        'platform_auto_approval_log'   => 'email',
        'platform_offline_checkins'    => 'staff_email',
        'signin_location_verifications'=> 'staff_email',
    ];

    foreach ($emailTargets as $table => $column) {
        if (!registrantPurgeColumnExists($pdo, $table, $column)) {
            continue;
        }
        $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE LOWER(TRIM(`{$column}`)) = :email");
        $stmt->execute(['email' => $email]);
        $deleted[$table . '_by_email'] = $stmt->rowCount();
    }

    if (registrantPurgeColumnExists($pdo, 'attendance', 'staff_email')) {
        $stmt = $pdo->prepare('DELETE FROM attendance WHERE LOWER(TRIM(staff_email)) = :email');
        $stmt->execute(['email' => $email]);
        $deleted['attendance_by_email'] = $stmt->rowCount();
    }

    return $deleted;
}

/** @param array<string, mixed> $staff */
function purgeRegistrantPsaFiles(array $staff): int
{
    $removed = 0;
    foreach (['psa_front_image', 'psa_back_image'] as $field) {
        $stored = trim((string) ($staff[$field] ?? ''));
        if ($stored === '') {
            continue;
        }
        $path = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $stored), '/');
        if (is_file($path) && @unlink($path)) {
            $removed++;
        }
    }

    return $removed;
}
