param(
    [string]$DbHost = "127.0.0.1",
    [int]$DbPort = 3306,
    [string]$DbName = "codex_auto_resume",
    [string]$DbUser = "root",
    [string]$DbPassword = "",
    [switch]$ForceConfig,
    [switch]$SkipLaragonProcfile
)

$ErrorActionPreference = "Stop"
$projectRoot = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $projectRoot ".env"
$schemaPath = Join-Path $projectRoot "database\schema.sql"
$varPath = Join-Path $projectRoot "var"
$laragonProcfile = "C:\laragon\usr\Procfile"

if ($DbName -notmatch '^[A-Za-z0-9_]+$') {
    throw "DbName may contain only letters, numbers, and underscores."
}

$phpCommand = Get-Command php.exe -ErrorAction Stop
$mysqlCommand = Get-Command mysql.exe -ErrorAction Stop
$codexCandidates = & where.exe codex 2>$null
$codexExecutable = $codexCandidates | Where-Object { $_ -match '\.exe$' -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
if (-not $codexExecutable) {
    throw "A direct codex.exe was not found."
}

$codexHome = if ($env:CODEX_HOME) { $env:CODEX_HOME } else { Join-Path $env:USERPROFILE ".codex" }
$stateDb = Join-Path $codexHome "state_5.sqlite"
if (-not (Test-Path -LiteralPath $stateDb)) {
    throw "Codex state database was not found at $stateDb"
}

if ($ForceConfig -or -not (Test-Path -LiteralPath $envPath)) {
    $normalizedHome = $codexHome.Replace('\', '/')
    $normalizedState = $stateDb.Replace('\', '/')
    $normalizedCodex = $codexExecutable.Replace('\', '/')
    $escapedPassword = $DbPassword.Replace('\', '\\').Replace('"', '\"')
    $configLines = @(
        'APP_TIMEZONE="Asia/Singapore"',
        'APP_ALLOWED_IPS="127.0.0.1,::1"',
        '',
        "DB_HOST=`"$DbHost`"",
        "DB_PORT=`"$DbPort`"",
        "DB_NAME=`"$DbName`"",
        "DB_USER=`"$DbUser`"",
        "DB_PASSWORD=`"$escapedPassword`"",
        'DB_RETRY_SECONDS="5"',
        '',
        "CODEX_HOME=`"$normalizedHome`"",
        "CODEX_STATE_DB=`"$normalizedState`"",
        "CODEX_EXECUTABLE=`"$normalizedCodex`"",
        'DEFAULT_RESUME_PROMPT="Continue the previous task from where you stopped. Review the existing session context first, do not redo completed work, and continue until the task is completed."',
        '',
        'WORKER_POLL_SECONDS="5"',
        'WORKER_STALE_SECONDS="30"',
        'SCAN_LOOKBACK_HOURS="168"',
        'INITIAL_SCAN_BYTES="1048576"',
        'RESUME_COOLDOWN_SECONDS="300"',
        'ACTIVE_WRITER_RETRY_SECONDS="300"',
        'RATE_LIMIT_RETRY_SECONDS="300"',
        'UNKNOWN_RESET_BACKOFF_SECONDS="1800"',
        'STALE_RESUME_LOCK_SECONDS="21600"',
        'RECOVERY_MAX_LIMIT_AGE_SECONDS="21600"',
        'POST_RESET_GRACE_SECONDS="600"',
        'LOG_RETENTION="500"'
    )
    Set-Content -LiteralPath $envPath -Value $configLines -Encoding UTF8
}

New-Item -ItemType Directory -Path $varPath -Force | Out-Null
$previousPassword = $env:MYSQL_PWD
$env:MYSQL_PWD = $DbPassword
try {
    $createDatabase = "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
    & $mysqlCommand.Source "--protocol=tcp" "--host=$DbHost" "--port=$DbPort" "--user=$DbUser" "--execute=$createDatabase"
    if ($LASTEXITCODE -ne 0) { throw "MySQL database creation failed." }
    Get-Content -LiteralPath $schemaPath -Raw | & $mysqlCommand.Source "--protocol=tcp" "--host=$DbHost" "--port=$DbPort" "--user=$DbUser" "--database=$DbName"
    if ($LASTEXITCODE -ne 0) { throw "MySQL schema installation failed." }
} finally {
    if ($null -eq $previousPassword) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue } else { $env:MYSQL_PWD = $previousPassword }
}

if (-not $SkipLaragonProcfile -and (Test-Path -LiteralPath $laragonProcfile)) {
    $markerStart = "; Codex Auto Resume Worker [managed:start]"
    $markerEnd = "; Codex Auto Resume Worker [managed:end]"
    $existing = Get-Content -LiteralPath $laragonProcfile -Raw
    if (-not $existing.Contains($markerStart)) {
        $backupPath = "$laragonProcfile.codex-auto-resume.bak"
        if (-not (Test-Path -LiteralPath $backupPath)) {
            Copy-Item -LiteralPath $laragonProcfile -Destination $backupPath
        }
        $entry = "Codex Auto Resume Worker: autorun `"$($phpCommand.Source)`" `"$(Join-Path $projectRoot 'worker.php')`" PWD=`"$projectRoot`""
        Add-Content -LiteralPath $laragonProcfile -Value @("", $markerStart, $entry, $markerEnd) -Encoding UTF8
    }
}

& $phpCommand.Source (Join-Path $projectRoot "worker.php") "--once" "--dry-run"
if ($LASTEXITCODE -ne 0) { throw "Worker dry-run verification failed." }

Write-Output "Codex Auto Resume installed."
Write-Output "Dashboard: http://codex_token_resume_management.test/"
Write-Output "Start worker now: powershell -ExecutionPolicy Bypass -File scripts\start-worker.ps1"
