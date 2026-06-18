# Phase A.3 Review Package — generate 8-step screenshots (mobile / tablet / desktop).
$ErrorActionPreference = 'Stop'
$Root = Split-Path $PSScriptRoot -Parent
$OutDir = Join-Path $Root 'docs\screenshots\a3-review'
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
<title>A.3 Step {STEP} - {NAME}</title>
<link rel="stylesheet" href="../_preview-styles.css">
<link rel="stylesheet" href="../../../../assets/css/registration-wizard.css">
</head>
<body class="registration-page--wizard">
<div class="preview-frame preview-frame--{VIEWPORT}">
<header class="staff-public-hero">
<p class="staff-public-hero__eyebrow">PSA security &middot; DSP &amp; Static</p>
<h1 class="staff-public-hero__title">Register for event work</h1>
<p class="staff-public-hero__lead">Free registration portal &mdash; connects you to event opportunities.</p>
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

function Get-WizardShell([int]$Step, [string]$StepName, [int]$Pct, [switch]$FullShell) {
    $trust = ''
    $platform = ''
    $estimate = ''
    if ($FullShell) {
        $estimate = '<p class="reg-wizard__estimate">Estimated completion time: <strong>3-5 minutes</strong></p>'
        $trust = '<ul class="reg-wizard__trust"><li>Free registration</li><li>Mobile friendly</li><li>Secure profile storage</li><li>Connects you to opportunities</li></ul>'
        $platform = '<p class="reg-wizard__platform-note">Olasentra connects people with opportunities. Employment, pay, contracts and working conditions are handled by employers and event organisers.</p>'
    }
    return @"
<div class="reg-wizard">
<div class="reg-wizard__progress-meta"><span class="reg-wizard__step-label">Step $Step of 8</span><span class="reg-wizard__step-name">$StepName</span></div>
<div class="reg-wizard__bar"><span class="reg-wizard__bar-fill" style="width:${Pct}%"></span></div>
$estimate
$trust
$platform
</div>
"@
}

