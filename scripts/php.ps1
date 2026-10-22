$phpArguments = $args
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpBinary = $env:PHP_BINARY
if (-not $phpBinary) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { $phpBinary = $phpCommand.Source }
    else { $phpBinary = Join-Path (Split-Path -Parent $projectRoot) '.runtime\php\php.exe' }
}
if (-not (Test-Path -LiteralPath $phpBinary -PathType Leaf)) {
    throw 'PHP was not found. Install PHP 8.3+ or set PHP_BINARY to the absolute php.exe path.'
}
Push-Location $projectRoot
try {
    & $phpBinary @phpArguments
    $result = $LASTEXITCODE
} finally { Pop-Location }
exit $result
