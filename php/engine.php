<?php
/* =====================================================================
   KIVU Tracker — php/engine.php
   Geospatial maths, offline geocoding, unit state/live-shape helpers.
   Included by api.php, tracker.php and the CLI tools.
   ===================================================================== */
declare(strict_types=1);

const EARTH_R = 6371000.0;

/* ------------------------------------------------------------ geo maths -- */
function geo_dist_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * EARTH_R * asin(min(1.0, sqrt($a)));
}
function geo_dist_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    return geo_dist_m($lat1, $lon1, $lat2, $lon2) / 1000;
}
function geo_bearing(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $dLon = deg2rad($lon2 - $lon1);
    $y = sin($dLon) * cos(deg2rad($lat2));
    $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLon);
    return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
}
/* Distance from a point to a segment, in metres. */
function point_segment_m(float $lat, float $lon, float $alat, float $alon, float $blat, float $blon): float {
    $mPerDegLat = 111132.92;
    $mPerDegLon = 111320.0 * cos(deg2rad(($alat + $blat) / 2));
    $px = ($lon - $alon) * $mPerDegLon; $py = ($lat - $alat) * $mPerDegLat;
    $bx = ($blon - $alon) * $mPerDegLon; $by = ($blat - $alat) * $mPerDegLat;
    $len2 = $bx * $bx + $by * $by;
    if ($len2 <= 1e-9) return sqrt($px * $px + $py * $py);
    $t = max(0.0, min(1.0, ($px * $bx + $py * $by) / $len2));
    $dx = $px - $t * $bx; $dy = $py - $t * $by;
    return sqrt($dx * $dx + $dy * $dy);
}
function point_in_polygon(float $lat, float $lon, array $pts): bool {
    $inside = false;
    $n = count($pts);
    if ($n < 3) return false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $yi = (float)$pts[$i][0]; $xi = (float)$pts[$i][1];
        $yj = (float)$pts[$j][0]; $xj = (float)$pts[$j][1];
        $den = ($yj - $yi);
        if ($den == 0.0) $den = 1e-12;
        if ((($yi > $lat) !== ($yj > $lat)) && ($lon < ($xj - $xi) * ($lat - $yi) / $den + $xi)) {
            $inside = !$inside;
        }
    }
    return $inside;
}
/* Is a point inside/on a geofence? $tolM widens circles/polygons/polylines. */
function geo_contains(array $geo, float $lat, float $lon, float $tolM = 0.0): bool {
    $pts = jdec($geo['points_json'] ?? null, []);
    $type = $geo['type'] ?? 'polygon';
    if ($type === 'circle') {
        if (!$pts) return false;
        return geo_dist_m($lat, $lon, (float)$pts[0][0], (float)$pts[0][1]) <= ((float)$geo['radius_m'] + $tolM);
    }
    if ($type === 'polyline' || $type === 'route') {
        $tol = $tolM > 0 ? $tolM : 30.0;
        for ($i = 1; $i < count($pts); $i++) {
            if (point_segment_m($lat, $lon, (float)$pts[$i - 1][0], (float)$pts[$i - 1][1], (float)$pts[$i][0], (float)$pts[$i][1]) <= $tol) return true;
        }
        return false;
    }
    if (point_in_polygon($lat, $lon, $pts)) return true;
    if ($tolM > 0) {
        $m = count($pts);
        for ($i = 1; $i < $m; $i++) {
            if (point_segment_m($lat, $lon, (float)$pts[$i - 1][0], (float)$pts[$i - 1][1], (float)$pts[$i][0], (float)$pts[$i][1]) <= $tolM) return true;
        }
    }
    return false;
}
function geo_polyline_length_m(array $pts): float {
    $d = 0.0;
    for ($i = 1; $i < count($pts); $i++) {
        $d += geo_dist_m((float)$pts[$i - 1][0], (float)$pts[$i - 1][1], (float)$pts[$i][0], (float)$pts[$i][1]);
    }
    return $d;
}
/* [[minLat,minLon],[maxLat,maxLon]] for a list of point-lists. */
function geo_bbox(array $ptsList): ?array {
    $minLat = $minLon = INF; $maxLat = $maxLon = -INF;
    foreach ($ptsList as $pts) {
        foreach ($pts as $p) {
            $la = (float)$p[0]; $lo = (float)$p[1];
            $minLat = min($minLat, $la); $maxLat = max($maxLat, $la);
            $minLon = min($minLon, $lo); $maxLon = max($maxLon, $lo);
        }
    }
    if ($minLat === INF) return null;
    return [[$minLat, $minLon], [$maxLat, $maxLon]];
}

