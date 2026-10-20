<#
.SYNOPSIS
    Runs the full test suite on every supported Laravel x PHP combination, each in an
    isolated temporary copy of the project. The original project, composer.json,
    composer.lock and vendor/ are never touched.

.DESCRIPTION
    For each combination the script:
      1. copies the project to a temp folder (without vendor/ and .git/, SDKs/ is linked),
      2. requires the matching laravel/framework, orchestra/testbench and phpunit,
      3. runs the full suite with the real ZATCA SDK enabled, live sandbox tests excluded,
      4. prints a summary table at the end.

    Composer's security-advisory blocking is turned off inside the temporary copies only:
    every Laravel 9/10/11 release carries known advisories, so a fresh install would
    otherwise be refused.

.PARAMETER Only
    Run only the listed combinations, e.g. -Only L12-php84,L9-php81

.PARAMETER PhpBinRoot
    Folder that contains the php81, php82, ... folders (default: Laravel Herd).

.PARAMETER Keep
    Keep the temporary copies after the run (default: they are deleted).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File docs\run-compat-matrix.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File docs\run-compat-matrix.ps1 -Only L12-php84
#>
param(
    [string[]] $Only,
    [string] $PhpBinRoot = "$env:USERPROFILE\.config\herd\bin",
    [switch] $Keep
)

$ErrorActionPreference = 'Stop'

$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$sdkRoot = Join-Path $project 'SDKs\zatca-einvoicing-sdk-Java-238-R3.4.8'
$composer = Join-Path $PhpBinRoot 'composer.phar'
$work = Join-Path $env:TEMP 'zatca-matrix'
$logs = Join-Path $work 'logs'

# Laravel => testbench, phpunit. Matches the support policy: Laravel 9-12.
$stack = @{
    9  = @{ Testbench = '^7.0';  PhpUnit = '^9.5' }
    10 = @{ Testbench = '^8.0';  PhpUnit = '^10.0' }
    11 = @{ Testbench = '^9.0';  PhpUnit = '^10.0|^11.0' }
    12 = @{ Testbench = '^10.0'; PhpUnit = '^11.0' }
}

# Only valid Laravel/PHP pairs, chosen so every Laravel (9-12) and every PHP (8.1-8.4)
# is verified at least once without repeating work.
$combos = @(
    @{ Laravel = 9;  Php = '81' }
    @{ Laravel = 9;  Php = '82' }
    @{ Laravel = 10; Php = '81' }
    @{ Laravel = 10; Php = '83' }
    @{ Laravel = 11; Php = '82' }
    @{ Laravel = 11; Php = '84' }
    @{ Laravel = 12; Php = '82' }
    @{ Laravel = 12; Php = '84' }
)

foreach ($required in @($composer, "$sdkRoot\Apps\zatca-einvoicing-sdk-238-R3.4.8.jar")) {
    if (-not (Test-Path $required)) { throw "Not found: $required" }
}

# Real SDK tests need these (Java must be installed).
$env:ZATCA_SDK_ROOT = $sdkRoot
$env:ZATCA_SDK_EXECUTABLE = Join-Path $sdkRoot 'Apps\zatca-einvoicing-sdk-238-R3.4.8.jar'
$env:COMPOSER_NO_INTERACTION = '1'

New-Item -ItemType Directory -Force $logs | Out-Null
$results = @()

foreach ($combo in $combos) {
    $name = "L$($combo.Laravel)-php$($combo.Php)"
    if ($Only -and ($Only -notcontains $name)) { continue }

    $php = Join-Path $PhpBinRoot "php$($combo.Php)\php.exe"
    $dir = Join-Path $work $name
    $log = Join-Path $logs "$name.log"
    $row = [ordered]@{ Combination = $name; Install = '-'; Tests = '-'; Assertions = '-'; Skipped = '-'; Result = '-' }

    Write-Host "=== $name ===" -ForegroundColor Cyan

    if (-not (Test-Path $php)) {
        $row.Result = "SKIPPED (PHP not found: $php)"
        $results += [pscustomobject]$row
        continue
    }

    if (Test-Path $dir) {
        if (Test-Path "$dir\SDKs") { cmd /c rmdir "$dir\SDKs" | Out-Null }
        Remove-Item -Recurse -Force $dir
    }

    # Copy the project without vendor/.git/SDKs; link SDKs read-only instead of copying 58 MB.
    robocopy $project $dir /E /XD vendor .git SDKs /NFL /NDL /NJH /NJS /NP | Out-Null
    cmd /c mklink /J "$dir\SDKs" "$(Join-Path $project 'SDKs')" | Out-Null
    Remove-Item "$dir\composer.lock" -ErrorAction SilentlyContinue

    Push-Location $dir
    try {
        $laravel = $combo.Laravel
        $config = $stack[$laravel]

        "=== $name ===" | Out-File $log -Encoding utf8
        cmd /c "`"$php`" `"$composer`" config audit.block-insecure false >> `"$log`" 2>&1"
        cmd /c "`"$php`" `"$composer`" require --dev `"laravel/framework:^$laravel.0`" `"orchestra/testbench:$($config.Testbench)`" `"phpunit/phpunit:$($config.PhpUnit)`" -W --no-interaction --no-progress --prefer-dist >> `"$log`" 2>&1"

        if ($LASTEXITCODE -ne 0) {
            $row.Install = 'FAILED'
            $row.Result = 'COULD NOT INSTALL (see log)'
        } else {
            $row.Install = 'ok'
            # --display-skipped is not available in PHPUnit 9, so it is not used.
            cmd /c "`"$php`" vendor\bin\phpunit --exclude-group sandbox --do-not-cache-result >> `"$log`" 2>&1"
            $exit = $LASTEXITCODE

            $summary = Select-String -Path $log -Pattern '^(Tests: .*|OK \(\d+ tests?.*)$' | Select-Object -Last 1
            if ($summary) {
                $text = $summary.Line
                if ($text -match 'Tests: (\d+)') { $row.Tests = $Matches[1] }
                if ($text -match 'Assertions: (\d+)') { $row.Assertions = $Matches[1] }
                $row.Skipped = if ($text -match 'Skipped: (\d+)') { $Matches[1] } else { '0' }
                if ($text -match 'Failures: (\d+)') { $row.Result = "FAILED ($($Matches[1]) failures)" }
                elseif ($text -match 'Errors: (\d+)') { $row.Result = "FAILED ($($Matches[1]) errors)" }
                elseif ($exit -eq 0) { $row.Result = 'PASSED' }
                else { $row.Result = "FAILED (exit $exit)" }
            } else {
                $row.Result = "FAILED (exit $exit, no summary)"
            }
        }
    } finally {
        Pop-Location
        if (Test-Path "$dir\SDKs") { cmd /c rmdir "$dir\SDKs" | Out-Null }
        if (-not $Keep) { Remove-Item -Recurse -Force $dir -ErrorAction SilentlyContinue }
    }

    $results += [pscustomobject]$row
    Write-Host ("    {0}: {1}" -f $name, $row.Result)
}

Write-Host ''
Write-Host '=== Compatibility matrix ===' -ForegroundColor Cyan
$results | Format-Table -AutoSize
Write-Host "Logs: $logs"

if ($results | Where-Object { $_.Result -notlike 'PASSED' }) { exit 1 }
