. "$PSScriptRoot\ftp-common.ps1"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$cfg = Get-DeployConfig
$files = @(
    'admin\attendance.php',
    'includes\attendance-repository.php',
    'includes\attendance-roster-helpers.php',
    'includes\registration-bib.php',
    'includes\admin-pagination.php',
    'assets\js\attendance-live.js'
)
foreach ($rel in $files) {
    $local = Join-Path $ProjectRoot $rel
    $remote = ($rel -replace '\\', '/')
    Send-FtpFile -LocalPath $local -RemoteRelativePath $remote -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
}
Write-Host 'Attendance roster fix deployed'
