<?php
/* =====================================================================
   KIVU Tracker — tools/gen_history.php
   Speeds up demos: generates historical telemetry (and the matching
   trips / events) for existing units.

   Examples:
     php tools/gen_history.php --days=7 --interval=180
     php tools/gen_history.php --unit=unt_acme_01 --days=3 --route=thika_road
     php tools/gen_history.php --all --days=1 --reset

   Options: --unit=<id|unique_id|name|all> --days=N --interval=S
            --route=<name> --phase=0..2 --reset --no-detect
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/php/config.php';
require_once dirname(__DIR__) . '/php/engine.php';
require_once dirname(__DIR__) . '/php/ingest.php';
require_once dirname(__DIR__) . '/php/notify.php';
require_once dirname(__DIR__) . '/php/trips.php';
require_once dirname(__DIR__) . '/php/payments.php';
require_once dirname(__DIR__) . '/php/demo.php';

$opts = ['unit' => 'all', 'days' => 3, 'interval' => 180, 'route' => '', 'phase' => 0.0,
    'reset' => false, 'detect' => true];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $k = $m[1]; $v = $m[2] ?? '1';
        if ($k === 'reset') $opts['reset'] = true;
        elseif ($k === 'no-detect') $opts['detect'] = false;
        elseif (array_key_exists($k, $opts)) $opts[$k] = is_numeric($v) ? $v + 0 : $v;
    }
}
$days = max(1, min(60, (int)$opts['days']));
$interval = max(30, min(1800, (int)$opts['interval']));
$routes = demo_routes();
$routeNames = array_keys($routes);

if ($opts['unit'] === 'all') {
    $units = db()->query('SELECT id,name FROM units ORDER BY name')->fetchAll();
} else {
    $st = db()->prepare('SELECT id,name FROM units WHERE id = ? OR unique_id = ? OR name LIKE ? LIMIT 1');
    $st->execute([$opts['unit'], $opts['unit'], '%' . $opts['unit'] . '%']);
    $units = $st->fetchAll();
}
if (!$units) { fwrite(STDERR, "No matching units. Run install.php first.\n"); exit(1); }

$to = now_dt();
$from = date('Y-m-d H:i:s', time() - $days * 86400);
echo 'Generating ' . $days . " day(s) of history for " . count($units) . " unit(s), every {$interval}s\n";
$t0 = microtime(true);
$totalPoints = 0; $totalEvents = 0; $totalTrips = 0;

foreach ($units as $i => $u) {
    $routeName = $opts['route'] !== '' ? (string)$opts['route'] : $routeNames[$i % count($routeNames)];
    $route = $routes[$routeName] ?? $routes['nairobi_city'];
    if ($opts['reset']) demo_clear_unit_history((string)$u['id']);
    $phase = (float)$opts['phase'] + ($i * 0.37);
    $res = demo_generate_history((string)$u['id'], $from, $to, $route, $interval,
        ['phase' => $phase, 'fuel_start' => 60 + 30 * ($i % 7), 'refill_at' => 0.4, 'drain_at' => 0.75]);
    $trips = 0; $parkings = 0;
    if ($opts['detect']) {
        $r = detect_trips((string)$u['id'], $from, $to, true);
        $trips = count($r['trips']); $parkings = count($r['parkings']);
    }
    $totalPoints += $res['points']; $totalEvents += $res['events']; $totalTrips += $trips;
    printf("  %-34s %-14s points %5d  events %3d  geofence %3d  trips %3d  parkings %3d\n",
        $u['name'], $routeName, $res['points'], $res['events'], $res['geofence_events'], $trips, $parkings);
}
printf("\nDone in %.1fs — %d points, %d notification events, %d trips.\n",
    microtime(true) - $t0, $totalPoints, $totalEvents, $totalTrips);
echo "Tip: run php tools/api-test.php http://127.0.0.1:8080 to verify the API.\n";
