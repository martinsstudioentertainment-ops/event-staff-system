<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/attendance-roster-helpers.php';

requireAdminCapability('invoices');

$pdo      = getDB();
$eventId  = (int) ($_GET['event_id'] ?? 0);
$status   = trim((string) ($_GET['status'] ?? ''));
$month    = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$events   = getEventsForCommissionInvoiceFilter($pdo);
$allList  = getCommissionInvoicesList($pdo, $eventId, $status, $month);
$page     = adminListPage();
$list     = array_slice($allList, adminListOffset($page), adminListPerPage());
$listTotal = count($allList);
$totals   = getCommissionInvoiceAggregates($pdo, $eventId, $status, $month);
$flash    = getAdminFlash();

$queryBase = http_build_query(array_filter([
    'event_id' => $eventId > 0 ? $eventId : null,
    'status'   => $status !== '' ? $status : null,
    'month'    => $month,
]));

$pageTitle  = 'Commission invoices';
$activePage = 'invoices';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Commission invoices</h2>
            <p class="card__subtitle">One invoice per event — monthly totals for all commissions in the selected month.</p>
        </div>
        <div class="toolbar toolbar--compact">
            <a href="export-invoices-month.php?<?= h($queryBase) ?>" class="btn btn--secondary">Export month CSV</a>
            <a href="print-invoices-month.php?<?= h($queryBase) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Print month summary</a>
            <a href="personal-invoices.php" class="btn btn--secondary">Personal invoices</a>
            <a href="job-records.php" class="btn btn--secondary">Staff job records</a>
            <a href="invoice-import.php" class="btn btn--secondary">Import spreadsheet</a>
            <a href="invoice-quick.php" class="btn btn--secondary">Quick invoice</a>
            <a href="personal-invoice-form.php" class="btn btn--primary">+ Personal invoice</a>
            <a href="job-record-form.php" class="btn btn--secondary">+ Staff job record</a>
            <a href="invoice-form.php" class="btn btn--secondary">From event sign-ins</a>
        </div>
    </div>

    <form method="get" class="filter-bar filter-bar--attendance">
        <div class="filter-bar__group">
            <label class="form-label" for="month">Month</label>
            <input class="form-input" type="month" id="month" name="month" value="<?= h($month) ?>" required>
        </div>
        <div class="filter-bar__group">
            <label class="form-label" for="event_id">Event</label>
            <select class="form-select" id="event_id" name="event_id">
                <option value="">All events</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>"<?= $eventId === (int) $event['id'] ? ' selected' : '' ?>>
                        <?= h(formatEventFilterOptionLabel($event)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__group">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All (excl. void)</option>
                <?php foreach (getCommissionInvoiceStatusOptions() as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Filter</button>
            <a href="invoices.php" class="btn btn--secondary">This month</a>
        </div>
    </form>

    <div class="stat-grid">
        <div class="stat-card">
            <p class="stat-card__value"><?= h($totals['month_label'] !== '' ? $totals['month_label'] : date('F Y', strtotime($month . '-01'))) ?></p>
            <p class="stat-card__label">Invoice month</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $totals['invoice_count'] ?></p>
            <p class="stat-card__label">Invoices (events)</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= (int) $totals['staff_count'] ?></p>
            <p class="stat-card__label">Staff shifts billed</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value"><?= h(formatHoursDecimal($totals['total_hours_billed'])) ?></p>
            <p class="stat-card__label">Total hours billed</p>
        </div>
        <div class="stat-card stat-card--highlight">
            <p class="stat-card__value"><?= h(formatSystemCurrencyAmount($totals['total_amount'], $pdo)) ?></p>
            <p class="stat-card__label">Total commission for month</p>
        </div>
    </div>
    <p class="form-hint">Totals sum all invoices with invoice date in this month<?= $status === '' ? ' (void invoices excluded)' : '' ?>. Hours worked this month: <strong><?= h(formatHoursDecimal($totals['total_hours_worked'])) ?></strong>.</p>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Staff</th>
                    <th>Hours billed</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($list === []): ?>
                    <tr>
                        <td colspan="8" class="data-table__empty">No commission invoices for this month. Change the month filter or create an invoice from Work hours.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td><strong><?= h((string) $row['invoice_number']) ?></strong></td>
                            <td>
                                <?= h((string) $row['event_name']) ?><br>
                                <span class="form-hint"><?= h(formatEventDateLabel((string) $row['event_date'])) ?></span>
                            </td>
                            <td><?= h(formatSystemDate((string) $row['invoice_date'], $pdo)) ?></td>
                            <td><?= (int) $row['staff_count'] ?></td>
                            <td><?= h(formatHoursDecimal((float) $row['total_hours_billed'])) ?></td>
                            <td><strong><?= h(formatSystemCurrencyAmount((float) $row['total_amount'], $pdo)) ?></strong></td>
                            <td><span class="badge badge--<?= $row['status'] === 'paid' ? 'approved' : ($row['status'] === 'draft' ? 'pending' : 'default') ?>"><?= h(getCommissionInvoiceStatusOptions()[(string) $row['status']] ?? $row['status']) ?></span></td>
                            <td>
                                <a href="invoice-form.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--secondary">Edit</a>
                                <a href="print-invoice.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--secondary" target="_blank" rel="noopener">Print</a>
                                <a href="export-invoice.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--secondary">CSV</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="data-table__foot">
                        <td colspan="3"><strong>Month total</strong></td>
                        <td><strong><?= (int) $totals['staff_count'] ?></strong></td>
                        <td><strong><?= h(formatHoursDecimal($totals['total_hours_billed'])) ?></strong></td>
                        <td><strong><?= h(formatSystemCurrencyAmount($totals['total_amount'], $pdo)) ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    renderAdminPagination($page, $listTotal, 'invoices.php', array_filter([
        'event_id' => $eventId > 0 ? $eventId : null,
        'status'   => $status !== '' ? $status : null,
        'month'    => $month !== date('Y-m') ? $month : null,
    ]));
    ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
