<?php
/* =====================================================================
   KIVU Tracker — php/ingest.php
   Turns a raw position packet into stored telemetry:
     validate/filter jumps -> store message -> update unit live state ->
     geofence enter/exit -> notification rules -> command queue.
   Used by php/tracker.php (IPS devices), /api/ingest and the CLI tools.
   ===================================================================== */
declare(strict_types=1);

/* Pulls the first existing numeric parameter among $names. */
function param_value(array $params, array $names): ?float {
    foreach ($names as $n) {
        if (isset($params[$n]) && $params[$n] !== '' && is_numeric($params[$n])) return (float)$params[$n];
    }
    foreach ($names as $n) {                       // case-insensitive second pass
        foreach ($params as $k => $v) {
            if (strcasecmp((string)$k, (string)$n) === 0 && $v !== '' && is_numeric($v)) return (float)$v;
        }
    }
    return null;
}

const PARAM_IGNITION = ['ign', 'ignition', 'acc', 'ign_status'];
const PARAM_FUEL     = ['fuel', 'f1', 'F1', 'fuel_level', 'fuel1'];
const PARAM_VOLTAGE  = ['pwr', 'voltage', 'vbat', 'bat', 'power'];
const PARAM_HDOP     = ['hdop'];
const PARAM_TEMP     = ['temp', 'temperature'];
const PARAM_MILEAGE  = ['mileage', 'odometer', 'odo', 'count1'];
const PARAM_EHOURS   = ['eh', 'engine_hours', 'hrs'];
const PARAM_SOS      = ['sos', 'alarm', 'panic'];

/* Normalises any incoming packet into the canonical message shape. */
function canonical_message(array $raw): array {
    $p = $raw['params'] ?? [];
    if (!is_array($p)) $p = [];
    $ign = param_value($p, PARAM_IGNITION);
    $msg = [
        'ts' => dt_param($raw['ts'] ?? null, now_dt()),
        'lat' => isset($raw['lat']) && $raw['lat'] !== '' ? (float)$raw['lat'] : null,
        'lon' => isset($raw['lon']) && $raw['lon'] !== '' ? (float)$raw['lon'] : null,
        'speed' => (float)($raw['speed'] ?? 0),          // km/h
        'course' => (float)($raw['course'] ?? 0),
        'alt' => (float)($raw['alt'] ?? 0),
        'sats' => (int)($raw['sats'] ?? 0),
        'hdop' => param_value($p, PARAM_HDOP),
        'ignition' => $ign === null ? null : ($ign > 0 ? 1 : 0),
        'fuel' => param_value($p, PARAM_FUEL),
        'voltage' => param_value($p, PARAM_VOLTAGE),
        'temperature' => param_value($p, PARAM_TEMP),
        'mileage' => param_value($p, PARAM_MILEAGE),
        'engine_hours' => param_value($p, PARAM_EHOURS),
        'sos' => param_value($p, PARAM_SOS),
        'params' => $p,
        'source' => (string)($raw['source'] ?? 'ips'),
        'valid' => array_key_exists('valid', $raw) ? (int)(bool)$raw['valid'] : 1,
    ];
    if ($msg['lat'] !== null && ($msg['lat'] < -90 || $msg['lat'] > 90)) $msg['valid'] = 0;
    if ($msg['lon'] !== null && ($msg['lon'] < -180 || $msg['lon'] > 180)) $msg['valid'] = 0;
    if ($msg['lat'] === null || $msg['lon'] === null) $msg['valid'] = 0;
    if ($msg['lat'] === 0.0 && $msg['lon'] === 0.0) $msg['valid'] = 0;
    return $msg;
}

function last_message(string $unitId, ?string $beforeTs = null): ?array {
    if ($beforeTs !== null) {
        $st = db()->prepare('SELECT * FROM messages WHERE unit_id = ? AND ts < ? ORDER BY ts DESC LIMIT 1');
        $st->execute([$unitId, $beforeTs]);
    } else {
        $st = db()->prepare('SELECT * FROM messages WHERE unit_id = ? ORDER BY ts DESC LIMIT 1');
        $st->execute([$unitId]);
    }
    return $st->fetch() ?: null;
}

/* Main entry point: store one position for a unit.
   Returns a summary used by the tracker log / API response.
   Positions are refused (not stored) when the owning account's trial or
   subscription has ended, so devices cannot feed an inactive account. */
