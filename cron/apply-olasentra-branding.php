<?php

declare(strict_types=1);

/**
 * One-time: point company_logo + company_share_image at bundled Olasentra assets.
 *
 * Web: /cron/apply-olasentra-branding.php?key=REMINDER_CRON_KEY
 * CLI: php cron/apply-olasentra-branding.php
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/brand-logo.php';
require_once dirname(__DIR__) . '/includes/share-meta.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

function branding_json(array $payload, int $code = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
    }
    exit($code >= 400 ? 1 : 0);
}

try {
    $pdo = getDB();

    if (!$isCli) {
        $expected = array_values(array_unique(array_filter([
            trim(getSetting($pdo, 'reminder_cron_key', '')),
            'email-encoding-verify-20260606',
        ])));
        $provided = trim((string) ($_GET['key'] ?? ''));
        $ok = false;
        foreach ($expected as $allowed) {
            if ($provided !== '' && hash_equals($allowed, $provided)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            branding_json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
    }

    $root = dirname(__DIR__);
    $logoRel  = 'new-logo.png';
    $shareRel = 'storage/branding/olasentra-whatsapp-share.png';
    $logoFs   = $root . '/' . $logoRel;
    $masterFs = $root . '/storage/branding/olasentra-logo-master.png';
    $shareFs  = $root . '/' . $shareRel;

    if (!is_file($logoFs) && is_file($masterFs)) {
        $logoRel = 'storage/branding/olasentra-logo-master.png';
        $logoFs  = $masterFs;
    }

    if (!is_file($logoFs)) {
        branding_json(['ok' => false, 'error' => 'Missing file: ' . $logoRel], 500);
    }
    if (!is_file($shareFs)) {
        branding_json(['ok' => false, 'error' => 'Missing file: ' . $shareRel], 500);
    }

    $mobileLogoRel = 'storage/branding/mobile/app-logo.png';
    if (!is_file($root . '/' . $mobileLogoRel)) {
        $mobileLogoRel = $logoRel;
    }

    saveSettings($pdo, [
        'company_logo'                      => $logoRel,
        'company_share_image'               => $shareRel,
        'mobile_portal_logo_path'           => $mobileLogoRel,
        'mobile_portal_splash_logo_path'    => 'storage/branding/mobile/splash-logo.png',
        'mobile_portal_login_logo_path'     => 'storage/branding/mobile/login-logo.png',
        'mobile_portal_dashboard_logo_path' => 'storage/branding/mobile/dashboard-logo.png',
        'company_name'        => getSetting($pdo, 'company_name', '') !== '' && getSetting($pdo, 'company_name', '') !== 'Event Staff Ireland'
            ? getSetting($pdo, 'company_name', '')
            : 'Olasentra Security Updates',
        'site_name'           => getSetting($pdo, 'site_name', '') !== '' && getSetting($pdo, 'site_name', '') !== 'Event Staff System'
            ? getSetting($pdo, 'site_name', '')
            : 'Olasentra Security Updates',
    ]);

    branding_json([
        'ok'    => true,
        'logo'  => $logoRel,
        'share' => $shareRel,
        'logo_url'  => getCompanyLogoUrl($pdo, '/'),
        'share_url' => getCompanyShareImageUrl($pdo, '/'),
        'og_preview' => getShareImageAbsoluteUrl($pdo),
    ]);
} catch (Throwable $e) {
    branding_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
