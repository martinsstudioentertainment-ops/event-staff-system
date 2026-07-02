<?php
require_once __DIR__ . '/config.php';
initSecureSession();
require_once __DIR__ . '/includes/events-repository.php';
require_once __DIR__ . '/includes/registration-options-repository.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/registration-forms.php';
require_once __DIR__ . '/includes/website-content.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/email-copy.php';
require_once __DIR__ . '/includes/staff-psa.php';
require_once __DIR__ . '/includes/phone-numbers.php';
require_once __DIR__ . '/includes/components/phone-input.php';

require_once __DIR__ . '/includes/global-public-site.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';
require_once __DIR__ . '/includes/feature-flags.php';
require_once __DIR__ . '/includes/registration-analytics.php';
require_once __DIR__ . '/includes/public/registration-wizard-shell.php';
require_once __DIR__ . '/includes/staff-google-oauth.php';
require_once __DIR__ . '/includes/registration-google-gate.php';
require_once __DIR__ . '/includes/registration-returning-profile.php';

$frontendEvents = [];
$siteName       = 'Event Staff System';
$pdo            = null;

try {
    $pdo            = getDB();
    bootstrapAppLocale($pdo);
    enforceMaintenanceMode($pdo);
    $frontendEvents = getActiveEventsForFrontend($pdo);
    $siteName       = getGlobalPublicSiteConfig($pdo)['siteName'];
} catch (Throwable $e) {
    $frontendEvents = [];
}

$allForms     = $pdo ? getEnabledRegistrationForms($pdo) : getDefaultRegistrationForms();
$formSlug     = strtolower(trim((string) ($_GET['form'] ?? '')));
$linkedForm   = ($formSlug !== '' && $pdo) ? getRegistrationForm($pdo, $formSlug) : null;
$lockFormType = $linkedForm !== null;

$old          = $_SESSION['registration_old'] ?? [];
$serverErrors = $_SESSION['registration_errors'] ?? [];
$returnEmail         = trim((string) ($old['email'] ?? ''));
$defaultPhoneCountry = $pdo ? resolvePhoneCountryIsoFromRequest($pdo) : defaultPhoneCountryIso();
unset($_SESSION['registration_old'], $_SESSION['registration_errors']);

if ($formSlug !== '' && $linkedForm === null) {
    $serverErrors['form_slug'] = 'This registration link is no longer available. Please choose a role below.';
}

function old(string $key, string $default = ''): string
{
    global $old;
    return htmlspecialchars((string) ($old[$key] ?? $default), ENT_QUOTES, 'UTF-8');
}

function checked(string $key, string $value): string
{
    global $old;
    return (($old[$key] ?? '') === $value) ? ' checked' : '';
}

function wiz_step_open(int $step): void
{
    global $wizardV2Enabled;
    if (!$wizardV2Enabled) {
        return;
    }
    $titles = [
        1 => 'Welcome - choose your role',
        2 => 'Pick your gigs',
        3 => 'Your email',
        4 => 'About you',
        5 => 'Contact',
        6 => 'Payroll details',
        7 => 'PSA compliance',
        8 => 'Review & submit',
    ];
    echo '<div class="reg-wizard__step" data-step="' . $step . '" hidden>';
    echo '<h3 class="reg-wizard__step-title">' . h($titles[$step] ?? 'Step ' . $step) . '</h3>';
}

function wiz_step_close(): void
{
    global $wizardV2Enabled;
    if ($wizardV2Enabled) {
        echo '</div>';
    }
}

$registeredCount = isset($_GET['registered']) ? max(1, (int) $_GET['registered']) : 0;
$flash = '';
if ($registeredCount > 0) {
    $flash = 'success';
} elseif (isset($_GET['error'])) {
    $flash = $_GET['error'] === 'db' ? 'db' : 'validation';
}

$selectedEvents = $old['event_ids'] ?? [];
if (!is_array($selectedEvents)) {
    $selectedEvents = $selectedEvents !== '' ? [$selectedEvents] : [];
}
$selectedVenueId = (int) ($old['venue_id'] ?? 0);
$selectedEventsJson = json_encode(array_values(array_map('intval', $selectedEvents)));

