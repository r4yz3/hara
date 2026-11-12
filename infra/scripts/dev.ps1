<#
.SYNOPSIS
  Task runner for Windows. Mirrors the Makefile targets.
.EXAMPLE
  .\infra\scripts\dev.ps1 setup
  .\infra\scripts\dev.ps1 check
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('setup', 'up', 'down', 'migrate', 'seed-demo', 'check', 'logs')]
    [string]$Task
)

$ErrorActionPreference = 'Stop'
$Root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$Compose = @('compose', '-f', (Join-Path $Root 'infra\compose.yaml'), '--env-file', (Join-Path $Root '.env'))

function Test-Docker { [bool](Get-Command docker -ErrorAction SilentlyContinue) }

function Assert-Docker {
    if (-not (Test-Docker)) { throw 'Docker is not installed or not on PATH. Install Docker Desktop first.' }
}

function Invoke-Step([string]$Name, [scriptblock]$Body) {
    Write-Host "==> $Name"
    & $Body
    if ($LASTEXITCODE -ne 0) { throw "FAILED: $Name (exit $LASTEXITCODE)" }
}

Push-Location $Root
try {
    switch ($Task) {
        'setup' {
            if (-not (Test-Path '.env')) { Copy-Item '.env.example' '.env'; Write-Host 'Created .env from .env.example' }
            if (-not (Test-Path 'backend\.env')) { Copy-Item '.env.example' 'backend\.env'; Write-Host 'Created backend\.env' }
            Invoke-Step 'pnpm install' { pnpm install --frozen-lockfile }
            Push-Location 'backend'
            try {
                Invoke-Step 'composer install' { composer install --no-interaction }
                if (-not (Select-String -Path '.env' -Pattern '^APP_KEY=base64:' -Quiet)) {
                    Invoke-Step 'generate APP_KEY' { php artisan key:generate --ansi }
                }
            } finally { Pop-Location }
            Push-Location 'apps\rider-flutter'
            try { Invoke-Step 'flutter pub get' { flutter pub get } } finally { Pop-Location }
        }
        'up' {
            Assert-Docker
            Invoke-Step 'start stack' { docker @Compose up -d --build --wait }
        }
        'down' {
            Assert-Docker
            # Stops containers and keeps data volumes. Never pass -v here.
            Invoke-Step 'stop stack' { docker @Compose down }
        }
        'migrate' {
            Assert-Docker
            Invoke-Step 'migrate' { docker @Compose exec api php artisan migrate }
        }
        'seed-demo' {
            throw 'seed-demo is not available yet: the gated DevelopmentSeeder is backlog item D-05 (Sprint 3).'
        }
        'logs' {
            Assert-Docker
            docker @Compose logs -f --tail 200
        }
        'check' {
            Invoke-Step 'web lint' { pnpm lint }
            Invoke-Step 'web typecheck' { pnpm typecheck }
            Invoke-Step 'web build' { pnpm build }

            if (Test-Docker) {
                Invoke-Step 'backend format' { docker @Compose exec -T api vendor/bin/pint --test }
                Invoke-Step 'backend static analysis' { docker @Compose exec -T api vendor/bin/phpstan analyse --no-progress --memory-limit=1G }
                Invoke-Step 'backend tests' { docker @Compose exec -T api php artisan test }
            } else {
                Write-Warning 'Docker not found: running backend checks with native PHP. Database-backed tests cannot run this way.'
                Push-Location 'backend'
                try {
                    Invoke-Step 'backend format' { php vendor/bin/pint --test }
                    Invoke-Step 'backend static analysis' { php vendor/bin/phpstan analyse --no-progress --memory-limit=1G }
                    Invoke-Step 'backend tests' { php artisan test }
                } finally { Pop-Location }
            }

            Push-Location 'apps\rider-flutter'
            try {
                Invoke-Step 'flutter analyze' { flutter analyze }
                Invoke-Step 'flutter test' { flutter test }
            } finally { Pop-Location }

            Write-Host 'All checks passed.'
        }
    }
} finally {
    Pop-Location
}
