<?php

declare(strict_types=1);

require_once __DIR__ . '/main-admin-bridge.php';
require_once __DIR__ . '/phone-numbers.php';

/** @return list<string> */
function apply_sheets_cleanup_test_email_patterns(): array
{
    return [
        '@olasentra-e2e.test',
        '@example.com',
        '@example.local',
        '@test.',
        'e2e@',
        'demo@',
        'qa@',
        'dev@',
        'fake@',
        'seed@',
        'probe-',
        'probe@',
        'reviewer@olasentra.com',
    ];
}

/** @return list<string> */
function apply_sheets_cleanup_test_name_patterns(): array
{
    return [
        'test user',
        'demo user',
        'sample staff',
        'dummy staff',
        'fake staff',
        'developer test',
        'test staff',
        'demo staff',
        'probe save',
        'user test',
    ];
}

function apply_sheets_is_test_vault_row(array $row): bool
{
    $email = strtolower(trim((string) ($row['email'] ?? '')));
    foreach (apply_sheets_cleanup_test_email_patterns() as $pattern) {
        if ($email !== '' && str_contains($email, strtolower($pattern))) {
            return true;
        }
    }

    $name = strtolower(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')));
    foreach (apply_sheets_cleanup_test_name_patterns() as $pattern) {
        if ($name !== '' && str_contains($name, $pattern)) {
            return true;
        }
    }

    $psa = strtoupper(trim((string) ($row['psa_licence'] ?? '')));
    if ($psa !== '' && preg_match('/^(TEST|TEMP|FAKE|DEMO|EM123456)/i', $psa)) {
        return true;
    }

    return (bool) preg_match('/@(mailinator|yopmail|tempmail|guerrillamail)\./i', $email);
}

/**
 * @return array<string, array<string, mixed>>
 */
function apply_sheets_erp_canonical_staff(PDO $eventPdo): array
{
    $map = [];
    try {
        $rows = $eventPdo->query(
            "SELECT id, email, mobile, pps_number, psa_licence, first_name, surname, is_blacklisted
             FROM staff
             WHERE email IS NOT NULL AND TRIM(email) <> ''"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    foreach ($rows as $row) {
        if ((int) ($row['is_blacklisted'] ?? 0) === 1) {
            continue;
        }
        $key = strtolower(trim((string) ($row['email'] ?? '')));
        if ($key === '') {
            continue;
        }
        $map[$key] = $row;
    }

    return $map;
}

function apply_sheets_normalize_phone(?string $phone): string
{
    if ($phone === null || trim($phone) === '') {
        return '';
    }
    if (function_exists('normalizeMobileNumber')) {
        return normalizeMobileNumber(trim($phone));
    }

    return preg_replace('/\D/', '', trim($phone)) ?? '';
}

function apply_sheets_normalize_pps(?string $pps): string
{
    return strtoupper(preg_replace('/\s+/', '', trim((string) $pps)) ?? '');
}

function apply_sheets_normalize_psa(?string $psa): string
{
    $psa = strtoupper(trim((string) $psa));
    if ($psa === '' || str_starts_with($psa, 'TEMP-PSA-')) {
        return '';
    }

    return $psa;
}

/**
 * @return array<string, mixed>
 */
function apply_sheets_audit_vault(PDO $eventPdo, PDO $applyPdo): array
{
    $canonical = apply_sheets_erp_canonical_staff($eventPdo);
    $rows      = $applyPdo->query('SELECT * FROM staff_master ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $toDelete   = [];
    $manualReview = [];

    $byEmail = [];
    $byPhone = [];
    $byPps   = [];
    $byPsa   = [];

    foreach ($rows as $row) {
        $id    = (int) ($row['id'] ?? 0);
        $email = strtolower(trim((string) ($row['email'] ?? '')));

        if ($email !== '') {
            $byEmail[$email][] = $row;
        }

        $phone = apply_sheets_normalize_phone((string) ($row['phone'] ?? ''));
        if (strlen($phone) >= 9) {
            $byPhone[$phone][] = $row;
        }

        $pps = apply_sheets_normalize_pps((string) ($row['national_insurance'] ?? ''));
        if ($pps !== '') {
            $byPps[$pps][] = $row;
        }

        $psa = apply_sheets_normalize_psa((string) ($row['psa_licence'] ?? ''));
        if ($psa !== '') {
            $byPsa[$psa][] = $row;
        }
    }

    $markDelete = static function (int $id, string $reason) use (&$toDelete): void {
        if (!isset($toDelete[$id])) {
            $toDelete[$id] = ['id' => $id, 'reason' => $reason];
        }
    };

    foreach ($rows as $row) {
        $id    = (int) ($row['id'] ?? 0);
        $email = strtolower(trim((string) ($row['email'] ?? '')));

        if (apply_sheets_is_test_vault_row($row)) {
            $markDelete($id, 'test_record');
            continue;
        }

        if ($email === '' || !isset($canonical[$email])) {
            $markDelete($id, 'email_not_in_erp');
        }
    }

    foreach ($byEmail as $emailKey => $group) {
        if (count($group) <= 1) {
            continue;
        }
        $keeper = apply_sheets_pick_vault_keeper($group, $canonical[$emailKey] ?? null);
        foreach ($group as $row) {
            if ((int) ($row['id'] ?? 0) !== (int) ($keeper['id'] ?? 0)) {
                $markDelete((int) $row['id'], 'duplicate_email');
            }
        }
    }

    foreach (['phone' => $byPhone, 'pps' => $byPps, 'psa' => $byPsa] as $field => $groups) {
        foreach ($groups as $group) {
            if (count($group) <= 1) {
                continue;
            }
            $keepers = array_values(array_filter($group, static fn ($r) => !isset($toDelete[(int) ($r['id'] ?? 0)])));
            if (count($keepers) <= 1) {
                continue;
            }
            $keeper = apply_sheets_pick_vault_keeper($keepers, null);
            foreach ($keepers as $row) {
                if ((int) ($row['id'] ?? 0) === (int) ($keeper['id'] ?? 0)) {
                    continue;
                }
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if (!isset($canonical[$email])) {
                    $markDelete((int) $row['id'], 'duplicate_' . $field . '_non_erp');
                } else {
                    $manualReview[] = [
                        'vault_id' => (int) $row['id'],
                        'email'    => (string) ($row['email'] ?? ''),
                        'field'    => $field,
                        'message'  => 'Duplicate ' . $field . ' shared with vault ID ' . (int) ($keeper['id'] ?? 0),
                    ];
                }
            }
        }
    }

    return [
        'vault_rows_before' => count($rows),
        'erp_staff_count'   => count($canonical),
        'marked_for_delete' => count($toDelete),
        'delete_candidates' => array_values($toDelete),
        'manual_review'     => $manualReview,
        'breakdown'         => apply_sheets_count_delete_reasons(array_values($toDelete)),
    ];
}

/**
 * @param list<array<string, mixed>> $group
 * @param array<string, mixed>|null $erpRow
 * @return array<string, mixed>
 */
function apply_sheets_pick_vault_keeper(array $group, ?array $erpRow): array
{
    if ($erpRow !== null) {
        $erpPhone = apply_sheets_normalize_phone((string) ($erpRow['mobile'] ?? ''));
        $erpPsa   = apply_sheets_normalize_psa((string) ($erpRow['psa_licence'] ?? ''));
        foreach ($group as $row) {
            $phoneMatch = $erpPhone === '' || apply_sheets_normalize_phone((string) ($row['phone'] ?? '')) === $erpPhone;
            $psaMatch   = $erpPsa === '' || apply_sheets_normalize_psa((string) ($row['psa_licence'] ?? '')) === $erpPsa;
            if ($phoneMatch && $psaMatch) {
                return $row;
            }
        }
    }

    usort($group, static fn ($a, $b) => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));

    return $group[0];
}

/** @param list<array{reason: string}> $items */
function apply_sheets_count_delete_reasons(array $items): array
{
    $counts = [];
    foreach ($items as $item) {
        $reason = (string) ($item['reason'] ?? 'unknown');
        $counts[$reason] = ($counts[$reason] ?? 0) + 1;
    }

    return $counts;
}

/**
 * @return array<string, mixed>
 */
function apply_sheets_cleanup_vault(PDO $eventPdo, PDO $applyPdo, bool $apply): array
{
    $audit = apply_sheets_audit_vault($eventPdo, $applyPdo);
    $deleted = [
        'test_record'              => 0,
        'email_not_in_erp'         => 0,
        'duplicate_email'          => 0,
        'duplicate_phone_non_erp'  => 0,
        'duplicate_pps_non_erp'    => 0,
        'duplicate_psa_non_erp'    => 0,
        'merged_staff_alias'       => 0,
    ];

    if ($apply && ($audit['delete_candidates'] ?? []) !== []) {
        $deleteNotes = $applyPdo->prepare('DELETE FROM staff_notes WHERE staff_id = :id');
        $deleteLog   = $applyPdo->prepare('DELETE FROM staff_status_log WHERE staff_id = :id');
        $stmt        = $applyPdo->prepare('DELETE FROM staff_master WHERE id = :id');
        foreach ($audit['delete_candidates'] as $candidate) {
            $id     = (int) ($candidate['id'] ?? 0);
            $reason = (string) ($candidate['reason'] ?? 'unknown');
            if ($id < 1) {
                continue;
            }
            try {
                try {
                    $deleteNotes->execute(['id' => $id]);
                } catch (Throwable $e) {
                    // optional table
                }
                try {
                    $deleteLog->execute(['id' => $id]);
                } catch (Throwable $e) {
                    // optional table
                }
                $stmt->execute(['id' => $id]);
                if ($stmt->rowCount() > 0) {
                    if ($reason === 'email_not_in_erp') {
                        ++$deleted['merged_staff_alias'];
                    } elseif ($reason === 'test_record') {
                        ++$deleted['test_record'];
                    } elseif ($reason === 'duplicate_email') {
                        ++$deleted['duplicate_email'];
                    } elseif ($reason === 'duplicate_phone_non_erp') {
                        ++$deleted['duplicate_phone_non_erp'];
                    } elseif ($reason === 'duplicate_pps_non_erp') {
                        ++$deleted['duplicate_pps_non_erp'];
                    } elseif ($reason === 'duplicate_psa_non_erp') {
                        ++$deleted['duplicate_psa_non_erp'];
                    }
                }
            } catch (Throwable $e) {
                error_log('[ApplySheetsCleanup] delete vault #' . $id . ': ' . $e->getMessage());
            }
        }
    }

    $afterCount = (int) $applyPdo->query('SELECT COUNT(*) FROM staff_master')->fetchColumn();

    return [
        'applied'           => $apply,
        'vault_rows_before' => (int) ($audit['vault_rows_before'] ?? 0),
        'vault_rows_after'  => $afterCount,
        'erp_staff_count'   => (int) ($audit['erp_staff_count'] ?? 0),
        'rows_marked'       => (int) ($audit['marked_for_delete'] ?? 0),
        'rows_deleted'      => $apply ? array_sum($deleted) : 0,
        'deleted_breakdown' => $deleted,
        'audit_breakdown'   => $audit['breakdown'] ?? [],
        'manual_review'     => $audit['manual_review'] ?? [],
        'delete_samples'    => array_slice($audit['delete_candidates'] ?? [], 0, 30),
    ];
}

/**
 * @return array<string, int>
 */
function apply_sheets_read_tab_row_counts(string $accessToken, string $spreadsheetId, array $tabNames): array
{
    $counts = [];
    foreach ($tabNames as $tab) {
        $counts[$tab] = apply_google_count_non_empty_rows($accessToken, $spreadsheetId, $tab);
    }

    return $counts;
}

function apply_google_count_non_empty_rows(string $accessToken, string $spreadsheetId, string $sheetName): int
{
    require_once __DIR__ . '/google-sheets-sync.php';

    $range = apply_google_tab_range($sheetName, 'A:A');
    $url   = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId)
        . '/values/' . rawurlencode($range);

    $request = curl_init();
    curl_setopt_array($request, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    ]);
    $response = curl_exec($request);
    curl_close($request);

    if (!is_string($response) || $response === '') {
        return 0;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['values']) || !is_array($data['values'])) {
        return 0;
    }

    $nonEmpty = 0;
    foreach ($data['values'] as $index => $row) {
        if ($index === 0) {
            continue;
        }
        $cell = trim((string) ($row[0] ?? ''));
        if ($cell !== '') {
            ++$nonEmpty;
        }
    }

    return $nonEmpty;
}

