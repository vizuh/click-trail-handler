Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-PluginVersion {
    param(
        [Parameter(Mandatory = $true)]
        [string] $PluginMainFile
    )

    $lines = Get-Content -Path $PluginMainFile
    foreach ($line in $lines) {
        if ($line -match '^\s*\*\s*Version:\s*(.+)\s*$') {
            return $matches[1].Trim()
        }
    }
    return 'dev'
}

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = (Resolve-Path (Join-Path $scriptRoot '..\..')).Path
$pluginSlug = Split-Path -Leaf $repoRoot
$pluginMain = Join-Path $repoRoot 'clicutcl.php'

if (-not (Test-Path -Path $pluginMain)) {
    throw "Could not find plugin main file at: $pluginMain"
}

$version = Get-PluginVersion -PluginMainFile $pluginMain
$outputDir = Join-Path $repoRoot 'dist'
$zipName = "$pluginSlug-$version.zip"
$zipPath = Join-Path $outputDir $zipName

if (-not (Test-Path -Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$tempRoot = Join-Path $env:TEMP ("$pluginSlug-build-" + [guid]::NewGuid().ToString('N'))
$stageDir = Join-Path $tempRoot $pluginSlug
New-Item -ItemType Directory -Path $stageDir -Force | Out-Null

$excludeDirs = @(
    '.git',
    '.github',
    '.vscode',
    'dist',
    'docs',
    'tools',
    'node_modules'
)

$excludeFiles = @(
    '.gitignore',
    '.gitattributes',
    'package.json',
    'package-lock.json',
    'phpcs.xml.dist',
    'readme_header_update.txt',
    'AGENTS.md'
)

$robocopyArgs = @(
    $repoRoot,
    $stageDir,
    '/E',
    '/NJH',
    '/NJS',
    '/NDL',
    '/NFL',
    '/NP'
)

if ($excludeDirs.Count -gt 0) {
    $robocopyArgs += '/XD'
    $robocopyArgs += $excludeDirs
}

if ($excludeFiles.Count -gt 0) {
    $robocopyArgs += '/XF'
    $robocopyArgs += $excludeFiles
}

& robocopy @robocopyArgs | Out-Null
$robocopyCode = $LASTEXITCODE
if ($robocopyCode -gt 7) {
    throw "Robocopy failed with exit code: $robocopyCode"
}

if (Test-Path -Path $zipPath) {
    Remove-Item -Path $zipPath -Force
}

Compress-Archive -Path $stageDir -DestinationPath $zipPath -Force
Remove-Item -Path $tempRoot -Recurse -Force

Write-Output "ZIP created: $zipPath"
