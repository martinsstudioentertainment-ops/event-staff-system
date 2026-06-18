<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/automation/automation-schema.php';
require_once __DIR__ . '/../includes/automation/roster-repository.php';
require_once __DIR__ . '/../includes/staff-allocation.php';

requireAdminCapability('events');

$pdo     = getDB();
$flash   = getAdminFlash();
auto_ensure_schema($pdo);
auto_ensure_phase67_schema($pdo);

$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to'   => trim((string) ($_GET['date_to'] ?? '')),
    'venue'     => trim((string) ($_GET['venue'] ?? '')),
    'role'      => trim((string) ($_GET['role'] ?? '')),
    'event_id'  => (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0),
];
$eventId = (int) $filters['event_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'auto_fill' && $eventId > 0) {
        $n = roster_auto_fill($pdo, $eventId);
        setAdminFlash('success', "Auto-filled {$n} assignment(s).");
    } elseif ($action === 'assign' && $eventId > 0) {
        $result = roster_assign_staff_safe($pdo, (int) ($_POST['slot_id'] ?? 0), (int) ($_POST['registration_id'] ?? 0));
        if ($result['ok']) {
            setAdminFlash('success', 'Staff assigned.');
        } else {
            setAdminFlash('error', 'Assignment blocked: double-booking or conflict detected.');
        }
    } elseif ($action === 'unassign') {
        roster_unassign($pdo, (int) ($_POST['assignment_id'] ?? 0));
        setAdminFlash('success', 'Assignment removed.');
    } elseif ($action === 'move') {
        roster_move_assignment($pdo, (int) ($_POST['assignment_id'] ?? 0), (int) ($_POST['target_slot_id'] ?? 0));
        setAdminFlash('success', 'Assignment moved.');
    }
    header('Location: event-rostering.php?event_id=' . $eventId);
    exit;
}

$events = roster_get_events_filtered($pdo, $filters, 80);
if ($eventId > 0) {
    roster_ensure_default_slots($pdo, $eventId);
}
$slots       = $eventId > 0 ? roster_get_slots($pdo, $eventId) : [];
if ($filters['role'] !== '' && $slots !== []) {
    $roleNeedle = strtolower($filters['role']);
    $slots = array_values(array_filter($slots, static fn ($s) => str_contains(strtolower((string) ($s['role_name'] ?? '')), $roleNeedle)));
}
$assignments = $eventId > 0 ? roster_get_assignments($pdo, $eventId) : [];
$pool        = $eventId > 0 ? roster_available_staff($pdo, $eventId) : [];
$coverage    = $eventId > 0 ? roster_coverage_summary($pdo, $eventId) : ['required' => 0, 'assigned' => 0, 'confirmed' => 0, 'checked_in' => 0, 'gap' => 0];
$conflicts   = [];
if ($eventId > 0) {
    foreach ($assignments as $a) {
        $c = roster_detect_conflicts($pdo, $eventId, (int) ($a['registration_id'] ?? 0));
        if ($c !== []) {
            $conflicts[(int) ($a['registration_id'] ?? 0)] = $c;
        }
    }
}

$assignedRegIds = array_map(static fn ($a) => (int) ($a['registration_id'] ?? 0), $assignments);
$poolUnassigned = array_values(array_filter($pool, static fn ($p) => !in_array((int) ($p['registration_id'] ?? 0), $assignedRegIds, true)));

$bySlot = [];
foreach ($assignments as $a) {
    $bySlot[(int) ($a['slot_id'] ?? 0)][] = $a;
}

$coveragePct = ($coverage['required'] ?? 0) > 0
    ? min(100, (int) round((($coverage['assigned'] ?? 0) / max(1, $coverage['required'])) * 100))
    : 100;

ensureStaffAllocationSchema($pdo);
$assignmentHistory = $eventId > 0 ? getEventAssignmentHistory($pdo, $eventId, 50) : [];

$pageTitle  = 'Event Rostering Centre';
$activePage = 'event-rostering';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/automation-suite.css">

