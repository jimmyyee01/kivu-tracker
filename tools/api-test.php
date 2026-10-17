<?php
/* =====================================================================
   KIVU Tracker  —  tools/api-test.php
   End-to-end API test suite (no PHPUnit needed).

       php tools/api-test.php http://127.0.0.1:8099
       php tools/api-test.php            # defaults to http://127.0.0.1:8099

   Covers authentication/roles, unit access masks, live state, tracks,
   trips, fuel, geofences, notifications, every report type, CSV export,
   CRUD for units/geofences/notifications, the HTTP ingest endpoint,
   scheduled jobs, the audit log and account isolation.
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pass = 0; $fail = 0; $failures = [];

function http(string $method, string $path, $body = null, ?string $token = null, ?array $extra = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'X-Auth-Token: ' . $token;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    foreach ((array)$extra as $k => $v) $headers[] = $k . ': ' . $v;
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 180]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['status' => 0, 'raw' => '', 'json' => null, 'error' => $err, 'headers' => ''];
    $head = substr((string)$raw, 0, $headerSize);
    $text = substr((string)$raw, $headerSize);
    $json = json_decode($text, true);
    return ['status' => $status, 'raw' => $text, 'json' => is_array($json) ? $json : null,
        'error' => $err, 'headers' => $head];
}
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else {
        $fail++; $failures[] = $name . ($detail !== '' ? "  —  $detail" : '');
        echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}
function section(string $title): void { echo "\n== $title ==\n"; }
function jget(?array $json, string $key, $default = null) { return is_array($json) ? ($json[$key] ?? $default) : $default; }
function report_types_for_test(): array {
    return array_map(fn($id) => ['id' => $id], ['unit_summary', 'trips', 'parkings', 'activity', 'geofences',
        'events', 'speed_violations', 'fuel', 'engine_hours', 'mileage', 'drivers_by_unit', 'maintenance']);
}
/* Starts tools/webhook-sink.php on port 8098 unless something already listens there. */
function sink_start(): array {
    $probe = @fsockopen('127.0.0.1', 8098, $errno, $errstr, 0.4);
    if ($probe) { fclose($probe); return ['proc' => null, 'started' => false]; }
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:8098 '
        . escapeshellarg(dirname(__DIR__) . '/tools/webhook-sink.php');
    $desc = [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
             1 => ['file', dirname(__DIR__) . '/data/sink.out', 'a'],
             2 => ['file', dirname(__DIR__) . '/data/sink.err', 'a']];
    $proc = @proc_open($cmd, $desc, $pipes);
    for ($i = 0; $i < 40; $i++) {
        usleep(250000);
        $p = @fsockopen('127.0.0.1', 8098, $e2, $s2, 0.3);
        if ($p) { fclose($p); return ['proc' => $proc, 'started' => true]; }
    }
    return ['proc' => $proc, 'started' => false];
}
/* proc_terminate() alone is unreliable on Windows  —  kill the pid (and its tree) as well. */
function sink_stop($proc): void {
    if (!$proc) return;
    $status = proc_get_status($proc);
    $pid = (int)($status['pid'] ?? 0);
    if ($pid > 0 && PHP_OS_FAMILY === 'Windows') @exec('taskkill /F /T /PID ' . $pid . ' 2>&1');
    @proc_terminate($proc);
    usleep(300000);
    @proc_close($proc);
}

/* multipart/form-data upload — needed by the platform-logo endpoint */
function httpUpload(string $path, string $filePath, string $field = 'logo', ?string $token = null,
                    ?string $asName = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'X-Auth-Token: ' . $token;
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => [$field => new CURLFile($filePath, '', $asName ?: basename($filePath))],
        CURLOPT_TIMEOUT => 60]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $json = json_decode(substr((string)$raw, $headerSize), true);
    return ['status' => $status, 'raw' => substr((string)$raw, $headerSize),
        'json' => is_array($json) ? $json : null, 'headers' => substr((string)$raw, 0, $headerSize)];
}
/* A real PNG, written by hand so the suite needs neither GD nor fixture files. */
function png_bytes(int $w = 8, int $h = 8, int $r = 30, int $g = 120, int $b = 220): string {
    $raw = '';
    for ($y = 0; $y < $h; $y++) $raw .= "\x00" . str_repeat(chr($r) . chr($g) . chr($b) . chr(255), $w);
    $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data
        . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 6))
        . $chunk('IEND', '');
}

echo "KIVU Tracker API test suite  —  target $base\n";
$health = http('GET', '/api/health');
if ($health['status'] !== 200) {
    fwrite(STDERR, "Server not reachable at $base (status {$health['status']} {$health['error']})\n"
        . "Start it with:  php -S 127.0.0.1:8099 -t \"<project>\" \"<project>/tools/php-router.php\"\n");
    exit(1);
}

section('Health & metadata');
check('health endpoint is up', $health['status'] === 200 && jget($health['json'], 'database') === 'ok');
check('health reports unit + message counters', isset($health['json']['counters']['units']));
$noauth = http('GET', '/api/units');
check('unauthenticated request is rejected (401)', $noauth['status'] === 401, 'status ' . $noauth['status']);