/* ------------------------------------------------------- offline geocoder */
function places_all(): array {
    static $places = null;
    if ($places === null) {
        try { $places = db()->query('SELECT name,kind,lat,lon,country FROM places')->fetchAll(); }
        catch (Throwable $e) { $places = []; }
    }
    return $places;
}
function nearest_place(float $lat, float $lon, float $maxKm = 12.0): ?array {
    $best = null; $bestD = $maxKm;
    foreach (places_all() as $p) {
        $d = geo_dist_km($lat, $lon, (float)$p['lat'], (float)$p['lon']);
        if ($d < $bestD) { $bestD = $d; $best = $p + ['distance_km' => $d]; }
    }
    return $best;
}
/* Human-readable location without any external API. */
function address_of($lat, $lon): string {
    if ($lat === null || $lon === null) return '';
    $lat = (float)$lat; $lon = (float)$lon;
    $p = nearest_place($lat, $lon);
    if ($p) {
        $d = (float)$p['distance_km'];
        $suffix = (string)($p['country'] ?? '');
        if ($d < 0.35) return (string)$p['name'] . ($suffix ? ', ' . $suffix : '');
        return sprintf('%s (+%.1f km)%s', $p['name'], $d, $suffix ? ', ' . $suffix : '');
    }
    return sprintf('%.5f, %.5f', $lat, $lon);
}

/* ---------------------------------------------------------------- units -- */
function unit_defaults(): array {
    return ['fuel_rate' => 8.0, 'fuel_price' => 1.2, 'cost_per_km' => 0.35, 'speed_limit' => 90,
        'parking_timeout_s' => 180, 'min_trip_s' => 60, 'min_trip_km' => 0.3, 'min_park_s' => 300, 'max_jump_km' => 3.0];
}
/* A single fix may never move a vehicle further than this. The per-unit max_jump_km plus
   an implied-speed check (see ingest_point) catches normal teleports, but when the stored
   history is stale a huge move can look plausible; this hard cap rejects it regardless.
   The value only greys out bad fixes (valid = 0) — nothing is ever deleted. */