<?php if ($flash): ?><div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Event Rostering Centre</h1>
        <p class="wf-hero__subtitle">Drag staff to shift slots, auto-fill vacancies, role allocation, and coverage visualization.</p>
    </div>
    <form method="get" class="wf-filters">
        <div><label>Event</label>
        <select name="event_id" class="input">
            <option value="">Select event…</option>
            <?php foreach ($events as $ev): ?>
                <option value="<?= (int) $ev['id'] ?>" <?= $eventId === (int) $ev['id'] ? 'selected' : '' ?>>
                    <?= h($ev['name'] ?? '') ?> — <?= h(formatSystemDate((string) ($ev['event_date'] ?? ''), $pdo)) ?>
                </option>
            <?php endforeach; ?>
        </select></div>
        <div><label>From</label><input type="date" name="date_from" value="<?= h($filters['date_from']) ?>" class="input"></div>
        <div><label>To</label><input type="date" name="date_to" value="<?= h($filters['date_to']) ?>" class="input"></div>
        <div><label>Venue</label><input type="text" name="venue" value="<?= h($filters['venue']) ?>" class="input" placeholder="Location filter"></div>
        <div><label>Role</label><input type="text" name="role" value="<?= h($filters['role']) ?>" class="input" placeholder="Filter slots"></div>
        <div style="align-self:end;"><button type="submit" class="btn btn--primary">Apply</button></div>
    </form>
</div>

<?php if ($eventId > 0): ?>
<div class="wf-grid">
    <div class="wf-metric"><div class="wf-metric__value"><?= (int) $coverage['required'] ?></div><div class="wf-metric__label">Required staff</div></div>
    <div class="wf-metric"><div class="wf-metric__value"><?= (int) $coverage['assigned'] ?></div><div class="wf-metric__label">Assigned staff</div></div>
    <div class="wf-metric wf-metric--green"><div class="wf-metric__value"><?= (int) $coverage['confirmed'] ?></div><div class="wf-metric__label">Confirmed staff</div></div>
    <div class="wf-metric"><div class="wf-metric__value"><?= (int) $coverage['checked_in'] ?></div><div class="wf-metric__label">Checked in</div></div>
    <div class="wf-metric wf-metric--<?= ($coverage['gap'] ?? 0) > 0 ? 'red' : 'green' ?>"><div class="wf-metric__value"><?= (int) $coverage['gap'] ?></div><div class="wf-metric__label">Remaining gap</div></div>
</div>
<div class="auto-coverage-bar"><div class="auto-coverage-bar__fill" style="width:<?= $coveragePct ?>%"></div></div>

<?php if ($conflicts !== []): ?>
<div class="alert alert--error alert--visible" style="margin-top:1rem;">
    <strong>Scheduling conflicts detected:</strong>
    <?php foreach ($conflicts as $regId => $items): ?>
        Registration #<?= (int) $regId ?> double-booked on <?= h((string) ($items[0]['event_date'] ?? '')) ?> (<?= count($items) ?> other event(s)).
    <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="card erp-card" style="margin-top:1rem;">
    <div class="card__header card__header--row">
        <h2 class="card__title">Staff pool</h2>
        <form method="post"><?= csrfField() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="auto_fill"><button type="submit" class="btn btn--primary">Auto-fill vacancies</button></form>
    </div>
    <div class="auto-roster-pool" id="roster-pool">
        <?php foreach ($poolUnassigned as $p): ?>
            <div class="auto-roster-card" draggable="true" data-registration-id="<?= (int) ($p['registration_id'] ?? 0) ?>">
                <?= h(trim(($p['first_name'] ?? '') . ' ' . ($p['surname'] ?? ''))) ?>
                <span class="text-muted"> · <?= h((string) ($p['staff_role'] ?? '')) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if ($poolUnassigned === []): ?><span class="text-muted">All approved staff are assigned.</span><?php endif; ?>
    </div>

    <div class="auto-roster-board">
        <?php foreach ($slots as $slot): ?>
            <?php $sid = (int) ($slot['id'] ?? 0); ?>
            <div class="auto-roster-column">
                <div class="auto-roster-column__title">
                    <?= h((string) ($slot['role_name'] ?? 'Role')) ?>
                    <?php if (($slot['shift_label'] ?? '') !== ''): ?> · <?= h((string) $slot['shift_label']) ?><?php endif; ?>
                    (<?= count($bySlot[$sid] ?? []) ?>/<?= (int) ($slot['slots_needed'] ?? 1) ?>)
                </div>
                <div class="auto-roster-slot" data-slot-id="<?= $sid ?>">
                    <?php foreach ($bySlot[$sid] ?? [] as $a): ?>
                        <div class="auto-roster-card" draggable="true" data-assignment-id="<?= (int) ($a['id'] ?? 0) ?>" data-registration-id="<?= (int) ($a['registration_id'] ?? 0) ?>"<?= isset($conflicts[(int) ($a['registration_id'] ?? 0)]) ? ' style="border-color:var(--color-danger,#e55);"' : '' ?>>
                            <?= h(trim(($a['first_name'] ?? '') . ' ' . ($a['surname'] ?? ''))) ?>
                            <form method="post" style="display:inline;margin-left:0.35rem;"><?= csrfField() ?><input type="hidden" name="action" value="unassign"><input type="hidden" name="assignment_id" value="<?= (int) ($a['id'] ?? 0) ?>"><button type="submit" class="btn btn--secondary" style="padding:0.1rem 0.35rem;font-size:0.65rem;">×</button></form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<form id="roster-assign-form" method="post" style="display:none;"><?= csrfField() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="slot_id" id="roster-slot-id"><input type="hidden" name="registration_id" id="roster-reg-id"></form>
