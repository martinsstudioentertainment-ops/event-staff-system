<?php

declare(strict_types=1);

/**
 * Local UX screenshot frame - full production CSS, no database required.
 * Run: php -S 127.0.0.1:8765 -t .  then open /wizard-screenshot-preview.php?step=5&vp=mobile
 */

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal = in_array($remote, ['127.0.0.1', '::1'], true);
if (!$isLocal) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/feature-flags.php';
    try {
        $pdo = getDB();
        if (!isFeatureEnabled($pdo, 'feature_registration_wizard_v2')) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Screenshot preview requires feature_registration_wizard_v2 enabled on production.';
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Screenshot preview unavailable.';
        exit;
    }
}

$step = max(1, min(8, (int) ($_GET['step'] ?? 5)));
$vp   = (string) ($_GET['vp'] ?? 'mobile');
$mode = (string) ($_GET['mode'] ?? 'normal');
if (!in_array($vp, ['mobile', 'tablet', 'desktop'], true)) {
    $vp = 'mobile';
}

$steps = [
    1 => 'Welcome',
    2 => 'Your gigs',
    3 => 'Email',
    4 => 'About you',
    5 => 'Contact',
    6 => 'Payroll',
    7 => 'PSA',
    8 => 'Review',
];
$stepTitle = $steps[$step] ?? ('Step ' . $step);
$pct = (int) round(($step / 8) * 100);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

header('Content-Type: text/html; charset=UTF-8');

$wizCssVer = is_file(__DIR__ . '/assets/css/registration-wizard.css')
    ? (string) filemtime(__DIR__ . '/assets/css/registration-wizard.css') : '1';
$compactVer = is_file(__DIR__ . '/assets/css/registration-compact.css')
    ? (string) filemtime(__DIR__ . '/assets/css/registration-compact.css') : '1';

$assetBase   = '';
$pdo         = null;
$themeColor  = '#6366f1';
$siteName    = 'Olasentra';
$fontUrl     = 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap';
$enablePwa   = false;

require_once __DIR__ . '/includes/public/staff-public-shell.php';

