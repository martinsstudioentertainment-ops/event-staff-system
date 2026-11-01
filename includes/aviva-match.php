<?php
declare(strict_types=1);
/**
 * AVIVA Match – Event Staff (registered-only, capped). Additive module; does not touch the
 * 1 Oct Aviva event, the registration system, or shared tables. Own table: aviva_match_signups.
 */
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/phone-numbers.php';

const AVIVA_MATCH_NAME      = 'AVIVA Match – Event Staff';
const AVIVA_MATCH_VENUE     = 'Aviva Stadium, Dublin';
const AVIVA_MATCH_TIME      = '13:00 – Finish';
const AVIVA_MATCH_CAPACITY  = 70;
const AVIVA_MATCH_MSG_UNREG = 'Please register on olasentra.com first, then come back to this form.';
const AVIVA_MATCH_MSG_DUP   = 'You are already signed up for this event. One person, one place.';
const AVIVA_MATCH_MSG_FULL  = 'Event full';

/** Single placeholder for the match date: DB setting aviva_match_date (YYYY-MM-DD). */
function avivaMatchDate(PDO $pdo): string
{
    return trim((string) getSetting($pdo, 'aviva_match_date', '')) ?: 'TBC';
}

function avivaMatchFormUrl(PDO $pdo): string
{
    return trim((string) getSetting($pdo, 'google_form_aviva_match_url', ''));
}

function avivaMatchEnsureSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS aviva_match_signups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        staff_id INT UNSIGNED NOT NULL,
        email VARCHAR(190) NOT NULL,
        mobile VARCHAR(40) NOT NULL DEFAULT \'\',
        full_name VARCHAR(190) NOT NULL DEFAULT \'\',
        form_response_id VARCHAR(120) NOT NULL DEFAULT \'\',
        is_test TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_aviva_match_staff (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function avivaMatchCount(PDO $pdo): int
{
    avivaMatchEnsureSchema($pdo);
    return (int) $pdo->query('SELECT COUNT(*) FROM aviva_match_signups')->fetchColumn();
}

function avivaMatchIsFull(PDO $pdo): bool
{
    return avivaMatchCount($pdo) >= AVIVA_MATCH_CAPACITY;
}

/** Digits only, Irish 08x -> 3538x, so "+353 87…", "00353 87…", "087…" all match. */
function avivaMatchPhoneKey(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($d, '00')) { $d = substr($d, 2); }
    if (str_starts_with($d, '0'))  { $d = '353' . substr($d, 1); }
    return $d;
}

/** Find a registered, non-blacklisted staff row by email OR mobile. */
function avivaMatchFindRegistered(PDO $pdo, string $email, string $mobile): ?array
{
    $email = strtolower(trim($email));
    if ($email !== '') {
        $st = $pdo->prepare('SELECT id, email, mobile, first_name, surname FROM staff
            WHERE LOWER(TRIM(email)) = :e AND IFNULL(is_blacklisted,0) = 0 LIMIT 1');
        $st->execute(['e' => $email]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) { return $row; }
    }
    $key = avivaMatchPhoneKey($mobile);
    if (strlen($key) >= 9) {
        $tail = substr($key, -9); // narrow in SQL, confirm exactly in PHP
        $st = $pdo->prepare("SELECT id, email, mobile, first_name, surname FROM staff
            WHERE IFNULL(is_blacklisted,0) = 0 AND REPLACE(REPLACE(REPLACE(mobile,' ',''),'-',''),'+','') LIKE :t");
        $st->execute(['t' => '%' . $tail]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (avivaMatchPhoneKey((string) $row['mobile']) === $key) { return $row; }
        }
    }
    return null;
}

/**
 * Validate + (unless $dryRun) record a sign-up. Codes: accepted|not_registered|duplicate|full|invalid.
 * Uses a named lock so two simultaneous submits cannot both take place #70.
 */
function avivaMatchSignup(PDO $pdo, array $in, bool $dryRun = false): array
{
    avivaMatchEnsureSchema($pdo);
    $email  = trim((string) ($in['email'] ?? ''));
    $mobile = trim((string) ($in['mobile'] ?? ''));
    if ($email === '' && $mobile === '') {
        return ['ok' => false, 'code' => 'invalid', 'message' => 'Enter your registered email or mobile.'];
    }
    $pdo->query("SELECT GET_LOCK('aviva_match_signup', 10)");
    try {
        $count = avivaMatchCount($pdo);
        $base  = ['count' => $count, 'capacity' => AVIVA_MATCH_CAPACITY];
        if ($count >= AVIVA_MATCH_CAPACITY) {
            return $base + ['ok' => false, 'code' => 'full', 'message' => AVIVA_MATCH_MSG_FULL];
        }
        $staff = avivaMatchFindRegistered($pdo, $email, $mobile);
        if ($staff === null) {
            return $base + ['ok' => false, 'code' => 'not_registered', 'message' => AVIVA_MATCH_MSG_UNREG];
        }
        $dup = $pdo->prepare('SELECT 1 FROM aviva_match_signups WHERE staff_id = :s LIMIT 1');
        $dup->execute(['s' => (int) $staff['id']]);
        if ($dup->fetchColumn()) {
            return $base + ['ok' => false, 'code' => 'duplicate', 'message' => AVIVA_MATCH_MSG_DUP];
        }
        if ($dryRun) {
            return $base + ['ok' => true, 'code' => 'eligible', 'message' => 'Eligible.'];
        }
        $pdo->prepare('INSERT INTO aviva_match_signups (staff_id,email,mobile,full_name,form_response_id,is_test)
            VALUES (:s,:e,:m,:n,:r,:t)')->execute([
            's' => (int) $staff['id'],
            'e' => strtolower((string) $staff['email']),
            'm' => (string) $staff['mobile'],
            'n' => trim(($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? '')),
            'r' => substr((string) ($in['response_id'] ?? ''), 0, 120),
            't' => !empty($in['is_test']) ? 1 : 0,
        ]);
        $count++;
        return ['ok' => true, 'code' => 'accepted', 'message' => 'You are confirmed for ' . AVIVA_MATCH_NAME . '.',
                'count' => $count, 'capacity' => AVIVA_MATCH_CAPACITY, 'full' => $count >= AVIVA_MATCH_CAPACITY];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('aviva_match_signup')");
    }
}