<form id="roster-move-form" method="post" style="display:none;"><?= csrfField() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="move"><input type="hidden" name="assignment_id" id="roster-move-aid"><input type="hidden" name="target_slot_id" id="roster-move-slot"></form>

<script>
(function () {
    var dragReg = null, dragAid = null;
    document.querySelectorAll('.auto-roster-card[draggable]').forEach(function (el) {
        el.addEventListener('dragstart', function (e) {
            dragReg = el.getAttribute('data-registration-id');
            dragAid = el.getAttribute('data-assignment-id');
            e.dataTransfer.setData('text/plain', dragReg || dragAid || '');
        });
    });
    document.querySelectorAll('.auto-roster-slot').forEach(function (slot) {
        slot.addEventListener('dragover', function (e) { e.preventDefault(); slot.classList.add('is-over'); });
        slot.addEventListener('dragleave', function () { slot.classList.remove('is-over'); });
        slot.addEventListener('drop', function (e) {
            e.preventDefault();
            slot.classList.remove('is-over');
            var slotId = slot.getAttribute('data-slot-id');
            if (dragAid) {
                document.getElementById('roster-move-aid').value = dragAid;
                document.getElementById('roster-move-slot').value = slotId;
                document.getElementById('roster-move-form').submit();
            } else if (dragReg) {
                document.getElementById('roster-slot-id').value = slotId;
                document.getElementById('roster-reg-id').value = dragReg;
                document.getElementById('roster-assign-form').submit();
            }
        });
    });
})();
</script>

<?php if ($assignmentHistory !== []): ?>
<section class="card erp-card" style="margin-top:1rem;">
    <div class="card__header">
        <h2 class="card__title">Assignment history</h2>
        <p class="card__subtitle">Admin allocation and override audit trail for this event</p>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Action</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Admin</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignmentHistory as $log): ?>
                    <tr>
                        <td><?= h(formatSystemDateTime((string) ($log['created_at'] ?? ''), $pdo)) ?></td>
                        <td><?= h(str_replace('_', ' ', (string) ($log['action'] ?? ''))) ?></td>
                        <td><?= h((string) ($log['from_event_name'] ?? '—')) ?></td>
                        <td><?= h((string) ($log['to_event_name'] ?? '—')) ?></td>
                        <td><?= h((string) ($log['admin_username'] ?? '')) ?></td>
                        <td><?= h((string) ($log['reason'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php else: ?>
<section class="card erp-card"><p class="card__subtitle">Select an upcoming event to manage rostering.</p></section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
