. "$PSScriptRoot\ftp-common.ps1"
$cfg = Get-DeployConfig
$out = Join-Path (Split-Path -Parent $PSScriptRoot) '_tmp-production-attendance.php'
Download-FtpFile -LocalPath $out -RemoteRelativePath 'admin/attendance.php' -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
Write-Host "Downloaded to $out size=$((Get-Item $out).Length)"