?>
<!DOCTYPE html>
<html lang="en" data-theme="light" class="screenshot-vp--<?= h($vp) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Wizard screenshot step <?= $step ?> | <?= h($siteName) ?></title>
    <meta name="theme-color" content="<?= h($themeColor) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?= h($fontUrl) ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/public-front.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/registration-compact.css?v=<?= h($compactVer) ?>">
    <link rel="stylesheet" href="assets/css/registration-wizard.css?v=<?= h($wizCssVer) ?>">
    <style>
        html.screenshot-vp--mobile,
        html.screenshot-vp--tablet,
        html.screenshot-vp--desktop { background: #030712; }
        .screenshot-vp--mobile .registration-page__wrap { max-width: 390px; margin: 0 auto; }
        .screenshot-vp--tablet .registration-page__wrap { max-width: 768px; margin: 0 auto; }
        .screenshot-vp--desktop .registration-page__wrap { max-width: 1200px; margin: 0 auto; }
        .screenshot-vp--desktop .staff-public-card { max-width: 720px; margin-left: auto; margin-right: auto; }
        .screenshot-vp--tablet .staff-public-card { max-width: 680px; margin-left: auto; margin-right: auto; }
    </style>
</head>
<body class="staff-public-shell staff-public-shell--event-ops registration-page registration-page--compact registration-page--wizard screenshot-vp--<?= h($vp) ?>" data-wizard-mode="1">
    <?php renderStaffPublicBackground(true); ?>

    <main class="registration-page__wrap staff-public-main">
        <?php renderStaffPublicHero([
            'eyebrow' => 'PSA security | DSP & Static',
            'title'   => 'Register for event work',
            'lead'    => '<p>Free registration portal - connects you to event opportunities.</p>',
        ]); ?>

        <section class="card staff-public-card staff-public-card--no-inner-title">
            <form id="registration-form" class="form-grid" onsubmit="return false;">
                <div class="reg-wizard">
                    <div class="reg-wizard__progress-meta">
                        <span class="reg-wizard__step-label">Step <?= $step ?> of 8</span>
                        <span class="reg-wizard__step-name"><?= h($stepTitle) ?></span>
                    </div>
                    <div class="reg-wizard__bar" role="progressbar" aria-valuemin="1" aria-valuemax="8" aria-valuenow="<?= $step ?>">
                        <span class="reg-wizard__bar-fill" style="width:<?= $pct ?>%"></span>
                    </div>
                <p class="reg-wizard__save-status reg-wizard__save-status--saved" id="reg-wizard-save-status">
                <span class="reg-wizard__save-status-icon" aria-hidden="true"></span>
                <span id="reg-wizard-save-text">Draft saved · Last saved 6 Jun, 14:32</span>
            </p>
            </div>

                <?php if ($step === 1): ?>
                <div class="reg-wizard__step reg-wizard__step--active" data-step="1">
                    <?php if ($mode === 'resume'): ?>
                    <div id="reg-wizard-resume-prompt" class="reg-resume-prompt">
                        <div class="reg-resume-prompt__card">
                            <p class="reg-resume-prompt__badge">Saved on this device</p>
                            <p class="reg-resume-prompt__title">Resume your application?</p>
                            <p class="reg-resume-prompt__text">Last saved <strong>6 Jun, 14:32</strong>. You reached <strong>Contact</strong> (step 5 of 8) · 1 shift selected.</p>
                            <p class="reg-resume-prompt__note">Progress is stored locally on this phone or browser. PSA photos are not saved in drafts - you will re-attach them on the PSA step.</p>
                            <div class="reg-resume-prompt__actions">
                                <button type="button" class="btn btn--primary">Resume application</button>
                                <button type="button" class="btn btn--secondary">Start fresh</button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="form_slug">Your role</label>
                        <select class="form-select" id="form_slug"><option selected>DSP Security Guard</option></select>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($step === 2): ?>
                <div class="reg-wizard__step reg-wizard__step--active" data-step="2">
                    <h3 class="form-section-title">Shift selection</h3>
                    <p class="form-hint">Event opportunities listed by organisers — select the shifts you want to apply for.</p>
                    <div class="reg-event-cards">
                        <label class="reg-event-card reg-event-card--selected">
                            <input type="checkbox" name="event_ids[]" value="42" checked>
                            <div class="reg-event-card__body">
                                <h4 class="reg-event-card__title">Sample Festival 2026</h4>
                                <dl class="reg-event-card__meta">
                                    <dt>Venue</dt><dd>Main Arena</dd>
                                    <dt>Date</dt><dd>Sat 5 Sep 2026</dd>
                                    <dt>County</dt><dd>Dublin</dd>
                                    <dt>Roles</dt><dd>DSP Security</dd>
                                </dl>
                                <span class="reg-event-card__status reg-event-card__status--open">Open for registration</span>
                            </div>
                            <span class="reg-event-card__check"></span>
                        </label>
                    </div>
                    <p class="shift-picker-summary">1 shift selected</p>
                </div>
                <?php endif; ?>

                <?php if ($step === 3): ?>
                <div class="reg-wizard__step reg-wizard__step--active" data-step="3">
                    <h3 class="form-section-title">Your email</h3>
                    <p class="form-hint">We use your email for registration confirmation and to link your profile if you return.</p>
                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="email">Email address</label>
                        <input class="form-input" type="email" id="email" name="email" value="jane.smith@example.com" autocomplete="email">
                    </div>
                    <?php if ($mode === 'returning'): ?>
                    <div class="reg-returning-panel" role="status">
                        <p class="reg-returning-panel__title">Welcome back</p>
                        <p class="reg-returning-panel__text">We found a profile for <strong>jane.smith@example.com</strong>. Your name and address will be prefilled on the next step.</p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($step === 4): ?>
                <div class="reg-wizard__step reg-wizard__step--active" data-step="4">
                    <h3 class="form-section-title">About you</h3>
                    <div class="form-group">
                        <label class="form-label form-label--required" for="first_name">First name</label>
                        <input class="form-input" type="text" id="first_name" name="first_name" value="Jane">
                    </div>
                    <div class="form-group">
                        <label class="form-label form-label--required" for="surname">Surname</label>
                        <input class="form-input" type="text" id="surname" name="surname" value="Smith">
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="full_address">Full address</label>
                        <input class="form-input" type="text" id="full_address" name="full_address" value="12 Main St, Dublin">
                    </div>
                    <div class="form-group">
                        <label class="form-label form-label--required" for="eircode">Eircode</label>
                        <input class="form-input" type="text" id="eircode" name="eircode" value="D02 X285">
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($step === 5): ?>
                <div class="reg-wizard__step reg-wizard__step--active" data-step="5">
                    <h3 class="form-section-title">Contact</h3>
                    <div class="reg-wizard__contact-panel" role="note">
                        <p class="reg-wizard__contact-lead">Your mobile number lets organisers contact you about shift confirmations, roster changes, and event-day check-in.</p>
                        <ul class="reg-wizard__contact-points">
                            <li>Used only for work you apply for</li>
                            <li>Not sold to third parties</li>
                            <li>Update anytime in your staff profile</li>
                        </ul>
                    </div>
                    <div class="form-group form-group--full reg-wizard__contact-field">
                        <label class="form-label form-label--required" for="mobile_national">Mobile number</label>
                        <div class="phone-input">
                            <select class="form-select phone-input__country" id="mobile_country"><option>IE +353</option></select>
                            <input class="form-input phone-input__number" id="mobile_national" value="87 123 4567">
                        </div>
                        <input type="hidden" id="mobile" name="mobile" value="+353871234567">
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($step === 6): ?>
                <div class="reg-wizard__step reg-wizard__step--active" data-step="6">
                    <h3 class="form-section-title">Financial &amp; identification</h3>
                    <div class="reg-wizard__payroll-notice" role="note">
                        <p><strong>Registration platform only.</strong> Olasentra connects you with event opportunities. We are <strong>not</strong> your employer, payroll provider, or contracting party. Bank and tax details you provide are passed to the paying contractor or event organiser for approved work only.</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label form-label--required" for="pps_number">National Insurance / PPS Number</label>
                        <input class="form-input" type="text" id="pps_number" name="pps_number" value="1234567AB">
                    </div>
                    <div class="form-group">
                        <label class="form-label form-label--required" for="bank_iban">Bank IBAN</label>
                        <input class="form-input" type="text" id="bank_iban" name="bank_iban" value="IE29AIBK93115212345678">
                        <p class="form-hint">Irish example: IE + 2 check digits + account (22 characters). UK: GB + 20 characters.</p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($step === 7): ?>
                <div class="reg-wizard__step reg-wizard__step--active" data-step="7">
                    <h3 class="form-section-title">PSA licence</h3>
                    <div class="form-group">
                        <label class="form-label form-label--required" for="psa_licence">PSA licence number</label>
                        <input class="form-input" type="text" id="psa_licence" value="EM123456/00">
                    </div>
                    <div class="form-group">
                        <label class="form-label form-label--required" for="psa_expiry_date">PSA expiry date</label>
                        <input class="form-input" type="date" id="psa_expiry_date" value="2028-12-31">
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="psa_front_image">PSA card, front photo</label>
                        <div class="reg-psa-upload reg-psa-upload--ready">
                            <p class="reg-psa-upload__hint">Photograph the front of your PSA card. On mobile, your camera opens automatically.</p>
                            <input class="form-input form-input--file" type="file" id="psa_front_image" data-psa-upload="front">
                            <p class="reg-psa-upload__status">Selected: psa-front.jpg</p>
                        </div>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label form-label--required" for="psa_back_image">PSA card, back photo</label>
                        <div class="reg-psa-upload">
                            <p class="reg-psa-upload__hint">Photograph the back of your PSA card. On mobile, your camera opens automatically.</p>
                            <input class="form-input form-input--file" type="file" id="psa_back_image" data-psa-upload="back">
                            <p class="reg-psa-upload__status">No photo selected</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($step === 8): ?>
                <div id="form-alert" class="alert alert--error alert--visible"<?= $mode === 'error' ? '' : ' hidden' ?>>Some details need correction. Review the highlighted sections below, then use Fix to update a section.</div>
                <div class="reg-wizard__step reg-wizard__step--active" data-step="8">
                    <div id="shift-picker-list" class="shift-picker-list shift-picker-list--wizard" data-selected='[42]' hidden>
                        <div class="reg-event-cards">
                            <label class="reg-event-card" data-event-id="42" data-event-name="Sample Festival">
                                <input type="checkbox" name="event_ids[]" value="42" checked>
                                <div class="reg-event-card__body"><h4 class="reg-event-card__title">Sample Festival</h4></div>
                            </label>
                        </div>
                    </div>
                    <input type="hidden" id="email" value="jane.smith@example.com">
                    <input type="hidden" id="surname" value="Smith">
                    <input type="hidden" id="first_name" value="Jane">
                    <input type="hidden" id="full_address" value="12 Main St, Dublin">
                    <input type="hidden" id="eircode" value="D02 X285">
                    <input type="hidden" id="pps_number" value="1234567AB">
                    <input type="hidden" id="bank_iban" value="IE29AIBK93115212345678">
                    <input type="hidden" id="psa_licence" value="EM123456/00">
                    <input type="hidden" id="psa_expiry_date" value="2028-12-31">
                    <select id="form_slug" hidden><option selected>DSP Security Guard</option></select>
                    <div id="reg-wizard-review-summary" class="reg-review-summary" aria-live="polite"></div>
                    <div class="form-group form-group--full">
                        <label class="form-checkbox">
                            <input type="checkbox" name="privacy_consent" value="1" checked>
                            <span>I agree to the <a href="privacy.php">Privacy Notice</a> and confirm I am applying as <strong>PSA-licensed security staff</strong> (not stewarding). This site is a <strong>registration portal only</strong> (not my employer).</span>
                        </label>
                    </div>
                </div>
                <?php endif; ?>

                <nav class="reg-wizard__nav" id="reg-wizard-nav">
                    <button type="button" class="btn btn--secondary reg-wizard__btn-back">Back</button>
                    <button type="button" class="btn btn--primary reg-wizard__btn-next"><?= $step === 8 ? 'Submit registration' : 'Continue' ?></button>
                </nav>
            </form>
        </section>
    </main>

    <?php if ($step === 8): ?>
    <?php if ($mode === 'error'): ?>
    <script>window.SERVER_FORM_ERRORS = {"bank_iban":"Enter a valid IBAN with country code (e.g. IE29AIBK93115212345678). Do not enter a bank name.","psa_back_image":"PSA back photo is required."};</script>
    <?php endif; ?>
    <script src="assets/js/registration-wizard-review.js?v=<?= h($wizCssVer) ?>"></script>
    <?php endif; ?>
</body>
</html>
