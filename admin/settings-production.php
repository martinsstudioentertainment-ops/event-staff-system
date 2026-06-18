<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/production-readiness.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/admin/settings-handler.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';
require_once __DIR__ . '/../includes/admin-ui-settings.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/world-currencies.php';
require_once __DIR__ . '/../includes/world-locales.php';
require_once __DIR__ . '/../includes/world-timezones.php';
require_once __DIR__ . '/../includes/pwa-push.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/google-drive-oauth.php';
require_once __DIR__ . '/../includes/registration-forms.php';
require_once __DIR__ . '/../includes/staff-profile-gate.php';
require_once __DIR__ . '/../includes/staff-google-oauth.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/mobile/schema/mobile-api-schema.php';

requireAdminCapability('settings');

$pdo            = getDB();
$staffAppUrl    = getRegistrationSiteUrl($pdo) . '/staff-app.php';
$adminUser      = getAdminUser();
$postAction     = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (string) ($_POST['action'] ?? '') : '';
$settingsAction = in_array($postAction, ['commission_rates', 'invoice_payment', 'google_sheets', 'pwa_settings', 'mobile_api_settings', 'staff_google_signin', 'staff_google_signin_go_live', 'staff_profile_gate'], true)
    ? $postAction
    : 'system';
$staffNeedingProfile = countStaffNeedingProfileUpdate($pdo);
$profileGateOn       = isStaffProfileUpdateRequired($pdo);
$result         = processSettingsPost($pdo, $adminUser, $settingsAction);
$error          = $result['error'];
$success        = $result['success'];
$settings       = getAllSettings($pdo);
$system         = getSystemSettings($pdo);
$uiSettings     = getAdminUiSettings($pdo);
$uiScaleOpts    = getAdminUiScaleOptions();
$layoutOpts     = getSystemLayoutModeOptions();
$currencyOpts   = getSystemCurrencyOptions();
$dateFormatOpts = getSystemDateFormatOptions();
$languageOpts   = getSystemLanguageOptions();
$timezoneOpts   = getSystemTimezoneOptions();

$checks = getProductionReadinessChecks($pdo);
$pass   = countReadinessStatus($checks, 'pass');
$warn   = countReadinessStatus($checks, 'warn');
$fail   = countReadinessStatus($checks, 'fail');
$ready  = $fail === 0 && isProductionApp();

$pageTitle          = 'System';
$activePage         = 'settings-production';
$erpSettingsActive  = 'system';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card erp-settings-panel">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Go live</h2>
            <p class="card__subtitle">Complete the full checklist before your first real event.</p>
        </div>
        <a href="go-live.php" class="btn btn--primary">Open go live checklist →</a>
    </div>
