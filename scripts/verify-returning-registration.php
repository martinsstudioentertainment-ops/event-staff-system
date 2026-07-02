<?php
/**
 * Returning user registration audit — production wizard prefill verification.
 *
 * Flow: Step 1 → Step 2 (select event) → Step 3 (email) → verify prefilled fields.
 *
 * Usage:
 *   php scripts/verify-returning-registration.php
 *   php scripts/verify-returning-registration.php --email=user@example.com --json
 */

declare(strict_types=1);

$opts = getopt('', ['base::', 'email::', 'json', 'screenshots', 'screenshots-only']);
$baseUrl = rtrim((string) ($opts['base'] ?? 'https://register.olasentra.com'), '/');
$email = trim((string) ($opts['email'] ?? 'e2e-wizard-20260606164932@olasentra-e2e.test'));
$jsonOut = array_key_exists('json', $opts);
$root = dirname(__DIR__);
$outPath = $root . '/storage/reports/returning-registration-audit-latest.json';

function fetch_lookup_json(string $baseUrl, string $email): ?array
{
    $script = dirname(__FILE__) . '/fetch-registrant-lookup.ps1';
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File '
        . escapeshellarg($script)
        . ' -Email '
        . escapeshellarg($email);
    $body = shell_exec($cmd);
    if (!is_string($body) || trim($body) === '') {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * @param callable(int, callable, callable):mixed $runner  ($send, $recv, $nextId) => result
 */
function cdp_session(string $pageUrl, callable $runner): mixed
{
    $edgeCandidates = [
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    ];
    $edge = null;
    foreach ($edgeCandidates as $candidate) {
        if (is_file($candidate)) {
            $edge = $candidate;
            break;
        }
    }
    if ($edge === null) {
        throw new RuntimeException('Microsoft Edge not found.');
    }

    $port = 9400 + random_int(1, 200);
    $userData = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-returning-' . bin2hex(random_bytes(6));
    mkdir($userData, 0777, true);
    $cmd = sprintf(
        '"%s" --headless=new --disable-gpu --remote-debugging-port=%d --user-data-dir="%s" "%s"',
        $edge,
        $port,
        $userData,
        $pageUrl
    );
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start headless Edge.');
    }
    fclose($pipes[0]);
    $socket = null;

    try {
        $target = null;
        $deadline = time() + 25;
        while (time() < $deadline) {
            $json = @file_get_contents("http://127.0.0.1:{$port}/json/list");
            if (is_string($json)) {
                foreach (json_decode($json, true) ?: [] as $t) {
                    if (($t['type'] ?? '') === 'page' && !str_starts_with((string) ($t['url'] ?? ''), 'chrome-')) {
                        $target = $t;
                        break 2;
                    }
                }
            }
            usleep(400000);
        }
        if ($target === null || empty($target['webSocketDebuggerUrl'])) {
            throw new RuntimeException('No DevTools page target.');
        }

        $wsUrl = parse_url((string) $target['webSocketDebuggerUrl']);
        $socket = stream_socket_client(
            'tcp://' . ($wsUrl['host'] ?? '127.0.0.1') . ':' . (int) ($wsUrl['port'] ?? 80),
            $errno,
            $errstr,
            10
        );
        if ($socket === false) {
            throw new RuntimeException($errstr);
        }

        $path = ($wsUrl['path'] ?? '/') . (isset($wsUrl['query']) ? '?' . $wsUrl['query'] : '');
        $key = base64_encode(random_bytes(16));
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
        stream_get_contents($socket, 8192);

        $send = static function ($socket, int $id, array $payload) {
            $payload['id'] = $id;
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $len = strlen($json);
            $frame = chr(0x81) . ($len <= 125 ? chr($len | 0x80) : chr(126 | 0x80) . pack('n', $len));
            $mask = random_bytes(4);
            $frame .= $mask;
            for ($i = 0; $i < $len; $i++) {
                $frame .= $json[$i] ^ $mask[$i % 4];
            }
            fwrite($socket, $frame);
        };
        $recvFor = static function ($socket, int $expectId) {
            $deadline = time() + 60;
            while (time() < $deadline) {
                $data = fread($socket, 524288);
                if ($data === false || $data === '') {
                    usleep(100000);
                    continue;
                }
                $payloadLen = ord($data[1]) & 0x7F;
                $offset = 2;
                if ($payloadLen === 126) {
                    $payloadLen = unpack('n', substr($data, 2, 2))[1];
                    $offset = 4;
                } elseif ($payloadLen === 127) {
                    $payloadLen = unpack('J', substr($data, 2, 8))[1];
                    $offset = 10;
                }
                $msg = json_decode(substr($data, $offset, $payloadLen), true);
                if (!is_array($msg) || !isset($msg['id'])) {
                    continue;
                }
                if ((int) $msg['id'] === $expectId) {
                    return $msg;
                }
            }
            throw new RuntimeException('CDP recv timeout for id ' . $expectId);
        };

        $nextId = 1;
        $sendFn = static function (array $payload) use ($socket, $send, $recvFor, &$nextId) {
            $id = $nextId++;
            $send($socket, $id, $payload);
            return $recvFor($socket, $id);
        };

        $sendFn(['method' => 'Runtime.enable', 'params' => (object) []]);
        $sendFn(['method' => 'Page.enable', 'params' => (object) []]);
        sleep(2);

        return $runner($sendFn);
    } finally {
        if (is_resource($proc)) {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}

function cdp_evaluate(string $pageUrl, string $expression): array
{
    return cdp_session($pageUrl, static function (callable $send) use ($expression): array {
        $msg = $send([
            'method' => 'Runtime.evaluate',
            'params' => ['expression' => $expression, 'awaitPromise' => true, 'returnByValue' => true],
        ]);
        if (!empty($msg['result']['exceptionDetails'])) {
            throw new RuntimeException((string) ($msg['result']['exceptionDetails']['exception']['description'] ?? 'JS error'));
        }
        $value = $msg['result']['result']['value'] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException('Bad CDP payload');
        }
        return $value;
    });
}

function cdp_capture_screenshot(callable $send, string $path): void
{
    $msg = $send([
        'method' => 'Page.captureScreenshot',
        'params' => ['format' => 'png', 'fromSurface' => true, 'captureBeyondViewport' => false],
    ]);
    $data = $msg['result']['data'] ?? '';
    if ($data === '') {
        throw new RuntimeException('Screenshot capture failed');
    }
    file_put_contents($path, base64_decode($data, true));
}

function capture_returning_psa_screenshots(string $baseUrl, string $email, string $outDir): void
{
    $emailEsc = addslashes($email);
    $prepJs = <<<JS
(async function () {
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const wizard = window.RegistrationWizard;
  wizard.showStep(2);
  await wait(1500);
  const openInput = document.querySelector('#shift-picker-list .reg-event-card:not(.reg-event-card--registered) input[name="event_ids[]"]');
  if (openInput) {
    const label = openInput.closest('label');
    if (label) label.click();
  }
  wizard.showStep(3);
  await wait(300);
  const emailEl = document.getElementById('email');
  emailEl.value = '{$emailEsc}';
  emailEl.dispatchEvent(new Event('input', { bubbles: true }));
  emailEl.dispatchEvent(new Event('blur', { bubbles: true }));
  await wait(1600);
  return true;
})()
JS;

    sleep(2);
    cdp_session($baseUrl . '/index.php', static function (callable $send) use ($prepJs, $outDir): void {
        $send([
            'method' => 'Runtime.evaluate',
            'params' => ['expression' => $prepJs, 'awaitPromise' => true],
        ]);
        $send([
            'method' => 'Runtime.evaluate',
            'params' => ['expression' => 'window.RegistrationWizard.showStep(7)', 'awaitPromise' => false],
        ]);
        usleep(400000);
        cdp_capture_screenshot($send, $outDir . '/after-step7.png');
        $send([
            'method' => 'Runtime.evaluate',
            'params' => ['expression' => 'window.RegistrationWizard.showStep(8); if(window.RegistrationWizardReview){window.RegistrationWizardReview.render();}', 'awaitPromise' => false],
        ]);
        usleep(400000);
        cdp_capture_screenshot($send, $outDir . '/after-step8.png');
    });
}

function field_check(string $label, ?string $apiValue, string $wizardValue, array &$checks, array &$defects): void
{
    $api = trim((string) $apiValue);
    $wiz = trim($wizardValue);
    $ok = $api !== '' && $wiz !== '';
    if ($api !== '' && $wiz === '') {
        $defects[] = "{$label}: profile has value but wizard field is blank";
        $ok = false;
    }
    $checks[$label] = [
        'api_value' => $api !== '' ? $api : null,
        'wizard_value' => $wiz !== '' ? $wiz : null,
        'pass' => $ok || ($api === '' && $wiz === ''),
    ];
}

$api = fetch_lookup_json($baseUrl, $email);
if (!$api || empty($api['found'])) {
    fwrite(STDERR, "Lookup failed for {$email}\n");
    exit(1);
}
$profile = (array) ($api['profile'] ?? []);

if (array_key_exists('screenshots-only', $opts)) {
    $shotDir = $root . '/docs/screenshots/returning-psa';
    @mkdir($shotDir, 0777, true);
    capture_returning_psa_screenshots($baseUrl, $email, $shotDir);
    fwrite(STDOUT, "Screenshots saved: {$shotDir}/after-step7.png, after-step8.png\n");
    exit(0);
}

$emailEsc = addslashes($email);
$js = <<<JS
(async function () {
  const t0 = performance.now();
  const out = { elapsed_ms: 0, fatal: null, fields: {}, validation: {}, psa: {}, profile_card: false, lookup: false };
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const fv = (id) => { const el = document.getElementById(id); return el ? String(el.value || '').trim() : ''; };
  const wizard = window.RegistrationWizard;
  const validation = window.RegistrationWizardValidation;
  if (!wizard) { out.fatal = 'RegistrationWizard missing'; return out; }

  wizard.showStep(1);
  await wait(200);
  wizard.showStep(2);
  await wait(1800);
  const openInput = document.querySelector('#shift-picker-list .reg-event-card:not(.reg-event-card--registered) input[name="event_ids[]"]');
  if (openInput) {
    const label = openInput.closest('label');
    if (label) label.click(); else { openInput.click(); openInput.dispatchEvent(new Event('change', { bubbles: true })); }
    await wait(250);
  }
  out.event_selected = !!(document.querySelector('#shift-picker-list input[name="event_ids[]"]:checked'));

  wizard.showStep(3);
  await wait(300);
  const emailEl = document.getElementById('email');
  emailEl.value = '{$emailEsc}';
  emailEl.dispatchEvent(new Event('input', { bubbles: true }));
  emailEl.dispatchEvent(new Event('blur', { bubbles: true }));
  await wait(1600);

  out.profile_card = !!(document.getElementById('reg-returning-panel') && !document.getElementById('reg-returning-panel').hidden);
  if (window.RegistrationWizardReturning && window.RegistrationWizardReturning.getLastPayload) {
    const p = window.RegistrationWizardReturning.getLastPayload();
    out.lookup = !!(p && p.found);
  }

  const genderEl = document.querySelector('input[name="gender"]:checked');
  const mobileNational = fv('mobile_national');
  const mobileHidden = fv('mobile');
  out.fields = {
    surname: fv('surname'),
    first_name: fv('first_name'),
    full_address: fv('full_address'),
    eircode: fv('eircode'),
    date_of_birth: fv('date_of_birth'),
    gender: genderEl ? genderEl.value : '',
    mobile_national: mobileNational,
    mobile: mobileHidden,
    pps_number: fv('pps_number'),
    bank_iban: fv('bank_iban'),
    psa_licence: fv('psa_licence'),
    psa_expiry_date: fv('psa_expiry_date'),
  };

  const front = document.getElementById('psa_front_image');
  const back = document.getElementById('psa_back_image');
  wizard.showStep(7);
  await wait(200);
  const frontWrap = front ? front.closest('.reg-psa-upload') : null;
  const backWrap = back ? back.closest('.reg-psa-upload') : null;
  const frontStatusEl = frontWrap ? frontWrap.querySelector('.reg-psa-upload__status') : null;
  const backStatusEl = backWrap ? backWrap.querySelector('.reg-psa-upload__status') : null;
  out.psa = {
    front_required: front ? front.required : null,
    back_required: back ? back.required : null,
    front_status: frontStatusEl ? frontStatusEl.textContent.trim() : '',
    back_status: backStatusEl ? backStatusEl.textContent.trim() : '',
    front_on_file: front ? front.dataset.psaOnFile === '1' : false,
    back_on_file: back ? back.dataset.psaOnFile === '1' : false,
  };

  wizard.showStep(8);
  await wait(200);
  if (window.RegistrationWizardReview && window.RegistrationWizardReview.render) {
    window.RegistrationWizardReview.render();
    await wait(100);
  }
  const review = document.getElementById('reg-wizard-review-summary');
  out.review_text = review ? review.innerText : '';

  if (validation && validation.validateStep) {
    for (let s = 2; s <= 8; s++) {
      wizard.showStep(s);
      await wait(120);
      out.validation['step_' + s] = validation.validateStep(s);
    }
  }
  out.elapsed_ms = Math.round(performance.now() - t0);
  return out;
})()
JS;

$browser = cdp_evaluate($baseUrl . '/index.php', $js);
$fields = (array) ($browser['fields'] ?? []);
$checks = [];
$defects = [];

field_check('Personal — surname', $profile['surname'] ?? '', $fields['surname'] ?? '', $checks, $defects);
field_check('Personal — first name', $profile['first_name'] ?? '', $fields['first_name'] ?? '', $checks, $defects);
field_check('Personal — address', $profile['full_address'] ?? '', $fields['full_address'] ?? '', $checks, $defects);
field_check('Personal — eircode', $profile['eircode'] ?? '', $fields['eircode'] ?? '', $checks, $defects);
field_check('Personal — date of birth', $profile['date_of_birth'] ?? '', $fields['date_of_birth'] ?? '', $checks, $defects);
field_check('Personal — gender', $profile['gender'] ?? '', $fields['gender'] ?? '', $checks, $defects);

$mobileApi = (string) ($profile['mobile'] ?? '');
$mobileWiz = trim(($fields['mobile_national'] ?? '') . '|' . ($fields['mobile'] ?? ''));
if ($mobileApi !== '' && $fields['mobile_national'] === '' && $fields['mobile'] === '') {
    $defects[] = 'Mobile: profile has value but wizard mobile fields are blank';
    $checks['Mobile'] = ['api_value' => $mobileApi, 'wizard_value' => null, 'pass' => false];
} else {
    $checks['Mobile'] = ['api_value' => $mobileApi, 'wizard_value' => $fields['mobile_national'] ?: $fields['mobile'], 'pass' => true];
}

field_check('Payroll — PPS', $profile['pps_number'] ?? '', $fields['pps_number'] ?? '', $checks, $defects);
field_check('Payroll — IBAN', $profile['bank_iban'] ?? '', $fields['bank_iban'] ?? '', $checks, $defects);
field_check('PSA — licence', $profile['psa_licence'] ?? '', $fields['psa_licence'] ?? '', $checks, $defects);
field_check('PSA — expiry', $profile['psa_expiry_date'] ?? '', $fields['psa_expiry_date'] ?? '', $checks, $defects);

$psa = (array) ($browser['psa'] ?? []);
$hasFront = !empty($profile['has_psa_front']);
$hasBack = !empty($profile['has_psa_back']);
$psaUploadPass = $hasFront && $hasBack && $psa['front_required'] === false && $psa['back_required'] === false;
$checks['PSA uploads recognised (not required)'] = [
    'api_value' => 'has_psa_front=' . ($hasFront ? 'true' : 'false') . ', has_psa_back=' . ($hasBack ? 'true' : 'false'),
    'wizard_value' => 'front_required=' . json_encode($psa['front_required'] ?? null) . ', back_required=' . json_encode($psa['back_required'] ?? null),
    'pass' => $psaUploadPass,
];
if ($hasFront && ($psa['front_required'] ?? true)) {
    $defects[] = 'PSA front: on file in profile but upload still required in wizard';
}
if ($hasBack && ($psa['back_required'] ?? true)) {
    $defects[] = 'PSA back: on file in profile but upload still required in wizard';
}
$frontStatus = (string) ($psa['front_status'] ?? '');
$backStatus = (string) ($psa['back_status'] ?? '');
$r1FrontPass = !$hasFront || str_contains($frontStatus, 'already on file');
$r1BackPass = !$hasBack || str_contains($backStatus, 'already on file');
$checks['R-1 Step 7 PSA status (front)'] = [
    'api_value' => $hasFront ? 'on file' : 'n/a',
    'wizard_value' => $frontStatus,
    'pass' => $r1FrontPass,
];
$checks['R-1 Step 7 PSA status (back)'] = [
    'api_value' => $hasBack ? 'on file' : 'n/a',
    'wizard_value' => $backStatus,
    'pass' => $r1BackPass,
];
if ($hasFront && !$r1FrontPass) {
    $defects[] = 'R-1: PSA front still shows "' . $frontStatus . '" instead of on-file message';
}
if ($hasBack && !$r1BackPass) {
    $defects[] = 'R-1: PSA back still shows "' . $backStatus . '" instead of on-file message';
}

$reviewText = (string) ($browser['review_text'] ?? '');
$r2FrontPass = !$hasFront || str_contains($reviewText, 'Front photo') && str_contains($reviewText, 'On file');
$r2BackPass = !$hasBack || str_contains($reviewText, 'Back photo') && str_contains($reviewText, 'On file');
$checks['R-2 Step 8 review (front)'] = [
    'api_value' => $hasFront ? 'On file expected' : 'n/a',
    'wizard_value' => str_contains($reviewText, 'On file') ? 'On file present in review' : 'missing',
    'pass' => $r2FrontPass,
];
$checks['R-2 Step 8 review (back)'] = [
    'api_value' => $hasBack ? 'On file expected' : 'n/a',
    'wizard_value' => str_contains($reviewText, 'On file') ? 'On file present in review' : 'missing',
    'pass' => $r2BackPass,
];
if ($hasFront && !str_contains($reviewText, 'On file')) {
    $defects[] = 'R-2: Step 8 review does not show "On file" for existing PSA photos';
}

$validation = (array) ($browser['validation'] ?? []);
$canContinue = ($validation['step_4'] ?? false)
    && ($validation['step_5'] ?? false)
    && ($validation['step_6'] ?? false)
    && ($validation['step_7'] ?? false)
    && ($validation['step_8'] ?? false);

$elapsedSec = round(((int) ($browser['elapsed_ms'] ?? 0)) / 1000, 1);
$under60 = $elapsedSec < 60;

$report = [
    'generated_at' => gmdate('c'),
    'base_url' => $baseUrl,
    'test_email' => $email,
    'api_profile' => $profile,
    'event_selected' => (bool) ($browser['event_selected'] ?? false),
    'profile_lookup' => (bool) ($browser['lookup'] ?? false),
    'welcome_card_shown' => (bool) ($browser['profile_card'] ?? false),
    'field_checks' => $checks,
    'step_validation' => $validation,
    'can_continue_without_reentry' => $canContinue,
    'elapsed_seconds' => $elapsedSec,
    'under_60_second_target' => $under60,
    'defects' => $defects,
    'r1_pass' => $r1FrontPass && $r1BackPass,
    'r2_pass' => $r2FrontPass && $r2BackPass,
    'review_excerpt' => substr($reviewText, 0, 500),
    'verdict' => ($defects === [] && $r1FrontPass && $r1BackPass && $r2FrontPass && $r2BackPass) ? 'PASS' : 'DEFECTS_FOUND',
];

@mkdir(dirname($outPath), 0777, true);
file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Returning registration audit — {$email}\n";
    echo 'Verdict: ' . $report['verdict'] . "\n";
    echo 'Elapsed: ' . $elapsedSec . "s (target <60s: " . ($under60 ? 'yes' : 'no') . ")\n";
    echo 'Can continue without re-entry: ' . ($canContinue ? 'yes' : 'no') . "\n\n";
    foreach ($checks as $name => $c) {
        echo ($c['pass'] ? '[PASS]' : '[FAIL]') . " {$name}\n";
    }
    if ($defects !== []) {
        echo "\nDefects:\n";
        foreach ($defects as $d) {
            echo " - {$d}\n";
        }
    }
    echo "\nJSON: {$outPath}\n";
}

if (array_key_exists('screenshots', $opts)) {
    $shotDir = $root . '/docs/screenshots/returning-psa';
    @mkdir($shotDir, 0777, true);
    try {
        capture_returning_psa_screenshots($baseUrl, $email, $shotDir);
        if (!$jsonOut) {
            fwrite(STDOUT, "Screenshots saved: {$shotDir}/after-step7.png, after-step8.png\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Screenshot capture failed: ' . $e->getMessage() . PHP_EOL);
    }
}

exit($report['verdict'] === 'PASS' ? 0 : 1);
