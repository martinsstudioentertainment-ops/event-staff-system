<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/payroll-xlsx-export.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/main-admin-bridge.php';

/** @return list<string> */
function payroll_column_headers(): array
{
    return apply_payroll_sheet_headers();
}

/**
 * @param list<string> $row
 * @return list<string>
 */
function payroll_format_export_row(array $row): array
{
    return [
        $row[0] ?? '',
        $row[1] ?? '',
        $row[2] ?? '',
        $row[3] ?? '',
        $row[4] ?? '',
        $row[5] ?? '',
        payroll_format_date_cell(($row[6] ?? '') !== '' ? (string) $row[6] : null),
        payroll_format_gender((string) ($row[7] ?? '')),
        $row[8] ?? '',
        $row[9] ?? '',
    ];
}

$message = '';
$error   = '';
$payrollRows     = [];
$payrollCount    = 0;
$withIbanCount   = 0;
$withPpsCount    = 0;
$pendingApproval   = 0;
$vaultTotal        = 0;
$notOnPayroll      = [];

$eventPdo = getMainAdminPdo();

try {
    $vaultTotal = (int) $pdo->query('SELECT COUNT(*) FROM staff_master')->fetchColumn();

    if ($eventPdo instanceof PDO) {
        $mainStats       = apply_main_erp_import_stats($eventPdo);
        $payrollCount    = $mainStats['unique_emails'];
        $pendingApproval = $mainStats['unique_pending_emails'];
        $sheetValues     = apply_build_payroll_sheet_values($pdo, $eventPdo);
        $payrollRows     = array_slice($sheetValues, 1);

        foreach ($payrollRows as $row) {
            if (trim((string) ($row[9] ?? '')) !== '') {
                ++$withIbanCount;
            }
            if (trim((string) ($row[8] ?? '')) !== '') {
                ++$withPpsCount;
            }
        }

        $notOnPayroll = apply_vault_not_on_payroll($eventPdo, $pdo);
    } else {
        $payrollCount = apply_count_payroll_staff($pdo);
        $stmt         = $pdo->query("
            SELECT last_name, first_name, address, postcode, email, phone,
                   date_of_birth, gender, national_insurance, bank_iban
            FROM staff_master
            WHERE " . apply_payroll_staff_sql_where() . "
            ORDER BY last_name ASC, first_name ASC
            LIMIT 500
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payrollRows[] = [
                (string) ($row['last_name'] ?? ''),
                (string) ($row['first_name'] ?? ''),
                (string) ($row['address'] ?? ''),
                (string) ($row['postcode'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['date_of_birth'] ?? ''),
                apply_format_gender_label((string) ($row['gender'] ?? '')),
                (string) ($row['national_insurance'] ?? ''),
                (string) ($row['bank_iban'] ?? ''),
            ];
            if (trim((string) ($row['bank_iban'] ?? '')) !== '') {
                ++$withIbanCount;
            }
            if (trim((string) ($row['national_insurance'] ?? '')) !== '') {
                ++$withPpsCount;
            }
        }
    }
} catch (Exception $e) {
    $error = 'Failed to load payroll data: ' . $e->getMessage();
}

if (($_GET['action'] ?? '') === 'export') {
    try {
        $exportRows = [];
        foreach ($payrollRows as $row) {
            $exportRows[] = payroll_format_export_row($row);
        }

        payroll_send_xlsx_download(
            payroll_column_headers(),
            $exportRows,
            'payroll_export_' . date('Y-m-d') . '.xlsx'
        );
        exit;
    } catch (Throwable $e) {
        $error = 'Export failed: ' . $e->getMessage();
    }
}

$headers = payroll_column_headers();

secure_layout_start(
    'Payroll vault',
    'payroll',
    'One row per approved person from main ERP — same data pushed to Google Payroll Staff.'
);

if ($message !== '') {
    echo '<div class="secure-alert secure-alert--success">' . secure_h($message) . '</div>';
}
if ($error !== '') {
    echo '<div class="secure-alert secure-alert--error">' . secure_h($error) . '</div>';
}
?>

<div class="secure-stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
    <div class="secure-stat secure-stat--ok">
        <div class="secure-stat__label">Approved payroll</div>
        <div class="secure-stat__value"><?= $payrollCount ?></div>
    </div>
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">Apply vault</div>
        <div class="secure-stat__value"><?= $vaultTotal ?></div>
    </div>
    <?php if ($pendingApproval > 0): ?>
    <div class="secure-stat secure-stat--warn">
        <div class="secure-stat__label">Pending approval</div>
        <div class="secure-stat__value"><?= $pendingApproval ?></div>
    </div>
    <?php endif; ?>
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">With bank / IBAN</div>
        <div class="secure-stat__value"><?= $withIbanCount ?></div>
    </div>
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">With NI / PPS</div>
        <div class="secure-stat__value"><?= $withPpsCount ?></div>
    </div>
</div>

<div class="secure-card secure-card--danger-top">
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem;margin-bottom:1rem;">
        <h2 style="margin:0;font-size:1rem;">Payroll records (main ERP approved)</h2>
        <a href="?action=export" class="secure-btn secure-btn--success">Download Excel</a>
    </div>

    <div class="secure-table-wrap">
        <table class="secure-table">
            <thead>
                <tr>
                    <?php foreach ($headers as $header): ?>
                        <th><?= secure_h(mb_strtoupper($header, 'UTF-8')) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($payrollRows === []): ?>
                    <tr><td colspan="<?= count($headers) ?>" style="color:var(--secure-muted);">No approved payroll records yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($payrollRows as $row): ?>
                        <tr>
                            <?php foreach (payroll_format_export_row($row) as $value): ?>
                                <td style="white-space:normal;word-break:break-word;max-width:220px;"><?= secure_h($value) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($notOnPayroll !== []): ?>
<div class="secure-card secure-card--danger-top">
    <h2 style="margin:0 0 0.75rem;font-size:1rem;">In apply vault but not on payroll (<?= count($notOnPayroll) ?>)</h2>
    <p style="margin:0 0 1rem;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        These people are in the apply vault but are <strong style="color:var(--secure-text);">not approved</strong> on main ERP yet.
        Approve them on <a href="https://admin.olasentra.com/staff.php" target="_blank" rel="noopener noreferrer" style="color:var(--secure-cyan);">main ERP → Staff</a>, then run sync.
    </p>
    <div class="secure-table-wrap">
        <table class="secure-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Vault status</th>
                    <th>Main ERP status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notOnPayroll as $gap): ?>
                    <tr>
                        <td><?= secure_h($gap['name']) ?></td>
                        <td><?= secure_h($gap['email']) ?></td>
                        <td><?= secure_status_badge($gap['profile_status']) ?></td>
                        <td><?= secure_status_badge($gap['main_status']) ?></td>
                        <td><a href="view-staff.php?id=<?= (int) $gap['id'] ?>" class="secure-btn secure-btn--ghost" style="padding:0.35rem 0.65rem;font-size:0.75rem;">View</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="secure-card">
    <p style="margin:0;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        Payroll includes every <strong style="color:var(--secure-text);">approved</strong> person on main ERP (unique email).
        Apply vault can have more people (e.g. pending approval on main).
        <?php if ($pendingApproval > 0): ?>
            <?= $pendingApproval ?> registered people are still <strong style="color:var(--secure-text);">pending approval</strong> on main ERP.
        <?php endif; ?>
    </p>
</div>

<?php secure_layout_end(); ?>
