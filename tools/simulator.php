<?php
/* =====================================================================
   KIVU Tracker — tools/simulator.php
   A Wialon IPS 1.1 device emulator. Logs in to php/tracker.php and
   streams live positions (with fuel, ignition, voltage and temperature
   parameters) along a demo route, acknowledging commands it receives.

   Examples:
     php tools/simulator.php 356938035643811 --minutes=3
     php tools/simulator.php "Van 03" --interval=5 --route=nairobi_city
     php tools/simulator.php unt_acme_01 --minutes=1 --reset --dec

   Options: --minutes=N --interval=S --server=host --port=20332
            --password=track --route=name --phase=0.5 --reset --dec --verbose
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/php/config.php';
require_once dirname(__DIR__) . '/php/engine.php';
require_once dirname(__DIR__) . '/php/ips.php';
require_once dirname(__DIR__) . '/php/demo.php';

$args = array_slice($argv, 1);
$target = null;
$opts = ['minutes' => 2, 'interval' => 10, 'server' => '127.0.0.1', 'port' => 0, 'password' => null,
    'route' => 'nairobi_city', 'phase' => 0.0, 'reset' => false, 'dec' => false, 'verbose' => false];
foreach ($args as $a) {
    if (strpos($a, '--') === 0) {
        if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
            $k = $m[1]; $v = $m[2] ?? '1';
            if ($k === 'reset') $opts['reset'] = true;
            elseif ($k === 'dec') $opts['dec'] = true;
            elseif ($k === 'verbose') $opts['verbose'] = true;
            elseif (array_key_exists($k, $opts)) $opts[$k] = is_numeric($v) ? $v + 0 : $v;
        }
        continue;
    }
    if ($target === null) $target = $a;
}
if ($target === null) {
    fwrite(STDERR, "Usage: php tools/simulator.php <unit-id|unique_id|unit name> [--minutes=2] [--interval=10]\n");
    fwrite(STDERR, "Available units:\n");
    foreach (db()->query('SELECT id,name,unique_id FROM units ORDER BY name')->fetchAll() as $u) {
        fwrite(STDERR, "  {$u['unique_id']}  {$u['name']}   [{$u['id']}]\n");
    }
    exit(1);
}

/* Resolve the unit by id, unique_id or (partial) name. */
$st = db()->prepare('SELECT * FROM units WHERE id = ? OR unique_id = ? OR name = ? LIMIT 1');
$st->execute([$target, $target, $target]);
$unit = $st->fetch();
if (!$unit) {
    $st = db()->prepare('SELECT * FROM units WHERE name LIKE ? LIMIT 1');
    $st->execute(['%' . $target . '%']);
    $unit = $st->fetch();
}
if (!$unit) { fwrite(STDERR, "Unit not found: $target\n"); exit(1); }

$port = (int)($opts['port'] ?: setting('ips_port', 20332));
$password = $opts['password'] ?? (string)$unit['device_password'];
$interval = max(1, (int)$opts['interval']);
$seconds = max(1, (int)$opts['minutes'] * 60);
$routes = demo_routes();
$route = $routes[$opts['route']] ?? $routes['nairobi_city'];

if ($opts['reset']) {
    demo_clear_unit_history((string)$unit['id']);
    echo "history cleared for {$unit['name']}\n";
}

$sampler = demo_route_sampler($route);
echo "simulating {$unit['name']} ({$unit['unique_id']}) -> {$opts['server']}:{$port}, "
    . "route {$opts['route']}, every {$interval}s for {$opts['minutes']} minute(s)\n";

$sock = @stream_socket_client('tcp://' . $opts['server'] . ':' . $port, $errno, $errstr, 5);
if (!$sock) {
    fwrite(STDERR, "cannot connect to {$opts['server']}:$port — $errstr ($errno)\n"
        . "Start the server first:  php php/tracker.php --verbose\n");
    exit(1);
}
stream_set_blocking($sock, false);

