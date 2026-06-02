<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
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

    <form method="post" class="form-grid settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="email">

        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="notify_staff_enabled" value="1"<?= ($settings['notify_staff_enabled'] ?? '1') === '1' ? ' checked' : '' ?>>
                Email staff when registration is approved or rejected
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="notify_on_registration" value="1"<?= ($settings['notify_on_registration'] ?? '0') === '1' ? ' checked' : '' ?>>
                Email staff when registration is received (pending)
            </label>
        </div>
        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="notify_on_checkin" value="1"<?= ($settings['notify_on_checkin'] ?? '1') === '1' ? ' checked' : '' ?>>
                Email staff when they sign in / check in to an event
            </label>
        </div>

        <h4 class="form-section-title form-group--full">Automated daily reminders</h4>
        <p class="form-hint form-group--full">Run once per day via cron or the button below. Event reminders stop automatically after each event ends.</p>

        <div class="form-group form-group--full">
            <label class="form-radio">
                <input type="checkbox" name="reminder_daily_enabled" value="1"<?= ($settings['reminder_daily_enabled'] ?? '1') === '1' ? ' checked' : '' ?>>
                Daily reminder for each event registration (from sign-up day until event ends)
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
        <div class="form-group">
            <label class="form-label" for="mail_from_name">From name</label>
            <input class="form-input" type="text" id="mail_from_name" name="mail_from_name" value="<?= h($settings['mail_from_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="mail_from_email">From email</label>
            <input class="form-input" type="email" id="mail_from_email" name="mail_from_email" value="<?= h($settings['mail_from_email'] ?? '') ?>">
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
            <input class="form-input" type="text" id="smtp_host" name="smtp_host" value="<?= h($settings['smtp_host'] ?? '') ?>">
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
