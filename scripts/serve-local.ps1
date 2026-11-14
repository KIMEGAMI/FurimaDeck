[CmdletBinding()]
param(
    [ValidateSet('127.0.0.1', 'localhost')]
    [string] $BindAddress = '127.0.0.1',

    [ValidateRange(1024, 65535)]
    [int] $Port = 8001,

    [switch] $CheckOnly
)

$ErrorActionPreference = 'Stop'
$php = (Get-Command php.exe -ErrorAction Stop).Source
$phpDirectory = Split-Path -Parent $php
$extensionDirectory = (& $php -r 'echo ini_get(''extension_dir'');').Trim()
$iniScanDirectory = Join-Path $PSScriptRoot 'php-windows'

if (-not [System.IO.Path]::IsPathRooted($extensionDirectory)) {
    $extensionDirectory = Join-Path $phpDirectory $extensionDirectory
}

$exifExtension = Join-Path $extensionDirectory 'php_exif.dll'
$originalIniScanDirectory = [Environment]::GetEnvironmentVariable('PHP_INI_SCAN_DIR', 'Process')
$exitCode = 1

try {
    $featureCheck = 'echo (extension_loaded(''exif'') ? ''exif'' : ''no-exif'').''|''.(extension_loaded(''gd'') && function_exists(''imagewebp'') ? ''gd-webp'' : ''no-gd-webp'');'
    $features = (& $php -r $featureCheck).Trim()

    if ($features -notmatch '^exif\|gd-webp$') {
        if ($features -notmatch '^no-exif\|gd-webp$' -or -not (Test-Path -LiteralPath $exifExtension -PathType Leaf)) {
            throw 'FurimaDeck needs PHP EXIF and GD WebP support. Enable them in the selected PHP runtime and retry.'
        }

        if (-not (Test-Path -LiteralPath (Join-Path $iniScanDirectory '10-exif.ini') -PathType Leaf)) {
            throw 'The project-local Windows PHP extension configuration is missing.'
        }

        [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', $iniScanDirectory, 'Process')

        $features = (& $php -r $featureCheck).Trim()
        if ($features -notmatch '^exif\|gd-webp$') {
            throw 'The selected PHP runtime could not load the EXIF extension.'
        }
    }

    Write-Output ('PHP_FEATURES=' + $features)

    if ($CheckOnly) {
        $exitCode = 0
    } else {
        $portProbe = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
        try {
            $portProbe.Start()
        } catch [System.Net.Sockets.SocketException] {
            throw "Port $Port is unavailable; refusing to start a second development server."
        } finally {
            if ($portProbe.Server.IsBound) {
                $portProbe.Stop()
            }
        }

        Write-Output "Starting the local FurimaDeck server on http://${BindAddress}:$Port"
        & $php artisan serve --host=$BindAddress --port=$Port
        $exitCode = $LASTEXITCODE
    }
} finally {
    [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', $originalIniScanDirectory, 'Process')
}

exit $exitCode
