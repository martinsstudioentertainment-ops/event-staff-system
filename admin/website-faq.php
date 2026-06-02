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
$result     = processWebsitePost($pdo, 'website_faq');
$error      = $result['error'];
$success    = $result['success'];
$content    = $result['content'];
$f          = $content['faq'] ?? [];
$previewUrl = normalizePublicSiteUrl(getAppBaseUrl()) . '/faq.php';

$pageTitle          = 'FAQ';
$activePage         = 'website-faq';
$adminSectionNav    = getAdminWebsiteNavItems();
$adminSectionActive = 'faq';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">FAQ</h2>
            <p class="card__subtitle">Page heading and up to six question-and-answer pairs.</p>
        </div>
        <a href="<?= h($previewUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Preview page ↗</a>
    </div>

    <?php if ($success !== ''): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="website_faq">
        <div class="form-group form-group--full">
            <label class="form-label" for="faq_title">Page title</label>
            <input class="form-input" id="faq_title" name="faq_title" value="<?= h($f['title'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="faq_subtitle">Subtitle</label>
            <input class="form-input" id="faq_subtitle" name="faq_subtitle" value="<?= h($f['subtitle'] ?? '') ?>">
        </div>
        <?php for ($i = 0; $i < 6; $i++): $item = ($f['items'][$i] ?? []); ?>
            <div class="form-group form-group--full">
                <label class="form-label">Question <?= $i + 1 ?></label>
                <input class="form-input" name="faq_q_<?= $i ?>" value="<?= h($item['q'] ?? '') ?>">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label">Answer <?= $i + 1 ?></label>
                <textarea class="form-textarea rich-text" name="faq_a_<?= $i ?>" rows="2"><?= h($item['a'] ?? '') ?></textarea>
            </div>
        <?php endfor; ?>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save FAQ</button>
        </div>
    </form>
</section>

<?php
$enableRichTextEditor = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';
