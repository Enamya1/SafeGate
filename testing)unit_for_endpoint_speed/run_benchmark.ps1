param(
    [string]$ConfigPath = ".\config.json",
    [switch]$DryRun
)

$scriptPath = Join-Path $PSScriptRoot "endpoint_benchmark.py"

if ($DryRun) {
    python $scriptPath --config $ConfigPath --dry-run
} else {
    python $scriptPath --config $ConfigPath
}
