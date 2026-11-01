# Apply docroot — replace stale duplicate PHP at /apply/ (pre-admin folder migration).
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'ftp-common.ps1')

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$cfg = Get-DeployConfig
$applyDir = $cfg.FtpApplyRemoteDir
if ([string]::IsNullOrWhiteSpace($applyDir) -or $applyDir -eq '/apply/admin') {
    $applyDir = '/apply'
}

$root = Join-Path $ProjectRoot 'apply\legacy-root'
$files = @(
    'index.php',
    'update-details.php',
    'update-profile.php',
    'import-main-staff.php',
    'staff-list.php'
)

Write-Host "Apply docroot retire -> $($cfg.FtpServer)$applyDir" -ForegroundColor Cyan
foreach ($name in $files) {
    $local = Join-Path $root $name
    if (-not (Test-Path $local)) { throw "Missing $local" }
    Send-FtpFile -LocalPath $local -RemoteRelativePath $name -RemoteBase $applyDir -Deploy $cfg
}

$htaccess = @'
# Apply docroot — legacy public loops retired; use register.olasentra.com
Options -Indexes
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^update-details\.php$ https://register.olasentra.com/staff-portal.php [L,R=301]
    RewriteRule ^update-profile\.php$ https://register.olasentra.com/staff-portal.php [L,R=301]
    RewriteRule ^index\.php$ https://register.olasentra.com/index.php [L,R=301]
    RewriteRule ^import-main-staff\.php$ https://register.olasentra.com/index.php [L,R=301]
    RewriteRule ^(joke-generator|test)\.php$ - [R=410,L]
</IfModule>
'@
$tmp = Join-Path $env:TEMP 'apply-root-htaccess.txt'
Set-Content -Path $tmp -Value $htaccess -Encoding UTF8
Send-FtpFile -LocalPath $tmp -RemoteRelativePath '.htaccess' -RemoteBase $applyDir -Deploy $cfg
Remove-Item $tmp -Force -ErrorAction SilentlyContinue

Write-Host 'Done — legacy apply docroot loops retired.' -ForegroundColor Green
