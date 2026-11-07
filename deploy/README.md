# Deploying KIVU Tracker

Everything needed to get this code running on a Linux VPS. Nothing here is
required for local development — `php install.php` + `php php/tracker.php`
still work exactly as before.

| File | What it is |
|---|---|
| `deploy-from-windows.ps1` | **one command** from your PC: packages the project, uploads it, runs the remote deploy |
| `remote-deploy.sh` | runs on the VPS: backup → PHP syntax gate → copy → permissions → schema top-up → restart → verify |
| `install-fresh-vps.sh` | fresh Ubuntu/Debian server: packages, MariaDB, code, systemd services, firewall, first super admin |
| `bootstrap.php` | schema + platform settings + one super admin, without seeding demo data |
| `kivu-web.service` | systemd unit for the web console + JSON API (`php -S 0.0.0.0:8099`) |
| `kivu-ips.service` | systemd unit for the Wialon IPS device server (TCP **20333**) |
| `gt06-bridge.service` | systemd unit for the Concox/GT06 bridge used by the real trackers (TCP **20332**) |
| `kivu-cron.txt` | optional `/api/tasks/run` cron line (trip detection, pruning, renewals) |

## The production server

| | |
|---|---|
| Host | `108.181.196.187` |
| SSH | port **10051**, user `administrator` (host name `Kivu-Tracker`) |
| Code | `/home/administrator/kivu-tracker` |
| Console | `http://108.181.196.187:10060/app` (public port → internal 8099) |
| Device gateway | `108.181.196.187` port **10062** (public port → internal 20332, GT06) |
| Services | `kivu-web` (web/API), `kivu-ips` (IPS devices), `gt06-bridge` (GT06 devices) |
| Logs | `journalctl -u kivu-web -f` · `journalctl -u kivu-ips -f` · `journalctl -u gt06-bridge -f` · `data/web.err` |

The sign-in page at `/login` prints the public device gateway (`108.181.196.187`
port `10062`) under the form, so that is the number to give a client when a
tracker has to be pointed at this server.

