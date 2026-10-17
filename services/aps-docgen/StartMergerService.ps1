# Start the APS document generation service.
#
# HOW TO RUN IT:
#   - Right-click the file -> Run with PowerShell, or
#   - from a PowerShell window in this folder:  .\StartMergerService.ps1
#   - from a cmd window:  powershell -ExecutionPolicy Bypass -File StartMergerService.ps1
#
# cmd CANNOT run a .ps1 directly. Windows associates .ps1 with an editor rather
# than the shell, so typing the filename in cmd (or double-clicking it) OPENS
# the script instead of running it. That is deliberate on Windows' part, not a
# fault in this file.
#
# Reads APS_DOCGEN_KEY from the project's .env so the service and Drupal always
# agree on the shared secret. If they drift, every merge fails with a 401 and
# the Job page only says the document could not be generated.
#
# Leave this window OPEN for as long as you need the feature: closing it stops
# the service. Running it as a real Windows service (NSSM) is still outstanding.
#
# !! ASCII ONLY IN THIS FILE. !!
# Windows PowerShell 5.1 reads a .ps1 with no BOM as ANSI, so a UTF-8 em dash
# (E2 80 94) decodes to three characters ending in 0x94 - a smart closing quote,
# which PowerShell honours as a string delimiter. One em dash in a comment or
# message silently breaks parsing several lines later with a misleading
# "string is missing the terminator". Use plain hyphens.
#
# Verify after editing:
#   [System.IO.File]::ReadAllBytes("StartMergerService.ps1") | ? { $_ -gt 127 }
# Anything returned is a character that will eventually break this script.

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$envFile = Join-Path $here '..\..\.env'
$python = Join-Path $here '.venv\Scripts\python.exe'

if (-not (Test-Path $python)) {
  Write-Host "The virtualenv is missing. Create it first:" -ForegroundColor Red
  Write-Host "  py -3.14 -m venv .venv"
  Write-Host "  .venv\Scripts\python.exe -m pip install -r requirements.txt"
  exit 1
}

if (-not (Test-Path $envFile)) {
  Write-Host "Could not find .env at $envFile" -ForegroundColor Red
  exit 1
}

# Pull the key out of .env. Only this one value is read, and it is never printed.
$key = $null
foreach ($line in Get-Content $envFile) {
  if ($line -match '^\s*APS_DOCGEN_KEY\s*=\s*(.+?)\s*$') {
    $key = $Matches[1].Trim('"').Trim("'")
  }
}

if ([string]::IsNullOrWhiteSpace($key)) {
  Write-Host "APS_DOCGEN_KEY is not set in .env, so the service would refuse every request." -ForegroundColor Red
  exit 1
}

$env:APS_DOCGEN_KEY = $key

Write-Host ""
Write-Host "  APS document generation service" -ForegroundColor Cyan
Write-Host "  listening on http://127.0.0.1:8099"
Write-Host "  health check: http://127.0.0.1:8099/health"
Write-Host ""
Write-Host "  Keep this window open. Press Ctrl+C to stop." -ForegroundColor Yellow
Write-Host ""

# uvicorn imports "app" by name from the current directory, and the working
# directory is NOT the script's folder when this is launched by double-click,
# from a shortcut, or via Start-Process. Without this it fails with
# "Error loading ASGI app. Could not import module app".
Set-Location $here

# 127.0.0.1 only: Drupal and this service share a box, and nothing off-host
# should be able to reach it.
& $python -m uvicorn app:app --host 127.0.0.1 --port 8099
