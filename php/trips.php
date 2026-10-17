<?php
/* =====================================================================
   KIVU Tracker — php/trips.php
   Trip / parking / idle detection ("movement" report) and fuel
   filling-draining analysis, computed straight from the messages table.
   ===================================================================== */
declare(strict_types=1);

/* Messages of one unit in a window (optionally valid fixes only). */
function messages_range(string $unitId, ?string $from = null, ?string $to = null, bool $validOnly = true): array {
    $sql = 'SELECT * FROM messages WHERE unit_id = ?';
    $p = [$unitId];
    if ($from !== null) { $sql .= ' AND ts >= ?'; $p[] = $from; }
    if ($to !== null) { $sql .= ' AND ts <= ?'; $p[] = $to; }
    if ($validOnly) $sql .= ' AND valid = 1';
    $sql .= ' ORDER BY ts';
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

/* Distance / speed / engine-time metrics for a slice of messages. */
function block_metrics(array $msgs, float $movingSpeed): array {
    $distance = 0.0; $maxSpeed = 0.0; $movingS = 0; $idleS = 0; $stops = 0; $wasMoving = null;
    $n = count($msgs);
    for ($i = 0; $i < $n; $i++) {
        $m = $msgs[$i];
        $speed = (float)$m['speed'];
        $maxSpeed = max($maxSpeed, $speed);
        $isMoving = $speed > $movingSpeed;
        if ($isMoving && $wasMoving === false) $stops++;
        $wasMoving = $isMoving;
    }
    for ($i = 1; $i < $n; $i++) {
        $a = $msgs[$i - 1]; $b = $msgs[$i];
        $dt = (int)strtotime((string)$b['ts']) - (int)strtotime((string)$a['ts']);
        if ($dt <= 0) continue;
        if ($dt > 3600) continue;                       // data gap, ignore interval
        $step = geo_dist_km((float)$a['lat'], (float)$a['lon'], (float)$b['lat'], (float)$b['lon']);
        $movingSegment = (float)$b['speed'] > $movingSpeed || (float)$a['speed'] > $movingSpeed;
        if ($movingSegment) { $movingS += $dt; if ($step < 2.0) $distance += $step; }
        elseif ($b['ignition'] !== null && (int)$b['ignition'] === 1) { $idleS += $dt; }
    }
    return ['distance_km' => round($distance, 3), 'max_speed' => round($maxSpeed, 1),
        'moving_s' => $movingS, 'idle_s' => $idleS, 'stops' => $stops];
}

/* Duration of one consecutive run, in seconds. */
function run_duration_s(array $msgs): int {
    if (count($msgs) < 2) return 0;
    $last = $msgs[count($msgs) - 1];
    return (int)strtotime((string)$last['ts']) - (int)strtotime((string)$msgs[0]['ts']);
}
/* 1 = engine ran during most of the run, 0 = off, null = unknown. */
function run_ignition(array $msgs): ?int {
    $on = 0; $known = 0;
    foreach ($msgs as $m) {
        if ($m['ignition'] === null) continue;
        $known++;
        if ((int)$m['ignition'] === 1) $on++;
    }
    return $known === 0 ? null : ($on * 2 >= $known ? 1 : 0);
}
function trip_fuel_estimate(array $unit, float $distanceKm, int $idleS): float {
    $rate = (float)$unit['fuel_rate'];                       // L / 100 km
    $driving = $distanceKm / 100.0 * $rate;
    $idlePerHour = $rate * 0.14;                             // rough idling rate
    return round($driving + ($idleS / 3600.0) * $idlePerHour, 2);
}

/* Detects trips, parkings and idle blocks (persisted unless $persist=false). */
function detect_trips(string $unitId, ?string $from = null, ?string $to = null, bool $persist = true): array {
    $unit = unit_by_id($unitId);
    $movingSpeed = (float)setting('moving_speed_kmh', 5);
    $minTripS = max(30, (int)$unit['min_trip_s']);
    $minTripKm = (float)$unit['min_trip_km'];
    $minParkS = max(60, (int)$unit['min_park_s']);
    $parkTimeout = max(60, (int)$unit['parking_timeout_s']);
    $maxGap = 1800;                                   // 30 min without data ends a block

    /* one hour of padding keeps boundary trips complete */
    $padFrom = $from !== null ? date('Y-m-d H:i:s', strtotime($from) - 3600) : null;
    $padTo = $to !== null ? date('Y-m-d H:i:s', strtotime($to) + 3600) : null;
    $msgs = messages_range($unitId, $padFrom, $padTo, true);
    if (!$msgs) return ['trips' => [], 'parkings' => [], 'messages' => 0];

    /* ---- split the stream into consecutive moving / standing runs ------ */
    $runs = []; $cur = null; $prev = null;
    foreach ($msgs as $m) {
        $gap = $prev !== null ? (int)strtotime((string)$m['ts']) - (int)strtotime((string)$prev['ts']) : 0;
        $isMoving = (float)$m['speed'] > $movingSpeed;
        if ($cur === null || $gap > $maxGap || $cur['moving'] !== $isMoving) {
            if ($cur !== null) $runs[] = $cur;
            $cur = ['moving' => $isMoving, 'msgs' => [$m]];
        } else {
            $cur['msgs'][] = $m;
        }
        $prev = $m;
    }
    if ($cur !== null) $runs[] = $cur;

    /* ---- absorb stops shorter than the parking timeout into the trip --- */
    $clean = [];
    foreach ($runs as $r) {
        $lastIdx = count($clean) - 1;
        $last = $lastIdx >= 0 ? $clean[$lastIdx] : null;
        if ($r['moving']) {
            if ($last !== null && $last['moving'] && $last['joined']) {
                $clean[$lastIdx]['msgs'] = array_merge($last['msgs'], $r['msgs']);
                continue;
            }
            $clean[] = ['moving' => true, 'msgs' => $r['msgs'], 'joined' => false];
            continue;
        }
        if ($last !== null && $last['moving'] && run_duration_s($r['msgs']) < $parkTimeout) {
            $clean[$lastIdx]['msgs'] = array_merge($last['msgs'], $r['msgs']);
            $clean[$lastIdx]['joined'] = true;
            continue;
        }
        $clean[] = ['moving' => false, 'msgs' => $r['msgs'], 'joined' => false];
    }

    /* ---- build trip / parking records --------------------------------- */
    return trip_records($unit, $clean, $from, $to, $persist, $minTripS, $minTripKm, $minParkS,
        $movingSpeed, $msgs, $padFrom, $padTo);
}

/* Classifies cleaned runs into trip / parking / idle records and stores them.
   When a window was requested only the records inside that (padded) window are
   replaced, so re-detecting one day never destroys the rest of the history. */
function trip_records(array $unit, array $clean, ?string $from, ?string $to, bool $persist,
                      int $minTripS, float $minTripKm, int $minParkS, float $movingSpeed, array $allMsgs,
                      ?string $delFrom = null, ?string $delTo = null): array {
    $trips = []; $parkings = [];      // records that overlap the *requested* window (returned to the caller)
    $store = [];                      // every record inside the analysed window (written to the database)
    foreach ($clean as $block) {
        $msgsB = $block['msgs'];
        $first = $msgsB[0];
        $last = $msgsB[count($msgsB) - 1];
        $startTs = (string)$first['ts'];
        $endTs = (string)$last['ts'];
        $dur = (int)strtotime($endTs) - (int)strtotime($startTs);
        $me = block_metrics($msgsB, $movingSpeed);
        $common = [
            'unit_id' => $unit['id'], 'account_id' => $unit['account_id'],
            'start_ts' => $startTs, 'end_ts' => $endTs,
            'start_lat' => (float)$first['lat'], 'start_lon' => (float)$first['lon'],
            'end_lat' => (float)$last['lat'], 'end_lon' => (float)$last['lon'],
            'start_address' => address_of($first['lat'], $first['lon']),
            'end_address' => address_of($last['lat'], $last['lon']),
            'duration_s' => $dur, 'moving_s' => $me['moving_s'], 'idle_s' => $me['idle_s'],
            'start_mileage' => $first['mileage'] === null ? null : (float)$first['mileage'],
            'end_mileage' => $last['mileage'] === null ? null : (float)$last['mileage'],
            'driver' => null, 'max_speed' => $me['max_speed'], 'stops' => $me['stops'],
        ];
        /* inside the window the user asked about (the padding is stored, but not reported) */
        $inWindow = ($from === null || $endTs >= $from) && ($to === null || $startTs <= $to);
        if ($block['moving']) {
            if ($dur < $minTripS || $me['distance_km'] < $minTripKm) continue;
            $rec = $common + [
                'kind' => 'trip', 'distance_km' => $me['distance_km'],
                'avg_speed' => $me['moving_s'] > 0 ? round($me['distance_km'] / ($me['moving_s'] / 3600.0), 1) : 0.0,
                'fuel_used' => trip_fuel_estimate($unit, $me['distance_km'], $me['idle_s']),
            ];
            $store[] = $rec;
            if ($inWindow) $trips[] = $rec;
        } else {
            if ($dur < $minParkS) continue;
            $ign = run_ignition($msgsB);
            $rec = $common + [
                'kind' => $ign === 1 ? 'idle' : 'parking',
                'distance_km' => 0.0, 'avg_speed' => 0.0,
                'fuel_used' => trip_fuel_estimate($unit, 0.0, $ign === 1 ? $dur : 0),
                'idle_s' => $ign === 1 ? $dur : 0, 'stops' => 0,
            ];
            $store[] = $rec;
            if ($inWindow) $parkings[] = $rec;
        }
    }
    if ($persist) {
        /* Replace only records overlapping the analysed window, so re-detecting a
           narrow period never destroys the rest of the unit's history. */
        $delSql = "DELETE FROM trips WHERE unit_id = ? AND kind IN ('trip','parking','idle')";
        $delPrms = [$unit['id']];
        if ($delFrom !== null) { $delSql .= ' AND end_ts >= ?'; $delPrms[] = $delFrom; }
        if ($delTo !== null) { $delSql .= ' AND start_ts <= ?'; $delPrms[] = $delTo; }
        db()->prepare($delSql)->execute($delPrms);
        $cols = ['id', 'unit_id', 'account_id', 'kind', 'start_ts', 'end_ts', 'start_lat', 'start_lon', 'end_lat', 'end_lon',
            'start_address', 'end_address', 'distance_km', 'max_speed', 'avg_speed', 'fuel_used', 'duration_s', 'moving_s',
            'idle_s', 'stops', 'start_mileage', 'end_mileage', 'driver', 'notes', 'created_at'];
        $st = db()->prepare('INSERT INTO trips (' . implode(',', $cols) . ') VALUES (' .
            implode(',', array_fill(0, count($cols), '?')) . ')');
        foreach ($store as $t) {
            $st->execute([uid('trp'), $t['unit_id'], $t['account_id'], $t['kind'], $t['start_ts'], $t['end_ts'],
                $t['start_lat'], $t['start_lon'], $t['end_lat'], $t['end_lon'], $t['start_address'], $t['end_address'],
                $t['distance_km'], $t['max_speed'], $t['avg_speed'], $t['fuel_used'], $t['duration_s'], $t['moving_s'],
                $t['idle_s'], $t['stops'], $t['start_mileage'], $t['end_mileage'], $t['driver'], null, now_dt()]);
        }
        foreach ($parkings as $pk) {                       // long-idle alerts (once per block)
            if ($pk['kind'] !== 'idle') continue;
            $st2 = db()->prepare("SELECT COUNT(*) FROM events WHERE unit_id = ? AND type = 'idle' AND ts BETWEEN ? AND ?");
            $st2->execute([$unit['id'], date('Y-m-d H:i:s', strtotime((string)$pk['end_ts']) - 120),
                date('Y-m-d H:i:s', strtotime((string)$pk['end_ts']) + 120)]);
            if ((int)$st2->fetchColumn() > 0) continue;
            notify_idle($unit, (int)$pk['duration_s'], $pk['end_lat'], $pk['end_lon']);
        }
    }
    return ['trips' => $trips, 'parkings' => $parkings, 'messages' => count($allMsgs),
        'stored' => count($store)];
}

/* --------------------------------------------------- fuel fill / drain -- */
/* Detects refuelling and fuel theft from the fuel-level sensor column. */
function fuel_events(string $unitId, ?string $from = null, ?string $to = null, float $threshold = 10.0): array {
    $sql = 'SELECT ts,lat,lon,fuel FROM messages WHERE unit_id = ? AND valid = 1 AND fuel IS NOT NULL';
    $p = [$unitId];
    if ($from !== null) { $sql .= ' AND ts >= ?'; $p[] = $from; }
    if ($to !== null) { $sql .= ' AND ts <= ?'; $p[] = $to; }
    $sql .= ' ORDER BY ts';
    $st = db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    if (count($rows) < 3) return [];

    /* 3-point moving average smooths sensor noise */
    $smooth = [];
    for ($i = 0; $i < count($rows); $i++) {
        $a = $rows[max(0, $i - 1)]; $b = $rows[$i]; $c = $rows[min(count($rows) - 1, $i + 1)];
        $smooth[] = ((float)$a['fuel'] + (float)$b['fuel'] + (float)$c['fuel']) / 3.0;
    }
    $events = []; $i = 1; $n = count($rows);
    while ($i < $n) {
        $delta = $smooth[$i] - $smooth[$i - 1];
        if (abs($delta) < 0.35) { $i++; continue; }
        $rising = $delta > 0;
        $startIdx = $i - 1;
        $total = $delta;
        $j = $i;
        while ($j + 1 < $n) {
            $gap = (int)strtotime((string)$rows[$j + 1]['ts']) - (int)strtotime((string)$rows[$j]['ts']);
            if ($gap > 1800) break;                       // a data gap ends the event
            $d = $smooth[$j + 1] - $smooth[$j];
            if (($rising && $d < -0.35) || (!$rising && $d > 0.35)) break;
            if (($rising && $d > 0) || (!$rising && $d < 0)) $total += $d;
            $j++;
        }
        $volume = round($total, 2);
        if (abs($volume) >= $threshold) {
            $endIdx = $j;
            $events[] = [
                'type' => $rising ? 'fill' : 'drain',
                'start_ts' => (string)$rows[$startIdx]['ts'],
                'end_ts' => (string)$rows[$endIdx]['ts'],
                'volume' => abs($volume),
                'before' => round($smooth[$startIdx], 1),
                'after' => round($smooth[$endIdx], 1),
                'lat' => (float)$rows[$endIdx]['lat'],
                'lon' => (float)$rows[$endIdx]['lon'],
                'address' => address_of($rows[$endIdx]['lat'], $rows[$endIdx]['lon']),
            ];
        }
        $i = $j + 1;
    }
    return $events;
}

/* ------------------------------------------------------ speed episodes -- */
/* Groups consecutive over-limit messages into violation episodes. */
function speed_violations(string $unitId, ?string $from = null, ?string $to = null, ?float $limit = null): array {
    $unit = unit_by_id($unitId);
    $limit = $limit ?? (float)$unit['speed_limit'];
    $sql = 'SELECT ts,lat,lon,speed FROM messages WHERE unit_id = ? AND valid = 1 AND speed > ?';
    $p = [$unitId, $limit];
    if ($from !== null) { $sql .= ' AND ts >= ?'; $p[] = $from; }
    if ($to !== null) { $sql .= ' AND ts <= ?'; $p[] = $to; }
    $sql .= ' ORDER BY ts';
    $st = db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    $episodes = []; $cur = null; $prev = null;
    foreach ($rows as $r) {
        $gap = $prev !== null ? (int)strtotime((string)$r['ts']) - (int)strtotime((string)$prev['ts']) : 999;
        if ($cur === null || $gap > 180) {
            if ($cur !== null) $episodes[] = $cur;
            $cur = ['start_ts' => (string)$r['ts'], 'end_ts' => (string)$r['ts'], 'max_speed' => (float)$r['speed'],
                'sample' => $r, 'limit' => $limit];
        } else {
            $cur['end_ts'] = (string)$r['ts'];
            if ((float)$r['speed'] > $cur['max_speed']) { $cur['max_speed'] = (float)$r['speed']; $cur['sample'] = $r; }
        }
        $prev = $r;
    }
    if ($cur !== null) $episodes[] = $cur;
    foreach ($episodes as &$e) {
        $e['duration_s'] = (int)strtotime($e['end_ts']) - (int)strtotime($e['start_ts']) + 60;
        $e['lat'] = (float)$e['sample']['lat'];
        $e['lon'] = (float)$e['sample']['lon'];
        $e['address'] = address_of($e['sample']['lat'], $e['sample']['lon']);
        unset($e['sample']);
    }
    return $episodes;
}

/* ---------------------------------------------------------- aggregates -- */
/* Scans the raw message stream and produces period totals for one unit. */
function messages_aggregate(string $unitId, ?string $from, ?string $to): array {
    $rows = messages_range($unitId, $from, $to, true);
    $movingSpeed = (float)setting('moving_speed_kmh', 5);
    $out = ['messages' => count($rows), 'distance_km' => 0.0, 'moving_s' => 0, 'idle_s' => 0,
        'engine_hours' => 0.0, 'max_speed' => 0.0, 'avg_speed' => 0.0, 'sat_avg' => 0,
        'first_ts' => null, 'last_ts' => null, 'first_lat' => null, 'first_lon' => null,
        'last_lat' => null, 'last_lon' => null, 'fuel_first' => null, 'fuel_last' => null,
        'speed_sum' => 0.0, 'speeding_s' => 0, 'idle_engine_s' => 0];
    if (!$rows) return $out;
    $n = count($rows);
    $first = $rows[0]; $last = $rows[$n - 1];
    $out['first_ts'] = (string)$first['ts']; $out['last_ts'] = (string)$last['ts'];
    $out['first_lat'] = (float)$first['lat']; $out['first_lon'] = (float)$first['lon'];
    $out['last_lat'] = (float)$last['lat']; $out['last_lon'] = (float)$last['lon'];
    $out['fuel_first'] = $first['fuel'] === null ? null : (float)$first['fuel'];
    $out['fuel_last'] = $last['fuel'] === null ? null : (float)$last['fuel'];
    $satSum = 0;
    for ($i = 0; $i < $n; $i++) {
        $m = $rows[$i];
        $speed = (float)$m['speed'];
        $out['max_speed'] = max($out['max_speed'], $speed);
        $out['speed_sum'] += $speed;
        $satSum += (int)$m['sats'];
        if ($i === 0) continue;
        $a = $rows[$i - 1];
        $dt = (int)strtotime((string)$m['ts']) - (int)strtotime((string)$a['ts']);
        if ($dt <= 0 || $dt > 3600) continue;
        $step = geo_dist_km((float)$a['lat'], (float)$a['lon'], (float)$m['lat'], (float)$m['lon']);
        $movingSegment = $speed > $movingSpeed || (float)$a['speed'] > $movingSpeed;
        if ($movingSegment) { $out['moving_s'] += $dt; if ($step < 2.0) $out['distance_km'] += $step; }
        elseif ($m['ignition'] !== null && (int)$m['ignition'] === 1) { $out['idle_s'] += $dt; $out['idle_engine_s'] += $dt; }
        if ($m['ignition'] !== null && (int)$m['ignition'] === 1) $out['engine_hours'] += $dt / 3600.0;
    }
    $out['avg_speed'] = round($out['speed_sum'] / $n, 1);
    $out['distance_km'] = round($out['distance_km'], 2);
    $out['engine_hours'] = round($out['engine_hours'], 2);
    $out['sat_avg'] = (int)round($satSum / $n);
    unset($out['speed_sum'], $out['idle_engine_s']);
    $out['avg_moving_speed'] = $out['moving_s'] > 0 ? round($out['distance_km'] / ($out['moving_s'] / 3600.0), 1) : 0.0;
    return $out;
}

/* Trips / parkings stored for a set of units in a window. */
function trips_list(array $unitIds, ?string $from = null, ?string $to = null, ?array $kinds = null): array {
    if (!$unitIds) return [];
    $sql = 'SELECT t.*, u.name AS unit_name FROM trips t JOIN units u ON u.id = t.unit_id WHERE t.unit_id IN ('
         . implode(',', array_fill(0, count($unitIds), '?')) . ')';
    $p = array_values($unitIds);
    if ($from !== null) { $sql .= ' AND t.start_ts >= ?'; $p[] = $from; }
    if ($to !== null) { $sql .= ' AND t.end_ts <= ?'; $p[] = $to; }
    if ($kinds) {
        $sql .= ' AND t.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
        foreach ($kinds as $k) $p[] = $k;
    }
    $sql .= ' ORDER BY t.start_ts DESC';
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

/* Daily / hourly mileage + movement series for charts. */
function activity_series(array $unitIds, string $from, string $to, string $group = 'day'): array {
    if (!$unitIds) return [];
    $expr = $group === 'hour' ? "DATE_FORMAT(t.start_ts,'%Y-%m-%d %H:00')" : 'DATE(t.start_ts)';
    $sql = "SELECT $expr AS label, SUM(t.distance_km) AS km, SUM(t.moving_s) AS moving_s,
                   SUM(t.idle_s) AS idle_s, COUNT(*) AS blocks, SUM(t.fuel_used) AS fuel
            FROM trips t WHERE t.unit_id IN (" . implode(',', array_fill(0, count($unitIds), '?')) . ')
            AND t.start_ts >= ? AND t.end_ts <= ? GROUP BY label ORDER BY label';
    $p = array_values($unitIds);
    $p[] = $from; $p[] = $to;
    $st = db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    return array_map(fn($r) => ['label' => (string)$r['label'], 'km' => round((float)$r['km'], 2),
        'moving_s' => (int)$r['moving_s'], 'idle_s' => (int)$r['idle_s'], 'blocks' => (int)$r['blocks'],
        'fuel' => round((float)$r['fuel'], 2)], $rows);
}
