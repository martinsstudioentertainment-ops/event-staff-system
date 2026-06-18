<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-google-oauth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings-repository.php';

function registrationGoogleGateReturnUrl(?PDO $pdo, string $formSlug = ''): string
{
    $url = 'index.php';
    if ($formSlug !== '') {
        $url .= '?form=' . urlencode($formSlug);
    }

    return $url;
}

function isRegistrationEmailOtpEnabled(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? getDB();

    return getSetting($pdo, 'mobile_email_otp_enabled', '1') === '1';
}

function renderRegistrationGoogleGate(PDO $pdo, string $formSlug = '', string $error = ''): void
{
    require_once __DIR__ . '/auth.php';

    $returnUrl   = registrationGoogleGateReturnUrl($pdo, $formSlug);
    $googleReady = isStaffGoogleSigninConfigured($pdo) && isStaffGoogleSigninEnabled($pdo);
    $emailReady  = isRegistrationEmailOtpEnabled($pdo);
    ?>
    <section class="card staff-public-card registration-google-gate">
        <div class="card__header">
            <h2 class="card__title">Start your application</h2>
            <p class="card__subtitle">Verify your email first — any address works (Gmail, Outlook, Yahoo, work email). Then pick a shift and complete your details.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert--error alert--visible" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($googleReady): ?>
            <p class="form-hint">Have Google? Use the same email you will register with.</p>
            <?php renderStaffGoogleSignInButton($pdo, $returnUrl, true); ?>
        <?php endif; ?>

        <?php if ($googleReady && $emailReady): ?>
            <p class="registration-identity-divider" aria-hidden="true"><span>or</span></p>
        <?php endif; ?>

        <?php if ($emailReady): ?>
            <div id="registration-email-otp"
                 class="registration-email-otp"
                 data-send-url="api/registration-email-otp-send.php"
                 data-verify-url="api/registration-email-otp-verify.php"
                 data-return-url="<?= h($returnUrl) ?>">

                <div class="registration-email-otp__step" data-step="email">
                    <label class="form-label form-label--required" for="registration-email-input">Your email address</label>
                    <input type="email"
                           id="registration-email-input"
                           class="form-input"
                           name="email"
                           autocomplete="email"
                           inputmode="email"
                           placeholder="you@example.com"
                           required>
                    <p class="form-hint">We will send a 6-digit code to this inbox. Use the same email for your staff profile.</p>
                    <button type="button" class="btn btn--primary btn--block" id="registration-email-send">
                        Send verification code
                    </button>
                </div>

                <div class="registration-email-otp__step" data-step="code" hidden>
                    <p class="form-hint">Enter the code sent to <strong id="registration-email-display"></strong></p>
                    <label class="form-label form-label--required" for="registration-code-input">Verification code</label>
                    <input type="text"
                           id="registration-code-input"
                           class="form-input registration-email-otp__code"
                           inputmode="numeric"
                           pattern="[0-9]{6}"
                           maxlength="6"
                           autocomplete="one-time-code"
                           placeholder="000000"
                           required>
                    <button type="button" class="btn btn--primary btn--block" id="registration-email-verify">
                        Verify and continue
                    </button>
                    <button type="button" class="btn btn--secondary btn--block registration-email-otp__back" id="registration-email-back">
                        Use a different email
                    </button>
                </div>

                <p class="form-error registration-email-otp__error" id="registration-email-error" role="alert" hidden></p>
            </div>
        <?php elseif (!$googleReady): ?>
            <div class="alert alert--warning alert--visible">Sign-in is being configured. Please try again shortly or contact the office.</div>
        <?php endif; ?>

        <p class="login-card__hint" style="margin-top:1rem;">
            Already registered? <a href="staff-app.php"><strong>Staff app sign-in</strong></a> (Google or email code).
        </p>
    </section>
    <?php
}
