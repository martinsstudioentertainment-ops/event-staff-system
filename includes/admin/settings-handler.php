<?php

require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/../reminders.php';
require_once __DIR__ . '/../admin-ui-settings.php';
require_once __DIR__ . '/../system-settings.php';
require_once __DIR__ . '/../i18n.php';

/**
 * @return array{error: string, success: string, settings: array<string, string>}
 */
function processSettingsPost(PDO $pdo, array $adminUser, string $expectedAction): array
{
    $error    = '';
    $success  = '';
    $settings = getAllSettings($pdo);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return compact('error', 'success', 'settings');
    }

    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        return ['error' => 'Invalid request. Please try again.', 'success' => '', 'settings' => $settings];
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action !== $expectedAction) {
        return compact('error', 'success', 'settings');
    }

    if ($action === 'site') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change site settings.', 'success' => '', 'settings' => $settings];
        }
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $regUrl   = normalizePublicSiteUrl((string) ($_POST['registration_site_url'] ?? ''));
        $adminUrl = normalizePublicSiteUrl((string) ($_POST['admin_site_url'] ?? ''));

        if ($siteName === '' || trim((string) ($_POST['company_name'] ?? '')) === '') {
            $error = 'Registration site name and company name are required.';
        } elseif (trim((string) ($_POST['company_email'] ?? '')) !== '' && !filter_var((string) $_POST['company_email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid company email address.';
        } elseif (!isValidPublicSiteUrl($regUrl) || !isValidPublicSiteUrl($adminUrl)) {
            $error = 'Please enter valid site URLs starting with http:// or https://, or leave them blank.';
        } else {
            $toSave = syncCompanyBrandingSettings($pdo, [
                'site_name'              => $siteName,
                'registration_site_url'  => $regUrl,
                'admin_site_url'         => $adminUrl,
                'company_name'           => trim((string) ($_POST['company_name'] ?? '')),
                'company_tagline'        => trim((string) ($_POST['company_tagline'] ?? '')),
                'company_email'          => trim((string) ($_POST['company_email'] ?? '')),
                'company_phone'          => trim((string) ($_POST['company_phone'] ?? '')),
                'company_whatsapp'       => trim((string) ($_POST['company_whatsapp'] ?? '')),
                'company_whatsapp_group' => trim((string) ($_POST['company_whatsapp_group'] ?? '')),
                'company_about'          => trim((string) ($_POST['company_about'] ?? '')),
            ]);
            saveSettings($pdo, $toSave);
            $settings = getAllSettings($pdo);
            $success  = 'Site settings saved. Company name updates the public homepage header, footer, and registration form.';
        }
    } elseif ($action === 'email') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change email settings.', 'success' => '', 'settings' => $settings];
        }
        $fromEmail = trim((string) ($_POST['mail_from_email'] ?? ''));
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid from email address.';
        } else {
            $transport = (string) ($_POST['mail_transport'] ?? 'php_mail');
            if (!in_array($transport, ['php_mail', 'smtp', 'log'], true)) {
                $transport = 'php_mail';
            }
            $encryption = (string) ($_POST['smtp_encryption'] ?? 'tls');
            if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
                $encryption = 'tls';
            }
            $emailSettings = [
                'notify_staff_enabled'   => !empty($_POST['notify_staff_enabled']) ? '1' : '0',
                'notify_on_registration' => !empty($_POST['notify_on_registration']) ? '1' : '0',
                'notify_on_checkin'      => !empty($_POST['notify_on_checkin']) ? '1' : '0',
                'reminder_daily_enabled' => !empty($_POST['reminder_daily_enabled']) ? '1' : '0',
                'reminder_signup_nudge_enabled' => !empty($_POST['reminder_signup_nudge_enabled']) ? '1' : '0',
                'reminder_signup_nudge_delay_days' => (string) max(0, (int) ($_POST['reminder_signup_nudge_delay_days'] ?? 2)),
                'reminder_signup_nudge_interval_days' => (string) max(1, (int) ($_POST['reminder_signup_nudge_interval_days'] ?? 3)),
                'reminder_cron_key'      => trim((string) ($_POST['reminder_cron_key'] ?? '')),
                'mail_from_name'         => trim((string) ($_POST['mail_from_name'] ?? '')),
                'mail_from_email'        => $fromEmail,
                'mail_transport'         => $transport,
                'smtp_host'              => trim((string) ($_POST['smtp_host'] ?? '')),
                'smtp_port'              => trim((string) ($_POST['smtp_port'] ?? '587')),
                'smtp_encryption'        => $encryption,
                'smtp_username'          => trim((string) ($_POST['smtp_username'] ?? '')),
            ];
            $newPassword = (string) ($_POST['smtp_password'] ?? '');
            if ($newPassword !== '') {
                $emailSettings['smtp_password'] = $newPassword;
            }
            saveSettings($pdo, $emailSettings);
            $settings = getAllSettings($pdo);
            $success  = 'Email settings saved.';
        }
    } elseif ($action === 'run_reminders') {
        $stats   = runDailyReminders($pdo);
        $success = sprintf(
            'Reminders sent — daily event: %d, signup nudges: %d%s.',
            $stats['daily_sent'],
            $stats['nudge_sent'],
            $stats['errors'] > 0 ? ' (' . $stats['errors'] . ' failed)' : ''
        );
    } elseif ($action === 'test_email') {
        $testTo = trim((string) ($_POST['test_email_to'] ?? ''));
        $result = sendTestEmail($pdo, $testTo);
        if ($result === true) {
            $success = 'Test email sent to ' . $testTo . '.';
        } else {
            $error = (string) $result;
        }
    } elseif ($action === 'ui_controls') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change UI settings.', 'success' => '', 'settings' => $settings];
        }
        $uiInput = [
            'ui_scale'      => (string) ($_POST['ui_scale'] ?? ''),
            'card_padding'  => (string) ($_POST['card_padding'] ?? ''),
            'input_height'  => (string) ($_POST['input_height'] ?? ''),
            'table_density' => (string) ($_POST['table_density'] ?? ''),
            'border_radius' => (string) ($_POST['border_radius'] ?? ''),
        ];
        $validationError = validateAdminUiSettingsInput($uiInput);
        if ($validationError !== null) {
            $error = $validationError;
        } else {
            saveAdminUiSettings($pdo, $uiInput);
            $settings = getAllSettings($pdo);
            $success  = 'Global UI controls saved.';
        }
    } elseif ($action === 'theme') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change theme settings.', 'success' => '', 'settings' => $settings];
        }
        $preset  = trim((string) ($_POST['theme_preset'] ?? ''));
        $color   = trim((string) ($_POST['theme_primary_color'] ?? ''));
        $font    = strtolower(trim((string) ($_POST['theme_font_family'] ?? '')));
        $presets = getThemePresets();

        if (!array_key_exists($preset, $presets)) {
            $error = 'Please select a valid interface theme.';
        } elseif ($color !== '' && !isValidThemeColor($color)) {
            $error = 'Please enter a valid hex color (e.g. #2563eb).';
        } elseif ($font !== '' && !array_key_exists($font, getThemeFontOptions())) {
            $error = 'Please select a valid font.';
        } else {
            saveSettings($pdo, [
                'theme_preset'        => $preset,
                'theme_primary_color' => $color,
                'theme_font_family'   => $font !== '' ? $font : ($presets[$preset]['font'] ?? 'poppins'),
            ]);
            $settings = getAllSettings($pdo);
            $success  = 'Interface theme saved.';
        }
    } elseif ($action === 'system') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change system settings.', 'success' => '', 'settings' => $settings];
        }
        $systemInput = [
            'layout_mode'              => (string) ($_POST['layout_mode'] ?? ''),
            'timezone'                 => (string) ($_POST['timezone'] ?? ''),
            'currency'                 => (string) ($_POST['currency'] ?? ''),
            'date_format'              => (string) ($_POST['date_format'] ?? ''),
            'language'                 => (string) ($_POST['language'] ?? ''),
            'maintenance_mode'         => (string) ($_POST['maintenance_mode'] ?? ''),
            'admin_2fa_required'       => (string) ($_POST['admin_2fa_required'] ?? ''),
            'activity_logging_enabled' => (string) ($_POST['activity_logging_enabled'] ?? ''),
            'auto_backup_enabled'      => (string) ($_POST['auto_backup_enabled'] ?? ''),
        ];
        $validationError = validateSystemSettingsInput($systemInput);
        if ($validationError !== null) {
            $error = $validationError;
        } else {
            saveSystemSettings($pdo, $systemInput);
            if (isset($_POST['compact_mode']) && trim((string) $_POST['compact_mode']) !== '') {
                $ui = getAdminUiSettings($pdo);
                $ui['ui_scale'] = normalizeAdminUiScale((string) $_POST['compact_mode']);
                saveAdminUiSettings($pdo, $ui);
            }
            applySystemRuntimeSettings($pdo);
            bootstrapAppLocale($pdo);
            $settings = getAllSettings($pdo);
            $success  = 'System settings saved.';
        }
    } elseif ($action === 'security') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change security settings.', 'success' => '', 'settings' => $settings];
        }
        saveSettings($pdo, [
            'signin_require_pps_last4' => !empty($_POST['signin_require_pps_last4']) ? '1' : '0',
            'google_maps_api_key'    => trim((string) ($_POST['google_maps_api_key'] ?? '')),
        ]);
        $settings = getAllSettings($pdo);
        $success  = 'Security settings saved.';
    } elseif ($action === 'commission_rates') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change commission rates.', 'success' => '', 'settings' => $settings];
        }
        $rates = [
            'commission_rate_dsp'     => max(0, round((float) ($_POST['commission_rate_dsp'] ?? 0), 2)),
            'commission_rate_steward' => max(0, round((float) ($_POST['commission_rate_steward'] ?? 0), 2)),
            'commission_rate_static'  => max(0, round((float) ($_POST['commission_rate_static'] ?? 0), 2)),
            'commission_rate_default' => max(0, round((float) ($_POST['commission_rate_default'] ?? 0), 2)),
        ];
        saveSettings($pdo, array_map(static fn (float $v): string => number_format($v, 2, '.', ''), $rates));
        $settings = getAllSettings($pdo);
        $success  = 'Default commission rates saved.';
    } elseif ($action === 'invoice_payment') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change invoice payment details.', 'success' => '', 'settings' => $settings];
        }
        saveSettings($pdo, [
            'invoice_bank_name'  => trim((string) ($_POST['invoice_bank_name'] ?? '')),
            'invoice_bank_iban'  => trim((string) ($_POST['invoice_bank_iban'] ?? '')),
            'invoice_bank_bic'   => trim((string) ($_POST['invoice_bank_bic'] ?? '')),
            'invoice_vat_number' => trim((string) ($_POST['invoice_vat_number'] ?? '')),
        ]);
        $settings = getAllSettings($pdo);
        $success  = 'Invoice payment details saved.';
    } elseif ($action === 'google_sheets') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change Google Sheets settings.', 'success' => '', 'settings' => $settings];
        }
        require_once __DIR__ . '/../google-sheets-sync.php';

        saveSettings($pdo, [
            'google_sheets_sync_enabled' => !empty($_POST['google_sheets_sync_enabled']) ? '1' : '0',
        ]);

        $success = 'Google Sheets settings saved.';

        if (!empty($_FILES['google_service_account']['tmp_name'])) {
            $upload = saveGoogleServiceAccountUpload($_FILES['google_service_account']);
            if (!$upload['ok']) {
                return ['error' => $upload['message'], 'success' => '', 'settings' => getAllSettings($pdo)];
            }
            $success = $upload['message'];
        }

        $settings = getAllSettings($pdo);
    } elseif ($action === 'pwa_settings') {
        if (!adminCan('settings')) {
            return ['error' => 'You do not have permission to change PWA settings.', 'success' => '', 'settings' => $settings];
        }
        require_once __DIR__ . '/../pwa-push.php';
        if (!empty($_POST['generate_vapid'])) {
            if (!ensureVapidKeys($pdo)) {
                return ['error' => 'Could not generate VAPID keys (OpenSSL required).', 'success' => '', 'settings' => getAllSettings($pdo)];
            }
            $settings = getAllSettings($pdo);
            $success  = 'VAPID keys generated for push notifications.';
        } else {
            saveSettings($pdo, [
                'pwa_push_enabled' => !empty($_POST['pwa_push_enabled']) ? '1' : '0',
            ]);
            $settings = getAllSettings($pdo);
            $success  = 'PWA settings saved.';
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            $result = updateAdminPassword($pdo, (int) $adminUser['id'], $current, $new);
            if ($result === true) {
                $success = 'Password updated successfully.';
            } else {
                $error = (string) $result;
            }
        }
    }

    return compact('error', 'success', 'settings');
}