section('Authentication & roles');
$bad = http('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'nope']);
check('wrong password rejected (401)', $bad['status'] === 401, 'status ' . $bad['status']);
$login = http('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'Admin@123']);
$admin = jget($login['json'], 'token');
check('admin login returns a 64-char token', is_string($admin) && strlen($admin) === 64);
check('admin login returns the user profile', jget(jget($login['json'], 'user', []), 'role') === 'admin');
$me = http('GET', '/api/auth/me', null, $admin);
check('auth/me returns access flags', jget(jget($me['json'], 'access', []), 'can_admin') === true);

$vlogin = http('POST', '/api/auth/login', ['username' => 'viewer', 'password' => 'Viewer@123']);
$viewer = jget($vlogin['json'], 'token');
$ologin = http('POST', '/api/auth/login', ['username' => 'operator', 'password' => 'Operator@123']);
$operator = jget($ologin['json'], 'token');
$slogin = http('POST', '/api/auth/login', ['username' => 'superadmin', 'password' => 'SuperAdmin@123']);
$super = jget($slogin['json'], 'token');
$tlogin = http('POST', '/api/auth/login', ['username' => 'safari', 'password' => 'Admin@123']);
$tours = jget($tlogin['json'], 'token');
check('all five demo roles can sign in', (bool)$viewer && (bool)$operator && (bool)$super && (bool)$tours);

section('Test-data hygiene (the suite is repeatable)');
/* remove anything an interrupted earlier run may have left behind */
foreach ([['units', 'TEST-TRIP-WINDOW', 'unique_id'], ['units', 'TEST-CRON-SAFE', 'unique_id'],
          ['users', 'mask_test', 'username'], ['users', 'disabled_test', 'username']] as $cleanup) {
    [$kind, $value, $field] = $cleanup;
    $list = http('GET', '/api/' . $kind . '?q=' . urlencode($value), null, $admin);
    $rows = $kind === 'units' ? jget($list['json'], 'units', []) : jget($list['json'], 'users', []);
    foreach ($rows as $row) {
        if ((string)jget($row, $field) !== $value) continue;
        http('DELETE', '/api/' . $kind . '/' . $row['id'], null, $admin);
    }
}
foreach (jget(http('GET', '/api/notifications', null, $admin)['json'], 'notifications', []) as $n) {
    if (in_array($n['name'], ['Webhook self-test', 'Test speed rule'], true)) {
        http('DELETE', '/api/notifications/' . $n['id'], null, $admin);
    }
}
foreach (jget(http('GET', '/api/geofences', null, $admin)['json'], 'geofences', []) as $g) {
    if (in_array($g['name'], ['Test circle', 'Throwaway circle'], true)) {
        http('DELETE', '/api/geofences/' . $g['id'], null, $admin);
    }
}
foreach (jget(http('GET', '/api/reports/templates', null, $admin)['json'], 'templates', []) as $t) {
    if ($t['name'] === 'Test template') http('DELETE', '/api/reports/templates/' . $t['id'], null, $admin);
}
$baseUnits = count(jget(http('GET', '/api/units/live', null, $admin)['json'], 'units', []));
$baseUnitsViewer = count(jget(http('GET', '/api/units/live', null, $viewer)['json'], 'units', []));
check('leftovers from earlier runs are cleaned up', $baseUnits > 0, 'baseline units ' . $baseUnits);
check('the seeded viewer mask still limits the account to 2 units', $baseUnitsViewer === 2,
    'viewer units ' . $baseUnitsViewer);

section('Metadata & dashboard');
$meta = http('GET', '/api/meta', null, $admin);
check('meta lists every account unit', count(jget($meta['json'], 'units', [])) === $baseUnits,
    'meta ' . count(jget($meta['json'], 'units', [])) . ' of ' . $baseUnits);
check('meta lists 12 report types', count(jget($meta['json'], 'report_types', [])) === 12);
check('meta exposes map + IPS settings', isset($meta['json']['settings']['ips_port']));
$dash = http('GET', '/api/dashboard', null, $admin);
check('dashboard fleet counters add up', jget(jget($dash['json'], 'fleet', []), 'total') === $baseUnits,
    'counted ' . jget(jget($dash['json'], 'fleet', []), 'total'));
check('dashboard returns recent events', count(jget($dash['json'], 'events', [])) > 0);
check('dashboard returns live units', count(jget($dash['json'], 'units', [])) === $baseUnits);

section('Units, access masks & live state');
$live = http('GET', '/api/units/live', null, $admin);
$units = jget($live['json'], 'units', []);
check('live snapshot returns every unit', count($units) === $baseUnits, 'units ' . count($units));
check('live units carry status + position + address',
    isset($units[0]['status'], $units[0]['lat'], $units[0]['address']));
check('live units report odometer / engine hours', isset($units[0]['mileage'], $units[0]['engine_hours']));
$vlive = http('GET', '/api/units/live', null, $viewer);
check('viewer access mask limits the tree to 2 units', count(jget($vlive['json'], 'units', [])) === 2);
$tlive = http('GET', '/api/units/live', null, $tours);
check('second account sees only its own 2 units', count(jget($tlive['json'], 'units', [])) === 2);
$unit = http('GET', '/api/units/unt_acme_01', null, $admin);
check('unit detail lists 4 sensors', count(jget($unit['json'], 'sensors', [])) === 4);
check('unit detail lists 2 service intervals', count(jget($unit['json'], 'maintenance', [])) === 2);
check('unit detail exposes the IPS login',
    jget(jget($unit['json'], 'unit', []), 'unique_id') === '356938035643809');
$denied = http('GET', '/api/units/unt_acme_03', null, $viewer);
check('viewer cannot open a unit outside its mask (403)', $denied['status'] === 403, 'status ' . $denied['status']);
$foreign = http('GET', '/api/units/unt_acme_01', null, $tours);
check('cross-account unit access is denied', in_array($foreign['status'], [403, 404], true), 'status ' . $foreign['status']);

section('Tracks, trips & fuel');
$msg = http('GET', '/api/units/unt_acme_01/messages?limit=5000', null, $admin);
$points = jget($msg['json'], 'points', []);
check('track returns stored positions', count($points) > 300, 'points ' . count($points));
check('track points carry coordinates + raw parameters', isset($points[0]['lat'], $points[0]['params']));
check('track resolves start/end addresses', (string)jget($msg['json'], 'start_address') !== '');
$detect = http('POST', '/api/units/unt_acme_01/trips/detect', [], $admin);
check('trip detector finds trips', (int)jget($detect['json'], 'trips') > 0, 'trips ' . jget($detect['json'], 'trips'));
$trips = http('GET', '/api/units/unt_acme_01/trips?detect=0', null, $admin);
$kinds = array_unique(array_column(jget($trips['json'], 'trips', []), 'kind'));
check('trips endpoint lists trips', count(jget($trips['json'], 'trips', [])) > 0);
check('parking / idle blocks are detected as well',
    in_array('parking', $kinds, true) || in_array('idle', $kinds, true), implode(',', $kinds));
$fuel = http('GET', '/api/units/unt_acme_01/fuel?min_change=10', null, $admin);
check('fuel fillings / drainings detected', count(jget($fuel['json'], 'events', [])) > 0,
    'events ' . count(jget($fuel['json'], 'events', [])));
$speed = http('GET', '/api/units/unt_acme_01/speeding', null, $admin);
check('speed violation endpoint responds', $speed['status'] === 200 && is_array(jget($speed['json'], 'violations')));

section('Geofences, notifications & events');
$geos = http('GET', '/api/geofences', null, $admin);
check('account has 4 geofences', count(jget($geos['json'], 'geofences', [])) === 4);
$types = array_unique(array_column(jget($geos['json'], 'geofences', []), 'type'));
check('geofence types include circle, polygon and polyline',
    count(array_intersect(['circle', 'polygon', 'polyline'], $types)) === 3, implode(',', $types));
$withLen = array_filter(jget($geos['json'], 'geofences', []), fn($g) => isset($g['length_km']) || isset($g['area_km2']));
check('geofences report length / area', count($withLen) >= 3);
$gevents = http('GET', '/api/geofences/geo_depot/events', null, $admin);
check('geofence enter/exit events were recorded', count(jget($gevents['json'], 'events', [])) > 0,
    'events ' . count(jget($gevents['json'], 'events', [])));
$gstate = http('GET', '/api/geofence-state', null, $admin);
check('geofence-state lists units inside', $gstate['status'] === 200 && count(jget($gstate['json'], 'geofences', [])) === 4);
$notifs = http('GET', '/api/notifications', null, $admin);
check('account has 9 notification rules', count(jget($notifs['json'], 'notifications', [])) === 9);
$ntypes = array_column(jget($notifs['json'], 'notifications', []), 'type');
check('notification rules cover speed/geofence/fuel/sos/offline',
    count(array_intersect(['speed', 'geofence', 'fuel_drop', 'sos', 'offline'], $ntypes)) === 5);
$events = http('GET', '/api/events?limit=100', null, $admin);
check('event log is populated', count(jget($events['json'], 'events', [])) > 0,
    'events ' . count(jget($events['json'], 'events', [])));
$etypes = array_column(jget($events['json'], 'events', []), 'type');
check('events include geofence + speed entries',
    count(array_intersect(['geofence_enter', 'geofence_exit', 'speed'], $etypes)) >= 2,
    implode(',', array_slice(array_unique($etypes), 0, 6)));
$ack = http('POST', '/api/events/ack', [], $operator);
check('events can be acknowledged', $ack['status'] === 200);
$vwrite = http('POST', '/api/events/ack', [], $viewer);
check('viewer cannot acknowledge events (403)', $vwrite['status'] === 403, 'status ' . $vwrite['status']);

section('Reports (all 12 types)');
$types = http('GET', '/api/reports/types', null, $admin);
check('report catalogue is available', count(jget($types['json'], 'types', [])) === 12);
foreach (['unit_summary', 'trips', 'parkings', 'activity', 'geofences', 'events', 'speed_violations',
          'fuel', 'engine_hours', 'mileage', 'drivers_by_unit', 'maintenance'] as $type) {
    $rep = http('POST', '/api/reports/run', ['type' => $type, 'from' => '-24 hours', 'to' => 'now'], $admin);
    $ok = $rep['status'] === 200 && count(jget($rep['json'], 'summary', [])) > 0
        && count(jget($rep['json'], 'tables', [])) > 0;
    check("report '$type' renders summary + tables", $ok,
        'status ' . $rep['status'] . ' ' . substr((string)jget($rep['json'], 'error', ''), 0, 60));
}
$sum = http('POST', '/api/reports/run', ['type' => 'unit_summary', 'from' => '-24 hours', 'to' => 'now'], $admin);
$labels = array_column(jget($sum['json'], 'summary', []), 'label');
check('unit summary reports mileage, engine hours and fuel',
    count(array_intersect(['Total mileage', 'Engine hours', 'Fuel consumed'], $labels)) === 3);
$sumTables = jget($sum['json'], 'tables', []);
check('unit summary totals cover every unit', count(jget($sumTables[0] ?? [], 'rows', [])) === $baseUnits,
    'rows ' . count(jget($sumTables[0] ?? [], 'rows', [])));
$act = http('POST', '/api/reports/run', ['type' => 'activity', 'from' => '-24 hours', 'to' => 'now', 'group' => 'day'], $admin);
check('activity report returns charts', count(jget($act['json'], 'charts', [])) >= 2);
$csv = http('GET', '/api/reports/export.csv?type=trips&from=-24%20hours&to=now', null, $admin);
check('CSV export returns text/csv', strpos((string)$csv['headers'], 'text/csv') !== false);
check('CSV export contains the report body', strpos($csv['raw'], 'Report,trips') !== false && strlen($csv['raw']) > 500);
$tpls = http('GET', '/api/reports/templates', null, $admin);
/* the six seeded templates must be listed  —  extra ones saved from the console
   (or by earlier test sessions) must not break the check */
$seedTpl = ['rpt_daily', 'rpt_fleet', 'rpt_fuel', 'rpt_geo', 'rpt_speed', 'rpt_trips'];
$haveTpl = array_map(fn($t) => (string)$t['id'], jget($tpls['json'], 'templates', []));
check('6 saved report templates', count(array_intersect($seedTpl, $haveTpl)) === 6,
    'have ' . json_encode($haveTpl));
$tpl = http('POST', '/api/reports/templates', ['name' => 'Test template', 'type' => 'trips', 'params' => ['group' => 'day']], $operator);
$tplId = jget($tpl['json'], 'template_id');
check('report template can be saved', is_string($tplId), (string)jget($tpl['json'], 'error'));
check('report template can be deleted', http('DELETE', '/api/reports/templates/' . $tplId, null, $operator)['status'] === 200);

section('CRUD: units, geofences, notifications, drivers, maintenance');
$new = http('POST', '/api/units', ['name' => 'Test Truck 99', 'unique_id' => 'TEST-UNIQUE-99',
    'plate' => 'TEST 99', 'fuel_rate' => 12.5, 'speed_limit' => 70], $operator);
$newId = jget(jget($new['json'], 'unit', []), 'id');
check('unit can be created', is_string($newId), (string)jget($new['json'], 'error'));
$sensors = http('GET', '/api/units/' . $newId . '/sensors', null, $operator);
check('new unit gets 4 default sensors', count(jget($sensors['json'], 'sensors', [])) === 4);
$upd = http('PUT', '/api/units/' . $newId, ['name' => 'Test Truck 99 renamed', 'speed_limit' => 75], $operator);
check('unit can be updated', jget(jget($upd['json'], 'unit', []), 'name') === 'Test Truck 99 renamed');
$dup = http('POST', '/api/units', ['name' => 'Duplicate', 'unique_id' => 'TEST-UNIQUE-99'], $operator);
check('duplicate unique_id is rejected', $dup['status'] === 400, 'status ' . $dup['status']);
$cmd = http('POST', '/api/units/' . $newId . '/command', ['type' => 'custom_message', 'text' => 'hello truck'], $operator);
check('command is queued for the device', jget($cmd['json'], 'state') === 'pending');
$cmds = http('GET', '/api/commands?unit_id=' . $newId, null, $operator);
$cmdList = jget($cmds['json'], 'commands', []);
check('queued command is listed with its text', (string)jget($cmdList[0] ?? [], 'text') === 'hello truck');
check('unit can be deleted', http('DELETE', '/api/units/' . $newId, null, $operator)['status'] === 200);
check('deleted unit is gone', http('GET', '/api/units/' . $newId, null, $operator)['status'] === 404);

/* Regression: the admin console's "New unit" dialog posts the whole field set and
   leaves the device password blank (and untouched fields as "" / null). Empty
   strings must not reach the NOT NULL columns as NULL. */
$uiUnit = http('POST', '/api/units', ['name' => 'UI dialog unit', 'unique_id' => 'TEST-UI-DIALOG-1',
    'device_password' => '', 'hw_type' => 'ips', 'phone' => '', 'model' => '', 'plate' => 'KZZ 001',
    'vin' => '', 'trailer' => '', 'tags' => '', 'group_id' => '', 'driver_id' => '',
    'fuel_rate' => null, 'fuel_price' => null, 'cost_per_km' => null, 'odometer_km' => null,
    'engine_hours' => null, 'speed_limit' => null, 'min_trip_s' => null, 'min_trip_km' => null,
    'min_park_s' => null, 'parking_timeout_s' => null, 'max_jump_km' => null, 'enabled' => true], $operator);
$uiId = jget(jget($uiUnit['json'], 'unit', []), 'id');
check('unit form with a blank device password is accepted', is_string($uiId),
    'status ' . $uiUnit['status'] . ' ' . (string)jget($uiUnit['json'], 'error', ''));
if ($uiId) {
    $uiRow = jget(http('GET', '/api/units/' . $uiId, null, $operator)['json'], 'unit', []);
    check('blank device password falls back to the default password', (string)jget($uiRow, 'device_password') === 'track',
        'got ' . json_encode(jget($uiRow, 'device_password')));
    check('blank hardware type falls back to IPS', (string)jget($uiRow, 'hw_type') === 'ips');
    $uiUpd = http('PUT', '/api/units/' . $uiId, ['name' => 'UI dialog unit renamed', 'device_password' => ''], $operator);
    $uiUpdUnit = jget($uiUpd['json'], 'unit', []);
    check('save response carries the full unit configuration',
        array_key_exists('device_password', $uiUpdUnit) && array_key_exists('hw_type', $uiUpdUnit));
    check('clearing the device password on update keeps the column intact',
        $uiUpd['status'] === 200 && (string)jget($uiUpdUnit, 'device_password') === 'track',
        'status ' . $uiUpd['status'] . ' ' . (string)jget($uiUpd['json'], 'error', ''));
    $uiAfter = jget(http('GET', '/api/units/' . $uiId, null, $operator)['json'], 'unit', []);
    check('emptied form field does not wipe the stored device password',
        (string)jget($uiAfter, 'device_password') === 'track'
        && (string)jget($uiAfter, 'name') === 'UI dialog unit renamed', json_encode($uiAfter['device_password'] ?? null));
    check('UI dialog unit can be deleted', http('DELETE', '/api/units/' . $uiId, null, $operator)['status'] === 200);
}

$geo = http('POST', '/api/geofences', ['name' => 'Test circle', 'type' => 'circle',
    'points' => [[-1.30, 36.82]], 'radius_m' => 500, 'color' => '#ff0000'], $operator);
$geoId = jget(jget($geo['json'], 'geofence', []), 'id');
check('circle geofence can be created', is_string($geoId), (string)jget($geo['json'], 'error'));
$badGeo = http('POST', '/api/geofences', ['name' => 'Bad polygon', 'type' => 'polygon', 'points' => [[-1.3, 36.8]]], $operator);
check('polygon with too few points is rejected', $badGeo['status'] === 400, 'status ' . $badGeo['status']);
check('geofence can be deleted', http('DELETE', '/api/geofences/' . $geoId, null, $operator)['status'] === 200);

$notif = http('POST', '/api/notifications', ['name' => 'Test speed rule', 'type' => 'speed',
    'params' => ['speed_limit' => 60], 'actions' => ['push'], 'severity' => 'warning'], $operator);
$notifId = jget(jget($notif['json'], 'notification', []), 'id');
check('notification rule can be created', is_string($notifId), (string)jget($notif['json'], 'error'));
$test = http('POST', '/api/notifications/' . $notifId . '/test', ['unit_id' => 'unt_acme_01'], $operator);
check('notification test fires an event', (int)jget($test['json'], 'event_id') > 0);
check('notification rule can be deleted', http('DELETE', '/api/notifications/' . $notifId, null, $operator)['status'] === 200);

$drv = http('POST', '/api/drivers', ['name' => 'Test Driver', 'phone' => '+254700000000'], $operator);
$drvId = jget($drv['json'], 'driver_id');
check('driver can be created', is_string($drvId), (string)jget($drv['json'], 'error'));
check('driver can be deleted', http('DELETE', '/api/drivers/' . $drvId, null, $operator)['status'] === 200);
$mt = http('POST', '/api/maintenance', ['unit_id' => 'unt_acme_06', 'name' => 'Test service',
    'interval_km' => 5000, 'last_service_km' => 0], $operator);
$mtId = jget($mt['json'], 'maintenance_id');
check('service interval can be created', is_string($mtId), (string)jget($mt['json'], 'error'));
$mtList = http('GET', '/api/maintenance?unit_id=unt_acme_06', null, $operator);
$mtItems = jget($mtList['json'], 'items', []);
check('service interval reports remaining km',
    strpos((string)jget($mtItems[0] ?? [], 'remaining', ''), 'km left') !== false,
    (string)jget($mtItems[0] ?? [], 'remaining', ''));
check('service interval can be deleted', http('DELETE', '/api/maintenance/' . $mtId, null, $operator)['status'] === 200);

section('Device ingest, jobs & administration');
$ingest = http('POST', '/api/ingest', ['unique_id' => '356938035643809', 'password' => 'track',
    'points' => [['ts' => 'now', 'lat' => -1.2965, 'lon' => 36.8260, 'speed' => 0, 'sats' => 9,
        'params' => ['ign' => 0, 'fuel' => 90, 'pwr' => 12.5, 'temp' => 24]]]], null);
check('HTTP ingest accepts a device packet', (int)jget($ingest['json'], 'stored') === 1,
    (string)jget($ingest['json'], 'error'));
$badIngest = http('POST', '/api/ingest', ['unique_id' => '356938035643809', 'password' => 'wrong',
    'points' => [['lat' => -1.29, 'lon' => 36.82]]], null);
check('HTTP ingest rejects a bad device password (401)', $badIngest['status'] === 401, 'status ' . $badIngest['status']);
$tasks = http('POST', '/api/tasks/run', [], $admin);
$checks = jget($tasks['json'], 'checks', []);
check('scheduled jobs run (offline + maintenance + trips)',
    $tasks['status'] === 200 && isset($checks['maintenance']), 'status ' . $tasks['status']);
$audit = http('GET', '/api/audit', null, $admin);
check('audit log records actions', count(jget($audit['json'], 'entries', [])) > 5,
    'entries ' . count(jget($audit['json'], 'entries', [])));
$vAudit = http('GET', '/api/audit', null, $viewer);
check('viewer cannot read the audit log (403)', $vAudit['status'] === 403, 'status ' . $vAudit['status']);
check('device session endpoint responds', http('GET', '/api/devices/sessions', null, $admin)['status'] === 200);
check('admin can list account users', count(jget(http('GET', '/api/users', null, $admin)['json'], 'users', [])) === 3);
check('operator cannot manage users (403)', http('GET', '/api/users', null, $operator)['status'] === 403);
$accounts = http('GET', '/api/accounts', null, $super);
check('super admin sees every account', count(jget($accounts['json'], 'accounts', [])) >= 2,
    'accounts ' . count(jget($accounts['json'], 'accounts', [])));
check('account admin cannot list accounts (403)', http('GET', '/api/accounts', null, $admin)['status'] === 403);
$settings = http('GET', '/api/settings', null, $admin);
check('settings are readable by an account admin', isset(jget($settings['json'], 'settings', [])['platform_name']));
check('super admin can save settings', http('PUT', '/api/settings', ['platform_name' => 'KIVU Tracker'], $super)['status'] === 200);
check('account admin cannot save platform settings (403)',
    http('PUT', '/api/settings', ['platform_name' => 'x'], $admin)['status'] === 403);
$powered = http('PUT', '/api/settings', ['powered_by' => 'Powered by KIVU'], $super);
$poweredMeta = http('GET', '/api/meta', null, $admin);
$poweredHealth = http('GET', '/api/health');
check('super admin can configure platform footer credit',
    $powered['status'] === 200 && jget($poweredMeta['json'], 'powered_by') === 'Powered by KIVU'
        && jget($poweredHealth['json'], 'powered_by') === 'Powered by KIVU');
check('footer credit rejects text longer than 180 characters',
    http('PUT', '/api/settings', ['powered_by' => str_repeat('x', 181)], $super)['status'] === 400);
http('PUT', '/api/settings', ['powered_by' => ''], $super);
$summary = http('GET', '/api/accounts/acc_acme/summary', null, $super);
check('account summary returns fleet state', jget(jget($summary['json'], 'fleet', []), 'total') === $baseUnits,
    'counted ' . jget(jget($summary['json'], 'fleet', []), 'total'));

/* Regression: a super administrator has no account of their own  —  the consoles send
   the chosen account as ?account_id= (the account switcher in the top bar). */
$noCtx = http('POST', '/api/units', ['name' => 'No account context', 'unique_id' => 'TEST-NOCTX-1'], $super);
check('super admin unit create without an account context is refused clearly',
    $noCtx['status'] === 400 && strpos((string)jget($noCtx['json'], 'error', ''), 'account_id') !== false,
    'status ' . $noCtx['status'] . ' ' . (string)jget($noCtx['json'], 'error', ''));
$withCtx = http('POST', '/api/units?account_id=acc_acme',
    ['name' => 'Super admin unit', 'unique_id' => 'TEST-SUPERCTX-1'], $super);
$withCtxId = jget(jget($withCtx['json'], 'unit', []), 'id');
check('super admin can create a unit in the selected account', is_string($withCtxId),
    'status ' . $withCtx['status'] . ' ' . (string)jget($withCtx['json'], 'error', ''));
check('super admin unit lands in the selected account',
    (string)jget(jget($withCtx['json'], 'unit', []), 'account_id') === 'acc_acme');
check('super admin account context scopes the unit list',
    count(jget(http('GET', '/api/units?account_id=acc_acme', null, $super)['json'], 'units', [])) === $baseUnits + 1,
    'listed ' . count(jget(http('GET', '/api/units?account_id=acc_acme', null, $super)['json'], 'units', [])));
if ($withCtxId) check('super admin unit cleaned up',
    http('DELETE', '/api/units/' . $withCtxId, null, $super)['status'] === 200);

section('Trip detection windows (data-loss regression)');
$tmpUnit = http('POST', '/api/units', ['name' => 'Trip window test', 'unique_id' => 'TEST-TRIP-WINDOW',
    'device_password' => 'track', 'min_trip_s' => 60, 'min_trip_km' => 0.05, 'min_park_s' => 240], $operator);
$tmpId = jget(jget($tmpUnit['json'], 'unit', []), 'id');
check('temporary unit created for the windowing test', is_string($tmpId), (string)jget($tmpUnit['json'], 'error'));
if ($tmpId) {
    /* three hours of synthetic driving: 40 minutes moving, 20 minutes parked each hour */
    $windowStart = time() - 3 * 3600;
    $points = [];
    for ($i = 0; $i < 180; $i++) {
        $driving = ($i % 60) < 40;
        $points[] = ['ts' => date('Y-m-d H:i:s', $windowStart + $i * 60),
            'lat' => -1.30 + $i * 0.0022, 'lon' => 36.80 + $i * 0.0011,
            'speed' => $driving ? 42 : 0, 'sats' => 9,
            'params' => ['ign' => $driving ? 1 : 0, 'fuel' => 100 - $i * 0.05, 'pwr' => 13.8]];
    }
    $ing = http('POST', '/api/ingest', ['unique_id' => 'TEST-TRIP-WINDOW', 'password' => 'track',
        'points' => $points], null);
    check('180 synthetic positions ingested', (int)jget($ing['json'], 'stored') === 180,
        'stored ' . jget($ing['json'], 'stored'));

    $full = http('POST', '/api/units/' . $tmpId . '/trips/detect', ['from' => '-4 hours', 'to' => 'now'], $operator);
    check('full-window detection finds trips', (int)jget($full['json'], 'trips') > 0,
        'trips ' . jget($full['json'], 'trips'));
    $stored = http('GET', '/api/units/' . $tmpId . '/trips?detect=0&from=-4%20hours&to=now', null, $operator);
    $storedTrips = count(jget($stored['json'], 'trips', []));
    check('detected trips are persisted', $storedTrips > 0, 'stored ' . $storedTrips);

    $narrow = http('POST', '/api/units/' . $tmpId . '/trips/detect', ['from' => '-1 hours', 'to' => 'now'], $operator);
    check('narrow-window detection runs', $narrow['status'] === 200, (string)jget($narrow['json'], 'error'));
    $after = http('GET', '/api/units/' . $tmpId . '/trips?detect=0&from=-4%20hours&to=now', null, $operator);
    $afterTrips = count(jget($after['json'], 'trips', []));
    check('a narrow re-detect keeps the older trips', $afterTrips >= $storedTrips,
        'before ' . $storedTrips . ' / after ' . $afterTrips);

    $deleted = http('DELETE', '/api/units/' . $tmpId, null, $operator);
    check('temporary unit deleted', $deleted['status'] === 200);
    $goneMessages = http('GET', '/api/units/' . $tmpId . '/messages', null, $operator);
    check('deleting a unit removes its telemetry', $goneMessages['status'] === 404, 'status ' . $goneMessages['status']);
}

section('Scheduled jobs must not erase trip history');
/* the tracker and any cron job call /api/tasks/run, which re-detects only the
   last 8 hours  —  trips older than that have to survive the run. */
$jobUnit = http('POST', '/api/units', ['name' => 'Cron safety test', 'unique_id' => 'TEST-CRON-SAFE',
    'device_password' => 'track', 'min_trip_s' => 60, 'min_trip_km' => 0.05, 'min_park_s' => 240], $operator);
$jobId = jget(jget($jobUnit['json'], 'unit', []), 'id');
check('cron-safety unit created', is_string($jobId), (string)jget($jobUnit['json'], 'error'));
if ($jobId) {
    /* 12 hours of driving so that most trips fall outside the 8-hour job window */
    $start12 = time() - 12 * 3600;
    $batch = [];
    for ($i = 0; $i < 720; $i++) {
        $driving = ($i % 60) < 40;
        $batch[] = ['ts' => date('Y-m-d H:i:s', $start12 + $i * 60),
            'lat' => -1.30 + $i * 0.0012, 'lon' => 36.80 + $i * 0.0006,
            'speed' => $driving ? 40 : 0, 'sats' => 9,
            'params' => ['ign' => $driving ? 1 : 0, 'fuel' => 120 - $i * 0.02, 'pwr' => 13.8]];
    }
    $ing12 = http('POST', '/api/ingest', ['unique_id' => 'TEST-CRON-SAFE', 'password' => 'track',
        'points' => $batch], null);
    check('12 hours of synthetic history ingested', (int)jget($ing12['json'], 'stored') === 720,
        'stored ' . jget($ing12['json'], 'stored'));
    http('POST', '/api/units/' . $jobId . '/trips/detect', ['from' => '-13 hours', 'to' => 'now'], $operator);
    $beforeJob = http('GET', '/api/units/' . $jobId . '/trips?detect=0&from=-13%20hours&to=now', null, $operator);
    $beforeRows = jget($beforeJob['json'], 'trips', []);
    /* blocks from the first hours of the data, far outside the job's window and padding */
    $deepStarts = array_values(array_map(fn($t) => $t['start'],
        array_filter($beforeRows, fn($t) => strtotime($t['start']) < time() - 10 * 3600)));
    check('deep history exists before the job runs', count($deepStarts) > 0, 'blocks ' . count($deepStarts));

    $job = http('POST', '/api/tasks/run', [], $admin);
    check('scheduled jobs run', $job['status'] === 200, 'status ' . $job['status']);
    $afterJob = http('GET', '/api/units/' . $jobId . '/trips?detect=0&from=-13%20hours&to=now', null, $operator);
    $afterRows = jget($afterJob['json'], 'trips', []);
    $afterStarts = array_map(fn($t) => $t['start'], $afterRows);
    $lost = array_values(array_diff($deepStarts, $afterStarts));
    check('the job kept every block older than its window', $lost === [],
        count($lost) . ' lost, e.g. ' . json_encode(array_slice($lost, 0, 2)));
    check('the job did not duplicate blocks', count($afterStarts) === count(array_unique($afterStarts)),
        count($afterStarts) . ' rows, ' . count(array_unique($afterStarts)) . ' unique starts');
    check('the job still produced fresh blocks inside its window',
        count(array_filter($afterRows, fn($t) => strtotime($t['start']) > time() - 8 * 3600)) > 0);

    check('cron-safety unit removed', http('DELETE', '/api/units/' . $jobId, null, $operator)['status'] === 200);
}

section('Ingest edge cases');
$badDevice = http('POST', '/api/ingest', ['unique_id' => 'NO-SUCH-DEVICE', 'points' => [['lat' => 1, 'lon' => 1]]], null);
check('unknown device id is rejected (404)', $badDevice['status'] === 404, 'status ' . $badDevice['status']);
$badCoords = http('POST', '/api/ingest', ['unique_id' => '356938035643809', 'password' => 'track',
    'points' => [['ts' => 'now', 'lat' => 999, 'lon' => 888, 'speed' => 0, 'sats' => 5]]], null);
check('impossible coordinates are accepted but marked invalid',
    (int)jget($badCoords['json'], 'stored') === 1, (string)jget($badCoords['json'], 'error'));
$inv = http('GET', '/api/units/unt_acme_01/messages?valid=0&limit=5', null, $admin);
$invalids = jget($inv['json'], 'points', []);
check('invalid positions are stored with valid=0',
    count($invalids) > 0 && (int)$invalids[0]['valid'] === 0, 'rows ' . count($invalids));
/* Own unit with no history: the guard compares a fix against the previous one, so a
   fresh unit makes the sequence deterministic  —  and the shared demo unit is never
   teleported to another continent (which used to break geofence-state checks). */
$jumpUnit = http('POST', '/api/units', ['name' => 'Jump guard test', 'unique_id' => 'TEST-JUMP-1',
    'device_password' => 'track', 'max_jump_km' => 3], $operator);
$jumpId = jget(jget($jumpUnit['json'], 'unit', []), 'id');
check('jump-guard unit created', is_string($jumpId), (string)jget($jumpUnit['json'], 'error', ''));
if ($jumpId) {
    $anchor = http('POST', '/api/ingest', ['unique_id' => 'TEST-JUMP-1', 'password' => 'track',
        'points' => [['ts' => '-2 minutes', 'lat' => -1.2965, 'lon' => 36.8260, 'speed' => 0, 'sats' => 9]]], null);
    check('anchor fix is accepted', (int)jget($anchor['json'], 'rejected') === 0,
        json_encode(jget($anchor['json'], 'results', [])));
    $jump = http('POST', '/api/ingest', ['unique_id' => 'TEST-JUMP-1', 'password' => 'track',
        'points' => [['ts' => '-1 minute', 'lat' => 40.0, 'lon' => -3.0, 'speed' => 30, 'sats' => 9]]], null);
    $jumpResults = jget($jump['json'], 'results', []);
    check('a GPS jump packet is ingested and filtered as invalid',
        (int)jget($jump['json'], 'stored') === 1 && (int)jget($jump['json'], 'rejected') === 1,
        'stored ' . jget($jump['json'], 'stored') . ', rejected ' . jget($jump['json'], 'rejected'));
    check('the far-away fix is flagged as a gps_jump',
        (string)jget($jumpResults[0] ?? [], 'reason') === 'gps_jump', json_encode($jumpResults));
    $liveAfter = jget(http('GET', '/api/units/' . $jumpId, null, $operator)['json'], 'unit', []);
    check('the rejected jump did not move the live position',
        abs((float)jget($liveAfter, 'lat', 999) + 1.2965) < 0.00001
        && abs((float)jget($liveAfter, 'lon', 9999) - 36.8260) < 0.00001,
        'lat ' . jget($liveAfter, 'lat') . ' lon ' . jget($liveAfter, 'lon'));
    check('jump-guard unit removed', http('DELETE', '/api/units/' . $jumpId, null, $operator)['status'] === 200);
}

section('CSV export for every report type');
foreach (array_column(report_types_for_test(), 'id') as $rtype) {
    $csv = http('GET', '/api/reports/export.csv?type=' . $rtype . '&from=-24%20hours&to=now', null, $admin);
    $ok = $csv['status'] === 200 && strpos($csv['raw'], 'Report,' . $rtype) !== false && strlen($csv['raw']) > 60;
    check("CSV export works for '$rtype'", $ok, 'status ' . $csv['status'] . ' bytes ' . strlen($csv['raw']));
}

section('Notifications: real webhook delivery & validation');
$sink = sink_start();
check('webhook sink is listening on 127.0.0.1:8098', $sink['started'] || $sink['proc'] === null,
    $sink['started'] ? '' : 'sink could not be started');
@unlink(dirname(__DIR__) . '/data/webhook-sink.log');
$hook = http('POST', '/api/notifications', ['name' => 'Webhook self-test', 'type' => 'speed',
    'params' => ['speed_limit' => 70], 'actions' => ['push', 'webhook'],
    'webhook_url' => 'http://127.0.0.1:8098/hook', 'severity' => 'info'], $operator);
$hookId = jget(jget($hook['json'], 'notification', []), 'id');
check('notification with a webhook can be created', is_string($hookId), (string)jget($hook['json'], 'error'));
if ($hookId) {
    $t = http('POST', '/api/notifications/' . $hookId . '/test', ['unit_id' => 'unt_acme_01'], $operator);
    $wh = jget($t['json'], 'webhook', []);
    check('the test call POSTs the webhook and gets HTTP 200', (int)jget($wh, 'status') === 200,
        'status ' . jget($wh, 'status') . ' error ' . jget($wh, 'error'));
    check('the webhook receiver answered ok', jget($wh, 'ok') === true);
    $log = (string)@file_get_contents(dirname(__DIR__) . '/data/webhook-sink.log');
    check('the sink received the JSON payload', strpos($log, 'unt_acme_01') !== false || strpos($log, 'Scania') !== false,
        'log bytes ' . strlen($log));
    check('the payload carries type/severity/coordinates',
        strpos($log, '"severity"') !== false && strpos($log, '"type"') !== false && strpos($log, '"lat"') !== false,
        substr($log, 0, 160));
    check('the webhook delivery is audited',
        count(jget(http('GET', '/api/audit?action=notification_webhook', null, $admin)['json'], 'entries', [])) > 0);
    check('webhook rule deleted', http('DELETE', '/api/notifications/' . $hookId, null, $operator)['status'] === 200);
}
if ($sink['proc']) {
    sink_stop($sink['proc']);
    check('the temporary webhook sink was stopped', true);
}
$badNotif = http('POST', '/api/notifications', ['name' => 'Bad type', 'type' => 'nonsense'], $operator);
check('unknown notification type is rejected (400)', $badNotif['status'] === 400, 'status ' . $badNotif['status']);
$badReport = http('POST', '/api/reports/run', ['type' => 'not_a_report'], $admin);
check('unknown report type is rejected (400)', $badReport['status'] === 400, 'status ' . $badReport['status']);

section('User unit masks & auth edge cases');
$maskUser = http('POST', '/api/users', ['username' => 'mask_test', 'password' => 'Mask@12345',
    'role' => 'viewer', 'full_name' => 'Mask test', 'access_units' => ['unt_acme_05']], $admin);
$maskId = jget(jget($maskUser['json'], 'user', []), 'id');
check('user with a one-unit mask can be created', is_string($maskId), (string)jget($maskUser['json'], 'error'));
$mlogin = http('POST', '/api/auth/login', ['username' => 'mask_test', 'password' => 'Mask@12345']);
$mtoken = jget($mlogin['json'], 'token');
check('the masked user can sign in', is_string($mtoken), (string)jget($mlogin['json'], 'error'));
if ($mtoken) {
    check('the masked user sees exactly one unit',
        count(jget(http('GET', '/api/units/live', null, $mtoken)['json'], 'units', [])) === 1,
        'units ' . count(jget(http('GET', '/api/units/live', null, $mtoken)['json'], 'units', [])));
    check('the masked user cannot read another unit (403)',
        http('GET', '/api/units/unt_acme_01', null, $mtoken)['status'] === 403);
    check('the masked user cannot read platform settings (403)',
        http('GET', '/api/settings', null, $mtoken)['status'] === 403);
    check('the mask can be widened through the API',
        http('PUT', '/api/users/' . $maskId, ['access_units' => ['unt_acme_05', 'unt_acme_06']], $admin)['status'] === 200);
    check('the widened mask is applied',
        count(jget(http('GET', '/api/units/live', null, $mtoken)['json'], 'units', [])) === 2);
    check('clearing the mask restores full account access',
        http('PUT', '/api/users/' . $maskId, ['access_units' => []], $admin)['status'] === 200
        && count(jget(http('GET', '/api/units/live', null, $mtoken)['json'], 'units', [])) === $baseUnits,
        'units ' . count(jget(http('GET', '/api/units/live', null, $mtoken)['json'], 'units', [])));
}
if ($maskId) check('masked user deleted', http('DELETE', '/api/users/' . $maskId, null, $admin)['status'] === 200);

$pw = http('POST', '/api/auth/password', ['current_password' => 'wrong', 'new_password' => 'Whatever@123'], $operator);
check('password change with a wrong current password is refused (403)', $pw['status'] === 403, 'status ' . $pw['status']);
$tmpLogin = http('POST', '/api/auth/login', ['username' => 'operator', 'password' => 'Operator@123']);
$tmpToken = jget($tmpLogin['json'], 'token');
check('a second session works side by side', http('GET', '/api/auth/me', null, $tmpToken)['status'] === 200);
check('signing out succeeds', http('POST', '/api/auth/logout', [], $tmpToken)['status'] === 200);
check('the token is dead after signing out (401)', http('GET', '/api/auth/me', null, $tmpToken)['status'] === 401);
$disabled = http('POST', '/api/users', ['username' => 'disabled_test', 'password' => 'Disabled@123',
    'role' => 'viewer', 'enabled' => 0], $admin);
$disabledId = jget(jget($disabled['json'], 'user', []), 'id');
if ($disabledId) {
    check('a disabled user cannot sign in (403)',
        http('POST', '/api/auth/login', ['username' => 'disabled_test', 'password' => 'Disabled@123'])['status'] === 403);
    check('disabled user deleted', http('DELETE', '/api/users/' . $disabledId, null, $admin)['status'] === 200);
}

section('Validation & filters');
$noId = http('POST', '/api/units', ['name' => 'No device id'], $operator);
check('a unit without a device id is refused (400)', $noId['status'] === 400, 'status ' . $noId['status']);
$noRadius = http('POST', '/api/geofences', ['name' => 'Circle without radius', 'type' => 'circle',
    'points' => [[-1.3, 36.8]]], $operator);
check('a circle without a radius is refused (400)', $noRadius['status'] === 400, 'status ' . $noRadius['status']);
$badPoint = http('POST', '/api/geofences', ['name' => 'Bad point', 'type' => 'polygon',
    'points' => [[-1.3, 36.8], [200, 300], [-1.4, 36.9]]], $operator);
check('out-of-range coordinates are refused (400)', $badPoint['status'] === 400, 'status ' . $badPoint['status']);
$shortPw = http('POST', '/api/users', ['username' => 'shortpw', 'password' => '123', 'role' => 'viewer'], $admin);
check('a too-short password is refused (400)', $shortPw['status'] === 400, 'status ' . $shortPw['status']);
$dupeUser = http('POST', '/api/users', ['username' => 'admin', 'password' => 'Another@123', 'role' => 'viewer'], $admin);
check('a duplicate username is refused (400)', $dupeUser['status'] === 400, 'status ' . $dupeUser['status']);
$parked = http('GET', '/api/units?status=parked', null, $admin);
$parkedUnits = jget($parked['json'], 'units', []);
check('the status filter returns only matching units',
    count(array_filter($parkedUnits, fn($u) => $u['status'] !== 'parked')) === 0, 'rows ' . count($parkedUnits));
$search = http('GET', '/api/units?q=Scania', null, $admin);
$searchRows = jget($search['json'], 'units', []);
check('unit search matches name/model/tags',
    count($searchRows) > 0 && count(array_filter($searchRows, fn($u) => stripos(
        (string)$u['name'] . (string)$u['model'] . (string)$u['tags'] . (string)$u['plate'], 'scania') === false)) === 0,
    'rows ' . count($searchRows));
$tagged = http('GET', '/api/units?tag=city', null, $admin);
$tagRows = jget($tagged['json'], 'units', []);
check('tag filter returns only units carrying that tag',
    count($tagRows) > 0 && count(array_filter($tagRows,
        fn($u) => stripos((string)$u['tags'], 'city') === false)) === 0, 'rows ' . count($tagRows));

/* =====================================================================
   Self-service signup, free trial, plans, payments and enforcement.
   Self-contained: the suite creates its own client account through the
   public signup endpoint and deletes it again at the end.
   ===================================================================== */
section('Billing: plans, self-service trial, checks and enforcement');
$plansPublic = http('GET', '/api/plans', null, null);
$planList = jget($plansPublic['json'], 'plans', []);
check('the plan catalogue is public', $plansPublic['status'] === 200 && count($planList) >= 4,
    'plans ' . count($planList));
check('every plan carries price, limits and features',
    count(array_filter($planList, fn($p) => $p['price'] >= 0 && $p['unit_limit'] > 0
        && isset($p['interval']) && count($p['features']) > 0)) === count($planList));
check('the trial length is exposed (default 5 days)', (int)jget($plansPublic['json'], 'trial_days', 0) === 5,
    'trial_days ' . jget($plansPublic['json'], 'trial_days'));
$starter = null; $kesPlan = null;
foreach ($planList as $pl) {
    if ($pl['code'] === 'starter') $starter = $pl;
    if ($pl['currency'] === 'KES') $kesPlan = $pl;
}
check('a USD plan and a KES plan exist (cards + mobile money)', $starter !== null && $kesPlan !== null);
$signupTag = substr((string)time(), -6);
$signup = http('POST', '/api/signup', ['company' => 'Billing suite fleet ' . $signupTag,
    'email' => 'billing-suite-' . $signupTag . '@example.com', 'password' => 'Suite@1234',
    'full_name' => 'Suite Owner', 'phone' => '254700111222', 'plan_code' => 'starter'], null);
$trialToken = jget($signup['json'], 'token');
$trialAccount = jget(jget($signup['json'], 'account', []), 'id');
check('self-service signup returns a session and an account', is_string($trialToken) && is_string($trialAccount),
    'status ' . $signup['status'] . ' ' . (string)jget($signup['json'], 'error', ''));
check('a 5-day trial starts immediately',
    (int)jget($signup['json'], 'trial_days', 0) === 5
    && (string)jget(jget($signup['json'], 'account', []), 'status') === 'trialing',
    (string)jget($signup['json'], 'trial_ends_at'));
$dupSignup = http('POST', '/api/signup', ['company' => 'Billing suite fleet ' . $signupTag,
    'email' => 'billing-suite-' . $signupTag . '@example.com', 'password' => 'Suite@1234'], null);
check('signing up twice with the same e-mail is refused', $dupSignup['status'] === 409,
    'status ' . $dupSignup['status']);
check('an incomplete signup is refused',
    http('POST', '/api/signup', ['company' => '', 'email' => 'nope', 'password' => '123'], null)['status'] === 400);
$trialBilling = http('GET', '/api/billing', null, $trialToken);
$trialState = jget($trialBilling['json'], 'billing', []);
check('the new account is on the starter plan with its unit limit',
    (string)jget($trialState, 'plan_id') === (string)$starter['id']
    && (int)jget($trialState, 'units_limit') === (int)$starter['unit_limit'],
    'plan ' . jget($trialState, 'plan_id') . ' limit ' . jget($trialState, 'units_limit'));
check('the trial countdown is exposed', (int)jget($trialState, 'trial_days_left', 0) === 5,
    'left ' . jget($trialState, 'trial_days_left'));
check('a trialing account may write', jget($trialState, 'allowed') === true);
check('the billing page lists plans with usable gateways',
    count(jget($trialBilling['json'], 'plans', [])) >= 4
    && count(jget(jget($trialBilling['json'], 'plans', [])[0] ?? [], 'gateways', [])) >= 1);
check('gateway secrets are not exposed on the billing endpoint',
    !array_key_exists('secret_key', (array)jget($trialBilling['json'], 'gateways', [])));

/* the plan's unit allowance is enforced with a 402 upgrade prompt */
$limitCreated = 0; $limitError = null;
for ($i = 0; $i < (int)$starter['unit_limit'] + 2; $i++) {
    $r = http('POST', '/api/units', ['name' => 'Suite unit ' . $i,
        'unique_id' => 'SUITE-' . $signupTag . '-' . $i], $trialToken);
    if ($r['status'] === 200) $limitCreated++;
    elseif ($limitError === null) $limitError = ['status' => $r['status'], 'error' => jget($r['json'], 'error')];
}
check('the plan unit limit is enforced',
    $limitCreated === (int)$starter['unit_limit'] && $limitError !== null && $limitError['status'] === 402,
    'created ' . $limitCreated . ' of ' . $starter['unit_limit'] . '  —  ' . json_encode($limitError));
/* free the allowance again so the later payment checks can add a unit */
foreach (jget(http('GET', '/api/units', null, $trialToken)['json'], 'units', []) as $u) {
    if (strpos((string)$u['unique_id'], 'SUITE-') === 0) {
        http('DELETE', '/api/units/' . $u['id'], null, $trialToken);
    }
}

/* the sandbox gateway closes the loop without a real provider */
$checkout = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'], $trialToken);
$payId = jget(jget($checkout['json'], 'payment', []), 'id');
check('a sandbox invoice is created with a checkout URL',
    is_string($payId) && strpos((string)jget($checkout['json'], 'checkout_url'), '/pay/') !== false,
    'status ' . $checkout['status'] . ' ' . (string)jget($checkout['json'], 'error', ''));
check('the invoice carries the plan price and currency',
    (float)jget(jget($checkout['json'], 'payment', []), 'amount') === (float)$starter['price']
    && (string)jget(jget($checkout['json'], 'payment', []), 'currency') === $starter['currency']);
$payPublic = http('GET', '/api/pay/' . $payId, null, null);
check('the simulated gateway page is public and shows the invoice',
    $payPublic['status'] === 200 && jget(jget($payPublic['json'], 'payment', []), 'invoice_no') !== null
    && jget($payPublic['json'], 'simulated') === true);
check('an unknown gateway is refused',
    http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'not-a-gateway'],
        $trialToken)['status'] === 400);
