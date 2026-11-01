<?php

declare(strict_types=1);

/**
 * Safe Google OAuth error logging and user-facing messages.
 * Never logs client secrets, authorization codes, or access/refresh tokens.
 */

/**
 * @return array{error: string, error_description: string, error_uri: string}
 */
function googleOAuthCallbackQueryErrors(): array
{
    return [
        'error'              => googleOAuthSanitizeCode((string) ($_GET['error'] ?? '')),
        'error_description'  => googleOAuthSanitizeDescription((string) ($_GET['error_description'] ?? '')),
        'error_uri'          => googleOAuthSanitizeErrorUri((string) ($_GET['error_uri'] ?? '')),
    ];
}

function googleOAuthSanitizeCode(string $error): string
{
    $error = strtolower(trim($error));
    if ($error === '' || preg_match('/^[a-z0-9_.-]{1,80}$/', $error) !== 1) {
        return '';
    }

    return $error;
}

function googleOAuthSanitizeDescription(string $description): string
{
    $description = googleOAuthRedactSecrets(trim(strip_tags($description)));
    $description = preg_replace('/\s+/', ' ', $description) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($description, 0, 300);
    }

    return substr($description, 0, 300);
}

function googleOAuthSanitizeErrorUri(string $uri): string
{
    $uri = googleOAuthRedactSecrets(trim($uri));
    if ($uri === '' || filter_var($uri, FILTER_VALIDATE_URL) === false) {
        return '';
    }
    if (!preg_match('#^https://#i', $uri)) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($uri, 0, 300);
    }

    return substr($uri, 0, 300);
}

function googleOAuthRedactSecrets(string $text): string
{
    $patterns = [
        '/GOCSPX-[A-Za-z0-9_-]+/i',
        '/ya29\.[A-Za-z0-9._-]+/',
        '/1\/\/[A-Za-z0-9_-]+/',
        '/\bAIza[A-Za-z0-9_-]{20,}\b/',
        '/client_secret=[^&\s]+/i',
        '/refresh_token=[^&\s]+/i',
        '/access_token=[^&\s]+/i',
        '/\bcode=[A-Za-z0-9._\/+-]{12,}/i',
    ];

    $redacted = preg_replace($patterns, '[redacted]', $text);

    return is_string($redacted) ? $redacted : '[redacted]';
}

/**
 * @param array<string, mixed> $details
 */
function googleOAuthLogEvent(string $flow, string $stage, array $details = []): void
{
    $safe = [
        'flow'  => preg_replace('/[^a-z0-9_-]/i', '', $flow) ?: 'oauth',
        'stage' => preg_replace('/[^a-z0-9_-]/i', '', $stage) ?: 'event',
    ];

    $allow = ['error', 'error_description', 'error_uri', 'http_status', 'redirect_host'];
    foreach ($allow as $key) {
        if (!array_key_exists($key, $details)) {
            continue;
        }
        $value = $details[$key];
        if (!is_scalar($value) && $value !== null) {
            continue;
        }
        $text = googleOAuthRedactSecrets(trim((string) $value));
        if ($text === '') {
            continue;
        }
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 300);
        } else {
            $text = substr($text, 0, 300);
        }
        $safe[$key] = $text;
    }

    $json = json_encode($safe, JSON_UNESCAPED_SLASHES);
    error_log('[EventStaff] oauth ' . ($json !== false ? $json : $safe['flow'] . ' ' . $safe['stage']));
}

function googleOAuthLogCallbackErrors(string $flow): void
{
    $errors = googleOAuthCallbackQueryErrors();
    if ($errors['error'] === '' && $errors['error_description'] === '') {
        return;
    }

    googleOAuthLogEvent($flow, 'callback_error', $errors);
}

/**
 * @param 'staff'|'admin_drive' $audience
 */
function googleOAuthUserMessage(string $error, string $audience = 'staff'): string
{
    $error = googleOAuthSanitizeCode($error);
    $isAdmin = $audience === 'admin_drive';

    return match ($error) {
        'access_denied' => $isAdmin
            ? 'Google Drive connection was cancelled.'
            : 'Google sign-in was cancelled.',
        'disabled_client' => $isAdmin
            ? 'Google has temporarily blocked this app from connecting Drive. Try again later, or use email verification for staff sign-in.'
            : 'Google sign-in is temporarily unavailable for this app. Please try again later, or verify by email instead.',
        'redirect_uri_mismatch', 'invalid_client', 'unauthorized_client', 'invalid_request' => $isAdmin
            ? 'Google Drive connection is misconfigured. The redirect URI in Google Cloud must match this site exactly.'
            : 'Google sign-in is misconfigured. Please contact support.',
        'server_error', 'temporarily_unavailable' => 'Google is temporarily unavailable. Please try again in a moment.',
        'invalid_grant', 'invalid_token' => $isAdmin
            ? 'Google connection expired. Click Connect Google account again.'
            : 'Google sign-in expired. Please try again.',
        default => $isAdmin
            ? 'Google Drive connection could not be completed. Please try again.'
            : 'Google sign-in could not be completed. Please try again.',
    };
}

/**
 * @return array{error: string, error_description: string}
 */
function googleOAuthParseJsonErrorBody(string $responseBody): array
{
    $safeBody = googleOAuthRedactSecrets($responseBody);
    $data = json_decode($safeBody, true);
    if (!is_array($data)) {
        return ['error' => '', 'error_description' => ''];
    }

    $error = googleOAuthSanitizeCode((string) ($data['error'] ?? ''));
    $description = googleOAuthSanitizeDescription((string) ($data['error_description'] ?? ''));

    return [
        'error'             => $error,
        'error_description' => $description,
    ];
}

function googleOAuthLogTokenExchangeFailure(string $flow, int $httpStatus, string $responseBody): void
{
    $parsed = googleOAuthParseJsonErrorBody($responseBody);
    googleOAuthLogEvent($flow, 'token_exchange_failed', [
        'error'             => $parsed['error'] !== '' ? $parsed['error'] : 'http_error',
        'error_description' => $parsed['error_description'],
        'http_status'       => (string) $httpStatus,
    ]);
}

function googleOAuthErrorSelfTest(): string
{
    $disabled = googleOAuthUserMessage('disabled_client', 'staff');
    if (!str_contains(strtolower($disabled), 'temporarily')) {
        throw new RuntimeException('disabled_client user message missing');
    }

    $denied = googleOAuthUserMessage('access_denied', 'staff');
    if (!str_contains(strtolower($denied), 'cancelled')) {
        throw new RuntimeException('access_denied user message missing');
    }

    $mismatch = googleOAuthUserMessage('redirect_uri_mismatch', 'admin_drive');
    if (!str_contains(strtolower($mismatch), 'redirect')) {
        throw new RuntimeException('redirect_uri_mismatch user message missing');
    }

    $redacted = googleOAuthRedactSecrets('secret=GOCSPX-abcTOKEN token=ya29.xyz refresh=1//abc code=abc123def456ghi');
    if (str_contains($redacted, 'GOCSPX-abcTOKEN') || str_contains($redacted, 'ya29.xyz') || str_contains($redacted, '1//abc')) {
        throw new RuntimeException('secret redaction failed');
    }

    $parsed = googleOAuthParseJsonErrorBody('{"error":"invalid_client","error_description":"Unauthorized"}');
    if ($parsed['error'] !== 'invalid_client') {
        throw new RuntimeException('json error parse failed');
    }

    $bad = googleOAuthSanitizeCode('<script>alert(1)</script>');
    if ($bad !== '') {
        throw new RuntimeException('error code sanitizer failed');
    }

    return 'user messages, redaction, and parse ok';
}
