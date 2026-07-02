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

Write-Host '[0/6] Deploy safety gate (repository integrity) ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\deploy-safety-gate.ps1')
if ($LASTEXITCODE -ne 0) {
    throw 'Deploy blocked by safety gate. See docs/phase1-deploy-safety-gate.json'
}

Write-Host ''
Write-Host '[1/6] Local pre-deploy backup ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\pre-deploy-backup.ps1')

Write-Host ''
Write-Host '[2/6] Cleanup audit (read-only, no deletions) ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\cleanup-audit.ps1')

Write-Host ''
Write-Host '[3/6] Pushing to GitHub (main) ...' -ForegroundColor Green
git -c "safe.directory=$Root" push origin main
if ($LASTEXITCODE -ne 0) {
    throw 'git push failed'
}

Write-Host ''
Write-Host '[3b/6] Generating Version 1.0 deployment manifest ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\generate-v10-deploy-manifest.ps1')

Write-Host ''
Write-Host '[4/6] Uploading main admin (admin.olasentra.com) ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\upload-from-manifest.ps1')

Write-Host ''
Write-Host '[5/6] Uploading apply site (apply.olasentra.com) ...' -ForegroundColor Green
& (Join-Path $Root 'scripts\upload-apply-site.ps1')

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Done — both sites deployed' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Main:  https://admin.olasentra.com/dashboard.php' -ForegroundColor Gray
Write-Host '  Apply: https://apply.olasentra.com/admin/login.php' -ForegroundColor Gray
Write-Host ''
