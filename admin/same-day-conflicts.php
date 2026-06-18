<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/date-format.php';

requireAdminCapability('events');

$pdo      = getDB();
$flash    = getAdminFlash();
$fromDate = trim((string) ($_GET['from'] ?? ''));
if ($fromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = '';
}

$conflicts = getAllSameDayDoubleBookings($pdo, $fromDate !== '' ? $fromDate : null);
$people    = count(array_unique(array_column($conflicts, 'email')));
$rows      = count($conflicts);
$toCancel  = 0;
foreach ($conflicts as $row) {
    $toCancel += max(0, count($row['registrations'] ?? []) - 1);
}

$pageTitle  = 'Same-day double bookings';
$activePage = 'events';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Same-day double bookings</h2>
            <p class="card__subtitle">
                Staff with 2+ shifts on one calendar date. Use <strong>Cancel duplicate shifts</strong> to reject later picks and keep the earliest registration for each day.
            </p>
        </div>
        <div class="toolbar">
            <?php if ($toCancel > 0): ?>
            <form method="post" action="same-day-conflicts-action.php" class="inline-form" onsubmit="return confirm('Reject <?= (int) $toCancel ?> later shift(s) and keep the first pick for each day? This cannot be undone easily.');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <?php if ($fromDate !== ''): ?>
                    <input type="hidden" name="from" value="<?= h($fromDate) ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn--primary">Cancel duplicate shifts (<?= (int) $toCancel ?>)</button>
            </form>
            <?php endif; ?>
            <a href="events.php" class="btn btn--secondary">Back to events</a>
        </div>
    </div>

    <form method="get" class="toolbar" style="margin-bottom:1rem">
        <label class="form-field" style="margin:0">
            <span class="form-label">From date</span>
            <input type="date" name="from" class="form-input" value="<?= h($fromDate) ?>">
        </label>
        <button type="submit" class="btn btn--secondary">Filter</button>
        <?php if ($fromDate !== ''): ?>
            <a href="same-day-conflicts.php" class="btn btn--ghost">Clear filter</a>
        <?php endif; ?>
    </form>

    <p>
        <strong><?= (int) $people ?></strong> staff ·
        <strong><?= (int) $rows ?></strong> same-day conflict(s) ·
        <strong><?= (int) $toCancel ?></strong> shift(s) would be cancelled
        <?php if ($fromDate !== ''): ?>
            · from <?= h(formatEventDateLabel($fromDate)) ?>
        <?php else: ?>
            · all dates
        <?php endif; ?>
    </p>

    <?php if ($conflicts === []): ?>
        <p class="data-table__empty">No same-day double bookings found<?= $fromDate !== '' ? ' for this date range' : '' ?>.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Staff</th>
                        <th>Email</th>
                        <th>Shifts that day</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($conflicts as $row): ?>
                    <tr>
                        <td><?= h(formatEventDateLabel((string) ($row['event_day'] ?? ''))) ?></td>
                        <td><?= h(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''))) ?></td>
                        <td><?= h((string) ($row['email'] ?? '')) ?></td>
                        <td>
                            <ul style="margin:0;padding-left:1.1rem">
                            <?php foreach ($row['registrations'] as $reg): ?>
                                <li>
                                    <?php if (!empty($reg['keep'])): ?>
                                        <strong>Keep</strong> —
                                    <?php else: ?>
                                        <span style="color:var(--color-danger,#c00)">Cancel</span> —
                                    <?php endif; ?>
                                    <?= h((string) ($reg['event_name'] ?? '')) ?>
                                    <span class="form-hint">#<?= (int) ($reg['registration_id'] ?? 0) ?> · <?= h((string) ($reg['status'] ?? '')) ?></span>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        </td>
                        <td class="table-actions">
                            <?php foreach ($row['registrations'] as $reg): ?>
                                <a class="btn btn--small btn--secondary" href="staff.php?event_id=<?= (int) ($reg['event_id'] ?? 0) ?>">Queue #<?= (int) ($reg['registration_id'] ?? 0) ?></a>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
