$ErrorActionPreference = 'Stop'

$extensionRoot = Split-Path -Parent $PSScriptRoot
$distPath = Join-Path $extensionRoot 'dist'
$releasePath = Join-Path $extensionRoot 'release'
$manifestPath = Join-Path $distPath 'manifest.json'

if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw 'Build output is missing. Run npm run build first.'
}

$manifest = Get-Content -LiteralPath $manifestPath -Encoding UTF8 -Raw | ConvertFrom-Json
if (-not $manifest.version -or -not (Test-Path -LiteralPath (Join-Path $distPath $manifest.icons.'128') -PathType Leaf)) {
    throw 'The built extension must have a version and a 128px store icon.'
}

New-Item -ItemType Directory -Path $releasePath -Force | Out-Null
$archivePath = Join-Path $releasePath "furimadeck-chrome-extension-$($manifest.version).zip"
if (Test-Path -LiteralPath $archivePath) {
    throw "Package already exists; refusing to overwrite it: $archivePath"
}

$tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$tempPrefix = $tempRoot.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
$stagingPath = [System.IO.Path]::GetFullPath((Join-Path $tempRoot "furimadeck-store-package-$([guid]::NewGuid().ToString('N'))"))
if (-not $stagingPath.StartsWith($tempPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Refusing to stage a package outside the system temporary directory.'
}

New-Item -ItemType Directory -Path $stagingPath | Out-Null
try {
    Copy-Item -Path (Join-Path $distPath '*') -Destination $stagingPath -Recurse -Force

    $stagedManifestPath = Join-Path $stagingPath 'manifest.json'
    $stagedManifest = Get-Content -LiteralPath $stagedManifestPath -Encoding UTF8 -Raw | ConvertFrom-Json
    $stagedManifest.optional_host_permissions = @($stagedManifest.optional_host_permissions | Where-Object { $_ -ne 'http://127.0.0.1/*' })
    $manifestJson = $stagedManifest | ConvertTo-Json -Depth 20
    [System.IO.File]::WriteAllText($stagedManifestPath, $manifestJson, [System.Text.UTF8Encoding]::new($false))

    if (@($stagedManifest.optional_host_permissions | Where-Object { $_ -like 'http://127.*' -or $_ -like 'http://localhost*' }).Count -gt 0) {
        throw 'The Chrome Web Store package must not request local development host permissions.'
    }

    $stagedPopupPath = Join-Path $stagingPath 'popup.html'
    $popupHtml = [System.IO.File]::ReadAllText($stagedPopupPath)
    $developmentDefault = 'value="http://127.0.0.1:8001"'
    if (-not $popupHtml.Contains($developmentDefault)) {
        throw 'The development server URL default was not found in the staged popup.'
    }
    $popupHtml = $popupHtml.Replace($developmentDefault, 'value="https://furimadeck.kimegami.jp"')
    [System.IO.File]::WriteAllText($stagedPopupPath, $popupHtml, [System.Text.UTF8Encoding]::new($false))

    Compress-Archive -Path (Join-Path $stagingPath '*') -DestinationPath $archivePath -CompressionLevel Optimal
} finally {
    $resolvedStagingPath = [System.IO.Path]::GetFullPath($stagingPath)
    if ($resolvedStagingPath.StartsWith($tempPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $resolvedStagingPath -Recurse -Force
    } else {
        throw 'Refusing to remove a staging directory outside the system temporary directory.'
    }
}

Write-Output "Chrome Web Store package created: $archivePath"
