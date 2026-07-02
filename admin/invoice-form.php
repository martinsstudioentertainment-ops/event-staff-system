<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';

requireAdminCapability('invoices');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can edit commission invoices.');
    header('Location: invoices.php');
    exit;
}

$pdo       = getDB();
$invoiceId = (int) ($_GET['id'] ?? 0);
$eventId   = (int) ($_GET['event_id'] ?? 0);
$events    = getEventsForFilter($pdo);
$invoice   = null;
$event     = null;
$lines     = [];
$error     = '';

if ($invoiceId > 0) {
    $invoice = getCommissionInvoiceById($pdo, $invoiceId);
    if (!$invoice) {
        setAdminFlash('error', 'Invoice not found.');
        header('Location: invoices.php');
        exit;
    }
    $eventId = (int) $invoice['event_id'];
    $event   = getEventById($pdo, $eventId);
    $lines   = getCommissionInvoiceLines($pdo, $invoiceId);
} elseif ($eventId > 0) {
    $event = getEventById($pdo, $eventId);
    if (!$event) {
        setAdminFlash('error', 'Event not found.');
        header('Location: invoices.php');
        exit;
    }

    $existing = getCommissionInvoiceByEventId($pdo, $eventId);
    if ($existing) {
        header('Location: invoice-form.php?id=' . (int) $existing['id']);
        exit;
    }

    $lines = buildCommissionInvoiceLinesFromEvent($pdo, $eventId);
    if ($lines === []) {
        $error = 'No sign-ins for this event yet. Staff must check in before creating an invoice.';
    }
}

$totals = recomputeCommissionInvoiceTotals($lines);
$currency = $invoice['currency'] ?? getSystemCurrency($pdo);
$canEdit  = true;

