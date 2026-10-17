# KIVU Tracker — GPS tracking &amp; fleet management platform

A complete, self-hosted telematics platform: a real **Wialon IPS 1.1 TCP
server** for trackers, live monitoring, track playback, trip/parking detection, geofences with
enter-exit events, notification rules, fuel analysis, twelve report types with CSV export and
printing, multi-account resources with four roles and an audit trail — with **no third-party
runtime dependencies**.

**Stack:** PHP 8.0+ (PDO MySQL) · MySQL / MariaDB · vanilla JavaScript (no build step, no npm).
Developed and tested on **XAMPP (PHP 8.2 + MariaDB 10.4)** on Windows.

---

## Quick start

```bash
# 1. Serve the console. Apache/XAMPP works out of the box (.htaccess is included);
#    for a quick local test use PHP's own server with the bundled router:
php -S 127.0.0.1:8099 -t "C:\path\to\kivu-tracker" "C:\path\to\kivu-tracker\tools\php-router.php"

# 2. Create the schema and the demo fleet (once; --fresh wipes and reseeds)
php install.php --fresh            # also works from a browser: /install.php

# 3. Start the Wialon IPS device server (TCP, default port 20332)
php php/tracker.php --verbose

# 4. Stream a live track from the bundled device simulator
php tools/simulator.php 356938035643811 --minutes=5 --interval=5 --verbose

# 5. Run the end-to-end API test suite (389 checks) and the gateway suite
php tools/api-test.php http://127.0.0.1:8099
php tools/payments-test.php http://127.0.0.1:8099

# 6. Optional: build the Android client and publish it for /download
powershell -ExecutionPolicy Bypass -File android\build-apk.ps1   # Windows
bash android/build-apk.sh                                        # Linux / macOS
```

Then open `http://127.0.0.1:8099/` — the landing page links to every console:

| URL | What it is |
|---|---|
| `/app` | **Monitoring console** — unit tree, live map, track playback, trips, events, messages, commands |
| `/reports` | **Report builder &amp; viewer** — 12 report types, charts, CSV export, printable |
| `/admin` | **Administration** — units, sensors, geofences (drawn on the map), notifications, drivers, service, groups, users, device log, audit |
| `/billing` | **Client billing** — current plan/trial, plan grid with every payment method, invoices, billing details |
| `/register` | **Self-service signup** — plan picker, account + admin user, starts the 5-day free trial |
| `/pay/<invoice>` | Simulated ("sandbox") gateway page — used while no live API keys are configured |
| `/super` | **Platform accounts, plans &amp; billing** — accounts, plans, invoices, gateway keys, platform settings, health, audit |
| `/track/<unit-id>` | Focused single-unit page — ideal for a client login restricted to one unit |
| `/download` | **Android app download** — version, size, SHA-256, install steps (public; also in the top bar as *Get the app*) |
| `/login` | Sign in |

### In-app client support
Every page includes a small support button at the lower-right edge. It opens a keyboard-accessible
help panel with quick guidance for tracker setup, user roles, reports, login and billing. The button
uses the configured platform logo when available and keeps the same colors in dark and light themes.
This is a built-in help guide, not a live AI service: questions stay in the browser and are not sent
to an external provider. Users should not enter passwords or other sensitive information in the panel.

### Demo accounts (created by `install.php`)

| Role | Username | Password | Sees |
|---|---|---|---|
| Super administrator | `superadmin` | `SuperAdmin@123` | everything, all accounts |
| Account admin (Acme Logistics) | `admin` | `Admin@123` | all Acme units + users |
| Operator (Acme) | `operator` | `Operator@123` | all Acme units, no user management |
| Viewer (Acme) | `viewer` | `Viewer@123` | **only 2 units**, read-only |
| Second account admin (Safari Tours) | `safari` | `Admin@123` | only that account |

Demo devices log in to the IPS server with the unit's **unique_id** (e.g. `356938035643809`)
and the device password `track`. The installer seeds 8 units (6 for Acme, 2 for Safari Tours),
5 geofences, 10 notification rules, 4 drivers, service intervals, report templates and ~24 h of
realistic telemetry (≈5 800 positions, ≈380 geofence events, ≈210 detected trips) so every
report and screen has data on first login.

---

## Feature map

### Monitoring console (`/app`)
* Unit tree grouped by unit group with status colours (moving / idle / parked /
  offline / no data), live search and a status filter.
* Live map with rotated vehicle markers, popups, follow mode, fit-all, geofence overlay and
  five polling speeds (5/15/30/60 s or off).
* **Track playback**: period presets (today, yesterday, 24 h, 7 d, 30 d) or a custom range,
  polyline, speed-profile chart and a timeline player (play/pause, ×1…×10) that walks a marker
  along the track.
* **Trips / parkings / idle** table with per-row map focus, mileage, duration, max speed, fuel
  estimate and CSV export; “Detect trips in range” re-runs the detector live.
* **Events** log with type/severity filters, acknowledge-all and click-to-locate.
* **Messages** grid with every raw parameter the device sent (speed, ignition, fuel, voltage,
  satellites, source, validity) and CSV export.
* **Commands** tab: queue `custom_message`, `engine_block`, `engine_unblock`, `query_position`,
  `set_interval` or `reboot`; the IPS server delivers them to the device and records the ack
  (a command the device never confirms is marked `failed — timeout (no ack)`).

### Device ingestion — Wialon IPS 1.1 (`php/tracker.php`)
Single-file TCP daemon (streams only — no PHP extensions required):

| Packet | Direction | Handling |
|---|---|---|
| `#L#login;password` | device → server | authenticates against `units.unique_id` + device password → `#AL#1` / `#AL#0` |
| `#D#date;time;lat1;lat2;lon1;lon2;speed;course;alt;sats;params` | device → server | stored as a message → `#AD#1` (the 8-field decimal-degrees variant is accepted too) |
| `#SD#date;time;text` | device → server | stored as a `text_message` event → `#ASD#1` |
| `#P#date;time` | device → server | keep-alive, refreshes the device session → `#AP#1` |
| `#B#…` (black box) | device → server | parsed like `#D#` → `#AB#1` |
| `#M#date;time;text` | server → device | commands and custom messages |
| `#AM#1` / `#AM#0` | device → server | command ack / nack, reflected in the `commands` table |

