Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-PluginHeaderValue {
    param(
        [Parameter(Mandatory = $true)]
        [string] $PluginMainFile,

        [Parameter(Mandatory = $true)]
        [string] $HeaderName
    )

    $lines = Get-Content -Path $PluginMainFile
    foreach ($line in $lines) {
        if ($line -match ('^\s*\*\s*' + [regex]::Escape($HeaderName) + ':\s*(.+)\s*$')) {
            return $matches[1].Trim()
        }
    }

    return $null
}

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = (Resolve-Path (Join-Path $scriptRoot '..\..')).Path
$pluginMain = Join-Path $repoRoot 'clicutcl.php'

if (-not (Test-Path -Path $pluginMain)) {
    throw "Could not find plugin main file at: $pluginMain"
}

# Use the plugin text domain as the canonical release slug so packaging
# stays stable even when the repo is opened from a temporary worktree.
$pluginSlug = Get-PluginHeaderValue -PluginMainFile $pluginMain -HeaderName 'Text Domain'
if ([string]::IsNullOrWhiteSpace($pluginSlug)) {
    $pluginSlug = Split-Path -Leaf $repoRoot
}

$version = Get-PluginHeaderValue -PluginMainFile $pluginMain -HeaderName 'Version'
if ([string]::IsNullOrWhiteSpace($version)) {
    $version = 'dev'
}

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
    '.claude',
    '.claude-flow',
    '.specify',
    '.understand-anything',
    '.tools',
    'dist',
    'docs',
    'tests',
    'tools',
    'vendor',
    'node_modules',
    'shopify-gtm-container-templates-master'
)

$excludeFiles = @(
    '.git',
    '.gitignore',
    '.gitattributes',
    '.editorconfig',
    '.mcp.json',
    '.distignore',
    '.phpunit.result.cache',
    'feature-test-matrix.json',
    'package.json',
    'package-lock.json',
    'composer.json',
    'composer.lock',
    'phpcs.xml.dist',
    'phpunit.xml.dist',
    'readme_header_update.txt',
    'AGENTS.md',
    'CLAUDE.md',
    'CONTRIBUTING.md',
    'CONTRIBUTING.pt-BR.md',
    'ROADMAP.md',
    'RELEASING.md',
    'README.md',
    'README.en.md',
    'README.pt-BR.md',
    'funnelsheet-logo.png',
    '*.pot',
    'GTM-*.json',
    'gtm-starter-kit.json',
    'build-starter-kit.py',
    'class-gtm-lead-magnet.php'
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

Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $entries = @($archive.Entries | ForEach-Object { $_.FullName.Replace('\', '/') })
} finally {
    $archive.Dispose()
}

$runtimeManifest = "$pluginSlug/config/feature-registry.json"
$testManifest = "$pluginSlug/config/feature-test-matrix.json"
if (($entries -notcontains $runtimeManifest) -or ($entries -contains $testManifest)) {
    Remove-Item -Path $zipPath -Force
    Remove-Item -Path $tempRoot -Recurse -Force
    throw 'Archive manifest validation failed'
}

Remove-Item -Path $tempRoot -Recurse -Force

Write-Output "ZIP created: $zipPath"
