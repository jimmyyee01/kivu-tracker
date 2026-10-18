<?php
/* =====================================================================
   KIVU Tracker — php/config.php
   Database connection, shared helpers, authentication/sessions and the
   schema bootstrap (so install.php works even if database.sql was never
   imported by hand).
   Edit DB_HOST / DB_NAME / DB_USER / DB_PASS, then open install.php once.
   ===================================================================== */
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'kivu_tracker');
define('DB_USER', 'root');
define('DB_PASS', 'panzi@123');

define('SESSION_DAYS', 7);
define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'KIVU Tracker');
define('APP_VERSION', '1.0.0');

class ApiError extends Exception {
    public int $status;
    public function __construct(string $message, int $status = 400) {
        parent::__construct($message);
        $this->status = $status;
    }
}
function need($cond, string $message, int $status = 400): void {
    if (!$cond) throw new ApiError($message, $status);
}

/* ------------------------------------------------------------ database -- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

/* Runs database.sql statement by statement. Every statement is
   CREATE TABLE IF NOT EXISTS, so this is safe to call at any time. */
function ensure_schema(): void {
    $sql = @file_get_contents(APP_ROOT . '/database.sql');
    if ($sql === false) return;
    $lines = [];
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        if (preg_match('/^\s*--/', $line)) continue;            // drop comments
        $lines[] = $line;
    }
    $clean = implode("\n", $lines);
    foreach (preg_split('/;\s*\n/', $clean) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        if (strcasecmp($stmt, 'SET FOREIGN_KEY_CHECKS = 0') === 0) continue;
        if (strcasecmp($stmt, 'SET FOREIGN_KEY_CHECKS = 1') === 0) continue;
        db()->exec($stmt);
    }
    ensure_columns();
}

/* Adds columns that later versions introduced, so an existing database keeps
   working without re-importing database.sql (billing/registration support). */
function ensure_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $want = [
        'accounts' => [
            'plan_id' => 'VARCHAR(32) DEFAULT NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'trialing'",
            'trial_ends_at' => 'DATETIME DEFAULT NULL',
            'contact_name' => 'VARCHAR(120) DEFAULT NULL',
            'contact_phone' => 'VARCHAR(40) DEFAULT NULL',
            'billing_email' => 'VARCHAR(160) DEFAULT NULL',
            'billing_address' => 'VARCHAR(255) DEFAULT NULL',
            'billing_city' => 'VARCHAR(120) DEFAULT NULL',
            'billing_country' => 'VARCHAR(64) DEFAULT NULL',
            'auto_renew' => 'TINYINT NOT NULL DEFAULT 1',
            'default_asset_type' => 'VARCHAR(32) DEFAULT NULL',
            'portal_id' => 'VARCHAR(32) DEFAULT NULL',
            'signup_ip' => 'VARCHAR(64) DEFAULT NULL',
            'signup_source' => 'VARCHAR(20) DEFAULT NULL',
            'notes' => 'VARCHAR(255) DEFAULT NULL',
        ],
        'units' => [
            'asset_type' => 'VARCHAR(32) DEFAULT NULL',
        ],
        'plans' => [
            'extra_unit_price' => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
        ],
        'settings' => [
            'trial_days' => 'INT NOT NULL DEFAULT 5',
            'signup_enabled' => 'TINYINT NOT NULL DEFAULT 1',
            'gateway_mode' => "VARCHAR(10) NOT NULL DEFAULT 'sandbox'",
            'theme_mode' => "VARCHAR(10) NOT NULL DEFAULT 'user'",
            'portal_price' => 'DECIMAL(12,2) NOT NULL DEFAULT 999.00',
            'invoice_prefix' => "VARCHAR(12) NOT NULL DEFAULT 'KIVU'",
            'billing_json' => 'LONGTEXT DEFAULT NULL',
            /* the downloadable Android app (see android/ and /download) */
            'apk_url' => 'VARCHAR(255) DEFAULT NULL',
            'apk_version' => 'VARCHAR(40) DEFAULT NULL',
            'apk_notes' => 'VARCHAR(500) DEFAULT NULL',
            /* platform logo: an uploaded file under /img/branding/ or any URL */
            'logo_url' => 'VARCHAR(500) DEFAULT NULL',
            /* optional platform-wide footer credit */
            'powered_by' => 'VARCHAR(180) DEFAULT NULL',
        ],
    ];
    foreach ($want as $table => $cols) {
        try {
            $st = db()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            $have = array_map('strval', array_column($st->fetchAll(), 'COLUMN_NAME'));
            if (!$have) continue;                       // table missing: database.sql creates it
            $added = [];
            foreach ($cols as $col => $ddl) {
                if (in_array($col, $have, true)) continue;
                db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
                $added[] = $col;
            }
            /* Accounts that predate billing were created by the platform owner, so
               they keep working: mark them complimentary instead of trialing. */
            if ($table === 'accounts' && in_array('status', $added, true)) {
                db()->exec("UPDATE accounts SET status = 'comp' WHERE status = 'trialing' AND trial_ends_at IS NULL");
            }
        } catch (Throwable $e) { /* a migration must never break a request */ }
    }
}

