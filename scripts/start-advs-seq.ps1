# Sequential start script for ADVS (Windows PowerShell)
# Usage: Right-click → Run with PowerShell, or execute from PowerShell: .\scripts\start-advs-seq.ps1

[CmdletBinding()]
param(
    [switch] $ResetDatabase
)

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$pythonDirectory = Join-Path $repo 'python'
$pythonExecutable = Join-Path $pythonDirectory 'env\Scripts\python.exe'
$resetDatabase = $ResetDatabase
Write-Host "Repository root: $repo"

function Run-Command {
    param(
        [string]$cmd,
        [switch]$exitOnError
    )
    Write-Host "Running: $cmd"
    $proc = Start-Process -FilePath pwsh -ArgumentList "-NoProfile","-Command","cd '$repo'; $cmd" -NoNewWindow -Wait -PassThru
    if ($proc.ExitCode -ne 0) {
        Write-Host "Command failed with exit code $($proc.ExitCode): $cmd" -ForegroundColor Red
        if ($exitOnError) { throw "Aborting due to error." }
    }
}

try {
    # 1) Prep steps (run sequentially and stop on first failure)
    if ($resetDatabase) {
        Run-Command -cmd "php artisan migrate:fresh --no-interaction" -exitOnError
        Run-Command -cmd "php artisan db:seed --no-interaction" -exitOnError
    }

    Run-Command -cmd "npm run build" -exitOnError
    Run-Command -cmd "php artisan optimize:clear" -exitOnError
    Run-Command -cmd "php artisan advs:db-ping --no-interaction" -exitOnError

    # 2) Start services, each in a new window
    Write-Host "Starting services in separate windows..."

    $startArgs = @( 
          @{ name = 'Laravel Server'; cmd = "php -d max_execution_time=0 artisan serve" },
          @{ name = 'Mail Queue Worker'; cmd = "powershell -NoProfile -ExecutionPolicy Bypass -File scripts/queue-worker.ps1 -Queue mail -Timeout 60" },
          @{ name = 'Document Queue Worker'; cmd = "powershell -NoProfile -ExecutionPolicy Bypass -File scripts/queue-worker.ps1 -Queue document-processing -Timeout 360" },
          @{ name = 'Vite Dev'; cmd = "npm run dev" },
          @{ name = 'Python API'; cmd = "& '$repo\start_fastapi.ps1'" }
    )

    foreach ($s in $startArgs) {
        $title = $s.name
        $cmd = $s.cmd
        Write-Host "Launching: $title -> $cmd"
        Start-Process -FilePath powershell -ArgumentList "-NoExit","-Command","Set-Location -LiteralPath '$repo'; $cmd" -WindowStyle Normal
        Start-Sleep -Milliseconds 400
    }

    if (-not (Test-Path -LiteralPath $pythonExecutable)) {
        throw "Python environment not found: $pythonExecutable"
    }

    $healthUri = "http://127.0.0.1:7860/health"
    $readyUri = "http://127.0.0.1:7860/ready"
    $deadline = (Get-Date).AddSeconds(90)
    $ready = $false
    $lastReadinessError = 'No response yet.'

    while ((Get-Date) -lt $deadline) {
        try {
            $health = Invoke-RestMethod -Uri $healthUri -TimeoutSec 5 -ErrorAction Stop
            if ($health.status -ne 'ok' -or $health.service -ne 'advs-ml-api') {
                throw "Unexpected FastAPI health response."
            }

            $readyResponse = Invoke-WebRequest -Uri $readyUri -TimeoutSec 5 -UseBasicParsing -ErrorAction Stop
            $readyBody = $readyResponse.Content | ConvertFrom-Json
            if ($readyResponse.StatusCode -eq 200 -and $readyBody.status -eq 'ready') {
                $ready = $true
                break
            }
        } catch {
            $lastReadinessError = if ($_.ErrorDetails.Message) {
                $_.ErrorDetails.Message
            } else {
                $_.Exception.Message
            }
            Start-Sleep -Seconds 2
        }
    }

    if (-not $ready) {
        throw "FastAPI did not become ready at $readyUri within 90 seconds. Last response: $lastReadinessError"
    }

    Write-Host "All services launched. Check each window for output." -ForegroundColor Green
} catch {
    Write-Host "Error: $_" -ForegroundColor Red
    exit 1
}
