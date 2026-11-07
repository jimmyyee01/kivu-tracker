<?php
/* =====================================================================
   KIVU Tracker — deploy/bootstrap.php
   Brings a server that has no data yet to a usable state:
   schema + platform settings + ONE super admin.

       php deploy/bootstrap.php --user=superadmin --email=you@example.com
       php deploy/bootstrap.php --user=superadmin --password='S3cret!' --email=me@x.com

   Everything is idempotent and it never touches existing data:
     1. ensure_schema()          — every CREATE TABLE IF NOT EXISTS
     2. settings row             — only when the settings table is empty
     3. one super admin          — only when no role='super' user exists
                                   (password generated + printed if omitted)

   It does NOT seed the demo fleet; `php install.php` does that (demo
   logins, sample units). Use this on a real deployment.
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("bootstrap.php must be run from the command line\n"); }

require_once dirname(__DIR__) . '/php/config.php';

$opts = ['user' => 'superadmin', 'email' => '', 'name' => 'Platform Super Admin', 'password' => ''];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--help' || $a === '-h') {
        echo "Usage: php deploy/bootstrap.php [--user=NAME] [--email=ADDR] [--name=\"Full Name\"] [--password=PW]\n";
        exit(0);
    }
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opts)) {
        $opts[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "unknown option: $a\n");
        exit(2);
    }
}

function line(string $s = ''): void { echo $s, PHP_EOL; }
function ok(string $s): void   { echo '  ok   ', $s, PHP_EOL; }
function warn(string $s): void { echo '  warn ', $s, PHP_EOL; }

line('KIVU Tracker — bootstrap');
line('=========================');
line('php ' . PHP_VERSION . '   app ' . APP_ROOT);

/* ------------------------------------------------------- 1. requirements */
$missing = [];
foreach (['pdo_mysql', 'curl', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) $missing[] = $ext;
}
if ($missing) {
    warn('missing PHP extension(s): ' . implode(', ', $missing) . '  (apt install php-mysql php-curl php-mbstring)');
} else {
    ok('php extensions present (pdo_mysql, curl, mbstring, json)');
}

/* -------------------------------------------------------------- 2. schema */
try {
    ensure_schema();
    $tables = (int)db()->query('SELECT COUNT(*) FROM information_schema.tables
                                WHERE table_schema = DATABASE()')->fetchColumn();
    ok('schema ready in ' . DB_NAME . " ($tables tables)");
} catch (Throwable $e) {
    fwrite(STDERR, "\nFAIL  cannot reach the database: " . $e->getMessage() . "\n");
    fwrite(STDERR, "      check DB_HOST/DB_NAME/DB_USER/DB_PASS in php/config.php\n");
    exit(1);
}


/* ------------------------------------------------------------ 3. settings */
$settings = (int)db()->query('SELECT COUNT(*) FROM settings')->fetchColumn();
if ($settings === 0) {
    db()->prepare('INSERT INTO settings (id,platform_name,company_name,support_email,default_currency,map_tiles,
                   default_lat,default_lon,default_zoom,online_timeout_s,moving_speed_kmh,retention_days,ips_port,seed,installed_at)
                   VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(['KIVU Tracker', 'KIVU Tracker', $opts['email'] ?: 'support@example.com', 'USD',
            'https://tile.openstreetmap.org/{z}/{x}/{y}.png', -1.2921, 36.8219, 11, 300, 5, 90, 20332,
            bin2hex(random_bytes(4)), now_dt()]);
    ok('platform settings created (IPS port 20332, map centred on Nairobi)');
} else {
    ok('platform settings already present — left untouched');
}

/* --------------------------------------------------------- 4. super admin */
$supers = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'super'")->fetchColumn();
if ($supers > 0) {
    $names = db()->query("SELECT username FROM users WHERE role = 'super'")->fetchAll(PDO::FETCH_COLUMN);
    ok('super admin already exists: ' . implode(', ', $names) . ' — nothing created');
} else {
    $pw = $opts['password'] !== '' ? $opts['password'] : bin2hex(random_bytes(6)) . '!Aa1';
    if (strlen($pw) < 8) { fwrite(STDERR, "FAIL  password must be at least 8 characters\n"); exit(1); }
    if (strlen($opts['user']) < 3) { fwrite(STDERR, "FAIL  --user must be at least 3 characters\n"); exit(1); }
    db()->prepare('INSERT INTO users (id,account_id,username,email,phone,full_name,role,salt,password_hash,
                   access_units_json,enabled,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)')
        ->execute(['usr_' . bin2hex(random_bytes(6)), null, $opts['user'], $opts['email'] ?: null, null,
            $opts['name'], 'super', bin2hex(random_bytes(8)),
            password_hash($pw, PASSWORD_BCRYPT), null, now_dt()]);
    ok('super admin created: ' . $opts['user']);
    line();
    line('  ---------------------------------------------------------------');
    line('   USERNAME : ' . $opts['user']);
    line('   PASSWORD : ' . $pw);
    line('  ---------------------------------------------------------------');
    line('   Sign in at /login and change this password straight away.');
    line();
}

/* ---------------------------------------------------------------- summary */
line();
line('Next steps');
line('  1. web console  : php -S 0.0.0.0:8099 -t ' . APP_ROOT . ' ' . APP_ROOT . '/tools/php-router.php');
line('                    (or install the kivu-web systemd unit — see deploy/)');
line('  2. IPS daemon   : php php/tracker.php --verbose        (TCP 20332)');
line('  3. devices      : unit unique_id + password "track" (set per unit in /admin)');
line('  4. housekeeping : see deploy/kivu-cron.txt for the /api/tasks/run cron line');
line('  5. remove install.php from production (deploy/remote-deploy.sh --delete-installer)');
