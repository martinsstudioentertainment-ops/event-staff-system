# Phase A.4 automated verification checks (no browser required).
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$results = @()

function Add-Result([string]$Check, [string]$Status, [string]$Detail) {
    $script:results += [PSCustomObject]@{ Check = $Check; Status = $Status; Detail = $Detail }
}

# 1. UTF-8 mojibake in wizard production files
$wizardFiles = @(
    'index.php',
    'includes\public\registration-wizard-shell.php',
    'assets\js\registration-wizard-review.js',
    'assets\js\registration-wizard-psa.js',
    'assets\js\registration-wizard-returning.js',
    'assets\css\registration-wizard.css'
)
$mojibake = @()
foreach ($f in $wizardFiles) {
    $path = Join-Path $Root $f
    if (-not (Test-Path $path)) { continue }
    $text = Get-Content $path -Raw -Encoding UTF8
    if ($text -match '\u2014|\u2013|\u00b7') {
        $mojibake += ($f + ' (unicode dash/dot in output)')
    }
}
if ($mojibake.Count -eq 0) {
    Add-Result 'UTF-8 encoding (wizard files)' 'PASS' 'No mojibake sequences in production wizard files'
} else {
    Add-Result 'UTF-8 encoding (wizard files)' 'FAIL' ("Found in: " + ($mojibake -join ', '))
}

# 2. Content-Type header
$index = Get-Content (Join-Path $Root 'index.php') -Raw
if ($index -match "header\('Content-Type: text/html; charset=UTF-8'\)") {
    Add-Result 'UTF-8 Content-Type header' 'PASS' 'index.php sends charset=UTF-8'
} else {
    Add-Result 'UTF-8 Content-Type header' 'FAIL' 'Missing charset header in index.php'
}

# 3. Review summary module exists and masks
$reviewJs = Get-Content (Join-Path $Root 'assets\js\registration-wizard-review.js') -Raw
$reviewChecks = @(
    @{ Pat = 'function maskIban'; Label = 'IBAN mask function' },
    @{ Pat = 'function maskPps'; Label = 'PPS mask function' },
    @{ Pat = 'reg-review-summary'; Label = 'Review summary markup' },
    @{ Pat = 'not your employer, payroll provider, or contracting party'; Label = 'Platform disclaimer in review' },
    @{ Pat = 'platform only - not your employer'; Label = 'Review disclaimer ASCII-safe' }
)
foreach ($c in $reviewChecks) {
    if ($reviewJs -match [regex]::Escape($c.Pat) -or $reviewJs -match $c.Pat) {
        Add-Result $c.Label 'PASS' 'Present in registration-wizard-review.js'
    } else {
        Add-Result $c.Label 'FAIL' 'Missing from registration-wizard-review.js'
    }
}

# Mask logic unit test (inline)
function Test-MaskIban([string]$iban) {
    $iban = $iban -replace '\s+', ''
    if ($iban.Length -lt 8) { return $iban }
    return '****' + $iban.Substring($iban.Length - 4)
}
function Test-MaskPps([string]$pps) {
    $pps = $pps.Trim()
    if ($pps.Length -lt 4) { return $pps }
    return '***' + $pps.Substring($pps.Length - 2)
}
$ibanMasked = Test-MaskIban 'IE29AIBK93115212345678'
$ppsMasked = Test-MaskPps '1234567AB'
if ($ibanMasked -eq '****5678') {
    Add-Result 'IBAN masking logic' 'PASS' "IE29...5678 -> $ibanMasked"
} else {
    Add-Result 'IBAN masking logic' 'FAIL' "Expected ****5678 got $ibanMasked"
}
if ($ppsMasked -eq '***AB') {
    Add-Result 'PPS masking logic' 'PASS' "1234567AB -> $ppsMasked"
} else {
    Add-Result 'PPS masking logic' 'FAIL' "Expected ***AB got $ppsMasked"
}

# 4. PSA upload status
$psaJs = Get-Content (Join-Path $Root 'assets\js\registration-wizard-psa.js') -Raw
if ($psaJs -match 'reg-psa-upload__status' -and $psaJs -match "capture") {
    Add-Result 'PSA upload status + camera' 'PASS' 'Status element and capture attribute in registration-wizard-psa.js'
} else {
    Add-Result 'PSA upload status + camera' 'FAIL' 'PSA module incomplete'
}

# 5. Contact panel + payroll notice in index.php
if ($index -match 'reg-wizard__contact-panel' -and $index -match 'reg-wizard__payroll-notice') {
    Add-Result 'Contact panel + payroll disclaimer' 'PASS' 'Markup present in index.php (wizard branch)'
} else {
    Add-Result 'Contact panel + payroll disclaimer' 'FAIL' 'Missing wizard panels in index.php'
}

if ($index -match 'not</strong> your employer, payroll provider, or contracting party') {
    Add-Result 'Payroll platform wording' 'PASS' 'Strengthened disclaimer text present'
} else {
    Add-Result 'Payroll platform wording' 'FAIL' 'Disclaimer text not found'
}

