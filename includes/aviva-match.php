<?php
declare(strict_types=1);
/**
 * AVIVA Match – Event Staff (registered-only, capped). Additive module; does not touch the
 * 1 Oct Aviva event, the registration system, or shared tables. Own table: aviva_match_signups.
 */
// Load maps.php before anything pulls in maps-core.php (maps-core guards its functions; maps.php does not).
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/phone-numbers.php';

const AVIVA_MATCH_NAME      = 'AVIVA Match – Event Staff';
const AVIVA_MATCH_VENUE     = 'Aviva Stadium, Dublin';
const AVIVA_MATCH_TIME      = '13:00 – Finish';
const AVIVA_MATCH_CAPACITY  = 70;
const AVIVA_MATCH_MSG_UNREG = 'Please register on olasentra.com first, then come back to this form.';
const AVIVA_MATCH_MSG_DUP   = 'You already have a place on this event. One person, one place.';
const AVIVA_MATCH_MSG_FULL  = 'Event full';
const AVIVA_MATCH_FORM_FALLBACK_URL = 'https://docs.google.com/forms/d/e/1FAIpQLSdE_ZTal9HKrAdVh2ZY3H2R5Hd-L4NFK1VhAzCWpUK3cbkmFg/viewform';

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
    static $done = false;
    if ($done) { return; }
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
    // Additive columns for the on-site form (safe to run repeatedly).
    $cols = [
        'form_first_name' => 'VARCHAR(100) NOT NULL DEFAULT \'\'',
        'form_surname'    => 'VARCHAR(100) NOT NULL DEFAULT \'\'',
        'form_email'      => 'VARCHAR(190) NOT NULL DEFAULT \'\'',
        'form_mobile'     => 'VARCHAR(40) NOT NULL DEFAULT \'\'',
        'full_address'    => 'VARCHAR(255) NOT NULL DEFAULT \'\'',
        'eircode'         => 'VARCHAR(16) NOT NULL DEFAULT \'\'',
        'gender'          => 'VARCHAR(16) NOT NULL DEFAULT \'\'',
        'bank_iban'       => 'VARCHAR(40) NOT NULL DEFAULT \'\'',
        'staff_role'      => 'VARCHAR(40) NOT NULL DEFAULT \'\'',
        'psa_licence'     => 'VARCHAR(40) NOT NULL DEFAULT \'\'',
        'psa_front_path'  => 'VARCHAR(255) NOT NULL DEFAULT \'\'',
        'psa_back_path'   => 'VARCHAR(255) NOT NULL DEFAULT \'\'',
        'source'          => 'VARCHAR(20) NOT NULL DEFAULT \'\'',
    ];
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM aviva_match_signups')->fetchAll(PDO::FETCH_COLUMN) as $c) { $have[$c] = true; }
    foreach ($cols as $name => $def) {
        if (!isset($have[$name])) { $pdo->exec("ALTER TABLE aviva_match_signups ADD COLUMN {$name} {$def}"); }
    }
    $done = true;
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
        $extra = ['form_first_name','form_surname','form_email','form_mobile','full_address','eircode','gender',
                  'bank_iban','staff_role','psa_licence','psa_front_path','psa_back_path','source'];
        $fields = ['staff_id','email','mobile','full_name','form_response_id','is_test'];
        $vals = [
            (int) $staff['id'], strtolower((string) $staff['email']), (string) $staff['mobile'],
            trim(($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? '')),
            substr((string) ($in['response_id'] ?? ''), 0, 120), !empty($in['is_test']) ? 1 : 0,
        ];
        foreach ($extra as $f) {
            if (isset($in[$f])) { $fields[] = $f; $vals[] = mb_substr((string) $in[$f], 0, 255); }
        }
        $pdo->prepare('INSERT INTO aviva_match_signups (' . implode(',', $fields) . ') VALUES ('
            . implode(',', array_fill(0, count($fields), '?')) . ')')->execute($vals);
        $count++;
        return ['ok' => true, 'code' => 'accepted', 'staff_id' => (int) $staff['id'], 'message' => 'You are confirmed for ' . AVIVA_MATCH_NAME . '.',
                'count' => $count, 'capacity' => AVIVA_MATCH_CAPACITY, 'full' => $count >= AVIVA_MATCH_CAPACITY];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('aviva_match_signup')");
    }
}

