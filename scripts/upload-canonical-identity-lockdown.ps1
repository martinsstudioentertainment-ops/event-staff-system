. "$PSScriptRoot\ftp-common.ps1"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$cfg = Get-DeployConfig
$files = @(
    'includes\platform\canonical-identity.php',
    'includes\validation.php',
    'includes\staff-repository.php',
    'includes\staff-allocation.php',
    'includes\platform\sidebar-ops.php',
    'includes\mobile\services\MobileEmailOtpAuthService.php',
    'cron\canonical-identity-nightly.php',
    'cron\canonical-identity-e2e-verify.php',
    'admin\staff-identity-manager.php',
    'admin\canonical-identity-dashboard.php',
    'includes\platform\master-staff-identity-ui.php',
    'scripts\canonical-identity-regression-test.php',
    'scripts\deploy-safety-gate.ps1',
    'scripts\deploy-critical-files.json',
    'docs\CANONICAL-IDENTITY-BASELINE.md',
    'apply\admin\includes\google-sheets-sync.php'
)
foreach ($rel in $files) {
    $local = Join-Path $ProjectRoot $rel
    if (-not (Test-Path $local)) { Write-Host "SKIP missing $rel"; continue }
    $remote = ($rel -replace '\\', '/')
    $base = if ($remote.StartsWith('apply/')) { $cfg.FtpApplyRemoteDir } else { $cfg.FtpRemoteDir }
    $remotePath = if ($remote.StartsWith('apply/admin/')) { $remote.Substring('apply/admin/'.Length) } else { $remote }
    Send-FtpFile -LocalPath $local -RemoteRelativePath $remotePath -RemoteBase $base -Deploy $cfg
}
Write-Host 'Canonical identity lockdown deployed'
