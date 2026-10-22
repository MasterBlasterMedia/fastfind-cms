$composerArguments = $args
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$composerBinary = $env:COMPOSER_BINARY
if (-not $composerBinary) {
    $composerCommand = Get-Command composer -ErrorAction SilentlyContinue
    if ($composerCommand) { $composerBinary = $composerCommand.Source }
    else { $composerBinary = Join-Path (Split-Path -Parent $projectRoot) '.runtime\composer.phar' }
}
if (-not (Test-Path -LiteralPath $composerBinary -PathType Leaf)) {
    throw 'Composer was not found. Install Composer 2 or set COMPOSER_BINARY to its absolute path.'
}
if ([IO.Path]::GetExtension($composerBinary) -eq '.phar') {
    & (Join-Path $PSScriptRoot 'php.ps1') $composerBinary @composerArguments
    exit $LASTEXITCODE
}
Push-Location $projectRoot
try {
    & $composerBinary @composerArguments
    $result = $LASTEXITCODE
} finally { Pop-Location }
exit $result
