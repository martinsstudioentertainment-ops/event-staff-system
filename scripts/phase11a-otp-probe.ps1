$base = 'https://register.olasentra.com'
$r = Invoke-WebRequest -Uri "$base/staff-app.php" -UseBasicParsing -SessionVariable sess -TimeoutSec 30
$b = $r.Content
if ($b -is [byte[]]) { $b = [System.Text.Encoding]::UTF8.GetString($b) } else { $b = [string]$b }

Write-Output "=== OTP markup ==="
Write-Output "staff-portal-email-otp: $($b -like '*id=\"staff-portal-email-otp\"*')"
Write-Output "staff-portal-email-send: $($b -like '*id=\"staff-portal-email-send\"*')"
Write-Output "otp js: $($b -like '*staff-portal-email-otp.js*')"
Write-Output "csrf attr: $(if ($b -match 'id=\"staff-portal-email-otp\"[^>]*data-csrf=\"([^\"]+)\"') { 'present len=' + $Matches[1].Length } else { 'MISSING' })"

Write-Output "`n=== Scripts (guest OTP related) ==="
[regex]::Matches($b, '<script[^>]+src=\"([^\"]+)\"') | ForEach-Object {
    if ($_.Groups[1].Value -match 'staff-app-v3|staff-portal-email-otp|pwa') {
        Write-Output $_.Groups[1].Value
    }
}

Write-Output "`n=== OTP send API (no CSRF) ==="
try {
    Invoke-WebRequest -Uri "$base/api/staff-portal-otp-send.php" -Method POST -WebSession $sess -UseBasicParsing -ContentType 'application/json' -Body '{"email":"probe@example.com"}' -TimeoutSec 20 -ErrorAction Stop
} catch {
    $code = [int]$_.Exception.Response.StatusCode
    $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
    $body = $reader.ReadToEnd()
    Write-Output "HTTP $code"
    Write-Output $body
}

Write-Output "`n=== Extract CSRF and retry ==="
if ($b -match 'data-csrf=\"([^\"]+)\"') {
    $csrf = $Matches[1]
    $payload = @{ email = 'probe@example.com'; csrf_token = $csrf } | ConvertTo-Json
    try {
        Invoke-WebRequest -Uri "$base/api/staff-portal-otp-send.php" -Method POST -WebSession $sess -UseBasicParsing -ContentType 'application/json' -Body $payload -TimeoutSec 20 -ErrorAction Stop | Out-Null
        Write-Output 'unexpected 200'
    } catch {
        $code = [int]$_.Exception.Response.StatusCode
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $body = $reader.ReadToEnd()
        Write-Output "HTTP $code"
        Write-Output $body
    }
}