Public port map (the provider's NAT, not ours — nothing to configure here):

| Public | Internal | Purpose |
|---|---|---|
| 10051 | 22 | SSH |
| 10060 | 8099 | web console + JSON API (`kivu-web`) |
| 10062 | 20332 | device gateway for GT06/HiFly trackers (`gt06-bridge`) |

Ports **80/443 are not forwarded to this VM** — the provider's own Go front end
answers them, so the console must be reached on `:10060`. If a domain is needed,
either ask the provider to forward 80/443 to the VM or front `:10060` with
Cloudflare. The IPS daemon (`kivu-ips`, 20333) has **no** public forward yet;
add one (for example 10063 → 20333) before advertising IPS to devices.

The default values in `deploy-from-windows.ps1` are already set for this
server, so the command below works as-is.

## A. Update the running server (normal case)

```powershell
cd "C:\Users\MAMA TECH SOLUTIONS\AppData\Local\Cline\kivu-tracker"
powershell -ExecutionPolicy Bypass -File deploy\deploy-from-windows.ps1
```

You will be asked for the SSH password (twice: `scp` + `ssh`) and, if `sudo`
is not passwordless, for the sudo password once more. What happens:

1. a `.tar.gz` is built in `%TEMP%` — `.git`, `data/`, build output and
   `*.apk` are excluded (0.3 MB, not 3 MB)
2. `remote-deploy.sh` and the tarball are copied to `/tmp` on the server
3. the server takes a backup (`~/kivu-backups/`, 10 newest kept), runs
   `php -l` on every PHP file (a syntax error aborts the deploy *before*
   anything is copied), copies the files, keeps the production
   `php/config.php`, fixes permissions, runs `install.php` (idempotent —
   it never seeds over existing data), restarts both services and verifies
   `http://127.0.0.1:8099/api/meta`, the effective device port read from
   `setting('ips_port')` (20333 on this server, not the code default) and
   the database

Useful switches:

```powershell
... -BuildOnly                       # package only, upload nothing
... -NoRestart                       # copy files, restart by hand
... -NoMigrate                       # do not run install.php
... -DeleteInstaller                 # rename install.php -> install.php.removed
... -SetupKey                        # install your SSH key once (passwordless after)
... -InstallUnits                    # (re)write the systemd units (needs root)
```

Once `-SetupKey` has run, deploys are completely unattended:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\deploy-from-windows.ps1
```

## B. Fresh VPS (new server)

```powershell
# 1. on your PC: build the release
powershell -ExecutionPolicy Bypass -File deploy\deploy-from-windows.ps1 -BuildOnly
#    -> %TEMP%\kivu-tracker-<stamp>.tar.gz

# 2. upload it
scp -P 10051 "$env:TEMP\kivu-tracker-<stamp>.tar.gz" administrator@108.181.196.187:/tmp/

# 3. on the server
sudo bash /tmp/install-fresh-vps.sh /tmp/kivu-tracker-<stamp>.tar.gz \
     --app-dir=/home/administrator/kivu-tracker \
     --run-user=administrator --email=you@example.com
```

The installer prints the database password and the super-admin login once.
Add `--demo` if you want the demo fleet and demo logins instead of a clean
install. It is safe to re-run.


## C. Manual deploy (hosting panel / cPanel / no SSH)

1. `deploy\deploy-from-windows.ps1 -BuildOnly` and open the `%TEMP%` tarball
   (or zip the project yourself, keeping `php/config.php` from the server).
2. Upload it through the panel's file manager and extract over the app
   directory — **do not overwrite the server's `php/config.php`**.
3. Run the same steps by hand:

```bash
cd /home/administrator/kivu-tracker
find . -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors'   # optional gate
php install.php                       # idempotent schema/column top-up
sudo systemctl restart kivu-web kivu-ips
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8099/api/meta   # 401 = up
```

## D. Rollback

Every deploy prints its backup path. To go back:

```bash
ssh -p 10051 administrator@108.181.196.187
tar -xzf ~/kivu-backups/kivu-<stamp>.tar.gz -C /home/administrator/kivu-tracker
sudo systemctl restart kivu-web kivu-ips
```

`data/` is excluded from the backup on purpose (logs and runtime files stay
in place), and the database is never touched by a code deploy.

## E. Checklist after a deploy

```bash
systemctl status kivu-web kivu-ips --no-pager | head -20   # both "active (running)"
ss -lntp | grep -E ':8099|:20332'                          # both listening
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8099/app   # 200
curl -s http://127.0.0.1:8099/js/common.js | grep -c google_sat      # 1 = new map layers live
tail -5 data/web.err                                       # no PHP fatals
```

Then open `http://108.181.196.187:10060/app` and check the map provider
dropdown lists the Google / Esri / Carto layers.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `Permission denied (publickey,password)` | wrong port or user. The working combination here is port **10051**, user `administrator`. Run with `-SetupKey` once to stop typing passwords. |
| `Failed to listen on 0.0.0.0:8099 (reason: Address already in use)` + restart counter climbing | something else holds the port (a `screen`/`nohup` copy). `sudo pkill -f 'php -S 0.0.0.0:8099'` then `sudo systemctl restart kivu-web`. |
| Nothing listening on 20332 | an old ad-hoc `php php/tracker.php` may hold it: `sudo pkill -f tracker.php`, then `sudo systemctl restart kivu-ips`. |
| Web console returns 500 / blank | check `data/web.err` first, then the DB credentials in `php/config.php` (`php deploy/bootstrap.php` prints a clear DB error). |
| `php -l` failures abort the deploy | that is intended: the release is never copied when a PHP file is broken. Fix locally and re-run. |
| Map tiles blank | the browser needs internet, or *Platform settings → Map tile URL* points at a dead server. `node tools/map-providers-check.js` tests every provider. |
| `sudo: a password is required` during restart | the deploy copies the files and tells you to run `sudo systemctl restart kivu-web kivu-ips` yourself. |

## What the deploy deliberately does **not** do

* never touches the database contents (only `CREATE TABLE IF NOT EXISTS` /
  new columns through `install.php`)
* never overwrites the server's `php/config.php` (production DB credentials)
* never deletes server-only files (uploads, published APKs, `data/`) unless
  you pass `--prune` to `remote-deploy.sh`
* never seeds demo data unless you ask for it (`--demo`)
