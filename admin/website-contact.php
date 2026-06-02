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
$result     = processWebsitePost($pdo, 'website_contact');
$error      = $result['error'];
$success    = $result['success'];
$content    = $result['content'];
$settings   = getAllSettings($pdo);
$c          = $content['contact'] ?? [];
$previewUrl = normalizePublicSiteUrl(getAppBaseUrl()) . '/contact.php';

$pageTitle          = 'Contact';
$activePage         = 'website-contact';
$adminSectionNav    = getAdminWebsiteNavItems();
$adminSectionActive = 'contact';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Contact page</h2>
            <p class="card__subtitle">Page copy, office hours, and contact details shown on the site.</p>
        </div>
        <a href="<?= h($previewUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Preview page ↗</a>
    </div>

    <?php if ($success !== ''): ?><div class="alert alert--success alert--visible"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert--error alert--visible"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="website_contact">
        <div class="form-group form-group--full">
            <label class="form-label" for="contact_title">Page title</label>
            <input class="form-input" id="contact_title" name="contact_title" value="<?= h($c['title'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="contact_subtitle">Subtitle</label>
            <input class="form-input" id="contact_subtitle" name="contact_subtitle" value="<?= h($c['subtitle'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="contact_intro">Intro</label>
            <textarea class="form-textarea rich-text" id="contact_intro" name="contact_intro" rows="3"><?= h($c['intro'] ?? '') ?></textarea>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="contact_hours">Office hours</label>
            <input class="form-input" id="contact_hours" name="contact_hours" value="<?= h($c['hours'] ?? '') ?>">
        </div>
        <h4 class="form-section-title form-group--full">Contact details</h4>
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
            <button type="submit" class="btn btn--primary">Save contact page</button>
        </div>
    </form>
</section>

<?php
$enableRichTextEditor = true;
include __DIR__ . '/../includes/admin/layout-bottom.php';
