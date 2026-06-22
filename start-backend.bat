@echo off
setlocal enabledelayedexpansion
title BACKEND - PHP/Apache Server
color 0E

echo.
echo ========================================
echo   BACKEND - PHP/Apache Server
echo ========================================
echo.

REM Initial check - only once at startup
netstat -an | findstr ":80" >nul
if %errorlevel% == 0 (
    echo [OK] Apache is running on port 80
) else (
    echo [ERROR] Apache is NOT running on port 80
    echo.
    echo ACTION REQUIRED:
    echo    1. Open XAMPP Control Panel
    echo    2. Click "Start" for Apache
    echo    3. Click "Start" for MySQL
    echo.
    pause
    exit /b 1
)

netstat -an | findstr ":3306" >nul
if %errorlevel% == 0 (
    echo [OK] MySQL is running on port 3306
) else (
    echo [WARNING] MySQL is NOT running on port 3306
    echo Please start MySQL in XAMPP Control Panel
)

echo.
echo ========================================
echo   Backend Endpoints
echo ========================================
echo   API Base:    http://localhost/resume_generator/api/
echo   Test DB:     http://localhost/resume_generator/api/test_db.php
echo ========================================
echo.
echo Server is running on port 80
echo.
echo Watching for errors...
echo    (Press Ctrl+C to close)
echo.
echo ========================================
echo.

REM Monitor Apache error log for new errors using PowerShell
set ERROR_LOG=C:\xampp\apache\logs\error.log

REM Use PowerShell to tail the error log file (similar to tail -f)
powershell -Command "$ErrorActionPreference='SilentlyContinue'; Get-Content '%ERROR_LOG%' -Wait -Tail 0 | ForEach-Object { Write-Host $_ }"

