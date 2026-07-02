# Production Step 2 event selection verification (scenarios A + B).
# Uses Edge DevTools Protocol (headless) — no Node required.
param(
    [string] $BaseUrl = 'https://register.olasentra.com',
    [string] $ReturningEmail = 'e2e-wizard-20260606164932@olasentra-e2e.test',
    [string] $NewEmail = '',
    [string] $OutJson = ''
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
if ($OutJson -eq '') {
    $OutJson = Join-Path $Root 'storage\reports\step2-selection-verify-latest.json'
}
New-Item -ItemType Directory -Force -Path (Split-Path $OutJson -Parent) | Out-Null

if ($NewEmail -eq '') {
    $NewEmail = 'step2-verify-' + (Get-Date -Format 'yyyyMMddHHmmss') + '@olasentra-e2e.test'
}

$Edge = @(
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $Edge) { throw 'Microsoft Edge is required for Step 2 interaction tests.' }

$report = [ordered]@{
    generated_at = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    base_url = $BaseUrl.TrimEnd('/')
    fix_deployed = $null
    scenarios = @()
    classification = 'pending'
    root_cause = 'shift-picker-locked CSS pointer-events:none on #event-selection-wrap while registration-shift-gate.js skips unlock in wizard mode'
    responsible_files = @('index.php', 'assets/js/registration-shift-gate.js', 'assets/css/registration-compact.css')
}

function Get-IndexHtml {
    return (Invoke-WebRequest -Uri "$($report.base_url)/index.php" -UseBasicParsing -TimeoutSec 45).Content
}

function Test-FixDeployed([string]$html) {
    if ($html -notmatch 'data-wizard-mode="1"') {
        return @{ ok = $false; detail = 'Wizard flag OFF (data-wizard-mode not 1)' }
    }
    if ($html -match 'id="event-selection-wrap"[^>]*shift-picker-locked') {
        return @{ ok = $false; detail = 'event-selection-wrap still has shift-picker-locked in server HTML' }
    }
    $gateJs = (Invoke-WebRequest -Uri "$($report.base_url)/assets/js/registration-shift-gate.js" -UseBasicParsing -TimeoutSec 30).Content
    if ($gateJs -notmatch 'wizardWrap\.classList\.remove\(''shift-picker-locked''\)') {
        return @{ ok = $false; detail = 'registration-shift-gate.js missing wizard unlock fallback' }
    }
    return @{ ok = $true; detail = 'No shift-picker-locked on wrap; wizard gate unlock present' }
}

Add-Type -AssemblyName System.Net.WebSockets

function Invoke-CdpSession {
    param(
        [string] $StartUrl,
        [string] $EvaluateScript,
        [int] $Port = 0
    )
    if ($Port -le 0) { $Port = 9200 + (Get-Random -Maximum 200) }
    $userData = Join-Path $env:TEMP ("edge-step2-" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Force -Path $userData | Out-Null
    $proc = Start-Process -FilePath $Edge -ArgumentList @(
        '--headless=new',
        '--disable-gpu',
        "--remote-debugging-port=$Port",
        "--user-data-dir=$userData",
        $StartUrl
    ) -PassThru -WindowStyle Hidden

    try {
        $deadline = (Get-Date).AddSeconds(25)
        $target = $null
        while ((Get-Date) -lt $deadline) {
            try {
                $targets = Invoke-RestMethod -Uri "http://127.0.0.1:$Port/json/list" -TimeoutSec 3
                $target = $targets | Where-Object { $_.type -eq 'page' -and $_.url -notmatch '^chrome-' } | Select-Object -First 1
                if ($target) { break }
            } catch { Start-Sleep -Milliseconds 400 }
        }
        if (-not $target) { throw 'Could not attach to Edge DevTools target.' }

        $ws = New-Object System.Net.WebSockets.ClientWebSocket
        $cts = New-Object System.Threading.CancellationTokenSource
        $ws.ConnectAsync([Uri]$target.webSocketDebuggerUrl, $cts.Token).Wait()
        $script:cdpId = 0
        $script:cdpReplies = @{}

        function Send-Cdp([string]$method, [hashtable]$params) {
            $script:cdpId++
            $id = $script:cdpId
            $payload = @{ id = $id; method = $method; params = $params }
            $json = ($payload | ConvertTo-Json -Compress -Depth 12)
            $bytes = [Text.Encoding]::UTF8.GetBytes($json)
            $seg = [ArraySegment[byte]]::new($bytes)
            $ws.SendAsync($seg, [Net.WebSockets.WebSocketMessageType]::Text, $true, $cts.Token).Wait() | Out-Null
            $buf = New-Object byte[] 65536
            $deadline = (Get-Date).AddSeconds(20)
            while ((Get-Date) -lt $deadline) {
                $segIn = [ArraySegment[byte]]::new($buf)
                $task = $ws.ReceiveAsync($segIn, $cts.Token)
                if (-not $task.Wait(5000)) { continue }
                $text = [Text.Encoding]::UTF8.GetString($buf, 0, $task.Result.Count)
                $msg = $text | ConvertFrom-Json
                if ($msg.id -eq $id) { return $msg }
            }
            throw "CDP timeout waiting for $method"
        }

        Send-Cdp 'Runtime.enable' @{} | Out-Null
        Send-Cdp 'Page.enable' @{} | Out-Null
        Start-Sleep -Seconds 2
        $expr = ($EvaluateScript -replace "`r`n", ' ').Trim()
        $resp = Send-Cdp 'Runtime.evaluate' @{
            expression = $expr
            awaitPromise = $true
            returnByValue = $true
        }
        if ($resp.result.exceptionDetails) {
            $text = $resp.result.exceptionDetails.exception.description
            if (-not $text) { $text = $resp.result.exceptionDetails.text }
            throw $text
        }
        return $resp.result.result.value
    }
    finally {
        if ($proc -and -not $proc.HasExited) { $proc.Kill($true) }
        Remove-Item -LiteralPath $userData -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Run-Scenario {
    param(
        [string] $Name,
        [string] $Email,
        [bool] $ExpectLookup,
        [bool] $EnterEmailOnStep3
    )

    $scenario = [ordered]@{
        scenario = $Name
        email = $Email
        profile_lookup_occurred = $false
        registered_event_ids = @()
        wrap_has_shift_picker_locked = $null
        open_cards_found = 0
        registered_cards_found = 0
        card_click_handler_fired = $false
        selected_shift_counter_changed = $false
        selected_shift_summary = ''
        continue_button_enabled = $false
        console_errors = @()
        pass = $false
        notes = @()
    }

    $js = @"
async function run() {
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
    email: '$Email'
  };
  const origErr = console.error;
  console.error = function() {
    out.console_errors.push(Array.from(arguments).join(' '));
    origErr.apply(console, arguments);
  };
  const wait = (ms) => new Promise(r => setTimeout(r, ms));
  const wizard = window.RegistrationWizard;
  if (!wizard || typeof wizard.showStep !== 'function') {
    out.fatal = 'RegistrationWizard not available';
    return out;
  }
  wizard.showStep(1);
  await wait(300);
  if ($($EnterEmailOnStep3.ToString().ToLower())) {
    wizard.showStep(3);
    await wait(400);
    const emailEl = document.getElementById('email');
    if (emailEl) {
      emailEl.value = '$Email';
      emailEl.dispatchEvent(new Event('input', { bubbles: true }));
      emailEl.dispatchEvent(new Event('blur', { bubbles: true }));
      await wait(1200);
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
  await wait(1500);
  const wrap = document.getElementById('event-selection-wrap');
  out.wrap_locked = !!(wrap && wrap.classList.contains('shift-picker-locked'));
  const list = document.getElementById('shift-picker-list');
  const summary = document.getElementById('shift-picker-summary');
  out.summary_before = summary ? summary.textContent.trim() : '';
  const openCard = list ? list.querySelector('.reg-event-card:not(.reg-event-card--registered) input[name="event_ids[]"]') : null;
  const regCard = list ? list.querySelector('.reg-event-card--registered') : null;
  out.open_cards = list ? list.querySelectorAll('.reg-event-card:not(.reg-event-card--registered)').length : 0;
  out.registered_cards = list ? list.querySelectorAll('.reg-event-card--registered').length : 0;
  if (openCard) {
    const label = openCard.closest('label');
    if (label) {
      label.click();
      await wait(200);
      out.click_fired = true;
    } else {
      openCard.click();
      openCard.dispatchEvent(new Event('change', { bubbles: true }));
      await wait(200);
      out.click_fired = true;
    }
  }
  out.summary_after = summary ? summary.textContent.trim() : '';
  const continueBtn = document.getElementById('reg-wizard-continue');
  out.continue_enabled = !!(continueBtn && !continueBtn.disabled);
  return out;
}
run();
"@

    $result = Invoke-CdpSession -StartUrl "$($report.base_url)/index.php" -EvaluateScript $js

    $scenario.wrap_has_shift_picker_locked = [bool]$result.wrap_locked
    $scenario.open_cards_found = [int]$result.open_cards
    $scenario.registered_cards_found = [int]$result.registered_cards
    $scenario.profile_lookup_occurred = [bool]$result.lookup
    if ($result.registered_ids) { $scenario.registered_event_ids = @($result.registered_ids) }
    $scenario.card_click_handler_fired = [bool]$result.click_fired
    $scenario.selected_shift_summary = [string]$result.summary_after
    $scenario.selected_shift_counter_changed = ($result.summary_before -ne $result.summary_after) -and ($result.summary_after -match '[1-9]\d* shift')
    $scenario.continue_button_enabled = [bool]$result.continue_enabled
    if ($result.console_errors) { $scenario.console_errors = @($result.console_errors) }
    if ($result.fatal) { $scenario.notes += [string]$result.fatal }

    $scenario.pass = (-not $scenario.wrap_has_shift_picker_locked) `
        -and ($scenario.open_cards_found -gt 0) `
        -and $scenario.card_click_handler_fired `
        -and $scenario.selected_shift_counter_changed `
        -and $scenario.continue_button_enabled

    if ($ExpectLookup -and -not $scenario.profile_lookup_occurred) {
        $scenario.notes += 'Expected profile lookup on Step 3 but none detected.'
        $scenario.pass = $false
    }

    return [pscustomobject]$scenario
}

Write-Host "Step 2 verification -> $BaseUrl" -ForegroundColor Cyan
$html = Get-IndexHtml
$fix = Test-FixDeployed $html
$report.fix_deployed = $fix

Write-Host ('Fix deployed: {0} - {1}' -f $(if ($fix.ok) { 'YES' } else { 'NO' }), $fix.detail)

$scenarioA = Run-Scenario -Name 'A_new_applicant' -Email $NewEmail -ExpectLookup $false -EnterEmailOnStep3 $false
$report.scenarios += $scenarioA

$scenarioB = Run-Scenario -Name 'B_returning_applicant' -Email $ReturningEmail -ExpectLookup $true -EnterEmailOnStep3 $true
$report.scenarios += $scenarioB

$allPass = $fix.ok -and ($scenarioA.pass) -and ($scenarioB.pass)
if ($allPass) {
    $report.classification = 'resolved_not_a_blocking_defect'
} elseif (-not $fix.ok) {
    $report.classification = 'production_defect_fix_not_deployed'
} elseif (-not $scenarioA.pass -and -not $scenarioB.pass) {
    $report.classification = 'production_defect_affects_both_paths'
} elseif (-not $scenarioA.pass) {
    $report.classification = 'production_defect_new_applicants_only'
} else {
    $report.classification = 'production_defect_returning_applicants_only'
}

$report | ConvertTo-Json -Depth 8 | Set-Content -Path $OutJson -Encoding UTF8

Write-Host ""
Write-Host "Scenario A (new): $($scenarioA.pass)" -ForegroundColor $(if ($scenarioA.pass) { 'Green' } else { 'Red' })
Write-Host "  Email: $NewEmail"
Write-Host "  Lookup: $($scenarioA.profile_lookup_occurred) | Wrap locked: $($scenarioA.wrap_has_shift_picker_locked)"
Write-Host "  Summary: $($scenarioA.selected_shift_summary) | Continue: $($scenarioA.continue_button_enabled)"

Write-Host ""
Write-Host "Scenario B (returning): $($scenarioB.pass)" -ForegroundColor $(if ($scenarioB.pass) { 'Green' } else { 'Red' })
Write-Host "  Email: $ReturningEmail"
Write-Host "  Lookup: $($scenarioB.profile_lookup_occurred) | Registered IDs: $($scenarioB.registered_event_ids -join ', ')"
Write-Host "  Summary: $($scenarioB.selected_shift_summary) | Continue: $($scenarioB.continue_button_enabled)"

Write-Host ""
Write-Host "Classification: $($report.classification)"
Write-Host "JSON: $OutJson"

if (-not $allPass) { exit 1 }
