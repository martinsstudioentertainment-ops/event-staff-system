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
$result     = processWebsitePost($pdo, 'website_home');
$error      = $result['error'];
$success    = $result['success'];
$content    = $result['content'];
$h          = $content['home'] ?? [];
$previewUrl = normalizePublicSiteUrl(getAppBaseUrl()) . '/home.php';

$pageTitle          = 'Homepage';
$activePage         = 'website-home';
$adminSectionNav    = getAdminWebsiteNavItems();
$adminSectionActive = 'home';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Homepage</h2>
            <p class="card__subtitle">Hero, stats, role preview, and bottom call-to-action.</p>
        </div>
        <a href="<?= h($previewUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Preview page ↗</a>
    </div>

    <?php if ($success !== ''): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="website_home">
        <div class="form-group form-group--full">
            <label class="form-label" for="hero_eyebrow">Hero eyebrow</label>
            <input class="form-input" id="hero_eyebrow" name="hero_eyebrow" value="<?= h($h['hero_eyebrow'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="hero_title">Hero title</label>
            <input class="form-input" id="hero_title" name="hero_title" value="<?= h($h['hero_title'] ?? '') ?>">
            <p class="form-hint">Large headline on the homepage — this is not the company name. Change company name under Settings → Site &amp; URLs or Website → Global &amp; brand.</p>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="hero_lead">Hero description</label>
            <textarea class="form-textarea rich-text" id="hero_lead" name="hero_lead" rows="3"><?= h($h['hero_lead'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label" for="cta_primary">Primary button</label>
            <input class="form-input" id="cta_primary" name="cta_primary" value="<?= h($h['cta_primary'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="cta_secondary">Secondary button</label>
            <input class="form-input" id="cta_secondary" name="cta_secondary" value="<?= h($h['cta_secondary'] ?? '') ?>">
        </div>
        <?php for ($i = 0; $i < 4; $i++): $stat = ($h['stats'][$i] ?? []); ?>
            <div class="form-group">
                <label class="form-label">Stat <?= $i + 1 ?> value</label>
                <input class="form-input" name="stat_value_<?= $i ?>" value="<?= h($stat['value'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Stat <?= $i + 1 ?> label</label>
                <input class="form-input" name="stat_label_<?= $i ?>" value="<?= h($stat['label'] ?? '') ?>">
            </div>
        <?php endfor; ?>
        <div class="form-group form-group--full">
            <label class="form-label" for="preview_title">Roles preview title</label>
            <input class="form-input" id="preview_title" name="preview_title" value="<?= h($h['preview_title'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="preview_desc">Roles preview description</label>
            <textarea class="form-textarea rich-text" id="preview_desc" name="preview_desc" rows="2"><?= h($h['preview_desc'] ?? '') ?></textarea>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="cta_band_title">Bottom CTA title</label>
            <input class="form-input" id="cta_band_title" name="cta_band_title" value="<?= h($h['cta_band_title'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="cta_band_desc">Bottom CTA description</label>
            <textarea class="form-textarea rich-text" id="cta_band_desc" name="cta_band_desc" rows="2"><?= h($h['cta_band_desc'] ?? '') ?></textarea>
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save homepage</button>
        </div>
    </form>
</section>

<?php
$enableRichTextEditor = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';
