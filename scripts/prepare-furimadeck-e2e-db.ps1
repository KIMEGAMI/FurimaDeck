$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$databaseSuffix = $env:FURIMADECK_E2E_DB_SUFFIX
if (-not $databaseSuffix -or $databaseSuffix -notmatch '^[a-zA-Z0-9-]{1,64}$') {
    throw 'FURIMADECK_E2E_DB_SUFFIX must contain only letters, numbers, or hyphens.'
}
$databaseName = "furimadeck-e2e-$databaseSuffix.sqlite"
$dbPath = Join-Path $projectRoot "storage/framework/testing/$databaseName"
$storagePath = Join-Path $projectRoot "storage/framework/testing/furimadeck-e2e-storage-$databaseSuffix"
if ((Test-Path -LiteralPath $dbPath) -or (Test-Path -LiteralPath $storagePath)) {
    throw 'Refusing to reuse an existing FurimaDeck E2E database or storage directory. Start a new run for a fresh isolated path.'
}
$environmentNames = @('DB_CONNECTION', 'FURIMADECK_DB_DRIVER', 'FURIMADECK_DB_DATABASE', 'FURIMADECK_DB_URL', 'SESSION_DRIVER', 'LARAVEL_STORAGE_PATH')
$previousEnvironment = @{}
foreach ($name in $environmentNames) {
    $previousEnvironment[$name] = @{
        Exists = Test-Path "Env:$name"
        Value = [Environment]::GetEnvironmentVariable($name, 'Process')
    }
}
$dbDir = Split-Path -Parent $dbPath
New-Item -ItemType Directory -Force -Path $dbDir | Out-Null
New-Item -ItemType Directory -Path $storagePath | Out-Null
foreach ($directory in @('framework/views', 'framework/cache/data', 'framework/sessions', 'logs', 'app/private', 'app/public')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $storagePath $directory) | Out-Null
}
$databaseFile = [System.IO.File]::Open($dbPath, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
$databaseFile.Dispose()
$env:DB_CONNECTION = 'furimadeck'
$env:FURIMADECK_DB_DRIVER = 'sqlite'
$env:FURIMADECK_DB_DATABASE = $dbPath
$env:FURIMADECK_DB_URL = ''
$env:SESSION_DRIVER = 'file'
$env:LARAVEL_STORAGE_PATH = $storagePath
try {
    & php (Join-Path $projectRoot 'artisan') migrate --database=furimadeck --path=database/migrations/furimadeck --force
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & php (Join-Path $projectRoot 'artisan') db:seed --database=furimadeck --class=FurimaDeckMarketplaceSeeder --no-interaction
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & php (Join-Path $projectRoot 'artisan') furimadeck:sync-demo-user
    exit $LASTEXITCODE
}
finally {
    foreach ($name in $environmentNames) {
        $previousValue = $previousEnvironment[$name]
        if ($previousValue.Exists) {
            Set-Item -Path "Env:$name" -Value $previousValue.Value
        } else {
            Remove-Item -Path "Env:$name" -ErrorAction SilentlyContinue
        }
    }
}
