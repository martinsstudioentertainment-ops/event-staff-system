# Generate Version 1.0 deployment manifest (main admin + apply admin).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\generate-v10-deploy-manifest.ps1

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$excludeDirNames = @(
    '.git', 'vendor', 'node_modules', '.cursor', '.idea', '.vscode',
    '_recovery-staging', '_tmp-restore', '_phase3_recovery_contents', '_probe-prod',
    'storage\backups', 'storage\logs', 'android'
)

$excludeFilePatterns = @(
    '(?i)^config\.php$',
    '(?i)deploy\.local\.ps1$',
    '(?i)service-account\.json$',
    '(?i)\\config\\database\.php$',
    '(?i)\\config\\eventstaff-database\.php$',
    '(?i)\\config\\sso\.local\.php$',
    '(?i)\.prod$',
    '(?i)error_log$'
)

function Test-DeployPathExcluded([string]$rel) {
    $norm = $rel -replace '/', '\'
    foreach ($d in $excludeDirNames) {
        $dNorm = $d -replace '/', '\'
        if ($norm -eq $dNorm -or $norm.StartsWith("$dNorm\")) { return $true }
    }
    foreach ($pat in $excludeFilePatterns) {
        if ($norm -match $pat) { return $true }
    }
    return $false
}

function Get-DeployFiles([string[]]$roots, [string[]]$extensions) {
    $out = [System.Collections.ArrayList]::new()
    foreach ($root in $roots) {
        $abs = Join-Path $ProjectRoot $root
        if (-not (Test-Path $abs)) { continue }
        Get-ChildItem -Path $abs -Recurse -File -ErrorAction SilentlyContinue | ForEach-Object {
            $rel = $_.FullName.Substring($ProjectRoot.Length).TrimStart('\').Replace('\', '/')
            if (Test-DeployPathExcluded $rel) { return }
            $ext = $_.Extension.ToLowerInvariant()
            if ($extensions -notcontains $ext -and $_.Name -ne '.htaccess') { return }
            if ($_.Length -lt 1) { return }
            $category = ($rel -split '/')[0]
            [void]$out.Add([ordered]@{
                local    = $rel
                remote   = $rel
                category = $category
                bytes    = $_.Length
            })
        }
    }
    return ,$out
}

$mainExt = @('.php', '.js', '.css', '.json', '.htaccess')
$mainRoots = @(
    'admin', 'includes', 'api', 'cron', 'scripts', 'lang', 'assets', 'database',
    'modules', 'features', 'integrations', 'services', 'storage/app'
)
$mainFiles = Get-DeployFiles $mainRoots $mainExt

foreach ($rootFile in @('index.php', 'submit.php', 'status.php', 'check-in.php', 'sign-in.php',
    'staff-portal.php', 'staff-app.php', 'staff-checkin.php', 'staff-notifications.php',
    'staff-messages.php', 'staff-profile.php', 'sw.js', '.htaccess', 'r.php', 'home.php')) {
    $abs = Join-Path $ProjectRoot $rootFile
    if ((Test-Path $abs) -and -not (Test-DeployPathExcluded $rootFile)) {
        [void]$mainFiles.Add([ordered]@{
            local    = $rootFile
            remote   = $rootFile
            category = 'root'
            bytes    = (Get-Item $abs).Length
        })
    }
}

$applyFiles = Get-DeployFiles @('apply/admin') $mainExt

$byCategory = @{}
foreach ($f in $mainFiles) {
    $cat = [string]$f.category
    if (-not $byCategory.ContainsKey($cat)) { $byCategory[$cat] = 0 }
    $byCategory[$cat]++
}

$manifest = [ordered]@{
    version      = '1.0.0'
    label        = 'v1.0-stable'
    build        = '2026070200'
    generated_at = (Get-Date).ToUniversalTime().ToString('o')
    description  = 'Olasentra Version 1.0 certified production deployment manifest'
    counts       = [ordered]@{
        main_admin = $mainFiles.Count
        apply_admin = $applyFiles.Count
        total      = $mainFiles.Count + $applyFiles.Count
        by_category = $byCategory
    }
    modules      = @(
        'Admin', 'Mobile API', 'Attendance', 'Payroll', 'Commission', 'Recruitment',
        'Google Sheets', 'Notifications', 'Master Staff Identity', 'Contractor Sheet',
        'Manual Sign-In', 'Personal Invoice', 'Cron jobs', 'Platform modules'
    )
    main_admin   = @($mainFiles | Sort-Object local)
    apply_admin  = @($applyFiles | Sort-Object local)
}

$outPath = Join-Path $ProjectRoot 'docs\V1.0-DEPLOY-MANIFEST.json'
$json = $manifest | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText($outPath, $json, [System.Text.UTF8Encoding]::new($false))

Write-Host "Wrote $($mainFiles.Count) main + $($applyFiles.Count) apply files -> docs/V1.0-DEPLOY-MANIFEST.json" -ForegroundColor Green
