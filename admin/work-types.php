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

    <?php if ($workTypes === []): ?>
        <p class="form-hint">No work types yet.</p>
    <?php else: ?>
        <div class="list-card-board">
            <?php foreach ($workTypes as $type): ?>
                <?php $activeType = (int) ($type['is_active'] ?? 0) === 1; ?>
                <details class="list-card list-card--<?= $activeType ? 'ok' : 'issue' ?>">
                    <summary class="list-card__front">
                        <span class="list-card__who">
                            <strong><?= h($type['name']) ?></strong>
                            <span class="list-card__hint">Click to open</span>
                        </span>
                        <?php if ($activeType): ?>
                            <span class="badge badge--approved">Active</span>
                        <?php else: ?>
                            <span class="badge badge--rejected">Inactive</span>
                        <?php endif; ?>
                    </summary>
                    <div class="list-card__body">
                        <dl class="list-card__facts">
                            <div>
                                <dt>Code</dt>
                                <dd><code><?= h($type['slug']) ?></code></dd>
                            </div>
                            <div>
                                <dt>Order</dt>
                                <dd><?= (int) ($type['sort_order'] ?? 0) ?></dd>
                            </div>
                            <div>
                                <dt>Events</dt>
                                <dd><?= (int) ($type['event_count'] ?? 0) ?></dd>
                            </div>
                        </dl>
                        <div class="list-card__actions">
                            <a href="work-type-form.php?id=<?= (int) $type['id'] ?>" class="btn btn--secondary btn--sm">Edit</a>
                            <form method="post" action="work-type-action.php">
                                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $type['id'] ?>">
                                <input type="hidden" name="action" value="<?= $activeType ? 'deactivate' : 'activate' ?>">
                                <button type="submit" class="btn btn--secondary btn--sm"><?= $activeType ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
