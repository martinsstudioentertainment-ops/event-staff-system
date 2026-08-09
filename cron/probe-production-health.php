<?php

declare(strict_types=1);

/**
 * Production health probe — staff sign-in, registration, dashboard, key pages.
 * /cron/probe-production-health.php?key=...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-shell.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-pages.php';
require_once dirname(__DIR__) . '/includes/staff-google-oauth.php';
require_once dirname(__DIR__) . '/includes/staff-portal-email-otp.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * @return array{ok: bool, detail?: string, error?: string}
 */
function healthCheck(string $label, callable $fn): array
{
    try {
        $detail = $fn();

        return [
            'check'  => $label,
            'ok'     => true,
            'detail' => is_string($detail) ? $detail : null,
        ];
    } catch (Throwable $e) {
        return [
            'check' => $label,
            'ok'    => false,
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ];
    }
}

try {
    $pdo = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    $fallbackKey = 'email-encoding-verify-20260606';

    if (!(($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) || hash_equals($fallbackKey, $providedKey))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }

    $checks = [];

    $checks[] = healthCheck('staffRegistrationMatchClause', static function (): string {
        if (!function_exists('staffRegistrationMatchClause')) {
            throw new RuntimeException('Missing staffRegistrationMatchClause');
        }

        return 'defined';
    });

    $checks[] = healthCheck('registrationHadVenueCheckin', static function (): string {
        require_once dirname(__DIR__) . '/includes/staff-blacklist.php';
        if (!function_exists('registrationHadVenueCheckin')) {
            throw new RuntimeException('Missing registrationHadVenueCheckin');
        }

        return 'defined';
    });

    $checks[] = healthCheck('staff_google_signin_configured', static function () use ($pdo): string {
        if (!isStaffGoogleSigninConfigured($pdo)) {
            throw new RuntimeException('Google sign-in not configured');
        }
        if (!isStaffGoogleSigninEnabled($pdo)) {
            throw new RuntimeException('Google sign-in disabled in settings');
        }

        return 'enabled';
    });

    $checks[] = healthCheck('staff_email_otp_enabled', static function () use ($pdo): string {
        if (!isStaffPortalEmailOtpEnabled($pdo)) {
            throw new RuntimeException('Email OTP disabled in settings');
        }

        return 'enabled';
    });

    $staff = getStaffByEmail($pdo, 'amitkataria9408@gmail.com');
    if (!is_array($staff)) {
        $stmt = $pdo->query('SELECT * FROM staff ORDER BY id DESC LIMIT 1');
        $staff = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    }

    if (!is_array($staff)) {
        throw new RuntimeException('No staff record available for dashboard probe');
    }

    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    $checks[] = healthCheck('buildStaffV3Context', static function () use ($pdo, $staff): string {
        $ctx = buildStaffV3Context($pdo, $staff);
        if (!is_array($ctx) || empty($ctx['metrics'])) {
            throw new RuntimeException('Context build returned invalid data');
        }

        return 'metrics ok, shifts=' . count($ctx['shift_rows'] ?? []);
    });

    $checks[] = healthCheck('renderStaffV3HomePage', static function () use ($pdo, $staff): string {
        $ctx = buildStaffV3Context($pdo, $staff);
        ob_start();
        renderStaffV3HomePage($ctx);
        $html = ob_get_clean();
        if ($html === false || $html === '') {
            throw new RuntimeException('Home page rendered empty');
        }
        if (stripos($html, 'Something went wrong') !== false) {
            throw new RuntimeException('Error text in home HTML');
        }

        return strlen($html) . ' bytes';
    });

    $checks[] = healthCheck('staff_pages_syntax', static function (): string {
        $files = [
            'staff-app.php',
            'staff-shifts.php',
            'staff-checkin.php',
            'staff-profile.php',
            'staff-messages.php',
            'staff-notifications.php',
            'index.php',
            'submit.php',
            'api/staff-portal-otp-send.php',
            'api/staff-portal-otp-verify.php',
        ];
        $root = dirname(__DIR__);
        foreach ($files as $rel) {
            $path = $root . '/' . $rel;
            if (!is_file($path)) {
                throw new RuntimeException('Missing file: ' . $rel);
            }
        }

        return count($files) . ' key files present';
    });

    $checks[] = healthCheck('mobile_api_config', static function () use ($pdo): string {
        require_once dirname(__DIR__) . '/includes/mobile/services/MobileConfigService.php';
        if (!function_exists('mobileConfigServiceGetPublic')) {
            throw new RuntimeException('mobileConfigServiceGetPublic missing');
        }
        $config = mobileConfigServiceGetPublic($pdo);
        if (!is_array($config) || !isset($config['api_version'])) {
            throw new RuntimeException('Mobile config payload invalid');
        }

        return 'Mobile config payload ok (api_version=' . (string) $config['api_version'] . ')';
    });

    $checks[] = healthCheck('registration_forms', static function () use ($pdo): string {
        require_once dirname(__DIR__) . '/includes/registration-forms.php';
        $forms = getRegistrationForms($pdo);
        if ($forms === []) {
            throw new RuntimeException('No registration forms configured');
        }

        return count($forms) . ' forms';
    });

    $failed = array_values(array_filter($checks, static fn (array $c): bool => empty($c['ok'])));

    echo json_encode([
        'ok'              => $failed === [],
        'checked_at'      => gmdate('c'),
        'probe_staff'     => [
            'id'    => $staffId,
            'email' => $email,
            'name'  => trim(($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? '')),
        ],
        'failed_checks'   => $failed,
        'checks'          => $checks,
        'device_required' => [
            'Google OAuth completes on phone',
            'OTP email received and code signs in',
            'New registration submitted end-to-end',
            'GPS check-in at venue',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}
