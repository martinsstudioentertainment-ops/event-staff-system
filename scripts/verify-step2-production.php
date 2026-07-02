<?php
/**
 * Production Step 2 event selection verification (scenarios A + B).
 *
 * Usage:
 *   php scripts/verify-step2-production.php
 *   php scripts/verify-step2-production.php --json
 */

declare(strict_types=1);

$opts = getopt('', ['base::', 'returning-email::', 'json']);
$baseUrl = rtrim((string) ($opts['base'] ?? getenv('REGISTRATION_BASE_URL') ?: 'https://register.olasentra.com'), '/');
$returningEmail = trim((string) ($opts['returning-email'] ?? 'e2e-wizard-20260606164932@olasentra-e2e.test'));
$newEmail = 'step2-verify-' . gmdate('YmdHis') . '@olasentra-e2e.test';
$jsonOut = array_key_exists('json', $opts);
$root = dirname(__DIR__);
$outPath = $root . '/storage/reports/step2-selection-verify-latest.json';

function http_get(string $url): string
{
    $ctx = stream_context_create(['http' => ['timeout' => 45, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : '';
}

function report_line(bool $jsonOut, string $msg): void
{
    if (!$jsonOut) {
        fwrite(STDOUT, $msg . PHP_EOL);
    }
}

function cdp_evaluate(string $pageUrl, string $expression): array
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
        throw new RuntimeException('Microsoft Edge not found for CDP verification.');
    }

    $port = 9300 + random_int(1, 200);
    $userData = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-step2-' . bin2hex(random_bytes(6));
    mkdir($userData, 0777, true);

    $cmd = sprintf(
        '"%s" --headless=new --disable-gpu --remote-debugging-port=%d --user-data-dir="%s" "%s"',
        $edge,
        $port,
        $userData,
        $pageUrl
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start headless Edge.');
    }
    fclose($pipes[0]);

    try {
        $target = null;
        $deadline = time() + 25;
        while (time() < $deadline) {
            $json = @file_get_contents("http://127.0.0.1:{$port}/json/list");
            if (is_string($json)) {
                $targets = json_decode($json, true);
                if (is_array($targets)) {
                    foreach ($targets as $t) {
                        if (($t['type'] ?? '') === 'page' && !str_starts_with((string) ($t['url'] ?? ''), 'chrome-')) {
                            $target = $t;
                            break 2;
                        }
                    }
                }
            }
            usleep(400000);
        }
        if ($target === null || empty($target['webSocketDebuggerUrl'])) {
            throw new RuntimeException('No DevTools page target available.');
        }

        $wsUrl = parse_url((string) $target['webSocketDebuggerUrl']);
        $host = $wsUrl['host'] ?? '127.0.0.1';
        $portWs = (int) ($wsUrl['port'] ?? 80);
        $path = $wsUrl['path'] ?? '/';
        if (!empty($wsUrl['query'])) {
            $path .= '?' . $wsUrl['query'];
        }

        $key = base64_encode(random_bytes(16));
        $socket = @stream_socket_client("tcp://{$host}:{$portWs}", $errno, $errstr, 10);
        if ($socket === false) {
            throw new RuntimeException("WebSocket TCP failed: {$errstr}");
        }

        $headers = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}:{$portWs}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($socket, $headers);
        $handshake = stream_get_contents($socket, 8192);
        if ($handshake === false || !str_contains($handshake, '101')) {
            throw new RuntimeException('WebSocket handshake failed.');
        }

        $send = static function ($socket, array $payload) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $len = strlen($json);
            $frame = chr(0x81);
            if ($len <= 125) {
                $frame .= chr($len | 0x80);
            } elseif ($len <= 65535) {
                $frame .= chr(126 | 0x80) . pack('n', $len);
            } else {
                $frame .= chr(127 | 0x80) . pack('J', $len);
            }
            $mask = random_bytes(4);
            $frame .= $mask;
            for ($i = 0; $i < $len; $i++) {
                $frame .= $json[$i] ^ $mask[$i % 4];
            }
            fwrite($socket, $frame);
        };

        $recv = static function ($socket) {
            $data = fread($socket, 65536);
            if ($data === false || $data === '') {
                return null;
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
            $payload = substr($data, $offset, $payloadLen);
            return json_decode($payload, true);
        };

        $send($socket, ['id' => 1, 'method' => 'Runtime.enable', 'params' => (object) []]);
        $recv($socket);
        sleep(2);

        $send($socket, [
            'id' => 2,
            'method' => 'Runtime.evaluate',
            'params' => [
                'expression' => $expression,
                'awaitPromise' => true,
                'returnByValue' => true,
            ],
        ]);

        $deadline = time() + 30;
        while (time() < $deadline) {
            $msg = $recv($socket);
            if (!is_array($msg) || ($msg['id'] ?? 0) !== 2) {
                continue;
            }
            if (!empty($msg['result']['exceptionDetails'])) {
                $desc = (string) ($msg['result']['exceptionDetails']['exception']['description'] ?? $msg['result']['exceptionDetails']['text'] ?? 'JS exception');
                throw new RuntimeException($desc);
            }
            $value = $msg['result']['result']['value'] ?? null;
            if (!is_array($value)) {
                throw new RuntimeException('Unexpected CDP result payload.');
            }
            return $value;
        }
        throw new RuntimeException('CDP evaluate timeout.');
    } finally {
        if (is_resource($proc)) {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        // Edge profile dir cleanup is best-effort; proc_terminate may leave locks.
    }
}

function build_scenario_js(string $email, bool $enterEmailOnStep3): string
{
    $enter = $enterEmailOnStep3 ? 'true' : 'false';
    $emailEsc = addslashes($email);

    return <<<JS
(async function () {
  const out = {
    console_errors: [],
    wrap_locked: false,
    open_cards: 0,
    registered_cards: 0,
    click_fired: false,
    summary_before: '',
    summary_after: '',
    continue_enabled: false,
    lookup: false,
    registered_ids: [],
    email: '{$emailEsc}'
  };
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const wizard = window.RegistrationWizard;
  if (!wizard || typeof wizard.showStep !== 'function') {
    out.fatal = 'RegistrationWizard not available';
    return out;
  }
  wizard.showStep(1);
  await wait(300);
  if ({$enter}) {
    wizard.showStep(3);
    await wait(500);
    const emailEl = document.getElementById('email');
    if (emailEl) {
      emailEl.value = '{$emailEsc}';
      emailEl.dispatchEvent(new Event('input', { bubbles: true }));
      emailEl.dispatchEvent(new Event('blur', { bubbles: true }));
      await wait(1500);
      if (window.RegistrationWizardReturning && typeof window.RegistrationWizardReturning.getLastPayload === 'function') {
        const payload = window.RegistrationWizardReturning.getLastPayload();
        if (payload && payload.found) {
          out.lookup = true;
          out.registered_ids = Array.isArray(payload.registered_event_ids) ? payload.registered_event_ids : [];
        }
      }
    }
  }
  wizard.showStep(2);
  await wait(2000);
  const wrap = document.getElementById('event-selection-wrap');
  out.wrap_locked = !!(wrap && wrap.classList.contains('shift-picker-locked'));
  const list = document.getElementById('shift-picker-list');
  const summary = document.getElementById('shift-picker-summary');
  out.summary_before = summary ? summary.textContent.trim() : '';
  const openInput = list ? list.querySelector('.reg-event-card:not(.reg-event-card--registered) input[name="event_ids[]"]') : null;
  out.open_cards = list ? list.querySelectorAll('.reg-event-card:not(.reg-event-card--registered)').length : 0;
  out.registered_cards = list ? list.querySelectorAll('.reg-event-card--registered').length : 0;
  if (openInput) {
    const label = openInput.closest('label');
    if (label) {
      label.click();
    } else {
      openInput.click();
      openInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    await wait(300);
    out.click_fired = true;
  }
  out.summary_after = summary ? summary.textContent.trim() : '';
  const continueBtn = document.getElementById('reg-wizard-next');
  out.continue_enabled = !!(continueBtn && !continueBtn.disabled && !continueBtn.hidden);
  return out;
})()
JS;
}

function scenario_from_cdp(string $name, string $email, array $cdp, bool $expectLookup): array
{
    $summaryBefore = (string) ($cdp['summary_before'] ?? '');
    $summaryAfter = (string) ($cdp['summary_after'] ?? '');
    $counterChanged = $summaryBefore !== $summaryAfter && preg_match('/[1-9]\d* shift/', $summaryAfter) === 1;

    $row = [
        'scenario' => $name,
        'email' => $email,
        'profile_lookup_occurred' => (bool) ($cdp['lookup'] ?? false),
        'registered_event_ids' => array_values(array_map('intval', (array) ($cdp['registered_ids'] ?? []))),
        'wrap_has_shift_picker_locked' => (bool) ($cdp['wrap_locked'] ?? false),
        'open_cards_found' => (int) ($cdp['open_cards'] ?? 0),
        'registered_cards_found' => (int) ($cdp['registered_cards'] ?? 0),
        'card_click_handler_fired' => (bool) ($cdp['click_fired'] ?? false),
        'selected_shift_counter_changed' => $counterChanged,
        'selected_shift_summary' => $summaryAfter,
        'continue_button_enabled' => (bool) ($cdp['continue_enabled'] ?? false),
        'console_errors' => array_values((array) ($cdp['console_errors'] ?? [])),
        'notes' => [],
        'pass' => false,
    ];

    if (!empty($cdp['fatal'])) {
        $row['notes'][] = (string) $cdp['fatal'];
    }
    if ($expectLookup && !$row['profile_lookup_occurred']) {
        $row['notes'][] = 'Expected profile lookup on Step 3 but none detected.';
    }

    $row['pass'] = !$row['wrap_has_shift_picker_locked']
        && $row['open_cards_found'] > 0
        && $row['card_click_handler_fired']
        && $row['selected_shift_counter_changed']
        && $row['continue_button_enabled']
        && (!$expectLookup || $row['profile_lookup_occurred']);

    return $row;
}

$indexHtml = http_get($baseUrl . '/index.php');
$gateJs = http_get($baseUrl . '/assets/js/registration-shift-gate.js');

$fixOk = str_contains($indexHtml, 'data-wizard-mode="1"')
    && !preg_match('/id="event-selection-wrap"[^>]*shift-picker-locked/', $indexHtml)
    && str_contains($gateJs, 'wizardWrap.classList.remove');

$fixDetail = $fixOk
    ? 'No shift-picker-locked on wrap; wizard gate unlock present'
    : (str_contains($indexHtml, 'shift-picker-locked') ? 'shift-picker-locked still present in production HTML' : 'Wizard assets/flag check failed');

report_line($jsonOut, "Step 2 verification -> {$baseUrl}");
report_line($jsonOut, 'Fix deployed: ' . ($fixOk ? 'YES' : 'NO') . ' - ' . $fixDetail);

$lookupJson = http_get($baseUrl . '/api/registrant-lookup.php?email=' . rawurlencode($returningEmail));
$lookupData = json_decode($lookupJson, true);

$scenarioA = scenario_from_cdp(
    'A_new_applicant',
    $newEmail,
    cdp_evaluate($baseUrl . '/index.php', build_scenario_js($newEmail, false)),
    false
);

$scenarioB = scenario_from_cdp(
    'B_returning_applicant',
    $returningEmail,
    cdp_evaluate($baseUrl . '/index.php', build_scenario_js($returningEmail, true)),
    true
);

$allPass = $fixOk && $scenarioA['pass'] && $scenarioB['pass'];
if ($allPass) {
    $classification = 'resolved_not_a_blocking_defect';
} elseif (!$fixOk) {
    $classification = 'production_defect_fix_not_deployed';
} elseif (!$scenarioA['pass'] && !$scenarioB['pass']) {
    $classification = 'production_defect_affects_both_paths';
} elseif (!$scenarioA['pass']) {
    $classification = 'production_defect_new_applicants_only';
} else {
    $classification = 'production_defect_returning_applicants_only';
}

$report = [
    'generated_at' => gmdate('c'),
    'base_url' => $baseUrl,
    'fix_deployed' => ['ok' => $fixOk, 'detail' => $fixDetail],
    'root_cause' => 'shift-picker-locked applied pointer-events:none on #event-selection-wrap; registration-shift-gate.js skipped unlock in wizard mode',
    'responsible_files' => ['index.php', 'assets/js/registration-shift-gate.js', 'assets/css/registration-compact.css'],
    'returning_lookup_api' => [
        'email' => $returningEmail,
        'found' => (bool) ($lookupData['found'] ?? false),
        'registered_event_ids' => array_values(array_map('intval', (array) ($lookupData['registered_event_ids'] ?? []))),
    ],
    'scenarios' => [$scenarioA, $scenarioB],
    'classification' => $classification,
];

@mkdir(dirname($outPath), 0777, true);
file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    foreach ([$scenarioA, $scenarioB] as $s) {
        report_line($jsonOut, '');
        report_line($jsonOut, $s['scenario'] . ': ' . ($s['pass'] ? 'PASS' : 'FAIL'));
        report_line($jsonOut, '  Email: ' . $s['email']);
        report_line($jsonOut, '  Lookup: ' . ($s['profile_lookup_occurred'] ? 'yes' : 'no'));
        report_line($jsonOut, '  Wrap locked: ' . ($s['wrap_has_shift_picker_locked'] ? 'yes' : 'no'));
        report_line($jsonOut, '  Click fired: ' . ($s['card_click_handler_fired'] ? 'yes' : 'no'));
        report_line($jsonOut, '  Counter changed: ' . ($s['selected_shift_counter_changed'] ? 'yes' : 'no'));
        report_line($jsonOut, '  Summary: ' . $s['selected_shift_summary']);
        report_line($jsonOut, '  Continue enabled: ' . ($s['continue_button_enabled'] ? 'yes' : 'no'));
    }
    report_line($jsonOut, '');
    report_line($jsonOut, 'Classification: ' . $classification);
    report_line($jsonOut, 'JSON: ' . $outPath);
}

exit($allPass ? 0 : 1);
