. "$PSScriptRoot\ftp-common.ps1"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$cfg = Get-DeployConfig
$files = @(
    'includes/attendance-repository.php',
    'includes/attendance-roster-helpers.php',
    'includes/registration-bib.php',
    'includes/admin-pagination.php'
)
foreach ($rel in $files) {
    $local = Join-Path $ProjectRoot ('_tmp-prod-' + ($rel -replace '[/\\]', '-'))
    try {
        Download-FtpFile -LocalPath $local -RemoteRelativePath $rel -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
        Write-Host "OK $rel -> $local ($((Get-Item $local).Length) bytes)"
    } catch {
        Write-Host "MISSING $rel"
    }
}
