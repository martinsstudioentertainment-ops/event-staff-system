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

require_once __DIR__ . '/includes/global-public-site.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';

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
$returnEmail  = trim((string) ($old['email'] ?? ''));
unset($_SESSION['registration_old'], $_SESSION['registration_errors']);

if ($pdo !== null && $returnEmail !== '') {
    require_once __DIR__ . '/includes/staff-onboarding.php';
    $profileUrl = getStaffOnboardingRedirectUrl($pdo, $returnEmail);
    if ($profileUrl !== null) {
        header('Location: ' . $profileUrl);
        exit;
    }
}

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

$pageTitle = $linkedForm
    ? (string) ($linkedForm['title'] ?? 'Staff Registration')
    : 'Register for Events';
$pageSubtitle = $linkedForm
    ? (string) ($linkedForm['subtitle'] ?? '')
    : t('register_page_subtitle');

$staffPortalUrl = 'staff-portal.php';
if ($pdo !== null) {
    require_once __DIR__ . '/includes/staff-onboarding.php';
    $staffPortalUrl = getStaffPortalUrl($pdo);
}

$showNotice = $pdo && isWebsiteNoticeEnabled($pdo);

$assetBase     = '';
$themeColor    = '#2563eb';
$themeCategory = 'events';
require_once __DIR__ . '/includes/theme.php';
if ($pdo) {
    $themeColor    = getThemeColor($pdo);
    $themeCategory = getThemeCategory($pdo);
}

