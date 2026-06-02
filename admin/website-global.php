<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/website-content.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/brand-logo.php';
require_once __DIR__ . '/../includes/admin/website-handler.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

$cmsPanel = ($_GET['panel'] ?? '') === 'cms';
if ($cmsPanel) {
    requireAdminCapability('website');
} else {
    requireAdminCapability('settings');
}

$pdo      = getDB();
$result   = processWebsitePost($pdo, 'website_global');
$error    = $result['error'];
$success  = $result['success'];
$content  = $result['content'];
$settings = getAllSettings($pdo);
$g        = $content['global'] ?? [];
$homePageUrl = normalizePublicSiteUrl(getAppBaseUrl()) . '/home.php';
$logoUrl  = getCompanyLogoUrl($pdo, '../');

$pageTitle          = $cmsPanel ? 'Global & brand' : 'Branding';
$activePage         = 'website-global';
if ($cmsPanel) {
    $adminSectionNav    = getAdminWebsiteNavItems();
    $adminSectionActive = 'global';
} else {
    $erpSettingsActive = 'branding';
}

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title"><?= $cmsPanel ? 'Global &amp; brand' : 'Branding' ?></h2>
            <p class="card__subtitle">Logo, company name on homepage, header tagline, and notice banner — updates the public site globally.</p>
        </div>
        <a href="<?= h($homePageUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Preview site ↗</a>
    </div>

    <?php if ($success !== ''): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid settings-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="website_global">

        <h4 class="form-section-title form-group--full">Company logo</h4>
        <p class="form-hint form-group--full">Shown in the website header, footer, and homepage hero. PNG or JPG with transparent background works best. Max 2 MB.</p>
        <div class="form-group form-group--full logo-upload-preview">
            <?php if ($logoUrl !== ''): ?>
                <img src="<?= h($logoUrl) ?>" alt="Current logo" class="logo-upload-preview__img">
            <?php else: ?>
                <div class="logo-upload-preview__placeholder">No logo uploaded — theme icon is used instead</div>
            <?php endif; ?>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="company_logo">Upload logo</label>
            <input class="form-input" type="file" id="company_logo" name="company_logo" accept="image/png,image/jpeg,image/webp,image/gif">
        </div>
        <?php if ($logoUrl !== ''): ?>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="remove_company_logo" value="1">
                Remove current logo
            </label>
        </div>
        <?php endif; ?>

        <div class="form-group form-group--full">
            <label class="form-label" for="company_name">Company name</label>
            <input class="form-input" id="company_name" name="company_name" value="<?= h($settings['company_name'] ?? '') ?>" required>
            <p class="form-hint">Same as Settings → Site &amp; URLs. Updates homepage header, footer, and hero brand card.</p>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="brand_tag">Header tagline</label>
            <input class="form-input" id="brand_tag" name="brand_tag" value="<?= h($g['brand_tag'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="company_tagline">Default tagline</label>
            <input class="form-input" id="company_tagline" name="company_tagline" value="<?= h($settings['company_tagline'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="footer_tagline">Footer description</label>
            <input class="form-input" id="footer_tagline" name="footer_tagline" value="<?= h($g['footer_tagline'] ?? '') ?>">
        </div>
        <h4 class="form-section-title form-group--full" id="notice_items">Scrolling notice banner</h4>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="notice_enabled" value="1"<?= !empty($g['notice_enabled']) ? ' checked' : '' ?>>
                Show scrolling notice banner
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="notice_items_field">Notice messages (one per line)</label>
            <textarea class="form-textarea" id="notice_items_field" name="notice_items" rows="5"><?= h(implode("\n", $g['notice_items'] ?? [])) ?></textarea>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="company_about">About text</label>
            <textarea class="form-textarea rich-text" id="company_about" name="company_about" rows="3"><?= h($settings['company_about'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label" for="company_email">Contact email</label>
            <input class="form-input" type="email" id="company_email" name="company_email" value="<?= h($settings['company_email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="company_phone">Contact phone</label>
            <input class="form-input" id="company_phone" name="company_phone" value="<?= h($settings['company_phone'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="company_whatsapp">WhatsApp</label>
            <input class="form-input" id="company_whatsapp" name="company_whatsapp" value="<?= h($settings['company_whatsapp'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="company_whatsapp_group">WhatsApp group link</label>
            <input class="form-input" type="url" id="company_whatsapp_group" name="company_whatsapp_group" value="<?= h($settings['company_whatsapp_group'] ?? '') ?>">
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save global settings</button>
        </div>
    </form>
</section>

<?php
$enableRichTextEditor = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';
