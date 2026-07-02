# Upload register-site root PHP only (no config.php)
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$cfg = & (Join-Path $ProjectRoot 'deploy.local.ps1')
function Send-Ftp($Local, $Remote) {
    if (-not (Test-Path $Local) -or (Get-Item $Local).Length -lt 50) { return }
    $uri = "ftp://$($cfg.FtpServer)$($cfg.FtpRemoteDir.TrimEnd('/'))/$Remote"
    Write-Host "  $Remote"
    $c = New-Object System.Net.WebClient
    $c.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
    try { $c.UploadFile($uri, $Local) } finally { $c.Dispose() }
}
@('index.php','staff-app.php','status.php','submit.php','check-in.php','staff-messages.php','staff-notifications.php','staff-checkin.php','terms.php','privacy.php','manifest.php') | ForEach-Object {
    Send-Ftp (Join-Path $ProjectRoot $_) $_
}
Write-Host 'Root public PHP uploaded' -ForegroundColor Green
