param([string]$RelativePath = 'admin/dashboard.php')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig
$uri = Get-FtpUri -Server $cfg.FtpServer -RemoteDir $cfg.FtpRemoteDir -RelativePath $RelativePath
$client = New-Object System.Net.WebClient
$client.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
$content = $client.DownloadString($uri)
$markers = @('dash--ops', 'Action required', 'Communication center', 'dash__ops-row')
foreach ($m in $markers) {
    if ($content -match [regex]::Escape($m)) {
        Write-Output "FOUND: $m"
    } else {
        Write-Output "MISSING: $m"
    }
}
