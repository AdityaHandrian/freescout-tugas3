@echo off
rem Pembungkus demo.sh untuk PowerShell / CMD. Selalu memakai Git Bash, bukan WSL.
rem Contoh dari root FreeScout:  .\tugas3\demo siap
set "GITBASH=C:\Program Files\Git\bin\bash.exe"
if not exist "%GITBASH%" (
  echo Git Bash tidak ditemukan di "%GITBASH%".
  exit /b 1
)
"%GITBASH%" "%~dp0demo.sh" %*
