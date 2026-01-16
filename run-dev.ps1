$ErrorActionPreference = "Stop"

$rootDir = $PSScriptRoot
$laravelDir = Join-Path $rootDir "safe_gate"
$pythonDir = Join-Path $rootDir "python_service"

if (!(Test-Path $laravelDir)) {
    throw "Missing folder: $laravelDir"
}

if (!(Test-Path $pythonDir)) {
    throw "Missing folder: $pythonDir"
}

$venvDir = Join-Path $pythonDir ".venv"
$pythonExe = Join-Path $venvDir "Scripts\\python.exe"

if (!(Test-Path $pythonExe)) {
    $null = Get-Command python -ErrorAction Stop
    python -m venv $venvDir
    & $pythonExe -m pip install -r (Join-Path $pythonDir "requirements.txt")
}

$laravelProc = Start-Process -FilePath "php" -WorkingDirectory $laravelDir -ArgumentList @(
    "artisan",
    "serve",
    "--host",
    "127.0.0.1",
    "--port",
    "8000"
) -PassThru

$pythonProc = Start-Process -FilePath $pythonExe -WorkingDirectory $pythonDir -ArgumentList @(
    "-m",
    "uvicorn",
    "app.main:app",
    "--host",
    "127.0.0.1",
    "--port",
    "8001",
    "--reload"
) -PassThru

Write-Host "Laravel running at: http://127.0.0.1:8000"
Write-Host "Python running at:  http://127.0.0.1:8001"

try {
    Wait-Process -Id @($laravelProc.Id, $pythonProc.Id)
} finally {
    if (Get-Process -Id $laravelProc.Id -ErrorAction SilentlyContinue) {
        Stop-Process -Id $laravelProc.Id -Force
    }

    if (Get-Process -Id $pythonProc.Id -ErrorAction SilentlyContinue) {
        Stop-Process -Id $pythonProc.Id -Force
    }
}
