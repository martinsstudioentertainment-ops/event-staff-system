$Base = 'https://register.olasentra.com'
$html = (Invoke-WebRequest -Uri "$Base/index.php" -UseBasicParsing -TimeoutSec 45).Content
$m = [regex]::Match($html, 'id="event-selection-wrap"[^>]*>')
Write-Output $m.Value
Write-Output ('wizard_on=' + [bool]($html -match 'data-wizard-mode="1"'))
$gate = (Invoke-WebRequest -Uri "$Base/assets/js/registration-shift-gate.js" -UseBasicParsing).Content
Write-Output ('gate_unlock=' + [bool]($gate -match 'wizardWrap\.classList\.remove'))
