#!/usr/bin/env bash
# =====================================================================
#  KIVU Tracker — deploy/remote-deploy.sh
#  Runs ON the VPS. Unpacks an uploaded tarball over the app directory,
#  keeps the production database credentials (php/config.php ships its code,
#  the DB_* defines stay), backs the old code up, checks every PHP file,
#  tops the schema up and restarts the two services.
#
#     bash remote-deploy.sh /tmp/kivu-tracker.tar.gz
#     bash remote-deploy.sh /tmp/kivu-tracker.tar.gz --no-restart --no-migrate
#
#  Options
#     --no-backup        skip the tarball backup of the current code
#     --no-restart       copy files only, do not touch systemd
#     --no-migrate       do not run install.php (schema/column top-up)
#     --delete-installer remove install.php once the schema is up to date
#     --install-units    (re)write the kivu-web / kivu-ips unit files (root)
#     --prune            delete server files the new release no longer ships
#                        (mirror mode). Off by default: it can remove
#                        uploads/APKs that only exist on the server.
#
#  Environment overrides: KIVU_APP_DIR, KIVU_SERVICE_WEB, KIVU_SERVICE_IPS
# =====================================================================
set -euo pipefail

APP_DIR="${KIVU_APP_DIR:-$HOME/kivu-tracker}"
WEB_SVC="${KIVU_SERVICE_WEB:-kivu-web}"
IPS_SVC="${KIVU_SERVICE_IPS:-kivu-ips}"
WEB_PORT="${KIVU_WEB_PORT:-8099}"
TARBALL="${1:-/tmp/kivu-tracker.tar.gz}"
shift || true

DO_BACKUP=1; DO_RESTART=1; DO_MIGRATE=1; DO_INSTALLER=0; DO_UNITS=0; DO_PRUNE=0
for a in "$@"; do
  case "$a" in
    --no-backup) DO_BACKUP=0 ;;
    --no-restart) DO_RESTART=0 ;;
    --no-migrate) DO_MIGRATE=0 ;;
    --delete-installer) DO_INSTALLER=1 ;;
    --install-units) DO_UNITS=1 ;;
    --prune) DO_PRUNE=1 ;;
    *) echo "unknown option: $a" >&2; exit 2 ;;
  esac
done

say()  { printf '\n==> %s\n' "$*"; }
ok()   { printf '    ok   %s\n' "$*"; }
warn() { printf '    warn %s\n' "$*"; }
die()  { printf '    FAIL %s\n' "$*" >&2; exit 1; }

PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || die "php is not installed on this server"
[ -f "$TARBALL" ] || die "tarball not found: $TARBALL"
[ -d "$APP_DIR" ] || die "app directory not found: $APP_DIR (set KIVU_APP_DIR)"
[ -f "$APP_DIR/php/config.php" ] || die "$APP_DIR does not look like a KIVU Tracker install"

RUN_USER="$(stat -c '%U' "$APP_DIR" 2>/dev/null || echo "$(id -un)")"

say "Release: $(basename "$TARBALL")  ($(du -h "$TARBALL" | cut -f1))"
say "Target : $APP_DIR  (owner $RUN_USER)  php $($PHP_BIN -r 'echo PHP_VERSION;')"

# ------------------------------------------------------------------ 1. unpack
STAGE="$(mktemp -d /tmp/kivu-release-XXXXXX)"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT
tar -xzf "$TARBALL" -C "$STAGE"
[ -f "$STAGE/php/config.php" ] || die "tarball does not contain php/config.php"
[ -f "$STAGE/database.sql" ] || die "tarball does not contain database.sql"
ok "unpacked to $STAGE ($(find "$STAGE" -type f | wc -l) files)"

# ------------------------------------------------------------- 2. syntax gate
say "Checking every PHP file for syntax errors"
bad=0
while IFS= read -r f; do
  if ! out="$($PHP_BIN -l "$f" 2>&1)"; then bad=$((bad + 1)); echo "$out" >&2; fi
done < <(find "$STAGE" -name '*.php' -type f)
[ "$bad" -eq 0 ] || die "$bad PHP file(s) failed php -l — nothing was deployed"
ok "$(find "$STAGE" -name '*.php' | wc -l) PHP files parse cleanly"

# ------------------------------------------------------------------- 3. backup
if [ "$DO_BACKUP" -eq 1 ]; then
  say "Backing up the current code"
  BK_DIR="$HOME/kivu-backups"
  mkdir -p "$BK_DIR"
  BK="$BK_DIR/kivu-$(date +%Y%m%d-%H%M%S).tar.gz"
  tar -czf "$BK" -C "$APP_DIR" --exclude=./data --exclude=./.git --exclude=./public/media . 2>/dev/null || true
  ok "$(du -h "$BK" | cut -f1)  ->  $BK"
  ls -1t "$BK_DIR"/kivu-*.tar.gz 2>/dev/null | tail -n +11 | xargs -r rm -f
  ok "kept the 10 newest backups"
  echo "     rollback: tar -xzf $BK -C $APP_DIR && sudo systemctl restart $WEB_SVC $IPS_SVC"