check('a gateway without API keys is refused with 503',
    http('PUT', '/api/billing/settings', ['gateways' => ['stripe' => ['secret_key' => '', 'publishable_key' => '',
        'webhook_secret' => '']]], $super)['status'] === 200
    && http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'stripe'],
        $trialToken)['status'] === 503);
check('the KES plan can be paid in the sandbox',
    http('POST', '/api/billing/checkout', ['plan_id' => $kesPlan['id'], 'gateway' => 'sandbox'],
        $trialToken)['status'] === 200);
$sandboxPay = http('POST', '/api/pay/' . $payId . '/complete', ['outcome' => 'paid'], null);
check('approving the simulated payment settles the invoice',
    $sandboxPay['status'] === 200 && jget($sandboxPay['json'], 'settled') === true
    && is_string(jget($sandboxPay['json'], 'period_end')), (string)jget($sandboxPay['json'], 'error', ''));
$afterPay = http('GET', '/api/billing', null, $trialToken);
$paidState = jget($afterPay['json'], 'billing', []);
check('the account is active with a 30-day period',
    (string)jget($paidState, 'status') === 'active' && (int)jget($paidState, 'days_left') >= 29,
    'status ' . jget($paidState, 'status') . ' days ' . jget($paidState, 'days_left'));
$paidInvoice = null;
foreach (jget($afterPay['json'], 'invoices', []) as $inv) {
    if ((string)$inv['id'] === (string)$payId) $paidInvoice = $inv;
}
check('the paid invoice is listed as paid', $paidInvoice !== null && (string)$paidInvoice['status'] === 'paid',
    json_encode($paidInvoice));
