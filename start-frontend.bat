@echo off
title FRONTEND Dev Server - Vite
color 0B

echo.
echo ========================================
echo   ⚡ FRONTEND - Vite Dev Server
echo ========================================
echo.

cd /d "%~dp0frontendreact"

if not exist "node_modules" (
    echo [📦 INFO] node_modules not found. Installing dependencies...
    echo.
    call npm install
    if errorlevel 1 (
        echo.
        echo [❌ ERROR] Failed to install dependencies
        echo Please check your internet connection and try again.
        echo.
        echo Press any key to exit...
        pause >nul
        exit /b 1
    )
    echo.
    echo [✅ OK] Dependencies installed successfully
    echo.
)

REM Check if port 3001 is in use
netstat -an | findstr ":3001" >nul
if %errorlevel% == 0 (
    echo [⚠️  WARNING] Port 3001 is already in use!
    echo Another process may be using this port.
    echo.
    echo To free the port, run:
    echo   netstat -ano ^| findstr :3001
    echo   taskkill /PID ^<PID^> /F
    echo.
    echo Press any key to exit...
    pause >nul
    exit /b 1
)

echo [🚀 INFO] Starting Vite development server...
echo.
echo ========================================
echo   ⚡ Frontend Server
echo ========================================
echo   URL:    http://localhost:3001
echo   Status: Starting...
echo ========================================
echo.
echo 💡 Tips:
echo    - Watch this window for compilation errors
echo    - Open browser DevTools (F12) for runtime errors
echo    - Hot reload is enabled - changes auto-refresh
echo.
echo ⚠️  Common Errors to Watch:
echo    - Module not found errors
echo    - Syntax errors in JS/JSX files
echo    - Import/export errors
echo    - Port conflicts
echo.
echo Press Ctrl+C to stop the server
echo.
echo ========================================
echo.

call npm run dev

if errorlevel 1 (
    echo.
    echo [❌ ERROR] Vite server failed to start
    echo.
    echo Check the error messages above for details.
    echo Common issues:
    echo   - Missing dependencies (run: npm install)
    echo   - Port 3001 already in use
    echo   - Syntax errors in code
    echo   - Missing files or imports
    echo.
    echo Press any key to exit...
    pause >nul
)

