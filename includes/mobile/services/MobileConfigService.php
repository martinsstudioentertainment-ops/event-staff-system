<?php

declare(strict_types=1);

require_once __DIR__ . '/../../staff-google-oauth.php';
require_once __DIR__ . '/../../attendance-gps-phase1.php';
require_once __DIR__ . '/../../site-urls.php';
require_once __DIR__ . '/../../app-build-version.php';
require_once __DIR__ . '/../schema/mobile-api-schema.php';

function mobileConfigServiceGetPublic(PDO $pdo): array
{
    $policy = getStaffAuthPolicy($pdo);

    $config = [
        'api_version'               => '1',
        'min_app_version'           => getSetting($pdo, 'mobile_min_app_version', '1.0.0'),
        'mobile_api_enabled'        => mobileApiIsEnabled($pdo),
        'google_signin_enabled'     => $policy['google_signin_enabled'],
        'google_signin_required'    => $policy['google_signin_required'],
        'pps_signin_enabled'        => $policy['pps_signin_enabled'],
        'email_otp_enabled'         => $policy['mobile_email_otp_enabled'],
        'gps_attendance_v2_enabled' => isGpsAttendanceV2Enabled($pdo),
        'gps_max_accuracy_m'        => (int) getSetting($pdo, 'gps_max_accuracy_m', '100'),
        'features'                  => [
            'availability'   => true,
            'shift_response' => true,
            'offline_sync'   => true,
        ],
        'registration_site_url'       => rtrim(getRegistrationSiteUrl($pdo), '/'),
        'privacy_url'                 => rtrim(getRegistrationSiteUrl($pdo), '/') . '/privacy.php',
        'terms_url'                   => rtrim(getRegistrationSiteUrl($pdo), '/') . '/terms.php',
        'build'                       => getAppBuildVersionPublic(),
    ];

    $portalPath = __DIR__ . '/MobilePortalConfigService.php';
    if (is_file($portalPath)) {
        try {
            require_once $portalPath;
            if (function_exists('mobilePortalGetPublicConfig')) {
                $config['portal'] = mobilePortalGetPublicConfig($pdo);
            }
        } catch (Throwable $e) {
            error_log('[MobileConfig] portal: ' . $e->getMessage());
        }
    }

    $prefsPath = __DIR__ . '/MobilePreferencesService.php';
    if (is_file($prefsPath)) {
        try {
            require_once $prefsPath;
            if (function_exists('mobilePreferencesConfigOptions')) {
                $config['preference_options'] = mobilePreferencesConfigOptions($pdo);
            }
        } catch (Throwable $e) {
            error_log('[MobileConfig] preferences: ' . $e->getMessage());
        }
    }

    $androidPath = dirname(__DIR__, 2) . '/staff-app-android.php';
    if (is_file($androidPath)) {
        try {
            require_once $androidPath;
            if (function_exists('staffAppAndroidDownloadUrl')) {
                $apkDownloadUrl = staffAppAndroidDownloadUrl($pdo);
                if ($apkDownloadUrl !== '') {
                    $config['android_apk_download_url'] = $apkDownloadUrl;
                    if (function_exists('staffAppAndroidDownloadPageUrl')) {
                        $config['android_apk_page_url'] = staffAppAndroidDownloadPageUrl($pdo);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[MobileConfig] android: ' . $e->getMessage());
        }
    }

    return $config;
}