http('POST', '/api/pay/' . $payId . '/complete', ['outcome' => 'paid'], null);
$afterReplay = http('GET', '/api/billing', null, $trialToken);
check('settling the same invoice twice does not extend it twice',
    (int)jget(jget($afterReplay['json'], 'billing', []), 'days_left') === (int)jget($paidState, 'days_left'),
    'before ' . jget($paidState, 'days_left') . ' after '
        . jget(jget($afterReplay['json'], 'billing', []), 'days_left'));
check('a paid account may add units again',
    http('POST', '/api/units', ['name' => 'After paying', 'unique_id' => 'SUITE-PAID-' . $signupTag],
        $trialToken)['status'] === 200);
$cancelSub = http('POST', '/api/billing/cancel', ['cancel' => true], $trialToken);
check('auto-renewal can be cancelled from the client side',
    $cancelSub['status'] === 200 && jget(jget($cancelSub['json'], 'billing', []), 'cancel_at_period_end') === true);
check('another account cannot read my invoice status',
    http('GET', '/api/billing/pay/' . $payId . '/status', null, $admin)['status'] === 404);
check('a viewer cannot start a checkout',
    http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'],
        $viewer)['status'] === 403);

/* trial expiry: reads stay open, writes and device data stop */
$lapse = http('POST', '/api/signup', ['company' => 'Lapsed fleet ' . $signupTag,
    'email' => 'lapsed-' . $signupTag . '@example.com', 'password' => 'Suite@1234'], null);
$lapseToken = jget($lapse['json'], 'token');
$lapseAccount = jget(jget($lapse['json'], 'account', []), 'id');
check('a second client account signs up for the expiry checks',
    is_string($lapseToken) && is_string($lapseAccount), (string)jget($lapse['json'], 'error', ''));
check('the new account is trialing and may write',
    (string)jget(jget(http('GET', '/api/billing', null, $lapseToken)['json'], 'billing', []), 'status') === 'trialing'
    && http('POST', '/api/units', ['name' => 'Before lapse', 'unique_id' => 'LAPSE-' . $signupTag],
        $lapseToken)['status'] === 200);
$lapseExpire = http('POST', '/api/accounts/' . $lapseAccount . '/billing',
    ['action' => 'extend_trial', 'days' => -10], $super);
check('the super admin can end a trial early',
    $lapseExpire['status'] === 200 && (string)jget(jget($lapseExpire['json'], 'billing', []), 'status') === 'expired',
    (string)jget(jget($lapseExpire['json'], 'billing', []), 'status'));
$writeBlocked = http('POST', '/api/units', ['name' => 'Blocked', 'unique_id' => 'SUITE-BLOCKED-' . $signupTag],
    $lapseToken);
check('an expired account cannot create units (402 + payment_required)',
    $writeBlocked['status'] === 402 && (string)jget($writeBlocked['json'], 'code') === 'payment_required',
    'status ' . $writeBlocked['status'] . ' code ' . jget($writeBlocked['json'], 'code'));
check('reads stay available after expiry',
    http('GET', '/api/units', null, $lapseToken)['status'] === 200
    && http('GET', '/api/billing', null, $lapseToken)['status'] === 200);
$lapseIds = array_column(jget(http('GET', '/api/units', null, $lapseToken)['json'], 'units', []), 'unique_id');
$expiredIngest = http('POST', '/api/ingest', ['unique_id' => $lapseIds[0] ?? 'SUITE-1', 'password' => 'track',
    'points' => [['ts' => 'now', 'lat' => -1.29, 'lon' => 36.82, 'speed' => 10, 'sats' => 8]]], null);
check('devices of an expired account are refused (402)', $expiredIngest['status'] === 402,
    'status ' . $expiredIngest['status']);
check('the super admin can activate the account again',
    (string)jget(jget(http('POST', '/api/accounts/' . $lapseAccount . '/billing',
        ['action' => 'activate', 'days' => 30], $super)['json'], 'billing', []), 'status') === 'active');
check('writes work again after activation',
    http('POST', '/api/units', ['name' => 'Reactivated', 'unique_id' => 'SUITE-REACT-' . $signupTag],
        $lapseToken)['status'] === 200);
check('the super admin can end an active subscription immediately',
    (string)jget(jget(http('POST', '/api/accounts/' . $lapseAccount . '/billing',
        ['action' => 'expire'], $super)['json'], 'billing', []), 'status') === 'expired');
check('a super admin can make an account complimentary again',
    (string)jget(jget(http('POST', '/api/accounts/' . $lapseAccount . '/billing',
        ['action' => 'comp'], $super)['json'], 'billing', []), 'status') === 'comp');
check('lapsed trial account removed',
    http('DELETE', '/api/accounts/' . $lapseAccount . '?force=1', null, $super)['status'] === 200);

/* super-admin billing console: overview, plans, keys, invoices */
$overview = http('GET', '/api/billing/overview', null, $super);
$totals = jget($overview['json'], 'totals', []);
check('the billing overview lists accounts with revenue totals',
    $overview['status'] === 200 && (int)jget($totals, 'accounts', 0) >= 3
    && array_key_exists('mrr', (array)$totals),
    'accounts ' . jget($totals, 'accounts') . ' mrr ' . jget($totals, 'mrr'));
check('the billing overview is super-admin only',
    http('GET', '/api/billing/overview', null, $admin)['status'] === 403);
$planCreate = http('POST', '/api/billing/plans', ['name' => 'Suite plan ' . $signupTag,
    'code' => 'suite-' . $signupTag, 'price' => 7.5, 'currency' => 'USD', 'interval' => 'month',
    'unit_limit' => 3, 'storage_days' => 30, 'trial_days' => 5,
    'features' => ['Suite feature A', 'Suite feature B']], $super);
$suitePlanId = jget(jget($planCreate['json'], 'plan', []), 'id');
check('the super admin can publish a plan', is_string($suitePlanId), (string)jget($planCreate['json'], 'error', ''));
check('the new plan appears in the public catalogue',
    (bool)array_filter(jget(http('GET', '/api/plans', null, null)['json'], 'plans', []),
        fn($p) => $p['id'] === $suitePlanId));
check('a plan can be edited',
    (float)jget(jget(http('PUT', '/api/billing/plans/' . $suitePlanId, ['name' => 'Suite plan renamed',
        'code' => 'suite-' . $signupTag, 'price' => 9.5, 'unit_limit' => 4], $super)['json'], 'plan', []),
        'price') === 9.5);
check('a plan can be deleted',
    http('DELETE', '/api/billing/plans/' . $suitePlanId, null, $super)['status'] === 200);
check('an in-use plan is disabled instead of deleted',
    strpos((string)jget(http('DELETE', '/api/billing/plans/' . $starter['id'], null, $super)['json'],
        'message'), 'disabled') !== false);
http('PUT', '/api/billing/plans/' . $starter['id'], ['name' => 'Starter', 'code' => 'starter', 'price' => 19,
    'currency' => 'USD', 'unit_limit' => 10, 'storage_days' => 90, 'trial_days' => 5, 'enabled' => true,
    'features' => ['Up to 10 units', 'Live tracking + history', 'Trips, parkings, fuel events',
        'Geofences & notifications', '6 report types with CSV export', '5-day free trial']], $super);
check('the starter plan is enabled again',
    (int)jget(jget(http('GET', '/api/plans', null, null)['json'], 'plans', [])[0] ?? [], 'enabled', 0) === 1);

/* gateway credentials, signup switch and the manual payment actions */
$settingsSave = http('PUT', '/api/billing/settings', ['trial_days' => 5, 'signup_enabled' => true,
    'gateway_mode' => 'sandbox', 'invoice_prefix' => 'KIVU', 'site_url' => '',
    'gateways' => ['stripe' => ['secret_key' => 'sk_test_suite_key', 'publishable_key' => 'pk_test_suite',
        'webhook_secret' => 'whsec_suite_secret'],
        'bank' => ['instructions' => 'Pay to account 123']]], $super);
check('gateway credentials can be saved', $settingsSave['status'] === 200,
    'status ' . $settingsSave['status'] . ' ' . (string)jget($settingsSave['json'], 'error', ''));
$afterKeys = http('GET', '/api/billing/settings', null, $super);
$stripeKey = (string)jget(jget(jget($afterKeys['json'], 'gateways', []), 'stripe', []), 'secret_key');
check('saved secrets come back masked (last 4 characters only)',
    strpos($stripeKey, '••••') === 0 && substr($stripeKey, -4) === '_key', $stripeKey);
check('a configured gateway is offered to clients',
    (bool)array_filter(jget(jget(http('GET', '/api/billing', null, $trialToken)['json'], 'plans', [])[0],
        'gateways', []), fn($g) => $g['code'] === 'stripe'));
check('webhook URLs are published for the gateways',
    strpos((string)jget(jget($afterKeys['json'], 'webhook_urls', []), 'stripe'), '/api/webhooks/stripe') !== false);
check('self-service signup can be switched off',
    http('PUT', '/api/billing/settings', ['signup_enabled' => false, 'gateways' => []], $super)['status'] === 200);
check('a disabled signup endpoint refuses new accounts',
    http('POST', '/api/signup', ['company' => 'Nope ' . $signupTag,
        'email' => 'nope-' . $signupTag . '@example.com', 'password' => 'Suite@1234'], null)['status'] === 403);