/**
 * @return array<string, mixed>
 */
function apply_sheets_verify_vault_against_erp(PDO $eventPdo, PDO $applyPdo): array
{
    $canonical = apply_sheets_erp_canonical_staff($eventPdo);
    $rows      = $applyPdo->query('SELECT * FROM staff_master ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $issues    = [];

    foreach ($rows as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $erp   = $canonical[$email] ?? null;
        if ($erp === null) {
            $issues[] = ['vault_id' => (int) $row['id'], 'email' => $email, 'issue' => 'email_missing_in_erp'];
            continue;
        }

        $erpPhone = apply_sheets_normalize_phone((string) ($erp['mobile'] ?? ''));
        $vaultPhone = apply_sheets_normalize_phone((string) ($row['phone'] ?? ''));
        if ($erpPhone !== '' && $vaultPhone !== '' && $erpPhone !== $vaultPhone) {
            $issues[] = ['vault_id' => (int) $row['id'], 'email' => $email, 'issue' => 'phone_mismatch'];
        }

        $erpPps = apply_sheets_normalize_pps((string) ($erp['pps_number'] ?? ''));
        $vaultPps = apply_sheets_normalize_pps((string) ($row['national_insurance'] ?? ''));
        if ($erpPps !== '' && $vaultPps !== '' && $erpPps !== $vaultPps) {
            $issues[] = ['vault_id' => (int) $row['id'], 'email' => $email, 'issue' => 'pps_mismatch'];
        }

        $erpPsa = apply_sheets_normalize_psa((string) ($erp['psa_licence'] ?? ''));
        $vaultPsa = apply_sheets_normalize_psa((string) ($row['psa_licence'] ?? ''));
        if ($erpPsa !== '' && $vaultPsa !== '' && $erpPsa !== $vaultPsa) {
            $issues[] = ['vault_id' => (int) $row['id'], 'email' => $email, 'issue' => 'psa_mismatch'];
        }
    }

    return [
        'pass'         => $issues === [],
        'vault_rows'   => count($rows),
        'erp_staff'    => count($canonical),
        'issue_count'  => count($issues),
        'issues'       => array_slice($issues, 0, 50),
    ];
}

/**
 * Full Apply vault + central sheets cleanup and rebuild from ERP.
 *
 * @return array<string, mixed>
 */
function run_apply_sheets_production_cleanup(PDO $applyPdo, bool $apply = false): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    require_once __DIR__ . '/staff-import.php';
    require_once __DIR__ . '/google-sheets-sync.php';

    $eventPdo = getMainAdminPdo();
    if (!$eventPdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Main ERP database is not connected.'];
    }

    $cfg           = apply_sheet_config();
    $spreadsheetId = (string) $cfg['spreadsheet_id'];
    $jsonPath      = __DIR__ . '/../config/google-service-account.json';
    $sheetBefore   = [];
    $accessToken   = '';

    if (is_readable($jsonPath)) {
        $credentials = json_decode((string) file_get_contents($jsonPath), true);
        if (is_array($credentials)) {
            $accessToken = apply_google_access_token($credentials);
            if ($accessToken !== '') {
                $sheetTitles = apply_google_sheet_titles($accessToken, $spreadsheetId);
                $payrollTab  = apply_google_find_sheet_title($sheetTitles, (string) $cfg['tab_payroll']) ?? (string) $cfg['tab_payroll'];
                $masterTab   = apply_google_resolve_master_tab($accessToken, $spreadsheetId, $cfg);
                $psaTab      = apply_google_resolve_psa_tab($accessToken, $spreadsheetId, $cfg);
                $sheetBefore = apply_sheets_read_tab_row_counts($accessToken, $spreadsheetId, [
                    'payroll' => $payrollTab,
                    'master'  => $masterTab,
                    'psa'     => $psaTab,
                ]);
            }
        }
    }

    $vaultCleanup = apply_sheets_cleanup_vault($eventPdo, $applyPdo, $apply);

    $import = null;
    $sync   = null;
    $phaseErrors = [];
    $vaultCleanupAfter = null;
    if ($apply) {
        require_once __DIR__ . '/auto-sync-runner.php';
        try {
            $runnerResult = run_apply_payroll_sync($applyPdo, true);
            $import         = $runnerResult['import'] ?? null;
            $sync           = $runnerResult['sheet'] ?? null;
            if (empty($runnerResult['ok'])) {
                $phaseErrors[] = (string) ($runnerResult['error'] ?? 'sync runner failed');
            }
        } catch (Throwable $e) {
            $phaseErrors[] = 'sync: ' . $e->getMessage();
        }

        // Import may recreate alias vault rows from old registration emails — purge again.
        $vaultCleanupAfter = apply_sheets_cleanup_vault($eventPdo, $applyPdo, true);
        if ((int) ($vaultCleanupAfter['rows_deleted'] ?? 0) > 0) {
            try {
                $runnerResult = run_apply_payroll_sync($applyPdo, true);
                $sync = $runnerResult['sheet'] ?? $sync;
                if (empty($runnerResult['ok'])) {
                    $phaseErrors[] = (string) ($runnerResult['error'] ?? 'resync after vault purge failed');
                }
            } catch (Throwable $e) {
                $phaseErrors[] = 'resync: ' . $e->getMessage();
            }
        }
    }

    $sheetAfter = $sheetBefore;
    if ($apply && $accessToken !== '') {
        $sheetTitles = apply_google_sheet_titles($accessToken, $spreadsheetId);
        $payrollTab  = apply_google_find_sheet_title($sheetTitles, (string) $cfg['tab_payroll']) ?? (string) $cfg['tab_payroll'];
        $masterTab   = apply_google_resolve_master_tab($accessToken, $spreadsheetId, $cfg);
        $psaTab      = apply_google_resolve_psa_tab($accessToken, $spreadsheetId, $cfg);
        $sheetAfter  = [
            'payroll' => (int) ($sync['payroll_rows'] ?? apply_google_count_non_empty_rows($accessToken, $spreadsheetId, $payrollTab)),
            'master'  => (int) ($sync['master_rows'] ?? apply_google_count_non_empty_rows($accessToken, $spreadsheetId, $masterTab)),
            'psa'     => (int) ($sync['psa_rows'] ?? apply_google_count_non_empty_rows($accessToken, $spreadsheetId, $psaTab)),
        ];
    }

    $verification = apply_sheets_verify_vault_against_erp($eventPdo, $applyPdo);

    $clean = $apply
        && $phaseErrors === []
        && (int) ($verification['issue_count'] ?? 0) === 0
        && ($sync === null || !empty($sync['ok']));

    return [
        'ok'                    => $phaseErrors === [],
        'errors'                => $phaseErrors,
        'applied'               => $apply,
        'google_sheets_status'  => $clean ? 'CLEAN' : ($apply ? 'REVIEW REQUIRED' : 'AUDIT ONLY'),
        'google_sheets_status_display' => $clean
            ? 'Google Sheets Status: CLEAN ✅'
            : ($apply ? 'Google Sheets Status: REVIEW REQUIRED ⚠️' : 'Google Sheets Status: AUDIT ONLY'),
        'vault_cleanup'         => $vaultCleanup,
        'vault_cleanup_after_import' => $vaultCleanupAfter,
        'sheets_before'         => $sheetBefore,
        'sheets_after'          => $sheetAfter,
        'import'                => $import,
        'sync'                  => $sync,
        'verification'          => $verification,
        'manual_review'         => array_merge(
            $vaultCleanup['manual_review'] ?? [],
            $import['errors'] ?? []
        ),
    ];
}
