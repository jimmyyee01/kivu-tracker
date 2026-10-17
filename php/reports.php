<?php
/* =====================================================================
   KIVU Tracker — php/reports.php
   Report catalogue + report engine. Every report returns the same
   JSON shape (summary / tables / charts) so one frontend renderer can
   display and export all of them (JSON, CSV, printable HTML).
   ===================================================================== */
declare(strict_types=1);

function report_types(): array {
    return [
        ['id' => 'unit_summary', 'name' => 'Unit summary', 'group' => 'General',
         'hint' => 'Mileage, engine hours, movement, fuel and speed totals per unit.'],
        ['id' => 'trips', 'name' => 'Trips', 'group' => 'Movement',
         'hint' => 'Every detected trip with distance, duration and average speed.'],
        ['id' => 'parkings', 'name' => 'Parkings & idling', 'group' => 'Movement',
         'hint' => 'Parking and idling episodes with duration and location.'],
        ['id' => 'activity', 'name' => 'Activity', 'group' => 'Movement',
         'hint' => 'Mileage and movement time grouped by day or hour.'],
        ['id' => 'geofences', 'name' => 'Geofence visits', 'group' => 'Geofences',
         'hint' => 'Enter/exit events per geofence with visits and time inside.'],
        ['id' => 'events', 'name' => 'Events & notifications', 'group' => 'Safety',
         'hint' => 'Everything the notification rules produced.'],
        ['id' => 'speed_violations', 'name' => 'Speed violations', 'group' => 'Safety',
         'hint' => 'Episodes above the unit speed limit.'],
        ['id' => 'fuel', 'name' => 'Fuel fillings & drainings', 'group' => 'Fuel',
         'hint' => 'Refuelling and theft detection from the fuel level sensor.'],
        ['id' => 'engine_hours', 'name' => 'Engine hours & idling', 'group' => 'Fuel',
         'hint' => 'Engine run time, idle time and fuel burnt while idling.'],
        ['id' => 'mileage', 'name' => 'Mileage by day', 'group' => 'General',
         'hint' => 'Daily mileage with optional cost estimate.'],
        ['id' => 'drivers_by_unit', 'name' => 'Drivers', 'group' => 'General',
         'hint' => 'Activity grouped by assigned driver.'],
        ['id' => 'maintenance', 'name' => 'Maintenance', 'group' => 'General',
         'hint' => 'Service intervals with remaining km / hours / days.'],
    ];
}

/* Units selected by the report request (respects the caller's access mask). */
function report_units(array $p, ?array $user): array {
    $filter = ['account_id' => scope_account($user)];
    $units = $p['units'] ?? null;
    if (is_array($units) && $units) $filter['ids'] = array_map('strval', $units);
    elseif (is_string($units) && $units !== '') $filter['ids'] = array_map('trim', explode(',', $units));
    return units_list($filter, $user);
}
function report_window(array $p): array {
    $to = dt_param($p['to'] ?? null, now_dt());
    $from = dt_param($p['from'] ?? null, date('Y-m-d H:i:s', strtotime((string)$to) - 7 * 86400));
    return [$from, $to];
}
function col(string $key, string $label, string $type = 'text'): array {
    return ['key' => $key, 'label' => $label, 'type' => $type];
}
function rep_row(array $t): array {
    return [
        'id' => $t['id'], 'unit' => $t['unit_name'] ?? null, 'kind' => $t['kind'],
        'start' => iso($t['start_ts']), 'end' => iso($t['end_ts']),
        'start_address' => $t['start_address'], 'end_address' => $t['end_address'],
        'distance_km' => (float)$t['distance_km'], 'max_speed' => (float)$t['max_speed'],
        'avg_speed' => (float)$t['avg_speed'], 'fuel_used' => (float)$t['fuel_used'],
        'duration_s' => (int)$t['duration_s'], 'moving_s' => (int)$t['moving_s'], 'idle_s' => (int)$t['idle_s'],
        'stops' => (int)$t['stops'], 'driver' => $t['driver'],
    ];
}

