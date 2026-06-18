<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';
require_once __DIR__ . '/../includes/workforce/preference-locations.php';

requireAdminCapability('settings');

$pdo = getDB();
ensureStaffPreferencesFoundationSchema($pdo);

$success = (string) ($_SESSION['pref_loc_flash_success'] ?? '');
$error   = (string) ($_SESSION['pref_loc_flash_error'] ?? '');
unset($_SESSION['pref_loc_flash_success'], $_SESSION['pref_loc_flash_error']);

$locations = preferenceLocationsList($pdo, false);
$editId    = (int) ($_GET['edit'] ?? 0);
$editRow   = $editId > 0 ? preferenceLocationById($pdo, $editId) : null;

$pageTitle         = 'Preference locations';
$activePage        = 'settings-preference-locations';
$erpSettingsActive = 'preference-locations';

include __DIR__ . '/../includes/admin/layout-top.php';
renderErpSettingsLayoutStart('preference-locations');
?>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">Preference locations</h2>
        <p class="card__subtitle">Manage work location options shown during staff registration and in the mobile app config.</p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="preference-location-action.php" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="task" value="save">
        <?php if ($editRow !== null): ?>
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label" for="label">Label</label>
                <input class="form-input" type="text" id="label" name="label" required maxlength="120"
                       value="<?= h((string) ($editRow['label'] ?? '')) ?>" placeholder="Dublin">
            </div>
            <div class="form-group">
                <label class="form-label" for="slug">Slug</label>
                <input class="form-input" type="text" id="slug" name="slug" maxlength="64"
                       value="<?= h((string) ($editRow['slug'] ?? '')) ?>" placeholder="dublin">
                <p class="form-hint">Lowercase identifier used in JSON preferences.</p>
            </div>
            <div class="form-group">
                <label class="form-label" for="sort_order">Sort order</label>
                <input class="form-input" type="number" id="sort_order" name="sort_order"
                       value="<?= (int) ($editRow['sort_order'] ?? 0) ?>">
            </div>
        </div>

        <div class="form-group form-group--full">
            <label class="form-checkbox">
                <input type="checkbox" name="is_active" value="1"<?= ($editRow === null || !empty($editRow['is_active'])) ? ' checked' : '' ?>>
                <span>Active (visible to staff)</span>
            </label>
        </div>

        <div class="form-actions form-actions--end">
            <button type="submit" class="btn btn--primary"><?= $editRow !== null ? 'Update location' : 'Add location' ?></button>
            <?php if ($editRow !== null): ?>
                <a href="settings-preference-locations.php" class="btn btn--secondary">Cancel edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">All locations</h2>
    </div>
    <table class="data-table" style="width:100%;">
        <thead>
            <tr>
                <th>Label</th>
                <th>Slug</th>
                <th>Sort</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($locations === []): ?>
            <tr><td colspan="5">No locations yet.</td></tr>
        <?php else: ?>
            <?php foreach ($locations as $loc): ?>
                <tr>
                    <td><?= h((string) $loc['label']) ?></td>
                    <td><code><?= h((string) $loc['slug']) ?></code></td>
                    <td><?= (int) ($loc['sort_order'] ?? 0) ?></td>
                    <td><?= !empty($loc['is_active']) ? 'Active' : 'Disabled' ?></td>
                    <td class="data-table__actions">
                        <a class="btn btn--secondary btn--sm" href="settings-preference-locations.php?edit=<?= (int) $loc['id'] ?>">Edit</a>
                        <form method="post" action="preference-location-action.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="task" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $loc['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= !empty($loc['is_active']) ? '0' : '1' ?>">
                            <button type="submit" class="btn btn--secondary btn--sm">
                                <?= !empty($loc['is_active']) ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php
renderErpSettingsLayoutEnd();
include __DIR__ . '/../includes/admin/layout-bottom.php';