/* ------------------------------------------------------------- helpers -- */
function uid(string $prefix): string { return $prefix . '_' . bin2hex(random_bytes(7)); }
function now_dt(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }

/* Asset types — captured at registration (the fleet's default) and on every
   unit; the glyph is the icon used by the map marker, the unit tree and the
   console tables (no image assets, works offline). */
function asset_types(): array {
    return [
        'lorry'      => ['label' => 'Lorry / truck', 'glyph' => '🚚'],
        'motorcycle' => ['label' => 'Motorcycle', 'glyph' => '🏍'],
        'bicycle'    => ['label' => 'Bicycle', 'glyph' => '🚲'],
        'car'        => ['label' => 'Car', 'glyph' => '🚗'],
        'van'        => ['label' => 'Van / minibus', 'glyph' => '🚐'],
        'bus'        => ['label' => 'Bus', 'glyph' => '🚌'],
        'tractor'    => ['label' => 'Tractor', 'glyph' => '🚜'],
        'boat'       => ['label' => 'Boat', 'glyph' => '🚢'],
        'machine'    => ['label' => 'Machine / plant', 'glyph' => '🏗'],
        'other'      => ['label' => 'Other asset', 'glyph' => '📦'],
    ];
}
/* Returns the validated code, or '' when unknown/empty (callers 400 on '' when
   the value was provided explicitly). */
function asset_type_valid(?string $code): string {
    $code = trim((string)$code);
    return $code !== '' && isset(asset_types()[$code]) ? $code : '';
}

/* Devices / protocols the platform supports out of the box — shown to the
   super admin (System tab) and used as the "hardware type" choices. */
function device_catalogue(): array {
    return [
        ['code' => 'ips', 'label' => 'Wialon IPS 1.1 tracker (any vendor)',
            'protocol' => 'TCP server · Wialon IPS 1.1', 'native' => true,
            'notes' => 'Connect the tracker to the built-in IPS port; the daemon answers '
                . '#L# login, #D# data, #SD# messages, #B# image and #P# keep-alive, and the same '
                . 'socket carries commands back to the device.',
            'examples' => 'Teltonika FMB/FMC (IPS mode), Concox GT06/GT02, Coban TK103/TK303, '
                . 'Ruptela FM-Eco, Queclink GV series, Galileosky, Wanway, Jimi IoT'],
        ['code' => 'http', 'label' => 'HTTP / JSON position gateway',
            'protocol' => 'HTTPS · POST /api/ingest', 'native' => false,
            'notes' => 'Any device or middleware that can POST JSON positions with the unit '
                . 'unique_id + device password, with a small anti-spoof filter (GPS jumps, '
                . 'teleport checks) and automatic geofence/trip/notification evaluation.',
            'examples' => 'custom IoT gateways, LoRaWAN/LoRa bridges, Traccar forwarder, '
                . 'sms-to-http bridges'],
        ['code' => 'phone', 'label' => 'Phone / tablet tracker app',
            'protocol' => 'HTTPS · POST /api/ingest', 'native' => false,
            'notes' => 'Any tracker app that can post to a custom HTTP endpoint works as a unit '
                . '(the unit password is the app "device id" secret).',
            'examples' => 'Traccar Client (Android/iOS), GPS Logger, OwnTracks via a bridge'],
    ];
}
function device_catalogue_codes(): array {
    return array_map(fn($d) => $d['code'], device_catalogue());
}

/* The platform logo the super admin set (uploaded file or URL), '' when none. */
function branding_logo_url(): string {
    return trim((string)setting('logo_url', ''));
}

