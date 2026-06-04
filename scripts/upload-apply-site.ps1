# Upload apply.olasentra.com files (all git-tracked PHP under apply/admin/).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\upload-apply-site.ps1

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')

$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$cfg      = Get-DeployConfig
$applyDir = $cfg.FtpApplyRemoteDir

$skipPattern = '(?i)(\.example\.|\.BACK$|backup$|error_log$)'

$tracked = git ls-files 'apply/admin/' 2>$null
if (-not $tracked) {
    Write-Host 'No apply/admin files in git.' -ForegroundColor Yellow
    exit 0
}

$files = @()
foreach ($path in $tracked) {
    if ($path -match $skipPattern) { continue }
    if ($path -notmatch '\.(php|css|js|htaccess)$') { continue }
    $remote = $path -replace '^apply/admin/', '' -replace '\\', '/'
    $local  = $path -replace '/', '\'
    $files += @{ Local = $local; Remote = $remote }
}

if ($files.Count -eq 0) {
    Write-Host 'No apply files to upload.' -ForegroundColor Yellow
    exit 0
}

Write-Host "Apply site -> $($cfg.FtpServer)$applyDir ($($files.Count) files)" -ForegroundColor Green

$dirs = $files | ForEach-Object {
    $parent = Split-Path $_.Remote -Parent
    while ($parent -and $parent -ne '.') {
        $parent
        $parent = Split-Path $parent -Parent
    }
} | Sort-Object -Unique

foreach ($dir in $dirs) {
    Ensure-FtpDirectory -Server $cfg.FtpServer -RemoteDir "$applyDir/$dir" -Deploy $cfg
}

foreach ($f in $files) {
    Send-FtpFile -LocalPath (Join-Path $ProjectRoot $f.Local) `
        -RemoteRelativePath $f.Remote -RemoteBase $applyDir -Deploy $cfg
}

Write-Host ''
Write-Host 'Apply site uploaded.' -ForegroundColor Green
Write-Host '  https://apply.olasentra.com/admin/login.php' -ForegroundColor Gray
Write-Host '  https://apply.olasentra.com/admin/dashboard.php' -ForegroundColor Gray