else
  warn "backup skipped (--no-backup)"
fi


# --------------------------------------------------------------------- 4. copy
say "Copying the release over $APP_DIR"
CFG_SAVE="$(mktemp)"
cp "$APP_DIR/php/config.php" "$CFG_SAVE"
if [ "$DO_PRUNE" -eq 1 ] && command -v rsync >/dev/null 2>&1; then
  rsync -a --delete \
    --exclude 'php/config.php' --exclude 'data/' --exclude '.git/' \
    --exclude 'public/download/*.apk' --exclude 'public/img/branding/*' \
    --exclude 'public/media/' \
    "$STAGE"/ "$APP_DIR"/
  ok "rsync mirror (data/, .git/, APKs, logos and login media preserved)"
else
  cp -a "$STAGE"/. "$APP_DIR"/
  [ "$DO_PRUNE" -eq 1 ] && warn "rsync not installed — mirrored without pruning"
  ok "copied (server-only files such as data/ and APKs are untouched)"
fi
# php/config.php carries this machine's DB credentials, so it is never taken
# from a release. Its *code* (shared helpers, auth, schema bootstrap) must
# still ship though — a helper left behind in the old file would make the whole
# API fatal. So: install the release's config.php, patch the four DB_* defines
# back to the values this server already had, and only swap the file in when the
# result still passes php -l.
CFG_NEW="$STAGE/php/config.php"
CFG_OUT="$(mktemp)"
MERGE_PHP="$(mktemp)"
cat > "$MERGE_PHP" <<'PHPEOF'
<?php
/* Writes the released php/config.php to $outFile with the DB_* defines of the
   server's current php/config.php put back. Silent; non-zero exit on failure. */
$prodFile = (string)($argv[1] ?? '');
$relFile  = (string)($argv[2] ?? '');
$outFile  = (string)($argv[3] ?? '');
$prod = @file_get_contents($prodFile);
$rel  = @file_get_contents($relFile);
if ($prod === false || $rel === false) { fwrite(STDERR, "config.php not readable\n"); exit(1); }
$kept = 0;
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
    if (!preg_match("/^define\('" . $k . "'[^\n]*/m", $prod, $m)) continue;
    $line = $m[0];
    $rel = preg_replace_callback("/^define\('" . $k . "'[^\n]*/m",
        static function () use ($line) { return $line; }, $rel, 1);
    $kept++;
}
if ($kept < 4) { fwrite(STDERR, "DB_* defines not found in the production config.php\n"); exit(2); }
if (file_put_contents($outFile, $rel) === false) { fwrite(STDERR, "cannot write $outFile\n"); exit(3); }
PHPEOF
if "$PHP_BIN" -l "$MERGE_PHP" >/dev/null 2>&1 \
   && "$PHP_BIN" "$MERGE_PHP" "$CFG_SAVE" "$CFG_NEW" "$CFG_OUT" \
   && "$PHP_BIN" -l "$CFG_OUT" >/dev/null 2>&1; then
  cat "$CFG_OUT" > "$APP_DIR/php/config.php"   # same inode, so owner/mode stay
  ok "php/config.php updated (production DB_* credentials kept)"
else
  warn "php/config.php left as it was (release version could not be merged)"
fi
rm -f "$MERGE_PHP" "$CFG_OUT" "$CFG_SAVE"


# --------------------------------------------------------------- 5. ownership
say "Fixing ownership and permissions"
if [ "$(id -un)" = "root" ]; then
  chown -R "$RUN_USER":"$RUN_USER" "$APP_DIR"
