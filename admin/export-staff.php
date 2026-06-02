<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

requireAdminCapability('export');

$pdo     = getDB();
$filters = getStaffFiltersFromRequest();
$events  = getEventsForFilter($pdo);
$flash   = getAdminFlash();

$pageTitle          = 'Export Staff CSV';
$activePage         = 'export-staff';
$adminSectionNav    = getAdminExportNavItems();
$adminSectionActive = 'staff';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Staff registrations CSV</h2>
        <p class="card__subtitle">Download matching registrations with full details. Exported rows are marked in the database.</p>
    </div>

    <form method="get" action="export.php" class="filter-bar">
        <div class="filter-bar__group">
            <input class="form-input" type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Search name, email, mobile">
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="status">
                <option value="">All statuses</option>
                <option value="pending"<?= $filters['status'] === 'pending' ? ' selected' : '' ?>>Pending</option>
                <option value="approved"<?= $filters['status'] === 'approved' ? ' selected' : '' ?>>Approved</option>
                <option value="rejected"<?= $filters['status'] === 'rejected' ? ' selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="role">
                <option value="">All roles</option>
                <option value="dsp"<?= $filters['role'] === 'dsp' ? ' selected' : '' ?>>Door Supervisor (DSP)</option>
                <option value="static"<?= $filters['role'] === 'static' ? ' selected' : '' ?>>Static Security</option>
                <option value="steward"<?= $filters['role'] === 'steward' ? ' selected' : '' ?>>Steward</option>
            </select>
        </div>
        <div class="filter-bar__group">
            <select class="form-select" name="event_id">
                <option value="">All events</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['id'] ?>"<?= (int) $filters['event_id'] === (int) $event['id'] ? ' selected' : '' ?>>
                        <?= h($event['name'] . ' — ' . date('d.m.Y', strtotime($event['event_date']))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar__actions">
            <button type="submit" class="btn btn--primary">Download CSV</button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
