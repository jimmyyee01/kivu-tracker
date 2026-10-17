#!/usr/bin/env bash
# =====================================================================
#  KIVU Tracker — Android build helper (Linux / macOS)
#
#    bash android/build-apk.sh
#
#  Produces public/download/kivu-tracker.apk (what the /download page and
#  the top-bar "Get the app" link hand out) and prints its SHA-256.
#  Needs JDK 17+, the Android SDK (platform 34) and Gradle 8.x — see
#  android/README.md.
# =====================================================================
set -euo pipefail
android_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_dir="$(dirname "$android_dir")"
out_dir="$project_dir/public/download"
apk_name="kivu-tracker.apk"

fail() { echo; echo "  $1" >&2; echo "  See android/README.md for the exact install commands." >&2; exit 2; }

command -v java >/dev/null 2>&1 || fail "Java not found. Install a JDK 17 (apt install openjdk-17-jdk / brew install openjdk@17)."
sdk="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-}}"
[ -n "$sdk" ] || fail "Android SDK not found. Export ANDROID_HOME=/path/to/android-sdk."
echo "  java: $(command -v java)"
echo "  android sdk: $sdk"
echo "sdk.dir=$sdk" > "$android_dir/local.properties"

if [ -x "$android_dir/gradlew" ]; then
  gradle_cmd="$android_dir/gradlew"
elif command -v gradle >/dev/null 2>&1; then
  gradle_cmd="gradle"
else
  fail "Gradle not found. Install Gradle 8.x or run inside Android Studio."
fi
echo "  gradle: $gradle_cmd"

task="assembleRelease"
if [ ! -f "$android_dir/keystore.properties" ]; then
  echo "  no keystore.properties -> building a DEBUG-signed package (installable, not for release)"
  task="assembleDebug"
fi

( cd "$android_dir" && "$gradle_cmd" "$task" --no-daemon )

apk="$(find "$android_dir/app/build/outputs/apk" -name '*.apk' ! -name '*unaligned*' -printf '%T@ %p\n' 2>/dev/null \
      | sort -rn | head -1 | cut -d' ' -f2-)"
[ -n "$apk" ] || fail "No APK produced — check the Gradle output above."
mkdir -p "$out_dir"
cp -f "$apk" "$out_dir/$apk_name"

echo
echo "  APK published: public/download/$apk_name  ($(du -h "$out_dir/$apk_name" | cut -f1))"
if command -v sha256sum >/dev/null 2>&1; then echo "  sha256: $(sha256sum "$out_dir/$apk_name" | cut -d' ' -f1)"
else echo "  sha256: $(shasum -a 256 "$out_dir/$apk_name" | cut -d' ' -f1)"; fi
echo "  download page: http://<your-server>/download"
echo "  set apk_version / apk_notes in the super console (Platform settings)."
