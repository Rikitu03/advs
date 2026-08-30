#!/usr/bin/env pwsh

[CmdletBinding()]
param(
    [int] $Port = 7860,
    [switch] $Reload
)

$pythonDirectory = (Resolve-Path (Join-Path $PSScriptRoot 'python')).Path
$pythonExecutable = Join-Path $pythonDirectory 'env\Scripts\python.exe'

if (-not (Test-Path -LiteralPath $pythonExecutable)) {
    throw "Python environment not found: $pythonExecutable"
}

Set-Location -LiteralPath $pythonDirectory

$uvicornArguments = @(
    '-m', 'uvicorn', 'api.main:app',
    '--host', '127.0.0.1',
    '--port', $Port
)

if ($Reload) {
    $uvicornArguments += '--reload'
}

& $pythonExecutable @uvicornArguments
exit $LASTEXITCODE
