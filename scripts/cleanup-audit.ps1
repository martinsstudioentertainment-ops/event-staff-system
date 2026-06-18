# Read-only cleanup audit before deploy. Never deletes files.
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Host 'cleanup-audit: php not found — skipped' -ForegroundColor Yellow
    exit 0
}

Set-Location $Root
& php .\scripts\cleanup-audit.php --report-only
if ($LASTEXITCODE -ne 0) {
    Write-Host 'cleanup-audit: warning — audit failed (deploy continues)' -ForegroundColor Yellow
    exit 0
}
