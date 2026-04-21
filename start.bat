@echo off
setlocal

echo ============================================================
echo  Garden System - Starting...
echo ============================================================
echo.

cd /d "%~dp0"

echo [1/2] Starting Laravel backend (http://127.0.0.1:8000) ...
start "Laravel Backend" cmd /k "cd /d "%~dp0backend" && php artisan serve"

timeout /t 2 /nobreak >nul

echo [2/2] Starting React frontend (http://localhost:5173) ...
start "React Frontend" cmd /k "cd /d "%~dp0frontend" && npm run dev"

echo.
echo ============================================================
echo  Both servers are starting in separate windows.
echo.
echo  Backend  : http://127.0.0.1:8000
echo  Frontend : http://localhost:5173
echo.
echo  Open http://localhost:5173 in your browser.
echo ============================================================
echo.
pause
