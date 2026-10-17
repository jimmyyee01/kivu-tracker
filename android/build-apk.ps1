# =====================================================================
#  KIVU Tracker — Android build helper (Windows PowerShell)
#
#    powershell -ExecutionPolicy Bypass -File android\build-apk.ps1
#
#  Produces public\download\kivu-tracker.apk (the file the /download page
#  and the top-bar "Get the app" link hand out) and prints its SHA-256.
#  Needs: JDK 17+, Android SDK (platform-tools/build-tools/platform 34) and
#  Gradle 8.x — see android/README.md for the one-line install commands.
# =====================================================================
param(
    [string]$Gradle = '',
    [switch]$DebugBuild
)
$ErrorActionPreference = 'Stop'
$androidDir = $PSScriptRoot
$projectDir = Split-Path $androidDir -Parent
$outDir = Join-Path $projectDir 'public\download'
$apkName = 'kivu-tracker.apk'

function Fail($msg) {
    Write-Host ''
    Write-Host "  $msg" -ForegroundColor Yellow
    Write-Host '  See android/README.md for the exact install commands.' -ForegroundColor Yellow
    exit 2
}

# ---------------------------------------------------------------- java ----
$java = $null
if ($env:JAVA_HOME -and (Test-Path (Join-Path $env:JAVA_HOME 'bin\java.exe'))) {
    $java = Join-Path $env:JAVA_HOME 'bin\java.exe'
} else {
    $j = Get-Command java -ErrorAction SilentlyContinue
    if ($j) { $java = $j.Source }
}
if (-not $java) { Fail 'Java not found. Install a JDK 17 (e.g. "winget install EclipseAdoptium.Temurin.17.JDK").' }
Write-Host "  java: $java"

# ------------------------------------------------------------------ sdk ---
$sdk = if ($env:ANDROID_HOME) { $env:ANDROID_HOME }
       elseif ($env:ANDROID_SDK_ROOT) { $env:ANDROID_SDK_ROOT }
       elseif (Test-Path (Join-Path $env:LOCALAPPDATA 'Android\Sdk')) { Join-Path $env:LOCALAPPDATA 'Android\Sdk' }
       else { '' }
if (-not $sdk) {
    Fail 'Android SDK not found. Set ANDROID_HOME (or install the SDK into %LOCALAPPDATA%\Android\Sdk).'
}
Write-Host "  android sdk: $sdk"
$localProps = Join-Path $androidDir 'local.properties'
"sdk.dir=$($sdk -replace '\\','\\')" | Set-Content -Encoding ASCII $localProps
Write-Host "  wrote $localProps"

# --------------------------------------------------------------- gradle ---
$gradleCmd = $null
if ($Gradle) {
    $gradleCmd = $Gradle
} elseif (Test-Path (Join-Path $androidDir 'gradlew.bat')) {
    $gradleCmd = Join-Path $androidDir 'gradlew.bat'
} else {
    $g = Get-Command gradle -ErrorAction SilentlyContinue
    if ($g) { $gradleCmd = $g.Source }
}
if (-not $gradleCmd) {
    Fail 'Gradle not found. Install Gradle 8.x (e.g. "winget install Gradle.Gradle") or pass -Gradle <path>.'
}
Write-Host "  gradle: $gradleCmd"

# ---------------------------------------------------------------- build ---
$task = if ($DebugBuild) { 'assembleDebug' } else { 'assembleRelease' }
if (-not (Test-Path (Join-Path $androidDir 'keystore.properties'))) {
    Write-Host '  no keystore.properties -> building a DEBUG-signed package (installable, not for release)' -ForegroundColor Yellow
    $task = 'assembleDebug'
}
Push-Location $androidDir
try {
    & $gradleCmd $task --no-daemon
    if ($LASTEXITCODE -ne 0) { Fail "Gradle failed with exit code $LASTEXITCODE." }
} finally { Pop-Location }

# --------------------------------------------------------------- publish --
$candidates = Get-ChildItem -Path (Join-Path $androidDir 'app\build\outputs\apk') -Recurse -Filter *.apk |
    Where-Object { $_.Name -notlike '*unaligned*' } | Sort-Object LastWriteTime -Descending
if (-not $candidates) { Fail 'No APK produced — check the Gradle output above.' }
$apk = $candidates | Select-Object -First 1
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir | Out-Null }
Copy-Item $apk.FullName (Join-Path $outDir $apkName) -Force

$hash = (Get-FileHash (Join-Path $outDir $apkName) -Algorithm SHA256).Hash.ToLower()
$size = [math]::Round((Get-Item (Join-Path $outDir $apkName)).Length / 1MB, 2)
Write-Host ''
Write-Host "  APK published: public\download\$apkName  ($size MB)" -ForegroundColor Green
Write-Host "  sha256: $hash"
Write-Host "  download page: http://<your-server>/download"
Write-Host "  set apk_version / apk_notes in the super console (Platform settings) so users see them."
