param(
    [string]$Database = 'furimadeck_csv_gate',
    [ValidateRange(1, 65535)]
    [int]$Port = 3306
)

$ErrorActionPreference = 'Stop'
$expectedDatabase = 'furimadeck_csv_gate'
if ($Database -cne $expectedDatabase) {
    throw "Refusing to run against a non-dedicated database: $Database"
}
$gatePassword = [Environment]::GetEnvironmentVariable('FURIMADECK_MARIADB_GATE_PASSWORD', 'Process')
if ([string]::IsNullOrWhiteSpace($gatePassword)) {
    throw 'Set FURIMADECK_MARIADB_GATE_PASSWORD in the current PowerShell process before running the MariaDB gate.'
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$artisanPath = Join-Path $projectRoot 'artisan'
$phpUnitPath = Join-Path $projectRoot 'vendor\phpunit\phpunit\phpunit'
$gateTestPath = Join-Path $projectRoot 'tests\Feature\FurimaDeckMariaDbCsvGateTest.php'
$phpExecutable = (Get-Command php -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source

foreach ($path in @($artisanPath, $phpUnitPath, $gateTestPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required test file was not found: $path"
    }
}

$environmentNames = @(
    'APP_ENV',
    'APP_KEY',
    'FURIMADECK_MARIADB_GATE',
    'FURIMADECK_MARIADB_GATE_PORT',
    'FURIMADECK_DB_DRIVER',
    'FURIMADECK_DB_HOST',
    'FURIMADECK_DB_PORT',
    'FURIMADECK_DB_DATABASE',
    'FURIMADECK_DB_URL',
    'FURIMADECK_DB_USERNAME',
    'FURIMADECK_DB_PASSWORD'
)
$originalEnvironment = @{}
foreach ($name in $environmentNames) {
    $originalEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

$exitCode = 1
$testKeyBytes = New-Object byte[] 32
$testKeyGenerator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
try {
    $testKeyGenerator.GetBytes($testKeyBytes)
    $env:APP_ENV = 'testing'
    $env:APP_KEY = 'base64:' + [Convert]::ToBase64String($testKeyBytes)
    $env:FURIMADECK_MARIADB_GATE = '1'
    $env:FURIMADECK_MARIADB_GATE_PORT = [string]$Port
    $env:FURIMADECK_DB_DRIVER = 'mysql'
    $env:FURIMADECK_DB_HOST = '127.0.0.1'
    $env:FURIMADECK_DB_PORT = [string]$Port
    $env:FURIMADECK_DB_DATABASE = $expectedDatabase
    $env:FURIMADECK_DB_USERNAME = 'root'
    $env:FURIMADECK_DB_PASSWORD = $gatePassword
    $env:FURIMADECK_DB_URL = ''

    Push-Location $projectRoot
    try {
        & $phpExecutable $artisanPath 'migrate' '--database=furimadeck' '--path=database/migrations/furimadeck' '--force'
        if ($LASTEXITCODE -ne 0) {
            throw "Additive MariaDB migrations failed with exit code $LASTEXITCODE."
        }

        & $phpExecutable $phpUnitPath '--no-coverage' $gateTestPath
        $exitCode = $LASTEXITCODE
    }
    finally {
        Pop-Location
    }
}
finally {
    foreach ($name in $environmentNames) {
        $value = $originalEnvironment[$name]
        [Environment]::SetEnvironmentVariable($name, $value, 'Process')
    }
    [Array]::Clear($testKeyBytes, 0, $testKeyBytes.Length)
    $testKeyGenerator.Dispose()
}

exit $exitCode
