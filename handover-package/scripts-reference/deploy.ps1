# Push to server — git push + FTP upload (main admin + apply site).
#
#   powershell -ExecutionPolicy Bypass -File .\deploy.ps1
#
# One-time: copy deploy.local.ps1.example → deploy.local.ps1 and set FtpPassword.

$ErrorActionPreference = 'Stop'
$Root = $PSScriptRoot
Set-Location $Root

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Push to server (GitHub + FTP)' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host ''

Write-Host '[0/5] Local pre-deploy backup ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\pre-deploy-backup.ps1')

Write-Host ''
Write-Host '[1/5] Cleanup audit (read-only, no deletions) ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\cleanup-audit.ps1')

Write-Host ''
Write-Host '[2/5] Pushing to GitHub (main) ...' -ForegroundColor Green
git push origin main
if ($LASTEXITCODE -ne 0) {
    throw 'git push failed'
}

Write-Host ''
Write-Host '[3/5] Uploading main admin (admin.olasentra.com) ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\upload-to-server.ps1')

Write-Host ''
Write-Host '[4/5] Uploading apply site (apply.olasentra.com) ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\upload-apply-site.ps1')

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Done — both sites deployed' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Main:  https://admin.olasentra.com/dashboard.php' -ForegroundColor Gray
Write-Host '  Apply: https://apply.olasentra.com/admin/login.php' -ForegroundColor Gray
Write-Host ''
