<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

requireAdminCapability('events');

$pdo        = getDB();
$allVenues  = getAllVenues($pdo);
$total      = count($allVenues);
$page       = adminListPage();
$venues     = array_slice($allVenues, adminListOffset($page), adminListPerPage());
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
            <p class="card__subtitle">Click a venue card to open. Active venues show as verified-style status on the front.</p>
        </div>
        <a href="venue-form.php" class="btn btn--primary">+ Add venue</a>
    </div>

    <?php if ($venues === []): ?>
        <p class="form-hint">No venues yet. Add venues before linking events.</p>
    <?php else: ?>
        <div class="list-card-board">
            <?php foreach ($venues as $venue): ?>
                <?php $active = (int) ($venue['is_active'] ?? 0) === 1; ?>
                <details class="list-card list-card--<?= $active ? 'ok' : 'issue' ?>">
                    <summary class="list-card__front">
                        <span class="list-card__who">
                            <strong><?= h($venue['name']) ?></strong>
                            <span class="list-card__hint">Click to open</span>
                        </span>
                        <?php if ($active): ?>
                            <span class="badge badge--approved">Active</span>
                        <?php else: ?>
                            <span class="badge badge--rejected">Inactive</span>
                        <?php endif; ?>
                    </summary>
                    <div class="list-card__body">
                        <dl class="list-card__facts">
                            <div>
                                <dt>Type</dt>
                                <dd><?= h(formatVenueTypeLabel((string) ($venue['venue_type'] ?? 'other'))) ?></dd>
                            </div>
                            <div>
                                <dt>Address</dt>
                                <dd><?= h((string) ($venue['address'] ?? '—')) ?></dd>
                            </div>
                            <div>
                                <dt>Events</dt>
                                <dd><?= (int) ($venue['event_count'] ?? 0) ?></dd>
                            </div>
                        </dl>
                        <div class="list-card__actions">
                            <a href="venue-form.php?id=<?= (int) $venue['id'] ?>" class="btn btn--secondary btn--sm">Edit</a>
                            <form method="post" action="venue-action.php">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $venue['id'] ?>">
                                <input type="hidden" name="action" value="<?= $active ? 'deactivate' : 'activate' ?>">
                                <button type="submit" class="btn btn--secondary btn--sm"><?= $active ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php renderAdminPagination($page, $total, 'venues.php'); ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