?>
<!DOCTYPE html>
<html lang="<?= h(getAppLocale()) ?>" data-theme="light">
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
</head>
<body class="staff-public-shell staff-public-shell--event-ops registration-page registration-page--compact" data-registration-page="true" data-pwa-install="1" data-theme-category="<?= h($themeCategory) ?>" data-backend-submit="true" data-flash="<?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>" data-registered-count="<?= $registeredCount ?>" data-site-name="<?= h($siteName) ?>" data-locked-role="<?= h($lockFormType ? $lockedRole : '') ?>" data-roles-on-shift-label="<?= h(t('roles_on_shift')) ?>">
    <?php renderStaffPublicBackground(true); ?>

    <?php
    renderStaffPublicHeader($pdo, $siteName, [
        'language_switcher' => $pdo !== null,
        'lang_query'        => $formSlug !== '' ? 'form=' . urlencode($formSlug) : '',
        'home_url'          => 'staff-app.php',
    ]);
    ?>

    <?php if ($showNotice): ?>
        <?php $web = ['pdo' => $pdo, 'notice_variant' => 'scroll']; include __DIR__ . '/includes/public/site-notice.php'; ?>
    <?php endif; ?>

    <main class="registration-page__wrap staff-public-main">
        <?php
        renderStaffPublicHero([
            'eyebrow' => $linkedForm ? (string) ($linkedForm['short_label'] ?? 'Registration') : 'PSA security · DSP & Static',
            'title'   => $pageTitle,
            'lead'    => renderRichText($pageSubtitle),
        ]);
        ?>

        <aside class="registration-profile-notice" role="region" aria-labelledby="registration-profile-notice-title">
            <p class="registration-profile-notice__eyebrow">Step 1 &amp; 2</p>
            <h2 id="registration-profile-notice-title" class="registration-profile-notice__title"><?= h(t('register_profile_notice_title')) ?></h2>
            <ol class="registration-profile-notice__steps">
                <li><?= h(t('register_profile_notice_step1')) ?></li>
                <li><?= h(t('register_profile_notice_step2')) ?></li>
            </ol>
            <p class="registration-profile-notice__actions">
                <a href="<?= h($staffPortalUrl) ?>" class="btn btn--primary btn--small registration-profile-notice__btn"><?= h(t('register_profile_notice_link')) ?></a>
            </p>
            <p class="registration-profile-notice__hint">
                <?= h(t('register_profile_notice_returning')) ?>
                <a href="<?= h($staffPortalUrl) ?>"><?= h(t('register_profile_notice_link')) ?></a>
            </p>
        </aside>

        <section class="card staff-public-card staff-public-card--no-inner-title">
            <p class="registration-mobile-title"><?= h($pageTitle) ?></p>
            <div class="card__header" aria-hidden="true">
                <h2 class="card__title"><?= h($pageTitle) ?></h2>
            </div>

            <div id="form-alert" class="alert" role="alert"></div>

            <form id="registration-form" action="submit.php" method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <div class="form-grid">

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

                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="email"><?= h(t('email_address')) ?></label>
                        <input class="form-input" type="email" id="email" name="email" value="<?= old('email') ?>" placeholder="you@example.com" autocomplete="email">
                        <p class="form-hint"><?= h(t('email_hint')) ?></p>
                        <span class="form-error" id="email-error"></span>
                    </div>

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
                        <input class="form-input" type="date" id="date_of_birth" name="date_of_birth" value="<?= old('date_of_birth') ?>">
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

                    <h3 class="form-section-title">Contact</h3>

                    <div class="form-group">
                        <label class="form-label form-label--required" for="mobile">Mobile number</label>
                        <input class="form-input" type="tel" id="mobile" name="mobile" value="<?= old('mobile') ?>" placeholder="+353 00 000 0000" autocomplete="tel" inputmode="tel">
                        <span class="form-error" id="mobile-error"></span>
                    </div>

                    <h3 class="form-section-title">Financial &amp; identification</h3>

                    <div class="form-group">
                        <label class="form-label form-label--required" for="pps_number">National Insurance / PPS Number</label>
                        <input class="form-input" type="text" id="pps_number" name="pps_number" value="<?= old('pps_number') ?>" placeholder="Enter NI or PPS number">
                        <span class="form-error" id="pps_number-error"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label form-label--required" for="bank_iban">Bank Account / IBAN</label>
                        <input class="form-input" type="text" id="bank_iban" name="bank_iban" value="<?= old('bank_iban') ?>" placeholder="Enter bank account or IBAN">
                        <span class="form-error" id="bank_iban-error"></span>
                    </div>

                    <h3 class="form-section-title">Shift selection</h3>

                    <?php if ($pdo): renderRegistrationPortalNotice($pdo); endif; ?>

                    <div class="form-group form-group--full" id="event-selection-wrap">
                        <label class="form-label form-label--required" id="shift-picker-label">Available shifts</label>
                        <p class="form-hint" id="event-selection-hint">
                            <?= h(t('shift_list_hint')) ?>
                        </p>
                        <div
                            id="shift-picker-list"
                            class="shift-picker-list"
                            role="group"
                            aria-labelledby="shift-picker-label"
                            data-selected="<?= htmlspecialchars($selectedEventsJson, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <p class="form-hint">Loading shifts…</p>
                        </div>
                        <p class="shift-picker-summary" id="shift-picker-summary" aria-live="polite">0 shifts selected</p>
                        <input type="hidden" id="venue_id" name="venue_id" value="<?= (int) $selectedVenueId ?>">
                        <span class="form-error" id="event_ids-error"></span>
                    </div>

                    <div class="form-group form-group--full">
                        <label class="form-checkbox">
                            <input type="checkbox" name="privacy_consent" value="1"<?= !empty($old['privacy_consent']) ? ' checked' : '' ?> required>
                            I agree to the <a href="privacy.php" target="_blank" rel="noopener">Privacy Notice</a> and confirm I am applying as <strong>PSA-licensed security staff</strong> (not stewarding). This site is a <strong>registration portal only</strong> (not my employer). I consent to my data being processed to register for security shifts and to pass my details to organisers or contractors where required.
                        </label>
                        <span class="form-error" id="privacy_consent-error"></span>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn--secondary">Clear form</button>
                        <button type="submit" class="btn btn--primary">Submit registration</button>
                    </div>
                </div>
            </form>
        </section>
    </main>

    <?php renderStaffPublicFooter($siteName); ?>

    <?php if (!empty($serverErrors)): ?>
    <script>window.SERVER_FORM_ERRORS = <?= json_encode($serverErrors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;</script>
    <?php endif; ?>

    <script>window.REGISTRATION_FORM_SLUG = <?= json_encode($formSlug !== '' && $linkedForm ? $formSlug : $selectedFormSlug, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;</script>
    <script src="assets/js/registration-fields.js"></script>
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
</body>
</html>