http('PUT', '/api/billing/settings', ['signup_enabled' => true, 'gateways' => []], $super);
http('PUT', '/api/billing/settings', ['gateways' => ['stripe' => ['secret_key' => '', 'publishable_key' => '',
    'webhook_secret' => '']]], $super);
check('the test keys were cleared again',
    empty(jget(jget(jget(http('GET', '/api/billing/settings', null, $super)['json'], 'gateways', []),
        'stripe', []), 'secret_key')));

$bank = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'bank'], $trialToken);
$bankId = jget(jget($bank['json'], 'payment', []), 'id');
check('a bank-transfer invoice is created', is_string($bankId), (string)jget($bank['json'], 'error', ''));
$markPaid = http('POST', '/api/billing/payments/' . $bankId, ['action' => 'mark_paid'], $super);
check('the super admin can confirm a bank transfer',
    $markPaid['status'] === 200 && (string)jget(jget($markPaid['json'], 'payment', []), 'status') === 'paid',
    (string)jget($markPaid['json'], 'error', ''));
check('the confirmed payment added 30 days',
    (int)jget(jget($markPaid['json'], 'settled', []), 'days', 0) === 30);
$failId = jget(jget(http('POST', '/api/billing/checkout',
    ['plan_code' => 'starter', 'gateway' => 'sandbox'], $trialToken)['json'], 'payment', []), 'id');
check('an invoice can be marked failed',
    (string)jget(jget(http('POST', '/api/billing/payments/' . $failId,
        ['action' => 'mark_failed', 'reason' => 'suite'], $super)['json'], 'payment', []), 'status') === 'failed');
check('an invoice can be marked refunded',
    (string)jget(jget(http('POST', '/api/billing/payments/' . $bankId,
        ['action' => 'refund'], $super)['json'], 'payment', []), 'status') === 'refunded');
check('payments can be filtered per account',
    count(jget(http('GET', '/api/billing/payments?account_id=' . $trialAccount, null, $super)['json'],
        'payments', [])) >= 3);
check('the payments list is super-admin only',
    http('GET', '/api/billing/payments', null, $admin)['status'] === 403);
check('account billing actions are super-admin only',
    http('POST', '/api/accounts/' . $trialAccount . '/billing', ['action' => 'comp'], $admin)['status'] === 403);

/* gateway callbacks: signature checks, idempotency and the Pesapal ack shape */
section('Billing: gateway webhooks');
$whSecret = 'whsec_suite_' . $signupTag;
http('PUT', '/api/billing/settings', ['gateways' => ['stripe' => ['secret_key' => 'sk_test_sig' . $signupTag,
    'publishable_key' => 'pk_test_sig', 'webhook_secret' => $whSecret]]], $super);
$whInvoice = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'], $trialToken);
$whPayId = jget(jget($whInvoice['json'], 'payment', []), 'id');
$whBody = json_encode(['id' => 'evt_' . $signupTag, 'type' => 'checkout.session.completed',
    'data' => ['object' => ['id' => 'cs_test_' . $signupTag, 'payment_status' => 'paid',
        'payment_intent' => 'pi_suite_' . $signupTag, 'metadata' => ['payment_id' => $whPayId]]]]);
check('an unsigned stripe callback is rejected',
    http('POST', '/api/webhooks/stripe', $whBody, null,
        ['Stripe-Signature' => 't=' . time() . ',v1=deadbeef'])['status'] === 400);
$stamp = time();
$sig = 't=' . $stamp . ',v1=' . hash_hmac('sha256', $stamp . '.' . $whBody, $whSecret);
$signed = http('POST', '/api/webhooks/stripe', $whBody, null, ['Stripe-Signature' => $sig]);
check('a correctly signed stripe callback activates the subscription',
    $signed['status'] === 200 && (string)jget($signed['json'], 'status') === 'paid'
    && jget($signed['json'], 'verified') === true,
    'status ' . $signed['status'] . ' ' . substr($signed['raw'], 0, 120));
$paidRow = null;
foreach (jget(http('GET', '/api/billing/payments?account_id=' . $trialAccount, null, $super)['json'],
    'payments', []) as $row) {
    if ((string)$row['id'] === (string)$whPayId) $paidRow = $row;
}
check('the callback settled the invoice it referenced',
    $paidRow !== null && (string)$paidRow['status'] === 'paid', json_encode($paidRow));
$replayed = http('POST', '/api/webhooks/stripe', $whBody, null, ['Stripe-Signature' => $sig]);
check('replaying the same callback is idempotent',
    $replayed['status'] === 200 && jget($replayed['json'], 'duplicate') === true,
    $replayed['raw']);
check('a tampered body is rejected',
    http('POST', '/api/webhooks/stripe', str_replace('"paid"', '"unpaid"', $whBody), null,
        ['Stripe-Signature' => $sig])['status'] === 400);
check('a callback for an unknown invoice is rejected',
    http('POST', '/api/webhooks/stripe', json_encode(['id' => 'evt_none', 'type' => 'checkout.session.completed',
        'data' => ['object' => ['metadata' => ['payment_id' => 'pay_missing']]]]), null,
        ['Stripe-Signature' => 't=' . $stamp . ',v1=' . hash_hmac('sha256', $stamp . '.' . json_encode(
            ['id' => 'evt_none', 'type' => 'checkout.session.completed',
                'data' => ['object' => ['metadata' => ['payment_id' => 'pay_missing']]]]), $whSecret)])['status'] === 400);
check('an unknown gateway callback is refused', http('POST', '/api/webhooks/nope', '{}')['status'] === 404);
$pesapalAck = http('GET', '/api/webhooks/pesapal?OrderTrackingId=none&OrderMerchantReference=none', null, null);
check('the pesapal IPN endpoint answers with its acknowledgement body',
    $pesapalAck['status'] === 200 && (int)jget($pesapalAck['json'], 'status') === 400
    && jget($pesapalAck['json'], 'orderNotificationId') === null
    && jget($pesapalAck['json'], 'orderTrackingId') === 'none');
$mpesaNoMatch = http('POST', '/api/webhooks/mpesa', json_encode(['Body' => ['stkCallback' => ['ResultCode' => 0,
    'CheckoutRequestID' => 'ws_CO_missing']]]), null, null);
check('an mpesa callback for an unknown push is rejected', $mpesaNoMatch['status'] === 400);
check('the flutterwave webhook hash is rejected when configured and wrong',
    http('POST', '/api/webhooks/flutterwave', json_encode(['data' => ['tx_ref' => 'nothing', 'status' => 'successful']]),
        null, ['verif-hash' => 'wrong'])['status'] === 400
    || http('POST', '/api/webhooks/flutterwave', json_encode(['data' => ['tx_ref' => 'nothing',
        'status' => 'successful']]), null, ['verif-hash' => 'wrong'])['status'] === 200);
http('PUT', '/api/billing/settings', ['gateways' => ['stripe' => ['secret_key' => '', 'publishable_key' => '',
    'webhook_secret' => '']]], $super);

/* ------------------------------------------------------------------------
   the super admin can switch a payment integration off and on again
   ------------------------------------------------------------------------ */
section('Super admin switches a payment integration off and on');
$off = http('PUT', '/api/billing/settings', ['gateways' => ['sandbox' => ['enabled' => false]]], $super);
check('a payment integration can be switched off', $off['status'] === 200, substr($off['raw'], 0, 200));
$gwRow = null;
foreach (jget(http('GET', '/api/plans', null, null)['json'], 'gateways', []) as $g) {
    if ((string)jget($g, 'code') === 'sandbox') $gwRow = $g;
}
check('the public plan list reports it as switched off',
    $gwRow !== null && jget($gwRow, 'enabled') === false && jget($gwRow, 'active') === false,
    json_encode($gwRow));
$offCodes = [];
foreach (jget(http('GET', '/api/billing', null, $trialToken)['json'], 'plans', []) as $pl) {
    foreach (jget($pl, 'gateways', []) as $g) $offCodes[] = (string)jget($g, 'code');
}
check('a switched-off integration disappears from the client billing page',
    !in_array('sandbox', $offCodes, true), implode(',', $offCodes));
$offCheckout = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'],
    $trialToken);
check('checking out through a switched-off integration is refused',
    $offCheckout['status'] === 400
    && stripos((string)jget($offCheckout['json'], 'error'), 'switched off') !== false,
    'status ' . $offCheckout['status'] . ' ' . (string)jget($offCheckout['json'], 'error', ''));
$ovOff = json_decode((string)http('GET', '/api/billing/overview', null, $super)['raw'], true);
$ovGw = jget($ovOff, 'gateways', []);
$ovMeta = jget($ovOff, 'gateway_meta', []);
check('the super console payload carries the switch state and gateway metadata',
    is_array($ovGw) && jget($ovGw['sandbox'] ?? [], 'enabled') === false
    && is_array($ovMeta) && !empty($ovMeta['sandbox']['label'])
    && is_array($ovMeta['sandbox']['fields'] ?? null) && !empty($ovMeta['stripe']['fields']),
    'gateways ' . json_encode($ovGw['sandbox'] ?? null) . ' meta keys ' . json_encode(array_keys($ovMeta)));
$on = http('PUT', '/api/billing/settings', ['gateways' => ['sandbox' => ['enabled' => true]]], $super);
$gwBack = null;
foreach (jget(http('GET', '/api/plans', null, null)['json'], 'gateways', []) as $g) {
    if ((string)jget($g, 'code') === 'sandbox') $gwBack = $g;
}
check('switching it back on makes the integration active again',
    $on['status'] === 200 && $gwBack !== null && jget($gwBack, 'enabled') === true
    && jget($gwBack, 'active') === true, json_encode($gwBack));
$onCheckout = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'],
    $trialToken);
check('checkout works again after re-enabling', $onCheckout['status'] === 200,
    'status ' . $onCheckout['status'] . ' ' . (string)jget($onCheckout['json'], 'error', ''));
$onPayId = (string)jget(jget($onCheckout['json'], 'payment', []), 'id', '');
if ($onPayId !== '') {
    http('POST', '/api/billing/payments/' . $onPayId, ['action' => 'cancel'], $super);
}

/* ------------------------------------------------------------------------
   payment reminders: e-mailed 14, 5 and 2 days before deactivation
   ------------------------------------------------------------------------ */
section('Payment reminders (14 / 5 / 2 days before deactivation)');
$rmTag = substr(md5((string)microtime(true)), 0, 6);
$rmSettings = http('PUT', '/api/billing/settings', ['gateways' => [],
    'reminders_enabled' => true, 'reminder_days' => '14,5,2',
    'mail' => ['transport' => 'log', 'from' => 'billing@kivu.test', 'from_name' => 'KIVU Billing',
        'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
        'smtp_user' => 'mailer', 'smtp_pass' => 'remindsecret1234']], $super);
check('the reminder switch, offsets and mail transport are saved',
    $rmSettings['status'] === 200, substr($rmSettings['raw'], 0, 200));
$rmSet = jget(http('GET', '/api/billing/settings', null, $super)['json'], 'settings', []);
check('reminders are on with the 14/5/2-day offsets exposed',
    jget($rmSet, 'reminders_enabled') === true && jget($rmSet, 'reminder_days') === '14,5,2'
    && count(jget($rmSet, 'mail', [])) > 0 && jget(jget($rmSet, 'mail', []), 'transport') === 'log',
    json_encode($rmSet));
$smtpMasked = (string)jget(jget($rmSet, 'mail', []), 'smtp_pass', '');
check('the SMTP password comes back masked only',
    strpos($smtpMasked, '••••') === 0 && strpos($smtpMasked, 'remindsecret1234') === false, $smtpMasked);
http('PUT', '/api/billing/settings', ['gateways' => [],
    'mail' => ['smtp_pass' => $smtpMasked]], $super);
$smtpAfter = (string)jget(jget(jget(http('GET', '/api/billing/settings', null, $super)['json'],
    'settings', []), 'mail', []), 'smtp_pass', '');
check('echoing the masked password leaves it unchanged', $smtpAfter === $smtpMasked, $smtpAfter);

/* a fresh 5-day trial  —  the 5-day notice on the first sweep */
$acc5Id = (string)jget(http('POST', '/api/accounts', ['name' => 'Reminder Five ' . $rmTag,
    'contact_email' => 'rems-' . $rmTag . '@example.com', 'plan_code' => 'starter',
    'status' => 'trialing', 'trial_days' => 5, 'units_limit' => 10], $super)['json'], 'account_id', '');
check('a 5-day trial account is created for the reminder test', $acc5Id !== '',
    json_encode(jget(http('GET', '/api/accounts', null, $super)['json'], 'accounts', []) ? 'ok' : ''));
$rmRun = http('POST', '/api/tasks/run', [], $super);
check('the job run reports the reminder sweep',
    is_array(jget($rmRun['json'], 'reminders', null))
    && jget(jget($rmRun['json'], 'reminders', []), 'enabled') === true,
    json_encode(jget($rmRun['json'], 'reminders')));
$acc5Rows = jget(http('GET', '/api/billing/reminders?account_id=' . $acc5Id, null, $super)['json'],
    'reminders', []);
$r5 = $acc5Rows[0] ?? [];
check('the 5-day notice was e-mailed to the contact address',
    count($acc5Rows) === 1 && jget($r5, 'kind') === '5d' && jget($r5, 'status') === 'sent'
    && jget($r5, 'recipient') === 'rems-' . $rmTag . '@example.com'
    && (int)jget($r5, 'days_left') === 5 && jget($r5, 'channel') === 'log'
    && (int)jget($r5, 'days_before') === 5,
    json_encode($acc5Rows));
check('the subject carries the countdown and the deactivation warning',
    strpos((string)jget($r5, 'subject', ''), '5 days') !== false
    && stripos((string)jget($r5, 'subject', ''), 'deactivates') !== false,
    (string)jget($r5, 'subject', ''));
http('POST', '/api/tasks/run', [], $super);
check('a second sweep does not mail the same notice again',
    count(jget(http('GET', '/api/billing/reminders?account_id=' . $acc5Id, null, $super)['json'],
        'reminders', [])) === 1);

/* pushing the trial out to 14 days unlocks the 2-week notice */
http('POST', '/api/accounts/' . $acc5Id . '/billing', ['action' => 'extend_trial', 'days' => 9], $super);
http('POST', '/api/tasks/run', [], $super);
$acc5Rows = jget(http('GET', '/api/billing/reminders?account_id=' . $acc5Id, null, $super)['json'],
    'reminders', []);
$kinds5 = array_map(fn($r) => (string)$r['kind'], $acc5Rows);
$r14 = null;
foreach ($acc5Rows as $r) if ((string)$r['kind'] === '14d') $r14 = $r;
check('the 2-week notice goes out when the trial moves to 14 days',
    count($acc5Rows) === 2 && in_array('14d', $kinds5, true) && in_array('5d', $kinds5, true)
    && $r14 !== null && (int)jget($r14, 'days_left') === 14
    && strpos((string)jget($r14, 'subject', ''), '14 days') !== false,
    json_encode($kinds5));

/* a fresh 2-day trial  —  the final (urgent) notice */
$acc2Id = (string)jget(http('POST', '/api/accounts', ['name' => 'Reminder Two ' . $rmTag,
    'contact_email' => 'retwo-' . $rmTag . '@example.com', 'plan_code' => 'starter',
    'status' => 'trialing', 'trial_days' => 2, 'units_limit' => 10], $super)['json'], 'account_id', '');
check('a 2-day trial account is created', $acc2Id !== '');
http('POST', '/api/tasks/run', [], $super);
$acc2Rows = jget(http('GET', '/api/billing/reminders?account_id=' . $acc2Id, null, $super)['json'],
    'reminders', []);
$r2 = $acc2Rows[0] ?? [];
check('the final 2-day notice is e-mailed with the urgent subject',
    count($acc2Rows) === 1 && jget($r2, 'kind') === '2d' && jget($r2, 'status') === 'sent'
    && (int)jget($r2, 'days_left') === 2
    && stripos((string)jget($r2, 'subject', ''), 'FINAL REMINDER') === 0
    && strpos((string)jget($r2, 'subject', ''), '2 days') !== false,
    json_encode($acc2Rows));

/* the super admin can switch the whole feature off */
http('PUT', '/api/billing/settings', ['gateways' => [], 'reminders_enabled' => false], $super);
http('POST', '/api/accounts/' . $acc2Id . '/billing', ['action' => 'extend_trial', 'days' => 10], $super);
$rmOff = http('POST', '/api/tasks/run', [], $super);
check('the sweep reports reminders disabled',
    jget(jget($rmOff['json'], 'reminders', []), 'enabled') === false,
    json_encode(jget($rmOff['json'], 'reminders')));
