# Keep the local database queue worker alive across Laravel's clean lost-
# connection exit. Non-zero exits are surfaced to the parent process.
[CmdletBinding()]
param(
    [ValidateSet('mail', 'document-processing')]
    [string] $Queue = 'document-processing',
    [ValidateRange(1, 3600)]
    [int] $Timeout = 360
)

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$php = (Get-Command php.exe -ErrorAction Stop).Source
$workerArguments = @(
    '-d', 'max_execution_time=0',
    'artisan', 'queue:work',
    '--tries=3',
    "--timeout=$Timeout",
    "--queue=$Queue"
)

Set-Location -LiteralPath $repo

while ($true) {
    & $php @workerArguments
    $exitCode = $LASTEXITCODE

    if ($exitCode -ne 0) {
        exit $exitCode
    }

    Write-Warning "Laravel queue worker for '$Queue' exited cleanly; restarting in 3 seconds."
    Start-Sleep -Seconds 3
}
