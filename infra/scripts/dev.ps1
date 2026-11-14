<#
.SYNOPSIS
  Task runner for Windows. Mirrors the Makefile targets.
.EXAMPLE
  .\infra\scripts\dev.ps1 setup
  .\infra\scripts\dev.ps1 check
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('setup', 'up', 'down', 'migrate', 'seed-demo', 'check', 'logs', 'backup', 'restore-drill')]
    [string]$Task
)

$ErrorActionPreference = 'Stop'
$Root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$Compose = @('compose', '-f', (Join-Path $Root 'infra\compose.yaml'), '--env-file', (Join-Path $Root '.env'))

function Test-Docker { [bool](Get-Command docker -ErrorAction SilentlyContinue) }

function Assert-Docker {
    if (-not (Test-Docker)) { throw 'Docker is not installed or not on PATH. Install Docker Desktop first.' }
}

function Get-EnvValue([string]$Path, [string]$Name) {
    # Value of NAME=... in an env file with any trailing "  # comment" removed; '' when absent or empty.
    $full = Join-Path $Root $Path
    if (-not (Test-Path $full)) { return '' }
    $line = Select-String -Path $full -Pattern "^$Name=(.*)$" | Select-Object -First 1
    if (-not $line) { return '' }
    ($line.Matches[0].Groups[1].Value -replace '\s+#.*$', '').Trim()
}

function Set-EnvValue([string]$Path, [string]$Name, [string]$Value) {
    $full = Join-Path $Root $Path
    $text = [System.IO.File]::ReadAllText($full)
    $pattern = "(?m)^$Name=[^\r\n]*"
    if ([regex]::IsMatch($text, $pattern)) {
        $text = [regex]::Replace($text, $pattern, { param($m) "$Name=$Value" })
    } else {
        $text = $text.TrimEnd() + "`n$Name=$Value`n"
    }
    [System.IO.File]::WriteAllText($full, $text, (New-Object System.Text.UTF8Encoding($false)))
}

function Invoke-Step([string]$Name, [scriptblock]$Body) {
    Write-Host "==> $Name"
    # Judge a step by its exit code only. Windows PowerShell 5.1 turns every
    # stderr line of a native command into an error record once output is
    # redirected (`2>&1 | Tee-Object`, CI), and with 'Stop' the first progress
    # line Docker prints would abort a step that is still succeeding.
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
        'setup' {
            if (-not (Test-Path '.env')) { Copy-Item '.env.example' '.env'; Write-Host 'Created .env from .env.example' }
            if (-not (Test-Path 'backend\.env')) { Copy-Item '.env.example' 'backend\.env'; Write-Host 'Created backend\.env' }
            Invoke-Step 'pnpm install' { pnpm install --frozen-lockfile }
            Push-Location 'backend'
            try {
                Invoke-Step 'composer install' { composer install --no-interaction }
            } finally { Pop-Location }
            # The api container reads the root .env (compose env_file), which wins over backend\.env; native PHP
            # reads backend\.env. Keep one key in both. Never replace a non-empty key; never print it.
            $key = Get-EnvValue '.env' 'APP_KEY'
            if ($key -eq '') { $key = Get-EnvValue 'backend\.env' 'APP_KEY' }
            if ($key -eq '') {
                $bytes = New-Object byte[] 32
                $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
                try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
                $key = 'base64:' + [Convert]::ToBase64String($bytes)
                Write-Host 'Generated a new APP_KEY'
            }
            foreach ($file in '.env', 'backend\.env') {
                if ((Get-EnvValue $file 'APP_KEY') -eq '') {
                    Set-EnvValue $file 'APP_KEY' $key
                    Write-Host "Set APP_KEY in $file"
                }
            }
            $key = $null
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
            Assert-Docker
            # Synthetic demo city and zone. The seeder refuses to run outside local and testing.
            Invoke-Step 'seed demo data' { docker @Compose exec -T api php artisan db:seed --class=DevelopmentSeeder }
        }
        'backup' {
            Assert-Docker
            # The dump holds personal data. infra/backups/ is git-ignored; keep it that way.
            $dir = Join-Path $Root 'infra\backups'
            New-Item -ItemType Directory -Force $dir | Out-Null
            $file = Join-Path $dir ("food_delivery-{0}.dump" -f (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss'))
            # Copied in, not piped: PowerShell appends a carriage return when piping to a native command.
            Invoke-Step 'copy script in' { docker @Compose cp (Join-Path $Root 'infra\scripts\db-backup.sh') postgres:/tmp/db-backup.sh }
            Invoke-Step 'dump database' { docker @Compose exec -T postgres sh /tmp/db-backup.sh }
            Invoke-Step 'copy backup out' { docker @Compose cp postgres:/tmp/backup.dump $file }
            Invoke-Step 'remove copy in container' { docker @Compose exec -T postgres rm -f /tmp/backup.dump /tmp/db-backup.sh }
            # Recorded now, checked by restore-drill before anything is restored.
            (Get-FileHash -Algorithm SHA256 $file).Hash.ToLower() | Set-Content -Encoding ascii "$file.sha256"
            Write-Host "Backup written to $file (checksum beside it). It is not encrypted: keep it on this machine."
        }
        'restore-drill' {
            Assert-Docker
            # Restores the newest backup into a scratch database, verifies it, removes the scratch database.
            $latest = Get-ChildItem (Join-Path $Root 'infra\backups') -Filter '*.dump' -ErrorAction SilentlyContinue |
                Sort-Object Name | Select-Object -Last 1
            if (-not $latest) { throw 'No backup found in infra\backups. Run the backup task first.' }
            Write-Host "Using $($latest.Name)"
            $sidecar = "$($latest.FullName).sha256"
            if (-not (Test-Path $sidecar)) { throw "No recorded checksum for $($latest.Name). Only backups made by the backup task are restored." }
            $expected = (Get-Content $sidecar -TotalCount 1).Trim()
            if ($expected -notmatch '^[0-9a-f]{64}$') { throw 'The recorded checksum is not a SHA-256 value.' }
            Invoke-Step 'copy backup in' { docker @Compose cp $latest.FullName postgres:/tmp/backup.dump }
            Invoke-Step 'copy script in' { docker @Compose cp (Join-Path $Root 'infra\scripts\db-restore-drill.sh') postgres:/tmp/db-restore-drill.sh }
            Invoke-Step 'restore and verify' { docker @Compose exec -T -e "EXPECTED_SHA256=$expected" postgres sh /tmp/db-restore-drill.sh }
        }
        'logs' {
            Assert-Docker
            docker @Compose logs -f --tail 200
        }
        'check' {
            Invoke-Step 'web lint' { pnpm lint }
            Invoke-Step 'web typecheck' { pnpm typecheck }
            Invoke-Step 'web tests' { pnpm test }
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
