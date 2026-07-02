<?php

declare(strict_types=1);

function mobileParseJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function mobileBearerToken(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'authorization') {
                    $header = (string) $value;
                    break;
                }
            }
        }
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        return $m[1];
    }

    return '';
}

function mobileClientIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return $ip !== '' ? $ip : '0.0.0.0';
}

function mobileUserAgent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function mobileValidateDeviceId(string $deviceId): bool
{
    $deviceId = trim($deviceId);

    return $deviceId !== '' && preg_match('/^[a-zA-Z0-9._-]{8,64}$/', $deviceId) === 1;
}

function mobileStaffSummary(array $staff, PDO $pdo): array
{
    require_once __DIR__ . '/../staff-profile-gate.php';
    require_once __DIR__ . '/../staff-onboarding.php';

    $blocked = staffNeedsProfileForm($pdo, $staff);

    return [
        'id'                        => (int) ($staff['id'] ?? 0),
        'email'                     => (string) ($staff['email'] ?? ''),
        'first_name'                => (string) ($staff['first_name'] ?? ''),
        'surname'                   => (string) ($staff['surname'] ?? ''),
        'profile_complete'          => isStaffOnboardingComplete($staff),
        'profile_reverify_required' => staffRequiresProfileReverify($staff),
        'profile_gate_blocked'      => $blocked,
    ];
}
