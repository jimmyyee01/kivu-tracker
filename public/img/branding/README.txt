Uploaded platform logos live here
=================================

Files are created by the super console (**Accounts → Platform settings →
Platform logo**) through POST /api/settings/logo and named
logo-<sha256-prefix>.<png|jpg|webp|gif|svg>.

They are served like any other static asset at /img/branding/… and are
referenced by settings.logo_url (the same setting also accepts any external
http(s) URL, in which case no file is stored here).

The old file is deleted automatically when a logo is replaced or removed.

Keep this folder writable by the web server user. Do not put anything else in
it — DELETE /api/settings/logo only ever removes files it created (matching
logo-*, and never outside this folder).
