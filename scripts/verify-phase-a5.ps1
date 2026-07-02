# Phase A.5 automated verification checks (no browser required).
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$results = @()

function Add-Result([string]$Check, [string]$Status, [string]$Detail) {
    $script:results += [PSCustomObject]@{ Check = $Check; Status = $Status; Detail = $Detail }
}

$restoreJs = Join-Path $Root 'assets\js\registration-wizard-server-restore.js'
if (Test-Path $restoreJs) {
    $text = Get-Content $restoreJs -Raw -Encoding UTF8
    $checks = @(
        'RESTORE_STEP = 8',
        'showAlert',
        'applyFieldErrors',
        'server_error_restore',
        'RegistrationWizard.showStep',
        'reg-review-summary__error-banner'
    )
    foreach ($c in $checks) {
        if ($text -match [regex]::Escape($c)) {
            Add-Result "server-restore: $c" 'PASS' 'Present'
        } else {
            Add-Result "server-restore: $c" 'FAIL' 'Missing'
        }
    }
} else {
    Add-Result 'registration-wizard-server-restore.js' 'FAIL' 'File missing'
}

$valJs = Get-Content (Join-Path $Root 'assets\js\registration-wizard-validation.js') -Raw
if ($valJs -match 'showError:\s*showError') {
    Add-Result 'Validation showError export' 'PASS' 'RegistrationWizardValidation.showError exposed'
} else {
    Add-Result 'Validation showError export' 'FAIL' 'showError not exported'
}

$eventsJs = Get-Content (Join-Path $Root 'assets\js\events.js') -Raw
if ($eventsJs -match 'RegistrationWizardReview\.render') {
    Add-Result 'Review refresh on shift load (D2)' 'PASS' 'events.js calls review render after cards populate'
} else {
    Add-Result 'Review refresh on shift load (D2)' 'FAIL' 'Missing review render hook'
}

$index = Get-Content (Join-Path $Root 'index.php') -Raw
if ($index -match 'data-server-error-restore') {
    Add-Result 'index.php server error flag' 'PASS' 'data-server-error-restore on body'
} else {
    Add-Result 'index.php server error flag' 'FAIL' 'Missing data-server-error-restore'
}
if ($index -match 'registration-wizard-server-restore\.js') {
    Add-Result 'index.php restore script' 'PASS' 'server-restore.js enqueued'
} else {
    Add-Result 'index.php restore script' 'FAIL' 'server-restore.js not enqueued'
}
if ($index -match 'REG_WIZARD_RESTORE_STEP = 8') {
    Add-Result 'index.php always restore Step 8' 'PASS' 'Server errors always open review step'
} else {
    Add-Result 'index.php always restore Step 8' 'FAIL' 'Missing Step 8 restore script'
}

$reviewJs = Get-Content (Join-Path $Root 'assets\js\registration-wizard-review.js') -Raw
$reviewChecks = @(
    @{ Pat = 'reg-review-summary__section--error'; Label = 'Review section error highlight' },
    @{ Pat = 'reg-review-summary__fix'; Label = 'Review Fix buttons' },
    @{ Pat = 'Consent'; Label = 'Consent section in review' },
    @{ Pat = 'shiftPickerReady'; Label = 'Shift picker ready listener' },
    @{ Pat = 'dataset.selected'; Label = 'Event ID fallback from dataset.selected' }
)
foreach ($c in $reviewChecks) {
    if ($reviewJs -match [regex]::Escape($c.Pat)) {
        Add-Result $c.Label 'PASS' 'Present in registration-wizard-review.js'
    } else {
        Add-Result $c.Label 'FAIL' 'Missing from registration-wizard-review.js'
    }
}

if (Test-Path (Join-Path $Root 'includes\public\registration-success-panel.php')) {
    Add-Result 'Success confirmation panel' 'PASS' 'registration-success-panel.php exists'
} else {
    Add-Result 'Success confirmation panel' 'FAIL' 'Missing success panel include'
}

$statusPhp = Get-Content (Join-Path $Root 'status.php') -Raw
if ($statusPhp -match 'renderRegistrationSuccessPanel') {
    Add-Result 'status.php success panel wired' 'PASS' 'Confirmation panel on status page'
} else {
    Add-Result 'status.php success panel wired' 'FAIL' 'Success panel not wired'
}

$e2e = Join-Path $Root 'scripts\e2e-registration-wizard-test.php'
if (Test-Path $e2e) {
    Add-Result 'E2E test script' 'PASS' 'scripts/e2e-registration-wizard-test.php exists'
} else {
    Add-Result 'E2E test script' 'FAIL' 'Missing e2e script'
}

$fail = @($results | Where-Object { $_.Status -eq 'FAIL' })
$pass = @($results | Where-Object { $_.Status -eq 'PASS' })
Write-Host ""
Write-Host "Phase A.5 verification: $($pass.Count) PASS / $($fail.Count) FAIL"
$results | Format-Table -AutoSize
if ($fail.Count -gt 0) { exit 1 }