/* ------------------------------------------------------------- engine -- */
function report_run(string $type, array $p, ?array $user = null): array {
    $units = report_units($p, $user);
    [$from, $to] = report_window($p);
    $unitIds = array_map(fn($u) => (string)$u['id'], $units);
    $out = ['type' => $type, 'from' => iso($from), 'to' => iso($to),
        'units' => array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name']], $units),
        'summary' => [], 'tables' => [], 'charts' => []];
    if (!$units) {
        $out['summary'][] = ['label' => 'Units', 'value' => '0'];
        return $out;
    }
    switch ($type) {
        case 'trips':      return report_trips($out, $units, $unitIds, $from, $to, ['trip']);
        case 'parkings':   return report_trips($out, $units, $unitIds, $from, $to, ['parking', 'idle']);
        case 'activity':   return report_activity($out, $units, $unitIds, $from, $to, $p);
        case 'geofences':  return report_geofences($out, $units, $unitIds, $from, $to);
        case 'events':     return report_events($out, $units, $unitIds, $from, $to, $p);
        case 'speed_violations': return report_speeding($out, $units, $unitIds, $from, $to, $p);
        case 'fuel':       return report_fuel($out, $units, $unitIds, $from, $to, $p);
        case 'engine_hours': return report_engine_hours($out, $units, $unitIds, $from, $to);
        case 'mileage':    return report_mileage($out, $units, $unitIds, $from, $to);
        case 'drivers_by_unit': return report_drivers($out, $units, $unitIds, $from, $to);
        case 'maintenance': return report_maintenance($out, $units, $unitIds);
        case 'unit_summary':
        default:           return report_summary($out, $units, $unitIds, $from, $to);
    }
}

/* Per-unit totals — the "unit summary" report. */
function report_summary(array $o, array $units, array $unitIds, $from, $to): array {
    $rows = []; $tot = ['km' => 0.0, 'eh' => 0.0, 'fuel' => 0.0, 'cost' => 0.0, 'trips' => 0, 'park' => 0, 'msgs' => 0];
    $labels = []; $kmSeries = [];
    foreach ($units as $u) {
        $agg = messages_aggregate($u['id'], $from, $to);
        $blocks = trips_list([$u['id']], $from, $to, ['trip', 'parking', 'idle']);
        $tripCount = 0; $parkCount = 0; $fuelTrips = 0.0;
        foreach ($blocks as $b) {
            if ($b['kind'] === 'trip') { $tripCount++; $fuelTrips += (float)$b['fuel_used']; }
            else $parkCount++;
        }
        $fuel = $fuelTrips > 0 ? $fuelTrips : trip_fuel_estimate($u, (float)$agg['distance_km'], (int)$agg['idle_s']);
        $cost = $fuel * (float)$u['fuel_price'] + (float)$agg['distance_km'] * (float)$u['cost_per_km'];
        $rows[] = ['unit' => $u['name'], 'mileage' => (float)$agg['distance_km'],
            'engine_hours' => (float)$agg['engine_hours'], 'moving' => (int)$agg['moving_s'],
            'idle' => (int)$agg['idle_s'], 'trips' => $tripCount, 'parkings' => $parkCount,
            'max_speed' => (float)$agg['max_speed'], 'avg_speed' => (float)$agg['avg_speed'],
            'fuel' => round($fuel, 2), 'cost' => round($cost, 2), 'messages' => (int)$agg['messages'],
            'first' => $agg['first_ts'], 'last' => $agg['last_ts']];
        $tot['km'] += (float)$agg['distance_km']; $tot['eh'] += (float)$agg['engine_hours'];
        $tot['fuel'] += $fuel; $tot['cost'] += $cost;
        $tot['trips'] += $tripCount; $tot['park'] += $parkCount; $tot['msgs'] += (int)$agg['messages'];
        $labels[] = $u['name']; $kmSeries[] = round((float)$agg['distance_km'], 2);
    }
    $o['summary'] = [
        ['label' => 'Units', 'value' => (string)count($units)],
        ['label' => 'Total mileage', 'value' => round($tot['km'], 2) . ' km'],
        ['label' => 'Engine hours', 'value' => round($tot['eh'], 2) . ' h'],
        ['label' => 'Trips', 'value' => (string)$tot['trips']],
        ['label' => 'Parkings', 'value' => (string)$tot['park']],
        ['label' => 'Fuel consumed', 'value' => round($tot['fuel'], 1) . ' L'],
        ['label' => 'Total cost', 'value' => number_format($tot['cost'], 2)],
        ['label' => 'Messages', 'value' => (string)$tot['msgs']],
    ];
    $o['tables'][] = ['id' => 'units', 'title' => 'Unit summary',
        'totals' => ['unit' => '', 'mileage' => round($tot['km'], 2), 'engine_hours' => round($tot['eh'], 2),
            'fuel' => round($tot['fuel'], 2), 'cost' => round($tot['cost'], 2),
            'trips' => $tot['trips'], 'parkings' => $tot['park']],
        'columns' => [col('unit', 'Unit'), col('mileage', 'Mileage', 'km'), col('engine_hours', 'Engine hours', 'hours'),
            col('moving', 'Moving', 'duration'), col('idle', 'Idle', 'duration'), col('trips', 'Trips', 'number'),
            col('parkings', 'Parkings', 'number'), col('max_speed', 'Max speed', 'speed'),
            col('avg_speed', 'Avg speed', 'speed'), col('fuel', 'Fuel', 'liters'), col('cost', 'Cost', 'money'),
            col('messages', 'Messages', 'number'), col('last', 'Last data', 'datetime')],
        'rows' => $rows];
    $o['charts'][] = ['id' => 'km_by_unit', 'title' => 'Mileage by unit', 'type' => 'bar',
        'labels' => $labels, 'series' => [['name' => 'km', 'data' => $kmSeries]]];
    return $o;
}

