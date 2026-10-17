# =====================================================================
#  KIVU Tracker — publish.ps1
#  One-shot script to push this project to GitHub.
#  Usage:  powershell -ExecutionPolicy Bypass -File publish.ps1 [-Remote <url>] [-Message "…"]
#
#  Rebrand note: this project was called "wialon-clone" before the rename, so the
#  default remote (and the local folder) changed. If your GitHub repository still
#  carries the old name, either rename it on GitHub or publish with the old URL:
#     .\publish.ps1 -Remote https://github.com/jimmyyee01/wialon-clone.git
# =====================================================================
param(
  [string]$Remote  = 'https://github.com/jimmyyee01/kivu-tracker.git',
  [string]$Message = 'KIVU Tracker: IPS tracking server, monitoring console, reports, multi-account admin'
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
Set-Location $root

if (-not (Test-Path (Join-Path $root '.git'))) {
  git init
  Write-Host 'Initialised a new git repository.'
}

git add -A
git -c user.name="$env:USERNAME" -c user.email="$env:USERNAME@example.com" commit -m $Message
if ($LASTEXITCODE -ne 0) { Write-Host 'Nothing new to commit (working tree clean).' }

git branch -M main

$existing = (git remote 2>$null)
if ($existing -notcontains 'origin') {
  git remote add origin $Remote
  Write-Host "Added remote origin -> $Remote"
} else {
  git remote set-url origin $Remote
}

Write-Host 'Pushing to origin/main …'
git push -u origin main

Write-Host ''
Write-Host 'Done. Remember: data/ is git-ignored, so no runtime logs are published.'
Write-Host 'On the server you just cloned: run "php install.php --fresh" once, then start php/tracker.php.'
