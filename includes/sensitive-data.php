<?php
/**
 * Masking and verification for PPS / IBAN (admin UI + sign-in).
 */

const SIGNIN_DETAILS_MISMATCH_MESSAGE = 'Email and last 4 characters of your PPS do not match our records.';

function getSigninMismatchMessage(?PDO $pdo = null): string
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            return SIGNIN_DETAILS_MISMATCH_MESSAGE;
        }
    }

    require_once __DIR__ . '/signin-display.php';

    return isSigninPpsRequired($pdo)
        ? SIGNIN_DETAILS_MISMATCH_MESSAGE
        : 'Email address does not match our records for this event.';
}

function signinIdentityMatches(array $row, string $ppsLast4, ?PDO $pdo = null): bool
{
    require_once __DIR__ . '/signin-display.php';

    if (!isSigninPpsRequired($pdo)) {
        return true;
    }

    return ppsLastFourMatches((string) ($row['pps_number'] ?? ''), $ppsLast4);
}

function normalizePpsValue(string $pps): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($pps)));
}

function getPpsLastFour(string $pps): string
{
    $normalized = normalizePpsValue($pps);

    if ($normalized === '') {
        return '';
    }

    return strlen($normalized) <= 4
        ? $normalized
        : substr($normalized, -4);
}

function isValidPpsLastFourInput(string $input): bool
{
    $input = strtoupper(preg_replace('/\s+/', '', trim($input)));

    return (bool) preg_match('/^[A-Z0-9]{4}$/', $input);
}

function ppsLastFourMatches(string $pps, string $input): bool
{
    if (!isValidPpsLastFourInput($input)) {
        return false;
    }

    return getPpsLastFour($pps) === strtoupper(preg_replace('/\s+/', '', trim($input)));
}

function maskPpsNumber(string $pps): string
{
    $last4 = getPpsLastFour($pps);

    if ($last4 === '') {
        return '—';
    }

    return '•••• ' . $last4;
}

function maskBankIban(string $iban): string
{
    $clean = strtoupper(preg_replace('/\s+/', '', trim($iban)));

    if ($clean === '') {
        return '—';
    }

    if (strlen($clean) <= 4) {
        return str_repeat('•', strlen($clean));
    }

    $visible = substr($clean, -4);
    $masked  = str_repeat('•', strlen($clean) - 4);

    return trim(chunk_split($masked . $visible, 4, ' '));
}