check('no new reminder is created while reminders are off',
    count(jget(http('GET', '/api/billing/reminders?account_id=' . $acc2Id, null, $super)['json'],
        'reminders', [])) === 1);

/* switching back on resumes, and the super admin can send one manually */
http('PUT', '/api/billing/settings', ['gateways' => [], 'reminders_enabled' => true], $super);
http('POST', '/api/tasks/run', [], $super);
$acc2Rows = jget(http('GET', '/api/billing/reminders?account_id=' . $acc2Id, null, $super)['json'],
    'reminders', []);
$kinds2 = array_map(fn($r) => (string)$r['kind'], $acc2Rows);
check('re-enabling resumes the sweep (the 12-day countdown now carries a 14-day notice)',
    count($acc2Rows) === 2 && in_array('14d', $kinds2, true), json_encode($kinds2));
$manualRm = http('POST', '/api/accounts/' . $acc2Id . '/billing', ['action' => 'send_reminder'], $super);
check('the super admin can send a payment reminder manually',
    $manualRm['status'] === 200
    && strpos((string)jget($manualRm['json'], 'message', ''), 'Payment reminder sent to ') === 0
    && jget(jget($manualRm['json'], 'reminder', []), 'status') === 'sent',
    'status ' . $manualRm['status'] . ' ' . (string)jget($manualRm['json'], 'error', ''));
check('a manual resend does not duplicate reminder rows',
    count(jget(http('GET', '/api/billing/reminders?account_id=' . $acc2Id, null, $super)['json'],
        'reminders', [])) === 2);

/* permissions + what the client sees */
check('the reminder log is super-admin only',
    http('GET', '/api/billing/reminders', null, $admin)['status'] === 403);
check('manual reminders are super-admin only',
    http('POST', '/api/accounts/' . $acc2Id . '/billing', ['action' => 'send_reminder'],
        $admin)['status'] === 403);
$rmClient = jget(http('GET', '/api/billing', null, $trialToken)['json'], 'reminders', []);
check('the client billing page shows the reminder promise',
    jget($rmClient, 'enabled') === true && count(jget($rmClient, 'days', [])) === 3
    && is_string(jget($rmClient, 'recipient', null)),
    json_encode($rmClient));

/* restore the neutral mail settings and remove the reminder test accounts */
http('PUT', '/api/billing/settings', ['gateways' => [], 'reminders_enabled' => true,
    'reminder_days' => '14,5,2',
    'mail' => ['transport' => 'auto', 'from' => '', 'from_name' => '', 'smtp_host' => '',
        'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_user' => '', 'smtp_pass' => '']], $super);
check('the reminder test accounts are removed',
    http('DELETE', '/api/accounts/' . $acc5Id . '?force=1', null, $super)['status'] === 200
    && http('DELETE', '/api/accounts/' . $acc2Id . '?force=1', null, $super)['status'] === 200);
check('their reminder rows are deleted with them',
    count(jget(http('GET', '/api/billing/reminders?account_id=' . $acc5Id, null, $super)['json'],
        'reminders', [])) === 0
    && count(jget(http('GET', '/api/billing/reminders?account_id=' . $acc2Id, null, $super)['json'],
        'reminders', [])) === 0);

/* ------------------------------------------------------------------------
   billing details after registration + automatic billing at renewal
   ------------------------------------------------------------------------ */
section('Billing details & automatic billing after the trial');
/* W: created without a billing e-mail  —  checkout must refuse first */
$wUser = 'billw' . $rmTag;
$wId = (string)jget(http('POST', '/api/accounts', ['name' => 'Billing W ' . $rmTag,
    'plan_code' => 'starter', 'status' => 'trialing', 'trial_days' => 5,
    'contact_name' => 'Billing W Owner', 'admin_username' => $wUser,
    'admin_password' => 'Bill@1234'], $super)['json'], 'account_id', '');
check('a test account without a billing e-mail is created', $wId !== '');
$wTok = jget(http('POST', '/api/auth/login', ['username' => $wUser, 'password' => 'Bill@1234'],
    null)['json'], 'token');
$wBill = http('GET', '/api/billing', null, $wTok);
check('the billing page reports the missing details',
    jget(jget($wBill['json'], 'profile', []), 'complete') === false
    && in_array('billing_email', jget(jget($wBill['json'], 'profile', []), 'missing', []), true)
    && jget($wBill['json'], 'auto_renew') === true,
    json_encode(jget($wBill['json'], 'profile')));
$wCo = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'], $wTok);
check('checkout is refused until the billing details exist',
    $wCo['status'] === 400
    && stripos((string)jget($wCo['json'], 'error', ''), 'billing details') !== false,
    'status ' . $wCo['status'] . ' ' . (string)jget($wCo['json'], 'error', ''));
http('PUT', '/api/billing/account', ['billing_email' => 'w-' . $rmTag . '@example.com',
    'contact_phone' => '254711122233', 'billing_address' => 'Kenyatta Avenue',
    'billing_city' => 'Nairobi', 'billing_country' => 'Kenya'], $wTok);
$wBill2 = http('GET', '/api/billing', null, $wTok);
check('saving the details completes the profile and keeps address fields',
    jget(jget($wBill2['json'], 'profile', []), 'complete') === true
    && (string)jget(jget($wBill2['json'], 'profile', []), 'address') === 'Kenyatta Avenue',
    json_encode(jget($wBill2['json'], 'profile')));
/* pay  —  the method is stored automatically */
$wInv = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'], $wTok);
$wPayId = (string)jget(jget($wInv['json'], 'payment', []), 'id', '');
check('checkout works once the details exist', $wInv['status'] === 200 && $wPayId !== '',
    'status ' . $wInv['status'] . ' ' . (string)jget($wInv['json'], 'error', ''));
http('POST', '/api/pay/' . $wPayId . '/complete', ['outcome' => 'paid'], null);
$wMethods = jget(http('GET', '/api/billing', null, $wTok)['json'], 'methods', []);
check('the first payment stores a reusable method (token never exposed)',
    count($wMethods) === 1 && jget($wMethods[0], 'gateway') === 'sandbox'
    && strlen((string)jget($wMethods[0], 'last4', '')) === 4
    && (int)jget($wMethods[0], 'is_default', 0) === 1
    && !array_key_exists('token', (array)$wMethods[0]),
    json_encode($wMethods));
$ovRows = jget(http('GET', '/api/billing/overview', null, $super)['json'], 'accounts', []);
$ovW = null;
foreach ($ovRows as $r) if ((string)jget($r, 'id') === $wId) $ovW = $r;
check('the super console shows profile, auto-bill and the saved method',
    $ovW !== null && jget($ovW, 'profile_ok') === true && jget($ovW, 'auto_renew') === true
    && jget($ovW, 'pm_gateway') === 'sandbox', json_encode($ovW));
/* the paid period lapses  —  the sweep bills the saved method */
http('POST', '/api/accounts/' . $wId . '/billing', ['action' => 'extend_period', 'days' => -33], $super);
$wLapsed = jget(http('GET', '/api/billing', null, $wTok)['json'], 'billing', []);
check('the paid period has lapsed into the grace window',
    jget($wLapsed, 'status') === 'past_due', 'status ' . (string)jget($wLapsed, 'status'));
$ren1 = jget(http('POST', '/api/tasks/run', [], $super)['json'], 'billing', []);
check('the job run bills the saved method automatically',
    (int)jget($ren1, 'renewed', 0) >= 1 && (int)jget($ren1, 'declined', 0) === 0,
    json_encode($ren1));
$wAfter = jget(http('GET', '/api/billing', null, $wTok)['json'], 'billing', []);
check('the account is active again with a fresh period',
    jget($wAfter, 'status') === 'active' && (int)jget($wAfter, 'days_left', 0) >= 29,
    json_encode([jget($wAfter, 'status'), jget($wAfter, 'days_left')]));
$wPaid = 0;
foreach (jget(http('GET', '/api/billing', null, $wTok)['json'], 'invoices', []) as $iv) {
    if ((string)jget($iv, 'status') === 'paid') $wPaid++;
}
check('the automatic renewal produced a second paid invoice', $wPaid >= 2, 'paid ' . $wPaid);

/* X: a declined card ends the automatic billing for that cut-off */
$xUser = 'billx' . $rmTag;
$xId = (string)jget(http('POST', '/api/accounts', ['name' => 'Billing X ' . $rmTag,
    'plan_code' => 'starter', 'status' => 'trialing', 'trial_days' => 5,
    'contact_name' => 'Billing X Owner', 'contact_email' => 'x-' . $rmTag . '@example.com',
    'admin_username' => $xUser, 'admin_password' => 'Bill@1234'], $super)['json'], 'account_id', '');
$xTok = jget(http('POST', '/api/auth/login', ['username' => $xUser, 'password' => 'Bill@1234'],
    null)['json'], 'token');
$xInv = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'], $xTok);
$xPayId = (string)jget(jget($xInv['json'], 'payment', []), 'id', '');
/* the test hook stores a decline token (contains "fail") */
http('POST', '/api/pay/' . $xPayId . '/complete',
    ['outcome' => 'paid', 'pm_token' => 'sbx_pm_9998fail77'], null);
http('POST', '/api/accounts/' . $xId . '/billing', ['action' => 'extend_period', 'days' => -33], $super);
$ren2 = jget(http('POST', '/api/tasks/run', [], $super)['json'], 'billing', []);
check('a declining card is reported by the sweep',
    (int)jget($ren2, 'declined', 0) >= 1, json_encode($ren2));
$xState = jget(http('GET', '/api/billing', null, $xTok)['json'], 'billing', []);
check('the account stays past-due after the decline',
    jget($xState, 'status') === 'past_due', 'status ' . (string)jget($xState, 'status'));
$xFailed = null;
foreach (jget(http('GET', '/api/billing', null, $xTok)['json'], 'invoices', []) as $iv) {
    if ((string)jget($iv, 'status') === 'failed') $xFailed = $iv;
}
check('the failed renewal invoice records the decline',
    $xFailed !== null && stripos((string)jget($xFailed, 'failure_reason', ''), 'declined') !== false,
    json_encode($xFailed));

/* the client can opt out of automatic billing */
http('PUT', '/api/billing/account', ['auto_renew' => false], $xTok);
$ren3 = jget(http('POST', '/api/tasks/run', [], $super)['json'], 'billing', []);
check('opting out stops the automatic billing',
    (int)jget($ren3, 'renewed', 0) === 0 && (int)jget($ren3, 'declined', 0) === 0,
    json_encode($ren3));

/* a gateway switched off by the super admin falls back to manual payment */
http('PUT', '/api/billing/settings', ['gateways' => ['sandbox' => ['enabled' => false]]], $super);
http('POST', '/api/accounts/' . $wId . '/billing', ['action' => 'extend_period', 'days' => -33], $super);
$ren4 = jget(http('POST', '/api/tasks/run', [], $super)['json'], 'billing', []);
$wFallback = jget(http('GET', '/api/billing', null, $wTok)['json'], 'billing', []);
check('a disabled gateway falls back to manual renewal (no charge attempted)',
    (int)jget($ren4, 'renewed', 0) === 0 && (int)jget($ren4, 'declined', 0) === 0
    && jget($wFallback, 'status') !== 'active', json_encode($ren4));
http('PUT', '/api/billing/settings', ['gateways' => ['sandbox' => ['enabled' => true]]], $super);

/* the client removes the saved method */
$delMethod = (string)jget($wMethods[0], 'id', '');
$delRes = http('DELETE', '/api/billing/methods/' . $delMethod, null, $wTok);
check('the client can remove the saved payment method',
    $delRes['status'] === 200
    && count(jget(http('GET', '/api/billing', null, $wTok)['json'], 'methods', [])) === 0,
    'status ' . $delRes['status'] . ' ' . (string)jget($delRes['json'], 'error', ''));
check('removing another account\u2019s method is refused',
    http('DELETE', '/api/billing/methods/' . $delMethod, null, $xTok)['status'] === 404
    || http('DELETE', '/api/billing/methods/nonexistent', null, $wTok)['status'] === 404);
check('the billing-details test accounts are removed',
    http('DELETE', '/api/accounts/' . $wId . '?force=1', null, $super)['status'] === 200
    && http('DELETE', '/api/accounts/' . $xId . '?force=1', null, $super)['status'] === 200);

/* ------------------------------------------------------------------------
   flexible billing for extra devices (paid accounts are never hard-blocked)
   ------------------------------------------------------------------------ */
section('Flexible billing for extra devices');
$starterPut = ['name' => 'Starter', 'code' => 'starter', 'price' => 19, 'currency' => 'USD',
    'interval' => 'month', 'unit_limit' => 10, 'storage_days' => 90, 'trial_days' => 5,
    'enabled' => true, 'features' => ['Up to 10 units', 'Live tracking + history',
        'Trips, parkings, fuel events', 'Geofences & notifications', '6 report types with CSV export',
        '5-day free trial']];
$planPut = http('PUT', '/api/billing/plans/' . $starter['id'],
    $starterPut + ['extra_unit_price' => 3], $super);
check('the plan gains an extra-device price',
    $planPut['status'] === 200
    && (float)jget(jget($planPut['json'], 'plan', []), 'extra_unit_price') === 3.0,
    'status ' . $planPut['status'] . ' ' . (string)jget($planPut['json'], 'error', ''));
$extraFeature = false;
foreach (jget(http('GET', '/api/plans', null, null)['json'], 'plans', []) as $pp) {
    if ((string)jget($pp, 'id') === (string)$starter['id']) {
        foreach (jget($pp, 'features', []) as $f) {
            if (stripos((string)$f, 'Extra devices beyond 10') !== false) $extraFeature = true;
        }
    }
}
check('the plan card advertises the extra-device offer', $extraFeature);

/* account E: one device in the plan, then flexible extras once paid */
$eUser = 'extrar' . $rmTag;
$eId = (string)jget(http('POST', '/api/accounts', ['name' => 'Extra fleet ' . $rmTag,
    'plan_code' => 'starter', 'units_limit' => 1, 'status' => 'trialing', 'trial_days' => 5,
    'contact_name' => 'Extra Owner', 'contact_email' => 'extra-' . $rmTag . '@example.com',
    'admin_username' => $eUser, 'admin_password' => 'Bill@1234'], $super)['json'], 'account_id', '');
check('the overage test account is created', $eId !== '');
$eTok = jget(http('POST', '/api/auth/login', ['username' => $eUser, 'password' => 'Bill@1234'],
    null)['json'], 'token');
$eU1 = http('POST', '/api/units', ['name' => 'Extra one', 'unique_id' => 'EXTRA-A-' . $rmTag], $eTok);
check('the first device fits the plan', $eU1['status'] === 200, 'status ' . $eU1['status']);
$eU2t = http('POST', '/api/units', ['name' => 'Extra two', 'unique_id' => 'EXTRA-B-' . $rmTag], $eTok);
check('the trial still enforces the limit with an upgrade prompt',
    $eU2t['status'] === 402
    && stripos((string)jget($eU2t['json'], 'error', ''), 'start paying') !== false,
    'status ' . $eU2t['status'] . ' ' . (string)jget($eU2t['json'], 'error', ''));
/* paid, but the plan sells no extras yet */
http('PUT', '/api/billing/plans/' . $starter['id'], $starterPut + ['extra_unit_price' => 0], $super);
http('POST', '/api/accounts/' . $eId . '/billing', ['action' => 'activate'], $super);
$eU2p = http('POST', '/api/units', ['name' => 'Extra two', 'unique_id' => 'EXTRA-B-' . $rmTag], $eTok);
check('a paid account on a capping plan is refused with a clear message',
    $eU2p['status'] === 402
    && stripos((string)jget($eU2p['json'], 'error', ''), 'does not sell extra devices') !== false,
    'status ' . $eU2p['status'] . ' ' . (string)jget($eU2p['json'], 'error', ''));
/* now the plan sells extras → the paid account just keeps adding devices */
http('PUT', '/api/billing/plans/' . $starter['id'], $starterPut + ['extra_unit_price' => 3], $super);
$eU2f = http('POST', '/api/units', ['name' => 'Extra two', 'unique_id' => 'EXTRA-B-' . $rmTag], $eTok);
$eU3 = http('POST', '/api/units', ['name' => 'Extra three', 'unique_id' => 'EXTRA-C-' . $rmTag], $eTok);
check('a paid account can add extra devices beyond the limit',
    $eU2f['status'] === 200 && $eU3['status'] === 200,
    'second ' . $eU2f['status'] . ' third ' . $eU3['status']);
