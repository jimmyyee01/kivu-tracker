# KIVU Tracker — Android client

The phone app for this platform. It is a **thin client**: it opens the same
console your desktop browsers use, and it can optionally turn the phone itself
into a tracker that reports its own GPS to your server.

| | |
|---|---|
| Package | `com.kivutracker.app` |
| Version | `1.0.0` (`versionCode 1` — see `app/build.gradle`) |
| Android | 6.0 (API 23) and newer, target API 34 |
| Dependencies | **none** (no AndroidX, no third-party libraries) |
| Built file | `public/download/kivu-tracker.apk` — served as `/download/kivu-tracker.apk` |

## What it does

* **Console mode** — a WebView shell for `<server>/app`: live map, units, trips,
  reports, geofences. The server address is asked once and remembered
  (SharedPreferences); javascript, DOM storage, cookies and downloads
  (reports/CSV, the APK itself) are enabled.
* **Tracker mode** — a foreground service (`TrackerService`) that posts the
  phone's position every *N* seconds to the platform's public ingest endpoint,
  exactly like a hardware tracker:

  ```json
  POST /api/ingest
  { "unique_id": "<unit unique id>", "password": "<device password>",
    "points": [ { "ts": "2026-10-15T15:30:00+0200", "lat": -1.29, "lon": 36.82,
                  "speed": 43.2, "course": 187, "alt": 1660, "accuracy": 8 } ] }
  ```

  Create the unit first under **Administration → Units** (hardware type
  `phone` — it is in the super console's *Supported devices* list), copy its
  unique ID and device password into the app, then *⋮ → Start tracker mode*.
  The notification bar shows what happened to the last fix
  (`stored +1 at 15:30:12`, `server said 401 …`, `offline — retrying next fix`).
* Theme, dark/light policy, map style and the offline map fallback all come from
  the platform, so the app follows whatever the super admin configured.

## Building the APK

The repository ships **source only** (no binary in version control), so the APK
is compiled on a machine that has the Android toolchain. There is no hidden
dependency: everything is framework APIs.

### 1. Prerequisites

```bash
# JDK 17 (Android Gradle Plugin 8.x needs it)
winget install EclipseAdoptium.Temurin.17.JDK      # Windows
sudo apt install openjdk-17-jdk                    # Debian/Ubuntu
brew install openjdk@17                            # macOS

# Android SDK (command-line tools) + the platform and build tools we compile against
sdkmanager "platform-tools" "platforms;android-34" "build-tools;34.0.0"
export ANDROID_HOME=/path/to/android-sdk           # Windows: setx ANDROID_HOME ...

# Gradle 8.x
winget install Gradle.Gradle                       # or download gradle-8.x-bin.zip
```

### 2. One command

```powershell
# Windows (from the repository root)
powershell -ExecutionPolicy Bypass -File android\build-apk.ps1
```

```bash
# Linux / macOS
bash android/build-apk.sh
```

Both scripts locate Java/SDK/Gradle, write `android/local.properties` for you,
build, copy the package to `public/download/kivu-tracker.apk` and print the
SHA-256. Add `-DebugBuild` (PowerShell) or remove `keystore.properties`
to build an installable debug-signed package instead.

### 3. Signing a release build

```bash
keytool -genkeypair -v -keystore android/kivu-release.jks \
  -alias kivu -keyalg RSA -keysize 2048 -validity 10000
```

`android/keystore.properties` (never commit it — it is in `.gitignore`):

```properties
storeFile=kivu-release.jks
storePassword=********
keyAlias=kivu
keyPassword=********
```

## Publishing it on the platform

The **download page is already wired** — you only have to place the file:

1. Copy the built APK to `public/download/kivu-tracker.apk`
   (the build scripts do this for you).
2. Open `/download` — the page reads `/api/app`, so it immediately shows the
   version, size, SHA-256 and a working **Download** button.
3. Optionally set the release notes and version in the super console
   (**Accounts → Platform settings**): `apk_version`, `apk_notes`, and
   `apk_url` if you would rather host the APK somewhere else
   (any `http(s)://` URL wins over the local file).
4. Users reach it from the top bar — **Get the app** — on every console page,
   and from the public landing page button.

`/api/app` is public on purpose (a phone must be able to fetch the download
link before signing in) and it never exposes secrets.

## Notes

* `android:usesCleartextTraffic="true"` is deliberate: trackers (and Wialon IPS
  itself) are usually served over plain http on a LAN/VPN. Remove it in
  `app/src/main/res/xml/network_security_config.xml` if your server is always
  https.
* Tracker mode asks for the location permission only when you start it.
* Bump `versionCode` **and** `versionName` in `app/build.gradle` for every
  release, then re-run the build script — the download page picks the version up
  from the setting you fill in (or from `1.0.0` otherwise).