$steps = @{
    1 = @{
        Name = 'Welcome'; Pct = 12; Height = @{ mobile = 920; tablet = 880; desktop = 900 }
        Body = @'
<div class="reg-resume-prompt">
<div class="reg-resume-prompt__card">
<p class="reg-resume-prompt__title">Previous registration draft found</p>
<p class="reg-resume-prompt__text">You started step 4 on 5 Jun, 14:32. Resume where you left off or start a new application.</p>
<div class="reg-resume-prompt__actions">
<button type="button" class="btn btn--primary">Resume Application</button>
<button type="button" class="btn btn--secondary">Start New Application</button>
</div>
</div>
</div>
<div class="form-group form-group--full">
<label class="form-label form-label--required">Your role</label>
<select class="form-select"><option>DSP Security Guard</option><option>Static Security Guard</option></select>
</div>
'@; FullShell = $true; Back = $false; Next = 'Continue'
    }
    2 = @{
        Name = 'Your gigs'; Pct = 25; Height = @{ mobile = 980; tablet = 920; desktop = 900 }
        Body = @'
<h3 class="form-section-title">Shift selection</h3>
<p class="form-hint">Event opportunities listed by organisers &mdash; select the shifts you want to apply for.</p>
<div class="reg-event-cards">
<label class="reg-event-card reg-event-card--selected">
<div class="reg-event-card__body">
<h4 class="reg-event-card__title">Electric Picnic 2026</h4>
<dl class="reg-event-card__meta"><dt>Venue</dt><dd>Stradbally Hall</dd><dt>Date</dt><dd>Sat 5 Sep 2026</dd><dt>County</dt><dd>Laois</dd><dt>Roles</dt><dd>DSP Security</dd></dl>
<span class="reg-event-card__status reg-event-card__status--open">Open for registration</span>
</div><span class="reg-event-card__check"></span>
</label>
<label class="reg-event-card reg-event-card--registered">
<div class="reg-event-card__body">
<h4 class="reg-event-card__title">Longitude 2026</h4>
<dl class="reg-event-card__meta"><dt>Venue</dt><dd>Marlay Park</dd><dt>Date</dt><dd>Sun 5 Jul 2026</dd><dt>County</dt><dd>Dublin</dd></dl>
<span class="reg-event-card__status reg-event-card__status--registered">Already Registered</span>
</div>
</label>
</div>
<p class="shift-picker-summary">1 shift selected</p>
'@; FullShell = $false; Back = $true; Next = 'Continue'
    }
    3 = @{
        Name = 'Email'; Pct = 37; Height = @{ mobile = 1100; tablet = 1050; desktop = 1000 }
        Body = @'
<div class="reg-returning-panel">
<div class="reg-profile-card">
<p class="reg-profile-card__welcome">Welcome Back</p>
<p class="reg-profile-card__lead"><strong>Existing profile found</strong> &mdash; empty fields were filled automatically. Your edits are never overwritten.</p>
<div class="reg-profile-card__grid">
<div class="reg-profile-card__metric"><span class="reg-profile-card__metric-val">92%</span><span class="reg-profile-card__metric-label">Profile complete</span></div>
<div class="reg-profile-card__metric"><span class="reg-profile-card__metric-val">3</span><span class="reg-profile-card__metric-label">Events applied</span></div>
</div>
<div class="reg-profile-card__status-row"><span class="reg-profile-card__status-label">Profile status</span><span class="reg-profile-card__status reg-profile-card__status--complete">Complete</span></div>
<p class="reg-profile-card__compliance"><strong>Compliance:</strong> PSA on file &middot; Profile ready for new opportunities</p>
<div class="reg-profile-card__events-wrap"><p class="reg-profile-card__events-title">Events already applied for</p>
<ul class="reg-profile-card__events"><li>Longitude 2026 &middot; 5 Jul 2026 (Approved)</li></ul></div>
<p class="reg-profile-card__platform">Olasentra connects people with opportunities. Employment, pay, contracts and working conditions are handled by employers and event organisers.</p>
<button type="button" class="btn btn--primary reg-profile-card__cta">Pick new events</button>
</div>
</div>
<div class="form-group form-group--full">
<label class="form-label form-label--required">Email address</label>
<input class="form-input" type="email" value="jane.smith@example.com">
<p class="form-hint">We'll use this to find your existing profile.</p>
</div>
'@; FullShell = $false; Back = $true; Next = 'Continue'
    }
    4 = @{
        Name = 'About you'; Pct = 50; Height = @{ mobile = 1450; tablet = 1350; desktop = 1280 }
        Body = @'
<h3 class="form-section-title">Personal details</h3>
<div class="form-group"><label class="form-label form-label--required">Surname</label><input class="form-input" value="Smith"></div>
<div class="form-group"><label class="form-label form-label--required">First name</label><input class="form-input" value="Jane"></div>
<div class="form-group form-group--full"><label class="form-label form-label--required">Full address</label><input class="form-input" value="12 Main St, Dublin"></div>
<div class="form-group"><label class="form-label form-label--required">Eircode</label><input class="form-input" value="D02 X285"></div>
<div class="form-group"><label class="form-label form-label--required">Date of birth</label><input class="form-input" type="date" value="1990-12-31"><p class="form-hint">Day / month / year (Irish format).</p></div>
<div class="form-group"><label class="form-label form-label--required">Gender</label>
<div class="form-radio-group">
<label class="form-radio"><input type="radio" checked> Male</label>
<label class="form-radio"><input type="radio"> Female</label>
<label class="form-radio"><input type="radio"> Other</label>
<label class="form-radio"><input type="radio"> Prefer not to say</label>
</div></div>
'@; FullShell = $false; Back = $true; Next = 'Continue'
    }
    5 = @{
        Name = 'Contact'; Pct = 62; Height = @{ mobile = 780; tablet = 760; desktop = 740 }
        Body = @'
<h3 class="form-section-title">Contact</h3>
<div class="form-group">
<label class="form-label form-label--required">Mobile number</label>
<div class="phone-input">
<select class="phone-input__country"><option>🇮🇪 +353</option></select>
<input class="phone-input__number" value="87 123 4567">
</div>
</div>
'@; FullShell = $false; Back = $true; Next = 'Continue'
    }
    6 = @{
        Name = 'Payroll'; Pct = 75; Height = @{ mobile = 900; tablet = 860; desktop = 820 }
        Body = @'
<h3 class="form-section-title">Financial &amp; identification</h3>
<p class="form-hint">Bank details are collected for your paying contractor or organiser &mdash; Olasentra does not process wages.</p>
<div class="form-group"><label class="form-label form-label--required">National Insurance / PPS Number</label><input class="form-input" value="1234567AB"></div>
<div class="form-group"><label class="form-label form-label--required">Bank IBAN</label><input class="form-input" value="IE29AIBK93115212345678"><p class="form-hint">Irish example: IE + 2 check digits + account (22 characters).</p></div>
'@; FullShell = $false; Back = $true; Next = 'Continue'
    }
    7 = @{
        Name = 'PSA'; Pct = 87; Height = @{ mobile = 1200; tablet = 1150; desktop = 1080 }
        Body = @'
<h3 class="form-section-title">PSA licence</h3>
<div class="form-group"><label class="form-label form-label--required">PSA licence number</label><input class="form-input" value="EM123456/00"><p class="form-hint">Format: EM123456/00 (shown on your PSA card).</p></div>
<div class="form-group"><label class="form-label form-label--required">PSA expiry date</label><input class="form-input" type="date" value="2028-12-31"></div>
<div class="form-group form-group--full"><label class="form-label form-label--required">PSA card &mdash; front photo</label><input class="form-input" type="file"></div>
<div class="form-group form-group--full"><label class="form-label form-label--required">PSA card &mdash; back photo</label><input class="form-input" type="file"></div>
'@; FullShell = $false; Back = $true; Next = 'Continue'
    }
    8 = @{
        Name = 'Review'; Pct = 100; Height = @{ mobile = 950; tablet = 900; desktop = 880 }
        Body = @'
<p class="reg-wizard__review-placeholder">Summary will appear here in a later update &mdash; confirm your details and submit below.</p>
<div class="form-group form-group--full">
<label class="form-checkbox">
<input type="checkbox" checked>
<span>I agree to the <a href="#">Privacy Notice</a> and confirm I am applying as <strong>PSA-licensed security staff</strong>. This site is a <strong>registration portal only</strong> (not my employer).</span>
</label>
</div>
'@; FullShell = $false; Back = $true; Next = 'Submit registration'
    }
}

