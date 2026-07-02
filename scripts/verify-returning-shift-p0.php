<?php
/**
 * P0 returning-user shift validation fix — production verification.
 *
 * Usage:
 *   php scripts/verify-returning-shift-p0.php
 *   php scripts/verify-returning-shift-p0.php --screenshots --json
 */
declare(strict_types=1);

$opts = getopt('', ['base::', 'email::', 'json', 'screenshots']);
$baseUrl = rtrim((string) ($opts['base'] ?? 'https://register.olasentra.com'), '/');
$email = trim((string) ($opts['email'] ?? 'e2e-wizard-20260606164932@olasentra-e2e.test'));
$jsonOut = array_key_exists('json', $opts);
$screenshots = array_key_exists('screenshots', $opts);
$root = dirname(__DIR__);
$reportPath = $root . '/storage/reports/returning-shift-p0-verify-latest.json';
$shotDir = $root . '/docs/screenshots/returning-shift-p0';

function p0_fetch_lookup(string $baseUrl, string $email): ?array
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

// Minimal CDP — copied from verify-returning-registration.php
function p0_cdp_session(string $pageUrl, callable $runner): mixed
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

    $port = 9500 + random_int(1, 200);
    $userData = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-p0-shift-' . bin2hex(random_bytes(6));
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
        $deadline = time() + 30;
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
            15
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
            $deadline = time() + 120;
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

