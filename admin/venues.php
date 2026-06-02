<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/venues-repository.php';

requireAdminCapability('events');

$pdo   = getDB();
$venues = getAllVenues($pdo);
$flash  = getAdminFlash();

$pageTitle  = 'Venues';
$activePage = 'venues';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Venues</h2>
            <p class="card__subtitle">Link events and shifts to venues. Registration forms show venue first, then relevant postings.</p>
        </div>
        <a href="venue-form.php" class="btn btn--primary">+ Add venue</a>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Venue</th>
                    <th>Type</th>
                    <th>Address</th>
                    <th>Events</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($venues === []): ?>
                    <tr>
                        <td colspan="6" class="data-table__empty">No venues yet. Add venues before linking events.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($venues as $venue): ?>
                        <tr>
                            <td><?= h($venue['name']) ?></td>
                            <td><?= h(formatVenueTypeLabel((string) ($venue['venue_type'] ?? 'other'))) ?></td>
                            <td><?= h((string) ($venue['address'] ?? '—')) ?></td>
                            <td><?= (int) ($venue['event_count'] ?? 0) ?></td>
                            <td>
                                <?php if ((int) ($venue['is_active'] ?? 0) === 1): ?>
                                    <span class="badge badge--approved">Active</span>
                                <?php else: ?>
                                    <span class="badge badge--rejected">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="data-table__actions">
                                <a href="venue-form.php?id=<?= (int) $venue['id'] ?>" class="btn btn--secondary btn--sm">Edit</a>
                                <?php if ((int) ($venue['is_active'] ?? 0) === 1): ?>
                                    <form method="post" action="venue-action.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $venue['id'] ?>">
                                        <input type="hidden" name="action" value="deactivate">
                                        <button type="submit" class="btn btn--secondary btn--sm">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="venue-action.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $venue['id'] ?>">
                                        <input type="hidden" name="action" value="activate">
                                        <button type="submit" class="btn btn--secondary btn--sm">Activate</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
