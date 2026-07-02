<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commission-invoice-quick.php';
require_once __DIR__ . '/../includes/system-settings.php';

requireAdminCapability('invoices');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can create quick invoices.');
    header('Location: invoices.php');
    exit;
}

$pdo       = getDB();
$pageTitle = 'Quick invoice (print only)';
$activePage = 'invoices';
$flash     = getAdminFlash();
$today     = date('Y-m-d');

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Quick invoice — print only</h2>
            <p class="card__subtitle">One-off print only — not saved. To keep job details for later, use <a href="job-record-form.php">Saved job records</a>.</p>
        </div>
        <a href="invoices.php" class="btn btn--secondary">← All invoices</a>
    </div>

    <div class="alert alert--warning alert--visible">
        This invoice is <strong>not stored</strong> in the system. Use <a href="invoice-import.php">Import spreadsheet</a> or <a href="invoice-form.php">New invoice</a> if you need it saved for monthly reports.
    </div>

    <form method="post" action="print-invoice-quick.php" target="_blank" class="erp-settings-form" id="quick-invoice-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="erp-settings-form__grid">
            <div class="form-group">
                <label class="form-label form-label--required" for="invoice_date">Invoice date</label>
                <input class="form-input" type="date" id="invoice_date" name="invoice_date" value="<?= h($today) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="invoice_number">Invoice reference</label>
                <input class="form-input" type="text" id="invoice_number" name="invoice_number" placeholder="Auto: QUICK-YYYYMMDD-HHMMSS">
            </div>
            <div class="form-group">
                <label class="form-label" for="client_name">Client name</label>
                <input class="form-input" type="text" id="client_name" name="client_name" placeholder="e.g. Map Security">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label form-label--required" for="event_name">Event / job description</label>
                <input class="form-input" type="text" id="event_name" name="event_name" placeholder="e.g. Aviva Stadium concert" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="event_date">Event date</label>
                <input class="form-input" type="date" id="event_date" name="event_date">
            </div>
            <div class="form-group">
                <label class="form-label" for="venue">Venue</label>
                <input class="form-input" type="text" id="venue" name="venue" placeholder="e.g. Aviva Stadium, Dublin">
            </div>
            <div class="form-group">
                <label class="form-label form-label--required" for="staff_count">Number of staff</label>
                <input class="form-input" type="number" min="1" step="1" id="staff_count" name="staff_count" value="30" required>
            </div>
            <div class="form-group">
                <label class="form-label form-label--required" for="hours_per_lad">Hours per staff</label>
                <input class="form-input" type="number" min="0.25" step="0.25" id="hours_per_lad" name="hours_per_lad" value="6" required>
            </div>
            <div class="form-group">
                <label class="form-label form-label--required" for="hourly_rate">Rate / hour (<?= h(getSystemCurrency($pdo)) ?>)</label>
                <input class="form-input" type="number" min="0" step="0.01" id="hourly_rate" name="hourly_rate" value="1" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="total_amount">Total override</label>
                <input class="form-input" type="number" min="0" step="0.01" id="total_amount" name="total_amount" placeholder="Leave blank = staff × hours × rate">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label" for="line_description">Line description on invoice</label>
                <input class="form-input" type="text" id="line_description" name="line_description" placeholder="Auto-generated from staff count and hours if blank">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label" for="notes">Notes</label>
                <textarea class="form-textarea" id="notes" name="notes" rows="2" placeholder="Optional — shown on printed invoice"></textarea>
            </div>
        </div>

        <p class="form-hint">Bank details come from <a href="settings-production.php">ERP Settings → Invoice payment details</a>.</p>

        <div class="form-actions form-actions--end">
            <button type="submit" class="btn btn--primary">Preview &amp; print</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">When to use which option</h2>
    </div>
    <ul class="form-hint" style="margin:0;padding-left:1.25rem;">
        <li><strong>Quick invoice (this page)</strong> — ad-hoc, print/PDF only, not in monthly totals.</li>
        <li><strong>Import spreadsheet</strong> — saved to system, appears in monthly reports.</li>
        <li><strong>New invoice from event</strong> — built from real sign-ins and work hours.</li>
    </ul>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
