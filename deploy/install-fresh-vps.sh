#!/usr/bin/env bash
# =====================================================================
#  KIVU Tracker — deploy/install-fresh-vps.sh
#  One-shot install on a FRESH Ubuntu/Debian VPS: PHP + MariaDB, the
#  database, the code, the two systemd services, the firewall and the
#  first super admin.
#
#     # on your PC
#     powershell -ExecutionPolicy Bypass -File deploy\deploy-from-windows.ps1 -BuildOnly
#     scp -P 10051 %TEMP%\kivu-tracker-*.tar.gz administrator@VPS:/tmp/
#
#     # on the VPS (as root)
#     sudo bash /tmp/install-fresh-vps.sh /tmp/kivu-tracker-<stamp>.tar.gz \
#          --app-dir=/home/administrator/kivu-tracker \
#          --run-user=administrator --email=you@example.com
#
#  Options (defaults in brackets)
#     --app-dir=PATH     where the code lives      [/home/<run-user>/kivu-tracker]
#     --run-user=NAME    linux user to run it as   [the sudo user, else root]
#     --web-port=N       console port              [8099]
#     --ips-port=N       Wialon IPS TCP port       [20332]
#     --db-name=NAME     database                  [kivu_tracker]
#     --db-user=NAME     database user             [kivu]
#     --db-pass=PASS     database password         [generated, printed]
#     --email=ADDR       support/super admin email [support@example.com]
#     --super-user=NAME  first super admin login   [superadmin]
#     --super-pass=PASS  its password              [generated, printed]
#     --demo             also seed the demo fleet (install.php) instead of
#                        a clean install (bootstrap.php)
#     --no-packages      skip apt-get (packages already installed)
#     --no-firewall      skip ufw rules
#
#  Safe to re-run: the schema step is idempotent and an existing super
#  admin / settings row is never overwritten.
# =====================================================================
set -euo pipefail

die()  { printf 'FAIL  %s\n' "$*" >&2; exit 1; }
say()  { printf '\n==> %s\n' "$*"; }
ok()   { printf '    ok   %s\n' "$*"; }
warn() { printf '    warn %s\n' "$*"; }

TARBALL="${1:-}"
[ -n "$TARBALL" ] || die "usage: sudo bash install-fresh-vps.sh <kivu-tracker.tar.gz> [options]"
[ -f "$TARBALL" ] || die "tarball not found: $TARBALL"
[ "$(id -u)" -eq 0 ] || die "run this with sudo/root"
shift

RUN_USER="${SUDO_USER:-root}"
WEB_PORT=8099
IPS_PORT=20332
DB_NAME=kivu_tracker
DB_USER=kivu
DB_PASS=""
EMAIL=support@example.com
SUPER_USER=superadmin
SUPER_PASS=""
DEMO=0
DO_PACKAGES=1
DO_FIREWALL=1
for a in "$@"; do
  case "$a" in
    --app-dir=*)    APP_DIR="${a#*=}" ;;
    --run-user=*)   RUN_USER="${a#*=}" ;;
    --web-port=*)   WEB_PORT="${a#*=}" ;;
    --ips-port=*)   IPS_PORT="${a#*=}" ;;
    --db-name=*)    DB_NAME="${a#*=}" ;;
    --db-user=*)    DB_USER="${a#*=}" ;;
    --db-pass=*)    DB_PASS="${a#*=}" ;;
    --email=*)      EMAIL="${a#*=}" ;;
    --super-user=*) SUPER_USER="${a#*=}" ;;
    --super-pass=*) SUPER_PASS="${a#*=}" ;;
    --demo)         DEMO=1 ;;
    --no-packages)  DO_PACKAGES=0 ;;
    --no-firewall)  DO_FIREWALL=0 ;;
    *) die "unknown option: $a" ;;
  esac
done
APP_DIR="${APP_DIR:-/home/$RUN_USER/kivu-tracker}"
[ -n "$DB_PASS" ] || DB_PASS="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)"

