$Root = Split-Path $PSScriptRoot -Parent
& (Join-Path $Root 'scripts\capture-wizard-screenshots-styled.ps1') `
    -Steps @(1, 8) `
    -OutSubDir 'styled' `
    -BaseOut (Join-Path $Root 'docs\screenshots\a6-verification')
