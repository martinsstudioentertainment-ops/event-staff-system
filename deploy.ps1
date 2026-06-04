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

Write-Host ''
Write-Host 'Uploading apply.olasentra.com SSO files ...' -ForegroundColor Green
try {
    & (Join-Path $Root 'scripts\upload-apply-sso.ps1')
} catch {
    Write-Host 'Apply upload skipped or failed: ' $_.Exception.Message -ForegroundColor Yellow
    Write-Host 'Fix FtpPassword in deploy.local.ps1, or upload apply/admin/sso.php via cPanel File Manager.' -ForegroundColor Yellow
}