say "KIVU Tracker fresh install"
echo "    code      : $APP_DIR   (user $RUN_USER)"
echo "    database  : $DB_NAME   (user $DB_USER)"
echo "    ports     : web $WEB_PORT, ips $IPS_PORT"
echo "    release   : $TARBALL"

# ------------------------------------------------------------- 1. packages
if [ "$DO_PACKAGES" -eq 1 ]; then
  say "Installing packages (php, mariadb, curl)"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq php-cli php-mysql php-curl php-mbstring php-xml \
      mariadb-server mariadb-client curl tar rsync ca-certificates >/dev/null
  ok "packages installed (php $(php -r 'echo PHP_VERSION;'))"
else
  warn "package step skipped (--no-packages)"
fi

# --------------------------------------------------------------- 2. mariadb
say "Starting the database server"
systemctl enable --now mariadb >/dev/null 2>&1 || systemctl enable --now mysql >/dev/null 2>&1 \
  || die "could not start mariadb/mysql"
for i in $(seq 1 20); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
ok "database server is up"

say "Creating database $DB_NAME and user $DB_USER"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# ------------------------------------------------------------------ 3. code
say "Unpacking the release into $APP_DIR"
id "$RUN_USER" >/dev/null 2>&1 || die "linux user $RUN_USER does not exist"
mkdir -p "$APP_DIR"
STAGE="$(mktemp -d /tmp/kivu-install-XXXXXX)"
tar -xzf "$TARBALL" -C "$STAGE"
[ -f "$STAGE/php/config.php" ] || die "the tarball does not contain the app"
cp -a "$STAGE"/. "$APP_DIR"/
rm -rf "$STAGE"
ok "$(find "$APP_DIR" -type f | wc -l) files in place"

say "Writing php/config.php"
sed -i \
  -e "s/^define('DB_HOST'.*/define('DB_HOST', '127.0.0.1');/" \
  -e "s/^define('DB_NAME'.*/define('DB_NAME', '$DB_NAME');/" \
  -e "s/^define('DB_USER'.*/define('DB_USER', '$DB_USER');/" \
  -e "s/^define('DB_PASS'.*/define('DB_PASS', '$DB_PASS');/" \
  "$APP_DIR/php/config.php"
grep -E "^define\('DB_(HOST|NAME|USER)'" "$APP_DIR/php/config.php" | sed 's/^/    /'
ok "config.php points at $DB_NAME"

