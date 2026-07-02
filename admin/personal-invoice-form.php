<?php



require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/job-record-repository.php';

require_once __DIR__ . '/../includes/system-settings.php';



requireAdminCapability('invoices');



if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {

    setAdminFlash('error', 'Only administrators and managers can edit invoices.');

    header('Location: personal-invoices.php');

    exit;

}



$pdo      = getDB();

$id       = (int) ($_GET['id'] ?? 0);

$record   = $id > 0 ? getJobRecordById($pdo, $id) : null;

$flash    = getAdminFlash();

$today    = date('Y-m-d');

$currency = getSystemCurrency($pdo);



if ($id > 0 && $record === null) {

    setAdminFlash('error', 'Invoice not found.');

    header('Location: personal-invoices.php');

    exit;

}



if ($record !== null && !isPersonalInvoiceRecord($record) && !isPersonalJobRecord($record)) {

    header('Location: job-record-form.php?id=' . $id);

    exit;

}



if ($record !== null && isPersonalWorkLog($record)) {

    header('Location: personal-work-log-form.php?id=' . $id);

    exit;

}



$combineIds = [];

foreach (explode(',', (string) ($_GET['combine'] ?? '')) as $part) {

    $part = (int) trim($part);

    if ($part > 0) {

        $combineIds[] = $part;

    }

}



$lines = [];

if ($record !== null) {

    $lines = getPersonalInvoiceLines($pdo, $id);

    if ($lines === []) {

        $lines = personalInvoiceLinesFromRecord($record);

    }

} elseif ($combineIds !== []) {

    $lines = personalInvoiceLinesFromWorkLogs($pdo, $combineIds);

}



if ($lines === []) {

    $lines = [[

        'description' => '',

        'job_date'    => '',

        'venue'       => '',

        'hours'       => 8,

        'hourly_rate' => 1,

        'line_amount' => '',

    ]];

}



$workLogs = listPersonalWorkLogs($pdo);

$selectedWorkLogIds = $combineIds;

foreach ($lines as $line) {

    if (!empty($line['source_work_log_id'])) {

        $selectedWorkLogIds[] = (int) $line['source_work_log_id'];

    }

}

$selectedWorkLogIds = array_values(array_unique($selectedWorkLogIds));



$defaults = [

    'invoice_number' => $record['invoice_number'] ?? generateJobRecordInvoiceNumber($pdo, 'personal'),

    'invoice_date'   => $record['invoice_date'] ?? $today,

    'client_name'    => $record['client_name'] ?? '',

    'notes'          => $record['notes'] ?? '',

    'status'         => $record['status'] ?? 'draft',

];



$pageTitle  = $record ? 'Edit personal invoice' : 'New personal invoice';

$activePage = 'personal-invoices';



include __DIR__ . '/../includes/admin/layout-top.php';

?>



<?php if ($flash): ?>

    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>

<?php endif; ?>



