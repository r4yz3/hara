<#
.SYNOPSIS
  Workstation helpers for the staging deploy files (backlog E-07). The server
  side is bash and docker compose; see docs/operations/staging-setup.md.
.DESCRIPTION
  config  Validates infra\staging\compose.yaml with `docker compose config`.
          Uses infra\staging\.env if it exists; otherwise a temporary copy of
          .env.example (removed afterwards). Prints nothing from the file, so no
          secret reaches the terminal.
  build   Builds the five release images (api, web, customer, merchant, admin)
          from the current working tree and tags them with -Release. Nothing is
          pushed. NEXT_PUBLIC_* values are public and come from -ApiUrl.
  ranges  Regenerates infra\staging\nginx\conf.d\cloudflare.conf (needs bash,
          e.g. Git Bash or WSL, and network access).
.EXAMPLE
  .\infra\scripts\staging.ps1 config
  .\infra\scripts\staging.ps1 build -Release abc1234
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('config', 'build', 'ranges')]
    [string]$Task,
    # Image tag, e.g. the short commit id. Letters, digits, dot, dash, underscore.
    [ValidatePattern('^[A-Za-z0-9._-]{1,64}$')]
    [string]$Release,
    # Public https origin of the API, written into the web bundles at build time.
    [ValidatePattern('^https://[A-Za-z0-9.-]+$')]
    [string]$ApiUrl = 'https://staging-api.haradelivery.com'
)

$ErrorActionPreference = 'Stop'
$Root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$Dir = Join-Path $Root 'infra\staging'
$EnvFile = Join-Path $Dir '.env'

function Invoke-Step([string]$Name, [scriptblock]$Body) {
    Write-Host "==> $Name"
    # Exit code decides; see the note in dev.ps1.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $Body 2>&1 | ForEach-Object {
            if ($_ -is [System.Management.Automation.ErrorRecord]) { Write-Host $_.Exception.Message } else { $_ }
        }
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($code -ne 0) { throw "FAILED: $Name (exit $code)" }
}

Push-Location $Root
try {
    switch ($Task) {
        'config' {
            $temporary = $false
            if (-not (Test-Path $EnvFile)) {
                Copy-Item (Join-Path $Dir '.env.example') $EnvFile
                $temporary = $true
            }
            try {
                $env:RELEASE = if ($Release) { $Release } else { 'validate' }
                Invoke-Step 'docker compose config (quiet)' { docker compose -f (Join-Path $Dir 'compose.yaml') --project-directory $Dir config --quiet }
                Write-Host 'compose.yaml is valid.'
            } finally {
                if ($temporary) { Remove-Item $EnvFile -Force }
            }
        }
        'build' {
            if (-not $Release) { throw 'Pass -Release <tag>.' }
            Invoke-Step "build api:$Release" { docker build -f infra/docker/prod/api.Dockerfile --target api -t "food-delivery-api:$Release" . }
            Invoke-Step "build web:$Release" { docker build -f infra/docker/prod/api.Dockerfile --target web -t "food-delivery-web:$Release" . }
            foreach ($app in 'customer', 'merchant', 'admin') {
                Invoke-Step "build $app-web:$Release" {
                    docker build -f infra/docker/prod/web.Dockerfile `
                        --build-arg "APP=$app-web" `
                        --build-arg "NEXT_PUBLIC_API_URL=$ApiUrl" `
                        -t "food-delivery-$app-web:$Release" .
                }
            }
        }
        'ranges' {
            $bash = Get-Command bash -ErrorAction SilentlyContinue
            if (-not $bash) { throw 'bash not found (install Git for Windows or WSL).' }
            Invoke-Step 'regenerate cloudflare.conf' { bash 'infra/staging/update-cloudflare-ranges.sh' }
        }
    }
} finally {
    Pop-Location
}