Every accepted position runs the whole pipeline: GPS-jump filtering, odometer and engine-hour
accumulation, live-state update, geofence enter/exit evaluation, notification edge rules and
retention pruning; “connection lost”, service-due alerts and the trip detector run on the
daemon's minute timer (or on demand from `/api/tasks/run`, so a cron job can drive them instead).

Non-IPS hardware or gateways can post to the HTTP endpoint instead:

```bash
curl -X POST http://127.0.0.1:8099/api/ingest -H 'Content-Type: application/json' -d '{
  "unique_id": "356938035643809", "password": "track",
  "points": [ { "ts": "now", "lat": -1.2965, "lon": 36.8260, "speed": 0, "sats": 9,
                "params": { "ign": 0, "fuel": 118.5, "pwr": 12.6, "temp": 24 } } ]
}'
```

Parameter names are mapped to the canonical columns automatically (`ign`/`ignition`/`acc` →
ignition, `fuel`/`F1`/`fuel_level` → fuel, `pwr`/`voltage`/`vbat` → voltage, `temp`, `hdop`,
`mileage`/`odo`, `eh`, `sos`); everything else is kept verbatim in `messages.params_json`.

### Geofences and notifications (`/admin`)
* Circle (click the centre, then the edge to set the radius), polygon and polyline/route
  geofences with colour, width, description, an optional **speed limit inside** and an enable
  switch — drawn directly on the map, with live area/length read-out.
* Enter/exit events are recorded per unit and feed the *Geofence visits* report (entries, exits,
  time inside, last visit).
* Notification rules: **speed** (optionally geofence-aware), **geofence** enter/exit,
  **movement**, **ignition** on/off, **idle**, **SOS**, **fuel drop / refuelling**, **power
  cut**, **no data / offline** and **service due** — scoped to all units or a selection, with
  severity, a message template (`{unit} {speed} {limit} {address} {geofence} {change} …`) and
  actions: in-console push, **real HTTP webhook (JSON POST via cURL)**, e-mail and SMS
  (recorded in the audit log — see *Notes and limitations*). Each rule has a **Test** button
  that fires a real event and reports the webhook's HTTP status.

### Units, sensors and service (`/admin`)
* Unit fields: name, asset type, device ID, device password, hardware type, SIM, model, plate, VIN, trailer,
  tags, group, driver, fuel consumption and price, cost per km, odometer, engine hours, speed
  limit and the trip-detection thresholds (min trip duration/distance, min parking, parking
  timeout, max GPS jump).
* **Asset types with their own icons.** Registration captures what the fleet tracks (lorry, motorcycle,
  bicycle, car, van, bus, tractor, boat, machine, other) and stores it as the account default; new units
  inherit it, the unit form can override it per asset, and the type drives the icon everywhere — the map
  marker (both the Leaflet and the offline canvas backend, kept upright while the marker rotates with the
  heading), the unit tree and the console tables (a shared glyph catalogue, no image assets, works offline).
* Sensors per unit (fuel level, ignition, voltage, temperature, counter or custom) with device
  parameter name, coefficient, offset, alarm bounds and colour; new units get the four standard
  sensors automatically.
* Service intervals (every N km / engine hours / days) with remaining-distance calculation and
  automatic “service due” alerts.

### Reports (`/reports`)
Twelve report types, all produced by one engine and rendered by one viewer (summary tiles,
tables with totals, canvas charts), exportable to **CSV** and printable to PDF:

`unit_summary` · `trips` · `parkings` · `activity` (by day or hour) · `geofences` · `events` ·
`speed_violations` · `fuel` (fillings and drainings) · `engine_hours` · `mileage` · `drivers` ·
`maintenance`

Any parameter combination can be saved as a **report template** for one-click re-runs.

### Colour-coded dashboards
Every dashboard renders its KPIs through one helper (`tiles()` in `public/js/common.js`), which
gives each metric **its own colour** instead of a wall of identical grey cards: the palette cycles
(`blue`, `green`, `violet`, `amber`, `cyan`, `orange`, `pink`, `teal`, `red`) and a metric can name a
colour (`color: 'teal'`) or state its meaning (`tone: 'ok' | 'warn' | 'danger'`) so status tiles turn
green/amber/red on their own — active accounts green, expired/due red, trials ending amber. The same
classes are used by the landing page, `/billing`, `/super` (billing, financial reports, account
detail, health), `/reports` summaries and the reseller portal, and they are theme-aware (light theme
darkens the values for contrast). Charts already painted their series from a palette
(`public/js/charts.js`). See *Colour-coded dashboards — implementation notes* below for the details.

### Android app (`/download`)
A downloadable phone client, so users can work from the field:
* **Source in `android/`** — plain Android (Java, no third-party libraries): a WebView shell for the
  console plus an optional **tracker mode** that posts the phone's own GPS to `/api/ingest` with the
  same `unique_id` + device password a hardware tracker uses (foreground service, notification with
  the last-fix result, battery-friendly interval).
* **One-command build** — `android\build-apk.ps1` (Windows) or `android/build-apk.sh`
  (Linux/macOS) locate Java/SDK/Gradle, build, copy the package to
  `public/download/kivu-tracker.apk` and print its SHA-256. `android/README.md` documents the
  prerequisites, release signing (`keytool` + `keystore.properties`) and how to bump the version.