/* Trips / parkings / idling table. */
function report_trips(array $o, array $units, array $unitIds, $from, $to, array $kinds): array {
    $list = trips_list($unitIds, $from, $to, $kinds);
    $rows = []; $km = 0.0; $dur = 0; $fuel = 0.0; $maxSpeed = 0.0;
    $daily = [];
    foreach ($list as $t) {
        $rows[] = ['unit' => $t['unit_name'], 'start' => iso($t['start_ts']), 'end' => iso($t['end_ts']),
            'kind' => $t['kind'], 'duration_s' => (int)$t['duration_s'], 'distance_km' => (float)$t['distance_km'],
            'avg_speed' => (float)$t['avg_speed'], 'max_speed' => (float)$t['max_speed'],
            'fuel_used' => (float)$t['fuel_used'], 'stops' => (int)$t['stops'],
            'start_address' => $t['start_address'], 'end_address' => $t['end_address']];
        $km += (float)$t['distance_km']; $dur += (int)$t['duration_s'];
        $fuel += (float)$t['fuel_used']; $maxSpeed = max($maxSpeed, (float)$t['max_speed']);
        $day = substr((string)$t['start_ts'], 0, 10);
        $daily[$day] = ($daily[$day] ?? 0) + (float)$t['distance_km'];
    }
    ksort($daily);
    $isTrip = $kinds === ['trip'];
    $o['summary'] = [
        ['label' => $isTrip ? 'Trips' : 'Blocks', 'value' => (string)count($rows)],
        ['label' => 'Total mileage', 'value' => round($km, 2) . ' km'],
        ['label' => 'Total duration', 'value' => fmt_duration($dur)],
        ['label' => 'Max speed', 'value' => round($maxSpeed, 1) . ' km/h'],
        ['label' => 'Fuel (est.)', 'value' => round($fuel, 1) . ' L'],
    ];
    $o['tables'][] = ['id' => 'blocks', 'title' => $isTrip ? 'Trips' : 'Parkings & idling',
        'columns' => [col('unit', 'Unit'), col('start', 'Start', 'datetime'), col('end', 'End', 'datetime'),
            col('duration_s', 'Duration', 'duration'), col('distance_km', 'Mileage', 'km'),
            col('avg_speed', 'Avg speed', 'speed'), col('max_speed', 'Max speed', 'speed'),
            col('fuel_used', 'Fuel', 'liters'), col('stops', 'Stops', 'number'),
            col('start_address', 'Start location'), col('end_address', 'End location')],
        'totals' => ['distance_km' => round($km, 2), 'duration_s' => $dur, 'fuel_used' => round($fuel, 2)],
        'rows' => $rows];
    $o['charts'][] = ['id' => 'daily_km', 'title' => 'Mileage by day', 'type' => 'bar',
        'labels' => array_keys($daily), 'series' => [['name' => 'km', 'data' => array_values($daily)]]];
    return $o;
}

/* Mileage and movement grouped by day / hour. */
function report_activity(array $o, array $units, array $unitIds, $from, $to, array $p): array {
    $group = ($p['group'] ?? 'day') === 'hour' ? 'hour' : 'day';
    $series = activity_series($unitIds, $from, $to, $group);
    $rows = []; $km = 0.0; $moving = 0; $idle = 0; $fuel = 0.0; $blocks = 0;
    foreach ($series as $s) {
        $rows[] = ['label' => $s['label'], 'km' => $s['km'], 'moving_s' => $s['moving_s'],
            'idle_s' => $s['idle_s'], 'blocks' => $s['blocks'], 'fuel' => $s['fuel']];
        $km += $s['km']; $moving += $s['moving_s']; $idle += $s['idle_s'];
        $fuel += $s['fuel']; $blocks += $s['blocks'];
    }
    $o['summary'] = [
        ['label' => 'Total mileage', 'value' => round($km, 2) . ' km'],
        ['label' => 'Moving time', 'value' => fmt_duration($moving)],
        ['label' => 'Idle time', 'value' => fmt_duration($idle)],
        ['label' => 'Fuel (est.)', 'value' => round($fuel, 1) . ' L'],
        ['label' => 'Blocks', 'value' => (string)$blocks],
    ];
    $o['tables'][] = ['id' => 'activity', 'title' => 'Activity by ' . $group,
        'columns' => [col('label', ucfirst($group)), col('km', 'Mileage', 'km'),
            col('moving_s', 'Moving', 'duration'), col('idle_s', 'Idle', 'duration'),
            col('blocks', 'Blocks', 'number'), col('fuel', 'Fuel', 'liters')],
        'totals' => ['km' => round($km, 2), 'moving_s' => $moving, 'idle_s' => $idle, 'fuel' => round($fuel, 2)],
        'rows' => $rows];
    $o['charts'][] = ['id' => 'activity_km', 'title' => 'Mileage by ' . $group, 'type' => 'bar',
        'labels' => array_column($series, 'label'),
        'series' => [['name' => 'km', 'data' => array_column($series, 'km')]]];
    $o['charts'][] = ['id' => 'activity_hours', 'title' => 'Moving vs idle (hours)', 'type' => 'line',
        'labels' => array_column($series, 'label'),
        'series' => [['name' => 'moving h', 'data' => array_map(fn($s) => round($s['moving_s'] / 3600, 2), $series)],
                     ['name' => 'idle h', 'data' => array_map(fn($s) => round($s['idle_s'] / 3600, 2), $series)]]];
    return $o;
}

