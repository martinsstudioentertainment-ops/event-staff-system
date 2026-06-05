# Force-upload apply login to every likely server path.

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')

$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

$cfg = Get-DeployConfig
$local = Join-Path $ProjectRoot 'apply\admin\admin\login.php'

$targets = @(
    @{ Base = $cfg.FtpApplyRemoteDir; Remote = 'admin/login.php' },
    @{ Base = $cfg.FtpApplyRemoteDir; Remote = 'login.php' },
    @{ Base = '/apply'; Remote = 'admin/login.php' },
    @{ Base = '/apply/admin'; Remote = 'admin/login.php' },
    @{ Base = '/apply/admin'; Remote = 'login.php' }
)

Write-Host 'Uploading login.php to all apply paths...' -ForegroundColor Green
foreach ($t in $targets) {
    if ([string]::IsNullOrWhiteSpace($t.Base)) { continue }
    Write-Host "Target: $($t.Base)/$($t.Remote)" -ForegroundColor DarkGray
    try {
        Send-FtpFile -LocalPath $local -RemoteRelativePath $t.Remote -RemoteBase $t.Base -Deploy $cfg
    } catch {
        Write-Host "  skipped: $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

Write-Host 'Done.' -ForegroundColor Green
