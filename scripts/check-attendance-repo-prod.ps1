$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$client = New-Object System.Net.WebClient
$client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
$uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath 'includes/attendance-repository.php'
$text = $client.DownloadString($uri)
Write-Output "attendance-repository.php: $($text.Length) chars"
@('checkin_open_time', 'checkin_close_time', 'getEventCheckinWindowInner', 'formatCheckinWindowMessage') | ForEach-Object {
    if ($text -match [regex]::Escape($_)) { "FOUND: $_" } else { "MISSING: $_" }
}