/* Daily mileage report. */
function report_mileage(array $o, array $units, array $unitIds, $from, $to): array {
    $series = activity_series($unitIds, $from, $to, 'day');
    $rows = []; $km = 0.0;
    foreach ($series as $s) {
        $rows[] = ['label' => $s['label'], 'km' => $s['km'], 'fuel' => $s['fuel'],
            'moving_s' => $s['moving_s'], 'idle_s' => $s['idle_s']];
        $km += $s['km'];
    }
    $costPerKm = 0.0;
    foreach ($units as $u) $costPerKm += (float)$u['cost_per_km'] / count($units);
    $o['summary'] = [
        ['label' => 'Days', 'value' => (string)count($series)],
        ['label' => 'Total mileage', 'value' => round($km, 2) . ' km'],
        ['label' => 'Avg per day', 'value' => ($series ? round($km / count($series), 2) : 0) . ' km'],
        ['label' => 'Mileage cost (est.)', 'value' => number_format($km * $costPerKm, 2)],
    ];
    $o['tables'][] = ['id' => 'daily', 'title' => 'Mileage by day',
        'columns' => [col('label', 'Day'), col('km', 'Mileage', 'km'), col('moving_s', 'Moving', 'duration'),
            col('idle_s', 'Idle', 'duration'), col('fuel', 'Fuel', 'liters')],
        'totals' => ['km' => round($km, 2)],
        'rows' => $rows];
    $o['charts'][] = ['id' => 'mileage_daily', 'title' => 'Mileage by day', 'type' => 'bar',
        'labels' => array_column($series, 'label'),
        'series' => [['name' => 'km', 'data' => array_column($series, 'km')]]];
    return $o;
}

