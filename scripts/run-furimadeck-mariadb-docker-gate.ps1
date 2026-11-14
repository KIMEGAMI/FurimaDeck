param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$Image
)

$ErrorActionPreference = 'Stop'
$gateScript = Join-Path $PSScriptRoot 'run-furimadeck-mariadb-csv-gate.ps1'
$database = 'furimadeck_csv_gate'
$containerName = 'furimadeck-mariadb-gate-' + [guid]::NewGuid().ToString('N')
$containerStarted = $false
$exitCode = 1
$environmentNames = @(
    'MYSQL_ROOT_PASSWORD',
    'MYSQL_DATABASE',
    'MARIADB_ROOT_PASSWORD',
    'MARIADB_DATABASE',
    'FURIMADECK_MARIADB_GATE_PASSWORD'
)
$originalEnvironment = @{}
foreach ($name in $environmentNames) {
    $originalEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

$randomBytes = New-Object byte[] 32
$random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
try {
    if (-not (Test-Path -LiteralPath $gateScript -PathType Leaf)) {
        throw 'The MariaDB gate script was not found.'
    }
    if ($Image -notmatch '\A[a-zA-Z0-9][a-zA-Z0-9._/:@-]*\z') {
        throw 'The database image reference contains unsupported characters.'
    }
    if ($Image -match '(?:^|/)(?<name>mysql|mariadb)(?=[:@]|$)') {
        $imageFlavor = $Matches.name.ToLowerInvariant()
    } else {
        throw 'Only the official MySQL or MariaDB image names are supported by this gate.'
    }

    $dockerInfo = & docker info --format '{{.ServerVersion}}' 2>&1
    if ($LASTEXITCODE -ne 0) {
        $diagnostic = ($dockerInfo | Out-String).Trim()
        throw "Docker Engine is unreachable or access was denied. Docker reported: $diagnostic"
    }

    $imageInspection = & docker image inspect $Image 2>&1
    if ($LASTEXITCODE -ne 0) {
        $diagnostic = ($imageInspection | Out-String).Trim()
        throw "The requested database image is not available locally. Pulling images is intentionally disabled. Docker reported: $diagnostic"
    }

    $random.GetBytes($randomBytes)
    $password = [Convert]::ToBase64String($randomBytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
    $rootPasswordVariable = if ($imageFlavor -eq 'mysql') { 'MYSQL_ROOT_PASSWORD' } else { 'MARIADB_ROOT_PASSWORD' }
    $databaseVariable = if ($imageFlavor -eq 'mysql') { 'MYSQL_DATABASE' } else { 'MARIADB_DATABASE' }
    [Environment]::SetEnvironmentVariable($rootPasswordVariable, $password, 'Process')
    $env:FURIMADECK_MARIADB_GATE_PASSWORD = $password
    $databaseArgument = "$databaseVariable=$database"
    $healthCommand = if ($imageFlavor -eq 'mysql') {
        'mysqladmin ping --protocol=tcp --host=127.0.0.1 --user=root --password="$MYSQL_ROOT_PASSWORD" --silent'
    } else {
        'healthcheck.sh --connect --innodb_initialized'
    }

    $containerStarted = $true
    $dockerArguments = @(
        'run', '--detach', '--name', $containerName,
        '--tmpfs', '/var/lib/mysql:rw,nosuid,nodev,size=512m',
        '--env', $rootPasswordVariable,
        '--env', $databaseArgument,
        '--publish', '127.0.0.1::3306',
        "--health-cmd=$healthCommand",
        '--health-interval=2s', '--health-timeout=5s', '--health-retries=30',
        $Image,
        '--character-set-server=utf8mb4', '--collation-server=utf8mb4_unicode_ci'
    )
    $null = & docker @dockerArguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not start the isolated database container.'
    }

    $portMappings = & docker port $containerName '3306/tcp'
    $portExitCode = $LASTEXITCODE
    $endpoint = (@($portMappings) | Select-Object -First 1)
    $endpoint = if ($null -eq $endpoint) { '' } else { ([string]$endpoint).Trim() }
    if ($portExitCode -ne 0) {
        throw "Could not inspect the isolated database container port mapping (exit ${portExitCode}). Docker reported: [$($portMappings -join ' | ')]"
    }
    if ($endpoint -notmatch '^127\.0\.0\.1:(?<port>[0-9]+)$') {
        throw "The database container did not receive a loopback-only host port. Docker reported: [$endpoint]"
    }
    $port = [int]$Matches.port

    $deadline = (Get-Date).AddMinutes(2)
    do {
        $health = & docker inspect --format '{{.State.Health.Status}}' $containerName
        if ($LASTEXITCODE -ne 0) {
            throw 'Could not inspect the isolated database container.'
        }
        if ($health -eq 'healthy') {
            break
        }
        if ((Get-Date) -ge $deadline) {
            throw 'The isolated database did not become healthy within two minutes.'
        }
        Start-Sleep -Seconds 2
    } while ($true)

    $powershellPath = (Get-Process -Id $PID).Path
    & $powershellPath -NoProfile -NonInteractive -File $gateScript -Database $database -Port $port
    $exitCode = $LASTEXITCODE
}
catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    $exitCode = 1
}
finally {
    foreach ($name in $environmentNames) {
        [Environment]::SetEnvironmentVariable($name, $originalEnvironment[$name], 'Process')
    }
    if ($containerStarted) {
        $null = & docker rm --force --volumes $containerName
    }
    $random.Dispose()
    [Array]::Clear($randomBytes, 0, $randomBytes.Length)
    $password = $null
}

exit $exitCode
