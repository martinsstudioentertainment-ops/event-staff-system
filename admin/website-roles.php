<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/website-content.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/admin/website-handler.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

requireAdminCapability('website');

$pdo        = getDB();
$result     = processWebsitePost($pdo, 'website_roles');
$error      = $result['error'];
$success    = $result['success'];
$content    = $result['content'];
$r          = $content['roles'] ?? [];
$previewUrl = normalizePublicSiteUrl(getAppBaseUrl()) . '/roles.php';

$pageTitle          = 'Roles';
$activePage         = 'website-roles';
$adminSectionNav    = getAdminWebsiteNavItems();
$adminSectionActive = 'roles';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Roles page</h2>
            <p class="card__subtitle">Page heading and intro for the roles listing.</p>
        </div>
        <a href="<?= h($previewUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Preview page ↗</a>
    </div>

    <?php if ($success !== ''): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="website_roles">
        <div class="form-group form-group--full">
            <label class="form-label" for="roles_title">Page title</label>
            <input class="form-input" id="roles_title" name="roles_title" value="<?= h($r['title'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="roles_subtitle">Subtitle</label>
            <input class="form-input" id="roles_subtitle" name="roles_subtitle" value="<?= h($r['subtitle'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="roles_intro">Intro</label>
            <textarea class="form-textarea rich-text" id="roles_intro" name="roles_intro" rows="3"><?= h($r['intro'] ?? '') ?></textarea>
        </div>
        <p class="form-hint form-group--full">Role cards use default content from the system. Each links to the registration form.</p>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save roles page</button>
        </div>
    </form>
</section>

<?php
$enableRichTextEditor = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';