$selectedFormSlug = (string) ($old['form_slug'] ?? '');
if ($selectedFormSlug === '' && $lockFormType) {
    $selectedFormSlug = $formSlug;
}
if ($selectedFormSlug === '') {
    $firstSlug = array_key_first($allForms);
    $selectedFormSlug = is_string($firstSlug) ? $firstSlug : 'dsp';
}

$selectedForm = $allForms[$selectedFormSlug] ?? null;
$lockedRole   = $lockFormType
    ? normalizeStaffRole((string) $linkedForm['staff_role'])
    : normalizeStaffRole((string) ($old['staff_role'] ?? ($selectedForm['staff_role'] ?? 'dsp')));

if (($old['staff_role'] ?? '') === '' && $lockedRole !== '') {
    $old['staff_role'] = $lockedRole;
}
if (($old['form_slug'] ?? '') === '') {
    $old['form_slug'] = $selectedFormSlug;
}

$registrationRequiresPsa = staffRoleRequiresPsa(
    $lockFormType
        ? $lockedRole
        : normalizeStaffRole((string) ($selectedForm['staff_role'] ?? 'dsp'))
);

$registrationVerificationRequired = $pdo && isRegistrationVerificationRequired($pdo);
$registrationGoogleRequired       = $pdo && isStaffGoogleSigninRequired($pdo);
$registrationVerifiedEmail          = $pdo ? getRegistrationVerifiedEmail() : null;
$registrationGoogleEmail            = $registrationVerifiedEmail;
$registrationGoogleError    = trim((string) ($_SESSION['registration_google_error'] ?? ''));
unset($_SESSION['registration_google_error']);
$showRegistrationGoogleGate = $registrationVerificationRequired
    && $registrationVerifiedEmail === null
    && $registeredCount === 0
    && $flash !== 'success';

if ($registrationVerificationRequired && $registrationVerifiedEmail === null) {
    $old['email']  = '';
    $returnEmail   = '';
}

if ($registrationVerifiedEmail !== null) {
    $old['email'] = $registrationVerifiedEmail;
    if ($returnEmail === '') {
        $returnEmail = $registrationVerifiedEmail;
    }
    if ($pdo instanceof PDO) {
        $old = applyReturningRegistrantPrefill($pdo, $registrationVerifiedEmail, $old);
    }
}

$pageTitle = $showRegistrationGoogleGate
    ? 'Pick available shift'
    : ($linkedForm
        ? (string) ($linkedForm['title'] ?? 'Pick available shift')
        : 'Pick available shift');
$pageSubtitle = $showRegistrationGoogleGate
    ? (function () use ($pdo): string {
        if (!$pdo instanceof PDO) {
            return 'Verify your email first, choose an open shift, then complete your details.';
        }
        $opts = registrationIdentityGateOptions($pdo);
        if (!empty($opts['dual'])) {
            return 'Verify with Google or email code, choose an open shift, then complete your details.';
        }
        if (!empty($opts['google_required']) || (!empty($opts['google']) && empty($opts['email']))) {
            return 'Sign in with Google, choose an open shift, then complete your details.';
        }

        return 'Verify your email first, choose an open shift, then complete your details.';
    })()
    : ($linkedForm
        ? (string) ($linkedForm['subtitle'] ?? '')
        : 'Choose an open shift, then complete your PSA and payroll details.');

$showNotice = $pdo && isWebsiteNoticeEnabled($pdo);

$wizardV2Enabled = isFeatureEnabled($pdo, 'feature_registration_wizard_v2');
$analyticsSessionId = '';
if ($wizardV2Enabled) {
    if (empty($_SESSION['registration_analytics_sid'])) {
        $_SESSION['registration_analytics_sid'] = createRegistrationAnalyticsSessionId();
    }
    $analyticsSessionId = (string) $_SESSION['registration_analytics_sid'];
}

