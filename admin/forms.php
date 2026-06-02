<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/registration-forms.php';
require_once __DIR__ . '/../includes/admin/forms-nav.php';

requireAdminCapability('forms');

$pdo   = getDB();
$forms = getRegistrationForms($pdo);

$pageTitle          = 'Registration Forms';
$activePage         = 'forms';
$adminSectionNav    = getAdminFormsNavItems();
$adminSectionActive = 'list';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Registration forms</h2>
        <p class="card__subtitle">Three shareable forms — DSP (nightclub / office / special events), Static (non-event site work), and Steward. Each has its own link and venue-first shift picker.</p>
    </div>

    <div class="admin-form-list">
        <?php foreach ($forms as $slug => $form): ?>
            <?php $shareUrl = getRegistrationFormUrl($pdo, $slug); ?>
            <article class="admin-form-list__item">
                <div class="admin-form-list__main">
                    <h3 class="admin-form-list__title"><?= h($form['label'] ?? ucfirst($slug)) ?></h3>
                    <p class="admin-form-list__desc"><?= h($form['description'] ?? $form['subtitle'] ?? '') ?></p>
                    <p class="admin-form-list__url"><code><?= h($shareUrl) ?></code></p>
                </div>
                <div class="admin-form-list__actions">
                    <?php if (!empty($form['enabled'])): ?>
                        <span class="status-badge status-badge--approved">Enabled</span>
                    <?php else: ?>
                        <span class="status-badge status-badge--rejected">Disabled</span>
                    <?php endif; ?>
                    <a href="form-edit.php?slug=<?= h($slug) ?>" class="btn btn--primary">Edit form</a>
                    <a href="<?= h($shareUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Open ↗</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <p class="form-hint" style="margin-top: 1rem;">
        Scrolling notice text is edited under <a href="website-global.php#notice_items">Website → Global &amp; brand</a>.
    </p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
