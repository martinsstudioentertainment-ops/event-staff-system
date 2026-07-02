$root = Split-Path -Parent $PSScriptRoot
$skip = @('node_modules', '_tmp-restore', '_recovery', 'ftp-download', '.git', 'vendor')
Get-ChildItem -Path $root -Recurse -Include *.php,*.js,*.css -File -ErrorAction SilentlyContinue |
    Where-Object {
        $p = $_.FullName
        -not ($skip | Where-Object { $p -like "*$_*" })
    } |
    Where-Object { $_.Length -eq 0 } |
    ForEach-Object { $_.FullName.Replace($root + '\', '').Replace('\', '/') } |
    Sort-Object
