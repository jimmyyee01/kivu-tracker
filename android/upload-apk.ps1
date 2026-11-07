# =====================================================================
#  KIVU Tracker - upload the freshly built APK to the server
#
#     powershell -ExecutionPolicy Bypass -File android\upload-apk.ps1
#
#  Pushes  public\download\kivu-tracker.apk  to
#     <user>@<server>:/home/administrator/kivu-tracker/public/download/
#
#  Uses PuTTY pscp when present (falls back to Windows OpenSSH scp).
#  You type the SSH password when asked; nothing is stored. Edit the
#  defaults below - or pass -Server/-Port/-User - to match your SSH path.
#  First connection also asks to cache the host key (answer y).
#
#  Check first without uploading:   ... upload-apk.ps1 -Probe
# =====================================================================
param(
    [string]$Server    = '108.181.196.187',   # SSH address of the VPS (edit if yours differs)
    [int]   $Port      = 22,                  # SSH port (use your mapped port if NAT'd)
    [string]$User      = 'administrator',
    [string]$RemoteDir = '/home/administrator/kivu-tracker/public/download/',
    [string]$Apk       = '',
    [switch]$Probe     # print the plan and exit without uploading
)
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
if (-not $Apk) { $Apk = Join-Path $root 'public\download\kivu-tracker.apk' }

if (-not (Test-Path $Apk)) {
    Write-Host "  APK not found: $Apk" -ForegroundColor Red
    Write-Host '  Run android\build-apk.ps1 first.' -ForegroundColor Yellow
    exit 2
}
$size = [math]::Round((Get-Item $Apk).Length / 1MB, 2)
$hash = (Get-FileHash $Apk -Algorithm SHA256).Hash.ToLower()

# pick an uploader: PuTTY pscp first, then Windows OpenSSH scp
$tool = $null; $kind = ''
foreach ($c in @(
        (Join-Path $env:ProgramFiles 'PuTTY\pscp.exe'),
        (Join-Path ${env:ProgramFiles(x86)} 'PuTTY\pscp.exe'),
        (Join-Path $env:ProgramFiles 'PuTTY\scp.exe'))) {
    if (Test-Path $c) { $tool = $c; $kind = 'pscp'; break }
}
if (-not $tool) {
    $c = Get-Command scp -ErrorAction SilentlyContinue
    if ($c) { $tool = $c.Source; $kind = 'scp' }
}
if (-not $tool) {
    Write-Host '  No uploader found - install PuTTY (pscp) or enable Windows OpenSSH.' -ForegroundColor Red
    exit 2
}

$dest = "${User}@${Server}:${RemoteDir}"
Write-Host "  apk     : $Apk"
Write-Host "  size    : $size MB"
Write-Host "  sha256  : $hash"
Write-Host "  uploader: $tool ($kind)"
Write-Host "  target  : $dest"

if ($Probe) { Write-Host '  (probe mode - not uploading)'; exit 0 }

if ($kind -eq 'pscp') { & $tool -P $Port $Apk $dest }
else                  { & $tool -P $Port $Apk $dest }
if ($LASTEXITCODE -ne 0) {
    Write-Host "  upload failed (exit $LASTEXITCODE)" -ForegroundColor Red
    exit $LASTEXITCODE
}

Write-Host ''
Write-Host '  UPLOAD OK' -ForegroundColor Green
Write-Host "  verify on the VPS:   sha256sum ${RemoteDir}kivu-tracker.apk"
Write-Host "  compare with:        $hash"
Write-Host '  quick web check:     curl -sI http://<server>:10060/download/kivu-tracker.apk'