<section class="card">

    <div class="card__header card__header--row">

        <div>

            <h2 class="card__title"><?= $record ? 'Edit personal invoice' : 'New personal invoice' ?></h2>

            <p class="card__subtitle">Add multiple jobs on one invoice, or pick saved jobs you logged earlier.</p>

        </div>

        <div class="toolbar toolbar--compact">

            <?php if ($id > 0): ?>

                <a href="export-personal-invoice.php?id=<?= $id ?>" class="btn btn--primary">Export Excel</a>

            <?php endif; ?>

            <a href="personal-work-log-form.php" class="btn btn--secondary">+ Log a job</a>

            <a href="personal-invoices.php" class="btn btn--secondary">← All invoices</a>

        </div>

    </div>



    <form method="post" action="job-record-action.php" class="erp-settings-form" id="personal-invoice-form" data-currency="<?= h($currency) ?>">

        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <input type="hidden" name="invoice_type" value="personal">

        <?php if ($id > 0): ?>

            <input type="hidden" name="id" value="<?= $id ?>">

        <?php endif; ?>



        <div class="erp-settings-form__grid">

            <div class="form-group">

                <label class="form-label form-label--required" for="invoice_date">Invoice date</label>

                <input class="form-input" type="date" id="invoice_date" name="invoice_date" value="<?= h((string) $defaults['invoice_date']) ?>" required>

            </div>

            <div class="form-group">

                <label class="form-label form-label--required" for="invoice_number">Invoice reference</label>

                <input class="form-input" type="text" id="invoice_number" name="invoice_number" value="<?= h((string) $defaults['invoice_number']) ?>" required>

            </div>

            <div class="form-group">

                <label class="form-label" for="client_name">Bill to (client)</label>

                <input class="form-input" type="text" id="client_name" name="client_name" value="<?= h((string) $defaults['client_name']) ?>" placeholder="e.g. Map Security">

            </div>

            <div class="form-group">

                <label class="form-label" for="status">Status</label>

                <select class="form-select" id="status" name="status">

                    <?php foreach (getJobRecordStatusOptions() as $value => $label): ?>

                        <?php if ($value === 'void') continue; ?>

                        <option value="<?= h($value) ?>"<?= $defaults['status'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>

                    <?php endforeach; ?>

                </select>

            </div>

        </div>



        <?php if ($workLogs !== []): ?>

            <div class="card card--nested personal-invoice-nested">

                <h3 class="card__title" style="font-size:1rem;margin:0 0 0.75rem">Add from saved jobs</h3>

                <p class="form-hint" style="margin-top:0">Select jobs you logged earlier — they will be added as invoice lines when you save.</p>

                <div class="personal-work-log-picker">

                    <?php foreach ($workLogs as $workLog): ?>

                        <?php

                        $wlId = (int) $workLog['id'];

                        $checked = in_array($wlId, $selectedWorkLogIds, true);

                        ?>

                        <label class="personal-work-log-picker__item">

                            <input type="checkbox" name="work_log_ids[]" value="<?= $wlId ?>" class="personal-work-log-pick" data-hours="<?= h((string) ($workLog['total_hours'] ?? 0)) ?>" data-amount="<?= h((string) ($workLog['total_amount'] ?? 0)) ?>"<?= $checked ? ' checked' : '' ?>>

                            <span>

                                <strong><?= h((string) $workLog['event_name']) ?></strong>

                                <?php if (!empty($workLog['event_date'])): ?>

                                    · <?= h(formatSystemDate((string) $workLog['event_date'], $pdo)) ?>

                                <?php endif; ?>

                                · <?= h(formatHoursDecimal((float) $workLog['total_hours'])) ?> h

                                · <?= h(formatSystemCurrencyAmount((float) $workLog['total_amount'], $pdo)) ?>

                            </span>

                        </label>

                    <?php endforeach; ?>

                </div>

            </div>

        <?php endif; ?>



        <div class="personal-invoice-lines" id="personal-invoice-lines">

            <div class="personal-invoice-lines__head">

                <h3 class="card__title" style="font-size:1rem;margin:0">Jobs on this invoice</h3>

                <button type="button" class="btn btn--small btn--secondary" id="personal-add-line">+ Add job line</button>

            </div>



            <template id="personal-line-template">

                <div class="personal-invoice-line" data-line>

                    <div class="personal-invoice-line__grid">

                        <div class="form-group form-group--full">

                            <label class="form-label form-label--required">Job description</label>

                            <input class="form-input" type="text" data-name="description" placeholder="What you did" required>

                        </div>

                        <div class="form-group">

                            <label class="form-label">Job date</label>

                            <input class="form-input" type="date" data-name="job_date">

                        </div>

                        <div class="form-group">

                            <label class="form-label">Location</label>

                            <input class="form-input" type="text" data-name="venue" placeholder="Venue / city">

                        </div>

                        <div class="form-group">

                            <label class="form-label form-label--required">Hours</label>

                            <input class="form-input personal-line-calc" type="number" min="0.25" step="0.25" data-name="hours" value="8" required>

                        </div>

                        <div class="form-group">

                            <label class="form-label form-label--required">Rate / h</label>

                            <input class="form-input personal-line-calc" type="number" min="0" step="0.01" data-name="hourly_rate" value="1" required>

                        </div>

                        <div class="form-group">

                            <label class="form-label">Line total</label>

                            <input class="form-input personal-line-amount" type="number" min="0" step="0.01" data-name="line_amount" placeholder="Auto">

                        </div>

                        <div class="form-group personal-invoice-line__actions">

                            <label class="form-label">&nbsp;</label>

                            <button type="button" class="btn btn--small btn--danger personal-remove-line">Remove</button>

                        </div>

                    </div>

                </div>

            </template>



            <div id="personal-lines-container">

                <?php foreach ($lines as $index => $line): ?>

                    <div class="personal-invoice-line" data-line>

                        <div class="personal-invoice-line__grid">

                            <div class="form-group form-group--full">

                                <label class="form-label form-label--required">Job description</label>

                                <input class="form-input" type="text" name="lines[<?= $index ?>][description]" value="<?= h((string) ($line['description'] ?? '')) ?>" placeholder="What you did" required>

                            </div>

                            <div class="form-group">

                                <label class="form-label">Job date</label>

                                <input class="form-input" type="date" name="lines[<?= $index ?>][job_date]" value="<?= h((string) ($line['job_date'] ?? '')) ?>">

                            </div>

                            <div class="form-group">

                                <label class="form-label">Location</label>

                                <input class="form-input" type="text" name="lines[<?= $index ?>][venue]" value="<?= h((string) ($line['venue'] ?? '')) ?>" placeholder="Venue / city">

                            </div>

                            <div class="form-group">

                                <label class="form-label form-label--required">Hours</label>

                                <input class="form-input personal-line-calc" type="number" min="0.25" step="0.25" name="lines[<?= $index ?>][hours]" value="<?= h((string) ($line['hours'] ?? '')) ?>" required>

                            </div>

                            <div class="form-group">

                                <label class="form-label form-label--required">Rate / h</label>

                                <input class="form-input personal-line-calc" type="number" min="0" step="0.01" name="lines[<?= $index ?>][hourly_rate]" value="<?= h((string) ($line['hourly_rate'] ?? '')) ?>" required>

                            </div>

                            <div class="form-group">

                                <label class="form-label">Line total</label>

                                <input class="form-input personal-line-amount" type="number" min="0" step="0.01" name="lines[<?= $index ?>][line_amount]" value="<?= ($line['line_amount'] ?? '') !== '' ? h((string) $line['line_amount']) : '' ?>" placeholder="Auto">

                            </div>

                            <div class="form-group personal-invoice-line__actions">

                                <label class="form-label">&nbsp;</label>

                                <button type="button" class="btn btn--small btn--danger personal-remove-line"<?= count($lines) <= 1 ? ' disabled' : '' ?>>Remove</button>

                            </div>

                            <?php if (!empty($line['source_work_log_id'])): ?>

                                <input type="hidden" name="lines[<?= $index ?>][source_work_log_id]" value="<?= (int) $line['source_work_log_id'] ?>">

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>



        <div class="erp-settings-form__grid" style="margin-top:1rem">

            <div class="form-group form-group--full">

                <div class="stat-card stat-card--highlight" id="personal-live-total" style="margin:0">

                    <p class="stat-card__value" id="personal-live-total-value">—</p>

                    <p class="stat-card__label"><span id="personal-live-hours-label">0</span> hours · <span id="personal-live-jobs-label">0</span> job lines (+ saved jobs selected)</p>

                </div>

            </div>

            <div class="form-group form-group--full">

                <label class="form-label" for="notes">Notes (on invoice)</label>

                <textarea class="form-textarea" id="notes" name="notes" rows="3" placeholder="Payment terms, extra detail…"><?= h((string) $defaults['notes']) ?></textarea>

            </div>

        </div>



        <p class="form-hint">Bank details for printed invoices: <a href="settings-production.php">ERP Settings → Invoice payment details</a>.</p>



        <div class="form-actions form-actions--end">

            <?php if ($id > 0): ?>

                <a href="print-job-record.php?id=<?= $id ?>" class="btn btn--secondary" target="_blank" rel="noopener">Print PDF</a>

            <?php endif; ?>

            <button type="submit" class="btn btn--secondary" name="save_and_print" value="1">Save &amp; print</button>

            <button type="submit" class="btn btn--primary">Save invoice</button>

        </div>

    </form>



    <?php if ($id > 0 && ($record['status'] ?? '') !== 'void'): ?>

        <form method="post" action="job-record-action.php" class="job-record-void-form" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--admin-border)" onsubmit="return confirm('Mark this invoice as void?');">

            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

            <input type="hidden" name="action" value="void">

            <input type="hidden" name="id" value="<?= $id ?>">

            <button type="submit" class="btn btn--small btn--danger">Void invoice</button>

        </form>

    <?php endif; ?>

</section>



<script src="<?= h($assetBase) ?>assets/js/personal-invoice-form.js"></script>



<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

