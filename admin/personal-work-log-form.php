<?php



require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/job-record-repository.php';

require_once __DIR__ . '/../includes/system-settings.php';



requireAdminCapability('invoices');



if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {

    setAdminFlash('error', 'Only administrators and managers can edit jobs.');

    header('Location: personal-invoices.php');

    exit;

}



$pdo      = getDB();

$id       = (int) ($_GET['id'] ?? 0);

$record   = $id > 0 ? getJobRecordById($pdo, $id) : null;

$flash    = getAdminFlash();

$currency = getSystemCurrency($pdo);



if ($id > 0 && $record === null) {

    setAdminFlash('error', 'Job not found.');

    header('Location: personal-invoices.php#work-logs');

    exit;

}



if ($record !== null && !isPersonalWorkLog($record)) {

    if (isPersonalInvoiceRecord($record)) {

        header('Location: personal-invoice-form.php?id=' . $id);

    } else {

        header('Location: job-record-form.php?id=' . $id);

    }

    exit;

}



$defaults = [

    'client_name'  => $record['client_name'] ?? '',

    'event_name'   => $record['event_name'] ?? '',

    'event_date'   => $record['event_date'] ?? '',

    'venue'        => $record['venue'] ?? '',

    'hours_worked' => $record['hours_per_staff'] ?? $record['total_hours'] ?? 8,

    'hourly_rate'  => $record['hourly_rate'] ?? 1,

    'notes'        => $record['notes'] ?? '',

];



$pageTitle  = $record ? 'Edit saved job' : 'Log a job';

$activePage = 'personal-invoices';



include __DIR__ . '/../includes/admin/layout-top.php';

?>



<?php if ($flash): ?>

    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>

<?php endif; ?>



<section class="card">

    <div class="card__header card__header--row">

        <div>

            <h2 class="card__title"><?= $record ? 'Edit saved job' : 'Log a job' ?></h2>

            <p class="card__subtitle">Save work you did for later — pick multiple saved jobs when you create an invoice.</p>

        </div>

        <a href="personal-invoices.php#work-logs" class="btn btn--secondary">← Saved jobs</a>

    </div>



    <form method="post" action="job-record-action.php" class="erp-settings-form" id="personal-work-log-form" data-currency="<?= h($currency) ?>">

        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <input type="hidden" name="invoice_type" value="personal">

        <input type="hidden" name="save_as" value="work_log">

        <?php if ($id > 0): ?>

            <input type="hidden" name="id" value="<?= $id ?>">

        <?php endif; ?>



        <div class="erp-settings-form__grid">

            <div class="form-group">

                <label class="form-label" for="client_name">Client (optional)</label>

                <input class="form-input" type="text" id="client_name" name="client_name" value="<?= h((string) $defaults['client_name']) ?>" placeholder="Who the work was for">

            </div>

            <div class="form-group form-group--full">

                <label class="form-label form-label--required" for="event_name">Job description</label>

                <input class="form-input" type="text" id="event_name" name="event_name" value="<?= h((string) $defaults['event_name']) ?>" placeholder="e.g. Site supervision — Aviva Stadium" required>

            </div>

            <div class="form-group">

                <label class="form-label" for="event_date">Job date</label>

                <input class="form-input" type="date" id="event_date" name="event_date" value="<?= h((string) $defaults['event_date']) ?>">

            </div>

            <div class="form-group">

                <label class="form-label" for="venue">Location</label>

                <input class="form-input" type="text" id="venue" name="venue" value="<?= h((string) $defaults['venue']) ?>" placeholder="e.g. Dublin">

            </div>

            <div class="form-group">

                <label class="form-label form-label--required" for="hours_worked">Hours worked</label>

                <input class="form-input work-log-calc" type="number" min="0.25" step="0.25" id="hours_worked" name="hours_worked" value="<?= h((string) $defaults['hours_worked']) ?>" required>

            </div>

            <div class="form-group">

                <label class="form-label form-label--required" for="hourly_rate">Rate / hour (<?= h($currency) ?>)</label>

                <input class="form-input work-log-calc" type="number" min="0" step="0.01" id="hourly_rate" name="hourly_rate" value="<?= h((string) $defaults['hourly_rate']) ?>" required>

            </div>

            <div class="form-group form-group--full">

                <div class="stat-card stat-card--highlight" style="margin:0">

                    <p class="stat-card__value" id="work-log-total-value">—</p>

                    <p class="stat-card__label">Estimated line total</p>

                </div>

            </div>

            <div class="form-group form-group--full">

                <label class="form-label" for="notes">Notes</label>

                <textarea class="form-textarea" id="notes" name="notes" rows="2"><?= h((string) $defaults['notes']) ?></textarea>

            </div>

        </div>



        <div class="form-actions form-actions--end">

            <button type="submit" class="btn btn--primary">Save job</button>

        </div>

    </form>



    <?php if ($id > 0): ?>

        <form method="post" action="job-record-action.php" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--admin-border)" onsubmit="return confirm('Delete this saved job?');">

            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

            <input type="hidden" name="action" value="void">

            <input type="hidden" name="id" value="<?= $id ?>">

            <button type="submit" class="btn btn--small btn--danger">Delete job</button>

        </form>

    <?php endif; ?>

</section>



<script>

(function () {

    var form = document.getElementById('personal-work-log-form');

    if (!form) return;

    var hoursEl = document.getElementById('hours_worked');

    var rateEl = document.getElementById('hourly_rate');

    var totalEl = document.getElementById('work-log-total-value');

    var currency = (form.getAttribute('data-currency') || 'EUR').toUpperCase();

    function formatMoney(n) {

        try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format(n); }

        catch (e) { return currency + ' ' + n.toFixed(2); }

    }

    function refresh() {

        var hours = Math.max(0, parseFloat(hoursEl.value) || 0);

        var rate = Math.max(0, parseFloat(rateEl.value) || 0);

        totalEl.textContent = formatMoney(Math.round(hours * rate * 100) / 100);

    }

    form.querySelectorAll('.work-log-calc').forEach(function (el) {

        el.addEventListener('input', refresh);

        el.addEventListener('change', refresh);

    });

    refresh();

})();

</script>



<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>

