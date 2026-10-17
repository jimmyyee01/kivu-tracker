<?php
/* =====================================================================
   KIVU Tracker — php/api.php
   JSON REST API entry point. Apache rewrites /api/<path> to this file
   (see .htaccess); tools/php-router.php does the same for `php -S`.

   Response envelope: { "ok": true, ...payload } or { "ok": false,
   "error": "..." }. Authentication is a bearer token (X-Auth-Token
   header, ?token= or Authorization: Bearer).
   ===================================================================== */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/ingest.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/trips.php';
require_once __DIR__ . '/ips.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/routes_auth.php';
require_once __DIR__ . '/routes_units.php';
require_once __DIR__ . '/routes_geo.php';
require_once __DIR__ . '/routes_reports.php';
require_once __DIR__ . '/routes_admin.php';
require_once __DIR__ . '/routes_billing.php';
require_once __DIR__ . '/routes_portals.php';
require_once __DIR__ . '/routes_finance.php';

/* JSON endpoints must never print PHP notices into the body — a stray warning
   corrupts the envelope and the browser reports a broken/aborted response.
   Log them to data/web.err instead. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(dirname(__DIR__) . '/data')) {
    ini_set('error_log', dirname(__DIR__) . '/data/web.err');
}

function api_path(): string {
    if (isset($_GET['r']) && (string)$_GET['r'] !== '') $p = (string)$_GET['r'];
    elseif (isset($_SERVER['PATH_INFO']) && (string)$_SERVER['PATH_INFO'] !== '') $p = (string)$_SERVER['PATH_INFO'];
    else $p = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    /* every entry point (Apache rewrite, php -S router, PATH_INFO) ends up here */
    $p = (string)preg_replace('#^/api#', '', $p);
    $p = strtok($p, '?');
    if ($p === false || $p === '') $p = '/';
    if ($p[0] !== '/') $p = '/' . $p;
    $p = rtrim($p, '/');
    return $p === '' ? '/' : $p;
}

/* [method, pattern, handler, access]
   access: public | read (any signed-in user) | write (not a viewer)
           admin (account admin) | billing (admin, never blocked by billing)
           super (platform)                                             */
function api_routes(): array {
    return array_merge(
        routes_auth(), routes_units(), routes_geo(), routes_reports(), routes_admin(), routes_billing(),
        routes_portals(), routes_finance(),
        [
            ['GET', '#^/health$#', 'h_health', 'public'],
            ['GET', '#^/app$#', 'h_app_info', 'public'],
            ['GET', '#^/meta$#', 'h_meta', 'read'],
            ['GET', '#^/dashboard$#', 'h_dashboard', 'read'],
            ['POST', '#^/tasks/run$#', 'h_tasks_run', 'read'],
            ['POST', '#^/ingest$#', 'h_ingest_http', 'public'],
            ['GET', '#^/audit$#', 'h_audit', 'admin'],
        ]
    );
}

function respond($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (is_array($data) && !array_key_exists('ok', $data)) $data = ['ok' => true] + $data;
    echo jenc($data);
}

