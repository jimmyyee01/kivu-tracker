<?php
/* =====================================================================
   KIVU Tracker — install.php
   One-time installer / seeder.
     1) Create the database (the installer can also create all tables
        itself — every statement in database.sql is IF NOT EXISTS).
     2) Edit php/config.php if your MySQL credentials differ.
     3) Open this file once in the browser (http://localhost/kivu-tracker/install.php)
        or run it from the CLI:  php install.php --fresh
   ?fresh=1 / --fresh wipes all data and reseeds the demo fleet.
   DELETE this file from production after installing.
   ===================================================================== */
declare(strict_types=1);

require_once __DIR__ . '/php/config.php';
require_once __DIR__ . '/php/engine.php';
require_once __DIR__ . '/php/ingest.php';
require_once __DIR__ . '/php/notify.php';
require_once __DIR__ . '/php/trips.php';
require_once __DIR__ . '/php/reports.php';
require_once __DIR__ . '/php/payments.php';   // plans/subscriptions the demo seeds
require_once __DIR__ . '/php/demo.php';

$cli = PHP_SAPI === 'cli';
$fresh = $cli ? in_array('--fresh', $argv ?? [], true) : (($_GET['fresh'] ?? '') === '1');
$hours = 24;          // hours of demo history to simulate
$interval = 120;      // seconds between simulated positions
foreach (['hours' => 2, 'interval' => 30] as $k => $min) {
    $src = $cli ? null : ($_GET[$k] ?? null);
    if ($src === null && $cli) {
        foreach (($argv ?? []) as $a) {
            if (preg_match('/^--' . $k . '=(\d+)$/', $a, $m)) $src = $m[1];
        }
    }
    if ($src !== null) {
        $$k = max($min, min($k === 'hours' ? 240 : 900, (int)$src));
    }
}

if (!$cli) header('Content-Type: text/html; charset=utf-8');
$t0 = microtime(true);
$log = [];
$error = null;
try {
    ensure_schema();
    $log[] = 'Schema ready (' . DB_NAME . ') — every table checked/created.';
    $log = array_merge($log, demo_seed($fresh, $hours, $interval));
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$elapsed = round(microtime(true) - $t0, 1);

if ($cli) {
    echo "KIVU Tracker installer\n======================\n";
    foreach ($log as $line) echo ' - ' . $line . "\n";
    if ($error) { echo "ERROR: $error\n"; exit(1); }
    echo "Done in {$elapsed}s.\n\nNext steps:\n";
    echo "  1) Web console:  php -S 127.0.0.1:8099 -t \"<project>\" \"<project>/tools/php-router.php\"\n";
    echo "                   (or Apache/XAMPP — pick a port that is free)\n";
    echo "  2) IPS server:   php php/tracker.php --verbose\n";
    echo "  3) Simulator:    php tools/simulator.php 356938035643811 --minutes=2 --port=20332\n";
    echo "  4) Test suites:  php tools/api-test.php http://127.0.0.1:8099"
        . " && php tools/protocol-test.php\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KIVU Tracker — installer</title>
<link rel="stylesheet" href="css/style.css">
<style>
  body { background:#0f1420; color:#dfe6f2; font:15px/1.55 system-ui,Segoe UI,Roboto,sans-serif; margin:0; padding:40px 18px; }
  .wrap { max-width:860px; margin:0 auto; background:#161d2d; border:1px solid #263149; border-radius:14px; padding:26px 28px; }
  h1 { margin:0 0 6px; font-size:22px; }
  h2 { font-size:17px; margin:22px 0 8px; }
  p  { color:#a9b6cd; }
  code { background:#0e1421; padding:2px 6px; border-radius:5px; color:#7fd4ff; }
  ul { padding-left:20px; }
  li { margin:5px 0; }
  .ok { color:#3ddc84; font-weight:600; }
  .err { color:#ff6b6b; font-weight:600; }
  table { border-collapse:collapse; width:100%; margin-top:10px; font-size:14px; }
  th,td { text-align:left; padding:7px 9px; border-bottom:1px solid #263149; }
  th { color:#8ea2c0; font-weight:600; }
  a.btn { display:inline-block; margin-top:14px; background:#2f9cf4; color:#04121f; text-decoration:none;
          padding:10px 16px; border-radius:8px; font-weight:700; }
</style>
</head>
<body>
<div class="wrap">
  <h1>KIVU Tracker — installer</h1>
  <?php if ($error): ?>
    <p class="err">Installation failed: <?php echo htmlspecialchars($error); ?></p>
    <p>Check the DB credentials in <code>php/config.php</code> and that MySQL/MariaDB is running
       (XAMPP control panel), then reload this page.</p>
  <?php else: ?>
    <p class="ok">Installed / verified in <?php echo $elapsed; ?> s.</p>
    <ul><?php foreach ($log as $line) echo '<li>' . htmlspecialchars($line) . '</li>'; ?></ul>

    <h2>Open the console</h2>
    <table>
      <tr><th>Page</th><th>What it is</th></tr>
      <tr><td><a href="app">/app</a></td><td>Monitoring console — live map, tracks, trips, events</td></tr>
      <tr><td><a href="admin">/admin</a></td><td>Administration — units, geofences, notifications, users</td></tr>
      <tr><td><a href="reports">/reports</a></td><td>Report builder &amp; viewer (CSV export, printing)</td></tr>
      <tr><td><a href="super">/super</a></td><td>Platform accounts &amp; settings (super admin)</td></tr>
    </table>

    <h2>Demo logins</h2>
    <table>
      <tr><th>Role</th><th>Username</th><th>Password</th></tr>
      <tr><td>Super admin</td><td>superadmin</td><td>SuperAdmin@123</td></tr>
      <tr><td>Account admin (Acme)</td><td>admin</td><td>Admin@123</td></tr>
      <tr><td>Operator (Acme)</td><td>operator</td><td>Operator@123</td></tr>
      <tr><td>Viewer — 2 units only</td><td>viewer</td><td>Viewer@123</td></tr>
      <tr><td>Second account admin</td><td>safari</td><td>Admin@123</td></tr>
    </table>

    <h2>Connect devices (Wialon IPS)</h2>
    <p>Start the IPS server, then point your tracker (or the bundled simulator) at it:</p>
    <ul>
      <li><code>php php/tracker.php --verbose</code> — listens on TCP <?php echo (int)setting('ips_port', 20332); ?></li>
      <li><code>php tools/simulator.php 356938035643811 --minutes=2</code> — streams a live track</li>
      <li>Device login = unit <em>unique_id</em> (e.g. <code>356938035643809</code>), password <code>track</code></li>
    </ul>
    <p class="err">Delete install.php from production now.</p>
  <?php endif; ?>
  <a class="btn" href="app">Open the monitoring console →</a>
</div>
</body>
</html>
