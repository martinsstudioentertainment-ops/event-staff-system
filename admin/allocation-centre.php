<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-allocation.php';
require_once __DIR__ . '/../includes/events-repository.php';

requireAdminCapability('staff');

$pdo      = getDB();
ensureStaffAllocationSchema($pdo);

$eventFilter = (int) ($_GET['event_id'] ?? 0);
$q           = trim((string) ($_GET['q'] ?? ''));
$flash       = getAdminFlash();

$eventRows = getAllocationCentreEventRows($pdo, $eventFilter > 0 ? $eventFilter : null, 150);
$waitlist  = getWaitingListEntries($pdo, [
    'event_id' => $eventFilter,
    'q'        => $q,
], 150);
$pendingRegistrations = getPendingRegistrationsForAllocation(
    $pdo,
    $eventFilter > 0 ? $eventFilter : null,
    150
);

try {
    $eventOptions = $pdo->query(
        "SELECT id, name, event_date FROM events WHERE is_active = 1 ORDER BY event_date ASC, name ASC LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $eventOptions = [];
}

$searchResults = $q !== '' ? searchStaffForAllocation($pdo, $q, 20) : [];

$pageTitle  = 'Allocation Centre';
$activePage = 'allocation-centre';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Allocation Centre</h1>
        <p class="wf-hero__subtitle">Manual shift assignment, waiting list, and capacity overview. All actions are audit logged.</p>
    </div>
</div>

<section class="card erp-card">
    <h2 class="card__title">Search staff</h2>
    <form method="get" class="form-row" style="gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
        <?php if ($eventFilter > 0): ?>
            <input type="hidden" name="event_id" value="<?= (int) $eventFilter ?>">
        <?php endif; ?>
        <div style="flex:1;min-width:220px;">
            <label for="q">Name, email, phone, PPS, PSA, or staff ID</label>
            <input id="q" name="q" class="input" value="<?= h($q) ?>" placeholder="Search…">
        </div>
        <button type="submit" class="btn btn--primary">Search</button>
    </form>
    <?php if ($searchResults !== []): ?>
        <div class="table-wrap" style="margin-top:1rem;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Mobile</th>
                        <th>Role</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($searchResults as $staff): ?>
                    <tr>
                        <td><?= (int) ($staff['id'] ?? 0) ?></td>
                        <td><?= h(trim((string) ($staff['first_name'] ?? '') . ' ' . (string) ($staff['surname'] ?? ''))) ?></td>
                        <td><?= h((string) ($staff['email'] ?? '')) ?></td>
                        <td><?= h((string) ($staff['mobile'] ?? '')) ?></td>
                        <td><?= h(formatRoleLabel((string) ($staff['staff_role'] ?? ''))) ?></td>
                        <td>
                            <button type="button" class="btn btn--small btn--secondary js-assign-open"
                                    data-staff-id="<?= (int) ($staff['id'] ?? 0) ?>"
                                    data-staff-label="<?= h(trim((string) ($staff['first_name'] ?? '') . ' ' . (string) ($staff['surname'] ?? ''))) ?>">
                                Assign shift
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($q !== ''): ?>
        <p class="form-hint" style="margin-top:0.75rem;">No staff matched that search.</p>
    <?php endif; ?>
</section>

<section class="card erp-card">
    <form method="get" class="form-row" style="gap:0.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem;">
        <div>
            <label for="event_id">Filter by event</label>
            <select id="event_id" name="event_id" class="input" onchange="this.form.submit()">
                <option value="">All events</option>
                <?php foreach ($eventOptions as $opt): ?>
                    <option value="<?= (int) ($opt['id'] ?? 0) ?>" <?= $eventFilter === (int) ($opt['id'] ?? 0) ? 'selected' : '' ?>>
                        <?= h((string) ($opt['name'] ?? '')) ?> — <?= h(formatSystemDate((string) ($opt['event_date'] ?? ''), $pdo)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($q !== ''): ?>
            <input type="hidden" name="q" value="<?= h($q) ?>">
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Needed</th>
                    <th>Filled</th>
                    <th>Remaining</th>
                    <th>Waiting list</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($eventRows === []): ?>
                <tr><td colspan="7" class="data-table__empty">No events found.</td></tr>
            <?php else: ?>
                <?php foreach ($eventRows as $row): ?>
                    <tr>
                        <td><strong><?= h((string) ($row['event_name'] ?? '')) ?></strong></td>
                        <td><?= h(formatSystemDate((string) ($row['event_date'] ?? ''), $pdo)) ?></td>
                        <td><?= $row['needed'] === null ? '—' : (int) $row['needed'] ?></td>
                        <td><?= (int) ($row['filled'] ?? 0) ?></td>
                        <td><?= $row['remaining'] === null ? '—' : (int) $row['remaining'] ?></td>
                        <td><?= (int) ($row['waitlist_count'] ?? 0) ?></td>
                        <td>
                            <a class="btn btn--small btn--secondary" href="staff.php?event_id=<?= (int) ($row['event_id'] ?? 0) ?>">Roster</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card erp-card">
    <h2 class="card__title">Pending registrations</h2>
    <?php if ($pendingRegistrations === []): ?>
        <p class="form-hint">No pending registrations<?= $eventFilter > 0 ? ' for this event' : '' ?>.</p>
    <?php else: ?>
        <form method="post" action="allocation-action.php" id="pending-bulk-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="bulk_approve">
            <?php if ($eventFilter > 0): ?>
                <input type="hidden" name="return_event_id" value="<?= (int) $eventFilter ?>">
            <?php endif; ?>
            <div class="form-row" style="gap:0.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem;">
                <div style="flex:1;min-width:200px;">
                    <label for="approve_reason">Reason (optional, for audit log)</label>
                    <input id="approve_reason" name="reason" class="input" placeholder="Bulk approve from allocation centre">
                </div>
                <button type="submit" class="btn btn--primary">Bulk approve selected</button>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="pending-select-all" aria-label="Select all"></th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Event</th>
                            <th>Registered</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingRegistrations as $reg): ?>
                        <tr>
                            <td><input type="checkbox" name="registration_ids[]" value="<?= (int) ($reg['id'] ?? 0) ?>"></td>
                            <td><?= h(trim((string) ($reg['first_name'] ?? '') . ' ' . (string) ($reg['surname'] ?? ''))) ?></td>
                            <td><?= h((string) ($reg['email'] ?? '')) ?></td>
                            <td><?= h((string) ($reg['event_name'] ?? '')) ?> — <?= h(formatSystemDate((string) ($reg['event_date'] ?? ''), $pdo)) ?></td>
                            <td><?= h(formatSystemDateTime((string) ($reg['created_at'] ?? ''), $pdo)) ?></td>
                            <td><a class="btn btn--small btn--secondary" href="view-staff.php?id=<?= (int) ($reg['id'] ?? 0) ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="card erp-card">
    <h2 class="card__title">Waiting list</h2>
    <?php if ($waitlist === []): ?>
        <p class="form-hint">No active waiting list entries<?= $eventFilter > 0 ? ' for this event' : '' ?>.</p>
    <?php else: ?>
        <form method="post" action="allocation-action.php" id="waitlist-bulk-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="bulk_allocate_waitlist">
            <div class="form-row" style="gap:0.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem;">
                <div>
                    <label for="bulk_event_id">Allocate selected to event</label>
                    <select id="bulk_event_id" name="event_id" class="input" required>
                        <option value="">Choose event…</option>
                        <?php foreach ($eventOptions as $opt): ?>
                            <option value="<?= (int) ($opt['id'] ?? 0) ?>" <?= $eventFilter === (int) ($opt['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= h((string) ($opt['name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1;min-width:200px;">
                    <label for="bulk_reason">Reason</label>
                    <input id="bulk_reason" name="reason" class="input" required placeholder="Why are these staff being allocated?">
                </div>
                <button type="submit" class="btn btn--primary">Bulk allocate</button>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="waitlist-select-all" aria-label="Select all"></th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Preferred event</th>
                            <th>Joined</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($waitlist as $entry): ?>
                        <tr>
                            <td><input type="checkbox" name="waitlist_ids[]" value="<?= (int) ($entry['id'] ?? 0) ?>"></td>
                            <td><?= h(trim((string) ($entry['first_name'] ?? '') . ' ' . (string) ($entry['surname'] ?? ''))) ?></td>
                            <td><?= h((string) ($entry['email'] ?? '')) ?></td>
                            <td><?= h(formatAllocationTypeLabel((string) ($entry['allocation_type'] ?? 'waiting_list'))) ?></td>
                            <td><?= h((string) ($entry['preferred_event_name'] ?? 'Any')) ?></td>
                            <td><?= h(formatSystemDateTime((string) ($entry['created_at'] ?? ''), $pdo)) ?></td>
                            <td>
                                <button type="button" class="btn btn--small btn--secondary js-waitlist-allocate"
                                        data-waitlist-id="<?= (int) ($entry['id'] ?? 0) ?>"
                                        data-label="<?= h(trim((string) ($entry['first_name'] ?? '') . ' ' . (string) ($entry['surname'] ?? ''))) ?>">
                                    Allocate
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="card erp-card" id="assign-panel" hidden>
    <h2 class="card__title">Assign shift</h2>
    <form method="post" action="allocation-action.php" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="staff_id" id="assign_staff_id" value="">
        <p class="form-hint" id="assign_staff_label"></p>
        <div>
            <label for="assign_event_id">Event / shift</label>
            <select id="assign_event_id" name="event_id" class="input" required>
                <option value="">Choose event…</option>
                <?php foreach ($eventOptions as $opt): ?>
                    <option value="<?= (int) ($opt['id'] ?? 0) ?>">
                        <?= h((string) ($opt['name'] ?? '')) ?> — <?= h(formatSystemDate((string) ($opt['event_date'] ?? ''), $pdo)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="assign_reason">Reason for override</label>
            <textarea id="assign_reason" name="reason" class="input" rows="3" required placeholder="Required for audit log"></textarea>
        </div>
        <label class="checkbox-label">
            <input type="checkbox" name="confirm_duplicate" value="1">
            Confirm if already registered on this event
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="confirm_same_day" value="1">
            Confirm same-day override (one shift per day rule)
        </label>
        <button type="submit" class="btn btn--primary">Assign</button>
    </form>
</section>

<section class="card erp-card" id="waitlist-allocate-panel" hidden>
    <h2 class="card__title">Allocate waiting list entry</h2>
    <form method="post" action="allocation-action.php" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="allocate_waitlist">
        <input type="hidden" name="waitlist_id" id="waitlist_allocate_id" value="">
        <p class="form-hint" id="waitlist_allocate_label"></p>
        <div>
            <label for="waitlist_allocate_event_id">Event / shift</label>
            <select id="waitlist_allocate_event_id" name="event_id" class="input" required>
                <option value="">Choose event…</option>
                <?php foreach ($eventOptions as $opt): ?>
                    <option value="<?= (int) ($opt['id'] ?? 0) ?>">
                        <?= h((string) ($opt['name'] ?? '')) ?> — <?= h(formatSystemDate((string) ($opt['event_date'] ?? ''), $pdo)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="waitlist_allocate_reason">Reason</label>
            <textarea id="waitlist_allocate_reason" name="reason" class="input" rows="3" required></textarea>
        </div>
        <button type="submit" class="btn btn--primary">Allocate to shift</button>
    </form>
</section>

<script>
(function () {
    document.querySelectorAll('.js-assign-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = document.getElementById('assign-panel');
            document.getElementById('assign_staff_id').value = btn.getAttribute('data-staff-id') || '';
            document.getElementById('assign_staff_label').textContent = 'Staff: ' + (btn.getAttribute('data-staff-label') || '');
            panel.hidden = false;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    document.querySelectorAll('.js-waitlist-allocate').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = document.getElementById('waitlist-allocate-panel');
            document.getElementById('waitlist_allocate_id').value = btn.getAttribute('data-waitlist-id') || '';
            document.getElementById('waitlist_allocate_label').textContent = btn.getAttribute('data-label') || '';
            panel.hidden = false;
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    var selectAll = document.getElementById('waitlist-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('#waitlist-bulk-form input[name="waitlist_ids[]"]').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
        });
    }
    var pendingAll = document.getElementById('pending-select-all');
    if (pendingAll) {
        pendingAll.addEventListener('change', function () {
            document.querySelectorAll('#pending-bulk-form input[name="registration_ids[]"]').forEach(function (cb) {
                cb.checked = pendingAll.checked;
            });
        });
    }
})();
</script>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
