<?php
/**
 * Event Staff System — Global settings (database-backed)
 */

require_once __DIR__ . '/../config.php';

/** @var array<string, string>|null */
$settingsCache = null;

function getAllSettings(PDO $pdo): array
{
    global $settingsCache;

    if ($settingsCache !== null) {
        return $settingsCache;
    }

    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
        $settingsCache = [];
        foreach ($rows as $row) {
            $settingsCache[$row['setting_key']] = (string) $row['setting_value'];
        }
        return $settingsCache;
    } catch (PDOException $e) {
        return getDefaultSettings();
    }
}

function getDefaultSettings(): array
{
    return [
        'site_name'              => 'Event Staff System',
        'notify_staff_enabled'   => '1',
        'notify_staff_shift_alerts' => '1',
        'email_show_pay_rate'       => '1',
        'notify_on_registration' => '0',
        'notify_on_checkin'      => '0',
        'notify_ops_on_checkin'  => '0',
        'staff_profile_update_required' => '0',
        'staff_profile_refresh_at'      => '',
        'reminder_daily_enabled' => '1',
        'reminder_signup_nudge_enabled' => '1',
        'reminder_signup_nudge_delay_days' => '2',
        'reminder_signup_nudge_interval_days' => '3',
        'reminder_cron_key'      => '',
        'auto_no_show_grace_hours_after_event' => '30',
        'mail_from_name'         => 'Event Staff System',
        'mail_from_email'        => 'noreply@event-staff.local',
        'mail_transport'         => 'php_mail',
        'smtp_host'              => '',
        'smtp_port'              => '587',
        'smtp_encryption'        => 'tls',
        'smtp_username'            => '',
        'smtp_password'            => '',
        'theme_primary_color'      => '',
        'theme_font_family'        => 'poppins',
        'theme_preset'             => 'security-classic-blue',
        'registration_site_url'    => '',
        'admin_site_url'           => '',
        'apply_site_url'           => '',
        'company_name'             => 'Event Staff Ireland',
        'company_tagline'          => 'Free registration portal — helping people find security and event work (we are not your employer)',
        'company_email'            => 'info@example.com',
        'company_phone'            => '+353 1 000 0000',
        'company_whatsapp'         => '+353 1 000 0000',
        'company_whatsapp_group'       => '',
        'company_whatsapp_group_label' => 'Join our staff WhatsApp group',
        'company_whatsapp_group_hint'  => 'Get shift updates, reminders, and last-minute calls. Tap to open WhatsApp — you may need approval from an admin to join.',
        'notify_admin_on_registration' => '1',
        'notify_admin_email'           => '',
        'company_about'            => 'Many people want security or event work but do not know where to start. We run a simple registration portal so you can apply for upcoming festivals, concerts, and events in one place — no confusing agencies, no endless forms.',
        'staff_display_hourly_rate' => '15.41',
        'email_primary_color'       => '#1e3a8a',
        'email_secondary_color'     => '#3b82f6',
        'email_button_color'        => '#2563eb',
        'google_maps_api_key'      => '',
        'signin_require_pps_last4' => '1',
        'system_layout_mode'         => 'dark',
        'system_timezone'            => 'Europe/Dublin',
        'system_currency'            => 'EUR',
        'system_date_format'         => 'd/m/Y',
        'system_language'            => 'en',
        'maintenance_mode'           => '0',
        'admin_2fa_required'         => '1',
        'admin_login_otp_enabled'    => '1',
        'admin_login_otp_email'      => 'olabodeoluwafemi2580@gmail.com',
        'activity_logging_enabled'   => '1',
        'auto_backup_enabled'        => '0',
        'google_sheets_sync_enabled' => '0',
        'google_sheets_drive_folder_id' => '',
        'google_sheets_template_id' => '',
        'staff_google_signin_enabled'  => '0',
        'staff_google_signin_required' => '1',
        'staff_google_oauth_redirect_uri' => '',
        'google_oauth_client_id' => '',
        'google_oauth_client_secret' => '',
        'google_oauth_refresh_token' => '',
        'google_oauth_access_token' => '',
        'google_oauth_token_expires' => '',
        'google_sheets_share_with_email' => '',
        'google_sheets_default_tab' => 'Registrations',
        'google_sheets_sync_quiet_enabled' => '0',
        'google_sheets_sync_quiet_start' => '22:00',
        'google_sheets_sync_quiet_end' => '07:00',
        'google_sheets_sync_min_interval_minutes' => '0',
        'google_sheets_apply_sync_interval_minutes' => '2',
        'google_sheets_last_live_sync_at' => '',
        'commission_rate_dsp'            => '0',
        'commission_rate_steward'        => '0',
        'commission_rate_static'         => '0',
        'commission_rate_fire_marshal'   => '0',
        'commission_rate_default'        => '0',
        'invoice_bank_name'          => '',
        'invoice_bank_iban'          => '',
        'invoice_bank_bic'           => '',
        'invoice_vat_number'         => '',
        'pwa_push_enabled'           => '1',
        'pwa_vapid_public_key'       => '',
        'pwa_vapid_private_key'      => '',
        'mobile_api_enabled'         => '0',
        'mobile_jwt_secret'          => '',
        'mobile_min_app_version'     => '1.0.0',
        'mobile_android_apk_path'    => '',
        'mobile_android_aab_path'    => '',
        'mobile_android_version_code'=> '',
        'mobile_android_released_at' => '',
        'mobile_jwt_access_ttl'      => '900',
        'mobile_jwt_refresh_days'    => '90',
        'fcm_project_id'             => '',
        'fcm_service_account_path'   => '',
        'mobile_portal_app_name'              => 'Olasentra',
        'mobile_portal_logo_path'             => '',
        'mobile_portal_splash_logo_path'        => '',
        'mobile_portal_login_logo_path'         => '',
        'mobile_portal_dashboard_logo_path'     => '',
        'mobile_portal_welcome_image_path'      => '',
        'mobile_portal_primary_color'           => '#1B1B1F',
        'mobile_portal_accent_color'            => '#E85D04',
        'mobile_portal_banner_title'            => '',
        'mobile_portal_banner_body'             => '',
        'mobile_portal_banner_image_path'       => '',
        'mobile_portal_announcements_json'      => '[]',
        'mobile_portal_help_links_json'         => '[]',
        'mobile_portal_contact_email'           => '',
        'mobile_portal_contact_phone'           => '',
        'mobile_portal_version_label'           => '',
        'mobile_portal_version_notes'           => '',
        'mobile_portal_maintenance_enabled'     => '0',
        'mobile_portal_maintenance_message'     => '',
        'mobile_portal_force_update_message'    => '',
        'mobile_portal_default_theme'           => 'dark',
        'mobile_portal_allow_theme_toggle'      => '0',
        'mobile_email_otp_enabled'              => '1',
        'staff_portal_email_otp_enabled'        => '1',
        'staff_portal_otp_ttl_seconds'          => '600',
        'mobile_email_otp_ttl_seconds'          => '600',
        'gps_max_accuracy_m'                    => '100',
    ];
}

