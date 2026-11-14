# Runs infra/staging/tests/restore-drill-faults.sh through Git Bash (backlog E-03).
# Needs Docker Desktop running and the postgis image from the dev stack present.
# Usage: .\infra\staging\tests\restore-drill-faults.ps1 [-Evidence <path>]
param([string]$Evidence = '')
$ErrorActionPreference = 'Stop'
$script = Join-Path $PSScriptRoot 'restore-drill-faults.sh'
$bash = Join-Path ${env:ProgramFiles} 'Git\bin\bash.exe'
if (-not (Test-Path $bash)) { $bash = (Get-Command bash -ErrorAction Stop).Source }
$env:MSYS_NO_PATHCONV = '1'
if ($Evidence) {
    & $bash $script 2>&1 | Tee-Object -FilePath $Evidence
} else {
    & $bash $script
}
exit $LASTEXITCODE
