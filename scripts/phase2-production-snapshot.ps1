# Phase 2 — read-only recursive production snapshot (FTP download only).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\phase2-production-snapshot.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\phase2-production-snapshot.ps1 -MaxFiles 500

param(
    [string]$Label = (Get-Date -Format 'yyyyMMdd-HHmmss'),
    [int]$MaxFiles = 0
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $ProjectRoot

. (Join-Path $PSScriptRoot 'ftp-common.ps1')
$cfg = Get-DeployConfig

$outRoot = Join-Path $ProjectRoot "_recovery-staging\production-snapshot-$Label"
New-Item -ItemType Directory -Force -Path $outRoot | Out-Null

$skipDirPrefixes = @(
    'storage/backups',
    'storage/logs',
    'node_modules',
    'vendor',
    '.git'
)

$allowedExt = @('.php', '.js', '.css')
$manifest = New-Object System.Collections.Generic.List[object]
$errors = New-Object System.Collections.Generic.List[object]
$downloaded = 0

function Test-SkipDir {
    param([string]$RelativeDir)
    $norm = $RelativeDir.Trim('/').Replace('\', '/')
    if ($norm -eq '') { return $false }
    foreach ($prefix in $script:skipDirPrefixes) {
        if ($norm -eq $prefix -or $norm.StartsWith("$prefix/")) {
            return $true
        }
    }
    return $false
}

function Walk-FtpDirectory {
    param([string]$RelativeDir)

    if (Test-SkipDir $RelativeDir) {
        return
    }

    $names = @()
    try {
        $names = @(Get-FtpDirectoryNames -RemoteRelativePath $RelativeDir -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg)
    } catch {
        $script:errors.Add([pscustomobject]@{
            path   = $RelativeDir
            status = 'list_failed'
            detail = $_.Exception.Message
        })
        return
    }

    foreach ($name in $names) {
        if ($script:MaxFiles -gt 0 -and $script:downloaded -ge $script:MaxFiles) {
            return
        }

        $childRel = if ($RelativeDir) { "$RelativeDir/$name" } else { $name }
        $childRel = $childRel.Replace('\', '/')

        if ($name.Contains('.')) {
            $ext = [System.IO.Path]::GetExtension($name).ToLowerInvariant()
            if ($allowedExt -notcontains $ext) {
                continue
            }

            $localOut = Join-Path $outRoot ($childRel -replace '/', '\')
            try {
                $bytes = Download-FtpFile -LocalPath $localOut -RemoteRelativePath $childRel -RemoteBase $cfg.FtpRemoteDir -Deploy $cfg
                $manifest.Add([pscustomobject]@{
                    path   = $childRel
                    bytes  = $bytes
                    status = 'saved'
                })
                $script:downloaded++
                if ($script:downloaded % 50 -eq 0) {
                    Write-Host "  ... $downloaded files" -ForegroundColor DarkGray
                }
            } catch {
                $script:errors.Add([pscustomobject]@{
                    path   = $childRel
                    status = 'download_failed'
                    detail = $_.Exception.Message
                })
            }
            continue
        }

        Walk-FtpDirectory -RelativeDir $childRel
    }
}

Write-Host ''
Write-Host 'Phase 2 — Production snapshot (read-only FTP)' -ForegroundColor Green
Write-Host "  Output: _recovery-staging/production-snapshot-$Label" -ForegroundColor Gray
Write-Host ''

Walk-FtpDirectory -RelativeDir ''

$meta = [ordered]@{
    label          = $Label
    created_at     = (Get-Date).ToString('o')
    phase          = 'PHASE2-20260621-OLASENTRA'
    server         = $cfg.FtpServer
    remote_dir     = $cfg.FtpRemoteDir
    file_count     = $manifest.Count
    error_count    = $errors.Count
    skip_dir_rules = @($skipDirPrefixes)
    extensions     = @($allowedExt)
    files          = @($manifest | Sort-Object path | ForEach-Object { [ordered]@{ path = $_.path; bytes = $_.bytes; status = $_.status } })
    errors         = @($errors | ForEach-Object { [ordered]@{ path = $_.path; status = $_.status; detail = $_.detail } })
}

$meta | ConvertTo-Json -Depth 6 | Set-Content (Join-Path $outRoot 'snapshot.json') -Encoding UTF8
$manifest | Export-Csv -Path (Join-Path $outRoot 'manifest.csv') -NoTypeInformation

Write-Host ''
Write-Host "Snapshot complete: $($manifest.Count) files, $($errors.Count) errors" -ForegroundColor Green
Write-Host "Manifest: _recovery-staging/production-snapshot-$Label/snapshot.json" -ForegroundColor Gray