$assetBase     = '';
/** Olasentra v3 brand — aligned with staff login (Phase 11). */
$themeColor    = '#F58220';
$themeCategory = 'events';
require_once __DIR__ . '/includes/theme.php';
if ($pdo) {
    $themeCategory = getThemeCategory($pdo);
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

?>
<!DOCTYPE html>
<html lang="<?= h(getAppLocale()) ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php
    require_once __DIR__ . '/includes/share-meta.php';
    require_once __DIR__ . '/includes/rich-text.php';
    $shareDesc = $pageSubtitle !== '' ? plainTextFromRich($pageSubtitle) : 'Register for event staff work with ' . $siteName;
    renderShareMeta([
        'title'       => $pageTitle . ' | ' . $siteName,
        'description' => $shareDesc,
        'url'         => getRegistrationFormUrl($pdo, $formSlug !== '' && $linkedForm ? $formSlug : null),
        'site_name'   => $siteName,
    ], $pdo);
    ?>
    <title><?= h($pageTitle) ?> | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <?php if ($showNotice): ?>
    <link rel="stylesheet" href="assets/css/site-notice.css">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/registration-compact.css?v=<?= is_file(__DIR__ . '/assets/css/registration-compact.css') ? (string) filemtime(__DIR__ . '/assets/css/registration-compact.css') : '1' ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/registration-v3.css?v=<?= is_file(__DIR__ . '/assets/css/registration-v3.css') ? (string) filemtime(__DIR__ . '/assets/css/registration-v3.css') : '1' ?>">
    <?php if ($wizardV2Enabled): ?>
    <link rel="stylesheet" href="assets/css/registration-wizard.css?v=<?= is_file(__DIR__ . '/assets/css/registration-wizard.css') ? (string) filemtime(__DIR__ . '/assets/css/registration-wizard.css') : '1' ?>">
    <?php endif; ?>
</head>
<body class="staff-public-shell staff-public-shell--event-ops registration-page registration-page--compact registration-page--v3<?= $wizardV2Enabled ? ' registration-page--wizard' : '' ?><?= $registrationVerifiedEmail !== null ? ' registration-page--google-verified' : '' ?><?= $registrationGoogleRequired ? ' registration-page--google-required' : '' ?><?= !$registrationRequiresPsa ? ' registration-page--no-psa' : '' ?>" data-registration-page="true" data-pwa-install="1" data-theme-category="<?= h($themeCategory) ?>" data-backend-submit="true" data-flash="<?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>" data-registered-count="<?= $registeredCount ?>" data-site-name="<?= h($siteName) ?>" data-locked-role="<?= h($lockFormType ? $lockedRole : '') ?>" data-psa-required="<?= $registrationRequiresPsa ? '1' : '0' ?>" data-roles-on-shift-label="<?= h(t('roles_on_shift')) ?>" data-wizard-mode="<?= $wizardV2Enabled ? '1' : '0' ?>" data-wizard-analytics="<?= $wizardV2Enabled ? '1' : '0' ?>" data-analytics-session="<?= h($analyticsSessionId) ?>" data-analytics-csrf="<?= h(csrfToken()) ?>" data-analytics-form-slug="<?= h($formSlug !== '' && $linkedForm ? $formSlug : $selectedFormSlug) ?>" data-registration-google-email="<?= h($registrationVerifiedEmail ?? '') ?>" data-shift-first-flow="<?= ($registrationVerificationRequired || $registrationVerifiedEmail !== null) ? '1' : '0' ?>" data-google-registration-required="<?= $registrationGoogleRequired ? '1' : '0' ?>"<?= ($wizardV2Enabled && !empty($serverErrors)) ? ' data-server-error-restore="1"' : '' ?>>
    <?php renderStaffPublicBackground(true); ?>

    <?php
    renderStaffFlashBroadcast($pdo);

    renderStaffPublicHeader($pdo, $siteName, [
        'language_switcher' => $pdo !== null,
        'lang_query'        => $formSlug !== '' ? 'form=' . urlencode($formSlug) : '',
        'home_url'          => 'index.php',
        'theme_toggle'      => false,
    ]);
    ?>

    <?php if ($showNotice): ?>
        <?php $web = ['pdo' => $pdo, 'notice_variant' => 'scroll']; include __DIR__ . '/includes/public/site-notice.php'; ?>
    <?php endif; ?>

    <main class="registration-page__wrap staff-public-main">
        <?php
        renderStaffPublicHero([
            'eyebrow' => $linkedForm ? (string) ($linkedForm['short_label'] ?? 'Registration') : 'PSA security | DSP & Static',
            'title'   => $pageTitle,
            'lead'    => renderRichText($pageSubtitle),
        ]);
        ?>

        <?php if (!$showRegistrationGoogleGate && (($registrationGoogleEmail ?? '') !== '' || $returnEmail !== '')): ?>
        <p class="form-hint" style="text-align:center;margin:0 0 1rem;max-width:36rem;margin-left:auto;margin-right:auto;">
            Signed in as <strong><?= h($registrationGoogleEmail ?? $returnEmail) ?></strong>.
            Already on the roster? <a href="staff-app.php"><strong>Staff app</strong></a>.
        </p>
        <?php endif; ?>

        <?php if ($showRegistrationGoogleGate): ?>
            <?php renderRegistrationGoogleGate($pdo, $formSlug !== '' && $linkedForm ? $formSlug : $selectedFormSlug, $registrationGoogleError); ?>
        <?php else: ?>
        <section class="card staff-public-card staff-public-card--no-inner-title">
            <p class="registration-mobile-title"><?= h($pageTitle) ?></p>
            <div class="card__header" aria-hidden="true">
                <h2 class="card__title"><?= h($pageTitle) ?></h2>
            </div>

            <div id="form-alert" class="alert" role="alert"></div>

            <form id="registration-form" action="submit.php" method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <?php if ($registrationVerifiedEmail !== null): ?>
                    <input type="hidden" name="registration_verified_email" id="registration_verified_email" value="<?= h($registrationVerifiedEmail) ?>">
                    <input type="hidden" name="registration_verified_google_email" id="registration_verified_google_email" value="<?= h($registrationVerifiedEmail) ?>">
                <?php endif; ?>
                <div class="form-grid">

                    <?php if ($wizardV2Enabled): ?>
                        <?php renderRegistrationWizardShell(count($frontendEvents)); ?>
                    <?php endif; ?>

                    <?php wiz_step_open(1); ?>
                    <?php if ($wizardV2Enabled): ?>
                        <div id="reg-wizard-resume-prompt" class="reg-resume-prompt" hidden></div>
                    <?php endif; ?>
                    <?php if (!$lockFormType): ?>
                    <div class="form-group form-group--full registration-role-field">
                        <label class="form-label form-label--required" for="form_slug"><?= h(t('your_role')) ?></label>
                        <select class="form-select" id="form_slug" name="form_slug" required>
                            <?php foreach ($allForms as $slug => $form): ?>
                                <?php if (empty($form['enabled'])) continue; ?>
                                <?php
                                $roleVal = normalizeStaffRole((string) ($form['staff_role'] ?? $slug));
                                $selected = ($old['form_slug'] ?? $selectedFormSlug) === $slug;
                                $roleHint = trim((string) ($form['role_hint'] ?? ''));
                                $roleDetail = $roleHint !== '' ? $roleHint : match ($roleVal) {
                                    'steward' => t('registering_as_steward_detail'),
                                    'static'  => t('registering_as_static_detail'),
                                    'both'    => t('registering_as_both_detail'),
                                    default   => t('registering_as_dsp_detail'),
                                };
                                ?>
                                <option value="<?= h($slug) ?>" data-role="<?= h($roleVal) ?>" data-label="<?= h((string) ($form['label'] ?? $slug)) ?>" data-detail="<?= h($roleDetail) ?>"<?= $selected ? ' selected' : '' ?>><?= h((string) ($form['label'] ?? $slug)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="form-error" id="form_slug-error"></span>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="form_slug" value="<?= h($formSlug) ?>">
                    <?php endif; ?>
                        <input type="hidden" name="staff_role" id="staff_role" value="<?= h($lockedRole) ?>">
                    <?php wiz_step_close(); ?>

                    <?php if ($registrationVerifiedEmail !== null): ?>
                        <input type="hidden" name="email" id="email" value="<?= old('email') ?>">
                    <?php elseif (!$registrationVerificationRequired): ?>
                    <?php wiz_step_open(3); ?>
                    <?php if ($wizardV2Enabled): ?>
                        <div id="reg-returning-panel" class="reg-returning-panel" hidden aria-live="polite"></div>
                    <?php endif; ?>
                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="email"><?= h(t('email_address')) ?></label>
                        <input class="form-input" type="email" id="email" name="email" value="<?= old('email') ?>" placeholder="you@example.com" autocomplete="email">
                        <p class="form-hint"><?= h(t('email_hint')) ?></p>
                        <span class="form-error" id="email-error"></span>
                    </div>
                    <?php wiz_step_close(); ?>
                    <?php endif; ?>

                    <?php wiz_step_open(4); ?>
                    <h3 class="form-section-title"><?= h(t('personal_details')) ?></h3>

                    <div class="form-group">
                        <label class="form-label form-label--required" for="surname"><?= h(t('surname')) ?></label>
                        <input class="form-input" type="text" id="surname" name="surname" value="<?= old('surname') ?>" placeholder="Enter surname" autocomplete="family-name">
                        <span class="form-error" id="surname-error"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label form-label--required" for="first_name"><?= h(t('first_name')) ?></label>
                        <input class="form-input" type="text" id="first_name" name="first_name" value="<?= old('first_name') ?>" placeholder="Enter first name" autocomplete="given-name">
                        <span class="form-error" id="first_name-error"></span>
                    </div>

                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="full_address">Full address</label>
                        <input class="form-input" type="text" id="full_address" name="full_address" value="<?= old('full_address') ?>" placeholder="Street, town, county" autocomplete="street-address">
                        <span class="form-error" id="full_address-error"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label form-label--required" for="eircode">Eircode</label>
                        <input class="form-input" type="text" id="eircode" name="eircode" value="<?= old('eircode') ?>" placeholder="e.g. D02 X285" autocomplete="postal-code" maxlength="8" autocapitalize="characters">
                        <span class="form-error" id="eircode-error"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label form-label--required" for="date_of_birth">Date of birth</label>
                        <input class="form-input" type="date" id="date_of_birth" name="date_of_birth" value="<?= old('date_of_birth') ?>" lang="en-IE">
                        <p class="form-hint">Day / month / year (Irish format, e.g. 31/12/1990).</p>
                        <span class="form-error" id="date_of_birth-error"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label form-label--required">Gender</label>
                        <div class="form-radio-group" role="radiogroup" aria-label="Gender">
                            <label class="form-radio"><input type="radio" name="gender" value="male"<?= checked('gender', 'male') ?>> Male</label>
                            <label class="form-radio"><input type="radio" name="gender" value="female"<?= checked('gender', 'female') ?>> Female</label>
                            <label class="form-radio"><input type="radio" name="gender" value="other"<?= checked('gender', 'other') ?>> Other</label>
                            <label class="form-radio"><input type="radio" name="gender" value="prefer_not_to_say"<?= checked('gender', 'prefer_not_to_say') ?>> Prefer not to say</label>
                        </div>
                        <span class="form-error" id="gender-error"></span>
                    </div>
                    <?php wiz_step_close(); ?>

                    <?php wiz_step_open(5); ?>
                    <h3 class="form-section-title">Contact</h3>
                    <?php if ($wizardV2Enabled): ?>
                        <div class="reg-wizard__contact-panel" role="note">
                            <p class="reg-wizard__contact-lead">Your mobile number lets organisers contact you about shift confirmations, roster changes, and event-day check-in.</p>
                            <ul class="reg-wizard__contact-points">
                                <li>Used only for work you apply for</li>
                                <li>Not sold to third parties</li>
                                <li>Update anytime in your staff profile</li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="form-group form-group--full reg-wizard__contact-field">
                        <label class="form-label form-label--required" for="mobile_national">Mobile number</label>
                        <?php renderPhoneInputField([
                            'id'         => 'mobile',
                            'value'      => (string) ($old['mobile'] ?? ''),
                            'defaultIso' => $defaultPhoneCountry,
                            'required'   => true,
                        ]); ?>
                    </div>
                    <?php wiz_step_close(); ?>

                    <?php wiz_step_open(6); ?>
                    <h3 class="form-section-title">Financial &amp; identification</h3>
                    <?php if ($wizardV2Enabled): ?>
                        <div class="reg-wizard__payroll-notice" role="note">
                            <p><strong>Registration platform only.</strong> Olasentra connects you with event opportunities. We are <strong>not</strong> your employer, payroll provider, or contracting party. Bank and tax details you provide are passed to the paying contractor or event organiser for approved work only.</p>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label form-label--required" for="pps_number">National Insurance / PPS Number</label>
                        <input class="form-input" type="text" id="pps_number" name="pps_number" value="<?= old('pps_number') ?>" placeholder="Enter NI or PPS number">
                        <span class="form-error" id="pps_number-error"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label form-label--required" for="bank_iban">Bank IBAN</label>
                        <input class="form-input" type="text" id="bank_iban" name="bank_iban" value="<?= old('bank_iban') ?>" placeholder="IE29AIBK93115212345678" autocomplete="off" autocapitalize="characters" maxlength="34" pattern="[A-Za-z]{2}[0-9]{2}[A-Za-z0-9]{11,30}" title="IBAN with country code, not a bank name">
                        <p class="form-hint">Irish example: IE + 2 check digits + account (22 characters). UK: GB + 20 characters.</p>
                        <span class="form-error" id="bank_iban-error"></span>
                    </div>
                    <?php wiz_step_close(); ?>

                    <?php wiz_step_open(7); ?>
                    <h3 class="form-section-title">PSA licence</h3>

                    <div class="form-group">
                        <label class="form-label<?= $registrationRequiresPsa ? ' form-label--required' : '' ?>" for="psa_licence">PSA licence number</label>
                        <input class="form-input" type="text" id="psa_licence" name="psa_licence" value="<?= old('psa_licence') ?>" placeholder="EM123456/00" autocomplete="off" autocapitalize="characters" pattern="EM[0-9]{6}/[0-9]{2}" title="Format EM123456/00"<?= $registrationRequiresPsa ? ' required' : '' ?>>
                        <p class="form-hint">Format: EM123456/00. We check the format, then you confirm on the <a href="https://www.psa-gov.ie/psa-registered-employees/" target="_blank" rel="noopener">official PSA register</a>.</p>
                        <p class="form-hint" id="psa-licence-verify-status" aria-live="polite"></p>
                        <span class="form-error" id="psa_licence-error"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label<?= $registrationRequiresPsa ? ' form-label--required' : '' ?>" for="psa_expiry_date">PSA expiry date</label>
                        <input class="form-input" type="date" id="psa_expiry_date" name="psa_expiry_date" value="<?= old('psa_expiry_date') ?>" lang="en-IE"<?= $registrationRequiresPsa ? ' required' : '' ?>>
                        <p class="form-hint">Day / month / year (Irish format, e.g. 31/12/2028).</p>
                        <span class="form-error" id="psa_expiry_date-error"></span>
                    </div>

                    <div class="form-group form-group--full">
                        <label class="form-label<?= $registrationRequiresPsa ? ' form-label--required' : '' ?>" for="psa_front_image">PSA card, front photo</label>
                        <input class="form-input form-input--file" type="file" id="psa_front_image" name="psa_front_image" accept="<?= h(psaImageFileAcceptAttribute()) ?>" data-psa-upload="front"<?= $registrationRequiresPsa ? ' required' : '' ?>>
                        <span class="form-error" id="psa_front_image-error"></span>
                    </div>

                    <div class="form-group form-group--full">
                        <label class="form-label<?= $registrationRequiresPsa ? ' form-label--required' : '' ?>" for="psa_back_image">PSA card, back photo</label>
                        <input class="form-input form-input--file" type="file" id="psa_back_image" name="psa_back_image" accept="<?= h(psaImageFileAcceptAttribute()) ?>" data-psa-upload="back"<?= $registrationRequiresPsa ? ' required' : '' ?>>
                        <span class="form-error" id="psa_back_image-error"></span>
                    </div>
                    <?php wiz_step_close(); ?>

                    <?php wiz_step_open(2); ?>
                    <h3 class="form-section-title"><?= $wizardV2Enabled ? 'Pick available shift' : 'Pick available shift' ?></h3>
                    <?php if ($wizardV2Enabled): ?>
                        <p class="form-hint">Open shifts load automatically — tap the shift you want, then continue to your details.</p>
                    <?php endif; ?>

                    <div id="shift-gate-notice" class="alert alert--warning" role="status" hidden>
                        <?= $registrationRequiresPsa
                            ? 'Complete all sections above (personal details, bank, and PSA card photos) before you can pick shifts.'
                            : 'Complete all sections above (personal details and bank) before you can pick shifts.' ?>
                    </div>

                    <?php if ($pdo): renderRegistrationPortalNotice($pdo); endif; ?>

                    <div class="form-group form-group--full<?= $wizardV2Enabled ? '' : ' shift-picker-locked' ?>" id="event-selection-wrap">
                        <label class="form-label form-label--required" id="shift-picker-label">Available shifts</label>
                        <p class="form-hint" id="event-selection-hint">
                            <?= h(t('shift_list_hint')) ?>
                        </p>
                        <div
                            id="shift-picker-list"
                            class="shift-picker-list<?= $wizardV2Enabled ? ' shift-picker-list--wizard' : '' ?>"
                            role="group"
                            aria-labelledby="shift-picker-label"
                            data-selected="<?= htmlspecialchars($selectedEventsJson, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <p class="form-hint">Loading shifts…</p>
                        </div>
                        <p class="shift-picker-summary" id="shift-picker-summary" aria-live="polite">0 shifts selected</p>
                        <input type="hidden" id="venue_id" name="venue_id" value="<?= (int) $selectedVenueId ?>">
                        <span class="form-error" id="event_ids-error"></span>
                        <div id="waitlist-offer" class="alert alert--info alert--visible" style="margin-top:0.75rem;display:none;">
                            <p><strong>No shifts available right now?</strong> You can still register your interest. We will contact you when a place opens.</p>
                            <label class="form-checkbox">
                                <input type="checkbox" name="join_waiting_list" id="join_waiting_list" value="1">
                                Add me to the waiting list
                            </label>
                            <input type="hidden" name="registration_mode" id="registration_mode" value="">
                        </div>
                    </div>
                    <?php if ($wizardV2Enabled): ?>
                        <div id="reg-fast-track-footer" class="reg-fast-track-footer" hidden>
                            <p class="reg-fast-track-lead">Welcome back — your saved profile will be used. Pick a shift and submit below.</p>
                            <div id="reg-fast-track-events" class="reg-fast-track-events" aria-live="polite"></div>
                            <div id="reg-fast-track-consent-mount"></div>
                        </div>
                    <?php endif; ?>
                    <?php wiz_step_close(); ?>

                    <?php wiz_step_open(8); ?>
                    <?php if ($wizardV2Enabled): ?>
                        <div id="reg-wizard-review-summary" class="reg-review-summary" aria-live="polite"></div>
                    <?php endif; ?>
                    <div id="reg-consent-home">
                    <div class="form-group form-group--full">
                        <label class="form-checkbox">
                            <input type="checkbox" name="privacy_consent" value="1"<?= !empty($old['privacy_consent']) ? ' checked' : '' ?> required>
                            I agree to the <a href="privacy.php" target="_blank" rel="noopener">Privacy Notice</a><?php if ($registrationRequiresPsa): ?> and confirm I am applying as <strong>PSA-licensed security staff</strong> (not stewarding)<?php else: ?> and confirm I am applying as <strong>event steward / crowd staff</strong><?php endif; ?>. This site is a <strong>registration portal only</strong> (not my employer). I consent to my data being processed to register for shifts and to pass my details to organisers or contractors where required.
                        </label>
                        <span class="form-error" id="privacy_consent-error"></span>
                    </div>
                    </div>

                    <div class="form-actions<?= $wizardV2Enabled ? ' form-actions--wizard-hidden' : '' ?>">
                        <button type="reset" class="btn btn--secondary">Clear form</button>
                        <button type="submit" class="btn btn--primary">Submit registration</button>
                    </div>
                    <?php wiz_step_close(); ?>

                    <?php if ($wizardV2Enabled): ?>
                        <?php renderRegistrationWizardNav(); ?>
                    <?php endif; ?>
                </div>
            </form>
        </section>
        <?php endif; ?>
    </main>

    <?php renderStaffPublicFooter($siteName); ?>

    <?php if (!empty($serverErrors)): ?>
    <script>window.SERVER_FORM_ERRORS = <?= json_encode($serverErrors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;</script>
    <?php endif; ?>

    <script>window.REGISTRATION_FORM_SLUG = <?= json_encode($formSlug !== '' && $linkedForm ? $formSlug : $selectedFormSlug, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;</script>
    <script src="assets/js/registration-fields.js"></script>
    <?php $phoneJsVer = is_file(__DIR__ . '/assets/js/phone-input.js') ? (string) filemtime(__DIR__ . '/assets/js/phone-input.js') : '1'; ?>
    <script src="assets/js/phone-input.js?v=<?= h($phoneJsVer) ?>"></script>
    <?php $eventsJsVer = is_file(__DIR__ . '/assets/js/events.js') ? (string) filemtime(__DIR__ . '/assets/js/events.js') : '1'; ?>
    <script src="assets/js/events.js?v=<?= h($eventsJsVer) ?>"></script>
    <?php
    $enablePwaInstall = true;
    include __DIR__ . '/includes/pwa-scripts.php';
    ?>
    <?php if ($showNotice): ?>
    <script src="assets/js/site-notice.js"></script>
    <?php endif; ?>
    <script src="assets/js/returning-registrant.js"></script>
    <?php if ($showRegistrationGoogleGate): ?>
    <?php $regOtpVer = is_file(__DIR__ . '/assets/js/registration-email-otp.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-email-otp.js') : '1'; ?>
    <script src="assets/js/registration-email-otp.js?v=<?= h($regOtpVer) ?>"></script>
    <?php endif; ?>
    <?php $shiftGateVer = is_file(__DIR__ . '/assets/js/registration-shift-gate.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-shift-gate.js') : '1'; ?>
    <script src="assets/js/registration-shift-gate.js?v=<?= h($shiftGateVer) ?>"></script>
    <?php if ($wizardV2Enabled): ?>
    <?php $finJsVer = is_file(__DIR__ . '/assets/js/financial-field-validation.js') ? (string) filemtime(__DIR__ . '/assets/js/financial-field-validation.js') : '1'; ?>
    <script src="assets/js/financial-field-validation.js?v=<?= h($finJsVer) ?>"></script>
    <?php $wizAutosaveVer = is_file(__DIR__ . '/assets/js/registration-wizard-autosave.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-wizard-autosave.js') : '1'; ?>
    <script src="assets/js/registration-wizard-autosave.js?v=<?= h($wizAutosaveVer) ?>"></script>
    <?php $wizValVer = is_file(__DIR__ . '/assets/js/registration-wizard-validation.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-wizard-validation.js') : '1'; ?>
    <script src="assets/js/registration-wizard-validation.js?v=<?= h($wizValVer) ?>"></script>
    <?php $wizAnalyticsVer = is_file(__DIR__ . '/assets/js/registration-wizard-analytics.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-wizard-analytics.js') : '1'; ?>
    <script src="assets/js/registration-wizard-analytics.js?v=<?= h($wizAnalyticsVer) ?>"></script>
    <?php $wizRetVer = is_file(__DIR__ . '/assets/js/registration-wizard-returning.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-wizard-returning.js') : '1'; ?>
    <script src="assets/js/registration-wizard-returning.js?v=<?= h($wizRetVer) ?>"></script>
    <?php $wizPsaVer = is_file(__DIR__ . '/assets/js/registration-wizard-psa.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-wizard-psa.js') : '1'; ?>
    <script src="assets/js/registration-wizard-psa.js?v=<?= h($wizPsaVer) ?>"></script>
    <?php $wizReviewVer = is_file(__DIR__ . '/assets/js/registration-wizard-review.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-wizard-review.js') : '1'; ?>
    <script src="assets/js/registration-wizard-review.js?v=<?= h($wizReviewVer) ?>"></script>
    <?php if (!empty($serverErrors)): ?>
    <script>window.REG_WIZARD_RESTORE_STEP = 8;</script>
    <?php endif; ?>
    <?php $wizJsVer = is_file(__DIR__ . '/assets/js/registration-wizard.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-wizard.js') : '1'; ?>
    <script src="assets/js/registration-wizard.js?v=<?= h($wizJsVer) ?>"></script>
    <?php $wizRestoreVer = is_file(__DIR__ . '/assets/js/registration-wizard-server-restore.js') ? (string) filemtime(__DIR__ . '/assets/js/registration-wizard-server-restore.js') : '1'; ?>
    <script src="assets/js/registration-wizard-server-restore.js?v=<?= h($wizRestoreVer) ?>"></script>
    <?php endif; ?>
</body>
</html>
