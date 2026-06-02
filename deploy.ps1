# Deploy app files to Namecheap: git push + FTP upload.
# Requires deploy.local.ps1 with real FtpPassword.
#
#   powershell -ExecutionPolicy Bypass -File .\deploy.ps1

$ErrorActionPreference = 'Stop'
$Root = $PSScriptRoot
Set-Location $Root

Write-Host 'Pushing to GitHub (main) ...' -ForegroundColor Green
git push origin main
if ($LASTEXITCODE -ne 0) {
    throw 'git push failed'
}

Write-Host ''
& (Join-Path $Root 'scripts\upload-to-server.ps1')
