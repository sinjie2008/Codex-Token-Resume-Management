param([switch]$Foreground)

$ErrorActionPreference = "Stop"
$projectRoot = Split-Path -Parent $PSScriptRoot
$workerPath = Join-Path $projectRoot "worker.php"
$varPath = Join-Path $projectRoot "var"
$phpCommand = Get-Command php.exe -ErrorAction Stop
New-Item -ItemType Directory -Path $varPath -Force | Out-Null

if ($Foreground) {
    & $phpCommand.Source $workerPath
    exit $LASTEXITCODE
}

$process = Start-Process -FilePath $phpCommand.Source -ArgumentList @($workerPath) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $varPath "worker.out.log") -RedirectStandardError (Join-Path $varPath "worker.error.log")
Set-Content -LiteralPath (Join-Path $varPath "worker.pid") -Value $process.Id -Encoding ASCII
Write-Output "Worker started with PID $($process.Id)."