/* Geofence visits: enter/exit pairs with the time spent inside. */
function report_geofences(array $o, array $units, array $unitIds, $from, $to): array {
    if (!$unitIds) return $o;
    $sql = 'SELECT e.*, u.name AS unit_name FROM geofence_events e JOIN units u ON u.id = e.unit_id
            WHERE e.unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')
            AND e.ts >= ? AND e.ts <= ? ORDER BY e.unit_id, e.geofence_id, e.ts';
    $p = array_values($unitIds); $p[] = $from; $p[] = $to;
    $st = db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();

    $agg = [];       // "unit|fence" => counters
    $open = [];      // "unit|fence" => enter timestamp
    foreach ($rows as $r) {
        $key = $r['unit_id'] . '|' . $r['geofence_id'];
        if (!isset($agg[$key])) {
            $agg[$key] = ['geofence' => $r['geofence_name'], 'unit' => $r['unit_name'], 'enters' => 0,
                'exits' => 0, 'inside_s' => 0, 'last' => $r['ts'], 'lat' => (float)$r['lat'], 'lon' => (float)$r['lon']];
        }
        $agg[$key]['last'] = $r['ts'];
        if ($r['event'] === 'enter') {
            $agg[$key]['enters']++;
            $open[$key] = (int)strtotime((string)$r['ts']);
        } else {
            $agg[$key]['exits']++;
            if (isset($open[$key])) {
                $agg[$key]['inside_s'] += max(0, (int)strtotime((string)$r['ts']) - $open[$key]);
                unset($open[$key]);
            }
        }
    }
    foreach ($open as $key => $ts) {                       // still inside when the window ends
        if (isset($agg[$key])) $agg[$key]['inside_s'] += max(0, (int)strtotime($to) - $ts);
    }
    $tableRows = []; $visits = 0; $inside = 0; $exits = 0; $byFence = []; $insideByFence = [];
    foreach ($agg as $a) {
        $tableRows[] = ['geofence' => $a['geofence'], 'unit' => $a['unit'], 'enters' => $a['enters'],
            'exits' => $a['exits'], 'inside_s' => $a['inside_s'], 'last' => iso($a['last']),
            'address' => address_of($a['lat'], $a['lon'])];
        $visits += $a['enters']; $exits += $a['exits']; $inside += $a['inside_s'];
        $byFence[$a['geofence']] = ($byFence[$a['geofence']] ?? 0) + $a['enters'];
        $insideByFence[$a['geofence']] = ($insideByFence[$a['geofence']] ?? 0) + $a['inside_s'];
    }
    usort($tableRows, fn($a, $b) => $b['enters'] <=> $a['enters']);
    $o['summary'] = [
        ['label' => 'Geofences visited', 'value' => (string)count($byFence)],
        ['label' => 'Entries', 'value' => (string)$visits],
        ['label' => 'Exits', 'value' => (string)$exits],
        ['label' => 'Time inside', 'value' => fmt_duration($inside)],
    ];
    $o['tables'][] = ['id' => 'visits', 'title' => 'Geofence visits',
        'columns' => [col('geofence', 'Geofence'), col('unit', 'Unit'), col('enters', 'Entries', 'number'),
            col('exits', 'Exits', 'number'), col('inside_s', 'Time inside', 'duration'),
            col('last', 'Last event', 'datetime'), col('address', 'Location')],
        'totals' => ['enters' => $visits, 'inside_s' => $inside],
        'rows' => $tableRows];
    $o['charts'][] = ['id' => 'visits_chart', 'title' => 'Entries per geofence', 'type' => 'bar',
        'labels' => array_keys($byFence), 'series' => [['name' => 'entries', 'data' => array_values($byFence)]]];
    $o['charts'][] = ['id' => 'inside_chart', 'title' => 'Time inside (hours)', 'type' => 'bar',
        'labels' => array_keys($insideByFence),
        'series' => [['name' => 'hours', 'data' => array_map(fn($v) => round($v / 3600, 2), array_values($insideByFence))]]];
    return $o;
}

/* Event / notification log. */
function report_events(array $o, array $units, array $unitIds, $from, $to, array $p): array {
    if (!$unitIds) return $o;
    $sql = 'SELECT e.*, u.name AS unit_name FROM events e LEFT JOIN units u ON u.id = e.unit_id
            WHERE e.unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')
            AND e.ts >= ? AND e.ts <= ?';
    $prm = array_values($unitIds); $prm[] = $from; $prm[] = $to;
    $types = $p['event_types'] ?? null;
    if (is_array($types) && $types) {
        $sql .= ' AND e.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        foreach ($types as $t) $prm[] = $t;
    }
    if (!empty($p['severity'])) { $sql .= ' AND e.severity = ?'; $prm[] = $p['severity']; }
    $sql .= ' ORDER BY e.ts DESC LIMIT 5000';
    $st = db()->prepare($sql);
    $st->execute($prm);
    $rows = []; $byType = []; $bySeverity = ['info' => 0, 'warning' => 0, 'critical' => 0];
    foreach ($st->fetchAll() as $e) {
        $rows[] = ['ts' => iso($e['ts']), 'unit' => $e['unit_name'], 'type' => $e['type'],
            'severity' => $e['severity'], 'text' => $e['text'],
            'speed' => $e['speed'] === null ? null : (float)$e['speed'],
            'address' => address_of($e['lat'], $e['lon']), 'acknowledged' => (int)$e['acknowledged']];
        $byType[$e['type']] = ($byType[$e['type']] ?? 0) + 1;
        $bySeverity[$e['severity']] = ($bySeverity[$e['severity']] ?? 0) + 1;
    }
    arsort($byType);
    $o['summary'] = [
        ['label' => 'Events', 'value' => (string)count($rows)],
        ['label' => 'Critical', 'value' => (string)($bySeverity['critical'] ?? 0)],
        ['label' => 'Warnings', 'value' => (string)($bySeverity['warning'] ?? 0)],
        ['label' => 'Info', 'value' => (string)($bySeverity['info'] ?? 0)],
        ['label' => 'Event types', 'value' => (string)count($byType)],
    ];
    $o['tables'][] = ['id' => 'events', 'title' => 'Events',
        'columns' => [col('ts', 'Time', 'datetime'), col('unit', 'Unit'), col('type', 'Type'),
            col('severity', 'Severity'), col('speed', 'Speed', 'speed'), col('text', 'Text'),
            col('address', 'Location'), col('acknowledged', 'Ack', 'number')],
        'rows' => $rows];
    $o['charts'][] = ['id' => 'events_chart', 'title' => 'Events by type', 'type' => 'bar',
        'labels' => array_keys($byType), 'series' => [['name' => 'events', 'data' => array_values($byType)]]];
    return $o;
}

