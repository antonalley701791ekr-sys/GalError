@echo off
setlocal enabledelayedexpansion

echo ==========================================
echo  Git pre-push safety check
echo ==========================================

where git >nul 2>nul
if errorlevel 1 (
  echo [ERROR] Git not found in PATH.
  exit /b 1
)

echo.
echo [1/5] Repository status
for /f "delims=" %%i in ('git rev-parse --is-inside-work-tree 2^>nul') do set INSIDE=%%i
if /I not "%INSIDE%"=="true" (
  echo [ERROR] Current directory is not a Git repository.
  exit /b 1
)
git status --short

echo.
echo [2/5] Staged files
git diff --cached --name-only

echo.
echo [3/5] Scan staged diff for sensitive keywords
set FOUND_SECRET=0
for /f "delims=" %%i in ('git diff --cached ^| findstr /I "DB_PASS DB_USER API_KEY SECRET TOKEN SMTP_PASS RESEND_API_KEY password Authorization Bearer"') do (
  if !FOUND_SECRET! EQU 0 echo [WARN] Sensitive keyword(s) found in staged diff:
  set FOUND_SECRET=1
  echo    %%i
)
if %FOUND_SECRET% EQU 0 (
  echo [OK] No sensitive keywords found in staged diff.
)

echo.
echo [4/5] Check tracked sensitive files
set FOUND_TRACKED=0
for /f "delims=" %%i in ('git ls-files ^| findstr /I "includes/config.php config.php .env .user.ini"') do (
  if !FOUND_TRACKED! EQU 0 echo [WARN] Sensitive/local files are tracked:
  set FOUND_TRACKED=1
  echo    %%i
)
if %FOUND_TRACKED% EQU 0 (
  echo [OK] No sensitive/local files are tracked.
)

echo.
echo [5/5] Verify .user.ini ignore rule
git check-ignore -v .user.ini >nul 2>nul
if errorlevel 1 (
  echo [WARN] .user.ini is NOT ignored (or file not present).
) else (
  for /f "delims=" %%i in ('git check-ignore -v .user.ini') do echo [OK] %%i
)

echo.
if %FOUND_SECRET% EQU 0 if %FOUND_TRACKED% EQU 0 (
  echo ==========================================
  echo  RESULT: PASS (safe to push)
  echo ==========================================
  exit /b 0
)

echo ==========================================
echo  RESULT: ATTENTION REQUIRED
if %FOUND_SECRET% EQU 1 echo  - Sensitive keywords detected in staged diff.
if %FOUND_TRACKED% EQU 1 echo  - Sensitive/local files are tracked.
echo ==========================================
echo Suggested quick fixes:
echo   git restore --staged ^<file^>
echo   git rm --cached ^<file^>
echo.
exit /b 2
