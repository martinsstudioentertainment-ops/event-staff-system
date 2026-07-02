$files = @(
    'includes/staff-app-easy.php',
    'assets/js/staff-portal-email-otp.js',
    'assets/css/staff-app-v3.css'
)
$root = 'g:\event-staff-system'
$base = 'https://register.olasentra.com'
foreach ($rel in $files) {
    $local = (Get-FileHash (Join-Path $root $rel) -Algorithm SHA256).Hash.ToLowerInvariant()
    $url = "$base/$($rel -replace '\\','/')"
    $tmp = Join-Path $env:TEMP ("p11a-" + [Guid]::NewGuid().ToString('n') + '-' + (Split-Path $rel -Leaf))
    Invoke-WebRequest -Uri $url -UseBasicParsing -OutFile $tmp | Out-Null
    $prod = (Get-FileHash $tmp -Algorithm SHA256).Hash.ToLowerInvariant()
    Remove-Item $tmp -Force
    Write-Output "$rel local=$local prod=$prod match=$($local -eq $prod)"
}