* **`/download` page + menu link** — "**Get the app**" sits in the top bar for every signed-in role
  and the landing page links to it too. The page is data-driven from the public `GET /api/app`
  endpoint: version, size, SHA-256, "what's new", Android/package requirements, install steps,
  permissions, the server address to type in, and the ingest URL for tracker mode — plus
  download / copy-link / e-mail-the-link actions. It says so honestly when no package has been
  published yet instead of offering a dead link.
* **Super-admin settings** — `apk_version`, `apk_notes` and `apk_url` (any `http(s)://` address wins
  over the local file, e.g. when the APK lives on a CDN) under *Platform settings*.

### Platform logo (system settings)
The super admin uploads the platform logo in **Accounts → Platform settings → Platform logo**:
* **Upload** a PNG, JPG, WebP, GIF or SVG (up to 512 KB) — or paste any image URL in the
  *Platform logo URL* field below it. A live preview shows the current logo, and *Remove logo*
  goes back to the default CSS mark.
* It appears **everywhere the brand appears**: the top bar of every console (including the
  Android app, which loads the same console), the sign-in, landing, sign-up and payment pages,
  the browser tab (favicon + home-screen icon) and the header of the payment-reminder e-mails.
* Uploaded files are stored under `public/img/branding/` and served like any other static asset
  (with a `?v=<hash>` cache-buster); replacing or removing a logo deletes the previous file, and
  every change is written to the audit log (`platform_logo_set` / `platform_logo_removed`).
* Server-side validation: real image content (`getimagesize`), a strict extension whitelist, the
  512 KB cap, and a refusal for SVGs containing scripts/handlers/iframes.

### Platform footer credit
The super admin can set optional **Powered by (footer)** text in **Accounts → Platform settings**.
It is shown as a small footer credit across public pages and consoles; leave it blank to hide it.
The value is plain text (up to 180 characters), not HTML.

### Platform reports for the super admin (`/super` → *Financial reports*)
Four platform-wide reports, all range-filtered (`?from=…&to=…`) and exportable
(`?format=csv`, downloaded through the authenticated blob helper):

* **Financial report** (`/api/finance/financial`) — collections per currency (collected, outstanding,
  failed, refunded, paid-invoice count), **MRR** derived from the paying accounts (annual plans ÷ 12),
  active/new accounts, reseller-portal revenue, plus the day series (invoiced vs collected),
  the gateway mix and the per-plan mix.
* **Invoicing report** (`/api/finance/invoicing`) — the invoice ledger with account, plan, gateway,
  amount, status, age in days, created/paid timestamps and the failure reason, the totals per status
  and the **ageing buckets** of the unpaid invoices (0-7 / 8-30 / 31-60 / 60+ days).
* **Invoicing alerts** (`/api/finance/alerts?overdue_days=7`) — one call that answers “what needs
  attention now”: overdue invoices (severity rises after 30 days), accounts in the grace window,
  trials ending within 2 days, expired subscriptions, reseller portals that are unpaid / expiring
  within 30 days / expired, failed automatic renewals in the last 7 days and reminders that could not
  be delivered. Each alert carries a `severity`, a `kind`, the referenced invoice/account/portal and the
  amount or days left, so it can be rendered, e-mailed or pushed by a cron job.
* **Performance report** (`/api/finance/performance`) — per account: units (total and online), plan
  utilisation %, positions processed, trips, distance and the revenue collected in the range; plus
  the platform totals (online %, positions per day and per unit/day, trips, kilometres, new accounts).

### Colour-coded dashboards — implementation notes
* The palette lives in `public/js/common.js` (`TILE_COLORS`, `TILE_TONES`, `tileColor()`), the colours in
  `public/css/style.css` (`.tile.k-*`, `.stat.k-*`, one `--kc` RGB triple per colour) — theme-aware
  tints, darker values in the light theme, neutral in print.
* A metric can name its colour (`color: 'teal'`) or state its meaning (`tone: 'ok' | 'warn' | 'danger'`),
  so status tiles are green/amber/red automatically (`Expired / due`, `Offline`, `Database ok`,
  trials ending) while plain counters cycle the palette in order.
* Covered dashboards: landing page, monitoring console, admin console, `/reports` summaries,
  `/billing`, `/super` (billing totals, financial reports, account detail, platform health) and the
  reseller portal (`/reseller` → client accounts, devices managed, licence days left).

### Supported devices & map styles
* **Device catalogue** — `device_catalogue()` describes what the platform speaks out of the box
  (native **Wialon IPS 1.1** TCP daemon, the HTTP/JSON ingest gateway and phone/tablet tracker apps)
  with the protocol, the connection path and example hardware. It is served in `/api/meta`,
  listed in the super console under *System → Supported devices & protocols*, and drives the
  **hardware type** dropdown of the unit form (`hw_type`).
* **Free map styles** — the monitoring console carries a map picker (`MAP_STYLES` in `common.js`)
  with the **platform default** (whatever `settings.map_tiles` is configured to) plus OpenStreetMap,
  OpenTopoMap, OSM Humanitarian, CyclOSM and the two Carto basemaps. The choice is remembered per
  browser (`localStorage`) and applied to both the Leaflet and the offline canvas backend, so the
  default never changes server-side unless the super admin changes the platform tiles.

### Accounts, roles and audit (`/super`)
* Accounts (resources) with plan, unit limit, retention, currency, timezone and
  enable/disable; creating an account can create its first administrator in the same step.
* **Account switcher for super admins.** A platform admin signs in without belonging to an
  account, so the top bar shows an *Account* picker (the resource selector). The choice is
  remembered and every API call is scoped with `?account_id=…`, which lets the super admin use the
  monitoring, administration and reporting consoles inside any account. Account admins never see
  the picker. `/super` explains itself instead of redirecting if a non-super user opens it.
* Four roles: `super` (platform), `admin` (account, including users), `operator` (tracking and
  configuration) and `viewer` (read-only, optionally restricted to a list of units).
* Audit trail of logins, configuration changes, report runs, command queueing, notification
  deliveries and scheduled jobs, plus a device-connection log with packet counts per session.

