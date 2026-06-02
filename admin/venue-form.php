<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/venues-repository.php';

requireAdminCapability('events');

$pdo    = getDB();
$id     = (int) ($_GET['id'] ?? 0);
$venue  = $id > 0 ? getVenueById($pdo, $id) : null;

if ($id > 0 && !$venue) {
    setAdminFlash('error', 'Venue not found.');
    header('Location: venues.php');
    exit;
}

$isEdit = $venue !== null;
$errors = $_SESSION['venue_form_errors'] ?? [];
$old    = $_SESSION['venue_form_old'] ?? [];
unset($_SESSION['venue_form_errors'], $_SESSION['venue_form_old']);

function venueOld(array $old, ?array $venue, string $key, string $default = ''): string
{
    if (isset($old[$key])) {
        return h((string) $old[$key]);
    }
    if ($venue && isset($venue[$key])) {
        return h((string) $venue[$key]);
    }
    return h($default);
}

$pageTitle  = $isEdit ? 'Edit Venue' : 'Add Venue';
$activePage = 'venues';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= $isEdit ? 'Edit Venue' : 'Add Venue' ?></h2>
            <p class="card__subtitle">Venues group nightclub, office, static, and event shifts on registration forms.</p>
        </div>
        <a href="venues.php" class="btn btn--secondary">← Back to Venues</a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert--error alert--visible">
            <?php foreach ($errors as $msg): ?>
                <div><?= h($msg) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="save-venue.php" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int) $venue['id'] ?>">
        <?php endif; ?>

        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="name">Venue name</label>
            <input class="form-input" type="text" id="name" name="name" value="<?= venueOld($old, $venue, 'name') ?>" placeholder="e.g. District Nightclub" required>
        </div>

        <div class="form-group">
            <label class="form-label form-label--required" for="venue_type">Venue type</label>
            <select class="form-select" id="venue_type" name="venue_type" required>
                <?php
                $selectedType = (string) ($old['venue_type'] ?? $venue['venue_type'] ?? 'other');
                foreach (getVenueTypeOptions() as $value => $label):
                ?>
                    <option value="<?= h($value) ?>"<?= $selectedType === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="address">Address</label>
            <input class="form-input" type="text" id="address" name="address" value="<?= venueOld($old, $venue, 'address') ?>" placeholder="Street, city">
        </div>

        <div class="form-group">
            <label class="form-label" for="venue_eircode">Eircode</label>
            <input class="form-input" type="text" id="venue_eircode" name="venue_eircode" value="<?= venueOld($old, $venue, 'venue_eircode') ?>" placeholder="e.g. D02 X285" maxlength="8">
        </div>

        <div class="form-group">
            <label class="form-label">Status</label>
            <label class="form-radio">
                <?php
                $isActive = isset($old['is_active'])
                    ? !empty($old['is_active'])
                    : ($venue ? (int) $venue['is_active'] === 1 : true);
                ?>
                <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>>
                Active (show on registration forms)
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save Changes' : 'Create Venue' ?></button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