function p0_cdp_evaluate(string $pageUrl, string $expression): array
{
    return p0_cdp_session($pageUrl, static function (callable $send) use ($expression): array {
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

function p0_screenshot(callable $send, string $path): void
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

$api = p0_fetch_lookup($baseUrl, $email);
if (!$api || empty($api['found'])) {
    fwrite(STDERR, "Lookup failed for {$email}\n");
    exit(1);
}

$registeredIds = array_values(array_map('strval', (array) ($api['registered_event_ids'] ?? [])));
$registeredJson = json_encode($registeredIds, JSON_UNESCAPED_SLASHES);
$emailEsc = addslashes($email);

$js = <<<JS
(async function () {
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const out = { scenarios: {} };
  const wizard = window.RegistrationWizard;
  const validation = window.RegistrationWizardValidation;
  const btnNext = document.getElementById('reg-wizard-next');
  const registeredIds = {$registeredJson};

  const countValid = () => document.querySelectorAll('#shift-picker-list input[name="event_ids[]"]:checked:not(:disabled)').length;
  const clickNext = () => { if (btnNext) btnNext.click(); };

  // --- Scenario A: selection loss after returning lookup ---
  wizard.showStep(2);
  await wait(1800);
  let lossInput = null;
  for (const id of registeredIds) {
    lossInput = document.querySelector('#shift-picker-list input[name="event_ids[]"][value="' + id + '"]');
    if (lossInput) break;
  }
  if (!lossInput) {
    lossInput = document.querySelector('#shift-picker-list .reg-event-card:not(.reg-event-card--registered) input[name="event_ids[]"]');
  }
  if (lossInput) {
    const label = lossInput.closest('label');
    if (label) label.click();
    await wait(300);
  }
  out.scenarios.loss = { selected_before: countValid() };
  clickNext();
  await wait(500);
  const emailEl = document.getElementById('email');
  emailEl.value = '{$emailEsc}';
  emailEl.dispatchEvent(new Event('input', { bubbles: true }));
  emailEl.dispatchEvent(new Event('blur', { bubbles: true }));
  await wait(2500);
  out.scenarios.loss.step_after_lookup = wizard.getCurrentStep();
  out.scenarios.loss.selected_after = countValid();
  out.scenarios.loss.error_visible = !!(document.getElementById('event_ids-error') && document.getElementById('event_ids-error').classList.contains('form-error--visible'));
  out.scenarios.loss.error_text = (document.getElementById('event_ids-error') || {}).textContent || '';

  // --- Scenario B: happy path — open event survives lookup ---
  wizard.showStep(2);
  await wait(1200);
  document.querySelectorAll('#shift-picker-list input[name="event_ids[]"]:checked').forEach((el) => { el.checked = false; });
  const openInput = document.querySelector('#shift-picker-list .reg-event-card:not(.reg-event-card--registered) input[name="event_ids[]"]');
  if (openInput) {
    const label = openInput.closest('label');
    if (label) label.click();
    await wait(300);
  }
  clickNext();
  await wait(400);
  emailEl.dispatchEvent(new Event('input', { bubbles: true }));
  emailEl.dispatchEvent(new Event('blur', { bubbles: true }));
  await wait(2200);
  out.scenarios.happy = {
    step_after_lookup: wizard.getCurrentStep(),
    selected_after: countValid(),
    validate_step8_events_only: false,
  };
  document.querySelector('input[name="privacy_consent"]').checked = false;
  out.scenarios.happy.validate_step8_without_consent = validation.validateStep(8);
  document.querySelector('input[name="privacy_consent"]').checked = true;
  out.scenarios.happy.validate_step8_with_consent = validation.validateStep(8);

  wizard.showStep(8);
  await wait(300);
  if (window.RegistrationWizardReview) window.RegistrationWizardReview.render();
  await wait(200);
  const review = document.getElementById('reg-wizard-review-summary');
  out.scenarios.happy.review_text = review ? review.innerText : '';
  out.scenarios.happy.review_has_none = review ? review.innerText.includes('None selected') : null;
  out.scenarios.happy.review_has_action = review ? review.innerText.includes('Action required') : null;

  // --- Scenario C: block advance to review without selection ---
  document.querySelectorAll('#shift-picker-list input[name="event_ids[]"]:checked').forEach((el) => { el.checked = false; });
  wizard.showStep(7);
  await wait(300);
  clickNext();
  await wait(500);
  out.scenarios.review_gate = {
    step_after_review_click: wizard.getCurrentStep(),
    selected: countValid(),
  };

  return out;
})()
JS;

$pageUrl = $baseUrl . '/index.php';
$result = p0_cdp_evaluate($pageUrl, $js);

$loss = (array) ($result['scenarios']['loss'] ?? []);
$happy = (array) ($result['scenarios']['happy'] ?? []);
$gate = (array) ($result['scenarios']['review_gate'] ?? []);

$checks = [
    'A_redirect_after_lookup_loss' => ($loss['step_after_lookup'] ?? 0) === 2
        && ($loss['selected_after'] ?? 1) === 0
        && !empty($loss['error_visible']),
    'B_happy_path_selection_survives' => ($happy['selected_after'] ?? 0) > 0
        && ($happy['step_after_lookup'] ?? 0) >= 3,
    'C_step8_requires_events' => ($happy['validate_step8_without_consent'] ?? true) === false,
    'D_step8_pass_with_events_and_consent' => ($happy['validate_step8_with_consent'] ?? false) === true,
    'E_review_no_none_selected' => ($happy['review_has_none'] ?? true) === false,
    'F_review_gate_blocks_step8' => ($gate['step_after_review_click'] ?? 8) === 2,
];

$report = [
    'generated_at' => gmdate('c'),
    'base_url' => $baseUrl,
    'test_email' => $email,
    'registered_event_ids' => $registeredIds,
    'checks' => $checks,
    'raw' => $result,
    'verdict' => !in_array(false, $checks, true) ? 'PASS' : 'FAIL',
];

@mkdir(dirname($reportPath), 0777, true);
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

if ($screenshots) {
    @mkdir($shotDir, 0777, true);
    $shotJs = <<<JS
(async function () {
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const wizard = window.RegistrationWizard;
  const emailEl = document.getElementById('email');
  emailEl.value = '{$emailEsc}';

  wizard.showStep(2);
  await wait(1800);
  const registeredIds = {$registeredJson};
  let input = null;
  for (const id of registeredIds) {
    input = document.querySelector('#shift-picker-list input[name="event_ids[]"][value="' + id + '"]');
    if (input) break;
  }
  if (input) {
    const label = input.closest('label');
    if (label) label.click();
    await wait(200);
  }
  wizard.showStep(3);
  await wait(300);
  emailEl.dispatchEvent(new Event('input', { bubbles: true }));
  emailEl.dispatchEvent(new Event('blur', { bubbles: true }));
  await wait(2500);
  return wizard.getCurrentStep();
})()
JS;
    p0_cdp_session($pageUrl, static function (callable $send) use ($shotJs, $shotDir): void {
        $send([
            'method' => 'Runtime.evaluate',
            'params' => ['expression' => $shotJs, 'awaitPromise' => true],
        ]);
        usleep(500000);
        p0_screenshot($send, $shotDir . '/after-fix-step2-redirect.png');

        $send([
            'method' => 'Runtime.evaluate',
            'params' => ['expression' => <<<'JS2'
(async function () {
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const wizard = window.RegistrationWizard;
  wizard.showStep(2);
  await wait(1500);
  const open = document.querySelector('#shift-picker-list .reg-event-card:not(.reg-event-card--registered) input[name="event_ids[]"]');
  if (open) { const l = open.closest('label'); if (l) l.click(); }
  await wait(200);
  for (let i = 0; i < 6; i++) { document.getElementById('reg-wizard-next').click(); await wait(350); }
  document.querySelector('input[name="privacy_consent"]').checked = true;
  if (window.RegistrationWizardReview) window.RegistrationWizardReview.render();
  await wait(300);
  return true;
})()
JS2, 'awaitPromise' => true],
        ]);
        usleep(500000);
        p0_screenshot($send, $shotDir . '/after-fix-step8-review.png');
    });
    $report['screenshots'] = [
        $shotDir . '/after-fix-step2-redirect.png',
        $shotDir . '/after-fix-step8-review.png',
    ];
}

if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo "Returning shift P0 verification — {$email}\n";
    echo 'Verdict: ' . $report['verdict'] . "\n\n";
    foreach ($checks as $name => $ok) {
        echo ($ok ? '[PASS]' : '[FAIL]') . " {$name}\n";
    }
    echo "\nJSON: {$reportPath}\n";
}

exit($report['verdict'] === 'PASS' ? 0 : 1);
