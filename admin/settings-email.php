<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/admin/settings-handler.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

requireAdminCapability('settings');

$pdo       = getDB();
$adminUser = getAdminUser();
$error     = '';
$success   = '';
$settings  = getAllSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'email');
    $result = processSettingsPost($pdo, $adminUser, $action);
    $error    = $result['error'];
    $success  = $result['success'];
    $settings = $result['settings'];
}

$pageTitle          = 'Email';
$activePage         = 'settings-email';
$erpSettingsActive  = 'email';
$emailGuide         = getRecommendedProductionEmails($pdo);

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Email notifications</h2>
        <p class="card__subtitle">Configure how the system sends email to staff.</p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert--success alert--visible"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert--error alert--visible"><?= h($error) ?></div>
    <?php endif; ?>

    <?php
    $outgoingOn = ($settings['outgoing_email_enabled'] ?? '1') === '1';
    ?>
    <style>
        .email-master-switch{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin:0 0 1.25rem;padding:1rem 1.15rem;border:2px solid <?= $outgoingOn ? '#1f7a4c' : '#9b2c2c' ?>;background:<?= $outgoingOn ? '#eef8f2' : '#fdecec' ?>;}
        .email-master-switch__copy h3{margin:0;font-size:1.1rem}
        .email-master-switch__copy p{margin:0.35rem 0 0;color:inherit;opacity:.9;font-size:.92rem}
        .switch{position:relative;display:inline-block;width:64px;height:36px;flex:0 0 auto}
        .switch input{opacity:0;width:0;height:0}
        .switch__slider{position:absolute;cursor:pointer;inset:0;background:#9b2c2c;transition:.2s;border-radius:36px}
        .switch__slider:before{position:absolute;content:"";height:28px;width:28px;left:4px;top:4px;background:#fff;transition:.2s;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.25)}
        .switch input:checked + .switch__slider{background:#1f7a4c}
        .switch input:checked + .switch__slider:before{transform:translateX(28px)}
        .switch__label{display:inline-flex;align-items:center;gap:.75rem;font-weight:800;user-select:none}
    </style>
    <div class="email-master-switch">
        <div class="email-master-switch__copy">
            <h3>Outgoing email — <?= $outgoingOn ? 'ON' : 'OFF' ?></h3>
            <p><?= $outgoingOn
                ? 'Emails will send (approvals, reminders, OTP, staff messages, tests).'
                : 'No emails will leave the system. Triggers still run, but nothing is delivered.' ?></p>
        </div>
        <form method="post" id="outgoing-email-switch-form" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="outgoing_email_toggle">
            <input type="hidden" name="outgoing_email_enabled" id="outgoing_email_enabled_value" value="<?= $outgoingOn ? '1' : '0' ?>">
            <label class="switch__label" for="outgoing_email_switch">
                <span><?= $outgoingOn ? 'ON' : 'OFF' ?></span>
                <span class="switch">
                    <input type="checkbox" id="outgoing_email_switch"<?= $outgoingOn ? ' checked' : '' ?> aria-label="Outgoing email on or off">
                    <span class="switch__slider"></span>
                </span>
            </label>
        </form>
    </div>
    <script>
    (function () {
        var box = document.getElementById('outgoing_email_switch');
        var form = document.getElementById('outgoing-email-switch-form');
        var hidden = document.getElementById('outgoing_email_enabled_value');
        if (!box || !form || !hidden) return;
        box.addEventListener('change', function () {
            hidden.value = box.checked ? '1' : '0';
            form.submit();
        });
    })();
    </script>

    <?php if (!$outgoingOn): ?>
        <div class="alert alert--error alert--visible">
            <strong>Outgoing email is OFF.</strong> Staff will not receive approval, reminder, or check-in emails. Admin login codes also will not send until you turn it ON.
        </div>
    <?php endif; ?>

    <?php if (isProductionApp() && ($settings['mail_transport'] ?? 'php_mail') !== 'smtp'): ?>
        <div class="alert alert--warning alert--visible">
            <strong>Go live requires SMTP.</strong> Create <code><?= h($emailGuide['from_email']) ?></code> in cPanel → set transport to <strong>SMTP</strong>,
            host <code><?= h(getDefaultSmtpHost($pdo)) ?></code>, port <code>587</code>, TLS, mailbox password → <strong>Send test email</strong>.
        </div>
    <?php elseif (isProductionApp() && ($settings['mail_transport'] ?? '') === 'smtp' && trim($settings['smtp_host'] ?? '') === ''): ?>
        <div class="alert alert--warning alert--visible">
            SMTP is selected but <strong>SMTP host</strong> is empty. Use <code><?= h(getDefaultSmtpHost($pdo)) ?></code> (cPanel mail server).
        </div>
    <?php endif; ?>

    <div class="url-format-guide" role="note" style="margin-bottom:1.25rem;">
        <p class="url-format-guide__title">Email vs your 3 domains</p>
        <p class="form-hint" style="margin:0 0 0.75rem;">You only need <strong>one</strong> sending address on the <strong>main domain</strong>. Subdomains are for websites, not separate From addresses.</p>
        <table class="url-format-guide__table">
            <tbody>
                <tr>
                    <th scope="row">olasentra.com</th>
                    <td>Marketing site — use <code><?= h($emailGuide['from_email']) ?></code> for system mail (create mailbox in cPanel)</td>
                </tr>
                <tr>
                    <th scope="row">register.olasentra.com</th>
                    <td>Staff form — links in emails point here (Settings → General → registration URL)</td>
                </tr>
                <tr>
                    <th scope="row">admin.olasentra.com</th>
                    <td>Admin only — not used as From address</td>
                </tr>
            </tbody>
        </table>
        <p class="form-hint" style="margin:0.75rem 0 0;"><strong>Recommended:</strong> From name <code><?= h($emailGuide['from_name']) ?></code> · From email <code><?= h($emailGuide['from_email']) ?></code> · Contact (General) <code><?= h($emailGuide['contact_email']) ?></code> · Transport <strong>SMTP</strong> with that mailbox’s cPanel credentials.</p>
        <p class="form-hint" style="margin:0.5rem 0 0;">You run a <strong>registration portal only</strong> — not a security employer. All emails must use your portal name and <code>noreply@yourdomain.com</code>. Never use another company’s name in From name or subject. Optional “Listed contractor” on events is third-party info only.</p>
    </div>

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="email">

        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="notify_staff_enabled" value="1"<?= ($settings['notify_staff_enabled'] ?? '1') === '1' ? ' checked' : '' ?>>
                Email staff when registration is approved or rejected (one email per person, all shifts together)
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="notify_staff_shift_alerts" value="1"<?= ($settings['notify_staff_shift_alerts'] ?? '1') === '1' ? ' checked' : '' ?>>
                Email staff when a shift is activated or more lads are needed — only people who have not already picked that shift
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="email_show_pay_rate" value="1"<?= ($settings['email_show_pay_rate'] ?? '1') === '1' ? ' checked' : '' ?>>
                Show hourly pay rate in staff emails (shift alerts, reminders, approval pass, check-in emails)
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="notify_on_registration" value="1"<?= ($settings['notify_on_registration'] ?? '0') === '1' ? ' checked' : '' ?>>
                Email staff when registration is received — one email listing all shifts selected
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="notify_on_checkin" value="1"<?= ($settings['notify_on_checkin'] ?? '1') === '1' ? ' checked' : '' ?>>
                Email staff when they sign in / check in (off by default — reduces email volume)
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="notify_ops_on_checkin" value="1"<?= ($settings['notify_ops_on_checkin'] ?? '0') === '1' ? ' checked' : '' ?>>
                Email ops team when any staff member signs in (uses admin alert emails from Settings → General)
            </label>
        </div>

        <h4 class="form-section-title form-group--full">Automated daily reminders</h4>
        <p class="form-hint form-group--full">Run once per day via cron or the button below. Reminders go only to staff with an incomplete profile (not verified). Event reminders stop automatically after each event ends.</p>

        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="reminder_daily_enabled" value="1"<?= ($settings['reminder_daily_enabled'] ?? '1') === '1' ? ' checked' : '' ?>>
                Daily reminder digest (one email per person per day; starts the day after sign-up)
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="reminder_signup_nudge_enabled" value="1"<?= ($settings['reminder_signup_nudge_enabled'] ?? '1') === '1' ? ' checked' : '' ?>>
                Delayed emails for other upcoming events they have not signed up for
            </label>
        </div>
        <div class="form-group">
            <label class="form-label" for="reminder_signup_nudge_delay_days">Signup nudge delay (days)</label>
            <input class="form-input" type="number" id="reminder_signup_nudge_delay_days" name="reminder_signup_nudge_delay_days" min="0" max="30" value="<?= h($settings['reminder_signup_nudge_delay_days'] ?? '2') ?>">
            <p class="form-hint">Wait this many days after first registration before the first nudge.</p>
        </div>
        <div class="form-group">
            <label class="form-label" for="reminder_signup_nudge_interval_days">Signup nudge interval (days)</label>
            <input class="form-input" type="number" id="reminder_signup_nudge_interval_days" name="reminder_signup_nudge_interval_days" min="1" max="30" value="<?= h($settings['reminder_signup_nudge_interval_days'] ?? '3') ?>">
            <p class="form-hint">Minimum days between nudge emails for the same person.</p>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="reminder_cron_key">Cron secret key (optional)</label>
            <input class="form-input" type="text" id="reminder_cron_key" name="reminder_cron_key" value="<?= h($settings['reminder_cron_key'] ?? '') ?>" autocomplete="off" placeholder="Random secret for web cron URL">
            <p class="form-hint">Web cron URL: <code>cron/daily-reminders.php?key=YOUR_KEY</code> — or use CLI: <code>php cron/daily-reminders.php</code></p>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label" for="mail_bcc_copy">Copy of every email</label>
            <input class="form-input" type="text" id="mail_bcc_copy" name="mail_bcc_copy" value="<?= h($settings['mail_bcc_copy'] ?? '') ?>">
            <p class="form-hint">Leave blank. Staff emails go only to the staff member.</p>
        </div>
        <div class="form-group">
            <label class="form-label" for="mail_from_name">From name</label>
            <input class="form-input" type="text" id="mail_from_name" name="mail_from_name" value="<?= h($settings['mail_from_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="mail_from_email">From email</label>
            <input class="form-input" type="email" id="mail_from_email" name="mail_from_email" value="<?= h($settings['mail_from_email'] ?? '') ?>" placeholder="<?= h($emailGuide['from_email']) ?>">
            <p class="form-hint">Use <code>@<?= h(getMainSiteEmailDomain($pdo)) ?></code> (main domain), not <code>@register.…</code>. Must match the SMTP mailbox you authenticate with.</p>
        </div>
        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="mail_transport">Email transport</label>
            <select class="form-select" id="mail_transport" name="mail_transport">
                <option value="php_mail"<?= ($settings['mail_transport'] ?? 'php_mail') === 'php_mail' ? ' selected' : '' ?>>PHP mail() — server default</option>
                <option value="smtp"<?= ($settings['mail_transport'] ?? '') === 'smtp' ? ' selected' : '' ?>>SMTP</option>
                <option value="log"<?= ($settings['mail_transport'] ?? '') === 'log' ? ' selected' : '' ?>>Log only (local dev)</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="smtp_host">SMTP host</label>
            <input class="form-input" type="text" id="smtp_host" name="smtp_host" value="<?= h($settings['smtp_host'] ?? '') ?>" placeholder="<?= h(getDefaultSmtpHost($pdo)) ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="smtp_port">SMTP port</label>
            <input class="form-input" type="number" id="smtp_port" name="smtp_port" value="<?= h($settings['smtp_port'] ?? '587') ?>" min="1" max="65535">
        </div>
        <div class="form-group">
            <label class="form-label" for="smtp_encryption">Encryption</label>
            <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                <option value="tls"<?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? ' selected' : '' ?>>TLS</option>
                <option value="ssl"<?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? ' selected' : '' ?>>SSL</option>
                <option value="none"<?= ($settings['smtp_encryption'] ?? '') === 'none' ? ' selected' : '' ?>>None</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="smtp_username">SMTP username</label>
            <input class="form-input" type="text" id="smtp_username" name="smtp_username" value="<?= h($settings['smtp_username'] ?? '') ?>" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-label" for="smtp_password">SMTP password</label>
            <input class="form-input" type="password" id="smtp_password" name="smtp_password" autocomplete="new-password" placeholder="<?= ($settings['smtp_password'] ?? '') !== '' ? 'Saved — leave blank to keep' : '' ?>">
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--primary">Save email settings</button>
        </div>
    </form>

    <form method="post" class="form-grid settings-form" style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--admin-border, #e2e8f0);">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="run_reminders">
        <div class="form-group form-group--full">
            <label class="form-label">Run reminders now</label>
            <p class="form-hint">Sends any due daily event reminders and signup nudges immediately (same as the daily cron job).</p>
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--secondary">Run daily reminders now</button>
        </div>
    </form>

    <form method="post" class="form-grid settings-form" style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--admin-border, #e2e8f0);">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="test_email">
        <div class="form-group form-group--full">
            <label class="form-label form-label--required" for="test_email_to">Send test email to</label>
            <input class="form-input" type="email" id="test_email_to" name="test_email_to" placeholder="you@example.com" required>
        </div>
        <div class="form-actions form-group--full">
            <button type="submit" class="btn btn--secondary">Send test email</button>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