/* Speed violation episodes. */
function report_speeding(array $o, array $units, array $unitIds, $from, $to, array $p): array {
    $limitOverride = isset($p['speed_limit']) && $p['speed_limit'] !== '' ? (float)$p['speed_limit'] : null;
    $rows = []; $total = 0; $worst = 0.0; $byUnit = [];
    foreach ($units as $u) {
        $vios = speed_violations($u['id'], $from, $to, $limitOverride);
        foreach ($vios as $v) {
            $over = round($v['max_speed'] - $v['limit'], 1);
            $rows[] = ['unit' => $u['name'], 'start' => iso($v['start_ts']), 'end' => iso($v['end_ts']),
                'duration_s' => (int)$v['duration_s'], 'limit' => $v['limit'], 'max_speed' => $v['max_speed'],
                'over_by' => $over, 'address' => $v['address']];
            $total++; $worst = max($worst, $v['max_speed']);
            $byUnit[$u['name']] = ($byUnit[$u['name']] ?? 0) + 1;
        }
    }
    usort($rows, fn($a, $b) => $b['max_speed'] <=> $a['max_speed']);
    $o['summary'] = [
        ['label' => 'Violations', 'value' => (string)$total],
        ['label' => 'Units involved', 'value' => (string)count($byUnit)],
        ['label' => 'Highest speed', 'value' => round($worst, 1) . ' km/h'],
        ['label' => 'Default limit', 'value' => $limitOverride === null ? 'per unit' : $limitOverride . ' km/h'],
    ];
    $o['tables'][] = ['id' => 'violations', 'title' => 'Speed violations',
        'columns' => [col('unit', 'Unit'), col('start', 'Start', 'datetime'), col('end', 'End', 'datetime'),
            col('duration_s', 'Duration', 'duration'), col('limit', 'Limit', 'speed'),
            col('max_speed', 'Max speed', 'speed'), col('over_by', 'Over by', 'speed'),
            col('address', 'Location')],
        'totals' => ['max_speed' => round($worst, 1)],
        'rows' => $rows];
    if ($byUnit) {
        arsort($byUnit);
        $o['charts'][] = ['id' => 'violations_chart', 'title' => 'Violations per unit', 'type' => 'bar',
            'labels' => array_keys($byUnit), 'series' => [['name' => 'violations', 'data' => array_values($byUnit)]]];
    }
    return $o;
}

/* Fuel fillings / drainings detected from the level sensor. */
function report_fuel(array $o, array $units, array $unitIds, $from, $to, array $p): array {
    $threshold = isset($p['min_change']) && $p['min_change'] !== '' ? (float)$p['min_change'] : 10.0;
    $rows = []; $filled = 0.0; $drained = 0.0; $fills = 0; $drains = 0; $fillCost = 0.0; $consumed = 0.0;
    $fillByUnit = []; $drainByUnit = [];
    foreach ($units as $u) {
        $events = fuel_events($u['id'], $from, $to, $threshold);
        $agg = messages_aggregate($u['id'], $from, $to);
        $consumed += trip_fuel_estimate($u, (float)$agg['distance_km'], (int)$agg['idle_s']);
        foreach ($events as $e) {
            $rows[] = ['unit' => $u['name'], 'type' => $e['type'] === 'fill' ? 'Filling' : 'Draining',
                'start' => iso($e['start_ts']), 'end' => iso($e['end_ts']), 'volume' => $e['volume'],
                'before' => $e['before'], 'after' => $e['after'], 'address' => $e['address']];
            if ($e['type'] === 'fill') {
                $filled += $e['volume']; $fills++;
                $fillCost += $e['volume'] * (float)$u['fuel_price'];
                $fillByUnit[$u['name']] = ($fillByUnit[$u['name']] ?? 0) + $e['volume'];
            } else {
                $drained += $e['volume']; $drains++;
                $drainByUnit[$u['name']] = ($drainByUnit[$u['name']] ?? 0) + $e['volume'];
            }
        }
    }
    usort($rows, fn($a, $b) => strcmp((string)$b['start'], (string)$a['start']));
    $o['summary'] = [
        ['label' => 'Fillings', 'value' => $fills . ' (' . round($filled, 1) . ' L)'],
        ['label' => 'Drainings', 'value' => $drains . ' (' . round($drained, 1) . ' L)'],
        ['label' => 'Refuelling cost', 'value' => number_format($fillCost, 2)],
        ['label' => 'Fuel consumed (est.)', 'value' => round($consumed, 1) . ' L'],
        ['label' => 'Unexplained loss', 'value' => round($drained, 1) . ' L'],
    ];
    $o['tables'][] = ['id' => 'fuel', 'title' => 'Fuel events (threshold ' . $threshold . ' L)',
        'columns' => [col('unit', 'Unit'), col('type', 'Type'), col('start', 'Start', 'datetime'),
            col('end', 'End', 'datetime'), col('volume', 'Volume', 'liters'), col('before', 'Level before', 'liters'),
            col('after', 'Level after', 'liters'), col('address', 'Location')],
        'totals' => ['volume' => round($filled + $drained, 2)],
        'rows' => $rows];
    $labels = array_values(array_unique(array_merge(array_keys($fillByUnit), array_keys($drainByUnit))));
    $o['charts'][] = ['id' => 'fuel_chart', 'title' => 'Fuel filled / drained (L)', 'type' => 'bar', 'labels' => $labels,
        'series' => [
            ['name' => 'filled', 'data' => array_map(fn($l) => round($fillByUnit[$l] ?? 0, 1), $labels)],
            ['name' => 'drained', 'data' => array_map(fn($l) => round($drainByUnit[$l] ?? 0, 1), $labels)],
        ]];
    return $o;
}

