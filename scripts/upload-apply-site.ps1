# Upload all apply.olasentra.com PHP files under apply/admin/ (not only git-tracked).

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')

$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$cfg      = Get-DeployConfig
$applyDir = $cfg.FtpApplyRemoteDir
if ([string]::IsNullOrWhiteSpace($applyDir) -or $applyDir -eq '/apply/admin') {
    $applyDir = '/apply'
}

$skipPattern = '(?i)(\\|/)(\.git|error_log|\.BACK|backup|\.zip$|\.example\.|(^|[\\/])front\.php$)'
$skipFiles   = @(
    'config\database.php',
    'config\eventstaff-database.php',
    'config\sso.local.php'
)

$files = Get-ChildItem -Path (Join-Path $ProjectRoot 'apply\admin') -Recurse -File |
    Where-Object {
        $_.FullName -notmatch $skipPattern -and
        ($_.Extension -match '^\.(php|css|js|htaccess)$') -or
        ($_.Extension -eq '.json' -and $_.FullName -match '[\\/]config[\\/]google-service-account\.json$') -or
        ($_.Extension -eq '.php' -and $_.Name -eq 'sheets.local.example.php')
    }

Write-Host "Apply site -> $($cfg.FtpServer)$applyDir ($($files.Count) files)" -ForegroundColor Green

foreach ($file in $files) {
    $relative = $file.FullName.Substring((Join-Path $ProjectRoot 'apply\admin').Length).TrimStart('\').Replace('\', '/')
    $skip = $false
    foreach ($sf in $skipFiles) {
        if ($relative -ieq $sf -or $relative -ieq ($sf -replace '\\', '/')) { $skip = $true; break }
    }
    if ($skip) {
        Write-Host "  skip (credentials): admin/$relative" -ForegroundColor DarkGray
        continue
    }
    $remoteDir = Split-Path $relative -Parent
    if ($remoteDir -and $remoteDir -ne '.') {
        Ensure-FtpDirectoryTree -Server $cfg.FtpServer -RemoteDir "$applyDir/admin/$remoteDir" -Deploy $cfg
    }
    Send-FtpFile -LocalPath $file.FullName -RemoteRelativePath "admin/$relative" -RemoteBase $applyDir -Deploy $cfg
}

Write-Host ''
Write-Host 'Apply site uploaded.' -ForegroundColor Green
Write-Host '  Login:     https://apply.olasentra.com/admin/admin/login.php' -ForegroundColor Gray
Write-Host '  Dashboard: https://apply.olasentra.com/admin/admin/dashboard.php' -ForegroundColor Gray
