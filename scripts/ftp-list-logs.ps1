$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
. (Join-Path $Root 'scripts\ftp-common.ps1')
$cfg = Get-DeployConfig

function List-FtpDir {
    param([string]$RemoteDir)
    $uri = "ftp://$($cfg.FtpServer)$($RemoteDir.TrimEnd('/'))/"
    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
    $req.Credentials = New-Object System.Net.NetworkCredential($cfg.FtpUser, $cfg.FtpPassword)
    $req.UsePassive = $true
    $resp = $req.GetResponse()
    $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
    $listing = $reader.ReadToEnd()
    $reader.Close()
    $resp.Close()
    return ($listing -split "`r?`n" | Where-Object { $_.Trim() -ne '' })
}

$paths = @('/', '/logs', '/../logs', '/public_html', '/access-logs')
foreach ($p in $paths) {
    Write-Host "`n=== $p ===" -ForegroundColor Cyan
    try {
        List-FtpDir -RemoteDir $p | Select-Object -First 20
    } catch {
        Write-Host "  (not accessible: $($_.Exception.Message))"
    }
}