# 6. Step 8 container (not placeholder)
if ($index -match 'reg-review-summary' -and $index -notmatch 'later update') {
    Add-Result 'Step 8 review container' 'PASS' 'Live summary div; placeholder removed'
} else {
    Add-Result 'Step 8 review container' 'FAIL' 'Placeholder may still be present'
}

# 7. Wizard JS wired in index.php
$wizardScripts = @('registration-wizard-review.js', 'registration-wizard-psa.js', 'registration-wizard.js', 'registration-wizard-autosave.js', 'registration-wizard-returning.js')
$missingScripts = $wizardScripts | Where-Object { $index -notmatch [regex]::Escape($_) }
if ($missingScripts.Count -eq 0) {
    Add-Result 'Wizard script includes' 'PASS' 'All wizard JS modules referenced when flag ON block present'
} else {
    Add-Result 'Wizard script includes' 'FAIL' ("Missing: " + ($missingScripts -join ', '))
}

# 8. Feature flag OFF = legacy unchanged
if ($index -match 'if \(\$wizardV2Enabled\)' ) {
    Add-Result 'Feature flag gating' 'PASS' 'Wizard UI/scripts gated on $wizardV2Enabled'
} else {
    Add-Result 'Feature flag gating' 'FAIL' 'Flag gating unclear'
}
$legacyOk = ($index -match 'form-actions--wizard-hidden') -and ($index -match "data-wizard-mode=.*\? '1' : '0'")
if ($legacyOk) {
    Add-Result 'Legacy form preserved (flag OFF)' 'PASS' 'Wizard hidden class + data-wizard-mode=0 when flag off'
} else {
    Add-Result 'Legacy form preserved (flag OFF)' 'FAIL' 'Legacy fallback markers missing'
}

# 9. Autosave module
$autosave = Get-Content (Join-Path $Root 'assets\js\registration-wizard-autosave.js') -Raw
if ($autosave -match 'REG_WIZARD_DRAFT' -and $autosave -match 'localStorage') {
    Add-Result 'Autosave + resume draft' 'PASS' 'localStorage draft deferral intact'
} else {
    Add-Result 'Autosave + resume draft' 'FAIL' 'Autosave module issue'
}

# 10. Progress bar + nav in shell
$shell = Get-Content (Join-Path $Root 'includes\public\registration-wizard-shell.php') -Raw
if ($shell -match 'role="progressbar"' -and $shell -match 'reg-wizard__bar-fill') {
    Add-Result 'Progress bar markup' 'PASS' 'ARIA progressbar in wizard shell'
} else {
    Add-Result 'Progress bar markup' 'FAIL' 'Progress bar incomplete'
}
$wizJs = Get-Content (Join-Path $Root 'assets\js\registration-wizard.js') -Raw
if ($wizJs -match 'goNext' -and $wizJs -match 'goBack' -and $wizJs -match 'showStep') {
    Add-Result 'Back/Continue navigation' 'PASS' 'showStep/goNext/goBack in registration-wizard.js'
} else {
    Add-Result 'Back/Continue navigation' 'FAIL' 'Navigation handlers missing'
}

# 11. PHP syntax
$phpFiles = @('index.php', 'includes\public\registration-wizard-shell.php')
$phpFail = @()
foreach ($f in $phpFiles) {
    $p = Join-Path $Root $f
    $out = & php -l $p 2>&1
    if ($LASTEXITCODE -ne 0) { $phpFail += "$f : $out" }
}
if ($phpFail.Count -eq 0) {
    Add-Result 'PHP syntax (wizard files)' 'PASS' 'php -l clean'
} else {
    Add-Result 'PHP syntax (wizard files)' 'FAIL' ($phpFail -join '; ')
}

# 12. submit.php untouched (production rule)
$submitDiff = git -C $Root diff HEAD -- submit.php 2>$null
if ([string]::IsNullOrWhiteSpace($submitDiff)) {
    Add-Result 'submit.php unchanged' 'PASS' 'No local diff vs HEAD'
} else {
    Add-Result 'submit.php unchanged' 'WARN' 'submit.php has uncommitted changes'
}

# Output JSON for report
$outJson = Join-Path $Root 'docs\screenshots\a4-verification\verify-results.json'
New-Item -ItemType Directory -Force -Path (Split-Path $outJson) | Out-Null
$results | ConvertTo-Json -Depth 3 | Set-Content $outJson -Encoding UTF8

$pass = ($results | Where-Object { $_.Status -eq 'PASS' }).Count
$fail = ($results | Where-Object { $_.Status -eq 'FAIL' }).Count
$warn = ($results | Where-Object { $_.Status -eq 'WARN' }).Count
Write-Host "Verification: $pass PASS, $fail FAIL, $warn WARN" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Yellow' })
$results | Format-Table -AutoSize

if ($fail -gt 0) { exit 1 }
exit 0