$pageTitle  = $invoice ? 'Edit commission invoice' : ($event ? 'New commission invoice' : 'Create commission invoice');
$activePage = 'invoices';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($error !== ''): ?>
    <div class="alert alert--error alert--visible"><?= h($error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= h($pageTitle) ?></h2>
            <?php if ($event): ?>
                <p class="card__subtitle"><?= h($event['name']) ?> · <?= h(formatEventDateLabel((string) $event['event_date'])) ?></p>
            <?php else: ?>
                <p class="card__subtitle">Pick an event with completed sign-ins to build the commission invoice.</p>
            <?php endif; ?>
        </div>
        <a href="invoices.php" class="btn btn--secondary">← All invoices</a>
    </div>

    <?php if (!$event): ?>
        <form method="get" class="filter-bar filter-bar--attendance">
            <div class="filter-bar__group">
                <label class="form-label" for="event_id">Event</label>
                <select class="form-select" id="event_id" name="event_id" required>
                    <option value="">Select event…</option>
                    <?php foreach ($events as $ev): ?>
                        <option value="<?= (int) $ev['id'] ?>"><?= h($ev['name'] . ' — ' . formatEventDateLabel((string) $ev['event_date'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-bar__actions">
                <button type="submit" class="btn btn--primary">Continue</button>
            </div>
        </form>
    <?php else: ?>

    <div class="stat-grid" id="invoice-totals-bar" data-currency="<?= h(getSystemCurrency($pdo)) ?>">
        <div class="stat-card">
            <p class="stat-card__value" data-total="staff"><?= (int) $totals['staff_count'] ?></p>
            <p class="stat-card__label">Staff (lads)</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value" data-total="hours-worked"><?= h(formatHoursDecimal($totals['total_hours_worked'])) ?></p>
            <p class="stat-card__label">Total hours worked</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value" data-total="hours-billed"><?= h(formatHoursDecimal($totals['total_hours_billed'])) ?></p>
            <p class="stat-card__label">Total hours billed</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__value" data-total="amount"><?= h(formatSystemCurrencyAmount($totals['total_amount'], $pdo)) ?></p>
            <p class="stat-card__label">Commission total</p>
        </div>
    </div>

    <form method="post" action="invoice-action.php" class="invoice-form" id="commission-invoice-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
        <input type="hidden" name="invoice_id" value="<?= (int) ($invoice['id'] ?? 0) ?>">

        <div class="form-grid erp-settings-form__grid">
            <div class="form-group">
                <label class="form-label" for="invoice_number">Invoice number</label>
                <input class="form-input" type="text" id="invoice_number" name="invoice_number" value="<?= h((string) ($invoice['invoice_number'] ?? '')) ?>" placeholder="Auto-generated if blank">
            </div>
            <div class="form-group">
                <label class="form-label form-label--required" for="invoice_date">Invoice date</label>
                <input class="form-input" type="date" id="invoice_date" name="invoice_date" value="<?= h((string) ($invoice['invoice_date'] ?? date('Y-m-d'))) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="client_name">Client name</label>
                <input class="form-input" type="text" id="client_name" name="client_name" value="<?= h((string) ($invoice['client_name'] ?? '')) ?>" placeholder="Who pays this commission">
            </div>
            <div class="form-group">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <?php foreach (getCommissionInvoiceStatusOptions() as $value => $label): ?>
                        <option value="<?= h($value) ?>"<?= (($invoice['status'] ?? 'draft') === $value) ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group form-group--full">
                <label class="form-label" for="print_layout">Print layout (client copy)</label>
                <select class="form-select" id="print_layout" name="print_layout">
                    <?php foreach (getCommissionInvoicePrintLayoutOptions() as $value => $label): ?>
                        <option value="<?= h($value) ?>"<?= (($invoice['print_layout'] ?? 'detailed') === $value) ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">Use <strong>Summary</strong> when the client does not need every lad name — totals only on print/PDF.</p>
            </div>
            <div class="form-group form-group--full">
                <label class="form-label" for="notes">Notes</label>
                <textarea class="form-textarea" id="notes" name="notes" rows="2" placeholder="Optional notes on this commission"><?= h((string) ($invoice['notes'] ?? '')) ?></textarea>
            </div>
        </div>

        <?php if ($lines === []): ?>
            <p class="form-hint">No staff lines to invoice. Check in staff first via Attendance or Work hours.</p>
        <?php else: ?>
            <div class="toolbar toolbar--compact invoice-fixed-toolbar" style="margin-top:1.25rem;">
                <button type="button" class="btn btn--secondary btn--small" id="invoice-fixed-select-all">Select all Fixed</button>
                <button type="button" class="btn btn--secondary btn--small" id="invoice-fixed-deselect-all">Deselect all Fixed</button>
                <span class="form-hint" style="margin:0;" id="invoice-fixed-toolbar-hint" aria-live="polite"></span>
            </div>
            <div class="table-wrap" style="margin-top:0.5rem;">
                <table class="data-table invoice-lines-table" id="invoice-lines-table">
                    <thead>
                        <tr>
                            <th>Staff</th>
                            <th>Role</th>
                            <th>Hours worked</th>
                            <th>Hours billed</th>
                            <th>Rate / hr</th>
                            <th>Amount</th>
                            <th>
                                <label class="form-checkbox" style="margin:0;white-space:nowrap;">
                                    <input type="checkbox" id="invoice-fixed-select-all-head" aria-label="Select all Fixed">
                                    <span>Fixed</span>
                                </label>
                            </th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $index => $line): ?>
                            <tr class="invoice-line" data-line-index="<?= (int) $index ?>">
                                <td>
                                    <strong><?= h((string) $line['staff_name']) ?></strong>
                                    <input type="hidden" name="lines[<?= (int) $index ?>][staff_name]" value="<?= h((string) $line['staff_name']) ?>">
                                    <input type="hidden" name="lines[<?= (int) $index ?>][registration_id]" value="<?= (int) $line['registration_id'] ?>">
                                    <input type="hidden" name="lines[<?= (int) $index ?>][attendance_id]" value="<?= (int) ($line['attendance_id'] ?? 0) ?>">
                                    <input type="hidden" name="lines[<?= (int) $index ?>][staff_role]" value="<?= h((string) ($line['staff_role'] ?? '')) ?>">
                                    <input type="hidden" name="lines[<?= (int) $index ?>][sort_order]" value="<?= (int) ($line['sort_order'] ?? $index) ?>">
                                </td>
                                <td><?= h(formatRoleLabel((string) ($line['staff_role'] ?? ''))) ?></td>
                                <td>
                                    <input class="form-input invoice-line__hours-worked" type="number" step="0.25" min="0" name="lines[<?= (int) $index ?>][hours_worked]" value="<?= h((string) $line['hours_worked']) ?>">
                                </td>
                                <td>
                                    <input class="form-input invoice-line__hours-billed" type="number" step="0.25" min="0" name="lines[<?= (int) $index ?>][hours_billed]" value="<?= h((string) $line['hours_billed']) ?>">
                                </td>
                                <td>
                                    <input class="form-input invoice-line__rate" type="number" step="0.01" min="0" name="lines[<?= (int) $index ?>][hourly_rate]" value="<?= h((string) $line['hourly_rate']) ?>">
                                </td>
                                <td>
                                    <input class="form-input invoice-line__amount" type="number" step="0.01" min="0" name="lines[<?= (int) $index ?>][line_amount]" value="<?= h((string) $line['line_amount']) ?>">
                                </td>
                                <td class="invoice-line__override-cell">
                                    <label class="form-checkbox">
                                        <input type="checkbox" class="invoice-line__override" name="lines[<?= (int) $index ?>][amount_override]" value="1"<?= !empty($line['amount_override']) ? ' checked' : '' ?>>
                                        <span>Fixed</span>
                                    </label>
                                </td>
                                <td>
                                    <input class="form-input" type="text" name="lines[<?= (int) $index ?>][note]" value="<?= h((string) ($line['note'] ?? '')) ?>" placeholder="Optional">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="data-table__foot">
                            <td colspan="2"><strong>Event totals</strong></td>
                            <td><strong data-total="hours-worked-foot"><?= h(formatHoursDecimal($totals['total_hours_worked'])) ?></strong></td>
                            <td><strong data-total="hours-billed-foot"><?= h(formatHoursDecimal($totals['total_hours_billed'])) ?></strong></td>
                            <td></td>
                            <td><strong data-total="amount-foot"><?= h(formatSystemCurrencyAmount($totals['total_amount'], $pdo)) ?></strong></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="form-hint">Uncheck <strong>Fixed</strong> to recalculate amount from hours billed × rate. Default rates: <a href="settings-production.php">ERP settings → System</a>.</p>
        <?php endif; ?>

        <div class="form-actions form-actions--end" style="margin-top:1.25rem;">
            <?php if ($invoice): ?>
                <a href="print-invoice.php?id=<?= (int) $invoice['id'] ?>" class="btn btn--secondary" target="_blank" rel="noopener">Print</a>
                <a href="export-invoice.php?id=<?= (int) $invoice['id'] ?>" class="btn btn--secondary">Export CSV</a>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary"<?= $lines === [] ? ' disabled' : '' ?>>Save invoice</button>
        </div>
    </form>

    <?php if ($invoice): ?>
        <form method="post" action="invoice-action.php" class="form-actions form-actions--end" style="margin-top:0.75rem;" onsubmit="return confirm('Replace all lines with staff who actually checked in? Manual edits to lines will be lost.');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="reload_checked_in">
            <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
            <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
            <button type="submit" class="btn btn--secondary">Reload from checked-in staff</button>
            <span class="form-hint" style="margin:0;">Syncs with venue check-ins only. Removes no-shows; clears all lines when nobody has signed in.</span>
        </form>

        <?php
        $invoiceStatus = (string) ($invoice['status'] ?? 'draft');
        $canDelete     = in_array($invoiceStatus, ['draft', 'void'], true);
        ?>
        <div class="form-actions" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--admin-border);display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;">
            <?php if ($invoiceStatus !== 'void'): ?>
                <form method="post" action="invoice-action.php" style="margin:0;" onsubmit="return confirm('Mark this commission invoice as void? It will be hidden from normal lists but still linked to this event.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="void">
                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                    <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                    <button type="submit" class="btn btn--small btn--danger">Void invoice</button>
                </form>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <form method="post" action="invoice-action.php" style="margin:0;" onsubmit="return confirm('Permanently delete this commission invoice? This cannot be undone. You can create a fresh invoice for this event afterwards.');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                    <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                    <button type="submit" class="btn btn--small btn--danger">Delete invoice</button>
                </form>
                <span class="form-hint" style="margin:0;">Delete removes the invoice completely so you can start again for this event.</span>
            <?php else: ?>
                <span class="form-hint" style="margin:0;">Sent or paid invoices cannot be deleted — use Void to hide from lists.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php endif; ?>
</section>

<?php if ($event && $lines !== []): ?>
<?php
$invoiceFormJsPath = dirname(__DIR__) . '/assets/js/invoice-form.js';
$invoiceFormJsVer  = is_file($invoiceFormJsPath) ? (string) filemtime($invoiceFormJsPath) : '1';
?>
<script src="<?= h($assetBase) ?>assets/js/invoice-form.js?v=<?= h($invoiceFormJsVer) ?>"></script>
<script>
(function () {
    'use strict';
    var form = document.getElementById('commission-invoice-form');
    var table = document.getElementById('invoice-lines-table');
    var hint = document.getElementById('invoice-fixed-toolbar-hint');
    if (!form || !table) {
        return;
    }

    function rowChecks() {
        return table.querySelectorAll('.invoice-line__override');
    }

    function setAll(checked) {
        var count = 0;
        rowChecks().forEach(function (cb) {
            cb.checked = checked;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
            count += 1;
        });
        var head = document.getElementById('invoice-fixed-select-all-head');
        if (head) {
            head.checked = checked;
            head.indeterminate = false;
        }
        if (hint) {
            hint.textContent = checked
                ? ('Fixed selected on ' + count + ' row' + (count === 1 ? '' : 's') + '.')
                : ('Fixed cleared on ' + count + ' row' + (count === 1 ? '' : 's') + ' — amounts recalculate from hours × rate.');
        }
        form.dispatchEvent(new CustomEvent('invoice-fixed-bulk-change'));
    }

    form.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.id) {
            return;
        }
        if (t.id === 'invoice-fixed-select-all') {
            e.preventDefault();
            setAll(true);
        } else if (t.id === 'invoice-fixed-deselect-all') {
            e.preventDefault();
            setAll(false);
        }
    });

    var head = document.getElementById('invoice-fixed-select-all-head');
    if (head) {
        head.addEventListener('change', function () {
            setAll(head.checked);
        });
    }
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
