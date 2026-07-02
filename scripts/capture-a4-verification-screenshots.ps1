# DEPRECATED: Use capture-wizard-screenshots-styled.ps1 instead (HTTP + full CSS).
# Phase A.4 Verification — screenshots for steps 5-8 (mobile / tablet / desktop).
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$OutDir = Join-Path $Root 'docs\screenshots\a4-verification'
$HtmlDir = Join-Path $OutDir 'html'
$Edge = @(
    "${env:ProgramFiles}\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $Edge) { throw 'Microsoft Edge required for screenshot capture.' }

$shellHead = @'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>A.4 Step {STEP} - {NAME}</title>
<link rel="stylesheet" href="_preview-styles.css">
<link rel="stylesheet" href="../../../assets/css/registration-wizard.css">
</head>
<body class="registration-page--wizard staff-public-shell staff-public-shell--event-ops">
<div class="preview-frame preview-frame--{VIEWPORT}">
<header class="staff-public-hero">
<p class="staff-public-hero__eyebrow">PSA security | DSP &amp; Static</p>
<h1 class="staff-public-hero__title">Register for event work</h1>
</header>
<section class="staff-public-card">
{WIZARD_SHELL}
{STEP_BODY}
<nav class="reg-wizard__nav">
{BACK_BTN}
<button type="button" class="btn btn--primary">{NEXT_LABEL}</button>
</nav>
</section>
</div>
</body>
</html>
'@

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
# Copy shared preview styles
$srcStyles = Join-Path $Root 'docs\screenshots\a3-review\_preview-styles.css'
$dstStyles = Join-Path $OutDir '_preview-styles.css'
if (Test-Path $srcStyles) {
    Copy-Item $srcStyles $dstStyles -Force
}

function Get-WizardShell([int]$Step, [string]$StepName, [int]$Pct) {
    return @"
<div class="reg-wizard">
<div class="reg-wizard__progress-meta"><span class="reg-wizard__step-label">Step $Step of 8</span><span class="reg-wizard__step-name">$StepName</span></div>
<div class="reg-wizard__bar" role="progressbar" aria-valuemin="1" aria-valuemax="8" aria-valuenow="$Step"><span class="reg-wizard__bar-fill" style="width:${Pct}%"></span></div>
</div>
"@
}

$reviewSummary = @'
<div class="reg-review-summary">
<div class="reg-review-summary__intro">
<p class="reg-review-summary__title">Review your application</p>
<p class="reg-review-summary__lead">Check everything below before you submit. Olasentra is a registration and opportunity platform only - not your employer, payroll provider, or contracting party.</p>
</div>
<section class="reg-review-summary__section"><h4 class="reg-review-summary__heading">Role</h4><dl class="reg-review-summary__list"><dt>Applying as</dt><dd>DSP Security Guard</dd></dl></section>
<section class="reg-review-summary__section"><h4 class="reg-review-summary__heading">Contact</h4><dl class="reg-review-summary__list"><dt>Email</dt><dd>jane.smith@example.com</dd><dt>Mobile</dt><dd>+353871234567</dd></dl></section>
<section class="reg-review-summary__section"><h4 class="reg-review-summary__heading">Personal details</h4><dl class="reg-review-summary__list"><dt>Name</dt><dd>Jane Smith</dd><dt>Address</dt><dd>12 Main St, Dublin</dd><dt>Eircode</dt><dd>D02 X285</dd></dl></section>
<section class="reg-review-summary__section"><h4 class="reg-review-summary__heading">Payroll (for contractor / organiser)</h4><dl class="reg-review-summary__list"><dt>NI / PPS</dt><dd>***AB</dd><dt>Bank IBAN</dt><dd>****5678</dd></dl></section>
<section class="reg-review-summary__section"><h4 class="reg-review-summary__heading">PSA compliance</h4><dl class="reg-review-summary__list"><dt>Licence number</dt><dd>EM123456/00</dd><dt>Front photo</dt><dd>Attached</dd><dt>Back photo</dt><dd>Attached</dd></dl></section>
<section class="reg-review-summary__section"><h4 class="reg-review-summary__heading">Selected opportunities</h4><dl class="reg-review-summary__list"><dt>Shift</dt><dd>Electric Picnic 2026</dd></dl></section>
</div>
'@

$steps = @{
    5 = @{
        Name = 'Contact'; Pct = 62; Height = @{ mobile = 820; tablet = 720; desktop = 700 }
        Body = @'
<h3 class="form-section-title">Contact</h3>
<div class="reg-wizard__contact-panel" role="note">
<p class="reg-wizard__contact-lead">Your mobile number lets organisers contact you about shift confirmations, roster changes, and event-day check-in.</p>
<ul class="reg-wizard__contact-points">
<li>Used only for work you apply for</li>
<li>Not sold to third parties</li>
<li>Update anytime in your staff profile</li>
</ul>
</div>
<div class="form-group form-group--full reg-wizard__contact-field">
<label class="form-label form-label--required">Mobile number</label>
<div class="phone-input">
<select class="phone-input__country"><option>IE +353</option></select>
<input class="phone-input__number" value="87 123 4567">
</div>
</div>
'@; Next = 'Continue'
    }
    6 = @{
        Name = 'Payroll'; Pct = 75; Height = @{ mobile = 980; tablet = 900; desktop = 860 }
        Body = @'
<h3 class="form-section-title">Financial &amp; identification</h3>
<div class="reg-wizard__payroll-notice" role="note">
<p><strong>Registration platform only.</strong> Olasentra connects you with event opportunities. We are <strong>not</strong> your employer, payroll provider, or contracting party. Bank and tax details you provide are passed to the paying contractor or event organiser for approved work only.</p>
</div>
<div class="form-group"><label class="form-label form-label--required">National Insurance / PPS Number</label><input class="form-input" id="pps_number" value="1234567AB"></div>
<div class="form-group"><label class="form-label form-label--required">Bank IBAN</label><input class="form-input" id="bank_iban" value="IE29AIBK93115212345678"><p class="form-hint">Irish example: IE + 2 check digits + account (22 characters).</p></div>
'@; Next = 'Continue'
    }
    7 = @{
        Name = 'PSA'; Pct = 87; Height = @{ mobile = 1280; tablet = 1180; desktop = 1100 }
        Body = @'
<h3 class="form-section-title">PSA licence</h3>
<div class="form-group"><label class="form-label form-label--required">PSA licence number</label><input class="form-input" value="EM123456/00"></div>
<div class="form-group"><label class="form-label form-label--required">PSA expiry date</label><input class="form-input" type="date" value="2028-12-31"></div>
<div class="form-group form-group--full">
<label class="form-label form-label--required">PSA card, front photo</label>
<div class="reg-psa-upload reg-psa-upload--ready">
<p class="reg-psa-upload__hint">Photograph the front of your PSA card. On mobile, your camera opens automatically.</p>
<input class="form-input form-input--file" type="file">
<p class="reg-psa-upload__status">Selected: psa-front.jpg</p>
</div>
</div>
<div class="form-group form-group--full">
<label class="form-label form-label--required">PSA card, back photo</label>
<div class="reg-psa-upload">
<p class="reg-psa-upload__hint">Photograph the back of your PSA card. On mobile, your camera opens automatically.</p>
<input class="form-input form-input--file" type="file">
<p class="reg-psa-upload__status">No photo selected</p>
</div>
</div>
'@; Next = 'Continue'
    }
    8 = @{
        Name = 'Review'; Pct = 100; Height = @{ mobile = 1200; tablet = 1150; desktop = 1100 }
        Body = (@'
{REVIEW}
<div class="form-group form-group--full">
<label class="form-checkbox">
<input type="checkbox" checked>
<span>I agree to the Privacy Notice and confirm I am applying as PSA-licensed security staff. This site is a registration portal only (not my employer).</span>
</label>
</div>
'@ -replace '\{REVIEW\}', $reviewSummary); Next = 'Submit registration'
    }
}

$viewports = @(
    @{ Key = 'mobile';  Width = 390  }
    @{ Key = 'tablet';  Width = 768  }
    @{ Key = 'desktop'; Width = 1200 }
)

foreach ($vp in @('mobile', 'tablet', 'desktop')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $OutDir $vp) | Out-Null
}
New-Item -ItemType Directory -Force -Path $HtmlDir | Out-Null

Write-Host 'Capturing A.4 verification screenshots...' -ForegroundColor Cyan

foreach ($stepNum in 5..8) {
    $step = $steps[$stepNum]
    $shell = Get-WizardShell -Step $stepNum -StepName $step.Name -Pct $step.Pct
    $backBtn = '<button type="button" class="btn btn--secondary reg-wizard__btn-back">Back</button>'

    foreach ($vp in $viewports) {
        $html = $shellHead -replace '\{STEP\}', $stepNum -replace '\{NAME\}', $step.Name -replace '\{VIEWPORT\}', $vp.Key
        $html = $html -replace '\{WIZARD_SHELL\}', $shell
        $html = $html -replace '\{STEP_BODY\}', $step.Body
        $html = $html -replace '\{BACK_BTN\}', $backBtn
        $html = $html -replace '\{NEXT_LABEL\}', $step.Next

        $slug = "step-{0:D2}-{1}" -f $stepNum, ($step.Name.ToLower())
        $htmlFile = Join-Path $HtmlDir ("{0}-{1}.html" -f $slug, $vp.Key)
        $pngFile  = Join-Path (Join-Path $OutDir $vp.Key) ("{0}.png" -f $slug)
        [System.IO.File]::WriteAllText($htmlFile, $html, [System.Text.UTF8Encoding]::new($false))

        $h = $step.Height[$vp.Key]
        $uri = [Uri]::new((Resolve-Path $htmlFile)).AbsoluteUri
        $args = @('--headless=new', '--disable-gpu', '--hide-scrollbars', "--window-size=$($vp.Width),$h", "--screenshot=$pngFile", $uri)
        Write-Host "  $($vp.Key)/$slug.png" -ForegroundColor Green
        Start-Process -FilePath $Edge -ArgumentList $args -Wait -WindowStyle Hidden | Out-Null
    }
}

Write-Host "Done: $OutDir" -ForegroundColor Cyan