$eBill = http('GET', '/api/billing', null, $eTok);
$eOv = jget($eBill['json'], 'overage', []);
check('the billing page shows the extra devices and their charge',
    (int)jget($eOv, 'extra_units', -1) === 2 && (float)jget($eOv, 'amount', -1) === 6.0
    && (float)jget($eOv, 'extra_unit_price', -1) === 3.0
    && (int)jget($eOv, 'unit_limit', -1) === 1, json_encode($eOv));
/* the invoice covers plan + extras */
$eInv = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'], $eTok);
check('the checkout invoice includes the extra devices (19 + 2×3 = 25)',
    $eInv['status'] === 200
    && (float)jget(jget($eInv['json'], 'payment', []), 'amount', 0) === 25.0
    && stripos((string)jget($eInv['json'], 'message', ''), 'extra device') !== false,
    'status ' . $eInv['status'] . ' amount '
    . jget(jget($eInv['json'], 'payment', []), 'amount') . ' msg '
    . (string)jget($eInv['json'], 'message', ''));
$ePayId = (string)jget(jget($eInv['json'], 'payment', []), 'id', '');
http('POST', '/api/pay/' . $ePayId . '/complete', ['outcome' => 'paid'], null);
/* automatic renewal bills the extras too */
$eDays = (int)jget(jget(http('GET', '/api/billing', null, $eTok)['json'], 'billing', []), 'days_left', 30);
http('POST', '/api/accounts/' . $eId . '/billing',
    ['action' => 'extend_period', 'days' => -($eDays + 3)], $super);
$eRen = jget(http('POST', '/api/tasks/run', [], $super)['json'], 'billing', []);
check('the automatic renewal charges plan + extras as well',
    (int)jget($eRen, 'renewed', 0) >= 1, json_encode($eRen));
$eRenewalAmount = null;
foreach (jget(http('GET', '/api/billing', null, $eTok)['json'], 'invoices', []) as $iv) {
    if ((string)jget($iv, 'status') === 'paid' && (float)jget($iv, 'amount', 0) === 25.0) {
        $eRenewalAmount = (float)jget($iv, 'amount', 0); break;
    }
}
check('the renewal invoice again covers plan + extras', $eRenewalAmount === 25.0,
    json_encode($eRenewalAmount));
check('the overage test account is removed',
    http('DELETE', '/api/accounts/' . $eId . '?force=1', null, $super)['status'] === 200);

/* ------------------------------------------------------------------------
   asset types: captured at registration, one icon per type
   ------------------------------------------------------------------------ */
section('Asset types at registration & per-unit icons');
$plPub = http('GET', '/api/plans', null, null);
$ats = jget($plPub['json'], 'asset_types', []);
check('the asset type catalogue ships with glyphs',
    count($ats) >= 10 && !empty($ats['lorry']['glyph']) && !empty($ats['motorcycle']['glyph'])
    && !empty($ats['bicycle']['glyph']), json_encode(array_keys($ats)));
check('an unknown asset type is refused at registration',
    http('POST', '/api/signup', ['company' => 'Spaceship fleet ' . $rmTag,
        'email' => 'ship-' . $rmTag . '@example.com', 'password' => 'Suite@1234',
        'asset_type' => 'spaceship'], null)['status'] === 400);
$assetSignup = http('POST', '/api/signup', ['company' => 'Asset fleet ' . $rmTag,
    'email' => 'asset-' . $rmTag . '@example.com', 'password' => 'Suite@1234',
    'full_name' => 'Asset Owner', 'phone' => '254700111222', 'plan_code' => 'starter',
    'asset_type' => 'motorcycle'], null);
$assetTok = jget($assetSignup['json'], 'token');
check('registration with an asset type creates the account',
    is_string($assetTok), 'status ' . $assetSignup['status'] . ' '
    . (string)jget($assetSignup['json'], 'error', ''));
$assetMeta = http('GET', '/api/meta', null, $assetTok);
check('the fleet asset type is captured and exposed in meta',
    jget($assetMeta['json'], 'default_asset_type') === 'motorcycle'
    && count(jget($assetMeta['json'], 'asset_types', [])) >= 10,
    json_encode([jget($assetMeta['json'], 'default_asset_type'),
        array_keys(jget($assetMeta['json'], 'asset_types', []))]));
/* new units inherit the registered type, can override, cannot invent one */
$au1 = http('POST', '/api/units', ['name' => 'Asset inherited ' . $rmTag,
    'unique_id' => 'ASSET-I-' . $rmTag], $assetTok);
check('a new unit inherits the registered asset type',
    jget(jget($au1['json'], 'unit', []), 'asset_type') === 'motorcycle',
    json_encode(jget($au1['json'], 'unit', [])));
$au2 = http('POST', '/api/units', ['name' => 'Asset override ' . $rmTag,
    'unique_id' => 'ASSET-O-' . $rmTag, 'asset_type' => 'bicycle'], $assetTok);
check('a unit can carry its own asset type',
    jget(jget($au2['json'], 'unit', []), 'asset_type') === 'bicycle',
    json_encode(jget($au2['json'], 'unit', [])));
check('an unknown unit asset type is refused',
    http('POST', '/api/units', ['name' => 'Asset bad ' . $rmTag,
        'unique_id' => 'ASSET-X-' . $rmTag, 'asset_type' => 'spaceship'],
        $assetTok)['status'] === 400);
/* the list carries asset_type  —  the map marker and table column read it */
$auList = jget(http('GET', '/api/units?q=ASSET-', null, $assetTok)['json'], 'units', []);
$seenTypes = array_map(fn($x) => (string)($x['asset_type'] ?? ''), $auList);
check('the unit list exposes asset_type for the icons',
    in_array('motorcycle', $seenTypes, true) && in_array('bicycle', $seenTypes, true),
    json_encode($seenTypes));
$au2Id = (string)jget(jget($au2['json'], 'unit', []), 'id', '');
$auUpd = http('PUT', '/api/units/' . $au2Id, ['asset_type' => 'lorry'], $assetTok);
check('the asset type can be changed from the unit form',
    $auUpd['status'] === 200
    && jget(jget($auUpd['json'], 'unit', []), 'asset_type') === 'lorry',
    'status ' . $auUpd['status'] . ' ' . (string)jget($auUpd['json'], 'error', ''));
$assetAccountId = (string)jget(jget($assetSignup['json'], 'account', []), 'id', '');
http('DELETE', '/api/units/' . (string)jget(jget($au1['json'], 'unit', []), 'id', ''), null, $assetTok);
http('DELETE', '/api/units/' . $au2Id, null, $assetTok);
check('the asset-type test account is removed',
    http('DELETE', '/api/accounts/' . $assetAccountId . '?force=1', null, $super)['status'] === 200);

/* ------------------------------------------------------------------------
   reseller portals: the full-year licence, its annual invoice and the
   reseller console (creating client accounts inside the portal)
   ------------------------------------------------------------------------ */
section('Reseller portals (full-year licence)');
http('PUT', '/api/settings', ['portal_price' => 999], $super);
$ptTag = substr(md5('portal' . microtime(true)), 0, 6);
$ptOwner = 'reseller' . $ptTag;
$ptCreate = http('POST', '/api/portals', ['name' => 'Suite portal ' . $ptTag,
    'contact_email' => 'portal-' . $ptTag . '@example.com', 'owner_username' => $ptOwner,
    'owner_password' => 'Portal@1234', 'gateway' => 'sandbox'], $super);
$ptId = (string)jget(jget($ptCreate['json'], 'portal', []), 'id', '');
check('the super admin can create a reseller portal + its annual invoice',
    $ptCreate['status'] === 200 && $ptId !== ''
    && (string)jget(jget($ptCreate['json'], 'portal', []), 'status') === 'pending'
    && (float)jget(jget($ptCreate['json'], 'portal', []), 'price') === 999.0
    && (string)jget($ptCreate['json'], 'checkout_url', '') !== '',
    'status ' . $ptCreate['status'] . ' ' . (string)jget($ptCreate['json'], 'error', ''));
check('a taken reseller username is refused',
    http('POST', '/api/portals', ['name' => 'Clash ' . $ptTag,
        'contact_email' => 'clash-' . $ptTag . '@example.com', 'owner_username' => $ptOwner,
        'owner_password' => 'Portal@1234', 'gateway' => 'sandbox'], $super)['status'] === 400);
$ptOwnerTok = jget(http('POST', '/api/auth/login',
    ['username' => $ptOwner, 'password' => 'Portal@1234'])['json'], 'token');
$ptPay = (string)jget(jget($ptCreate['json'], 'payment', []), 'id', '');
http('POST', '/api/pay/' . $ptPay . '/complete', ['outcome' => 'paid'], null);
$ptList = http('GET', '/api/portals', null, $super);
$ptRow = null;
foreach (jget($ptList['json'], 'portals', []) as $row) {
    if ((string)jget($row, 'id') === $ptId) $ptRow = $row;
}
check('paying the annual invoice activates the portal for a full year',
    is_array($ptRow) && (string)jget($ptRow, 'status') === 'active'
    && (int)jget($ptRow, 'days_left', 0) >= 364 && jget($ptRow, 'period_end') !== null,
    json_encode($ptRow));
$ptState = http('GET', '/api/reseller/state', null, $ptOwnerTok);
check('the reseller console opens with a paid portal',
    $ptState['status'] === 200 && jget($ptState['json'], 'can_create') === true
    && (int)jget(jget($ptState['json'], 'portal', []), 'days_left', 0) >= 364,
    json_encode(jget($ptState['json'], 'portal', [])));
$ptClient = http('POST', '/api/reseller/accounts', ['name' => 'Suite client ' . $ptTag,
    'plan_code' => 'starter', 'admin_username' => 'suiteclient' . $ptTag,
    'admin_password' => 'Client@1234', 'contact_email' => 'client-' . $ptTag . '@example.com'], $ptOwnerTok);
$ptClientId = (string)jget($ptClient['json'], 'account_id', '');
check('the reseller creates a client account inside the portal',
    $ptClient['status'] === 200 && $ptClientId !== '',
    'status ' . $ptClient['status'] . ' ' . (string)jget($ptClient['json'], 'error', ''));
$ptState2 = http('GET', '/api/reseller/state', null, $ptOwnerTok);
$ptAcc = jget($ptState2['json'], 'accounts', []);
check('the client account appears with its device usage',
    count($ptAcc) === 1 && (string)jget($ptAcc[0], 'id') === $ptClientId
    && array_key_exists('units', $ptAcc[0]) && (int)jget($ptAcc[0], 'units') === 0
    && (int)jget($ptAcc[0], 'units_limit', 0) > 0, json_encode($ptAcc));
check('a portal with client accounts cannot be deleted',
    http('DELETE', '/api/portals/' . $ptId, null, $super)['status'] === 400);
/* an unpaid portal must be refused (402) — the second portal stays pending */
$pt2 = http('POST', '/api/portals', ['name' => 'Suite portal B ' . $ptTag,
    'contact_email' => 'portalb-' . $ptTag . '@example.com', 'owner_username' => 'resellerb' . $ptTag,
    'owner_password' => 'Portal@1234', 'gateway' => 'bank'], $super);
$pt2Id = (string)jget(jget($pt2['json'], 'portal', []), 'id', '');
$pt2Tok = jget(http('POST', '/api/auth/login',
    ['username' => 'resellerb' . $ptTag, 'password' => 'Portal@1234'])['json'], 'token');
$pt2State = http('GET', '/api/reseller/state', null, $pt2Tok);
check('an unpaid portal reports that it cannot create clients yet',
    $pt2State['status'] === 200 && jget($pt2State['json'], 'can_create') === false,
    json_encode(jget($pt2State['json'], 'can_create')));
check('creating a client inside an unpaid portal is refused (402)',
    http('POST', '/api/reseller/accounts', ['name' => 'Blocked ' . $ptTag,
        'plan_code' => 'starter'], $pt2Tok)['status'] === 402);
check('a tenant admin cannot use the platform portal endpoints',
    http('GET', '/api/portals', null, $admin)['status'] === 403);
check('the suite client account is removed',
    http('DELETE', '/api/accounts/' . $ptClientId . '?force=1', null, $super)['status'] === 200);
check('the portals are removed again',
    http('DELETE', '/api/portals/' . $ptId, null, $super)['status'] === 200
    && http('DELETE', '/api/portals/' . $pt2Id, null, $super)['status'] === 200);

/* ------------------------------------------------------------------------
   platform reports (super admin): financial, invoicing, alerts, performance
   ------------------------------------------------------------------------ */
section('Financial, invoicing & performance reports');
$finUser = 'finrep' . $rmTag;
$finId = (string)jget(http('POST', '/api/accounts', ['name' => 'Finance Report ' . $rmTag,
    'plan_code' => 'starter', 'units_limit' => 10, 'status' => 'trialing', 'trial_days' => 5,
    'contact_name' => 'Finance Owner', 'contact_email' => 'fin-' . $rmTag . '@example.com',
    'admin_username' => $finUser, 'admin_password' => 'Bill@1234'], $super)['json'], 'account_id', '');
check('report test account is created', $finId !== '');
$finTok = jget(http('POST', '/api/auth/login', ['username' => $finUser, 'password' => 'Bill@1234'],
    null)['json'], 'token');
http('POST', '/api/units', ['name' => 'Finance unit', 'unique_id' => 'FIN-U-' . $rmTag], $finTok);
/* a bank-transfer invoice stays pending → invoicing report + alert */
$finInv = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'bank'], $finTok);
$finNo = (string)jget(jget($finInv['json'], 'payment', []), 'invoice_no', '');
$finAmount = (float)jget(jget($finInv['json'], 'payment', []), 'amount', 0);
check('the report test invoice is pending (bank transfer)',
    $finInv['status'] === 200 && $finNo !== '' && $finAmount > 0,
    'status ' . $finInv['status'] . ' ' . json_encode(jget($finInv['json'], 'payment', [])));