### Accounts, trials and payments (`/register`, `/billing`, `/super`)
* **Self-service registration online.** `/register` lists the published plans and creates the account, its first
  administrator and a **free trial (default 5 days)** in one step — the client is signed in immediately and lands
  on the console. `settings.signup_enabled` switches public signup off, and signups are rate-limited per IP.
* **Free trial, then a plan.** `accounts.trial_ends_at` + the newest `subscriptions` row decide the state:
  `trialing → active → past_due (7-day grace) → expired`, plus the manual `comp` and `suspended` states.
  While an account is expired, **reads (maps, tracks, reports, CSV) keep working** but every change and every
  device packet is refused with HTTP 402 + `code: payment_required`, and the consoles show an upgrade banner.
* **Plan limits are enforced — but billing stays flexible.** Each plan carries a price, currency, interval,
  unit limit, history window, trial length and an **extra-device price**: a trial account that hits the limit
  gets the 402 upgrade prompt, while a **paid** account on a plan with an extra-device price (per billing
  period) simply keeps adding devices — every checkout and the automatic renewal then invoice
  `plan price + extras × price` (shown on `/billing` as *Extra devices … will be added to your next invoice*,
  spelled out in the checkout message, and recorded in the invoice meta as `extra_units` / `overage`).
  A paid account on a plan whose extra price is 0 still gets a clear 402 ("this plan does not sell extra
  devices"), and a successful payment applies the plan's limits to the account.
* **Payments through the gateway the client prefers** (all hosted pages — no card data ever touches this server):

  | Gateway | What it collects | Notes |
  |---|---|---|
  | `stripe` | Cards: Visa / Mastercard / Amex / Apple & Google Pay | Checkout Session, `Stripe-Signature` verified |
  | `pesapal` | Cards + mobile money across East Africa | v3 API, IPN registered automatically, status re-checked server-side |
  | `mpesa` | M-Pesa **STK push** to the client's phone | Daraja OAuth, whole-shilling KES, callback result verified |
  | `flutterwave` | Cards, mobile money, bank transfer | `verif-hash` header is required when configured |
  | `paypal` | PayPal balance or card | Orders v2 create + capture |
  | `bank` | Manual transfer | invoice stays pending until the super admin confirms it |
  | `sandbox` | Simulated gateway (no money moves) | shipped for demos, training and the test suites |

* **Super-admin billing console** (`/super` → *Plans & billing*): revenue totals (MRR, collected, trials ending),
  every account with its trial/subscription state, plan CRUD, every invoice with *mark paid / failed / refund*,
  and gateway credentials (masked on read, `sandbox`/`live` switch, per-gateway API base URLs).
  **Every payment integration can be activated or deactivated individually** with the *Payments* switch on its
  card: a deactivated gateway disappears from the client billing page and `/api/plans`, and checkouts through it
  are refused with a clear message — regardless of whether its API keys are present.
  Account-level actions: apply plan, extend trial, add paid period, activate, make complimentary, suspend, expire.
* **Payment reminders are e-mailed 14, 5 and 2 days before an account is deactivated** (offsets are
  configurable). The switch, the offsets and the SMTP credentials live in the super console; the sweep runs from
  `/api/tasks/run` (cron) and the IPS daemon's hourly timer, and each notice is sent **once per countdown**
  (a `billing_reminders` row per account/kind/deactivation date, so re-runs and gateway retries can never
  duplicate a mail). Each account gets the band that matches its remaining time (6–14 → 2-week, 3–5 → 5-day,
  1–2 → final), the recipient is the account's contact/billing address with an admin e-mail fallback, and a
  super admin can fire a reminder manually per account. Delivery uses a **built-in SMTP client**
  (STARTTLS/SSL + AUTH LOGIN), PHP `mail()`, or a `log` transport that appends to `data/mail.log` — every
  attempt is journalled in `billing_reminders` and the audit log with its status and error.
* **Billing details are collected right after registration.** The success card points the new client to
  *Billing details* (contact name, billing e-mail, phone, address/city/country); checkout refuses with a
  clear `400` until the name and a valid billing e-mail exist, the billing page shows an "add details now"
  banner while the profile is incomplete, and the super console's subscription table reports the profile
  state per account. Signups already collect name/e-mail/phone, so a fresh registration starts complete.
* **Callbacks are idempotent.** Every callback is journalled in `payment_events` with a unique
  `(gateway, event)` key, and the invoice keeps its gateway reference so a gateway retry (or a replay) can never
  extend a subscription twice. `/api/tasks/run` also sweeps trials/periods into `past_due`/`expired`.

| Tool | Purpose |
|---|---|
| `php-router.php` | router for `php -S` (Apache uses `.htaccess`) |
| `simulator.php` | IPS device emulator: logs in, streams a route, acks commands, `--reset`, `--dec` |
| `gen_history.php` | generates days of historical telemetry (plus trips and events) for existing units |
| `api-test.php` | 389-check end-to-end API suite (auth, roles, CRUD, reports, CSV, ingest, jobs, masks, webhooks, **billing**: gateways, switches, reminders, billing details, automatic renewal, flexible extra devices, asset types, **platform reports**: financial, invoicing, alerts, performance, device catalogue, **Android download**: `/api/app`, `/download`, settings, **platform logo**: upload/replace/remove, exposure, validation) |
| `payments-test.php` | 86-check gateway suite: drives Stripe, Pesapal, M-Pesa, Flutterwave and PayPal against `gateway-stub.php`, plus the saved-method renewal charge |
| `gateway-stub.php` | stand-in for the payment providers (request journal at `/_requests`, STK outcomes at `/_outcome`) |
| `protocol-test.php` | 41-check Wialon IPS protocol suite — starts its own tracker and talks to it as a device |
| `ui-smoke.html` + `ui-smoke.js` | browser-driven UI test: loads the real admin console, clicks **+ New unit**, saves it and checks the result (headless Chrome/Edge instructions inside) |
| `ui-billing.html` + `ui-billing.js` | browser-driven billing test: registers a trial account, pays through the sandbox gateway, checks the billing page |
| `ui-super-billing.html` + `ui-super-billing.js` | browser-driven super console test: opens *Plans & billing*, switches a payment integration off with the per-gateway switch, verifies the offer disappears, switches it back on, then renders the *Financial reports* pane (4 reports + alerts + CSV download), the *Supported devices* list, the colour-coded KPI tiles and the **Get the app** menu link |
| `ui-reseller.html` + `ui-reseller.js` | browser-driven reseller portal test: boots `/reseller` with a reseller session, checks the licence chip and period, the colour-coded portal KPIs, the client-account table (`Account | Status | Plan | Devices | …`) and `/api/reseller/state` |
| `portal-session.php` | provisions the reseller portal the harness needs — `php tools/portal-session.php` prints a reseller token, `--cleanup` removes the portal, its owner login and its invoices again |
| `ui-syntax.html` | browser syntax check of every page script (`new Function(...)` per file), the check that catches errors a bracket counter cannot see |
| `webhook-sink.php` | tiny webhook receiver used by the API suite (webhooks must not target the same `php -S`) |
| `jscheck.php` | dependency-free JavaScript checker (no Node required): bracket balance, unterminated strings/regex **and declarations without an initialiser** (`const x(` → the "Missing initializer" error that silently kills a whole file) |
| `pagecheck.php` | cross-checks each HTML page against the element ids its scripts use |
| `../android/` | the Android client (source: WebView console + tracker mode) — see `android/README.md` |
| `../android/build-apk.ps1`, `../android/build-apk.sh` | one-command APK build; publish the result into `public/download/` |

---

## Architecture

```
kivu-tracker/
├─ install.php               one-time installer / demo seeder (CLI or browser)
├─ database.sql              full schema (every statement is CREATE TABLE IF NOT EXISTS)
├─ .htaccess                 Apache routing (api/, pages, static assets)
├─ php/
│  ├─ config.php             PDO connection, helpers, auth/sessions, schema bootstrap
│  ├─ engine.php             geo maths, offline geocoder, unit state/live shapes
│  ├─ ingest.php             packet -> message -> live state -> geofences -> notifications
│  ├─ notify.php             geofence checks, notification rules, webhooks, scheduled alerts
│  ├─ trips.php              trip/parking/idle detection, fuel events, speed episodes, aggregates
│  ├─ reports.php            the twelve report builders (uniform JSON shape)
│  ├─ ips.php                Wialon IPS 1.1 packet codec (parse and build)
│  ├─ tracker.php            the IPS TCP server daemon
│  ├─ api.php                REST router (+ health, meta, dashboard, tasks, audit, ingest)
│  ├─ routes_auth.php        login/logout/me/password/sessions and user management
│  ├─ routes_units.php       units, groups, sensors, drivers, service, tracks, trips, events, commands
│  ├─ routes_geo.php         geofences (+state, events) and notification rules
│  ├─ routes_reports.php     report catalogue, run, CSV export, templates
│  ├─ routes_admin.php       accounts and platform settings
│  └─ demo.php               gazetteer, demo routes, track generator, demo seeder
├─ public/                   everything the browser loads
│  ├─ index.html login.html app.html admin.html reports.html super.html track.html
│  ├─ css/style.css          the design system (dark, responsive, print styles)
│  └─ js/ common.js mapcore.js charts.js app.js admin.js reports.js super.js track.js login.js
├─ tools/                    dev router, simulator, generators, test suites
└─ data/                     runtime logs (git-ignored)
```

Design decisions worth knowing:

* **One report shape.** Every report returns `{summary, tables, charts}`, so the viewer, the CSV
  writer and the print layout are written once.
* **One ingest path.** The IPS daemon, the HTTP endpoint, the simulator and the demo generator
  all funnel through `ingest_point()`, so anything you exercise in the browser runs exactly the
  production pipeline.
* **Offline geocoder.** `address_of()` resolves coordinates to readable names from the `places`
  table (a seeded gazetteer) with no external API call, falling back to decimal coordinates.
* **Map with a fallback.** `mapcore.js` uses Leaflet when it can be downloaded and otherwise
  switches to a built-in canvas slippy map (tiles, pan/zoom, polylines, polygons, circles,
  markers), so the console keeps working on an isolated network — only the map tiles then need
  connectivity.
* **One process per request.** No queues, workers or cron are strictly required; the IPS daemon
  is the only long-running process and it is optional (the HTTP ingest endpoint plus
  `/api/tasks/run` cover gateways and shared hosting).

---

## REST API

Authentication uses a bearer token: send `X-Auth-Token: <token>` (or `?token=`); obtain it from
`POST /api/auth/login`. Every response is `{"ok":true,...}` or `{"ok":false,"error":"..."}`.
Access levels: `public`, `read` (any signed-in user), `write` (anything but a viewer), `admin`
(account admin), `super` (platform).

| Method and path | Level | Purpose |
|---|---|---|
| `GET /api/health` | public | version, PHP, database, counters, IPS port |
| `POST /api/auth/login` | public | sign in, returns the token |
| `POST /api/auth/logout`, `GET /api/auth/me`, `POST /api/auth/password`, `GET /api/auth/sessions` | read | session utilities |
| `GET /api/meta` | read | units, groups, geofences, report types, settings, permissions |
| `GET /api/dashboard` | read | fleet counters, live units, recent events |
| `GET /api/units/live` | read | live snapshot (the console's polling endpoint) |
| `GET/POST /api/units`, `GET/PUT/DELETE /api/units/<id>` | read/write | unit CRUD (filters: `q`, `status`, `group_id`, `tag`, `enabled`) |
| `POST /api/units/<id>/command` | write | queue a device command |
| `GET /api/units/<id>/messages` | read | track / raw messages (`from`, `to`, `limit`, `valid`) |
| `GET /api/units/<id>/trips`, `POST /api/units/<id>/trips/detect` | read/write | stored trips, run the detector |
| `GET /api/units/<id>/fuel`, `GET /api/units/<id>/speeding` | read | fuel events, speed episodes |
| `GET/POST/PUT/DELETE /api/{groups,drivers,maintenance,sensors…}` | read/write | configuration CRUD |
| `GET /api/geofences`, `POST/PUT/DELETE /api/geofences/<id>`, `GET /api/geofences/<id>/events`, `GET /api/geofence-state` | read/write | geofences, enter/exit log, who is inside now |
| `GET/POST/PUT/DELETE /api/notifications`, `POST /api/notifications/<id>/test` | read/write | rules and a real test firing |
| `GET /api/events`, `POST /api/events/ack` | read/write | event log, acknowledge |
| `GET /api/commands`, `DELETE /api/commands/<id>` | read/write | command queue |
| `GET /api/devices/sessions` | read | device connection log |
| `GET /api/reports/types`, `POST /api/reports/run`, `GET /api/reports/export.csv` | read | reports and CSV export |
| `GET/POST/PUT/DELETE /api/reports/templates` | read/write | saved report templates |
| `GET/POST/PUT/DELETE /api/users` | admin | user management (roles, unit masks) |
| `GET/POST/PUT/DELETE /api/accounts`, `GET /api/accounts/<id>/summary` | super | resources / tenants |
| `GET/PUT /api/settings` | admin/super | platform settings (write is super-only) |
| `GET /api/audit` | admin | audit trail |
| `POST /api/tasks/run` | read | run the scheduled jobs (detector, alerts, pruning, billing renewals, payment reminders) |
| `POST /api/ingest` | public (device credentials) | HTTP position ingest |

### Billing API

| Method and path | Level | Purpose |
|---|---|---|
| `GET /api/plans` | public | plan catalogue + registration settings (used by `/register`) |
| `POST /api/signup` | public | self-service account creation + `trial_days` trial, returns a session token |
| `GET /api/billing` | account admin | current plan, trial/period state, usage, invoices, plans with their usable gateways |
| `POST /api/billing/checkout` | account admin | create the invoice and start a payment (`gateway`, `plan_id`, `phone`) |
| `POST /api/billing/pay/<invoice>/status` | account admin | confirm with the gateway (used after a redirect and while polling an STK push) |
| `POST /api/billing/cancel` | account admin | cancel / re-enable auto-renewal at the end of the period |
| `PUT /api/billing/account` | account admin | billing details (contact, e-mail, phone, address) + the automatic-billing opt-in/out |
| `DELETE /api/billing/methods/<id>` | account admin | remove a saved payment method (renewal falls back to invoice + reminders) |
| `GET /api/pay/<invoice>` | public | invoice view for the simulated gateway page |
| `POST /api/pay/<invoice>/complete` | public | sandbox only: approve / decline / cancel the simulated payment |
| `POST|GET /api/webhooks/<gateway>` | public | gateway callbacks (`stripe`, `pesapal`, `mpesa`, `flutterwave`, `paypal`) |
| `GET /api/billing/overview` | super | accounts with trial/subscription state, revenue totals, invoices and gateway config |
| `GET /api/billing/payments` | super | every invoice (filters: `account_id`, `status`, `gateway`, `q`) |
| `GET /api/billing/reminders` | super | recent payment-reminder e-mails (account, offset, recipient, status, error) |
| `POST /api/billing/payments/<id>` | super | `mark_paid` (bank confirmation), `mark_failed`, `refund`, `cancel` |
| `POST|PUT|DELETE /api/billing/plans[/<id>]` | super | plan CRUD (in-use plans are disabled instead of deleted) |
| `GET|PUT /api/billing/settings` | super | gateway credentials (masked on read), per-gateway on/off switches, reminder switch/offsets, SMTP transport, trial length, signup switch, gateway mode |
| `POST /api/accounts/<id>/billing` | super | `set_plan`, `extend_trial`, `extend_period`, `activate`, `comp`, `suspend`, `expire`, `cancel`, `send_reminder` |

`POST /api/reports/run` accepts `type`, `units` (ids), `from`, `to`, `group` (`day`/`hour`),
`speed_limit`, `min_change`, `severity`, `event_types`. `from`/`to` accept ISO strings,
`Y-m-d H:i:s`, epochs or relative expressions such as `-24 hours` and `now`.

`POST /api/ingest` answers with `stored`, `rejected` and a per-fix `results` array
(`{ts, valid, reason, message_id}`), so a device or gateway can see which fixes were kept and why
one was dropped (`reason: "gps_jump"` for a teleport, out-of-range coordinates, and so on).

### Super-admin account context
An endpoint that needs an account uses the caller's own account; a `super` admin has none, so pass
`?account_id=acc_…` (or `account_id` in the body) whenever the request is about account data. The
web consoles do this automatically from the account switcher, and unit creation without a context
answers `400 Choose the account this unit belongs to (account_id)` instead of failing silently.

### Token in the URL
Every endpoint accepts `?token=<session token>` as well as the `X-Auth-Token` header, and the web
pages adopt a token passed as `?token=…` once and then strip it from the address bar. That is handy
for kiosk/embedded dashboards (`/app?token=…`) and it is how the headless UI test authenticates.

---

## Security model

| Capability | viewer | operator | admin | super |
|---|:--:|:--:|:--:|:--:|
| Read units, tracks, trips, events, reports | ✓ | ✓ | ✓ | ✓ |
| Restricted to selected units (`access_units`) | optional | optional | optional | – |
| Create/edit units, sensors, geofences, rules, drivers, service | – | ✓ | ✓ | ✓ |
| Queue device commands, acknowledge events, run the trip detector | – | ✓ | ✓ | ✓ |
| Manage users, read the audit log, edit account settings | – | – | ✓ | ✓ |
| Create/edit accounts and platform settings, see all accounts | – | – | – | ✓ |

Passwords are hashed with bcrypt (`password_hash`, cost default); sessions are 64-hex random
tokens stored in `sessions` with a 7-day expiry and are invalidated on sign-out, password change
or user deletion. Every request re-checks the account's enabled flag and the unit access mask,
and the API returns 403 (not 404) for masked units so clients can tell the difference.

---

## Testing

```bash
php tools/jscheck.php                         # JS balance check for every public/js file
php tools/pagecheck.php                       # page <-> script id cross-check
php tools/api-test.php http://127.0.0.1:8099  # 327 end-to-end API checks (exit code 1 on failure)
php tools/protocol-test.php --port=20399      # 41 Wialon IPS protocol checks (starts its own tracker)
php tools/payments-test.php                   # 86 gateway checks (starts tools/gateway-stub.php)
```

### Gateway integration tests
`tools/payments-test.php` starts `tools/gateway-stub.php` (a stand-in for the payment providers) and points every
gateway's API base URL at it, so the real driver code runs: the exact request each gateway receives (amounts and
minor units, currency, phone normalisation, callback URLs, metadata, credentials/tokens), how redirects and STK
pushes are handled, and how each callback settles the invoice — including the signature checks (Stripe HMAC,
Flutterwave `verif-hash`, Pesapal status re-query, M-Pesa result callback) and replay protection. The stub
journals every call, so failures show exactly what was sent:

```bash
php tools/payments-test.php http://127.0.0.1:8099 8097
curl -s http://127.0.0.1:8097/_requests | head -c 400     # what the gateways received
```

The suite is repeatable: it removes its own fixtures first, so it can be run again and again against
the same database without reseeding (`php install.php --fresh` only when you want clean demo data).

### Browser-level UI smoke test
`tools/ui-smoke.html` loads the **real** admin console (markup + scripts), clicks *+ New unit*, fills
the dialog — deliberately clearing the device password — saves it and reports the outcome, the toast
text and the API state of the created unit (then deletes it). It runs without a click when driven by
a headless browser, which is the fastest way to catch a broken form, dialog or API call:

```bash
# 1) get a token (any role)
curl -s -X POST http://127.0.0.1:8099/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"username":"admin","password":"Admin@123"}'
# 2) drive the console and print the report
chrome --headless=new --disable-gpu --virtual-time-budget=25000 --dump-dom \
       "http://127.0.0.1:8099/tools/ui-smoke.html?token=<token>" | grep -E 'STEP|RESULT|TOAST'
```

It is a development aid: keep `tools/` out of a public docroot (the bundled `.htaccess` blocks
`tools/` and `data/` for exactly this reason).

The API suite covers: health, unauthenticated rejection, wrong-password rejection, all five demo
logins, metadata, the dashboard, live snapshots, the viewer's unit mask, cross-account isolation,
tracks, the trip detector, parking/idle detection, fuel events, geofences (all three types with
area/length), the enter/exit event log, notification rules, the event log and acknowledgement,
every one of the twelve report types, CSV export for each of them, report templates, CRUD for
units, sensors, geofences, notifications, drivers and service intervals, the HTTP ingest endpoint
(unknown device, bad password, impossible coordinates, GPS jump filtering with a per-fix reason),
the scheduled jobs, the audit log, user management (roles and unit masks, including clearing a
mask), super-admin accounts and platform settings, the full admin-console unit form payload
(blank device password and untouched fields), super-admin unit creation with and without an
account context, validation failures and the unit filters/search.

The protocol suite starts `php/tracker.php` on a spare port and speaks to it as a real device:
login handshake (wrong password, unknown device, data before login), `#D#` in both the
degrees+minutes and decimal-degrees forms, `#SD#`, `#P#`, `#B#`, malformed packets, GPS-jump
filtering over the wire, command delivery with `#AM#1` ack and `#AM#0` nack, two devices
connected at once and socket teardown. It refuses to run if something already listens on the
port (a stale tracker would silently answer instead of the one it started) and cleans up both
its fixtures and the daemon it spawned.

Two regression tests protect the invariants that were broken during development:

* **Trip detection is windowed.** Re-detecting a narrow period (the monitoring console's
  “Detect trips in range”, or `/api/tasks/run` with its 8-hour window) replaces only the records
  that overlap that window — it must never delete the rest of the unit's history, and it must not
  duplicate blocks at the window edges.
* **Webhooks are really delivered.** The suite starts `tools/webhook-sink.php` and asserts the
  payload arrives with HTTP 200 and contains the unit, type, severity and coordinates. (Point a
  webhook at the same single-threaded `php -S` instance and it will block until the cURL timeout —
  use Apache/nginx, `PHP_CLI_SERVER_WORKERS`, or a dedicated sink in development.)

Manual device test (two terminals):

```bash
php php/tracker.php --port=20333 --verbose
php tools/simulator.php 356938035643811 --minutes=2 --interval=3 --port=20333 --verbose
#   -> the device logs in (#AL#1), streams positions (#AD#1) and acks commands (#AM#1)
#   -> enter/exit geofence lines appear in the tracker log
#   -> watch the unit move in /app (or check the DB counters)
```

---

## Notes and limitations (read this before promising anything)

* **The logo is user-supplied image data.** Uploads are validated server-side (real image content,
  extension whitelist, 512 KB cap, SVG scripts/handlers refused) and stored under
  `public/img/branding/`, but a raster logo is not re-encoded — treat anything a super admin uploads
  as content you trust, and prefer PNG/SVG for sharp scaling on high-DPI screens.
* **Outbound e-mail and SMS are queued, not sent.** Notification actions `email` and `sms` record
  a delivery request (recipient + rendered text) in the audit log so the workflow and the message
  text are auditable; wiring them to a real SMTP/SMS gateway is a small addition in
  `php/notify.php` (`fire_notification()`). **Webhooks are executed for real** — the JSON payload
  is POSTed with cURL and the HTTP status is recorded (and shown by the *Test* button).
* **The IPS server is single-process.** One PHP process handles all device sockets with
  `stream_select`, which is plenty for hundreds of trackers on a VPS, but it is not a horizontally
  scaled cluster. Run one tracker process per port/instance if you need more, and supervise it
  (Windows: Task Scheduler / `start /b`; Linux: systemd or `nohup`).
* **IPS traffic is plain TCP.** Wialon IPS has no TLS layer, so terminate the port behind a VPN
  or a TLS wrapper if trackers traverse the public internet.
* **Map tiles need internet.** The console works without Leaflet (built-in canvas map), but the
  raster tiles come from the URL in *Platform settings* — point it at your own tile server for
  fully offline operation. The geocoder never calls an external service (it uses the `places`
  table), so addresses work offline too.
* **The map style is a client choice.** The picker in the monitoring console only swaps the tile URL
  in the browser (`localStorage`), so it cannot break a deployment; *Platform settings → Map tile URL*
  stays the default that every new browser (and every other map, e.g. the geofence editor in the admin
  console) starts from.
* **JSON responses never contain PHP notices.** `php/api.php` sets `display_errors=0` and logs to
  `data/web.err`, because one stray warning emitted before `json_encode()` both corrupts the envelope
  and sends the "headers already sent" cascade — browsers then report an aborted/empty response
  (`ECONNRESET` in a Node client, `ERR_EMPTY_RESPONSE` in Chrome). If a console call ever misbehaves,
  check `data/web.err` first; the same applies when the built-in server is busy: `php -S` is
  **single-threaded**, so one slow request (a big CSV export, a gateway timeout) queues everything
  behind it and a very short client timeout looks like a connection error. Use Apache/nginx, or a
  longer client timeout, for production.
* **Storage growth.** Positions are kept for `retention_days` (default 90) and pruned by
  `/api/tasks/run`; run that from cron (e.g. every 10 minutes) for unattended deployments.
* **Trip/fuel detection is heuristic.** Trips use speed thresholds plus parking/stop logic;
  fuel events use a smoothed level sensor with a litre threshold. Both are configurable per unit
  and are the same calculations commercial telematics platforms expose — expect to tune the
  thresholds for noisy hardware (`tw=0` sensors, HDOP spikes, etc. are filtered by the GPS-jump check).
* **Bad GPS fixes are greyed out, never dropped.** A fix is stored with `valid = 0` (and reported as
  `reason: "gps_jump"`) when it moves further than the unit's *Max GPS jump* and faster than
  250 km/h, or when a single fix covers more than 500 km (`max_teleport_km()` in `php/engine.php` —
  the hard cap that catches teleports even when the stored history is days old). Invalid fixes never
  touch the live state, mileage or trips, so they are harmless; the *Messages* filters show them.
* **Money handling in a nutshell.** The platform never sees card data: cards and mobile money are entered on the
  gateway's own hosted page (or on the client's phone for an M-Pesa STK push). Only the gateway's reference,
  receipt and status are stored, and every callback is signature-checked when a secret/hash is configured, then
  matched to a pending invoice before anything is activated. Configure the gateway credentials in
  Accounts → Plans & billing, set the **public base URL** there when the server sits behind a proxy (that is what
  the webhook and return URLs are built from), and register the printed webhook URLs with each provider.
