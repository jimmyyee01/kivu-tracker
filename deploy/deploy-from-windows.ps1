<#
=====================================================================
 KIVU Tracker — deploy/deploy-from-windows.ps1
 Packages this working copy and deploys it to the VPS in one command.

   powershell -ExecutionPolicy Bypass -File deploy\deploy-from-windows.ps1
   powershell -ExecutionPolicy Bypass -File deploy\deploy-from-windows.ps1 -SetupKey
   powershell -ExecutionPolicy Bypass -File deploy\deploy-from-windows.ps1 -BuildOnly
   powershell -ExecutionPolicy Bypass -File deploy\deploy-from-windows.ps1 -NoRestart -NoMigrate

 What it does
   1. builds a .tar.gz of the project (git, runtime data, APKs excluded)
   2. copies it + deploy/remote-deploy.sh to /tmp on the VPS (scp)
   3. runs remote-deploy.sh there: backup -> syntax check -> copy ->
      permissions -> schema top-up -> restart kivu-web/kivu-ips -> verify

 -SetupKey installs your public key in the VPS authorized_keys so the
 next deploys run without a password (one password prompt, once).

 The VPS account needs sudo for `systemctl restart`; the script asks for
 the password interactively when sudo is not passwordless.
=====================================================================
#>
[CmdletBinding()]
param(
  [string]$VpsHost      = '108.181.196.187',
  [int]   $SshPort      = 10051,
  [string]$VpsUser      = 'administrator',
  [string]$RemoteAppDir = '/home/administrator/kivu-tracker',
  [switch]$SetupKey,
  [switch]$NoRestart,
  [switch]$NoMigrate,
  [switch]$DeleteInstaller,
  [switch]$InstallUnits,
  [switch]$BuildOnly
)

$ErrorActionPreference = 'Stop'
$root   = Split-Path -Parent $PSScriptRoot          # project root (parent of deploy\)
$target = "$VpsUser@$VpsHost"
$sshOpt = @('-p', "$SshPort", '-o', 'ConnectTimeout=15')

function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Ok($msg)   { Write-Host "    ok   $msg" -ForegroundColor Green }
function Warn($msg) { Write-Host "    warn $msg" -ForegroundColor Yellow }

# ---------------------------------------------------------------- sanity
foreach ($f in @('php\config.php', 'database.sql', 'tools\php-router.php', 'deploy\remote-deploy.sh')) {
  if (-not (Test-Path (Join-Path $root $f))) { throw "not found: $f - run this from inside the project" }
}
$tarExe = (Get-Command tar.exe -ErrorAction SilentlyContinue).Source
if (-not $tarExe) { throw 'tar.exe not found (Windows 10+ ships it as C:\Windows\System32\tar.exe)' }

# ------------------------------------------------------- optional SSH key
if ($SetupKey) {
  Step "Installing your public key on $target (passwordless deploys from now on)"
  $pub = Join-Path $env:USERPROFILE '.ssh\id_ed25519.pub'
  if (-not (Test-Path $pub)) {
    Warn "no $pub yet"
    Write-Host '    Create the key first (press Enter for an empty passphrase):'
    Write-Host '        ssh-keygen -t ed25519'
    Write-Host '    then run this script again with -SetupKey'
    return
  }
  $key = (Get-Content $pub -Raw).Trim()
  $cmd = "mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && " +
         "grep -qxF '$key' ~/.ssh/authorized_keys || echo '$key' >> ~/.ssh/authorized_keys; " +
         "chmod 600 ~/.ssh/authorized_keys; echo KEY_OK"
  & ssh @sshOpt -o StrictHostKeyChecking=accept-new $target $cmd
  if ($LASTEXITCODE -ne 0) { throw 'key setup failed' }
  Ok 'public key installed - the next deploy will not ask for a password'
}

# ------------------------------------------------------------ 1. package
Step 'Packaging the project'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$tar   = Join-Path $env:TEMP "kivu-tracker-$stamp.tar.gz"
$excludes = @(
  './.git', './data', './.vscode', './.idea', './node_modules',
  './android/.gradle', './android/app/build', './android/local.properties',
  './android/keystore.properties', './deploy/_build',
  './public/download/*.apk', './install.php.removed'
)
$tarArgs = @('-czf', $tar) + ($excludes | ForEach-Object { "--exclude=$_" }) + @('-C', $root, '.')
& $tarExe @tarArgs
if ($LASTEXITCODE -ne 0) { throw 'packaging failed' }
$size = [math]::Round((Get-Item $tar).Length / 1MB, 2)
Ok "$tar ($size MB)"

# sanity: the archive must carry the app and must not carry data/ or .git/
$list = & $tarExe -tzf $tar
if (-not ($list -contains './php/config.php')) { throw 'archive is missing php/config.php' }
foreach ($forbidden in @('./data/', './.git/')) {
  if ($list -match [regex]::Escape($forbidden)) { throw "archive unexpectedly contains $forbidden" }
}
Ok 'archive contents look right (no .git, no runtime data)'

if ($BuildOnly) {
  Write-Host "`nBuild-only: nothing was uploaded." -ForegroundColor Cyan
  Write-Host "Tarball: $tar"
  return
}

# -------------------------------------------------------------- 2. upload
Step "Uploading to $target"
$remoteTar = "/tmp/kivu-tracker-$stamp.tar.gz"
& scp -P $SshPort -o ConnectTimeout=15 (Join-Path $root 'deploy\remote-deploy.sh') "${target}:/tmp/remote-deploy.sh"
if ($LASTEXITCODE -ne 0) { throw 'scp of remote-deploy.sh failed' }
& scp -P $SshPort -o ConnectTimeout=15 $tar "${target}:$remoteTar"
if ($LASTEXITCODE -ne 0) { throw 'scp of the tarball failed' }
Ok "uploaded to $remoteTar"

# --------------------------------------------------------------- 3. deploy
Step 'Running the remote deploy'
$flags = @()
if ($NoRestart)       { $flags += '--no-restart' }
if ($NoMigrate)       { $flags += '--no-migrate' }
if ($DeleteInstaller) { $flags += '--delete-installer' }
if ($InstallUnits)    { $flags += '--install-units' }
$remote = "KIVU_APP_DIR='$RemoteAppDir' bash /tmp/remote-deploy.sh $remoteTar $($flags -join ' ')"
# -t gives sudo a terminal for the systemctl restart password prompt
& ssh -t @sshOpt $target $remote
$code = $LASTEXITCODE
if ($code -ne 0) { throw "remote deploy exited with $code" }

Write-Host "`nDeployed." -ForegroundColor Green
Write-Host "  console : http://$VpsHost`:10060/app"
Write-Host "  health  : http://$VpsHost`:10060/api/meta"
Write-Host "  ips     : tcp://$VpsHost`:20332"
Write-Host "`nRoll back with the backup printed above:"
Write-Host "  ssh -p $SshPort $target `"tar -xzf ~/kivu-backups/<file>.tar.gz -C $RemoteAppDir && sudo systemctl restart kivu-web kivu-ips`""
