<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/venues-repository.php';

requireAdminCapability('events');

$pdo    = getDB();
$events = getAllEvents($pdo);
$flash  = getAdminFlash();

$pageTitle  = 'Event Management';
$activePage = 'events';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Events</h2>
            <p class="card__subtitle"><?= count($events) ?> event(s) — active events appear on the registration form.</p>
        </div>
        <a href="event-form.php" class="btn btn--primary">+ Add Event</a>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Event Name</th>
                    <th>Main security</th>
                    <th>Date</th>
                    <th>Venue</th>
                    <th>Work type</th>
                    <th>Location</th>
                    <th>Staff needed</th>
                    <th>Google Sheet</th>
                    <th>Status</th>
                    <th>Registrations</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($events === []): ?>
                    <tr>
                        <td colspan="11" class="data-table__empty">No events yet. Add your first event.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= h($event['name']) ?></td>
                            <td><?= h(formatEventMainSecurityLabel($event) !== '' ? formatEventMainSecurityLabel($event) : '—') ?></td>
                            <td><?= h(formatEventDateLabel($event['event_date'])) ?></td>
                            <td><?= h((string) ($event['venue_name'] ?? '—')) ?></td>
                            <td><?= h(formatWorkTypeLabel((string) ($event['work_type'] ?? 'special_event'))) ?></td>
                            <td><?= h(formatEventLocationLabel($event)) ?></td>
                            <td><?= isset($event['staff_needed']) && $event['staff_needed'] !== null && $event['staff_needed'] !== '' ? (int) $event['staff_needed'] : '—' ?></td>
                            <td>
                                <?php if (trim((string) ($event['google_sheet_url'] ?? '')) !== ''): ?>
                                    <span class="badge badge--approved" title="Syncs on registration">Linked</span>
                                <?php else: ?>
                                    <span class="form-hint">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $event['is_active'] === 1): ?>
                                    <span class="badge badge--approved">Active</span>
                                <?php else: ?>
                                    <span class="badge badge--rejected">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $event['registration_count'] ?></td>
                            <td>
                                <div class="action-group">
                                    <a href="event-sign-qr.php?id=<?= (int) $event['id'] ?>" class="btn btn--small btn--primary">Sign-in</a>
                                    <a href="event-form.php?id=<?= (int) $event['id'] ?>" class="btn btn--small btn--secondary">Edit</a>
                                    <form method="post" action="toggle-event.php">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">
                                        <input type="hidden" name="active" value="<?= (int) $event['is_active'] === 1 ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn--small btn--secondary">
                                            <?= (int) $event['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