function ingest_point(array $unit, array $raw): array {
    /* billing helpers decide whether the owning account may still ingest;
       load them for entry points that do not go through php/api.php
       (the IPS tracker daemon, the history generator, the CLI seeder). */
    if (!function_exists('billing_state')) require_once __DIR__ . '/payments.php';
    static $allowed = [];
    $accountId = (string)($unit['account_id'] ?? '');
    if ($accountId !== '' && isset($allowed[$accountId]) && $allowed[$accountId] === false) {
        return ['unit_id' => $unit['id'], 'ts' => (string)($raw['ts'] ?? now_dt()), 'valid' => false,
            'stored' => false, 'rejected' => true, 'reason' => 'account_inactive',
            'message' => 'Account inactive — no active plan', 'events' => [], 'geofence_events' => []];
    }
    if ($accountId !== '' && !isset($allowed[$accountId])) {
        $owner = account_by_id($accountId);
        $state = $owner ? billing_state($accountId, $owner) : ['allowed' => true];
        $allowed[$accountId] = !empty($state['allowed']);
        if (!$allowed[$accountId]) {
            return ['unit_id' => $unit['id'], 'ts' => (string)($raw['ts'] ?? now_dt()), 'valid' => false,
                'stored' => false, 'rejected' => true, 'reason' => 'account_inactive',
                'message' => (string)($state['reason'] ?? 'Account inactive — no active plan'),
                'events' => [], 'geofence_events' => []];
        }
    }
    $m = canonical_message($raw);
    $prev = last_message($unit['id'], $m['ts']);
    $res = ['unit_id' => $unit['id'], 'ts' => $m['ts'], 'valid' => (bool)$m['valid'],
            'stored' => true, 'distance_km' => 0.0, 'events' => [], 'geofence_events' => []];
    $movingSpeed = (float)setting('moving_speed_kmh', 5);

    /* ---- GPS jump filtering (tracker-style validity check) ------------ */
    if ($m['valid'] === 1 && $prev && $prev['lat'] !== null) {
        $dt = max(1, (int)strtotime($m['ts']) - (int)strtotime((string)$prev['ts']));
        $distKm = geo_dist_km((float)$prev['lat'], (float)$prev['lon'], (float)$m['lat'], (float)$m['lon']);
        $implied = $distKm / ($dt / 3600.0);
        if ($distKm > (float)$unit['max_jump_km'] && ($implied > 250.0 || $distKm > max_teleport_km())) {
            $m['valid'] = 0;
            $res['valid'] = false;
            $res['reason'] = 'gps_jump';
        }
    }

    /* ---- distance between two accepted fixes -------------------------- */
    $distKm = 0.0;
    if ($m['valid'] === 1 && $prev && $prev['lat'] !== null) {
        $step = geo_dist_km((float)$prev['lat'], (float)$prev['lon'], (float)$m['lat'], (float)$m['lon']);
        $movingNow = $m['speed'] > $movingSpeed;
        $movingPrev = (float)$prev['speed'] > $movingSpeed;
        if (($movingNow || $movingPrev) && $step < 5.0) $distKm = $step;
    }
    $res['distance_km'] = round($distKm, 4);

    /* ---- store the message -------------------------------------------- */
    $ins = db()->prepare('INSERT INTO messages (unit_id,ts,lat,lon,speed,course,alt,sats,hdop,ignition,fuel,voltage,mileage,engine_hours,params_json,valid,source,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$unit['id'], $m['ts'], $m['lat'], $m['lon'], $m['speed'], $m['course'], $m['alt'], $m['sats'],
        $m['hdop'], $m['ignition'], $m['fuel'], $m['voltage'], $m['mileage'], $m['engine_hours'],
        $m['params'] ? jenc($m['params']) : null, $m['valid'], $m['source'], now_dt()]);
    $res['message_id'] = (int)db()->lastInsertId();

    if ($m['valid'] !== 1) return $res;      // invalid fixes never touch the live state

    /* ---- unit live state ---------------------------------------------- */
    $set = ['last_time = ?', 'last_lat = ?', 'last_lon = ?', 'last_speed = ?', 'last_course = ?',
            'last_alt = ?', 'last_sats = ?', 'online = 1', 'moving = ?'];
    $vals = [$m['ts'], $m['lat'], $m['lon'], $m['speed'], $m['course'], $m['alt'], $m['sats'],
             $m['speed'] > $movingSpeed ? 1 : 0];
    if ($m['ignition'] !== null) { $set[] = 'last_ignition = ?'; $vals[] = $m['ignition']; }
    if ($m['fuel'] !== null) { $set[] = 'last_fuel = ?'; $vals[] = $m['fuel']; }
    if ($m['voltage'] !== null) { $set[] = 'last_voltage = ?'; $vals[] = $m['voltage']; }
    if ($distKm > 0) { $set[] = 'odometer_km = odometer_km + ?'; $vals[] = $distKm; }
    if ($m['engine_hours'] !== null) { $set[] = 'engine_hours = ?'; $vals[] = $m['engine_hours']; }
    elseif ($m['ignition'] === 1 && $prev && (int)$prev['ignition'] === 1) {
        $delta = (int)strtotime($m['ts']) - (int)strtotime((string)$prev['ts']);
        if ($delta > 0 && $delta < 3600) { $set[] = 'engine_hours = engine_hours + ?'; $vals[] = $delta / 3600.0; }
    }
    if (!empty($raw['ip'])) { $set[] = 'last_ip = ?'; $vals[] = $raw['ip']; }
    $vals[] = $unit['id'];
    db()->prepare('UPDATE units SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    $res['mileage'] = round((float)$unit['odometer_km'] + $distKm, 2);

    /* ---- geofences + notification rules -------------------------------- */
    $res['geofence_events'] = geofence_check($unit, $m, $prev);
    $res['events'] = notification_rules($unit, $m, $prev, $res['geofence_events']);
    return $res;
}
