@echo off
cd /d "%~dp0"
set "MYSQL=C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe"

if not exist "%MYSQL%" (
    echo ERROR: MySQL not found. Start Laragon first.
    pause
    exit /b 1
)

echo Running all migrations...
for %%F in (
    migrate-phase3.sql
    migrate-phase4.sql
    migrate-phase5-attendance.sql
    migrate-phase5-token.sql
    migrate-phase6-smtp.sql
    migrate-phase7.sql
    migrate-phase8-backfill.sql
    migrate-phase9-unique-registration.sql
    migrate-phase10-theme-preset.sql
) do (
    if exist "%%F" (
        echo   - %%F
        type "%%F" | "%MYSQL%" -u root
    )
)

echo Done.
pause