</section>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">System Settings</h2>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="system">

        <div class="erp-settings-form__grid">
            <div class="form-group">
                <label class="form-label form-label--caps" for="layout_mode">Layout mode</label>
                <select class="form-select" id="layout_mode" name="layout_mode">
                    <?php foreach ($layoutOpts as $value => $label): ?>
                        <option value="<?= h($value) ?>"<?= $system['layout_mode'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label form-label--caps" for="compact_mode">Compact mode</label>
                <select class="form-select form-select--highlight" id="compact_mode" name="compact_mode">
                    <?php foreach ($uiScaleOpts as $value => $label): ?>
                        <option value="<?= h($value) ?>"<?= $uiSettings['ui_scale'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group form-group--full">
                <label class="form-label form-label--caps" for="timezone">Timezone</label>
                <div class="erp-combobox">
                    <span class="erp-combobox__icon" aria-hidden="true"><?= renderAdminNavIcon('search') ?></span>
                    <input class="form-input erp-combobox__input" type="text" id="timezone" value="<?= h(getWorldTimezoneOptions()[$system['timezone']] ?? $system['timezone']) ?>" list="timezone-options" autocomplete="off" required>
                    <datalist id="timezone-options">
                        <?php foreach ($timezoneOpts as $tzId => $tzLabel): ?>
                            <option value="<?= h($tzLabel) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" id="timezone_id" name="timezone" value="<?= h($system['timezone']) ?>">
                </div>
                <p class="form-hint">All IANA timezones — search by city or UTC offset (e.g. Lagos, New York, UTC+1).</p>
            </div>

            <div class="form-group form-group--full">
                <label class="form-label form-label--caps" for="currency">Currency</label>
                <div class="erp-combobox">
                    <span class="erp-combobox__icon" aria-hidden="true"><?= renderAdminNavIcon('search') ?></span>
                    <input class="form-input erp-combobox__input" type="text" id="currency" value="<?= h(getWorldCurrencyLabel($system['currency'])) ?>" list="currency-options" autocomplete="off" required>
                    <datalist id="currency-options">
                        <?php foreach ($currencyOpts as $code => $label): ?>
                            <option value="<?= h($label) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" id="currency_code" name="currency" value="<?= h($system['currency']) ?>">
                </div>
                <p class="form-hint">ISO 4217 codes worldwide — search by code or name (e.g. NGN, EUR, USD).</p>
            </div>

            <div class="form-group">
                <label class="form-label form-label--caps" for="date_format">Date format</label>
                <select class="form-select" id="date_format" name="date_format">
                    <?php foreach ($dateFormatOpts as $value => $label): ?>
                        <option value="<?= h($value) ?>"<?= $system['date_format'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group form-group--full">
                <label class="form-label form-label--caps" for="language">Language</label>
                <div class="erp-combobox">
                    <span class="erp-combobox__icon" aria-hidden="true"><?= renderAdminNavIcon('search') ?></span>
                    <input class="form-input erp-combobox__input" type="text" id="language" value="<?= h(getWorldLocaleLabel($system['language'])) ?>" list="language-options" autocomplete="off" required>
                    <datalist id="language-options">
                        <?php foreach ($languageOpts as $code => $label): ?>
                            <option value="<?= h($label) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" id="language_code" name="language" value="<?= h($system['language']) ?>">
                </div>
                <p class="form-hint">Any world language/locale — untranslated strings fall back to English.</p>
            </div>
        </div>

        <div class="form-group form-group--full erp-system-status" id="maintenance-toggles">
            <div class="erp-system-status__grid">
                <label class="erp-system-status__item">
                    <input type="checkbox" name="maintenance_mode" value="1"<?= $system['maintenance_mode'] === '1' ? ' checked' : '' ?>>
                    <span class="erp-system-status__dot"></span>
                    <span class="erp-system-status__label">Maintenance mode</span>
                </label>
                <label class="erp-system-status__item">
                    <input type="checkbox" name="admin_2fa_required" value="1"<?= $system['admin_2fa_required'] === '1' ? ' checked' : '' ?>>
                    <span class="erp-system-status__dot"></span>
                    <span class="erp-system-status__label">Login verification code (email)</span>
                </label>
                <label class="erp-system-status__item">
                    <input type="checkbox" name="activity_logging_enabled" value="1"<?= $system['activity_logging_enabled'] === '1' ? ' checked' : '' ?>>
                    <span class="erp-system-status__dot"></span>
                    <span class="erp-system-status__label">Activity logging</span>
                </label>
                <label class="erp-system-status__item">
                    <input type="checkbox" name="auto_backup_enabled" value="1"<?= $system['auto_backup_enabled'] === '1' ? ' checked' : '' ?>>
                    <span class="erp-system-status__dot"></span>
                    <span class="erp-system-status__label">Weekly auto backup</span>
                </label>
            </div>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="admin_login_otp_email">Admin login verification email</label>
            <input class="form-input" type="email" id="admin_login_otp_email" name="admin_login_otp_email" value="<?= h(getSetting($pdo, 'admin_login_otp_email', 'olabodeoluwafemi2580@gmail.com')) ?>" placeholder="olabodeoluwafemi2580@gmail.com">
            <p class="form-hint">6-digit codes are sent here when login verification is enabled. “Trust this browser” uses a 30-day cookie to skip the code on return visits.</p>
        </div>

        <div class="form-actions form-actions--end">
            <button type="submit" class="btn btn--gradient-save">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                Save Settings
            </button>
        </div>
    </form>
</section>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">Default commission rates</h2>
        <p class="card__subtitle">Used when creating commission invoices — pre-fills hourly rate per role. Amounts remain editable on each invoice.</p>
    </div>

    <form method="post" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="commission_rates">

        <div class="erp-settings-form__grid">
            <?php foreach (getStaffRolesForEvents($pdo) as $commissionRole): ?>
                <?php $rateKey = commissionRateSettingKey($commissionRole); ?>
                <div class="form-group">
                    <label class="form-label" for="<?= h($rateKey) ?>"><?= h(formatStaffRoleLabel($commissionRole, $pdo)) ?> rate / hour<?= $commissionRole === 'dsp' ? ' (' . h(getSystemCurrency($pdo)) . ')' : '' ?></label>
                    <input class="form-input" type="number" step="0.01" min="0" id="<?= h($rateKey) ?>" name="<?= h($rateKey) ?>" value="<?= h($settings[$rateKey] ?? '0') ?>">
                </div>
            <?php endforeach; ?>
            <div class="form-group">
                <label class="form-label" for="commission_rate_default">Fallback / other roles / hour</label>
                <input class="form-input" type="number" step="0.01" min="0" id="commission_rate_default" name="commission_rate_default" value="<?= h($settings['commission_rate_default'] ?? '0') ?>">
            </div>
        </div>

        <div class="form-actions form-actions--end">
            <button type="submit" class="btn btn--primary">Save commission rates</button>
        </div>
    </form>
</section>

<section class="card erp-settings-panel">
    <div class="card__header">
        <h2 class="card__title">Invoice payment details</h2>
        <p class="card__subtitle">Your company bank details — printed on commission invoices so the client knows where to pay. Staff bank IBANs are separate (for payroll only).</p>
    </div>

    <form method="post" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="invoice_payment">

        <div class="erp-settings-form__grid">
            <div class="form-group form-group--full">
                <label class="form-label" for="invoice_bank_name">Account name</label>
                <input class="form-input" type="text" id="invoice_bank_name" name="invoice_bank_name" value="<?= h($settings['invoice_bank_name'] ?? '') ?>" placeholder="e.g. Event Staff Ireland Ltd">
            </div>
            <div class="form-group form-group--full">
                <label class="form-label" for="invoice_bank_iban">Bank IBAN</label>
                <input class="form-input" type="text" id="invoice_bank_iban" name="invoice_bank_iban" value="<?= h($settings['invoice_bank_iban'] ?? '') ?>" placeholder="IE29AIBK93115212345678" autocapitalize="characters" maxlength="34">
                <p class="form-hint">IBAN with country code only — not a bank name.</p>
            </div>
            <div class="form-group">
                <label class="form-label" for="invoice_bank_bic">BIC / SWIFT</label>
                <input class="form-input" type="text" id="invoice_bank_bic" name="invoice_bank_bic" value="<?= h($settings['invoice_bank_bic'] ?? '') ?>" placeholder="Optional">
            </div>
            <div class="form-group">
                <label class="form-label" for="invoice_vat_number">VAT number</label>
                <input class="form-input" type="text" id="invoice_vat_number" name="invoice_vat_number" value="<?= h($settings['invoice_vat_number'] ?? '') ?>" placeholder="Optional">
            </div>
        </div>

        <div class="form-actions form-actions--end">
            <button type="submit" class="btn btn--primary">Save payment details</button>
        </div>
    </form>
</section>

<?php
$sa = loadGoogleServiceAccount();
$saEmail = $sa ? (string) ($sa['client_email'] ?? '') : '';
$googleOauthConnected = googleDriveOAuthConfigured($pdo);
$googleOauthSecretSaved = isGoogleOAuthClientSecretConfigured($pdo);
$googleOauthFlash = '';
$googleOauthFlashType = 'info';
if (isset($_GET['google_oauth']) && $_GET['google_oauth'] === 'connected') {
    $googleOauthFlash = (string) ($_SESSION['google_oauth_success'] ?? 'Google account connected. Run the diagnostic create test again.');
    unset($_SESSION['google_oauth_success']);
    $googleOauthFlashType = 'success';
    $googleOauthConnected = googleDriveOAuthConfigured($pdo);
} elseif (isset($_GET['google_oauth'])) {
    $oauthParam = (string) $_GET['google_oauth'];
    $googleOauthFlash = (string) ($_SESSION['google_oauth_error'] ?? '');
    unset($_SESSION['google_oauth_error']);
    if ($googleOauthFlash === '') {
        $googleOauthFlash = match ($oauthParam) {
            'invalid_state' => 'Sign-in session expired — click Connect Google account again while logged in to admin.',
            'denied'        => 'Google sign-in was cancelled. Try Connect again.',
            'no_code'       => 'Google did not return a code — check redirect URI in Google Cloud matches Settings.',
            default         => 'Google connection failed. Check Client ID, secret, and redirect URI.',
        };
    }
    $googleOauthFlashType = 'warning';
}
?>
<section class="card erp-settings-panel" id="google-sheets">
    <div class="card__header">
        <h2 class="card__title">Google Sheets sync</h2>
        <p class="card__subtitle">Each event gets its own spreadsheet named <strong>date — event name — Staff</strong> (e.g. 10/06/2026 — Nick Cave — Staff). New registrations append a row automatically.</p>
    </div>

    <?php if ($googleOauthFlash !== ''): ?>
        <div class="alert alert--<?= $googleOauthFlashType === 'success' ? 'success' : ($googleOauthFlashType === 'warning' ? 'warning' : 'info') ?> alert--visible" style="margin-bottom:1rem"><?= h($googleOauthFlash) ?></div>
    <?php endif; ?>

    <?php if ($saEmail === '' || !$googleOauthSecretSaved): ?>
        <div class="alert alert--warning alert--visible" style="margin-bottom:1rem">
            <p style="margin:0 0 0.5rem"><strong>Two different Google credentials</strong> (do not mix them up):</p>
            <ol style="margin:0 0 0 1.25rem;padding:0;font-size:0.9rem">
                <li><strong>Service account JSON file</strong> (bottom of this form) — from IAM → Service accounts → Keys → JSON. Contains <code>client_email</code> ending in <code>.iam.gserviceaccount.com</code>.</li>
                <li><strong>OAuth Client ID + secret</strong> (boxes above) — from Credentials → <strong>Web application</strong> OAuth client. Secret starts with <code>GOCSPX-</code> — type it in the password box, not in the file upload.</li>
            </ol>
            <?php if ($saEmail === ''): ?>
                <p style="margin:0.5rem 0 0"><strong>Missing:</strong> service account JSON file.</p>
            <?php endif; ?>
            <?php if (!$googleOauthSecretSaved): ?>
                <p style="margin:0.5rem 0 0"><strong>Missing:</strong> OAuth Client secret (paste GOCSPX-… and Save).</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="erp-settings-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="google_sheets">

        <div class="form-group form-group--full">
            <label class="form-label">Connect your Gmail (required for auto-create)</label>
            <p class="form-hint">
                Service accounts have <strong>no Drive storage</strong>. Sign in once with the Gmail that owns
                <strong>Event Staff Sheets</strong> so the app can copy <strong>Event Staff Template</strong> using your 15 GB.
            </p>
            <div class="form-group">
                <label class="form-label" for="google_oauth_client_id">OAuth Client ID (Web application)</label>
                <input class="form-input" type="text" id="google_oauth_client_id" name="google_oauth_client_id" value="<?= h($settings['google_oauth_client_id'] ?? '') ?>" placeholder="From Google Cloud → APIs &amp; Credentials → Web client">
            </div>
            <div class="form-group">
                <label class="form-label" for="google_oauth_client_secret">OAuth Client secret</label>
                <?php if ($googleOauthSecretSaved): ?>
                    <p class="form-hint" style="margin:0 0 0.5rem">
                        <span class="badge badge--approved">Secret saved in database</span>
                        — this page never shows it again (security). Leave the box empty unless you are replacing it.
                    </p>
                <?php endif; ?>
                <input
                    class="form-input"
                    type="password"
                    id="google_oauth_client_secret"
                    name="google_oauth_client_secret"
                    value=""
                    autocomplete="off"
                    placeholder="<?= $googleOauthSecretSaved ? 'Leave empty to keep current secret' : 'Paste GOCSPX-… from Web client 1, then Save below' ?>"
                >
                <?php if (!$googleOauthSecretSaved): ?>
                    <p class="form-hint">Google Cloud → Credentials → <strong>Web client 1</strong> → copy Client secret → paste here → click <strong>Save Google Sheets settings</strong> (not Connect yet).</p>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label" for="google_oauth_redirect_uri">Redirect URI override (only if Google still says invalid)</label>
                <input class="form-input" type="text" id="google_oauth_redirect_uri" name="google_oauth_redirect_uri" value="<?= h($settings['google_oauth_redirect_uri'] ?? '') ?>" placeholder="<?= h(googleDriveOAuthRedirectUri($pdo)) ?>">
            </div>
            <p class="form-hint">
                In Google Cloud → your <strong>Web client</strong> → add these <strong>exactly</strong> (no extra <code>/admin</code> if you use the admin subdomain):
            </p>
            <ul class="form-hint" style="margin:0.25rem 0 0 1rem">
                <li>Authorized JavaScript origins: <code><?= h(googleDriveOAuthJavaScriptOrigin($pdo)) ?></code></li>
                <li>Authorized redirect URIs: <code><?= h(googleDriveOAuthRedirectUri($pdo)) ?></code></li>
            </ul>
            <p class="form-hint">OAuth consent screen → add your Gmail as a <strong>Test user</strong> if the app is in Testing mode.</p>
            <p class="form-hint">After a code update, click <strong>Connect Google account</strong> again so Google grants Drive access to copy your template.</p>
            <p class="form-hint">
                <strong>Status:</strong>
                Client ID <?= trim($settings['google_oauth_client_id'] ?? '') !== '' ? 'saved' : 'missing' ?> ·
                Client secret <?= $googleOauthSecretSaved ? 'saved' : 'missing' ?> ·
                Gmail <?= $googleOauthConnected ? 'connected' : 'not connected' ?>
            </p>
            <?php if ($googleOauthSecretSaved && !$googleOauthConnected): ?>
                <p class="form-hint">Secret is saved — click <strong>Connect Google account</strong> below (no need to re-paste the secret).</p>
            <?php elseif (!$googleOauthSecretSaved): ?>
                <p class="form-hint">Paste the secret and <strong>Save Google Sheets settings</strong> before Connect — Connect does not store the secret.</p>
            <?php endif; ?>
        </div>

        <label class="form-checkbox" style="margin-bottom:1rem;">
            <input type="checkbox" name="google_sheets_sync_enabled" value="1"<?= ($settings['google_sheets_sync_enabled'] ?? '0') === '1' ? ' checked' : '' ?>>
            <span>Enable live sync to Google Sheets (approved staff only — added on approval, removed if rejected)</span>
        </label>

        <div class="form-group form-group--full">
            <label class="form-label" for="google_sheets_drive_folder_id">Drive folder ID (required for auto-create)</label>
            <input class="form-input" type="text" id="google_sheets_drive_folder_id" name="google_sheets_drive_folder_id" value="<?= h($settings['google_sheets_drive_folder_id'] ?? '') ?>" placeholder="Paste folder URL or ID from Google Drive">
            <p class="form-hint">
                In <strong>your Gmail</strong> Google Drive: create a folder (e.g. “Event Staff Sheets”) → <strong>Share</strong> with
                <code><?= h($saEmail !== '' ? $saEmail : 'service-account@project.iam.gserviceaccount.com') ?></code> as <strong>Editor</strong>
                → open the folder → copy the ID from the browser URL (<code>…/folders/<strong>THIS_PART</strong></code>) → paste here → Save.
            </p>
            <p class="form-hint">
                Inside that folder, add one blank Google Sheet named <strong>Event Staff Template</strong> (the system copies it for each event — required for personal Gmail).
            </p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="google_sheets_template_id">Template sheet URL (optional)</label>
            <input class="form-input" type="text" id="google_sheets_template_id" name="google_sheets_template_id" value="<?= h($settings['google_sheets_template_id'] ?? '') ?>" placeholder="Leave blank if sheet is named Event Staff Template in the folder">
            <p class="form-hint">Only needed if the template has a different name. Paste the sheet URL from inside your shared folder.</p>
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="google_sheets_share_with_email">Share new auto-created sheets with (Gmail)</label>
            <input class="form-input" type="email" id="google_sheets_share_with_email" name="google_sheets_share_with_email" value="<?= h($settings['google_sheets_share_with_email'] ?? '') ?>" placeholder="you@olasentra.com">
            <p class="form-hint">Optional. Also shares each new sheet with this address as Editor (in addition to the folder above).</p>
        </div>

        <div class="form-group">
            <label class="form-label" for="google_sheets_default_tab">Tab name on auto-created sheets</label>
            <input class="form-input" type="text" id="google_sheets_default_tab" name="google_sheets_default_tab" value="<?= h($settings['google_sheets_default_tab'] ?? 'Registrations') ?>" maxlength="100">
        </div>

        <p class="form-hint">
            Service account:
            <?php if ($saEmail !== ''): ?>
                <strong><?= h($saEmail) ?></strong> — used for API sync; new sheets are copied with your connected Gmail into the shared folder.
            <?php else: ?>
                <strong>Not uploaded</strong> — upload JSON from Google Cloud Console below.
            <?php endif; ?>
        </p>

        <div class="form-group form-group--full">
            <label class="form-label" for="google_service_account">Service account JSON</label>
            <input class="form-input" type="file" id="google_service_account" name="google_service_account" accept=".json,application/json">
            <p class="form-hint">
                Google Cloud → <strong>IAM &amp; Admin → Service accounts</strong> → select the robot account → <strong>Keys → Add key → JSON</strong>.
                Not the OAuth “Download JSON” from Credentials — that file will not work here.
            </p>
            <p class="form-hint"><a href="google-sheets-diagnostic.php"><strong>Google Sheets diagnostic</strong></a> — if bulk create fails, run this after deploy.</p>
        </div>

        <div class="form-actions form-actions--end" style="flex-wrap:wrap;gap:0.5rem">
            <button type="submit" class="btn btn--primary">Save Google Sheets settings</button>
            <?php if (trim($settings['google_oauth_client_id'] ?? '') !== '' && $googleOauthSecretSaved): ?>
                <a href="<?= h(googleDriveOAuthAuthorizeUrl($pdo)) ?>" class="btn btn--secondary">Connect Google account</a>
            <?php endif; ?>
            <a href="google-sheets-diagnostic.php" class="btn btn--secondary">Diagnostic</a>
        </div>
    </form>
</section>

<section class="card erp-settings-panel" id="staff-google-signin">
    <div class="card__header">
        <h2 class="card__title">Staff app — Google (Gmail) sign-in</h2>
        <p class="card__subtitle" style="margin-top:0.35rem;"><a href="staff-go-live.php"><strong>Easy setup guide (4 steps) →</strong></a></p>
        <p class="card__subtitle">Uses the same OAuth Client ID / secret as Google Sheets above. Staff sign in with Gmail — free, no extra Google billing. Keeps them signed in on their phone for GPS shift tracking.</p>
    </div>

    <?php if (isStaffGoogleSigninEnabled($pdo)): ?>
        <div class="alert alert--success alert--visible">
            <strong>On.</strong> Staff use <em>Continue with Google</em> on
            <a href="<?= h($staffAppUrl) ?>" target="_blank" rel="noopener">staff-app.php</a>.
            <?php if (isStaffGoogleSigninRequired($pdo)): ?>
                Email + PPS sign-in is hidden (Google only).
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="alert alert--visible" style="background:#f1f5f9;border-color:#cbd5e1;color:#334155;">
            Off — staff still use email + last 4 of PPS on the staff app.
        </div>
    <?php endif; ?>

    <p class="form-hint form-group--full">
        <strong>Before enabling — Google Cloud Console</strong> (same Web client as admin Sheets): add
        <code><?= h(staffGoogleOAuthRedirectUri($pdo)) ?></code> under <strong>Authorized redirect URIs</strong>
        and <code><?= h(rtrim(getRegistrationSiteUrl($pdo), '/')) ?></code> under <strong>Authorized JavaScript origins</strong>.
    </p>

    <form method="post" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="staff_google_signin">

        <label class="form-checkbox" style="margin-bottom:0.75rem;">
            <input type="checkbox" name="staff_google_signin_enabled" value="1"<?= ($settings['staff_google_signin_enabled'] ?? '0') === '1' ? ' checked' : '' ?>>
            <span>Enable Google sign-in for staff app</span>
        </label>
        <label class="form-checkbox" style="margin-bottom:1rem;">
            <input type="checkbox" name="staff_google_signin_required" value="1"<?= ($settings['staff_google_signin_required'] ?? '0') === '1' ? ' checked' : '' ?>>
            <span>Require Google — hide email + PPS form (staff must use Gmail that matches registration)</span>
        </label>
        <div class="form-group form-group--full">
            <label class="form-label" for="staff_google_oauth_redirect_uri">Staff redirect URI override (optional)</label>
            <input class="form-input" type="text" id="staff_google_oauth_redirect_uri" name="staff_google_oauth_redirect_uri" value="<?= h($settings['staff_google_oauth_redirect_uri'] ?? '') ?>" placeholder="<?= h(staffGoogleOAuthRedirectUri($pdo)) ?>">
        </div>
        <div class="form-actions form-actions--end" style="flex-wrap:wrap;gap:0.5rem;">
            <button type="submit" class="btn btn--primary">Save staff Google sign-in</button>
            <?php if (isStaffGoogleSigninConfigured($pdo)): ?>
                <button type="submit" class="btn btn--secondary" formaction="settings-production.php" name="action" value="staff_google_signin_go_live"
                    onclick="return confirm('Turn ON Gmail sign-in and REQUIRE it for the staff app? Venue QR still uses email + PPS. Add the redirect URI in Google Cloud first.');">
                    Enable &amp; require Gmail (go live)
                </button>
            <?php endif; ?>
            <a href="staff-google-signin-diagnostic.php" class="btn btn--secondary">Diagnostic</a>
        </div>
    </form>
</section>

<section class="card erp-settings-panel" id="staff-profile-gate">
    <div class="card__header">
        <h2 class="card__title">Staff profile update (mobile app)</h2>
        <p class="card__subtitle">Force existing staff to verify email + date of birth and complete PSA, bank, and address details before they can use the staff app, check in, or view status.</p>
    </div>

    <?php if ($profileGateOn): ?>
        <div class="alert alert--warning alert--visible">
            <strong>Active.</strong> <?= (int) $staffNeedingProfile ?> staff member(s) still need to update their profile on
            <a href="<?= h($staffAppUrl) ?>" target="_blank" rel="noopener">staff-app.php</a>.
        </div>
    <?php else: ?>
        <div class="alert alert--visible" style="background:#f1f5f9;border-color:#cbd5e1;color:#334155;">
            Off — staff can use the app without being prompted to update their profile.
        </div>
    <?php endif; ?>

    <form method="post" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="staff_profile_gate">

        <label class="form-checkbox" style="margin-bottom:1rem;">
            <input type="checkbox" name="staff_profile_update_required" value="1"<?= $profileGateOn ? ' checked' : '' ?>>
            <span>Require staff profile update on mobile app</span>
        </label>

        <div class="form-actions form-actions--end" style="flex-wrap:wrap;gap:0.5rem">
            <button type="submit" class="btn btn--primary">Save</button>
            <button type="submit" name="activate_staff_profile_gate" value="1" class="btn btn--secondary">Activate now — reset all staff &amp; require update</button>
            <?php if ($profileGateOn): ?>
                <button type="submit" name="deactivate_staff_profile_gate" value="1" class="btn btn--secondary">Turn off</button>
            <?php endif; ?>
        </div>
        <p class="form-hint" style="margin-top:0.75rem;">Use <strong>Activate now</strong> for a one-time rollout. After each person saves their profile they are not asked again — they only sign in with email + date of birth to view shifts. Turn this <strong>off</strong> when everyone has updated.</p>
    </form>
</section>

<section class="card erp-settings-panel" id="mobile-api">
    <div class="card__header">
        <h2 class="card__title">Mobile API (Native Android)</h2>
        <p class="card__subtitle">REST API at <code>/api/mobile/v1/</code> for the future native staff app. Web staff app (PWA/TWA) is unchanged.</p>
    </div>

    <?php if (mobileApiIsEnabled($pdo)): ?>
        <div class="alert alert--success alert--visible">
            <strong>Enabled.</strong> Auth endpoints accept requests when JWT secret is set.
            OpenAPI: <code>docs/api/mobile/openapi.yaml</code>
        </div>
    <?php else: ?>
        <div class="alert alert--visible" style="background:#f1f5f9;border-color:#cbd5e1;color:#334155;">
            Disabled — <code>GET /api/mobile/v1/config</code> still works; auth returns 503 until enabled.
        </div>
    <?php endif; ?>

    <p class="form-hint form-group--full">
        JWT secret:
        <?php if (trim($settings['mobile_jwt_secret'] ?? '') !== ''): ?>
            <strong>Configured</strong> (<?= h(substr($settings['mobile_jwt_secret'], 0, 8)) ?>…)
        <?php else: ?>
            <strong>Not set</strong> — generate before enabling auth.
        <?php endif; ?>
        · Base URL: <code><?= h(rtrim(getRegistrationSiteUrl($pdo), '/')) ?>/api/mobile/v1</code>
    </p>

    <form method="post" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="mobile_api_settings">

        <label class="form-checkbox" style="margin-bottom:0.75rem;">
            <input type="checkbox" name="mobile_api_enabled" value="1"<?= ($settings['mobile_api_enabled'] ?? '0') === '1' ? ' checked' : '' ?>>
            <span>Enable Mobile API (auth, shifts, check-in — Sprint 1+: auth only until later sprints)</span>
        </label>

        <div class="form-group">
            <label class="form-label" for="mobile_min_app_version">Minimum app version</label>
            <input class="form-input" type="text" id="mobile_min_app_version" name="mobile_min_app_version" value="<?= h($settings['mobile_min_app_version'] ?? '1.0.0') ?>" maxlength="20">
        </div>

        <div class="form-group">
            <label class="form-label" for="mobile_jwt_access_ttl">Access token TTL (seconds)</label>
            <input class="form-input" type="number" id="mobile_jwt_access_ttl" name="mobile_jwt_access_ttl" value="<?= h($settings['mobile_jwt_access_ttl'] ?? '900') ?>" min="60" max="3600">
        </div>

        <div class="form-group">
            <label class="form-label" for="mobile_jwt_refresh_days">Refresh token lifetime (days)</label>
            <input class="form-input" type="number" id="mobile_jwt_refresh_days" name="mobile_jwt_refresh_days" value="<?= h($settings['mobile_jwt_refresh_days'] ?? '90') ?>" min="1" max="365">
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="fcm_project_id">Firebase project ID (Sprint 5+)</label>
            <input class="form-input" type="text" id="fcm_project_id" name="fcm_project_id" value="<?= h($settings['fcm_project_id'] ?? '') ?>" placeholder="olasentra-staff">
        </div>

        <div class="form-group form-group--full">
            <label class="form-label" for="fcm_service_account_path">FCM service account path on server</label>
            <input class="form-input" type="text" id="fcm_service_account_path" name="fcm_service_account_path" value="<?= h($settings['fcm_service_account_path'] ?? '') ?>" placeholder="storage/firebase/service-account.json">
            <p class="form-hint">Do not commit this file. Upload via FTP/cPanel separately.</p>
        </div>

        <div class="form-actions form-actions--end" style="flex-wrap:wrap;gap:0.5rem;">
            <button type="submit" class="btn btn--primary">Save Mobile API settings</button>
            <a href="mobile-api-qa.php" class="btn btn--secondary">Mobile API QA (temporary)</a>
            <button type="submit" name="generate_mobile_jwt_secret" value="1" class="btn btn--secondary"
                onclick="return confirm('Generate a new JWT secret? All existing mobile tokens will stop working.');">
                Generate JWT secret
            </button>
        </div>
    </form>
</section>

<section class="card erp-settings-panel" id="pwa-push">
    <div class="card__header">
        <h2 class="card__title">PWA &amp; push notifications</h2>
        <p class="card__subtitle">Staff install the app from <a href="<?= h($staffAppUrl) ?>" target="_blank" rel="noopener">staff-app.php</a>. Push alerts when registration is approved (requires HTTPS + composer).</p>
    </div>

    <form method="post" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="pwa_settings">

        <label class="form-checkbox" style="margin-bottom:1rem;">
            <input type="checkbox" name="pwa_push_enabled" value="1"<?= ($settings['pwa_push_enabled'] ?? '1') === '1' ? ' checked' : '' ?>>
            <span>Send push when staff are approved</span>
        </label>

        <p class="form-hint">
            VAPID keys:
            <?php if (isPwaPushConfigured($pdo)): ?>
                <strong>Configured</strong> (public key <?= h(substr(getVapidPublicKey($pdo), 0, 12)) ?>…)
            <?php else: ?>
                <strong>Not set</strong> — generate keys below.
            <?php endif; ?>
            · Composer library: <?= is_file(dirname(__DIR__) . '/vendor/autoload.php') ? 'installed' : 'run <code>composer install</code> in project root' ?>.
        </p>

        <div class="form-actions form-actions--end">
            <button type="submit" class="btn btn--primary">Save PWA settings</button>
            <button type="submit" name="generate_vapid" value="1" class="btn btn--secondary">Generate VAPID keys</button>
        </div>
    </form>
</section>

<details class="erp-settings-advanced card">
    <summary class="erp-settings-advanced__summary">Production readiness (<?= $pass ?> passed · <?= $warn ?> warnings · <?= $fail ?> must fix)</summary>
    <div class="erp-settings-advanced__body">
        <?php if ($ready): ?>
            <div class="alert alert--success alert--visible">All critical checks passed.</div>
        <?php elseif ($fail > 0): ?>
            <div class="alert alert--error alert--visible">Fix failed items below before going live.</div>
        <?php else: ?>
            <div class="alert alert--warning alert--visible">Complete warnings and set APP_ENV to production.</div>
        <?php endif; ?>
        <div class="readiness-list">
            <?php foreach ($checks as $check): ?>
                <article class="readiness-item readiness-item--<?= h($check['status']) ?>">
                    <div class="readiness-item__head">
                        <span class="readiness-item__badge"><?= h(strtoupper($check['status'])) ?></span>
                        <h3 class="readiness-item__title"><?= h($check['label']) ?></h3>
                    </div>
                    <p class="readiness-item__detail"><?= h($check['detail']) ?></p>
                    <?php if (!empty($check['fix_url'])): ?>
                        <a href="<?= h($check['fix_url']) ?>" class="readiness-item__link">Fix in admin →</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</details>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
<script>
(function () {
    function bindCombobox(textInputId, hiddenInputId, map) {
        var textInput = document.getElementById(textInputId);
        var hiddenInput = document.getElementById(hiddenInputId);
        if (!textInput || !hiddenInput) return;

        function sync() {
            var val = textInput.value.trim();
            Object.keys(map).forEach(function (code) {
                if (val === code || val === map[code]) {
                    hiddenInput.value = code;
                    textInput.removeAttribute('name');
                }
            });
        }

        textInput.addEventListener('change', sync);
        textInput.addEventListener('blur', sync);
        return sync;
    }

    var currencyMap = <?= json_encode($currencyOpts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var timezoneMap = <?= json_encode($timezoneOpts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var languageMap = <?= json_encode($languageOpts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    var syncCurrency = bindCombobox('currency', 'currency_code', currencyMap);
    var syncTimezone = bindCombobox('timezone', 'timezone_id', timezoneMap);
    var syncLanguage = bindCombobox('language', 'language_code', languageMap);

    document.querySelector('.erp-settings-form')?.addEventListener('submit', function () {
        syncCurrency && syncCurrency();
        syncTimezone && syncTimezone();
        syncLanguage && syncLanguage();

        if (document.getElementById('currency_code')?.value === '') {
            document.getElementById('currency')?.setAttribute('name', 'currency');
        }
        if (document.getElementById('timezone_id')?.value === '') {
            document.getElementById('timezone')?.setAttribute('name', 'timezone');
        }
        if (document.getElementById('language_code')?.value === '') {
            document.getElementById('language')?.setAttribute('name', 'language');
        }
    });
})();
</script>
