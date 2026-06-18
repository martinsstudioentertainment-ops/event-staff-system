<?php



declare(strict_types=1);



/**

 * Staff app guest screen — premium v3 login.

 */



require_once __DIR__ . '/company.php';
require_once __DIR__ . '/settings-repository.php';

require_once __DIR__ . '/site-urls.php';

require_once __DIR__ . '/brand-logo.php';

require_once __DIR__ . '/staff-profile-gate.php';



/**

 * @param array{error?: string} $state

 */

function renderStaffAppGuestEasyPage(PDO $pdo, array $state = [], string $notice = ''): void

{

    require_once __DIR__ . '/auth.php';

    require_once __DIR__ . '/staff-google-oauth.php';
    require_once __DIR__ . '/staff-portal-email-otp.php';



    $companyName  = getCompanyName($pdo);
    $siteName     = getSiteName($pdo);
    $appTitle     = $siteName !== '' && $siteName !== 'Event Staff System' ? $siteName : $companyName;

    $registerUrl  = getRegistrationSiteUrl($pdo) . '/index.php';

    $googleReady  = isStaffGoogleSigninConfigured($pdo) && isStaffGoogleSigninEnabled($pdo);
    $emailOtpReady = isStaffPortalEmailOtpEnabled($pdo);

    $googleHref   = 'staff-google-signin.php?' . http_build_query(['return' => 'staff-app.php']);

    $assetBase    = '';

    ?>

    <div class="es-v3-login">

        <header class="es-v3-login__header">

            <div class="es-v3-login__logo-wrap">

                <?php renderStaffBrandLogo($pdo, 'es-v3-login__logo', $assetBase, $companyName); ?>

            </div>

            <div class="es-v3-login__brand-text">

                <h1><?= h($appTitle !== '' ? $appTitle : 'Olasentra') ?></h1>

                <p>Shifts, check-in &amp; messages</p>

            </div>

        </header>



        <section class="es-v3-login__hero">

            <h2>Staff sign-in</h2>

            <p>Use the <strong>same email</strong> you registered with to view shifts, check in, and messages.</p>

        </section>



        <?php if ($notice !== ''): ?>

            <div class="es-v3-login__alert" role="status"><?= h($notice) ?></div>

        <?php endif; ?>



        <?php if (!empty($state['error'])): ?>

            <div class="es-v3-login__alert es-v3-login__alert--error" role="alert"><?= h((string) $state['error']) ?></div>

        <?php endif; ?>



        <div class="es-v3-login__cta">

            <?php if ($googleReady): ?>

                <a class="es-v3__google-btn" href="<?= h($googleHref) ?>">

                    <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">

                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/>

                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>

                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>

                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>

                    </svg>

                    Continue with Google

                </a>

            <?php endif; ?>



            <?php if ($emailOtpReady): ?>

                <?php if ($googleReady): ?>

                    <p class="es-v3-login__divider" aria-hidden="true"><span>or</span></p>

                <?php endif; ?>



                <div id="staff-portal-email-otp"
                     class="es-v3-login__email-otp"
                     data-send-url="api/staff-portal-otp-send.php"
                     data-verify-url="api/staff-portal-otp-verify.php"
                     data-csrf="<?= h(csrfToken()) ?>">

                    <div class="es-v3-login__email-step" data-step="email">

                        <button type="button" class="es-v3__email-btn" id="staff-portal-email-toggle">

                            Continue with Email Code

                        </button>

                        <div class="es-v3-login__email-panel" id="staff-portal-email-panel" hidden>

                            <label class="es-v3-login__label" for="staff-portal-email-input">Work email</label>

                            <input type="email"
                                   id="staff-portal-email-input"
                                   class="es-v3-login__input"
                                   name="email"
                                   autocomplete="email"
                                   inputmode="email"
                                   placeholder="you@example.com"
                                   required>

                            <button type="button" class="es-v3__email-submit" id="staff-portal-email-send">

                                Send verification code

                            </button>

                        </div>

                    </div>



                    <div class="es-v3-login__email-step" data-step="code" hidden>

                        <p class="es-v3-login__email-hint">Enter the 6-digit code sent to <strong id="staff-portal-email-display"></strong></p>

                        <label class="es-v3-login__label" for="staff-portal-code-input">Verification code</label>

                        <input type="text"
                               id="staff-portal-code-input"
                               class="es-v3-login__input es-v3-login__input--code"
                               name="otp_code"
                               inputmode="numeric"
                               pattern="[0-9]{6}"
                               maxlength="6"
                               autocomplete="one-time-code"
                               placeholder="000000"
                               required>

                        <button type="button" class="es-v3__email-submit" id="staff-portal-email-verify">

                            Verify and sign in

                        </button>

                        <button type="button" class="es-v3-login__email-back" id="staff-portal-email-back">

                            Use a different email

                        </button>

                    </div>



                    <p class="es-v3-login__email-error" id="staff-portal-email-error" role="alert" hidden></p>

                </div>

            <?php endif; ?>



            <?php if (!$googleReady && !$emailOtpReady): ?>

                <p class="es-v3-login__alert">Sign-in is being configured. Please try again shortly.</p>

            <?php endif; ?>

        </div>



        <a href="<?= h($registerUrl) ?>" class="es-v3-login__secondary">Apply to Join</a>



        <div class="es-v3-login__features" aria-label="App features">

            <span class="es-v3-login__feature">

                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>

                <span>My Shifts</span>

            </span>

            <span class="es-v3-login__feature">

                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M7 12h10"/></svg>

                <span>Check In</span>

            </span>

            <span class="es-v3-login__feature">

                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>

                <span>Messages</span>

            </span>

            <span class="es-v3-login__feature">

                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>

                <span>Documents</span>

            </span>

        </div>



        <footer class="es-v3-login__footer">

            <p>Your data is protected. Sign in with Google or a one-time email code.</p>

            <p class="es-v3-login__secure">

                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>

                Secure staff authentication

            </p>

        </footer>

    </div>

    <?php

}



function renderStaffProfileNeededBanner(PDO $pdo, array $staff): void

{

    if (!staffNeedsProfileForm($pdo, $staff)) {

        return;

    }

    ?>

    <div class="staff-easy__banner" role="status">

        <strong>Payroll profile incomplete.</strong>

        <a href="staff-profile.php">Complete when ready</a> — you can still view shifts.

    </div>

    <?php

}


