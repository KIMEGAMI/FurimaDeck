$ErrorActionPreference = 'Stop'
$dbPath = Join-Path (Get-Location) 'storage/framework/testing/furimadeck-e2e.sqlite'
$dbDir = Split-Path -Parent $dbPath
New-Item -ItemType Directory -Force -Path $dbDir | Out-Null
Remove-Item -LiteralPath $dbPath -Force -ErrorAction SilentlyContinue
New-Item -ItemType File -Path $dbPath | Out-Null
$env:FURIMADECK_DB_DRIVER = 'sqlite'
$env:FURIMADECK_DB_DATABASE = $dbPath
try {
    & php artisan migrate --database=furimadeck --path=database/migrations/furimadeck --force
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & php artisan db:seed --class=FurimaDeckMarketplaceSeeder --no-interaction
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & php artisan furimadeck:sync-demo-user
    exit $LASTEXITCODE
}
finally {
    Remove-Item Env:FURIMADECK_DB_DRIVER -ErrorAction SilentlyContinue
    Remove-Item Env:FURIMADECK_DB_DATABASE -ErrorAction SilentlyContinue
}