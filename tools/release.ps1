<#
.SYNOPSIS
    Publishes this plugin to the WordPress.org SVN repository.

.DESCRIPTION
    Copies the plugin files from this Git repository into the SVN working copy,
    creates the tag directory when the version is new, shows exactly what would
    be published, and commits after confirmation.

    Nothing is published until the diff has been shown and confirmed.

.EXAMPLE
    .\tools\release.ps1 -Message "Bump tested up to 7.1" -DryRun
    Shows what would be published and stops.

.EXAMPLE
    .\tools\release.ps1 -Message "Bump tested up to 7.1"
    Shows the diff, asks for confirmation, then commits.
#>

[CmdletBinding()]
param(
    # Commit message for WordPress.org.
    [Parameter(Mandatory = $true)]
    [string]$Message,

    # SVN working copy. Defaults to a sibling of this repository.
    [string]$SvnPath,

    # Show what would be published, then stop without committing.
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

$repo = Split-Path -Parent $PSScriptRoot
$plugin = Join-Path $repo 'infopeak-captcha'
if (-not $SvnPath) { $SvnPath = Join-Path (Split-Path -Parent $repo) 'infopeak-captcha-svn' }

function Fail($text) { Write-Host "FAILED: $text" -ForegroundColor Red; exit 1 }
function Step($text) { Write-Host "`n== $text" -ForegroundColor Cyan }

if (-not (Get-Command svn -ErrorAction SilentlyContinue)) {
    Fail "The 'svn' command was not found. Install a Subversion client and make sure it is on PATH."
}

# --- Read the version from all three places and make sure they agree ----------

Step "Checking the version number"

$php = Get-Content (Join-Path $plugin 'infopeak-captcha.php') -Raw
$readme = Get-Content (Join-Path $plugin 'readme.txt') -Raw

$versions = [ordered]@{
    'Version: header'    = [regex]::Match($php, '(?m)^\s*\*\s*Version:\s*(.+?)\s*$').Groups[1].Value
    'IPKCAP_VERSION'     = [regex]::Match($php, "IPKCAP_VERSION'\s*,\s*'(.+?)'").Groups[1].Value
    'readme Stable tag'  = [regex]::Match($readme, '(?m)^Stable tag:\s*(.+?)\s*$').Groups[1].Value
}

foreach ($k in $versions.Keys) {
    if (-not $versions[$k]) { Fail "Could not read the version from: $k" }
    Write-Host ("  {0,-20} {1}" -f $k, $versions[$k])
}

$version = $versions['Version: header']
if (($versions.Values | Select-Object -Unique).Count -ne 1) {
    Fail "The three version numbers above do not match. Fix them before releasing."
}

# --- Make sure the Git side is committed --------------------------------------

Step "Checking this repository"

$dirty = & git -C $repo status --porcelain
if ($dirty) {
    Write-Host $dirty
    Fail "There are uncommitted changes. Commit them in Git first, so the released files are the ones in history."
}
Write-Host "  Working tree is clean."

# --- Get the SVN working copy up to date --------------------------------------

if (-not (Test-Path (Join-Path $SvnPath '.svn'))) {
    Step "Checking out the WordPress.org repository into $SvnPath"
    & svn checkout https://plugins.svn.wordpress.org/infopeak-captcha/ $SvnPath
    if ($LASTEXITCODE -ne 0) { Fail "svn checkout failed." }
} else {
    Step "Updating the SVN working copy"
    & svn update $SvnPath
    if ($LASTEXITCODE -ne 0) { Fail "svn update failed." }
}

$trunk = Join-Path $SvnPath 'trunk'
$tag = Join-Path $SvnPath "tags\$version"

# --- Copy the plugin files in --------------------------------------------------

Step "Copying the plugin files into trunk"

$files = @('infopeak-captcha.php', 'readme.txt', 'uninstall.php')
foreach ($f in $files) {
    Copy-Item (Join-Path $plugin $f) (Join-Path $trunk $f) -Force
    Write-Host "  trunk\$f"
}

if (Test-Path $tag) {
    Step "Updating the existing tag directory tags\$version"
    foreach ($f in $files) {
        Copy-Item (Join-Path $plugin $f) (Join-Path $tag $f) -Force
        Write-Host "  tags\$version\$f"
    }
} else {
    Step "Creating the new tag directory tags\$version from trunk"
    & svn copy $trunk $tag
    if ($LASTEXITCODE -ne 0) { Fail "svn copy failed." }
}

# Pick up any file that is new to SVN.
& svn add --force --quiet (Join-Path $SvnPath 'trunk') (Join-Path $SvnPath 'tags') | Out-Null

# --- Show exactly what would be published --------------------------------------

Step "This is what would be published"

$status = & svn status $SvnPath
if (-not $status) {
    Write-Host "  Nothing changed. WordPress.org already has these files." -ForegroundColor Yellow
    exit 0
}
Write-Host ($status -join "`n")

Write-Host ""
& svn diff $SvnPath

# --- Commit --------------------------------------------------------------------

if ($DryRun) {
    Step "Dry run, nothing was published"
    Write-Host "  Run the same command without -DryRun to publish."
    exit 0
}

Step "Publish?"
Write-Host "  This goes live for every user immediately. There is no undo." -ForegroundColor Yellow
$answer = Read-Host "  Type 'yes' to publish"
if ($answer -ne 'yes') {
    Write-Host "`n  Cancelled. Nothing was published." -ForegroundColor Yellow
    Write-Host "  The copied files are still in the SVN working copy; run 'svn revert -R $SvnPath' to discard them."
    exit 0
}

Step "Publishing"
& svn commit $SvnPath -m $Message
if ($LASTEXITCODE -ne 0) { Fail "svn commit failed. Nothing was published." }

Step "Done"
Write-Host "  Live at https://wordpress.org/plugins/infopeak-captcha/"
Write-Host "  Verify with: svn cat https://plugins.svn.wordpress.org/infopeak-captcha/tags/$version/readme.txt"