fi
find "$APP_DIR" -type d -exec chmod 755 {} +
find "$APP_DIR" -type f -exec chmod 644 {} +
mkdir -p "$APP_DIR/data" "$APP_DIR/public/img/branding" "$APP_DIR/public/media/login"
chmod 775 "$APP_DIR/data" "$APP_DIR/public/img/branding" "$APP_DIR/public/media/login"
chmod 755 "$APP_DIR"/deploy/*.sh 2>/dev/null || true
ok "files 644, directories 755, data/ + branding/ + media/ writable"

# ------------------------------------------------------------------ 6. schema
if [ "$DO_MIGRATE" -eq 1 ]; then
  say "Topping the schema up (install.php is idempotent)"
  if ( cd "$APP_DIR" && $PHP_BIN install.php 2>&1 | tail -5 ); then
    ok "schema/columns up to date (existing data is never seeded over)"
  else
    warn "install.php reported a problem — check the DB credentials in php/config.php"
  fi
else
  warn "schema step skipped (--no-migrate)"
fi

if [ "$DO_INSTALLER" -eq 1 ] && [ -f "$APP_DIR/install.php" ]; then
  mv "$APP_DIR/install.php" "$APP_DIR/install.php.removed"
  ok "install.php disabled (renamed to install.php.removed)"
fi

# ------------------------------------------------------------------- 7. units
if [ "$DO_UNITS" -eq 1 ]; then
  say "Installing the systemd units"
  [ "$(id -u)" -eq 0 ] || die "--install-units needs root (run with sudo)"
  PHP_EXE="$(command -v php)"
  cat > "/etc/systemd/system/$WEB_SVC.service" <<EOF
[Unit]
Description=KIVU Tracker web console (php -S 0.0.0.0:8099)
After=network.target mysql.service mariadb.service

[Service]
Type=simple
User=$RUN_USER
Group=$RUN_USER
WorkingDirectory=$APP_DIR
ExecStart=$PHP_EXE -S 0.0.0.0:8099 -t $APP_DIR $APP_DIR/tools/php-router.php
Restart=always
RestartSec=5
StandardOutput=append:$APP_DIR/data/web.log
StandardError=append:$APP_DIR/data/web.err

[Install]
WantedBy=multi-user.target
EOF
  cat > "/etc/systemd/system/$IPS_SVC.service" <<EOF
[Unit]
Description=KIVU Tracker IPS daemon (Wialon IPS 1.1 device server)
After=network.target mysql.service mariadb.service

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
  systemctl enable "$WEB_SVC" "$IPS_SVC" >/dev/null 2>&1 || true
  ok "wrote /etc/systemd/system/$WEB_SVC.service and $IPS_SVC.service"
fi

# ----------------------------------------------------------------- 8. restart
if [ "$DO_RESTART" -eq 1 ]; then
  say "Restarting the services"
  if sudo -n systemctl restart "$WEB_SVC" "$IPS_SVC" 2>/dev/null; then
    ok "restarted $WEB_SVC and $IPS_SVC"
  else
    warn "could not restart automatically (sudo wants a password)"
    echo "     run this now:  sudo systemctl restart $WEB_SVC $IPS_SVC"
  fi
  sleep 2
else
  warn "services not restarted (--no-restart)"
  echo "     run this now:  sudo systemctl restart $WEB_SVC $IPS_SVC"
fi

# ----------------------------------------------------------------- 9. verify
say "Verifying the deployment"
# the IPS port comes from the app settings, not from a hard-coded default
IPS_PORT="$($PHP_BIN -r 'require "'"$APP_DIR"'/php/config.php";
  echo (int)setting("ips_port", 20332);' 2>/dev/null || true)"
[ -n "$IPS_PORT" ] || IPS_PORT=20332

CODE="$(curl -s -o /dev/null -w '%{http_code}' -m 10 "http://127.0.0.1:$WEB_PORT/api/meta" 2>/dev/null || true)"
[ -n "$CODE" ] || CODE=000
if [ "$CODE" = "200" ] || [ "$CODE" = "401" ]; then
  ok "web console answers on port $WEB_PORT (http $CODE)"
else
  warn "web console returned $CODE — check: journalctl -u $WEB_SVC -n 30 --no-pager"
fi

# fetch to a file first: piping curl straight into `grep -q` breaks the
# pipeline (grep exits on the first match, curl dies on SIGPIPE) and reported a
# false warning even when the file was correct
ASSET_PROBE="$(mktemp 2>/dev/null || echo /tmp/kivu-asset.probe)"
if curl -s -m 15 "http://127.0.0.1:$WEB_PORT/js/common.js" -o "$ASSET_PROBE" 2>/dev/null \
   && grep -q "google_sat" "$ASSET_PROBE" 2>/dev/null; then
  ok "this release is live (map provider list found in /js/common.js)"
else
  warn "could not confirm the new map provider list on /js/common.js"
fi
rm -f "$ASSET_PROBE"

if command -v ss >/dev/null 2>&1; then
  if ss -lntp 2>/dev/null | grep -q ":$IPS_PORT"; then
    ok "IPS daemon is listening on TCP $IPS_PORT"
  else
    warn "nothing is listening on $IPS_PORT — check: journalctl -u $IPS_SVC -n 30 --no-pager"
  fi
fi

DBOK="$($PHP_BIN -r 'require "'"$APP_DIR"'/php/config.php";
  try { $n = db()->query("SELECT COUNT(*) FROM units")->fetchColumn();
        echo "connected, ", $n, " unit(s)"; }
  catch (Throwable $e) { echo "ERROR: ", $e->getMessage(); }' 2>&1 || true)"
DBOK="$(printf '%s' "$DBOK" | tr '\n' ' ' | cut -c1-160)"
case "$DBOK" in
  connected*) ok "database $DBOK" ;;
  *)          warn "database check: $DBOK" ;;
esac

say "Deployed"
echo "    code : $APP_DIR"
echo "    web  : http://127.0.0.1:$WEB_PORT/app"
echo "    ips  : tcp://0.0.0.0:$IPS_PORT"
echo "    logs : journalctl -u $WEB_SVC -f   /   journalctl -u $IPS_SVC -f"
