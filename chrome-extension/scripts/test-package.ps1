$ErrorActionPreference = 'Stop'

$extensionRoot = Split-Path -Parent $PSScriptRoot
$manifestPath = Join-Path $extensionRoot 'public/manifest.json'
$manifest = Get-Content -LiteralPath $manifestPath -Encoding UTF8 -Raw | ConvertFrom-Json
$archivePath = Join-Path $extensionRoot "release/furimadeck-chrome-extension-$($manifest.version).zip"

if (-not (Test-Path -LiteralPath $archivePath -PathType Leaf)) {
    throw "Version-matched package not found. Run npm run package:store first: $archivePath"
}

$tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$tempPrefix = $tempRoot.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
$testDirectory = [System.IO.Path]::GetFullPath((Join-Path $tempRoot "furimadeck-package-test-$([guid]::NewGuid().ToString('N'))"))
if (-not $testDirectory.StartsWith($tempPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Refusing to extract outside the system temporary directory.'
}

$previousExtensionPath = $env:FURIMADECK_EXTENSION_PATH
$testExitCode = 1
New-Item -ItemType Directory -Path $testDirectory | Out-Null

try {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::ExtractToDirectory($archivePath, $testDirectory)
    $packagedManifestPath = Join-Path $testDirectory 'manifest.json'
    $packagedManifest = Get-Content -LiteralPath $packagedManifestPath -Encoding UTF8 -Raw | ConvertFrom-Json
    if ($packagedManifest.version -ne $manifest.version) {
        throw 'The ZIP manifest version does not match the source manifest.'
    }
    if (@($packagedManifest.optional_host_permissions | Where-Object { $_ -like 'http://127.*' -or $_ -like 'http://localhost*' }).Count -gt 0) {
        throw 'The Chrome Web Store package must not request local development host permissions.'
    }
    foreach ($requiredFile in @('popup.html', 'options.html', 'service-worker.js', 'content.js', 'product-bridge.js', 'icons/icon-128.png')) {
        if (-not (Test-Path -LiteralPath (Join-Path $testDirectory $requiredFile) -PathType Leaf)) {
            throw "The Chrome Web Store package is missing a required file: $requiredFile"
        }
    }
    $packagedPopup = Get-Content -LiteralPath (Join-Path $testDirectory 'popup.html') -Raw
    if (-not $packagedPopup.Contains('value="https://furimadeck.kimegami.jp"') -or $packagedPopup.Contains('value="http://127.0.0.1:8001"')) {
        throw 'The Chrome Web Store popup must default to production and must not expose the local development URL.'
    }
    Push-Location $extensionRoot
    try {
        $env:FURIMADECK_EXTENSION_PATH = $testDirectory
        & node (Join-Path $extensionRoot 'tests/package-smoke.mjs')
        if ($LASTEXITCODE -ne 0) {
            throw "Chrome Web Store package runtime smoke failed with exit code $LASTEXITCODE."
        }

        $env:FURIMADECK_EXTENSION_PATH = Join-Path $extensionRoot 'dist'
        & node (Join-Path $extensionRoot 'tests/chrome-smoke.mjs')
        $testExitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }
} finally {
    if ($null -eq $previousExtensionPath) {
        Remove-Item Env:FURIMADECK_EXTENSION_PATH -ErrorAction SilentlyContinue
    } else {
        $env:FURIMADECK_EXTENSION_PATH = $previousExtensionPath
    }

    $resolvedTestDirectory = [System.IO.Path]::GetFullPath($testDirectory)
    if ($resolvedTestDirectory.StartsWith($tempPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $resolvedTestDirectory -Recurse -Force
    } else {
        throw 'Refusing to remove a directory outside the system temporary directory.'
    }
}

if ($testExitCode -ne 0) {
    throw "Packaged Chrome extension E2E failed with exit code $testExitCode."
}

Write-Output "Chrome Web Store ZIP integrity and runtime smoke passed; development build E2E passed: version $($manifest.version)."
