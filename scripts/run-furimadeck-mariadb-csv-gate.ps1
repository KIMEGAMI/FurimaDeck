param(
    [string]$Database = 'furimadeck_csv_gate'
)

$blockedNames = @('furimadeck_local', 'furimadeck_local_clean', 'production', 'prod')
if ($blockedNames -contains $Database.ToLowerInvariant() -or $Database -notmatch '^furimadeck_csv_gate$') {
    throw "Refusing to run against a non-dedicated database: $Database"
}

$env:FURIMADECK_MARIADB_GATE = '1'
$env:FURIMADECK_DB_DATABASE = $Database
try {
    & php vendor\phpunit\phpunit\phpunit --no-coverage tests/Feature/FurimaDeckRestoreCsvTest.php
    exit $LASTEXITCODE
}
finally {
    Remove-Item Env:FURIMADECK_MARIADB_GATE -ErrorAction SilentlyContinue
    Remove-Item Env:FURIMADECK_DB_DATABASE -ErrorAction SilentlyContinue
}