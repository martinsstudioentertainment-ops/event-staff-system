<?php



require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/job-record-repository.php';

require_once __DIR__ . '/../includes/admin-pagination.php';

require_once __DIR__ . '/../includes/system-settings.php';



requireAdminCapability('invoices');



$pdo    = getDB();

$month  = trim((string) ($_GET['month'] ?? date('Y-m')));

$status = trim((string) ($_GET['status'] ?? ''));

$q      = trim((string) ($_GET['q'] ?? ''));



if (!preg_match('/^\d{4}-\d{2}$/', $month)) {

    $month = date('Y-m');

}



$filters = [

    'month'        => $month,

    'status'       => $status,

    'q'            => $q,

    'invoice_type' => 'personal',

    'record_kind'  => 'invoice',

];

$all     = listJobRecords($pdo, $filters, 500);

$page    = adminListPage();

$records = array_slice($all, adminListOffset($page), adminListPerPage());

$total   = count($all);

$totals  = getJobRecordMonthTotals($pdo, $month, $status, 'personal', 'invoice');

$workLogs = listPersonalWorkLogs($pdo);

$flash   = getAdminFlash();

$today   = date('Y-m-d');



$pageTitle  = 'Personal invoices';

$activePage = 'personal-invoices';



include __DIR__ . '/../includes/admin/layout-top.php';

?>



<?php if ($flash): ?>

    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>

<?php endif; ?>



<section class="card" id="work-logs">

    <div class="card__header card__header--row">

        <div>

            <h2 class="card__title">Saved jobs (not yet invoiced)</h2>

            <p class="card__subtitle">Log work as you go, then invoice one or many jobs together.</p>

        </div>

        <a href="personal-work-log-form.php" class="btn btn--primary">+ Log a job</a>

    </div>



    <?php if ($workLogs === []): ?>

        <p class="form-hint" style="padding:0 0 1rem">No saved jobs waiting. <a href="personal-work-log-form.php">Log a job</a> after you finish work, then select multiple jobs to invoice.</p>

    <?php else: ?>

        <form method="post" action="job-record-action.php" class="erp-settings-form">

            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

            <input type="hidden" name="action" value="combine_work_logs">

            <input type="hidden" name="invoice_type" value="personal">



            <div class="erp-settings-form__grid" style="margin-bottom:1rem">

                <div class="form-group">

                    <label class="form-label form-label--required" for="combine_invoice_date">Invoice date</label>

                    <input class="form-input" type="date" id="combine_invoice_date" name="invoice_date" value="<?= h($today) ?>" required>

                </div>

                <div class="form-group">

                    <label class="form-label" for="combine_client_name">Bill to (client)</label>

                    <input class="form-input" type="text" id="combine_client_name" name="client_name" placeholder="Client for combined invoice">

                </div>

            </div>



            <div class="table-wrap">

                <table class="data-table">

                    <thead>

                        <tr>

                            <th style="width:2.5rem"><span class="sr-only">Select</span></th>

                            <th>Job</th>

                            <th>Date</th>

                            <th>Hours</th>

                            <th>Total</th>

                            <th>Actions</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($workLogs as $row): ?>

                            <tr>

                                <td>

                                    <input type="checkbox" name="work_log_ids[]" value="<?= (int) $row['id'] ?>" aria-label="Select <?= h((string) $row['event_name']) ?>">

                                </td>

                                <td>

                                    <strong><?= h((string) $row['event_name']) ?></strong>

                                    <?php if (!empty($row['venue'])): ?>

                                        <br><span class="form-hint"><?= h((string) $row['venue']) ?></span>

                                    <?php endif; ?>

                                </td>

                                <td><?= !empty($row['event_date']) ? h(formatSystemDate((string) $row['event_date'], $pdo)) : '—' ?></td>

                                <td><?= h(formatHoursDecimal((float) $row['total_hours'])) ?></td>

                                <td><?= h(formatSystemCurrencyAmount((float) $row['total_amount'], $pdo)) ?></td>

                                <td>

                                    <a href="personal-work-log-form.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--secondary">Edit</a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>



            <div class="form-actions form-actions--end" style="margin-top:1rem">

                <button type="submit" class="btn btn--secondary" name="save_and_print" value="1">Invoice selected &amp; print</button>

                <button type="submit" class="btn btn--primary">Invoice selected jobs</button>

            </div>

        </form>

    <?php endif; ?>

