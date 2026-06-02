<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/work-types-repository.php';

requireAdminCapability('events');

$pdo        = getDB();
$workTypes  = getAllWorkTypes($pdo);
$flash      = getAdminFlash();

$pageTitle  = 'Work Types';
$activePage = 'work-types';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Work types</h2>
            <p class="card__subtitle">Define shift categories (concert, nightclub, hospital, etc.). Events use a work type; registration forms filter which types each role sees.</p>
        </div>
        <a href="work-type-form.php" class="btn btn--primary">+ Add work type</a>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Order</th>
                    <th>Events</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($workTypes === []): ?>
                    <tr>
                        <td colspan="6" class="data-table__empty">No work types yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($workTypes as $type): ?>
                        <tr>
                            <td><?= h($type['name']) ?></td>
                            <td><code><?= h($type['slug']) ?></code></td>
                            <td><?= (int) ($type['sort_order'] ?? 0) ?></td>
                            <td><?= (int) ($type['event_count'] ?? 0) ?></td>
                            <td>
                                <?php if ((int) ($type['is_active'] ?? 0) === 1): ?>
                                    <span class="badge badge--approved">Active</span>
                                <?php else: ?>
                                    <span class="badge badge--rejected">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="data-table__actions">
                                <a href="work-type-form.php?id=<?= (int) $type['id'] ?>" class="btn btn--secondary btn--sm">Edit</a>
                                <?php if ((int) ($type['is_active'] ?? 0) === 1): ?>
                                    <form method="post" action="work-type-action.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $type['id'] ?>">
                                        <input type="hidden" name="action" value="deactivate">
                                        <button type="submit" class="btn btn--secondary btn--sm">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="work-type-action.php" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $type['id'] ?>">
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
