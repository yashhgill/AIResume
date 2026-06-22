@echo off
title Resume Generator - Development Servers
color 0A

echo.
echo ========================================
echo   Resume Generator - Dev Environment
echo ========================================
echo.
echo Starting Backend and Frontend servers...
echo.

REM Check if Apache is running
netstat -an | findstr ":80" >nul
if %errorlevel% == 0 (
    echo [OK] Backend: Apache running on port 80
) else (
    echo [WARNING] Backend: Apache not running!
    echo          Please start XAMPP Apache first
    echo.
)

echo [INFO] Frontend: Starting Vite dev server...
echo.

cd /d "%~dp0frontendreact"

if not exist "node_modules" (
    echo [INFO] Installing dependencies...
    call npm install
    if errorlevel 1 (
        echo [ERROR] Failed to install dependencies
        pause
        exit /b 1
    )
    echo.
)

echo ========================================
echo   Servers Running:
echo ========================================
echo   Backend:  http://localhost/resume_generator/api/
echo   Frontend: http://localhost:3001
echo ========================================
echo.
echo Press Ctrl+C to stop all servers
echo.

call npm run dev

pause

