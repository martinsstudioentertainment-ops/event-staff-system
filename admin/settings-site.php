<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/admin/settings-handler.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

requireAdminCapability('settings');

require_once __DIR__ . '/../includes/global-public-site.php';

$pdo       = getDB();
$adminUser = getAdminUser();
$result    = processSettingsPost($pdo, $adminUser, 'site');
$error     = $result['error'];
$success   = $result['success'];
$settings  = $result['settings'];
$publicBrand = getGlobalPublicSiteConfig($pdo);

$pageTitle          = 'General';
$activePage         = 'settings-site';
$erpSettingsActive  = 'general';
$registrationUrl    = getRegistrationFormUrl($pdo);
$homePageUrl        = getMarketingSiteUrl($pdo) . '/home.php';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">General</h2>
        <p class="card__subtitle">Company details, contact info, and site URLs — applies globally to the public website and registration form.</p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="erp-welcome__main" style="margin-bottom:1.25rem;">
        <h3 class="erp-welcome__title" style="font-size:1.125rem;">Global public site</h3>
        <p class="erp-welcome__subtitle" style="margin-bottom:0.75rem;">Changes here apply everywhere: homepage (<code>home.php</code>), registration form, sign-in pages, and emails. Current public name: <strong><?= h($publicBrand['companyName']) ?></strong></p>
        <div class="erp-welcome__actions">
            <a href="<?= h($publicBrand['homeUrl']) ?>" class="btn btn--secondary btn--sm" target="_blank" rel="noopener">Preview homepage ↗</a>
            <a href="website-global.php" class="btn btn--secondary btn--sm">Branding</a>
            <a href="settings-theme.php" class="btn btn--secondary btn--sm">UI controls</a>
        </div>
    </div>

    <div class="url-format-guide" role="note">
        <p class="url-format-guide__title">Production URL layout</p>
        <table class="url-format-guide__table">
            <tbody>
                <tr class="url-format-guide__row--active">
                    <th scope="row">Main company website</th>
                    <td><code>https://olasentra.com</code></td>
                </tr>
                <tr class="url-format-guide__row--active">
                    <th scope="row">Staff registration form</th>
                    <td><code>https://register.olasentra.com</code></td>
                </tr>
                <tr class="url-format-guide__row--active">
                    <th scope="row">Admin panel</th>
                    <td><code>https://admin.olasentra.com</code></td>
                </tr>
                <tr class="url-format-guide__row--active">
                    <th scope="row">Apply / staff profiles</th>
                    <td><code>https://apply.olasentra.com</code></td>
                </tr>
            </tbody>
        </table>
    </div>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="site">

        <h4 class="form-section-title form-group--full">Company homepage</h4>
        <?php
        require_once __DIR__ . '/../includes/email-copy.php';
        renderRegistrationPortalNotice($pdo);
        ?>
        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="company_name">Portal / company name</label>
            <input class="form-input" type="text" id="company_name" name="company_name" value="<?= h($settings['company_name'] ?? '') ?>" required placeholder="e.g. Olasentra">
            <p class="form-hint">Your registration portal name — used on the website, registration form, and as the <strong>From name</strong> on staff emails. Use your own brand, not a security contractor clients work with on site.</p>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="company_tagline">Tagline</label>
            <input class="form-input" type="text" id="company_tagline" name="company_tagline" value="<?= h($settings['company_tagline'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="company_email">Contact email</label>
            <input class="form-input" type="email" id="company_email" name="company_email" value="<?= h($settings['company_email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="company_phone">Contact phone</label>
            <input class="form-input" type="text" id="company_phone" name="company_phone" value="<?= h($settings['company_phone'] ?? '') ?>" placeholder="+353 1 000 0000">
        </div>
        <div class="form-group">
            <label class="form-label" for="company_whatsapp">WhatsApp</label>
            <input class="form-input" type="text" id="company_whatsapp" name="company_whatsapp" value="<?= h($settings['company_whatsapp'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="company_whatsapp_group">WhatsApp group link</label>
            <input class="form-input" type="url" id="company_whatsapp_group" name="company_whatsapp_group" value="<?= h($settings['company_whatsapp_group'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="company_about">About text</label>
            <textarea class="form-textarea" id="company_about" name="company_about" rows="3"><?= h($settings['company_about'] ?? '') ?></textarea>
        </div>

        <h4 class="form-section-title form-group--full">Registration &amp; admin URLs</h4>
        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="site_name">Registration site name</label>
            <input class="form-input" type="text" id="site_name" name="site_name" value="<?= h($settings['site_name'] ?? '') ?>" required>
            <p class="form-hint">Title on the staff registration form (<code>index.php</code>). Usually the same as company name — it auto-syncs when you change company name.</p>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="registration_site_url">Registration form URL</label>
            <input class="form-input" type="url" id="registration_site_url" name="registration_site_url" value="<?= h($settings['registration_site_url'] ?? '') ?>" placeholder="https://register.your-events.com">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="admin_site_url">Admin panel URL</label>
            <input class="form-input" type="url" id="admin_site_url" name="admin_site_url" value="<?= h($settings['admin_site_url'] ?? '') ?>" placeholder="https://manage.your-events.com/admin">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="apply_site_url">Apply admin URL</label>
            <input class="form-input" type="url" id="apply_site_url" name="apply_site_url" value="<?= h($settings['apply_site_url'] ?? inferApplySiteUrl($pdo)) ?>" placeholder="https://apply.olasentra.com">
            <p class="form-hint">Apply site host only — <code>https://apply.olasentra.com</code> (not <code>/admin</code>). SSO opens <code>/sso.php</code> on that host. Leave blank to auto-detect.</p>
        </div>

        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save site settings</button>
            <a href="<?= h($homePageUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Preview homepage ↗</a>
            <a href="<?= h($registrationUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">Registration form ↗</a>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
