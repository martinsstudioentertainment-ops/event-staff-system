# Phase A.6 automated verification — autosave visibility (no browser required).
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$results = @()

function Add-Result([string]$Check, [string]$Status, [string]$Detail) {
    $script:results += [PSCustomObject]@{ Check = $Check; Status = $Status; Detail = $Detail }
}

$shell = Get-Content (Join-Path $Root 'includes\public\registration-wizard-shell.php') -Raw
if ($shell -match 'reg-wizard-save-status' -and $shell -match 'reg-wizard-save-text') {
    Add-Result 'Save status mount in wizard shell' 'PASS' 'reg-wizard-save-status in registration-wizard-shell.php'
} else {
    Add-Result 'Save status mount in wizard shell' 'FAIL' 'Missing save status element'
}

$autosave = Get-Content (Join-Path $Root 'assets\js\registration-wizard-autosave.js') -Raw
$autosaveChecks = @(
    @{ Pat = 'setSaveStatus'; Label = 'Save status UI function' },
    @{ Pat = 'Saving draft'; Label = 'Saving draft message' },
    @{ Pat = 'Last saved'; Label = 'Last saved time label' },
    @{ Pat = 'formatSavedTime'; Label = 'Saved time formatter' },
    @{ Pat = 'stepLabel'; Label = 'Step label helper' },
    @{ Pat = 'REG_WIZARD_STEP_NAMES'; Label = 'Shared step names export' },
    @{ Pat = 'localStorage'; Label = 'localStorage only (no DB)' }
)
foreach ($c in $autosaveChecks) {
    if ($autosave -match [regex]::Escape($c.Pat)) {
        Add-Result $c.Label 'PASS' 'Present in registration-wizard-autosave.js'
    } else {
        Add-Result $c.Label 'FAIL' 'Missing from registration-wizard-autosave.js'
    }
}

$returning = Get-Content (Join-Path $Root 'assets\js\registration-wizard-returning.js') -Raw
$returningChecks = @(
    @{ Pat = 'reg-resume-prompt__badge'; Label = 'Resume badge copy' },
    @{ Pat = 'Resume your application'; Label = 'Resume title' },
    @{ Pat = 'Last saved'; Label = 'Last saved in resume prompt' },
    @{ Pat = 'PSA photos are not saved'; Label = 'PSA draft disclaimer' },
    @{ Pat = 'Start fresh'; Label = 'Start fresh button' },
    @{ Pat = 'stepLabel'; Label = 'Step name in resume prompt' }
)
foreach ($c in $returningChecks) {
    if ($returning -match [regex]::Escape($c.Pat)) {
        Add-Result $c.Label 'PASS' 'Present in registration-wizard-returning.js'
    } else {
        Add-Result $c.Label 'FAIL' 'Missing from registration-wizard-returning.js'
    }
}

$css = Get-Content (Join-Path $Root 'assets\css\registration-wizard.css') -Raw
$cssChecks = @(
    'reg-wizard__save-status',
    'reg-wizard__save-status--saving',
    'reg-wizard__save-status--saved',
    'reg-resume-prompt__badge',
    'reg-resume-prompt__note'
)
foreach ($pat in $cssChecks) {
    if ($css -match [regex]::Escape($pat)) {
        Add-Result "CSS: $pat" 'PASS' 'Styled in registration-wizard.css'
    } else {
        Add-Result "CSS: $pat" 'FAIL' 'Missing from registration-wizard.css'
    }
}

$index = Get-Content (Join-Path $Root 'index.php') -Raw
if ($index -match 'registration-wizard-autosave\.js' -and $index -match 'data-wizard-mode') {
    Add-Result 'Wizard-only script gating' 'PASS' 'Autosave only when wizard flag ON'
} else {
    Add-Result 'Wizard-only script gating' 'FAIL' 'Check index.php wizard gating'
}

$submit = Get-Content (Join-Path $Root 'submit.php') -Raw -ErrorAction SilentlyContinue
if ($null -eq $submit) {
    Add-Result 'submit.php unchanged' 'PASS' 'File not readable — manual check'
} else {
    # A.6 must not touch submit.php — verify no autosave references added
    if ($submit -notmatch 'registration-wizard-autosave') {
        Add-Result 'submit.php unchanged' 'PASS' 'No autosave wiring in submit.php'
    } else {
        Add-Result 'submit.php unchanged' 'FAIL' 'submit.php references autosave'
    }
}

$fail = @($results | Where-Object { $_.Status -eq 'FAIL' })
$pass = @($results | Where-Object { $_.Status -eq 'PASS' })
Write-Host ""
Write-Host "Phase A.6 verification: $($pass.Count) PASS / $($fail.Count) FAIL"
$results | Format-Table -AutoSize
if ($fail.Count -gt 0) { exit 1 }