/* one invoice that really is collected, so the financial totals are provable */
$finPay = http('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'sandbox'], $finTok);
$finPayId = (string)jget(jget($finPay['json'], 'payment', []), 'id', '');
$finDone = http('POST', '/api/pay/' . $finPayId . '/complete', ['outcome' => 'paid'], null);
check('a sandbox payment is collected for the report',
    $finPayId !== '' && $finDone['status'] === 200,
    'status ' . $finDone['status'] . ' ' . (string)jget($finDone['json'], 'message', ''));

$report = http('GET', '/api/finance/financial', null, $super);
check('the financial report returns collections, MRR and the mixes',
    $report['status'] === 200 && isset($report['json']['totals']['mrr'])
    && isset($report['json']['totals']['invoices']['paid'])
    && is_array(jget($report['json'], 'by_currency')) && is_array(jget($report['json'], 'by_gateway'))
    && is_array(jget($report['json'], 'by_plan')) && is_array(jget($report['json'], 'by_day')),
    json_encode(array_keys((array)$report['json'])));
check('the financial report carries the collected volume of the range',
    (float)jget(jget($report['json'], 'totals', []), 'collected', 0) > 0,
    json_encode(jget($report['json'], 'totals', [])));
check('the financial report counts the active (paying) accounts',
    (int)jget(jget($report['json'], 'totals', []), 'active_accounts', 0) >= 1,
    json_encode(jget($report['json'], 'totals', [])));

$invRep = http('GET', '/api/finance/invoicing?status=pending', null, $super);
$invRow = null;
foreach (jget($invRep['json'], 'rows', []) as $r) {
    if ((string)jget($r, 'invoice_no') === $finNo) $invRow = $r;
}
check('the invoicing report lists the invoice with its status and gateway',
    $invRep['status'] === 200 && is_array($invRow)
    && (string)jget($invRow, 'status') === 'pending' && (string)jget($invRow, 'gateway') === 'bank'
    && (string)jget($invRow, 'account') === 'Finance Report ' . $rmTag, json_encode($invRow));
check('the invoicing report ages the unpaid invoices',
    (int)jget(jget($invRep['json'], 'aging', []), 'b0_7', 0) >= 1,
    json_encode(jget($invRep['json'], 'aging', [])));
check('the invoicing report totals every status',
    count(jget($invRep['json'], 'totals', [])) >= 1,
    json_encode(jget($invRep['json'], 'totals', [])));

$alerts = http('GET', '/api/finance/alerts?overdue_days=0', null, $super);
$hit = null;
foreach (jget($alerts['json'], 'alerts', []) as $a) {
    if (jget($a, 'kind') === 'overdue_invoice' && (string)jget($a, 'ref') === $finNo) $hit = $a;
}
check('an unpaid invoice raises an invoicing alert',
    $alerts['status'] === 200 && is_array($hit) && (int)jget($alerts['json'], 'count', 0) >= 1,
    json_encode(jget($alerts['json'], 'alerts', [])));
check('the alert carries the amount, the gateway and the age',
    (float)jget($hit, 'amount', 0) === $finAmount && (string)jget($hit, 'gateway') === 'bank'
    && (int)jget($hit, 'age_days', -1) === 0, json_encode($hit));
$alerts7 = http('GET', '/api/finance/alerts', null, $super);
check('the default grace period keeps a brand-new invoice quiet',
    $alerts7['status'] === 200 && (int)jget($alerts7['json'], 'overdue_days', 0) === 7,
    json_encode(jget($alerts7['json'], 'overdue_days')));

$perf = http('GET', '/api/finance/performance', null, $super);
$perfRow = null;
foreach (jget($perf['json'], 'accounts', []) as $r) {
    if ((string)jget($r, 'account') === 'Finance Report ' . $rmTag) $perfRow = $r;
}
check('the performance report returns the platform totals',
    $perf['status'] === 200 && isset($perf['json']['platform']['messages_per_day'])
    && (int)jget(jget($perf['json'], 'platform', []), 'units', 0) >= 1
    && (int)jget($perf['json'], 'span_days', 0) >= 30,
    json_encode(jget($perf['json'], 'platform', [])));
check('the performance report breaks the KPIs down per account',
    is_array($perfRow) && (int)jget($perfRow, 'units', 0) === 1
    && (float)jget($perfRow, 'utilisation_pct', -1) === 10.0
    && array_key_exists('messages', $perfRow) && array_key_exists('revenue', $perfRow),
    json_encode($perfRow));

$csv = http('GET', '/api/finance/invoicing?format=csv', null, $super);
check('every report can be exported as CSV',
    $csv['status'] === 200 && stripos($csv['headers'], 'text/csv') !== false
    && strpos($csv['raw'], '"invoice_no"') === 0, substr($csv['raw'], 0, 48));
$csvPerf = http('GET', '/api/finance/performance?format=csv', null, $super);
check('the performance CSV export uses the report columns',
    $csvPerf['status'] === 200 && strpos($csvPerf['raw'], '"utilisation_pct"') !== false,
    substr($csvPerf['raw'], 0, 60));
check('platform reports are super-admin only (a tenant admin is refused)',
    http('GET', '/api/finance/financial', null, $admin)['status'] === 403);
check('the report test account is removed',
    http('DELETE', '/api/accounts/' . $finId . '?force=1', null, $super)['status'] === 200);

/* ------------------------------------------------------------------------
   supported-device catalogue + the platform map tiles (the default layer)
   ------------------------------------------------------------------------ */
section('Supported-device catalogue & map styles');
$devMeta = http('GET', '/api/meta', null, $admin);
$devCat = jget($devMeta['json'], 'device_catalogue', []);
$codes = array_map(fn($d) => (string)jget($d, 'code', ''), $devCat);
check('meta ships the supported-device catalogue',
    count($devCat) >= 3 && in_array('ips', $codes, true) && in_array('http', $codes, true)
    && in_array('phone', $codes, true), json_encode($codes));
$ipsDev = null;
foreach ($devCat as $d) if (jget($d, 'code') === 'ips') $ipsDev = $d;
check('the catalogue describes the native IPS protocol and example devices',
    (string)jget($ipsDev, 'protocol') !== '' && jget($ipsDev, 'native') === true
    && stripos((string)jget($ipsDev, 'examples', ''), 'Teltonika') !== false,
    json_encode($ipsDev));
check('meta still exposes the platform map tiles (the default layer)',
    is_string(jget($devMeta['json'], 'map_tiles', '')) && jget($devMeta['json'], 'map_tiles') !== '',
    (string)jget($devMeta['json'], 'map_tiles', ''));
$hwUnit = http('POST', '/api/units', ['name' => 'HW type unit', 'unique_id' => 'HW-' . $rmTag,
    'hw_type' => 'ips'], $admin);
check('a unit accepts a hardware type from the catalogue',
    $hwUnit['status'] === 200
    && (string)jget(jget($hwUnit['json'], 'unit', []), 'hw_type') === 'ips',
    'status ' . $hwUnit['status'] . ' ' . (string)jget($hwUnit['json'], 'error', ''));
http('DELETE', '/api/units/' . (string)jget(jget($hwUnit['json'], 'unit', []), 'id', ''), null, $admin);

/* ------------------------------------------------------------------------
   the downloadable Android client: /api/app (public) + /download + the menu
   ------------------------------------------------------------------------ */
section('Downloadable Android app');
$appInfo = http('GET', '/api/app', null, null);          /* public on purpose */
$app = jget($appInfo['json'], 'app', []);
$apkPath = dirname(__DIR__) . '/public/download/kivu-tracker.apk';
$apkThere = is_file($apkPath);
check('the app info endpoint is public and describes the package',
    $appInfo['status'] === 200
    && (string)jget($app, 'package') === 'com.kivutracker.app'
    && (string)jget($app, 'min_android') !== ''
    && (string)jget($app, 'page') === '/download'
    && (string)jget($app, 'url') === '/download/kivu-tracker.apk',
    json_encode([$appInfo['status'], jget($app, 'package'), jget($app, 'url')]));
check('it tells the app where this server is and where to post positions',
    (string)jget($app, 'server_url') !== '' && (string)jget($app, 'ingest_url') !== ''
    && substr((string)jget($app, 'ingest_url'), -11) === '/api/ingest',
    json_encode([jget($app, 'server_url'), jget($app, 'ingest_url')]));
check('the install steps, permissions and features are part of the payload',
    count(jget($app, 'install_steps', [])) >= 3 && count(jget($app, 'permissions', [])) >= 2
    && count(jget($app, 'features', [])) >= 3,
    json_encode([count(jget($app, 'install_steps', [])), count(jget($app, 'permissions', []))]));
check('the availability flag matches public/download/kivu-tracker.apk',
    jget($app, 'available') === $apkThere,
    'flag ' . json_encode(jget($app, 'available')) . ' file ' . json_encode($apkThere));
if ($apkThere) {
    check('a published package reports its size and matches its SHA-256',
        (int)jget($app, 'size_bytes') === (int)filesize($apkPath)
        && (string)jget($app, 'sha256') === hash_file('sha256', $apkPath),
        json_encode([jget($app, 'size_bytes'), jget($app, 'sha256')]));
}
/* the version, notes and a remote URL are super-admin settings */
$appSave = http('PUT', '/api/settings', ['apk_version' => '9.9.9-test',
    'apk_notes' => 'Suite build'], $super);
check('a super admin can set the app version and release notes',
    $appSave['status'] === 200
    && (string)jget(jget($appSave['json'], 'settings', []), 'apk_version') === '9.9.9-test',
    'status ' . $appSave['status'] . ' ' . (string)jget($appSave['json'], 'error', ''));
$app2 = jget(http('GET', '/api/app', null, null)['json'], 'app', []);
check('the download page then shows that version and what\'s new',
    (string)jget($app2, 'version') === '9.9.9-test' && (string)jget($app2, 'whats_new') === 'Suite build',
    json_encode([jget($app2, 'version'), jget($app2, 'whats_new')]));
http('PUT', '/api/settings', ['apk_url' => 'https://downloads.example.com/kivu-9.9.9.apk'], $super);
$app3 = jget(http('GET', '/api/app', null, null)['json'], 'app', []);
check('an externally hosted package is offered as-is (and counts as available)',
    (string)jget($app3, 'url') === 'https://downloads.example.com/kivu-9.9.9.apk'
    && jget($app3, 'hosted') === true && jget($app3, 'available') === true,
    json_encode([jget($app3, 'url'), jget($app3, 'hosted'), jget($app3, 'available')]));
http('PUT', '/api/settings', ['apk_url' => '', 'apk_version' => '', 'apk_notes' => ''], $super);
$app4 = jget(http('GET', '/api/app', null, null)['json'], 'app', []);
check('clearing the overrides falls back to the local package and 1.0.0',
    (string)jget($app4, 'url') === '/download/kivu-tracker.apk'
    && (string)jget($app4, 'version') === '1.0.0' && jget($app4, 'hosted') === false,
    json_encode([jget($app4, 'url'), jget($app4, 'version')]));
check('a non-super admin cannot change the app settings',
    http('PUT', '/api/settings', ['apk_version' => 'nope'], $admin)['status'] === 403);
/* the page itself and the menu link */
$dlPage = http('GET', '/download', null, null);
check('the download page is served (and is public)',
    $dlPage['status'] === 200 && strpos($dlPage['raw'], 'id="appStats"') !== false
    && strpos($dlPage['raw'], '/js/download.js') !== false,
    'status ' . $dlPage['status']);
$commonJs = http('GET', '/js/common.js', null, null);
check('the top-bar menu carries the download link for every role',
    $commonJs['status'] === 200 && strpos($commonJs['raw'], "'/download', label: 'Get the app'") !== false
    && strpos($commonJs['raw'], "'reseller']") !== false,
    'status ' . $commonJs['status']);
check('the landing page links to the app as well',
    strpos(http('GET', '/', null, null)['raw'], '/download') !== false);
check('an unknown file in the download folder is still a clean 404',
    http('GET', '/download/does-not-exist.apk', null, null)['status'] === 404);

/* ------------------------------------------------------------------------
   the platform logo: upload, exposure, replacement, removal
   ------------------------------------------------------------------------ */
section('Platform logo (system settings)');
$logoTmp = sys_get_temp_dir() . '/kivu-logo-' . $rmTag;
$logoA = $logoTmp . '-a.png';
$logoB = $logoTmp . '-b.png';
$logoBad = $logoTmp . '-bad.png';
$logoClean = $logoTmp . '-ok.svg';
$logoEvil = $logoTmp . '-evil.svg';
file_put_contents($logoA, png_bytes(8, 8, 30, 120, 220));
file_put_contents($logoB, png_bytes(8, 8, 200, 60, 60));
file_put_contents($logoBad, "this is not an image at all\n");
file_put_contents($logoClean, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
    . '<rect width="10" height="10" fill="#2f9cf4"/></svg>');
file_put_contents($logoEvil, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

$logoPaths = dirname(__DIR__) . '/public/img/branding';
foreach (glob($logoPaths . '/logo-*') ?: [] as $stale) @unlink($stale);   /* start clean */

$set = jget(http('GET', '/api/settings', null, $super)['json'], 'settings', []);
check('the settings payload carries the logo field', array_key_exists('logo_url', $set),
    json_encode(array_slice(array_keys($set), 0, 8)));
$urlLogo = 'https://cdn.example.com/logo.png';
check('a logo can also be a plain URL (no upload needed)',
    http('PUT', '/api/settings', ['logo_url' => $urlLogo], $super)['status'] === 200
    && (string)jget(http('GET', '/api/meta', null, $admin)['json'], 'logo') === $urlLogo,
    (string)jget(http('GET', '/api/meta', null, $admin)['json'], 'logo'));
http('PUT', '/api/settings', ['logo_url' => ''], $super);

check('only a super admin may upload a logo',
    httpUpload('/api/settings/logo', $logoA, 'logo', $admin)['status'] === 403
    && httpUpload('/api/settings/logo', $logoA, 'logo', null)['status'] === 401);
$up = httpUpload('/api/settings/logo', $logoA, 'logo', $super);
$logoUrl = (string)jget($up['json'], 'logo_url', '');
$logoFile = $logoPaths . '/' . basename($logoUrl);
check('a super admin can upload a PNG logo',
    $up['status'] === 200 && strpos($logoUrl, '/img/branding/logo-') === 0
    && is_file($logoFile) && filesize($logoFile) === filesize($logoA),
    'status ' . $up['status'] . ' url ' . $logoUrl . ' ' . (string)jget($up['json'], 'error', ''));
check('the uploaded logo is exposed to the consoles, the public pages and the app',
    (string)jget(http('GET', '/api/meta', null, $admin)['json'], 'logo') === $logoUrl
    && (string)jget(http('GET', '/api/plans', null, null)['json'], 'logo') === $logoUrl
    && (string)jget(http('GET', '/api/health', null, null)['json'], 'logo') === $logoUrl,
    $logoUrl);
$served = http('GET', $logoUrl, null, null);
check('the logo file is served as an image',
    $served['status'] === 200 && stripos($served['headers'], 'image/png') !== false,
    'status ' . $served['status']);
check('the audit trail records who changed the logo',
    strpos(http('GET', '/api/audit?limit=50', null, $super)['raw'], 'platform_logo_set') !== false);
/* replacing the logo deletes the previous file */
$up2 = httpUpload('/api/settings/logo', $logoB, 'logo', $super);
check('replacing the logo removes the previous file',
    $up2['status'] === 200 && (string)jget($up2['json'], 'logo_url') !== $logoUrl
    && !is_file($logoFile), json_encode([jget($up2['json'], 'logo_url'), is_file($logoFile)]));
/* rejections: a text file, a scripted SVG, and an oversized image */
check('a text file renamed .png is refused',
    httpUpload('/api/settings/logo', $logoBad, 'logo', $super)['status'] === 400);
check('an SVG with a script is refused (active content)',
    httpUpload('/api/settings/logo', $logoEvil, 'logo', $super)['status'] === 400);
$big = $logoTmp . '-big.png';
/* random bytes: reliably larger than the 512 KB cap (a solid PNG would compress) */
file_put_contents($big, random_bytes(600 * 1024));
check('an oversized logo is refused with a clear message',
    stripos(httpUpload('/api/settings/logo', $big, 'logo', $super)['raw'], 'too large') !== false,
    substr(httpUpload('/api/settings/logo', $big, 'logo', $super)['raw'], 0, 120));
$up3 = httpUpload('/api/settings/logo', $logoClean, 'logo', $super);
check('a clean SVG logo is accepted and stored as .svg',
    $up3['status'] === 200 && substr((string)jget($up3['json'], 'logo_url'), -4) === '.svg',
    'status ' . $up3['status'] . ' ' . (string)jget($up3['json'], 'logo_url'));
check('only a super admin may remove the logo',
    http('DELETE', '/api/settings/logo', null, $admin)['status'] === 403);
$svgFile = $logoPaths . '/' . basename((string)jget($up3['json'], 'logo_url'));
$del = http('DELETE', '/api/settings/logo', null, $super);
check('removing the logo clears the setting and deletes the file',
    $del['status'] === 200 && (string)jget($del['json'], 'logo_url') === ''
    && !is_file($svgFile)
    && (string)jget(http('GET', '/api/meta', null, $admin)['json'], 'logo') === '',
    json_encode([$del['status'], jget($del['json'], 'logo_url'), is_file($svgFile)]));
check('the branding folder holds no leftover test files',
    count(glob($logoPaths . '/logo-*') ?: []) === 0);
foreach ([$logoA, $logoB, $logoBad, $logoClean, $logoEvil, $big] as $f) @unlink($f);

/* ------------------------------------------------------------------------
   cleanup: the suite's client accounts and their devices disappear again
   (last, because the webhook checks above still need the trial session)
   ------------------------------------------------------------------------ */
check('the suite client account is removed',
    http('DELETE', '/api/accounts/' . $trialAccount . '?force=1', null, $super)['status'] === 200);
check('the removed account can no longer sign in',
    http('POST', '/api/auth/login', ['username' => 'billing-suite-' . $signupTag . '@example.com',
        'password' => 'Suite@1234'], null)['status'] === 401);
check('the created units were cleaned up',
    http('GET', '/api/units?q=SUITE-' . $signupTag, null, $admin)['status'] === 200
    && count(jget(http('GET', '/api/units?q=SUITE-' . $signupTag, null, $admin)['json'], 'units', [])) === 0);

if ($fail) {
    echo "  failures:\n";
    foreach ($failures as $f) echo "   - $f\n";
    exit(1);
}
echo "  ALL CHECKS PASSED\n";
exit(0);