</section>



<section class="card" style="margin-top:1.5rem">

    <div class="card__header card__header--row">

        <div>

            <h2 class="card__title">Personal invoices</h2>

            <p class="card__subtitle">Invoices you created — single or multiple jobs per invoice.</p>

        </div>

        <div class="toolbar toolbar--compact">

            <a href="export-personal-shifts.php?<?= h(http_build_query(array_filter(['month' => $month !== '' ? $month : null]))) ?>" class="btn btn--secondary">Export shifts Excel</a>

            <a href="invoices.php" class="btn btn--secondary">Commission invoices</a>

            <a href="job-records.php" class="btn btn--secondary">Staff job records</a>

            <a href="personal-invoice-form.php" class="btn btn--primary">+ New personal invoice</a>

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

            <input class="form-input" type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Job, client, invoice #…">

        </div>

        <div class="filter-bar__actions">

            <button type="submit" class="btn btn--primary">Filter</button>

            <a href="personal-invoices.php" class="btn btn--secondary">This month</a>

        </div>

    </form>



    <div class="stat-grid">

        <div class="stat-card">

            <p class="stat-card__value"><?= (int) $totals['invoice_count'] ?></p>

            <p class="stat-card__label">Invoices this month</p>

        </div>

        <div class="stat-card">

            <p class="stat-card__value"><?= h(formatHoursDecimal($totals['total_hours'])) ?></p>

            <p class="stat-card__label">Hours invoiced</p>

        </div>

        <div class="stat-card stat-card--highlight">

            <p class="stat-card__value"><?= h(formatSystemCurrencyAmount($totals['total_amount'], $pdo)) ?></p>

            <p class="stat-card__label">Total value</p>

        </div>

    </div>



    <?php if ($records === []): ?>

        <p class="form-hint" style="padding:1rem 0">No personal invoices yet. <a href="personal-invoice-form.php">Create an invoice</a> or select saved jobs above.</p>

    <?php else: ?>

        <div class="table-wrap">

            <table class="data-table">

                <thead>

                    <tr>

                        <th>Invoice</th>

                        <th>Jobs</th>

                        <th>Bill to</th>

                        <th>Hours</th>

                        <th>Total</th>

                        <th>Status</th>

                        <th>Actions</th>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($records as $row): ?>

                        <?php

                        $lineCount = count(getPersonalInvoiceLines($pdo, (int) $row['id']));

                        if ($lineCount === 0) {

                            $lineCount = 1;

                        }

                        ?>

                        <tr>

                            <td>

                                <strong><?= h((string) $row['invoice_number']) ?></strong><br>

                                <span class="form-hint"><?= h(formatSystemDate((string) $row['invoice_date'], $pdo)) ?></span>

                            </td>

                            <td>

                                <?= h((string) $row['event_name']) ?>

                                <?php if ($lineCount > 1): ?>

                                    <br><span class="form-hint"><?= $lineCount ?> jobs</span>

                                <?php endif; ?>

                            </td>

                            <td><?= h((string) ($row['client_name'] ?: '—')) ?></td>

                            <td><?= h(formatHoursDecimal((float) $row['total_hours'])) ?></td>

                            <td><?= h(formatSystemCurrencyAmount((float) $row['total_amount'], $pdo)) ?></td>

                            <td><span class="badge badge--<?= ($row['status'] ?? '') === 'paid' ? 'approved' : (($row['status'] ?? '') === 'void' ? 'rejected' : 'pending') ?>"><?= h(getJobRecordStatusOptions()[(string) $row['status']] ?? $row['status']) ?></span></td>

                            <td class="data-table__actions">

                                <div class="table-actions table-actions--stack">

                                    <a href="export-personal-invoice.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--secondary">Excel</a>

                                    <a href="print-job-record.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--primary" target="_blank" rel="noopener">Print PDF</a>

                                    <a href="personal-invoice-form.php?id=<?= (int) $row['id'] ?>" class="btn btn--small btn--secondary">Edit</a>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <?php renderAdminPagination($page, $total, 'personal-invoices.php', array_filter([

            'month'  => $month,

            'status' => $status !== '' ? $status : null,

            'q'      => $q !== '' ? $q : null,

        ])); ?>

    <?php endif; ?>

</section>



<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

