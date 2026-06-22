@echo off
title Resume Generator - Dev Launcher
color 0A

echo.
echo ========================================
echo   Resume Generator - Dev Environment
echo ========================================
echo.
echo Starting Backend and Frontend servers...
echo Opening separate windows for monitoring...
echo.

REM Check if Apache is running first
netstat -an | findstr ":80" >nul
if %errorlevel% == 0 (
    echo [OK] Backend: Apache detected on port 80
) else (
    echo [WARNING] Backend: Apache not detected on port 80
    echo          Please ensure XAMPP Apache is running!
    echo.
)

REM Check if MySQL is running
netstat -an | findstr ":3306" >nul
if %errorlevel% == 0 (
    echo [OK] Database: MySQL detected on port 3306
) else (
    echo [WARNING] Database: MySQL not detected on port 3306
    echo          Please ensure XAMPP MySQL is running!
    echo.
)

echo.
echo Opening windows...
echo.

REM Start backend monitor in new window (yellow/orange)
start "🔧 BACKEND - PHP/Apache Monitor" cmd /k "color 0E && title BACKEND Monitor && cd /d %~dp0 && call start-backend.bat"

REM Wait a bit for window to open
timeout /t 1 /nobreak >nul

REM Start frontend in new window (cyan/blue)
start "⚡ FRONTEND - Vite Dev Server" cmd /k "color 0B && title FRONTEND Dev Server && cd /d %~dp0 && call start-frontend.bat"

REM Wait a bit
timeout /t 2 /nobreak >nul

echo.
echo ========================================
echo   ✅ Development Servers Starting
echo ========================================
echo.
echo 📍 URLs:
echo    Backend:  http://localhost/resume_generator/api/
echo    Frontend: http://localhost:3001
echo.
echo 📋 Two windows are now open:
echo    🔧 BACKEND  - Check for PHP/Apache errors
echo    ⚡ FRONTEND - Check for Vite/React errors
echo.
echo 💡 Tips:
echo    - Watch both windows for error messages
echo    - Backend errors appear in BACKEND window
echo    - Frontend errors appear in FRONTEND window
echo    - Press Ctrl+C in each window to stop that server
echo.
echo ========================================
echo.
echo This launcher window will close in 5 seconds...
echo (Servers will keep running in their own windows)
echo.
timeout /t 5 /nobreak >nul

