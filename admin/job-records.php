<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/job-record-repository.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('invoices');

$pdo    = getDB();
$month  = trim((string) ($_GET['month'] ?? date('Y-m')));
$status = trim((string) ($_GET['status'] ?? ''));
$q      = trim((string) ($_GET['q'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$filters = ['month' => $month, 'status' => $status, 'q' => $q, 'invoice_type' => 'staff_commission'];
$all     = listJobRecords($pdo, $filters, 500);
$page    = adminListPage();
$records = array_slice($all, adminListOffset($page), adminListPerPage());
$total   = count($all);
$totals  = getJobRecordMonthTotals($pdo, $month, $status, 'staff_commission');
$flash   = getAdminFlash();

$queryBase = http_build_query(array_filter([
    'month'  => $month,
    'status' => $status !== '' ? $status : null,
    'q'      => $q !== '' ? $q : null,
]));

$pageTitle  = 'Saved job records';
$activePage = 'job-records';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Staff job records</h2>
            <p class="card__subtitle">Event staffing — staff count, hours per lad, rate, and total. Commission-style invoices for lads covered.</p>
        </div>
        <div class="toolbar toolbar--compact">
            <a href="personal-invoices.php" class="btn btn--secondary">Personal invoices</a>
            <a href="invoices.php" class="btn btn--secondary">Commission invoices</a>
            <a href="job-record-form.php" class="btn btn--primary">+ New job record</a>
        </div>
    </div>

    <form method="get" class="filter-bar filter-bar--compact">
        <div class="filter-bar__group">
            <label class="form-label" for="month">Month</label>
            <input class="form-input" type="month" id="month" name="month" value="<?= h($month) ?>">
        </div>
        <div class="filter-bar__group">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Active (excl. void)</option>
                <?php foreach (getJobRecordStatusOptions() as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__group">
            <label class="form-label" for="q">Search</label>
            <input class="form-input" type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Event, client, invoice #…">
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="job-records.php" class="btn btn--secondary">This month</a>
        </div>
    </form>

    <div class="stat-grid">
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $totals['invoice_count'] ?></p>
            <p class="stat-card__label">Jobs this month</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $totals['staff_count'] ?></p>
            <p class="stat-card__label">Staff (lads) billed</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= h(formatHoursDecimal($totals['total_hours'])) ?></p>
            <p class="stat-card__label">Total hours</p>
        </div>
        <div class="stat-card stat-card--highlight">
            <p class="stat-card__value"><?= h(formatSystemCurrencyAmount($totals['total_amount'], $pdo)) ?></p>
            <p class="stat-card__label">Total value</p>
        </div>
    </div>

    <?php if ($records === []): ?>
        <p class="form-hint" style="padding:1rem 0">No saved jobs yet. <a href="job-record-form.php">Create your first job record</a> — enter event details, staff, hours, and rate, then print the invoice as PDF.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Event / job</th>
                        <th>Client</th>
                        <th>Staff</th>
                        <th>Hours</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $row): ?>
                        <tr>
                            <td>
                                <strong><?= h((string) $row['invoice_number']) ?></strong><br>
                                <span class="form-hint"><?= h(formatSystemDate((string) $row['invoice_date'], $pdo)) ?></span>
                            </td>
                            <td>
                                <?= h((string) $row['event_name']) ?>
                                <?php if (!empty($row['venue'])): ?>
                                    <br><span class="form-hint"><?= h((string) $row['venue']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= h((string) ($row['client_name'] ?: '—')) ?></td>
                            <td><?= (int) $row['staff_count'] ?></td>
                            <td><?= h(formatHoursDecimal((float) $row['total_hours'])) ?></td>
                            <td><?= h(formatSystemCurrencyAmount((float) $row['total_amount'], $pdo)) ?></td>
                            <td><span class="badge badge--<?= ($row['status'] ?? '') === 'paid' ? 'approved' : (($row['status'] ?? '') === 'void' ? 'rejected' : 'pending') ?>"><?= h(getJobRecordStatusOptions()[(string) $row['status']] ?? $row['status']) ?></span></td>
                            <td class="data-table__actions">
                                <div class="table-actions table-actions--stack">
                                    <a href="print-job-record.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--primary" target="_blank" rel="noopener">Print PDF</a>
                                    <a href="job-record-form.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--secondary">Edit</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderAdminPagination($page, $total, 'job-records.php', array_filter([
            'month'  => $month,
            'status' => $status !== '' ? $status : null,
            'q'      => $q !== '' ? $q : null,
        ])); ?>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
