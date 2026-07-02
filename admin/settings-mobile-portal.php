<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/admin/settings-handler.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/mobile/services/MobilePortalConfigService.php';
require_once __DIR__ . '/../includes/mobile/schema/mobile-api-schema.php';
require_once __DIR__ . '/../includes/staff-app-android.php';

requireAdminCapability('settings');

$pdo       = getDB();
$adminUser = getAdminUser();
$result    = processSettingsPost($pdo, $adminUser, 'mobile_portal_settings');
$error     = $result['error'];
$success   = $result['success'];
$settings  = $result['settings'];
$portal    = mobilePortalGetPublicConfig($pdo);
$configUrl = rtrim(getRegistrationSiteUrl($pdo), '/') . '/api/mobile/v1/config';

$pageTitle         = 'Mobile app';
$activePage        = 'settings-mobile-portal';
$erpSettingsActive = 'mobile';

include __DIR__ . '/../includes/admin/layout-top.php';
renderErpSettingsLayoutStart('mobile');
?>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">Mobile Portal — admin control</h2>
        <p class="card__subtitle">
            Everything the native Android app shows is controlled here. The app loads
            <code><?= h($configUrl) ?></code> on startup — no rebuild required for branding or theme changes.
        </p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="alert alert--info alert--visible" style="margin-bottom:1rem;">
        <strong>Live preview (API)</strong>
        App name: <strong><?= h((string) ($portal['app_name'] ?? 'Olasentra')) ?></strong>
        · Theme: <strong><?= h((string) ($portal['theme']['default'] ?? 'dark')) ?></strong>
        · Maintenance: <strong><?= !empty($portal['maintenance']['enabled']) ? 'ON' : 'off' ?></strong>
    </div>

    <form method="post" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="mobile_portal_settings">

        <h3 class="form-section-title form-group--full">App identity</h3>
        <div class="form-group form-group--full">
            <label class="form-label" for="mobile_portal_app_name">App name</label>
            <input class="form-input" type="text" id="mobile_portal_app_name" name="mobile_portal_app_name" maxlength="80" value="<?= h($settings['mobile_portal_app_name'] ?? 'Olasentra') ?>" required>
            <p class="form-hint">Shown on splash, login, and dashboard. Must be <strong>Olasentra</strong> per branding rules unless you have a specific override.</p>
        </div>

        <h3 class="form-section-title form-group--full">Theme (forced by admin)</h3>
        <div class="form-group">
            <label class="form-label" for="mobile_portal_default_theme">Default theme</label>
            <select class="form-select" id="mobile_portal_default_theme" name="mobile_portal_default_theme">
                <option value="dark"<?= ($settings['mobile_portal_default_theme'] ?? 'dark') === 'dark' ? ' selected' : '' ?>>Dark (recommended)</option>
                <option value="light"<?= ($settings['mobile_portal_default_theme'] ?? 'dark') === 'light' ? ' selected' : '' ?>>Light</option>
            </select>
        </div>
        <div class="form-group form-group--full">
            <label class="form-checkbox">
                <input type="checkbox" name="mobile_portal_allow_theme_toggle" value="1"<?= ($settings['mobile_portal_allow_theme_toggle'] ?? '0') === '1' ? ' checked' : '' ?>>
                <span>Allow staff to change theme in app Settings</span>
            </label>
            <p class="form-hint">When unchecked, all devices stay on the admin default theme.</p>
        </div>

        <h3 class="form-section-title form-group--full">Brand colours</h3>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label" for="mobile_portal_primary_color">Primary colour</label>
                <input class="form-input" type="text" id="mobile_portal_primary_color" name="mobile_portal_primary_color" maxlength="7" value="<?= h($settings['mobile_portal_primary_color'] ?? '#1B1B1F') ?>" placeholder="#1B1B1F">
            </div>
            <div class="form-group">
                <label class="form-label" for="mobile_portal_accent_color">Accent colour</label>
                <input class="form-input" type="text" id="mobile_portal_accent_color" name="mobile_portal_accent_color" maxlength="7" value="<?= h($settings['mobile_portal_accent_color'] ?? '#E85D04') ?>" placeholder="#E85D04">
            </div>
        </div>

        <h3 class="form-section-title form-group--full">Logos &amp; images (server paths)</h3>
        <p class="form-hint form-group--full">Paths relative to site root on <?= h(getRegistrationSiteUrl($pdo)) ?>. Upload files via FTP to <code>storage/branding/mobile/</code>.</p>
        <?php
        $assetFields = [
            'mobile_portal_logo_path'           => 'App logo',
            'mobile_portal_splash_logo_path'    => 'Splash logo',
            'mobile_portal_login_logo_path'     => 'Login logo',
            'mobile_portal_dashboard_logo_path' => 'Dashboard logo',
            'mobile_portal_welcome_image_path'  => 'Welcome image',
            'mobile_portal_banner_image_path'   => 'Banner image',
        ];
        foreach ($assetFields as $key => $label):
            $preview = mobilePortalPublicAssetUrl($pdo, $key);
            ?>
            <div class="form-group form-group--full">
                <label class="form-label" for="<?= h($key) ?>"><?= h($label) ?></label>
                <input class="form-input" type="text" id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= h($settings[$key] ?? '') ?>" placeholder="storage/branding/mobile/...">
                <?php if ($preview): ?>
                    <p class="form-hint">Live URL: <a href="<?= h($preview) ?>" target="_blank" rel="noopener"><?= h($preview) ?></a></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <h3 class="form-section-title form-group--full">Welcome banner</h3>
        <div class="form-group form-group--full">
            <label class="form-label" for="mobile_portal_banner_title">Banner title</label>
            <input class="form-input" type="text" id="mobile_portal_banner_title" name="mobile_portal_banner_title" value="<?= h($settings['mobile_portal_banner_title'] ?? '') ?>">
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="mobile_portal_banner_body">Banner body</label>
            <textarea class="form-input" id="mobile_portal_banner_body" name="mobile_portal_banner_body" rows="3"><?= h($settings['mobile_portal_banner_body'] ?? '') ?></textarea>
        </div>

        <h3 class="form-section-title form-group--full">Announcements (JSON)</h3>
        <div class="form-group form-group--full">
            <label class="form-label" for="mobile_portal_announcements_json">Announcements</label>
            <textarea class="form-input" id="mobile_portal_announcements_json" name="mobile_portal_announcements_json" rows="5" style="font-family:monospace;"><?= h($settings['mobile_portal_announcements_json'] ?? '[]') ?></textarea>
            <p class="form-hint">Example: <code>[{"title":"Shift reminder","body":"Arrive 15 minutes early."}]</code></p>
        </div>

        <h3 class="form-section-title form-group--full">Help links (JSON)</h3>
        <div class="form-group form-group--full">
            <label class="form-label" for="mobile_portal_help_links_json">Help links</label>
            <textarea class="form-input" id="mobile_portal_help_links_json" name="mobile_portal_help_links_json" rows="4" style="font-family:monospace;"><?= h($settings['mobile_portal_help_links_json'] ?? '[]') ?></textarea>
            <p class="form-hint">Example: <code>[{"label":"FAQ","url":"https://olasentra.com/faq.php"}]</code></p>
        </div>

        <h3 class="form-section-title form-group--full">Contact &amp; version</h3>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label" for="mobile_portal_contact_email">Support email</label>
                <input class="form-input" type="email" id="mobile_portal_contact_email" name="mobile_portal_contact_email" value="<?= h($settings['mobile_portal_contact_email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="mobile_portal_contact_phone">Support phone</label>
                <input class="form-input" type="text" id="mobile_portal_contact_phone" name="mobile_portal_contact_phone" value="<?= h($settings['mobile_portal_contact_phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="mobile_portal_version_label">Version label</label>
                <input class="form-input" type="text" id="mobile_portal_version_label" name="mobile_portal_version_label" value="<?= h($settings['mobile_portal_version_label'] ?? '') ?>" placeholder="1.0.15">
            </div>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="mobile_portal_version_notes">Version notes</label>
            <textarea class="form-input" id="mobile_portal_version_notes" name="mobile_portal_version_notes" rows="2"><?= h($settings['mobile_portal_version_notes'] ?? '') ?></textarea>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="mobile_portal_force_update_message">Force-update message</label>
            <textarea class="form-input" id="mobile_portal_force_update_message" name="mobile_portal_force_update_message" rows="2"><?= h($settings['mobile_portal_force_update_message'] ?? '') ?></textarea>
        </div>

        <h3 class="form-section-title form-group--full">Maintenance mode</h3>
        <div class="form-group form-group--full">
            <label class="form-checkbox">
                <input type="checkbox" name="mobile_portal_maintenance_enabled" value="1"<?= ($settings['mobile_portal_maintenance_enabled'] ?? '0') === '1' ? ' checked' : '' ?>>
                <span>Block app login — show maintenance message</span>
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="mobile_portal_maintenance_message">Maintenance message</label>
            <textarea class="form-input" id="mobile_portal_maintenance_message" name="mobile_portal_maintenance_message" rows="2"><?= h($settings['mobile_portal_maintenance_message'] ?? '') ?></textarea>
        </div>

        <div class="form-actions form-actions--end">
            <button type="submit" class="btn btn--primary">Save Mobile Portal settings</button>
            <a href="<?= h($configUrl) ?>" class="btn btn--secondary" target="_blank" rel="noopener">View config JSON</a>
        </div>
    </form>
</section>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">Related controls</h2>
    </div>
    <ul style="margin:0;padding-left:1.25rem;">
        <li><a href="settings-preference-locations.php">Preference locations (Dublin, Malahide, etc.)</a></li>
        <li><a href="staff-preferences.php">Staff preferences (view / filter / export)</a></li>
        <li><a href="mobile-app-releases.php">Staff app releases (APK / AAB upload, version history, rollback)</a></li>
        <li><a href="<?= h(staffAppAndroidDownloadPageUrl($pdo)) ?>" target="_blank" rel="noopener">Public staff app download page</a></li>
        <li><a href="settings-production.php#mobile-api">Mobile API (auth, JWT, FCM)</a></li>
        <li><a href="settings-security.php">Google Maps API key (event venue map / Eircode lookup)</a></li>
        <li><a href="feature-flags.php">GPS attendance v2 feature flag</a></li>
        <li><a href="event-form.php">Event venue map, pin &amp; sign-in radius</a></li>
    </ul>
</section>

<?php
renderErpSettingsLayoutEnd();
include __DIR__ . '/../includes/admin/layout-bottom.php';
