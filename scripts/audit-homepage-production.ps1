param([string] $BaseUrl = 'https://admin.olasentra.com')
$ErrorActionPreference = 'Stop'
$homeUrl = "$BaseUrl/home.php"
$html = (Invoke-WebRequest -Uri $homeUrl -UseBasicParsing -TimeoutSec 45).Content
$checks = [ordered]@{}
$checks['http_ok'] = $true
$checks['premium_flag_class'] = [bool]($html -match 'site-page--premium-home')
$checks['home_premium_css'] = [bool]($html -match 'home-premium\.css')
$checks['home_premium_js'] = [bool]($html -match 'home-premium\.js')
$checks['hero_section'] = [bool]($html -match 'hp-hero')
$checks['live_events'] = [bool]($html -match 'hp-events-grid|hp-empty')
$checks['stats_bar'] = [bool]($html -match 'hp-stats-bar')
$checks['data_hp_count'] = ([regex]::Matches($html, 'data-hp-count')).Count
$checks['staff_portal_cta'] = [bool]($html -match 'staff-portal')
$checks['testimonials'] = [bool]($html -match 'hp-testimonial')
$checks['register_cta'] = ([regex]::Matches($html, 'Register|Browse open events|Apply')).Count
$out = Join-Path (Split-Path $PSScriptRoot -Parent) 'storage\reports\phase-b-homepage-audit-snapshot.json'
$payload = @{
    url = $homeUrl
    generated_at = (Get-Date).ToUniversalTime().ToString('o')
    checks = $checks
    title = if ($html -match '<title>([^<]+)</title>') { $Matches[1] } else { '' }
}
$payload | ConvertTo-Json -Depth 4 | Set-Content $out -Encoding UTF8
Write-Output $out
$checks.GetEnumerator() | ForEach-Object { Write-Output ("$($_.Key)=$($_.Value)") }
