<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/job-record-repository.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/system-settings.php';

requireAdminCapability('invoices');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can edit job records.');
    header('Location: job-records.php');
    exit;
}

$pdo     = getDB();
$id      = (int) ($_GET['id'] ?? 0);
$record  = $id > 0 ? getJobRecordById($pdo, $id) : null;
$events  = getEventsForFilter($pdo);
$flash   = getAdminFlash();
$today   = date('Y-m-d');
$currency = getSystemCurrency($pdo);

if ($id > 0 && $record === null) {
    setAdminFlash('error', 'Job record not found.');
    header('Location: job-records.php');
    exit;
}

if ($record !== null && isPersonalJobRecord($record)) {
    header('Location: personal-invoice-form.php?id=' . $id);
    exit;
}

$defaults = [
    'invoice_number'   => $record['invoice_number'] ?? generateJobRecordInvoiceNumber($pdo),
    'invoice_date'     => $record['invoice_date'] ?? $today,
    'client_name'      => $record['client_name'] ?? '',
    'event_name'       => $record['event_name'] ?? '',
    'event_date'       => $record['event_date'] ?? '',
    'venue'            => $record['venue'] ?? '',
    'staff_count'      => $record['staff_count'] ?? 30,
    'hours_per_staff'  => $record['hours_per_staff'] ?? 6,
    'hourly_rate'      => $record['hourly_rate'] ?? 1,
    'total_amount'     => $record['total_amount'] ?? '',
    'line_description' => $record['line_description'] ?? '',
    'notes'            => $record['notes'] ?? '',
    'status'           => $record['status'] ?? 'draft',
    'event_id'         => $record['event_id'] ?? '',
];

$pageTitle  = $record ? 'Edit job record' : 'New job record';
$activePage = 'job-records';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= $record ? 'Edit saved job' : 'New job record' ?></h2>
            <p class="card__subtitle">Save event coverage details (staff / lads, hours, rate). Print or save as PDF whenever you need an invoice.</p>
        </div>
        <a href="job-records.php" class="btn btn--secondary">← All job records</a>
    </div>

    <form method="post" action="job-record-action.php" class="erp-settings-form" id="job-record-form" data-currency="<?= h($currency) ?>">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="invoice_type" value="staff_commission">
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
                <label class="form-label" for="client_name">Client name</label>
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
            <div class="form-group form-group--full">
                <label class="form-label form-label--required" for="event_name">Event / job covered</label>
                <input class="form-input" type="text" id="event_name" name="event_name" value="<?= h((string) $defaults['event_name']) ?>" placeholder="e.g. Aviva Stadium concert" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="event_date">Event date</label>
                <input class="form-input" type="date" id="event_date" name="event_date" value="<?= h((string) $defaults['event_date']) ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="venue">Venue</label>
                <input class="form-input" type="text" id="venue" name="venue" value="<?= h((string) $defaults['venue']) ?>" placeholder="e.g. Aviva Stadium, Dublin">
            </div>
            <div class="form-group">
                <label class="form-label" for="event_id">Link to system event (optional)</label>
                <select class="form-select" id="event_id" name="event_id">
                    <option value="">— None —</option>
                    <?php foreach ($events as $event): ?>
                        <option value="<?= (int) $event['id'] ?>"<?= (string) $defaults['event_id'] === (string) $event['id'] ? ' selected' : '' ?>>
                            <?= h($event['name'] . ' — ' . formatEventDateLabel((string) $event['event_date'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label form-label--required" for="staff_count">Number of staff (lads)</label>
                <input class="form-input job-calc" type="number" min="1" step="1" id="staff_count" name="staff_count" value="<?= h((string) $defaults['staff_count']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label form-label--required" for="hours_per_staff">Hours per staff</label>
                <input class="form-input job-calc" type="number" min="0.25" step="0.25" id="hours_per_staff" name="hours_per_staff" value="<?= h((string) $defaults['hours_per_staff']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label form-label--required" for="hourly_rate">Rate / hour (<?= h($currency) ?>)</label>
                <input class="form-input job-calc" type="number" min="0" step="0.01" id="hourly_rate" name="hourly_rate" value="<?= h((string) $defaults['hourly_rate']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="total_amount">Total override</label>
                <input class="form-input job-calc-override" type="number" min="0" step="0.01" id="total_amount" name="total_amount" value="<?= $defaults['total_amount'] !== '' ? h((string) $defaults['total_amount']) : '' ?>" placeholder="Auto = staff × hours × rate">
            </div>
            <div class="form-group form-group--full">
                <div class="stat-card stat-card--highlight" id="job-live-total" style="margin:0">
                    <p class="stat-card__value" id="job-live-total-value">—</p>
                    <p class="stat-card__label"><span id="job-live-hours-label">0</span> total hours · calculated total</p>
                </div>
            </div>
            <div class="form-group form-group--full">
                <label class="form-label" for="line_description">Line on printed invoice</label>
                <input class="form-input" type="text" id="line_description" name="line_description" value="<?= h((string) $defaults['line_description']) ?>" placeholder="Auto-generated if blank">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label" for="notes">Job notes (internal)</label>
                <textarea class="form-textarea" id="notes" name="notes" rows="3" placeholder="Coverage details, contact on site, extras…"><?= h((string) $defaults['notes']) ?></textarea>
            </div>
        </div>

        <p class="form-hint">Bank details for printed invoices: <a href="settings-production.php">ERP Settings → Invoice payment details</a>.</p>

        <div class="form-actions form-actions--end">
            <?php if ($id > 0): ?>
                <a href="print-job-record.php?id=<?= $id ?>" class="btn btn--secondary" target="_blank" rel="noopener">Print PDF</a>
            <?php endif; ?>
            <button type="submit" class="btn btn--secondary" name="save_and_print" value="1">Save &amp; print</button>
            <button type="submit" class="btn btn--primary">Save job record</button>
        </div>
    </form>

    <?php if ($id > 0 && ($record['status'] ?? '') !== 'void'): ?>
        <form method="post" action="job-record-action.php" class="job-record-void-form" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--admin-border)" onsubmit="return confirm('Mark this job record as void?');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="void">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn--small btn--danger">Void record</button>
        </form>
    <?php endif; ?>
</section>

<script src="<?= h($assetBase) ?>assets/js/job-record-form.js"></script>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
