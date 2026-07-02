$ErrorActionPreference = 'Continue'
$apiBase = 'https://register.olasentra.com/api/mobile/v1'
$results = @()

function Probe-Api($path, $method = 'GET', $body = $null) {
  $uri = "$apiBase/$path"
  try {
    $params = @{
      Uri = $uri
      Method = $method
      UseBasicParsing = $true
      TimeoutSec = 20
    }
    if ($body) {
      $params.Body = ($body | ConvertTo-Json)
      $params.ContentType = 'application/json'
    }
    $r = Invoke-WebRequest @params
    $snippet = $r.Content.Substring(0, [Math]::Min(200, $r.Content.Length)) -replace "`n", ' '
    return "$method $path|$($r.StatusCode)|$snippet"
  } catch {
    $code = if ($_.Exception.Response) { [int]$_.Exception.Response.StatusCode } else { 0 }
    $msg = $_.Exception.Message
    return "$method $path|$code|ERROR: $msg"
  }
}

$results += Probe-Api 'config'
$results += Probe-Api 'dashboard' 
$results += Probe-Api 'shifts'
$results += Probe-Api 'notifications'
$results += Probe-Api 'messages'
$results += Probe-Api 'documents'
$results += Probe-Api 'availability'
$results += Probe-Api 'gps/status'
$results += Probe-Api 'events'
$results += Probe-Api 'me'
$results += Probe-Api 'auth/otp/send' 'POST' @{ email = 'audit-test@invalid.example' }
$results += Probe-Api 'auth/google' 'POST' @{ id_token = 'invalid' }
$results += Probe-Api 'auth/otp/verify' 'POST' @{ email = 'test@invalid.example'; code = '000000' }
$results += Probe-Api 'sync/offline' 'POST' @{ actions = @() }
$results += Probe-Api 'me/password' 'POST' @{ current_password = 'x'; new_password = 'y' }
$results += Probe-Api 'shifts/today'
$results += Probe-Api 'checkin' 'POST' @{ registration_id = 1; latitude = 53.3; longitude = -6.2 }

$results | ForEach-Object { Write-Output $_ }
