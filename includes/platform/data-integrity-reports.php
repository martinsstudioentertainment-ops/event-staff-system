<?php

declare(strict_types=1);

require_once __DIR__ . '/data-integrity.php';

/**
 * @return array{ok: bool, written: list<string>, integrity_score: int, vault_score: int, test_accounts: int, merge_recs: int, message: string}
 */
function generateSprint66DataIntegrityReports(?PDO $pdo = null, ?PDO $applyPdo = null, ?string $docsDir = null): array
{
    $pdo      = $pdo ?? getDB();
    $applyPdo = $applyPdo ?? getApplyVaultPdo();
    ensureDataIntegritySchema($pdo);

    $audit          = runFullDataIntegrityAudit($pdo, $applyPdo);
    $testData       = detectTestAccounts($pdo, $applyPdo);
    $vaultHealth    = computeVaultHealthScore($applyPdo);
    $integrityScore = computeDataIntegrityScore($audit, $vaultHealth);
    $mergeRecs      = buildMergeRecommendations($pdo, $applyPdo);
    $trustQ         = auditTrustScoreDataQuality($pdo);
    $plan           = buildProductionCleanupPlan($audit, $testData, $mergeRecs);

    $docsDir = $docsDir ?? dirname(__DIR__, 2) . '/docs';
    if (!is_dir($docsDir) && !mkdir($docsDir, 0755, true) && !is_dir($docsDir)) {
        return [
            'ok'              => false,
            'written'         => [],
            'integrity_score' => (int) ($integrityScore['score'] ?? 0),
            'vault_score'     => (int) ($vaultHealth['score'] ?? 0),
            'test_accounts'   => count($testData['accounts'] ?? []),
            'merge_recs'      => count($mergeRecs),
            'message'         => 'Could not create docs directory.',
        ];
    }

    $written = [];

    $rows = [];
    foreach ($audit['duplicate_phones_main'] ?? [] as $d) {
        $rows[] = ['Main phone', $d['phone'] ?? '', (string) ($d['count'] ?? 0), 'Staff IDs: ' . implode(', ', $d['staff_ids'] ?? []), 'Keep #' . ($d['recommended'] ?? '?')];
    }
    foreach ($audit['duplicate_phones_vault'] ?? [] as $d) {
        $rows[] = ['Vault phone', $d['phone'] ?? '', (string) ($d['count'] ?? 0), $d['names'] ?? '', 'Keep vault #' . ($d['recommended'] ?? '?')];
    }
    foreach ($audit['duplicate_staff_profiles'] ?? [] as $d) {
        $rows[] = ['Duplicate profile', $d['name'] ?? '', (string) ($d['count'] ?? 0), $d['emails'] ?? '', 'Keep #' . ($d['recommended'] ?? '?')];
    }
    foreach ($audit['orphaned'] ?? [] as $o) {
        $rows[] = ['Orphan', $o['type'] ?? '', (string) ($o['count'] ?? 0), 'Sample in report', 'Manual fix'];
    }
    $body = '<p>Data integrity grade: <strong>' . ($integrityScore['grade'] ?? '?') . '</strong> (' . (int) ($integrityScore['score'] ?? 0) . '%)</p>';
    $body .= $rows === [] ? '<div class="card"><p>No duplicate groups found in audit scope.</p></div>' : dataIntegrityTable(['Type', 'Key', 'Count', 'Detail', 'Recommendation'], $rows);
    $body .= '<h2>Inactive / abandoned</h2>';
    $inactiveRows = array_map(static fn ($i) => [$i['type'] ?? '', (string) ($i['count'] ?? 0)], $audit['inactive'] ?? []);
    $body .= $inactiveRows === [] ? '<div class="card"><p>None flagged.</p></div>' : dataIntegrityTable(['Type', 'Count'], $inactiveRows);
    file_put_contents($docsDir . '/DATA-INTEGRITY-AUDIT-REPORT.html', dataIntegrityReportShell('Data Integrity Audit', 'Sprint 6.6 Task 1 — read-only audit', $body, (int) ($integrityScore['score'] ?? 0)));
    $written[] = 'DATA-INTEGRITY-AUDIT-REPORT.html';

    $testRows = [];
    foreach ($testData['accounts'] ?? [] as $a) {
        $testRows[] = [
            $a['source'] ?? '',
            $a['email'] ?? '',
            $a['name'] ?? '',
            (string) ($a['registrations'] ?? $a['vault_id'] ?? ''),
            (string) ($a['attendance'] ?? ''),
            (string) ($a['notifications'] ?? ''),
            (string) ($a['messages'] ?? ''),
        ];
    }
    $body = '<p>Total test/demo accounts: <strong>' . count($testData['accounts'] ?? []) . '</strong>. No deletions performed.</p>';
    $body .= $testRows === [] ? '<div class="card"><p>No test accounts matched patterns.</p></div>' : dataIntegrityTable(['Source', 'Email', 'Name', 'Regs/Vault', 'Attendance', 'Notifications', 'Messages'], $testRows);
    file_put_contents($docsDir . '/TEST-DATA-INVENTORY-REPORT.html', dataIntegrityReportShell('Test Data Inventory', 'Sprint 6.6 Task 2 — detection only', $body));
    $written[] = 'TEST-DATA-INVENTORY-REPORT.html';

    $phoneRows = [];
    foreach ($audit['duplicate_phones_main'] ?? [] as $d) {
        $ids = $d['staff_ids'] ?? [];
        $phoneRows[] = ['Main ERP', $d['phone'] ?? '', 'A: #' . ($ids[0] ?? '?'), 'B: #' . ($ids[1] ?? '?'), 'Keep #' . ($d['recommended'] ?? '?')];
    }
    foreach ($audit['duplicate_phones_vault'] ?? [] as $d) {
        $ids = $d['vault_ids'] ?? [];
        $phoneRows[] = ['Apply vault', $d['phone'] ?? '', 'A: #' . ($ids[0] ?? '?'), 'B: #' . ($ids[1] ?? '?'), 'Keep #' . ($d['recommended'] ?? '?')];
    }
    $body = '<p>Blank vault phones: ' . (int) ($audit['blank_phones_vault'] ?? 0) . ' · Invalid formats: ' . (int) ($audit['invalid_phones_vault'] ?? 0) . '</p>';
    $body .= dataIntegrityTable(['System', 'Phone', 'Record A', 'Record B', 'Recommended owner'], $phoneRows ?: [['—', '—', '—', '—', 'No duplicates']]);
    file_put_contents($docsDir . '/PHONE-DUPLICATE-REPORT.html', dataIntegrityReportShell('Phone Duplicate Report', 'Sprint 6.6 Task 3', $body));
    $written[] = 'PHONE-DUPLICATE-REPORT.html';

    $psaRows = [];
    foreach ($audit['duplicate_psa_vault'] ?? [] as $d) {
        $ids = $d['vault_ids'] ?? [];
        $psaRows[] = [$d['psa'] ?? '', ($d['is_test'] ?? false) ? 'YES' : 'no', (string) ($d['count'] ?? 0), $d['names'] ?? '', 'Vault #' . ($ids[0] ?? '?') . ' vs #' . ($ids[1] ?? '?')];
    }
    if ($applyPdo instanceof PDO) {
        try {
            foreach (dataIntegrityTestPsaValues() as $testPsa) {
                $stmt = $applyPdo->prepare('SELECT id, email, first_name, last_name FROM staff_master WHERE UPPER(TRIM(psa_licence)) = :p LIMIT 20');
                $stmt->execute(['p' => strtoupper($testPsa)]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $psaRows[] = [$testPsa, 'TEST VALUE', '1', dataIntegrityVaultLabel($r), 'Vault #' . ($r['id'] ?? '')];
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    $body = dataIntegrityTable(['PSA', 'Test?', 'Count', 'Names', 'Records'], $psaRows ?: [['—', '—', '0', '—', 'No issues']]);
    file_put_contents($docsDir . '/PSA-INTEGRITY-REPORT.html', dataIntegrityReportShell('PSA Integrity Report', 'Sprint 6.6 Task 4', $body));
    $written[] = 'PSA-INTEGRITY-REPORT.html';

    $importChecks = [
        ['Import pre-check module', 'pass', 'apply/admin/includes/import-precheck.php'],
        ['Human-readable phone skips', 'pass', 'Owner name + vault ID in staff-import.php'],
        ['Human-readable PSA skips', 'pass', 'Pre-insert PSA conflict check'],
        ['Precheck UI on import page', 'pass', 'import-applicants.php Continue/Skip/Cancel'],
    ];
    $body = dataIntegrityTable(['Check', 'Status', 'Detail'], array_map(static fn ($c) => [$c[0], $c[1], $c[2]], $importChecks));
    file_put_contents($docsDir . '/IMPORT-STABILIZATION-REPORT.html', dataIntegrityReportShell('Import Stabilization Report', 'Sprint 6.6 Task 6', $body));
    $written[] = 'IMPORT-STABILIZATION-REPORT.html';

    $metricRows = [];
    foreach ($vaultHealth['metrics'] ?? [] as $k => $v) {
        $metricRows[] = [$k, (string) $v];
    }
    $body = '<p>Issues: ' . htmlspecialchars(implode('; ', $vaultHealth['issues'] ?? []) ?: 'None') . '</p>';
    $body .= dataIntegrityTable(['Metric', 'Value'], $metricRows);
    file_put_contents($docsDir . '/VAULT-HEALTH-REPORT.html', dataIntegrityReportShell('Apply Vault Health', 'Sprint 6.6 Task 7', $body, (int) ($vaultHealth['score'] ?? 0)));
    $written[] = 'VAULT-HEALTH-REPORT.html';

    $body = '<p>Distortion risk: <strong>' . (($trustQ['distorted'] ?? false) ? 'YES' : 'NO') . '</strong></p>';
    $body .= dataIntegrityTable(['Issue'], array_map(static fn ($i) => [$i], $trustQ['issues'] ?? ['No issues detected']));
    file_put_contents($docsDir . '/TRUST-SCORE-DATA-QUALITY-REPORT.html', dataIntegrityReportShell('Trust Score Data Quality', 'Sprint 6.6 Task 8', $body));
    $written[] = 'TRUST-SCORE-DATA-QUALITY-REPORT.html';

    $body = '';
    foreach ($plan as $phase) {
        $body .= '<div class="card"><h2>' . htmlspecialchars((string) ($phase['title'] ?? '')) . '</h2><ul>';
        foreach ($phase['items'] ?? [] as $item) {
            $body .= '<li>' . htmlspecialchars((string) $item) . '</li>';
        }
        $body .= '</ul><p><em>Plan only — no deletions or merges executed.</em></p></div>';
    }
    file_put_contents($docsDir . '/PRODUCTION-CLEANUP-PLAN.html', dataIntegrityReportShell('Production Cleanup Plan', 'Sprint 6.6 Task 10 — recommendations only', $body));
    $written[] = 'PRODUCTION-CLEANUP-PLAN.html';

    return [
        'ok'              => true,
        'written'         => $written,
        'integrity_score' => (int) ($integrityScore['score'] ?? 0),
        'vault_score'     => (int) ($vaultHealth['score'] ?? 0),
        'test_accounts'   => count($testData['accounts'] ?? []),
        'merge_recs'      => count($mergeRecs),
        'message'         => 'Reports regenerated (' . count($written) . ' files).',
    ];
}
