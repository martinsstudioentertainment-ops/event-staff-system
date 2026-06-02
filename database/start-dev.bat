@echo off

title Event Staff System - Dev Server (Cursor)

echo.

echo ============================================

echo   Event Staff System - Start Dev Server

echo ============================================

echo.



set "PHP=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"

set "MYSQL=C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe"

set "ROOT=%~dp0.."



if not exist "%PHP%" (

    echo ERROR: PHP not found. Laragon must be installed.

    pause

    exit /b 1

)



echo Checking MySQL...

"%MYSQL%" -u root -e "SELECT 1" >nul 2>&1

if errorlevel 1 (

    echo.

    echo WARNING: MySQL is not running.

    echo   - The site WILL load, but form save will fail until MySQL starts.

    echo   - Fix: Laragon tray icon ^> Start All

    echo.

) else (

    echo MySQL OK.

    echo.

)



echo Starting PHP server...

echo.

echo   Registration:  http://localhost:8080/index.php

echo   Admin login:   http://localhost:8080/admin/login.php

echo   Health check:  http://localhost:8080/api/health.php

echo   DB setup:      http://localhost:8080/database/setup.php

echo.

echo Keep this window open while working. Press Ctrl+C to stop.

echo.



cd /d "%ROOT%"

"%PHP%" -S localhost:8080 -t "%ROOT%"