/* MySQL DATETIME -> ISO-8601 for the frontend. */
function iso($dt): ?string {
    if (!$dt || $dt === '0000-00-00 00:00:00') return null;
    $t = strtotime((string)$dt);
    return $t === false ? null : date('Y-m-d\TH:i:s', $t);
}
function jenc($v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function jdec($s, $fallback = []) {
    if ($s === null || $s === '') return $fallback;
    $v = json_decode((string)$s, true);
    return $v === null ? $fallback : $v;
}
function f2($v, int $p = 2): float { return round((float)$v, $p); }
function ip_addr(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'); }

/* Accepts ISO-8601, "Y-m-d H:i:s", "Y-m-d", epoch seconds or milliseconds. */
function dt_param($v, ?string $default = null): ?string {
    if ($v === null || $v === '' || $v === false) return $default;
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n > 1e12) $n = $n / 1000;          // milliseconds
        return date('Y-m-d H:i:s', (int)$n);
    }
    $s = str_replace('T', ' ', (string)$v);
    $s = preg_replace('/Z$/', '', $s);
    $t = strtotime($s);
    return $t === false ? $default : date('Y-m-d H:i:s', $t);
}
function qp(string $key, $default = null) {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}
function body(): array {
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input');
        $json = $raw === '' ? null : json_decode($raw, true);
        $data = is_array($json) ? $json : $_POST;
    }
    return $data;
}
function bval(string $key, $default = null, ?array $src = null) {
    $b = $src ?? body();
    if (array_key_exists($key, $b)) {
        $v = $b[$key];
        return is_string($v) ? trim($v) : $v;
    }
    $v = $_GET[$key] ?? null;
    if ($v !== null) return is_string($v) ? trim($v) : $v;
    return $default;
}
function bbool(string $key, bool $default = false): bool {
    $v = bval($key, null);
    if ($v === null) return $default;
    if (is_bool($v)) return $v;
    return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}
function bint(string $key, int $default = 0): int {
    $v = bval($key, null);
    return $v === null || $v === '' ? $default : (int)$v;
}
function bfloat(string $key, float $default = 0): float {
    $v = bval($key, null);
    return $v === null || $v === '' ? $default : (float)$v;
}
function bstr(string $key, string $default = ''): string {
    $v = bval($key, null);
    return $v === null ? $default : (string)$v;
}
function list_of(string $key): array {
    $v = bval($key, []);
    if (is_array($v)) return array_values(array_filter(array_map('strval', $v), fn($x) => $x !== ''));
    if (is_string($v) && $v !== '') return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
    return [];
}
function int_list(string $key): array {
    return array_map(fn($x) => (int)$x, list_of($key));
}
function fmt_duration($seconds): string {
    $s = (int)round((float)$seconds);
    return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
}

/* ------------------------------------------------------------ settings -- */
/* The settings row is read once per request; settings_forget() invalidates it
   so a save is visible immediately (gateway credentials, trial length…). */
function &settings_store(): array {
    static $s = null;
    if ($s === null) {
        try { $row = db()->query('SELECT * FROM settings WHERE id = 1')->fetch(); }
        catch (Throwable $e) { $row = null; }
        $s = $row ?: [];
    }
    return $s;
}
function settings_forget(): void {
    $s = &settings_store();
    $s = null;
}
function setting(string $key = '', $default = null) {
    $s = settings_store();
    if ($key === '') return $s;
    return $s[$key] ?? $default;
}
function settings_save(array $fields): void {
    $sets = []; $vals = [];
    foreach ($fields as $k => $v) {
        if (!preg_match('/^[a-z_]+$/', (string)$k)) continue;
        $sets[] = "`$k` = ?";
        $vals[] = $v;
    }
    if (!$sets) return;
    $vals[] = 1;
    $st = db()->prepare('UPDATE settings SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $st->execute($vals);
    settings_forget();
}

/* ---------------------------------------------------------------- auth -- */
function bearer_token(): ?string {
    $t = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if ($t === '') {
        $a = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (stripos($a, 'bearer ') === 0) $t = substr($a, 7);
    }
    if ($t === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'X-Auth-Token') === 0) { $t = (string)$v; break; }
        }
    }
    if ($t === '' && isset($_GET['token'])) $t = (string)$_GET['token'];
    if ($t === '' && isset($_POST['token'])) $t = (string)$_POST['token'];
    $t = trim($t);
    return $t === '' ? null : $t;
}