function getSetting(PDO $pdo, string $key, ?string $default = null): string
{
    $settings = getAllSettings($pdo);
    if (array_key_exists($key, $settings)) {
        return $settings[$key];
    }

    $defaults = getDefaultSettings();
    return $default ?? ($defaults[$key] ?? '');
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    global $settingsCache;

    $stmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute(['key' => $key, 'value' => $value]);

    clearSettingsCache();
}

function clearSettingsCache(): void
{
    global $settingsCache;
    $settingsCache = null;
}

/**
 * @param array<string, string> $settings
 */
function saveSettings(PDO $pdo, array $settings): void
{
    foreach ($settings as $key => $value) {
        setSetting($pdo, $key, $value);
    }
}

function isNotifyStaffEnabled(PDO $pdo): bool
{
    return getSetting($pdo, 'notify_staff_enabled', '1') === '1';
}

function isNotifyStaffShiftAlertsEnabled(PDO $pdo): bool
{
    return getSetting($pdo, 'notify_staff_shift_alerts', '1') === '1';
}

function isNotifyOnCheckinEnabled(PDO $pdo): bool
{
    return getSetting($pdo, 'notify_on_checkin', '1') === '1';
}

function getSiteName(PDO $pdo): string
{
    $company = trim(getSetting($pdo, 'company_name', ''));
    if ($company !== '') {
        return $company;
    }

    return getSetting($pdo, 'site_name', 'Event Staff System');
}

/**
 * Keep public branding in sync when company name is updated.
 *
 * @param array<string, string> $settings
 * @return array<string, string>
 */
function syncCompanyBrandingSettings(PDO $pdo, array $settings): array
{
    $company = trim($settings['company_name'] ?? '');
    if ($company === '') {
        return $settings;
    }

    $currentSite = trim(getSetting($pdo, 'site_name', ''));
    $currentFrom = trim(getSetting($pdo, 'mail_from_name', ''));
    $defaults    = getDefaultSettings();

    if ($currentSite === '' || $currentSite === ($defaults['site_name'] ?? '') || $currentSite === trim(getSetting($pdo, 'company_name', ''))) {
        $settings['site_name'] = $company;
    }

    if ($currentFrom === '' || $currentFrom === ($defaults['mail_from_name'] ?? '') || $currentFrom === $currentSite) {
        $settings['mail_from_name'] = $company;
    }

    return $settings;
}