/* Engine run time and idling. */
function report_engine_hours(array $o, array $units, array $unitIds, $from, $to): array {
    $rows = []; $eh = 0.0; $idle = 0; $moving = 0; $km = 0.0; $idleFuel = 0.0;
    foreach ($units as $u) {
        $agg = messages_aggregate($u['id'], $from, $to);
        $fuel = trip_fuel_estimate($u, (float)$agg['distance_km'], (int)$agg['idle_s']);
        $rows[] = ['unit' => $u['name'], 'engine_hours' => (float)$agg['engine_hours'],
            'idle_s' => (int)$agg['idle_s'], 'moving_s' => (int)$agg['moving_s'],
            'mileage' => (float)$agg['distance_km'], 'fuel' => $fuel,
            'idle_share' => (float)$agg['engine_hours'] > 0
                ? round((int)$agg['idle_s'] / ((float)$agg['engine_hours'] * 3600) * 100, 1) : 0.0];
        $eh += (float)$agg['engine_hours']; $idle += (int)$agg['idle_s'];
        $moving += (int)$agg['moving_s']; $km += (float)$agg['distance_km']; $idleFuel += $fuel;
    }
    $o['summary'] = [
        ['label' => 'Engine hours', 'value' => round($eh, 2) . ' h'],
        ['label' => 'Moving time', 'value' => fmt_duration($moving)],
        ['label' => 'Idle time', 'value' => fmt_duration($idle)],
        ['label' => 'Idle share', 'value' => ($eh > 0 ? round($idle / ($eh * 3600) * 100, 1) : 0) . ' %'],
        ['label' => 'Fuel (est.)', 'value' => round($idleFuel, 1) . ' L'],
    ];
    $o['tables'][] = ['id' => 'engine', 'title' => 'Engine hours',
        'columns' => [col('unit', 'Unit'), col('engine_hours', 'Engine hours', 'hours'),
            col('moving_s', 'Moving', 'duration'), col('idle_s', 'Idle', 'duration'),
            col('idle_share', 'Idle share', 'percent'), col('mileage', 'Mileage', 'km'), col('fuel', 'Fuel', 'liters')],
        'totals' => ['engine_hours' => round($eh, 2), 'idle_s' => $idle, 'mileage' => round($km, 2)],
        'rows' => $rows];
    $o['charts'][] = ['id' => 'eh_chart', 'title' => 'Engine hours vs idle (h)', 'type' => 'bar',
        'labels' => array_column($rows, 'unit'),
        'series' => [['name' => 'moving h', 'data' => array_map(fn($r) => round($r['moving_s'] / 3600, 2), $rows)],
                     ['name' => 'idle h', 'data' => array_map(fn($r) => round($r['idle_s'] / 3600, 2), $rows)]]];
    return $o;
}

