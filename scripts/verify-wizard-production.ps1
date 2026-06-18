# Verify registration wizard is active on production (register.olasentra.com).
$ErrorActionPreference = 'Stop'
$Base = if ($env:REGISTRATION_BASE_URL) { $env:REGISTRATION_BASE_URL } else { 'https://register.olasentra.com' }
$results = @()

function Add([string]$Check, [string]$Status, [string]$Detail) {
    $script:results += [PSCustomObject]@{ Check = $Check; Status = $Status; Detail = $Detail }
}

$html = (Invoke-WebRequest -Uri "$Base/index.php" -UseBasicParsing -TimeoutSec 30).Content

if ($html -match 'data-wizard-mode="1"') {
    Add 'Wizard mode in index.php' 'PASS' 'data-wizard-mode="1"'
} elseif ($html -match 'data-wizard-mode="0"') {
    Add 'Wizard mode in index.php' 'FAIL' 'data-wizard-mode="0" — feature_registration_wizard_v2 is OFF'
} else {
    Add 'Wizard mode in index.php' 'FAIL' 'data-wizard-mode attribute missing'
}

$wizardAssetsInHtml = @(
    'registration-wizard.css',
    'registration-wizard.js',
    'registration-wizard-autosave.js',
    'registration-wizard-validation.js'
)
foreach ($asset in $wizardAssetsInHtml) {
    if ($html -match [regex]::Escape($asset)) {
        Add "HTML includes $asset" 'PASS' 'Linked in index.php response'
    } else {
        Add "HTML includes $asset" 'FAIL' 'Not in page (flag likely OFF)'
    }
}

$stepCount = ([regex]::Matches($html, 'reg-wizard__step')).Count
if ($stepCount -ge 8) {
    Add 'Wizard step panels in HTML' 'PASS' "$stepCount reg-wizard__step elements"
} else {
    Add 'Wizard step panels in HTML' 'FAIL' "Found $stepCount (expected 8+)"
}

if ($html -match 'Step 1 of 8') {
    Add 'Progress label Step 1 of 8' 'PASS' 'Visible in wizard shell'
} else {
    Add 'Progress label Step 1 of 8' 'FAIL' 'Not in HTML (wizard shell not rendered)'
}

$remoteAssets = @(
    'assets/css/registration-wizard.css',
    'assets/js/registration-wizard.js'
)
foreach ($path in $remoteAssets) {
    try {
        $head = Invoke-WebRequest -Uri "$Base/$path" -Method Head -UseBasicParsing -TimeoutSec 15
        Add "Asset HTTP $($path)" 'PASS' "HTTP $($head.StatusCode)"
    } catch {
        Add "Asset HTTP $($path)" 'FAIL' $_.Exception.Message
    }
}

try {
    $analytics = Invoke-WebRequest -Uri "$Base/api/registration-analytics-event.php" -Method POST -ContentType 'application/json' -Body '{"event":"ping","session_id":"test","csrf_token":"x"}' -UseBasicParsing -TimeoutSec 15
    if ($analytics.Content -match 'Analytics disabled') {
        Add 'Analytics API (flag proxy)' 'FAIL' 'feature_registration_wizard_v2 is OFF'
    } else {
        Add 'Analytics API (flag proxy)' 'PASS' 'Analytics endpoint not disabled'
    }
} catch {
    Add 'Analytics API (flag proxy)' 'WARN' $_.Exception.Message
}

$fail = @($results | Where-Object Status -eq 'FAIL')
Write-Host ""
Write-Host "Wizard production check: $($results.Count - $fail.Count) OK / $($fail.Count) FAIL"
$results | Format-Table -AutoSize
if ($fail.Count -gt 0) { exit 1 }