say "Setting ownership and permissions"
chown -R "$RUN_USER":"$RUN_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} +
find "$APP_DIR" -type f -exec chmod 644 {} +
mkdir -p "$APP_DIR/data" "$APP_DIR/public/img/branding"
chmod 775 "$APP_DIR/data" "$APP_DIR/public/img/branding"
chmod 755 "$APP_DIR"/deploy/*.sh 2>/dev/null || true
ok "files 644, directories 755, data/ writable"

# --------------------------------------------------------------- 4. systemd
say "Installing the kivu-web and kivu-ips services"
PHP_EXE="$(command -v php)"
cat > /etc/systemd/system/kivu-web.service <<EOF
[Unit]
Description=KIVU Tracker web console (php -S 0.0.0.0:$WEB_PORT)
After=network.target mariadb.service mysql.service

[Service]
Type=simple
User=$RUN_USER
Group=$RUN_USER
WorkingDirectory=$APP_DIR
ExecStart=$PHP_EXE -S 0.0.0.0:$WEB_PORT -t $APP_DIR $APP_DIR/tools/php-router.php
Restart=always
RestartSec=5
StandardOutput=append:$APP_DIR/data/web.log
StandardError=append:$APP_DIR/data/web.err

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/kivu-ips.service <<EOF
[Unit]
Description=KIVU Tracker IPS daemon (Wialon IPS 1.1, TCP $IPS_PORT)
After=network.target mariadb.service mysql.service

[Service]
Type=simple
User=$RUN_USER
Group=$RUN_USER
WorkingDirectory=$APP_DIR
ExecStart=$PHP_EXE $APP_DIR/php/tracker.php --verbose
Restart=always
RestartSec=5
StandardOutput=append:$APP_DIR/data/ips.log
StandardError=append:$APP_DIR/data/ips.err

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now kivu-web kivu-ips >/dev/null 2>&1 \
  || warn "could not start the services automatically — check: systemctl status kivu-web kivu-ips"
sleep 2
ok "services installed"

# -------------------------------------------------------------- 5. firewall
if [ "$DO_FIREWALL" -eq 1 ] && command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  say "Opening the firewall"
  ufw allow "$WEB_PORT"/tcp >/dev/null
  ufw allow "$IPS_PORT"/tcp >/dev/null
  ok "ufw: $WEB_PORT/tcp and $IPS_PORT/tcp allowed"
else
  warn "firewall untouched (ufw inactive, missing, or --no-firewall) — make sure $WEB_PORT/tcp and $IPS_PORT/tcp are reachable"
fi

# ---------------------------------------------------------- 6. first account
say "Preparing the database"
if [ "$DEMO" -eq 1 ]; then
  su -s /bin/bash "$RUN_USER" -c "cd '$APP_DIR' && php install.php" | sed 's/^/    /' || true
  ok "demo fleet + demo logins seeded (change every password)"
else
  SUPER_ARGS="--user=$SUPER_USER --email=$EMAIL"
  [ -n "$SUPER_PASS" ] && SUPER_ARGS="$SUPER_ARGS --password=$SUPER_PASS"
  OUT="$(su -s /bin/bash "$RUN_USER" -c "cd '$APP_DIR' && php deploy/bootstrap.php $SUPER_ARGS" 2>&1 || true)"
  echo "$OUT" | sed 's/^/    /'
  case "$OUT" in
    *'super admin'*) : ;;
    *) warn "the bootstrap step did not report a super admin — check the DB credentials in php/config.php" ;;
  esac
  GENERATED="$(printf '%s' "$OUT" | awk '/PASSWORD :/ {print $3}')"
  if [ -n "$GENERATED" ]; then SUPER_PASS="$GENERATED"; fi
fi

# ----------------------------------------------------------------- 7. verify
say "Verifying"
CODE="$(curl -s -o /dev/null -w '%{http_code}' -m 10 "http://127.0.0.1:$WEB_PORT/api/meta" 2>/dev/null || true)"
[ -n "$CODE" ] || CODE=000
if [ "$CODE" = "401" ] || [ "$CODE" = "200" ]; then
  ok "web console answers on port $WEB_PORT (http $CODE)"
else
  warn "web console returned $CODE — journalctl -u kivu-web -n 30 --no-pager"
fi
if ss -lntp 2>/dev/null | grep -q ":$IPS_PORT"; then
  ok "IPS daemon listening on $IPS_PORT"
else
  warn "nothing on $IPS_PORT — journalctl -u kivu-ips -n 30 --no-pager"
fi

cat <<EOF

=====================================================================
 KIVU Tracker is installed
=====================================================================
  code          : $APP_DIR
  console       : http://<server-ip>:$WEB_PORT/app
  api health    : http://<server-ip>:$WEB_PORT/api/meta
  device server : tcp://<server-ip>:$IPS_PORT   (Wialon IPS 1.1)
  database      : $DB_NAME / $DB_USER / $DB_PASS

  super admin   : $SUPER_USER${SUPER_PASS:+ / $SUPER_PASS}
  (sign in at /login and change the password immediately)

  services      : systemctl status kivu-web kivu-ips
  logs          : journalctl -u kivu-web -f  /  journalctl -u kivu-ips -f
  update code   : bash $APP_DIR/deploy/remote-deploy.sh /tmp/kivu-tracker-<new>.tar.gz
  housekeeping  : see $APP_DIR/deploy/kivu-cron.txt
  device config : unit unique_id + password "track" (set per unit in /admin)
  security      : delete/rename install.php once you are done
=====================================================================
EOF

ok "database ready (password printed at the end)"
