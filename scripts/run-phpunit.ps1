param(
    [switch] $EnableDevelopmentR2LiveTest,
    [switch] $EnableProductionR2LiveTest,
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PhpUnitArguments
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$php = (Get-Command php.exe -ErrorAction Stop).Source
$autoload = Join-Path $projectRoot 'vendor\autoload.php'
$phpUnit = Join-Path $projectRoot 'vendor\bin\phpunit'
$cacheDirectory = Join-Path $projectRoot 'storage\framework\phpunit-cache'

foreach ($requiredPath in @($autoload, $phpUnit)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Required test runtime file was not found: $requiredPath"
    }
}

if ($EnableDevelopmentR2LiveTest -and $EnableProductionR2LiveTest) {
    throw 'Enable only one live R2 target per test run.'
}

$testKeyBytes = New-Object byte[] 32
$testKeyGenerator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$testKeyGenerator.GetBytes($testKeyBytes)
$testAppKey = 'base64:' + [Convert]::ToBase64String($testKeyBytes)
$developmentR2LiveTest = if ($EnableDevelopmentR2LiveTest) { '1' } else { '0' }
$productionR2LiveTest = if ($EnableProductionR2LiveTest) { '1' } else { '0' }

$testEnvironment = @{
    APP_KEY = $testAppKey
    APP_ENV = 'testing'
    APP_MAINTENANCE_DRIVER = 'file'
    BCRYPT_ROUNDS = '4'
    BROADCAST_CONNECTION = 'null'
    CACHE_STORE = 'array'
    DB_CONNECTION = 'sqlite'
    DB_DATABASE = ':memory:'
    DB_URL = ''
    FURIMADECK_DB_DRIVER = 'sqlite'
    FURIMADECK_DB_DATABASE = ':memory:'
    FURIMADECK_PRODUCT_IMAGE_DISK = 'local'
    FURIMADECK_R2_LIVE_TEST = $developmentR2LiveTest
    FURIMADECK_R2_PRODUCTION_LIVE_TEST = $productionR2LiveTest
    MAIL_MAILER = 'array'
    QUEUE_CONNECTION = 'sync'
    SESSION_DRIVER = 'array'
    VIEW_COMPILED_PATH = 'storage/framework/views-testing'
    PULSE_ENABLED = 'false'
    TELESCOPE_ENABLED = 'false'
    NIGHTWATCH_ENABLED = 'false'
}

$originalEnvironment = @{}
foreach ($name in $testEnvironment.Keys) {
    $originalEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

$testExitCode = 1
try {
    foreach ($name in $testEnvironment.Keys) {
        Set-Item -Path "Env:$name" -Value $testEnvironment[$name]
    }

    $phpArguments = @('-d', "auto_prepend_file=$autoload")
    $loadedExtensions = @(& $php -m)
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not inspect the PHP extensions required for tests.'
    }

    if ($loadedExtensions -notcontains 'exif' -and $env:OS -eq 'Windows_NT') {
        $phpDirectory = Split-Path -Parent $php
        $exifExtension = Join-Path $phpDirectory 'ext\php_exif.dll'
        if (Test-Path -LiteralPath $exifExtension -PathType Leaf) {
            $phpArguments += @('-d', 'extension=exif')
        }
    }

    $phpUnitArguments = @(
        '--no-configuration',
        "--cache-directory=$cacheDirectory",
        (Join-Path $projectRoot 'tests\Unit'),
        (Join-Path $projectRoot 'tests\Feature')
    ) + $PhpUnitArguments

    & $php @phpArguments $phpUnit @phpUnitArguments
    $testExitCode = $LASTEXITCODE
} finally {
    foreach ($name in $testEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($name, $originalEnvironment[$name], 'Process')
    }
    [Array]::Clear($testKeyBytes, 0, $testKeyBytes.Length)
    $testKeyGenerator.Dispose()
}

exit $testExitCode