function create_session(array $user): string {
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO sessions (token,user_id,created_at,expires_at,ip,user_agent) VALUES (?,?,?,?,?,?)')
        ->execute([$token, $user['id'], now_dt(),
            date('Y-m-d H:i:s', time() + SESSION_DAYS * 86400),
            ip_addr(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250)]);
    db()->prepare('UPDATE users SET last_login = ?, last_ip = ? WHERE id = ?')
        ->execute([now_dt(), ip_addr(), $user['id']]);
    return $token;
}
function destroy_session(string $token): void {
    db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
}

function user_public(array $u): array {
    return [
        'id' => $u['id'], 'username' => $u['username'], 'email' => $u['email'],
        'phone' => $u['phone'], 'full_name' => $u['full_name'], 'role' => $u['role'],
        'account_id' => $u['account_id'], 'enabled' => (int)$u['enabled'],
        'access_units' => $u['access_units_json'] === null ? null : jdec($u['access_units_json'], []),
        'access_mask' => jdec($u['access_mask_json'] ?? null, []),
        'last_login' => iso($u['last_login']), 'created_at' => iso($u['created_at']),
    ];
}

/* Returns the authenticated user row (including 'token') or null. */
function auth_user(bool $required = true): ?array {
    static $cache = [];
    $token = bearer_token();
    if ($token === null) {
        if ($required) throw new ApiError('Authentication required', 401);
        return null;
    }
    if (array_key_exists($token, $cache)) {
        $u = $cache[$token];
    } else {
        $st = db()->prepare('SELECT u.*, s.expires_at FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ?');
        $st->execute([$token]);
        $u = $st->fetch() ?: null;
        if ($u) {
            if (strtotime((string)$u['expires_at']) < time() || (int)$u['enabled'] !== 1) $u = null;
            else $u['token'] = $token;
        }
        $cache[$token] = $u;
    }
    if (!$u) {
        if ($required) throw new ApiError('Session expired — please sign in again', 401);
        return null;
    }
    return $u;
}

function is_super(?array $u): bool { return $u !== null && $u['role'] === 'super'; }
function is_reseller(?array $u): bool { return $u !== null && $u['role'] === 'reseller'; }
function is_admin(?array $u): bool { return $u !== null && in_array($u['role'], ['super', 'admin'], true); }
function can_write(?array $u): bool { return $u !== null && $u['role'] !== 'viewer'; }
function can_admin(?array $u): bool { return is_admin($u); }

function require_admin(?array $u = null): array {
    $u = $u ?? auth_user();
    need(is_admin($u), 'Administrator rights required', 403);
    return $u;
}
function require_write(?array $u = null): array {
    $u = $u ?? auth_user();
    need(can_write($u), 'This account is read-only (viewer role)', 403);
    return $u;
}
/* Account the caller operates on; the super admin may pass ?account_id. */
function scope_account(?array $u = null): ?string {
    $u = $u ?? auth_user();
    if (is_super($u)) {
        $a = (string)(qp('account_id') ?: bstr('account_id', ''));
        return $a === '' ? null : $a;
    }
    need(!empty($u['account_id']), 'This user is not attached to an account', 403);
    return (string)$u['account_id'];
}

/* Unit ids the caller may see: null = every unit of the account.
   A null user (CLI: tracker, installer, generators) means "no mask",
   because those callers already scoped the work themselves. */
function allowed_unit_ids(?array $u = null): ?array {
    if ($u === null) return null;
    if ($u['access_units_json'] === null || $u['access_units_json'] === '') return null;
    return array_values(array_map('strval', jdec($u['access_units_json'], [])));
}
/* Returns [" AND unit_id IN (?,?)", [params]] for the caller's access mask. */
function unit_scope_sql(?array $u, string $column = 'unit_id'): array {
    $ids = allowed_unit_ids($u);
    if ($ids === null) return ['', []];
    if (!$ids) return [" AND $column = ''", []];
    return [" AND $column IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}
function unit_allowed(?array $u, string $unitId): bool {
    $ids = allowed_unit_ids($u);
    return $ids === null || in_array($unitId, $ids, true);
}

function audit(string $action, ?string $entity = null, ?string $entityId = null, ?array $details = null): void {
    try {
        $u = auth_user(false);
        db()->prepare('INSERT INTO audit_log (account_id,user_id,username,ts,action,entity,entity_id,details_json,ip) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$u['account_id'] ?? (qp('account_id') ?: null), $u['id'] ?? null, $u['username'] ?? 'system',
                now_dt(), $action, $entity, $entityId, $details ? jenc($details) : null, ip_addr()]);
    } catch (Throwable $e) { /* auditing must never break a request */ }
}
