# Fix staff inbox reply (Quill required-field blocks submit).
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$base = $cfg.FtpRemoteDir

$files = @(
    @{ Local = 'assets\js\admin-rich-text.js'; Remote = 'assets/js/admin-rich-text.js' },
    @{ Local = 'admin\staff-inbox-thread.php'; Remote = 'admin/staff-inbox-thread.php' },
    @{ Local = 'includes\components\message-thread.php'; Remote = 'includes/components/message-thread.php' }
)

Write-Host 'Uploading staff inbox reply fix...' -ForegroundColor Green
foreach ($f in $files) {
    $localPath = Join-Path $ProjectRoot $f.Local
    if (-not (Test-Path $localPath)) {
        throw "Missing file: $($f.Local)"
    }
    Send-FtpFile -LocalPath $localPath -RemoteRelativePath $f.Remote -RemoteBase $base -Deploy $cfg
}
Write-Host 'Done.' -ForegroundColor Green