function api_dispatch(): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = api_path();
    if ($method === 'OPTIONS') {
        http_response_code(204);
        header('Allow: GET, POST, PUT, DELETE, OPTIONS');
        return;
    }
    /* method override for hosts that cannot send PUT / DELETE */
    if ($method === 'POST' && isset($_GET['_method'])) $method = strtoupper((string)$_GET['_method']);

    foreach (api_routes() as $route) {
        [$m, $pattern, $fn, $level] = $route;
        if ($m !== '*' && $m !== $method) continue;
        if (!preg_match($pattern, $path, $mm)) continue;
        $params = [];
        foreach ($mm as $k => $v) if (is_string($k)) $params[$k] = $v;
        try {
            $user = null;
            if ($level !== 'public') {
                $user = auth_user();
                if ($level === 'write') require_write($user);
                elseif ($level === 'admin') require_admin($user);
                elseif ($level === 'billing') {
                    /* paying the bill must always be possible, even when the
                       trial has ended — hence no billing gate here */
                    require_admin($user);
                }
                elseif ($level === 'super') need(is_super($user), 'Super administrator rights required', 403);
                elseif ($level === 'reseller') need(is_reseller($user), 'Reseller rights required', 403);
                /* an ended trial/subscription keeps reads (and billing) open but
                   blocks every change, so clients can still see and export data */
                if (in_array($level, ['write', 'admin'], true)) {
                    $access = account_access($user);
                    if (empty($access['allowed'])) {
                        respond(['ok' => false, 'error' => $access['reason'] !== ''
                                ? $access['reason']
                                : 'This account needs an active plan to make changes',
                            'code' => 'payment_required', 'billing' => $access], 402);
                        return;
                    }
                }
            }
            $out = $fn($user, $params);
            if ($out === null) $out = [];
            if (is_array($out) && array_key_exists('__raw', $out)) {
                header('Content-Type: ' . ($out['__content_type'] ?? 'text/plain') . '; charset=utf-8');
                if (!empty($out['__filename'])) {
                    header('Content-Disposition: attachment; filename="' . $out['__filename'] . '"');
                }
                echo (string)$out['__raw'];
                return;
            }
            if (is_string($out)) { header('Content-Type: text/plain; charset=utf-8'); echo $out; return; }
            respond($out);
        } catch (ApiError $e) {
            respond(['ok' => false, 'error' => $e->getMessage()], $e->status);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'Unknown database') !== false) {
                respond(['ok' => false, 'error' => 'Database not initialised — open install.php once (or import database.sql).'], 503);
                return;
            }
            respond(['ok' => false, 'error' => 'Database error: ' . $msg], 500);
        } catch (Throwable $e) {
            respond(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        return;
    }
    respond(['ok' => false, 'error' => 'Unknown endpoint: ' . $method . ' ' . $path], 404);
}

/* ------------------------------------------------------------------ misc */
function h_health($user, $p): array {
    $dbOk = true; $units = 0; $messages = 0;
    try {
        $units = (int)db()->query('SELECT COUNT(*) FROM units')->fetchColumn();
        $messages = (int)db()->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    } catch (Throwable $e) { $dbOk = false; }
    return ['ok' => true, 'app' => APP_NAME, 'version' => APP_VERSION, 'php' => PHP_VERSION,
        'time' => date('c'), 'database' => $dbOk ? 'ok' : 'error', 'driver' => db()->getAttribute(PDO::ATTR_DRIVER_NAME),
        'platform' => setting('platform_name', APP_NAME), 'logo' => branding_logo_url(),
        'powered_by' => trim((string)setting('powered_by', '')),
        'counters' => ['units' => $units, 'messages' => $messages],
        'ips_port' => (int)setting('ips_port', 20332)];
}

/* ------------------------------------------------- the downloadable app -- */
/* The Android app is a thin client for this server: it opens the console and
   can also run as a tracker (posting positions to /api/ingest). The package is
   built from android/ (see android/README.md) and dropped into public/download/
   — or hosted elsewhere and pointed at with the apk_url setting. */
function app_apk_local(): string {
    return dirname(__DIR__) . '/public/download/kivu-tracker.apk';
}
function app_download_info(): array {
    $custom = trim((string)setting('apk_url', ''));
    $hosted = $custom !== '' && preg_match('#^https?://#i', $custom) === 1;
    $file = app_apk_local();
    $exists = !$hosted && is_file($file);
    $size = $exists ? (int)filesize($file) : 0;
    return [
        'name' => setting('platform_name', APP_NAME) . ' for Android',
        'logo' => branding_logo_url(),
        'powered_by' => trim((string)setting('powered_by', '')),
        'package' => 'com.kivutracker.app',
        'version' => trim((string)setting('apk_version', '')) ?: '1.0.0',
        'min_android' => '6.0 (API 23)', 'target_android' => '14 (API 34)',
        'url' => $hosted ? $custom : ($custom !== '' ? $custom : '/download/kivu-tracker.apk'),
        'page' => '/download',
        'available' => $exists || $hosted,
        'hosted' => $hosted,
        'size_bytes' => $size,
        'size' => $size > 0 ? round($size / 1048576, 2) . ' MB' : null,
        'sha256' => $exists ? hash_file('sha256', $file) : null,
        'updated_at' => $exists ? date('c', (int)filemtime($file)) : null,
        'whats_new' => trim((string)setting('apk_notes', '')),
        'server_url' => app_base_url(),
        'ingest_url' => app_base_url() . '/api/ingest',
        'source' => 'android/'];
}
function h_app_info($user, $p): array {
    $app = app_download_info();
    $app['features'] = [
        'Console mode — the full monitoring/reports console in a native shell, server URL saved once',
        'Tracker mode — the phone itself reports GPS as a unit (battery-friendly interval, works with the screen off)',
        'Dark and light theme follow the platform policy, works on 2G/3G with the offline map fallback',
        'Signed releases only: verify the SHA-256 above before installing'];
    $app['install_steps'] = [
        'Open this page on the phone (or scan/tap the link you were sent) and tap Download.',
        'Android asks to allow installing from this source — accept it (Settings → Apps → Special access).',
        'Open KIVU Tracker, type this server address: ' . app_base_url(),
        'Sign in with your normal platform account. For tracker mode, enter a unit\'s unique ID and device password.'];
    $app['permissions'] = [
        'Internet — talk to this tracking server',
        'Location (optional) — only used in tracker mode, to report this phone as a unit',
        'Foreground service (optional) — keeps tracker mode alive with the screen off'];
    return ['ok' => true, 'app' => $app];
}

