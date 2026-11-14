param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PhpUnitArguments
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$php = (Get-Command php.exe -ErrorAction Stop).Source
$autoload = Join-Path $projectRoot 'vendor\autoload.php'
$phpUnit = Join-Path $projectRoot 'vendor\bin\phpunit'
$cacheDirectory = Join-Path $projectRoot 'storage\framework\phpunit-cache'
$temporaryDirectory = Join-Path $projectRoot 'storage\framework\tmp'

New-Item -ItemType Directory -Path $temporaryDirectory -Force | Out-Null

$testAppKeyBytes = New-Object byte[] 32
$randomNumberGenerator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
try {
    $randomNumberGenerator.GetBytes($testAppKeyBytes)
    $testAppKey = 'base64:' + [Convert]::ToBase64String($testAppKeyBytes)
} finally {
    $randomNumberGenerator.Dispose()
    [Array]::Clear($testAppKeyBytes, 0, $testAppKeyBytes.Length)
}

foreach ($requiredPath in @($autoload, $phpUnit)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Required test runtime file was not found: $requiredPath"
    }
}

$testEnvironment = @{
    APP_ENV = 'testing'
    APP_KEY = $testAppKey
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
    MAIL_MAILER = 'array'
    QUEUE_CONNECTION = 'sync'
    SESSION_DRIVER = 'array'
    VIEW_COMPILED_PATH = 'storage/framework/views-testing'
    PULSE_ENABLED = 'false'
    TELESCOPE_ENABLED = 'false'
    NIGHTWATCH_ENABLED = 'false'
    TEMP = $temporaryDirectory
    TMP = $temporaryDirectory
    TMPDIR = $temporaryDirectory
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

    if ($env:OS -eq 'Windows_NT') {
        $phpDirectory = Split-Path -Parent $php
        foreach ($extensionName in @('exif', 'sqlite3')) {
            if ($loadedExtensions -contains $extensionName) {
                continue
            }

            $extensionFile = Join-Path $phpDirectory "ext\php_$extensionName.dll"
            if (Test-Path -LiteralPath $extensionFile -PathType Leaf) {
                $phpArguments += @('-d', "extension=$extensionFile")
            }
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
}

exit $testExitCode