/* Driver activity (driver assigned to the unit). */
function report_drivers(array $o, array $units, array $unitIds, $from, $to): array {
    $list = trips_list($unitIds, $from, $to, ['trip']);
    $byDriver = [];
    foreach ($list as $t) {
        $driver = trim((string)$t['driver']) !== '' ? (string)$t['driver'] : 'Unassigned';
        if (!isset($byDriver[$driver])) {
            $byDriver[$driver] = ['driver' => $driver, 'units' => [], 'trips' => 0, 'mileage' => 0.0,
                'moving_s' => 0, 'idle_s' => 0, 'fuel' => 0.0, 'max_speed' => 0.0];
        }
        $d = &$byDriver[$driver];
        $d['units'][$t['unit_name']] = true;
        $d['trips']++; $d['mileage'] += (float)$t['distance_km']; $d['moving_s'] += (int)$t['moving_s'];
        $d['idle_s'] += (int)$t['idle_s']; $d['fuel'] += (float)$t['fuel_used'];
        $d['max_speed'] = max($d['max_speed'], (float)$t['max_speed']);
        unset($d);
    }
    $rows = []; $trips = 0; $km = 0.0;
    foreach ($byDriver as $d) {
        $unitList = implode(', ', array_keys($d['units']));
        $rows[] = ['driver' => $d['driver'], 'units' => $unitList, 'unit_count' => count($d['units']),
            'trips' => $d['trips'], 'mileage' => round($d['mileage'], 2), 'moving_s' => $d['moving_s'],
            'idle_s' => $d['idle_s'], 'fuel' => round($d['fuel'], 1), 'max_speed' => $d['max_speed'],
            'avg_speed' => $d['moving_s'] > 0 ? round($d['mileage'] / ($d['moving_s'] / 3600), 1) : 0.0];
        $trips += $d['trips']; $km += $d['mileage'];
    }
    usort($rows, fn($a, $b) => $b['mileage'] <=> $a['mileage']);
    $o['summary'] = [
        ['label' => 'Drivers', 'value' => (string)count($rows)],
        ['label' => 'Trips', 'value' => (string)$trips],
        ['label' => 'Total mileage', 'value' => round($km, 2) . ' km'],
        ['label' => 'Units covered', 'value' => (string)count($unitIds)],
    ];
    $o['tables'][] = ['id' => 'drivers', 'title' => 'Driver activity',
        'columns' => [col('driver', 'Driver'), col('units', 'Units'), col('trips', 'Trips', 'number'),
            col('mileage', 'Mileage', 'km'), col('moving_s', 'Moving', 'duration'),
            col('idle_s', 'Idle', 'duration'), col('avg_speed', 'Avg speed', 'speed'),
            col('max_speed', 'Max speed', 'speed'), col('fuel', 'Fuel', 'liters')],
        'totals' => ['mileage' => round($km, 2), 'trips' => $trips],
        'rows' => $rows];
    if ($rows) {
        $o['charts'][] = ['id' => 'driver_chart', 'title' => 'Mileage by driver', 'type' => 'bar',
            'labels' => array_column($rows, 'driver'), 'series' => [['name' => 'km', 'data' => array_column($rows, 'mileage')]]];
    }
    return $o;
}

/* Maintenance / service intervals. */
function report_maintenance(array $o, array $units, array $unitIds): array {
    if (!$unitIds) return $o;
    $sql = 'SELECT m.*, u.name AS unit_name FROM maintenance m JOIN units u ON u.id = m.unit_id
            WHERE m.unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ') ORDER BY u.name, m.name';
    $st = db()->prepare($sql);
    $st->execute(array_values($unitIds));
    $rows = []; $due = 0;
    foreach ($st->fetchAll() as $item) {
        $unit = unit_by_id((string)$item['unit_id']);
        $status = maintenance_status($item, $unit);
        $rows[] = ['unit' => $item['unit_name'], 'item' => $item['name'],
            'interval_km' => $item['interval_km'] === null ? null : (float)$item['interval_km'],
            'interval_hours' => $item['interval_hours'] === null ? null : (float)$item['interval_hours'],
            'interval_days' => $item['interval_days'] === null ? null : (int)$item['interval_days'],
            'last_service' => $item['last_service_date'], 'odometer' => (float)$unit['odometer_km'],
            'engine_hours' => (float)$unit['engine_hours'], 'remaining' => $status['text'],
            'status' => $status['due'] ? 'DUE' : 'OK', 'cost' => $item['cost'] === null ? null : (float)$item['cost']];
        if ($status['due']) $due++;
    }
    $o['summary'] = [
        ['label' => 'Service items', 'value' => (string)count($rows)],
        ['label' => 'Due now', 'value' => (string)$due],
        ['label' => 'Units covered', 'value' => (string)count($unitIds)],
    ];
    $o['tables'][] = ['id' => 'maintenance', 'title' => 'Service intervals',
        'columns' => [col('unit', 'Unit'), col('item', 'Service'), col('interval_km', 'Interval', 'km'),
            col('interval_hours', 'Interval', 'hours'), col('interval_days', 'Interval', 'number'),
            col('last_service', 'Last service'), col('odometer', 'Odometer', 'km'),
            col('engine_hours', 'Engine hours', 'hours'), col('remaining', 'Remaining'), col('status', 'Status'),
            col('cost', 'Cost', 'money')],
        'rows' => $rows];
    return $o;
}
