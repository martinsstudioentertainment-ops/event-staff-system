# Deploy Phase 7 — Design System (DO NOT RUN without approval)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
. (Join-Path $root 'deploy.local.ps1')

$files = @(
    'assets/css/staff-app-v3.css',
    'assets/css/notifications.css',
    'assets/js/staff-app-v3.js',
    'includes/staff-app-v3-pages.php',
    'includes/staff-portal-shift.php',
    'includes/components/notification-list.php',
    'includes/staff-app-easy.php',
    'offline.php'
)

Write-Host "Phase 7 deploy — $($files.Count) files (NOT running FTP until you uncomment and approve)"
Write-Host "Files:"
$files | ForEach-Object { Write-Host "  $_" }
Write-Host ""
Write-Host "To deploy after approval: integrate into deploy.ps1 or run FTP upload for the list above."