function h_meta($user, $p): array {
    /* Values every console needs, whatever the role. */
    $shared = [
        'platform' => setting('platform_name', APP_NAME),
        'logo' => branding_logo_url(),
        'powered_by' => trim((string)setting('powered_by', '')),
        'map_tiles' => setting('map_tiles'),
        'defaults' => ['lat' => (float)setting('default_lat', -1.2921), 'lon' => (float)setting('default_lon', 36.8219),
            'zoom' => (int)setting('default_zoom', 11)],
        'settings' => ['online_timeout_s' => (int)setting('online_timeout_s', 300),
            'moving_speed_kmh' => (float)setting('moving_speed_kmh', 5),
            'currency' => setting('default_currency', 'USD'),
            'retention_days' => (int)setting('retention_days', 90),
            'ips_port' => (int)setting('ips_port', 20332),
            'theme_mode' => setting('theme_mode', 'user')],
        'report_types' => report_types(),
        'asset_types' => asset_types(),
        'device_catalogue' => device_catalogue(),
        'app' => ['page' => '/download', 'version' => app_download_info()['version'],
            'available' => app_download_info()['available']],
        'notification_types' => ['speed', 'geofence', 'ignition', 'sos', 'fuel_drop', 'fuel_fill', 'idle',
            'power_cut', 'offline', 'movement', 'maintenance'],
        'user' => user_public($user),
        'server_time' => date('c')];
    /* A reseller owns a portal, not an account: they get the platform basics
       (so /reseller boots) and never any client fleet data. */
    if (!is_super($user) && (string)($user['role'] ?? '') === 'reseller') {
        return $shared + ['reseller' => true, 'units' => [], 'groups' => [], 'geofences' => [],
            'default_asset_type' => null, 'can_write' => false, 'can_admin' => false, 'is_super' => false,
            'billing' => ['allowed' => true, 'status' => 'comp', 'reason' => '', 'super' => false]];
    }
    $accountId = scope_account($user);
    $units = units_list(['account_id' => $accountId], $user);
    $groups = [];
    foreach ($units as $u) if ($u['group_id']) $groups[$u['group_id']] = $u['group_name'] ?: $u['group_id'];
    return $shared + [
        'units' => array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'], 'group_id' => $u['group_id'],
            'unique_id' => $u['unique_id'], 'enabled' => (int)$u['enabled']], $units),
        'groups' => array_map(fn($k, $v) => ['id' => $k, 'name' => $v], array_keys($groups), array_values($groups)),
        'geofences' => array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name'], 'type' => $g['type']],
            geofences_list($accountId, true)),
        'default_asset_type' => $accountId
            ? (account_by_id($accountId)['default_asset_type'] ?? null) : null,
        'can_write' => can_write($user), 'can_admin' => can_admin($user), 'is_super' => is_super($user),
        'billing' => account_access($user)];
}

