# GPS Attendance Phase 1.5 rollback helper (read-only guidance + optional SQL reminder).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\rollback-gps-phase15.ps1
#
# L0 (instant): Admin → Feature flags → feature_gps_attendance_v2 = OFF
# L1 (schema):  Run database/rollback-phase53-gps-attendance-phase15.sql on production DB
# L2 (code):    Redeploy previous build via deploy.ps1 from known-good commit

Write-Host ''
Write-Host 'GPS Phase 1.5 rollback steps' -ForegroundColor Yellow
Write-Host '1. Set feature_gps_attendance_v2 = 0 (Admin -> Feature flags) — immediate legacy behaviour'
Write-Host '2. Optional: database/rollback-phase53-gps-attendance-phase15.sql (drops last_gps_* columns)'
Write-Host '3. Phase 52 columns remain unless rollback-phase52 is also run'
Write-Host '4. Redeploy prior code if needed: deploy.ps1 from last known-good commit'
Write-Host ''
Write-Host 'Phase 1.5 does NOT modify payroll logic. Flag OFF restores all legacy check-in paths.' -ForegroundColor Green
Write-Host ''
