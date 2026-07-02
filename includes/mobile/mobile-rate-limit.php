<?php

declare(strict_types=1);

/**
 * Simple file-based rate limiter for mobile API (per key / window).
 */
function mobileThrottle(string $key, int $maxAttempts, int $windowSeconds): bool
{
    $key  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $key) ?? 'unknown';
    $dir  = sys_get_temp_dir() . '/olasentra-mobile-api';
    $file = $dir . '/rl_' . hash('sha256', $key) . '.json';

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $now  = time();
    $data = ['count' => 0, 'reset' => $now + $windowSeconds];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    if (($data['reset'] ?? 0) < $now) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    }

    $data['count'] = (int) ($data['count'] ?? 0) + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    return $data['count'] <= $maxAttempts;
}

function mobileThrottleOrFail(string $bucket, int $maxAttempts, int $windowSeconds): void
{
    if (mobileThrottle($bucket, $maxAttempts, $windowSeconds)) {
        return;
    }

    require_once __DIR__ . '/mobile-response.php';
    mobileJsonError('Too many requests. Please try again later.', 429, 'RATE_LIMITED');
}

function mobileAuthThrottle(string $ip, string $email = ''): void
{
    mobileThrottleOrFail('auth_ip_' . $ip, 10, 60);
    if ($email !== '') {
        mobileThrottleOrFail('auth_email_' . md5(strtolower($email)), 5, 60);
    }
}