/** Optional: append an accepted on-site sign-up to the AVIVA Match Google Sheet (best effort, never blocks). */
const AVIVA_MATCH_SHEET_ID = '1WQpUucBP2PVwLX1x3dLNI_qrpM0wiMcsJwesq3ev9ok';

function avivaMatchAppendToSheet(PDO $pdo, array $row): bool
{
    try {
        $sheetId = trim((string) getSetting($pdo, 'aviva_match_sheet_id', AVIVA_MATCH_SHEET_ID));
        if ($sheetId === '' || !empty($row['is_test'])) { return false; }
        require_once __DIR__ . '/google-sheets-sync.php';
        if (!function_exists('loadGoogleServiceAccount') || !function_exists('googleSheetsAppendRangeValues')) { return false; }
        $sa = loadGoogleServiceAccount();
        if ($sa === null) { return false; }
        $token = googleSheetsGetAccessToken($sa);
        if (!$token) { return false; }
        $tab = trim((string) getSetting($pdo, 'aviva_match_sheet_tab', 'Sheet1')) ?: 'Sheet1';
        $range = "'" . str_replace("'", "''", $tab) . "'!A1";
        return googleSheetsAppendRangeValues($token, (string) ($sa['project_id'] ?? ''), $sheetId, $range, [[
            date('Y-m-d H:i:s'), $row['form_first_name'] ?? '', $row['form_surname'] ?? '', $row['form_mobile'] ?? '',
            $row['form_email'] ?? '', $row['full_address'] ?? '', $row['eircode'] ?? '', $row['gender'] ?? '',
            $row['bank_iban'] ?? '', $row['staff_role'] ?? '', $row['psa_licence'] ?? '', 'Confirmed (on-site)',
            (string) ($row['staff_id'] ?? ''),
        ]]);
    } catch (Throwable $e) {
        error_log('[EventStaff] aviva-match sheet append: ' . $e->getMessage());
        return false;
    }
}

/** Simple per-IP rate limit (file based, storage/ is web-denied). */
function avivaMatchRateLimited(string $ip, int $max = 20, int $window = 600): bool
{
    $dir = dirname(__DIR__) . '/storage/aviva-match-rl';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $file = $dir . '/' . hash('sha256', $ip) . '.json';
    $now = time();
    $hits = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, static fn($t) => $t > $now - $window));
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return count($hits) > $max;
}

/** Save a PSA badge upload outside the web root equivalent (storage/ is denied by .htaccess). */
function avivaMatchStorePsaUpload(string $field, string $side): array
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'Upload a photo of the ' . $side . ' of your PSA badge.'];
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        return ['error' => 'PSA badge ' . $side . ': upload failed. Try a screenshot.'];
    }
    if ((int) $file['size'] > 20 * 1024 * 1024) {
        return ['error' => 'PSA badge ' . $side . ' must be 20 MB or smaller.'];
    }
    $info = @getimagesize((string) $file['tmp_name']);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heic'][$mime] ?? '';
    if ($ext === '' && preg_match('/\.(heic|heif)$/i', (string) $file['name'])) { $ext = 'heic'; }
    if ($ext === '') { return ['error' => 'PSA badge ' . $side . ' must be a photo (JPG, PNG, WEBP or HEIC).']; }
    $dir = dirname(__DIR__) . '/storage/aviva-match-psa';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
    $name = date('Ymd-His') . '-' . $side . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
        return ['error' => 'PSA badge ' . $side . ': could not save. Try again.'];
    }
    return ['path' => 'storage/aviva-match-psa/' . $name];
}
