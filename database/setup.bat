@echo off
title Event Staff System - Database Setup
echo.
echo ============================================
echo   Event Staff System - Database Setup
echo ============================================
echo.

set "MYSQL=C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe"
set "SQLFILE=%~dp0database.sql"

if not exist "%MYSQL%" (
    echo ERROR: MySQL not found. Is Laragon installed?
    pause
    exit /b 1
)

echo Checking MySQL connection...
"%MYSQL%" -u root -e "SELECT 1" >nul 2>&1
if errorlevel 1 (
    echo ERROR: Cannot connect to MySQL.
    echo Open Laragon and click "Start All", then run this again.
    pause
    exit /b 1
)

echo Importing database...
powershell -NoProfile -Command "Get-Content '%SQLFILE%' -Raw | & '%MYSQL%' -u root"
if errorlevel 1 (
    echo ERROR: Import failed.
    pause
    exit /b 1
)

echo.
echo SUCCESS! Database ready:
echo   Database: event_staff_system
echo   Tables:   events, staff_registrations
echo   Events:   32 loaded
echo.
echo Open: http://event-staff-system.test/index.php
echo.
pause
