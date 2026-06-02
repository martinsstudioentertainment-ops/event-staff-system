<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdminCapability('invoices');

$pageTitle  = 'Import commission invoice';
$activePage = 'invoices';
$flash      = getAdminFlash();

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Import from spreadsheet</h2>
            <p class="card__subtitle">Build a commission invoice from Excel or Google Sheets — no sign-ins required.</p>
        </div>
        <a href="invoices.php" class="btn btn--secondary">← All invoices</a>
        <a href="invoice-quick.php" class="btn btn--secondary">Quick invoice (print only)</a>
    </div>

    <div class="alert alert--info alert--visible">
        <strong>Excel users:</strong> prepare your sheet, then <strong>File → Save As → CSV (Comma delimited) (*.csv)</strong> and upload that file here.
    </div>

    <h3 class="card__subtitle" style="margin-top:0;">Choose a template</h3>
    <div class="toolbar toolbar--compact" style="margin-bottom:1.25rem;">
        <a href="invoice-import-template.php?mode=summary" class="btn btn--secondary">Download summary template</a>
        <a href="invoice-import-template.php?mode=detailed" class="btn btn--secondary">Download detailed template</a>
    </div>

    <div class="erp-settings-form__grid" style="margin-bottom:1.5rem;">
        <article class="stat-card">
            <p class="stat-card__label">Summary template</p>
            <p class="form-hint" style="margin:0.5rem 0 0;">One row — <strong>no staff names</strong> on the printed client invoice. Use: event, client, staff count, hours per lad, rate.</p>
        </article>
        <article class="stat-card">
            <p class="stat-card__label">Detailed template</p>
            <p class="form-hint" style="margin:0.5rem 0 0;">One row per lad with name, role, hours, and rate. Use when the client expects a full breakdown.</p>
        </article>
    </div>

    <form method="post" action="invoice-import-action.php" enctype="multipart/form-data" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="spreadsheet">Upload CSV</label>
            <input class="form-input" type="file" id="spreadsheet" name="spreadsheet" accept=".csv,text/csv" required>
            <p class="form-hint">Max one invoice per event. If the event does not exist yet, it will be created from the spreadsheet.</p>
        </div>

        <div class="form-actions form-actions--end">
            <button type="submit" class="btn btn--primary">Import and create invoice</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Do I need every name on the client invoice?</h2>
    </div>
    <p class="form-hint" style="margin:0;">
        <strong>Usually no.</strong> Most clients only need the event, date, total staff, total hours, rate, and amount due.
        Keep the full lad list in the admin (Work hours / detailed export) for your records.
        On each invoice, set <strong>Print layout → Summary</strong> to hide names on the printed PDF.
    </p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
