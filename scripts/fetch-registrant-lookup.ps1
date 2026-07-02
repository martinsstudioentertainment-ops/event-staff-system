param([string]$Email = 'e2e-wizard-20260606164932@olasentra-e2e.test')
$uri = 'https://register.olasentra.com/api/registrant-lookup.php?email=' + [uri]::EscapeDataString($Email)
(Invoke-RestMethod -Uri $uri -TimeoutSec 30) | ConvertTo-Json -Depth 6