function h_dashboard($user, $p): array {
    $accountId = scope_account($user);
    $units = units_list(['account_id' => $accountId], $user);
    $byStatus = ['moving' => 0, 'idle' => 0, 'parked' => 0, 'offline' => 0, 'never' => 0];
    foreach ($units as $u) { $st = unit_state($u)['status']; $byStatus[$st] = ($byStatus[$st] ?? 0) + 1; }
    [$scopeSql, $scopePr] = unit_scope_sql($user, 'e.unit_id');
    $sql = 'SELECT e.*, u.name AS unit_name FROM events e LEFT JOIN units u ON u.id = e.unit_id WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND e.account_id = ?'; $prm[] = $accountId; }
    $sql .= $scopeSql . ' ORDER BY e.ts DESC LIMIT 12';
    $st = db()->prepare($sql);
    $st->execute(array_merge($prm, $scopePr));
    $events = array_map(fn($e) => ['id' => (int)$e['id'], 'ts' => iso($e['ts']), 'type' => $e['type'],
        'severity' => $e['severity'], 'unit' => $e['unit_name'], 'unit_id' => $e['unit_id'],
        'text' => $e['text'], 'address' => address_of($e['lat'], $e['lon'])], $st->fetchAll());

    $dayAgo = date('Y-m-d H:i:s', time() - 86400);
    [$msgSql, $msgPr] = unit_scope_sql($user, 'unit_id');
    $st = db()->prepare('SELECT COUNT(*) FROM messages WHERE ts >= ?' . $msgSql);
    $st->execute(array_merge([$dayAgo], $msgPr));
    $msgs24 = (int)$st->fetchColumn();
    $st = db()->prepare('SELECT COUNT(*) FROM events WHERE ts >= ? AND acknowledged = 0'
        . ($accountId ? ' AND account_id = ?' : ''));
    $st->execute($accountId ? [$dayAgo, $accountId] : [$dayAgo]);
    $newEvents = (int)$st->fetchColumn();
    $ids = array_map(fn($u) => (string)$u['id'], $units);
    $pending = 0;
    if ($ids) {
        $st = db()->prepare('SELECT COUNT(*) FROM commands WHERE state = ? AND unit_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute(array_merge(['pending'], $ids));
        $pending = (int)$st->fetchColumn();
    }
    return [
        'fleet' => ['total' => count($units)] + $byStatus,
        'counters' => ['messages_24h' => $msgs24, 'events_24h' => $newEvents, 'commands_pending' => $pending],
        'units' => array_map(fn($u) => unit_live_shape($u), array_slice($units, 0, 300)),
        'events' => $events,
        'top_units' => array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'],
            'mileage' => round((float)$u['odometer_km'], 1), 'engine_hours' => round((float)$u['engine_hours'], 1),
            'status' => unit_state($u)['status']], $units),
    ];
}

/* Scheduled jobs (offline alerts, maintenance alerts, trip detection,
   telemetry pruning). Point a cron job / Task Scheduler entry at this
   endpoint, or let php/tracker.php call it every minute. */
function h_tasks_run($user, $p): array {
    $accountId = scope_account($user);
    ensure_schema();
    $cut = date('Y-m-d H:i:s', time() - 8 * 3600);
    $trips = 0; $processed = 0;
    foreach (units_list(['account_id' => $accountId], $user) as $u) {
        try {
            $r = detect_trips((string)$u['id'], $cut, now_dt(), true);
            $trips += count($r['trips'] ?? []);
            $processed++;
        } catch (Throwable $e) { /* keep processing the fleet */ }
    }
    $checks = run_scheduled_checks($accountId);
    $pruned = prune_messages();
    $billing = is_super($user) ? billing_renewals() : ['expired' => 0, 'past_due' => 0];
    /* payment reminders (14 / 5 / 2 days before deactivation) run with the
       same cadence as the dunning sweep — super admin only, like renewals */
    $reminders = is_super($user) ? billing_reminders_sweep()
        : ['enabled' => billing_reminders_enabled(), 'ran' => false, 'sent' => 0, 'failed' => 0,
            'skipped' => 0, 'no_recipient' => 0];
    audit('tasks_run', 'system', null, ['trips' => $trips, 'checks' => $checks, 'pruned' => $pruned,
        'billing' => $billing, 'reminders' => $reminders]);
    return ['checks' => $checks, 'units_processed' => $processed, 'trips_detected' => $trips,
        'messages_pruned' => $pruned, 'billing' => $billing, 'reminders' => $reminders];
}

