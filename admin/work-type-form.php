<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/work-types-repository.php';

requireAdminCapability('events');

$pdo      = getDB();
$id       = (int) ($_GET['id'] ?? 0);
$workType = $id > 0 ? getWorkTypeById($pdo, $id) : null;

if ($id > 0 && !$workType) {
    setAdminFlash('error', 'Work type not found.');
    header('Location: work-types.php');
    exit;
}

$isEdit = $workType !== null;
$errors = $_SESSION['work_type_form_errors'] ?? [];
$old    = $_SESSION['work_type_form_old'] ?? [];
unset($_SESSION['work_type_form_errors'], $_SESSION['work_type_form_old']);

function workTypeOld(array $old, ?array $workType, string $key, string $default = ''): string
{
    if (isset($old[$key])) {
        return h((string) $old[$key]);
    }
    if ($workType && isset($workType[$key])) {
        return h((string) $workType[$key]);
    }
    return h($default);
}

$pageTitle  = $isEdit ? 'Edit Work Type' : 'Add Work Type';
$activePage = 'work-types';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= $isEdit ? 'Edit Work Type' : 'Add Work Type' ?></h2>
            <p class="card__subtitle">The code (slug) is fixed after creation so existing events keep working.</p>
        </div>
        <a href="work-types.php" class="btn btn--secondary">← Back to Work Types</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert--error alert--visible">
            <?php foreach ($errors as $msg): ?>
                <div><?= h($msg) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="save-work-type.php" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int) $workType['id'] ?>">
        <?php endif; ?>

        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="name">Display name</label>
            <input class="form-input" type="text" id="name" name="name" value="<?= workTypeOld($old, $workType, 'name') ?>" placeholder="e.g. Hospital security" required>
        </div>

        <?php if ($isEdit): ?>
            <div class="form-group">
                <label class="form-label">Code (stored on events)</label>
                <input class="form-input" type="text" value="<?= h((string) $workType['slug']) ?>" readonly disabled>
            </div>
        <?php endif; ?>

        <div class="form-group form-group--full">
            <label class="form-label" for="description">Description</label>
            <input class="form-input" type="text" id="description" name="description" value="<?= workTypeOld($old, $workType, 'description') ?>" placeholder="Optional note for admins">
        </div>

        <div class="form-group">
            <label class="form-label" for="sort_order">Sort order</label>
            <input class="form-input" type="number" id="sort_order" name="sort_order" min="0" max="9999" value="<?= workTypeOld($old, $workType, 'sort_order', '0') ?>">
            <p class="form-hint">Lower numbers appear first in dropdowns.</p>
        </div>

        <div class="form-group">
            <label class="form-label">Status</label>
            <label class="form-radio">
                <?php
                $isActive = isset($old['is_active'])
                    ? !empty($old['is_active'])
                    : ($workType ? (int) $workType['is_active'] === 1 : true);
                ?>
                <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>>
                Active (available on new events and registration forms)
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save Changes' : 'Create Work Type' ?></button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