* **Receipts are queued, not e-mailed; payment reminders are real e-mail.** A successful payment writes a
  `receipt_queued` entry (recipient, invoice, amount, plan) to the audit log for the receipt itself, while the
  14/5/2-day **payment reminders go out through the configured transport** (built-in SMTP client, PHP `mail()`
  or `data/mail.log`) with the attempt recorded in `billing_reminders`. Wire the SMTP host under
  Accounts → Plans & billing to make `mail()`-less servers deliver them.
* **Renewals are billed automatically when a saved method exists.** The first payment stores a reusable
  payment method (sandbox card token, Stripe payment method + customer, Flutterwave card token, the M-Pesa
  number); when the trial or paid period ends, `/api/tasks/run` (and the daemon) charges it off-session:
  Stripe `POST /v1/charges` (`off_session`), Flutterwave `POST /v3/charges` with the token, M-Pesa re-issues an
  STK push, the sandbox settles instantly. Declines mark the invoice failed (three failed automatic attempts
  stop the cycle), the client can opt out (`auto_renew`) or remove the method, and gateways without a
  tokenised repeat charge (**Pesapal, PayPal, bank**) — or a gateway the super admin switched off — fall back
  to the previous behaviour: `past_due` (7 days of grace) + payment reminders until the client pays from
  `/billing`. The job response reports `renewed / declined / pending / renewal_skipped` with reasons, so the
  console can explain any subscription that did not renew. Verify the provider endpoints against live
  credentials before going live.
* **Test with the sandbox gateway.** `sandbox` is always available, so the whole signup → trial → pay → activate
  journey works offline (`/pay/<invoice>` is the simulated gateway page). It moves no money and should be
  switched off (or left as a demo) before going live.
* **`install.php` should be deleted** from a production deployment once seeding is done.
* **Protocol compatibility note.** KIVU Tracker is an independent implementation. It speaks the
  public *Wialon IPS 1.1* protocol so that off-the-shelf trackers can be pointed at it, and it
  exchanges data with Wialon in the same way any IPS client does — it shares no code and no branding
  with Gurtam's product (Wialon is a trademark of Gurtam).

---

## Publishing

```bash
cd kivu-tracker
git init
git add .
git commit -m "KIVU Tracker: IPS tracking server, monitoring console, reports, multi-account admin"
git branch -M main
git remote add origin https://github.com/<user>/kivu-tracker.git
git push -u origin main
```

`data/` (runtime logs) and any uploaded files are git-ignored; the schema, demo seeder and test
suites are committed, so a fresh clone is one `install.php` run away from a working demo.