function max_teleport_km(): float { return 500.0; }
function unit_by_id(string $id): array {
    $st = db()->prepare('SELECT * FROM units WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    need($u, 'Unit not found', 404);
    return $u;
}
/* status: moving | idle | parked | offline | never */
function unit_state(array $u): array {
    $timeout = (int)setting('online_timeout_s', 300);
    $movingSpeed = (float)setting('moving_speed_kmh', 5);
    $age = empty($u['last_time']) ? null : max(0, time() - (int)strtotime((string)$u['last_time']));
    $speed = (float)$u['last_speed'];
    if ($age === null) return ['status' => 'never', 'age_s' => null, 'color' => '#8b93a7', 'label' => 'No data'];
    if ($age > $timeout) return ['status' => 'offline', 'age_s' => $age, 'color' => '#7d8598', 'label' => 'Offline'];
    if ((int)$u['moving'] === 1 && $speed > $movingSpeed) return ['status' => 'moving', 'age_s' => $age, 'color' => '#2ecc71', 'label' => 'Moving'];
    if ($u['last_ignition'] === null || (int)$u['last_ignition'] === 1) {
        return ['status' => 'idle', 'age_s' => $age, 'color' => '#f1c40f', 'label' => 'Idle (engine on)'];
    }
    return ['status' => 'parked', 'age_s' => $age, 'color' => '#2f9cf4', 'label' => 'Parked'];
}
function unit_live_shape(array $u): array {
    $st = unit_state($u);
    return [
        'id' => $u['id'], 'name' => $u['name'], 'unique_id' => $u['unique_id'],
        'account_id' => $u['account_id'], 'group_id' => $u['group_id'],
        'group_name' => $u['group_name'] ?? null, 'driver_id' => $u['driver_id'] ?? null,
        'driver_name' => $u['driver_name'] ?? null,
        'plate' => $u['plate'], 'model' => $u['model'], 'phone' => $u['phone'], 'tags' => $u['tags'],
        'asset_type' => $u['asset_type'] ?? null,
        'enabled' => (int)$u['enabled'],
        'lat' => $u['last_lat'] === null ? null : (float)$u['last_lat'],
        'lon' => $u['last_lon'] === null ? null : (float)$u['last_lon'],
        'speed' => (float)$u['last_speed'], 'course' => (float)$u['last_course'],
        'alt' => $u['last_alt'] === null ? null : (float)$u['last_alt'],
        'sats' => $u['last_sats'] === null ? null : (int)$u['last_sats'],
        'ignition' => $u['last_ignition'] === null ? null : (int)$u['last_ignition'],
        'fuel' => $u['last_fuel'] === null ? null : (float)$u['last_fuel'],
        'voltage' => $u['last_voltage'] === null ? null : (float)$u['last_voltage'],
        'altitude' => $u['last_alt'] === null ? null : (float)$u['last_alt'],
        'mileage' => (float)$u['odometer_km'], 'engine_hours' => (float)$u['engine_hours'],
        'last_time' => iso($u['last_time']), 'status' => $st['status'], 'status_label' => $st['label'],
        'color' => $st['color'], 'age_s' => $st['age_s'],
        'address' => address_of($u['last_lat'], $u['last_lon']),
        'speed_limit' => (int)$u['speed_limit'], 'fuel_rate' => (float)$u['fuel_rate'],
    ];
}
/* Filters: account_id, q, group_id, status, enabled, tag, ids[] */
function units_list(array $f, ?array $authUser = null): array {
    $sql = 'SELECT u.*, g.name AS group_name, d.name AS driver_name FROM units u'
         . ' LEFT JOIN unit_groups g ON g.id = u.group_id'
         . ' LEFT JOIN drivers d ON d.id = u.driver_id WHERE 1=1';
    $p = [];
    if (!empty($f['account_id'])) { $sql .= ' AND u.account_id = ?'; $p[] = $f['account_id']; }
    if (!empty($f['group_id'])) { $sql .= ' AND u.group_id = ?'; $p[] = $f['group_id']; }
    if (!empty($f['q'])) {
        $sql .= ' AND (u.name LIKE ? OR u.unique_id LIKE ? OR u.plate LIKE ? OR u.tags LIKE ? OR u.phone LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($p, $like, $like, $like, $like, $like);
    }
    if (!empty($f['tag'])) { $sql .= ' AND u.tags LIKE ?'; $p[] = '%' . $f['tag'] . '%'; }
    if (isset($f['enabled']) && $f['enabled'] !== '' && $f['enabled'] !== null) { $sql .= ' AND u.enabled = ?'; $p[] = (int)$f['enabled']; }
    if (!empty($f['ids']) && is_array($f['ids'])) {
        $sql .= ' AND u.id IN (' . implode(',', array_fill(0, count($f['ids']), '?')) . ')';
        foreach ($f['ids'] as $id) $p[] = $id;
    }
    [$scope, $scopeP] = unit_scope_sql($authUser, 'u.id');
    $sql .= $scope;
    $p = array_merge($p, $scopeP);
    $sql .= ' ORDER BY u.name';
    $st = db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    $status = isset($f['status']) && $f['status'] !== '' ? (string)$f['status'] : null;
    if ($status !== null) {
        $rows = array_values(array_filter($rows, fn($u) => unit_state($u)['status'] === $status));
    }
    return $rows;
}
function geofences_list(?string $accountId, bool $enabledOnly = false): array {
    /* Memoised per process: the ingest path calls this for every single
       position packet, and geofences change rarely (each web request is a
       new process, so the cache can never serve stale data across edits). */
    static $cache = [];
    $key = ($accountId ?? '*') . ($enabledOnly ? '|on' : '|all');
    if (isset($cache[$key])) return $cache[$key];
    $sql = 'SELECT * FROM geofences WHERE 1=1';
    $p = [];
    if ($accountId) { $sql .= ' AND account_id = ?'; $p[] = $accountId; }
    if ($enabledOnly) $sql .= ' AND enabled = 1';
    $sql .= ' ORDER BY name';
    $st = db()->prepare($sql);
    $st->execute($p);
    $cache[$key] = $st->fetchAll();
    return $cache[$key];
}
function geofences_forget_cache(): void {
    /* no-op helper kept for clarity — cache lives for one request only */
}
