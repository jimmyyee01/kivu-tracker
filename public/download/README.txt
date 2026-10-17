Where the Android package goes
==============================

Drop the built file here, exactly with this name:

    public/download/kivu-tracker.apk

The /download page and the top-bar "Get the app" link serve it from
/download/kivu-tracker.apk and read the version/size/SHA-256 through the
public /api/app endpoint.

Build it with (see ../android/README.md for prerequisites):

    powershell -ExecutionPolicy Bypass -File android\build-apk.ps1     # Windows
    bash android/build-apk.sh                                          # Linux/macOS

Prefer hosting the APK somewhere else (a CDN, Play-like mirror, object
storage)? Set apk_url (and apk_version / apk_notes) in the super console under
Platform settings — any http(s):// address wins over this folder.

This folder contains no binary in version control on purpose: the app is
compiled from android/ so the source always matches the published package.