function sim_read($sock, bool $verbose, int &$commands = null): array {
    if ($commands === null) $commands = 0;
    $lines = [];
    $in = @fread($sock, 8192);
    if (is_string($in) && $in !== '') {
        foreach (preg_split('/\r?\n/', $in) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $lines[] = $line;
            if ($verbose) echo "  <- $line\n";
            if (strpos($line, '#M#') === 0) {                 // server command -> acknowledge
                $parts = explode('#', $line);
                $payload = $parts[2] ?? '';
                $f = explode(';', $payload, 3);
                $text = $f[2] ?? $payload;                    // #M#date;time;text
                $commands++;
                echo "  command received: $text -> acking\n";
                @fwrite($sock, '#AM#1' . IPS_CRLF);
            }
        }
    }
    return $lines;
}

$commands = 0;
@fwrite($sock, ips_build_login((string)$unit['unique_id'], $password));
usleep(300000);
$resp = sim_read($sock, (bool)$opts['verbose'], $commands);
if (!in_array('#AL#1', $resp, true)) {
    fwrite(STDERR, 'login rejected by the server (expected #AL#1, got ' . implode(' ', $resp) . ")\n"
        . "Check the unit unique_id and device password ({$unit['device_password']}).\n");
    exit(1);
}
echo "logged in — streaming data (Ctrl+C to stop)\n";

$dist = (float)$opts['phase'] * 1000;
$fuel = (float)($unit['last_fuel'] ?? 120.0);
$start = time();
$sent = 0; $pings = 0;
$lastPing = 0;

while (time() - $start < $seconds) {
    $elapsed = time() - $start;
    $t = time();
    $parked = ($t % 900) < 120;                          // stop for 2 min every 15 min
    $speed = $parked ? 0.0 : max(0.0, 38 + 20 * sin($t / 240.0) + 8 * cos($t / 90.0));
    if ($speed > 0) $dist += $speed / 3.6 * $interval;
    [$lat, $lon] = $sampler($dist);
    $fuel = max(5.0, $fuel - 0.05 * ($interval / 60.0));

    $sats = 7 + (int)round(4 * abs(sin($t / 60.0)));
    $params = [
        'ign' => $parked ? 0 : 1,
        'fuel' => round($fuel, 1),
        'pwr' => $parked ? 12.5 : 13.8,
        'hdop' => round(0.7 + 0.5 * abs(cos($t / 180.0)), 2),
        'temp' => round(21 + 7 * sin($t / 1200.0), 1),
        'gsm' => 4,
    ];
    @fwrite($sock, ips_build_data($t, $lat, $lon, $speed, fmod($dist / 30, 360), 1650, $sats,
        $params, $opts['dec'] ? 'dec' : 'dm'));
    $sent++;
    if ($opts['verbose'] || $sent % 10 === 0) {
        printf("  -> %s  %.5f,%.5f  %5.1f km/h  fuel %.0f L%s\n", date('H:i:s'), $lat, $lon, $speed, $fuel,
            $parked ? '  (parked)' : '');
    }
    foreach (sim_read($sock, (bool)$opts['verbose'], $commands) as $line) {
        if (strpos($line, '#AD#') === 0 && $line !== '#AD#1') echo "  !! server refused the data packet\n";
    }
    if ($elapsed - $lastPing >= 60) {
        @fwrite($sock, ips_build_ping());
        $pings++;
        $lastPing = $elapsed;
        sim_read($sock, false, $commands);
    }
    sleep($interval);
}

$unit = unit_by_id((string)$unit['id']);
printf("\nsent %d data packets and %d pings, acknowledged %d command(s)\n", $sent, $pings, $commands);
printf("unit state now: %s | last fix %s | odometer %.1f km | fuel %.1f L\n",
    unit_state($unit)['label'], (string)$unit['last_time'], (float)$unit['odometer_km'], (float)$unit['last_fuel']);
echo 'address: ' . address_of($unit['last_lat'], $unit['last_lon']) . "\n";
@fclose($sock);
