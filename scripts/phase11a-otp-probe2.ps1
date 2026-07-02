$base = 'https://register.olasentra.com'
$r = Invoke-WebRequest -Uri "$base/staff-app.php" -UseBasicParsing -SessionVariable sess -TimeoutSec 30
$b = $r.Content
if ($b -is [byte[]]) { $b = [System.Text.Encoding]::UTF8.GetString($b) } else { $b = [string]$b }

Write-Output "Length: $($b.Length)"
Write-Output "Has staff-portal-email-otp: $($b.Contains('staff-portal-email-otp'))"
Write-Output "Has staff-portal-email-send: $($b.Contains('staff-portal-email-send'))"
Write-Output "Has Send verification code: $($b.Contains('Send verification code'))"
Write-Output "Has Welcome to Olasentra: $($b.Contains('Welcome to Olasentra'))"
Write-Output "Has Sign in with Email Code: $($b.Contains('Sign in with Email Code'))"

$idx = $b.IndexOf('staff-portal-email-otp')
if ($idx -ge 0) {
    $start = [Math]::Max(0, $idx - 80)
    $len = [Math]::Min(600, $b.Length - $start)
    Write-Output "`n--- snippet ---"
    Write-Output $b.Substring($start, $len)
}

# CSRF from OTP root specifically
if ($b -match 'id="staff-portal-email-otp"[^>]*data-csrf="([^"]+)"') {
    $csrf = $Matches[1]
    Write-Output "`nOTP root CSRF found"
    $payload = '{"email":"probe@example.com","csrf_token":"' + $csrf + '"}'
    try {
        $resp = Invoke-WebRequest -Uri "$base/api/staff-portal-otp-send.php" -Method POST -WebSession $sess -UseBasicParsing -ContentType 'application/json' -Body $payload -TimeoutSec 20 -ErrorAction Stop
        Write-Output "API HTTP $($resp.StatusCode): $($resp.Content)"
    } catch {
        $code = [int]$_.Exception.Response.StatusCode
        $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
        $body = $reader.ReadToEnd()
        Write-Output "API HTTP ${code}: $body"
    }
} else {
    Write-Output "`nOTP root CSRF pattern NOT matched"
}