$viewports = @(
    @{ Key = 'mobile';  Width = 390  }
    @{ Key = 'tablet';  Width = 768  }
    @{ Key = 'desktop'; Width = 1200 }
)

foreach ($vp in @('mobile', 'tablet', 'desktop')) {
    $dir = Join-Path $OutDir $vp
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
}
New-Item -ItemType Directory -Force -Path $HtmlDir | Out-Null

Write-Host 'Generating A.3 Review Package screenshots...' -ForegroundColor Cyan

foreach ($stepNum in 1..8) {
    $step = $steps[$stepNum]
    $shell = Get-WizardShell -Step $stepNum -StepName $step.Name -Pct $step.Pct -FullShell:($step.FullShell)
    $backBtn = if ($step.Back) { '<button type="button" class="btn btn--secondary reg-wizard__btn-back">Back</button>' } else { '' }

    foreach ($vp in $viewports) {
        $html = $shellHead -replace '\{STEP\}', $stepNum -replace '\{NAME\}', $step.Name -replace '\{VIEWPORT\}', $vp.Key
        $html = $html -replace '\{WIZARD_SHELL\}', $shell
        $html = $html -replace '\{STEP_BODY\}', $step.Body
        $html = $html -replace '\{BACK_BTN\}', $backBtn
        $html = $html -replace '\{NEXT_LABEL\}', $step.Next

        $htmlFile = Join-Path $HtmlDir ("step-{0:D2}-{1}-{2}.html" -f $stepNum, ($step.Name -replace ' ','-').ToLower(), $vp.Key)
        $pngFile  = Join-Path (Join-Path $OutDir $vp.Key) ("step-{0:D2}-{1}.png" -f $stepNum, ($step.Name -replace ' ','-').ToLower())
        [System.IO.File]::WriteAllText($htmlFile, $html, [System.Text.UTF8Encoding]::new($false))

        $h = $step.Height[$vp.Key]
        $uri = [Uri]::new((Resolve-Path $htmlFile)).AbsoluteUri
        $args = @('--headless=new', '--disable-gpu', '--hide-scrollbars', "--window-size=$($vp.Width),$h", "--screenshot=$pngFile", $uri)
        Write-Host "  $($vp.Key)/step-$('{0:D2}' -f $stepNum)-$($step.Name.ToLower() -replace ' ','-').png" -ForegroundColor Green
        Start-Process -FilePath $Edge -ArgumentList $args -Wait -WindowStyle Hidden | Out-Null
    }
}

Write-Host "Done. Output: $OutDir" -ForegroundColor Cyan