/* Deletes telemetry older than the retention window (per call, capped). */
function prune_messages(?int $days = null): int {
    $days = $days === null ? (int)setting('retention_days', 90) : $days;
    if ($days <= 0) return 0;
    $cut = date('Y-m-d H:i:s', time() - $days * 86400);
    $st = db()->prepare('DELETE FROM messages WHERE ts < ? LIMIT 20000');
    $st->execute([$cut]);
    return $st->rowCount();
}

function h_audit($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT * FROM audit_log WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND account_id = ?'; $prm[] = $accountId; }
    if (qp('action')) { $sql .= ' AND action = ?'; $prm[] = qp('action'); }
    $sql .= ' ORDER BY id DESC LIMIT 300';
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['entries' => array_map(fn($r) => ['id' => (int)$r['id'], 'ts' => iso($r['ts']),
        'username' => $r['username'], 'action' => $r['action'], 'entity' => $r['entity'],
        'entity_id' => $r['entity_id'], 'details' => jdec($r['details_json'] ?? null, null),
        'ip' => $r['ip']], $st->fetchAll())];
}

/* Live fleet snapshot — the endpoint the monitoring console polls. */
function h_units_live($user, $p): array {
    $accountId = scope_account($user);
    $f = ['account_id' => $accountId, 'q' => (string)qp('q', ''), 'group_id' => (string)qp('group_id', ''),
        'status' => (string)qp('status', ''), 'tag' => (string)qp('tag', '')];
    if (qp('ids')) $f['ids'] = array_map('trim', explode(',', (string)qp('ids')));
    $units = units_list($f, $user);
    return ['units' => array_map(fn($u) => unit_live_shape($u), $units),
        'server_time' => date('c'), 'count' => count($units)];
}

/* HTTP ingest so any protocol/device/service can push positions:
   POST /api/ingest { unique_id, password, points:[{ts,lat,lon,speed,...}] } */
function h_ingest_http($user, $p): array {
    $b = body();
    $unique = trim((string)($b['unique_id'] ?? bstr('unique_id', '')));
    need($unique !== '', 'unique_id is required');
    $st = db()->prepare('SELECT * FROM units WHERE unique_id = ?');
    $st->execute([$unique]);
    $unit = $st->fetch();
    need($unit, 'Unknown device: ' . $unique, 404);
    $expect = (string)$unit['device_password'];
    if ($expect !== '') need((string)($b['password'] ?? bstr('password', '')) === $expect, 'Bad device password', 401);
    /* devices of an account whose trial/plan has ended stop being accepted */
    $owner = account_by_id((string)$unit['account_id']);
    if ($owner) {
        $state = billing_state((string)$owner['id'], $owner);
        if (empty($state['allowed'])) {
            throw new ApiError(($state['reason'] !== '' ? $state['reason'] : 'This account is not active')
                . ' — positions are not being stored.', 402);
        }
    }

    $points = $b['points'] ?? null;
    if (!is_array($points) || !$points) {
        $points = [array_intersect_key($b, array_flip(['ts', 'lat', 'lon', 'speed', 'course', 'alt', 'sats',
            'params', 'valid', 'ignition', 'fuel', 'voltage']))];
    }
    $stored = 0; $events = 0; $geofence = 0; $results = [];
    foreach ($points as $point) {
        if (!is_array($point)) continue;
        $res = ingest_point($unit, $point + ['source' => 'api', 'ip' => ip_addr()]);
        if (!empty($res['stored'])) $stored++;
        $events += count($res['events'] ?? []);
        $geofence += count($res['geofence_events'] ?? []);
        $results[] = ['ts' => iso($res['ts']), 'valid' => !empty($res['valid']),
            'reason' => $res['reason'] ?? null, 'message_id' => $res['message_id'] ?? null];
    }
    return ['stored' => $stored,
        'rejected' => count(array_filter($results, fn($r) => $r['valid'] === false)),
        'results' => $results, 'events' => $events, 'geofence_events' => $geofence, 'unit' => $unit['name']];
}

api_dispatch();
