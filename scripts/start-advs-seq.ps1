# Sequential start script for ADVS (Windows PowerShell)
# Usage: Right-click → Run with PowerShell, or execute from PowerShell: .\scripts\start-advs-seq.ps1

$repo = Split-Path -Parent $MyInvocation.MyCommand.Definition
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
    Run-Command -cmd "php artisan migrate:fresh --no-interaction" -exitOnError
    Run-Command -cmd "php artisan db:seed --no-interaction" -exitOnError
    Run-Command -cmd "npm run build" -exitOnError
    Run-Command -cmd "php artisan optimize:clear" -exitOnError

    # 2) Start services, each in a new window
    Write-Host "Starting services in separate windows..."

    $startArgs = @( 
          @{ name = 'Laravel Server'; cmd = "php -d max_execution_time=0 artisan serve" },
          @{ name = 'Queue Worker'; cmd = "php -d max_execution_time=0 artisan queue:work --queue=document-processing,mail,default" },
          @{ name = 'Vite Dev'; cmd = "npm run dev" },
          @{ name = 'Python API'; cmd = "$repo\python\env\Scripts\python.exe -m uvicorn api.main:app --port 7860" }
    )

    foreach ($s in $startArgs) {
        $title = $s.name
        $cmd = $s.cmd
        Write-Host "Launching: $title -> $cmd"
        Start-Process -FilePath powershell -ArgumentList "-NoExit","-Command","cd '$repo'; $cmd" -WindowStyle Normal
        Start-Sleep -Milliseconds 400
    }

    Write-Host "All services launched. Check each window for output." -ForegroundColor Green
} catch {
    Write-Host "Error: $_" -ForegroundColor Red
    exit 1
}
