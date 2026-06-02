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
$result     = processWebsitePost($pdo, 'website_events');
$error      = $result['error'];
$success    = $result['success'];
$content    = $result['content'];
$e          = $content['events'] ?? [];
$previewUrl = normalizePublicSiteUrl(getAppBaseUrl()) . '/events-page.php';

$pageTitle          = 'Events page';
$activePage         = 'website-events';
$adminSectionNav    = getAdminWebsiteNavItems();
$adminSectionActive = 'events';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Events page</h2>
            <p class="card__subtitle">Page copy and the four-step process section.</p>
        </div>
        <a href="<?= h($previewUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Preview page ↗</a>
    </div>

    <?php if ($success !== ''): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="website_events">
        <div class="form-group form-group--full">
            <label class="form-label" for="events_title">Page title</label>
            <input class="form-input" id="events_title" name="events_title" value="<?= h($e['title'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="events_subtitle">Subtitle</label>
            <input class="form-input" id="events_subtitle" name="events_subtitle" value="<?= h($e['subtitle'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="events_intro">Intro</label>
            <textarea class="form-textarea rich-text" id="events_intro" name="events_intro" rows="3"><?= h($e['intro'] ?? '') ?></textarea>
        </div>
        <?php for ($i = 0; $i < 4; $i++): $step = ($e['steps'][$i] ?? []); ?>
            <div class="form-group">
                <label class="form-label">Step <?= $i + 1 ?> title</label>
                <input class="form-input" name="event_step_title_<?= $i ?>" value="<?= h($step['title'] ?? '') ?>">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label">Step <?= $i + 1 ?> description</label>
                <textarea class="form-textarea rich-text" name="event_step_desc_<?= $i ?>" rows="2"><?= h($step['desc'] ?? '') ?></textarea>
            </div>
        <?php endfor; ?>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save events page</button>
        </div>
    </form>
</section>

<?php
$enableRichTextEditor = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';
