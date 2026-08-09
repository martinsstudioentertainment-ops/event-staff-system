<?php

declare(strict_types=1);

require_once __DIR__ . '/../schema/mobile-api-schema.php';
require_once __DIR__ . '/../../staff-google-oauth.php';
require_once __DIR__ . '/../../signin-display.php';
require_once __DIR__ . '/../../attendance-gps-phase1.php';
require_once __DIR__ . '/../../site-urls.php';
require_once __DIR__ . '/../../staff-app-android.php';
require_once __DIR__ . '/MobilePortalConfigService.php';
require_once __DIR__ . '/MobilePreferencesService.php';

function mobileConfigServiceGetPublic(PDO $pdo): array
{
    $ppsEnabled = isSigninPpsRequired($pdo);
    if (isStaffGoogleSigninRequired($pdo)) {
        $ppsEnabled = false;
    }

    $config = [
        'api_version'              => '1',
        'min_app_version'          => getSetting($pdo, 'mobile_min_app_version', '1.0.0'),
        'mobile_api_enabled'       => mobileApiIsEnabled($pdo),
        'google_signin_enabled'    => isStaffGoogleSigninEnabled($pdo),
        'google_signin_required'   => isStaffGoogleSigninRequired($pdo),
        'pps_signin_enabled'       => $ppsEnabled,
        'email_otp_enabled'        => getSetting($pdo, 'mobile_email_otp_enabled', '1') === '1',
        'gps_attendance_v2_enabled'=> isGpsAttendanceV2Enabled($pdo),
        'gps_max_accuracy_m'       => (int) getSetting($pdo, 'gps_max_accuracy_m', '100'),
        'features'                 => [
            'availability'   => true,
            'shift_response' => true,
            'offline_sync'   => true,
        ],
        'registration_site_url'    => rtrim(getRegistrationSiteUrl($pdo), '/'),
        'privacy_url'              => rtrim(getRegistrationSiteUrl($pdo), '/') . '/privacy.php',
        'terms_url'                => rtrim(getRegistrationSiteUrl($pdo), '/') . '/terms.php',
    ];

    $config['portal'] = mobilePortalGetPublicConfig($pdo);
    $config['preference_options'] = mobilePreferencesConfigOptions($pdo);

    $apkDownloadUrl = staffAppAndroidDownloadUrl($pdo);
    if ($apkDownloadUrl !== '') {
        $config['android_apk_download_url'] = $apkDownloadUrl;
        $config['android_apk_page_url']     = staffAppAndroidDownloadPageUrl($pdo);
    }

    return $config;
}
