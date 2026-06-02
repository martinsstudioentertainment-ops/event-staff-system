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
$result     = processWebsitePost($pdo, 'website_how');
$error      = $result['error'];
$success    = $result['success'];
$content    = $result['content'];
$w          = $content['how'] ?? [];
$previewUrl = normalizePublicSiteUrl(getAppBaseUrl()) . '/how-it-works.php';

$pageTitle          = 'How it works';
$activePage         = 'website-how';
$adminSectionNav    = getAdminWebsiteNavItems();
$adminSectionActive = 'how';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">How it works</h2>
            <p class="card__subtitle">Process steps and trust bullet points.</p>
        </div>
        <a href="<?= h($previewUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Preview page ↗</a>
    </div>

    <?php if ($success !== ''): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="website_how">
        <div class="form-group form-group--full">
            <label class="form-label" for="how_page_title">Page title</label>
            <input class="form-input" id="how_page_title" name="how_page_title" value="<?= h($w['title'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="how_subtitle">Subtitle</label>
            <input class="form-input" id="how_subtitle" name="how_subtitle" value="<?= h($w['subtitle'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="how_intro">Intro</label>
            <textarea class="form-textarea rich-text" id="how_intro" name="how_intro" rows="3"><?= h($w['intro'] ?? '') ?></textarea>
        </div>
        <?php for ($i = 0; $i < 4; $i++): $step = ($w['steps'][$i] ?? []); ?>
            <div class="form-group">
                <label class="form-label">Step <?= $i + 1 ?> number</label>
                <input class="form-input" name="how_num_<?= $i ?>" value="<?= h($step['num'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Step <?= $i + 1 ?> title</label>
                <input class="form-input" name="how_title_<?= $i ?>" value="<?= h($step['title'] ?? '') ?>">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label">Step <?= $i + 1 ?> description</label>
                <textarea class="form-textarea rich-text" name="how_desc_<?= $i ?>" rows="2"><?= h($step['desc'] ?? '') ?></textarea>
            </div>
        <?php endfor; ?>
        <div class="form-group form-group--full">
            <label class="form-label" for="how_trust">Trust bullet points (one per line)</label>
            <textarea class="form-textarea" id="how_trust" name="how_trust" rows="5"><?= h(implode("\n", $w['trust'] ?? [])) ?></textarea>
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save how it works</button>
        </div>
    </form>
</section>

<?php
$enableRichTextEditor = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';
