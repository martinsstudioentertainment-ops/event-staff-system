<?php
/**
 * Phase 11A — OTP send investigation probe (read-only).
 * Run: php scripts/phase11a-otp-investigation.php
 */
$base = 'https://register.olasentra.com';

function fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($raw)) {
        return ['code' => 0, 'body' => ''];
    }
    $parts = explode("\r\n\r\n", $raw, 2);
    return ['code' => $code, 'body' => $parts[1] ?? ''];
}

echo "Phase 11A OTP investigation\n\n";

$r = fetch($base . '/staff-app.php');
$html = $r['body'];
echo "staff-app.php HTTP {$r['code']}\n";

$checks = [
    'staff-portal-email-otp root' => str_contains($html, 'id="staff-portal-email-otp"'),
    'send button id' => str_contains($html, 'id="staff-portal-email-send"'),
    'Send verification code label' => str_contains($html, 'Send verification code'),
    'staff-portal-email-otp.js script' => str_contains($html, 'staff-portal-email-otp.js'),
    'data-csrf on root' => (bool) preg_match('/id="staff-portal-email-otp"[^>]*data-csrf="[^"]+"/', $html),
    'data-send-url' => str_contains($html, 'data-send-url="api/staff-portal-otp-send.php"'),
    'es-v3-login--compact' => str_contains($html, 'es-v3-login--compact'),
    'Welcome to Olasentra' => str_contains($html, 'Welcome to Olasentra'),
];

foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . "  {$label}\n";
}

// Compare script order
if (preg_match_all('/<script[^>]+src="([^"]+)"[^>]*>/', $html, $m)) {
    echo "\nScript load order:\n";
    foreach ($m[1] as $src) {
        if (str_contains($src, 'staff-app-v3') || str_contains($src, 'staff-portal-email-otp') || str_contains($src, 'pwa')) {
            echo "  - {$src}\n";
        }
    }
}

// CSRF cookie/session probe via GET send (expect 403/405 not 500)
$ch = curl_init($base . '/api/staff-portal-otp-send.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['email' => 'test@example.com', 'csrf_token' => 'invalid']),
    CURLOPT_TIMEOUT => 20,
]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "\nOTP send POST (invalid CSRF) HTTP {$code}\n";
echo "Body: {$resp}\n";

// Fetch OTP JS and verify sendBtn binding exists
$js = fetch($base . '/assets/js/staff-portal-email-otp.js');
echo "\nstaff-portal-email-otp.js HTTP {$js['code']} bytes=" . strlen($js['body']) . "\n";
echo 'sendBtn listener: ' . (str_contains($js['body'], "getElementById('staff-portal-email-send')") ? 'present' : 'MISSING') . "\n";
