<?php
/* =====================================================================
   KIVU Tracker — php/routes_units.php
   Units (trackers/vehicles), groups, sensors, drivers, service
   intervals, track/message queries, trips, events and device commands.
   ===================================================================== */
declare(strict_types=1);

function routes_units(): array {
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    return [
        /* literal paths must come before the /units/<id> pattern */
        ['GET', '#^/units/live$#', 'h_units_live', 'read'],
        ['GET', '#^/units$#', 'h_units_list', 'read'],
        ['POST', '#^/units$#', 'h_units_save', 'write'],
        ['GET', '#^/units/' . $id . '$#', 'h_unit_get', 'read'],
        ['PUT', '#^/units/' . $id . '$#', 'h_units_save', 'write'],
        ['DELETE', '#^/units/' . $id . '$#', 'h_unit_delete', 'write'],
        ['POST', '#^/units/' . $id . '/command$#', 'h_unit_command', 'write'],
        ['GET', '#^/units/' . $id . '/messages$#', 'h_unit_messages', 'read'],
        ['GET', '#^/units/' . $id . '/trips$#', 'h_unit_trips', 'read'],
        ['POST', '#^/units/' . $id . '/trips/detect$#', 'h_unit_trips_detect', 'write'],
        ['GET', '#^/units/' . $id . '/fuel$#', 'h_unit_fuel', 'read'],
        ['GET', '#^/units/' . $id . '/speeding$#', 'h_unit_speeding', 'read'],
        ['GET', '#^/units/' . $id . '/sensors$#', 'h_unit_sensors', 'read'],
        ['POST', '#^/units/' . $id . '/sensors$#', 'h_sensor_save', 'write'],
        ['PUT', '#^/sensors/' . $id . '$#', 'h_sensor_save', 'write'],
        ['DELETE', '#^/sensors/' . $id . '$#', 'h_sensor_delete', 'write'],
        ['GET', '#^/groups$#', 'h_groups_list', 'read'],
        ['POST', '#^/groups$#', 'h_group_save', 'write'],
        ['PUT', '#^/groups/' . $id . '$#', 'h_group_save', 'write'],
        ['DELETE', '#^/groups/' . $id . '$#', 'h_group_delete', 'write'],
        ['GET', '#^/drivers$#', 'h_drivers_list', 'read'],
        ['POST', '#^/drivers$#', 'h_driver_save', 'write'],
        ['PUT', '#^/drivers/' . $id . '$#', 'h_driver_save', 'write'],
        ['DELETE', '#^/drivers/' . $id . '$#', 'h_driver_delete', 'write'],
        ['GET', '#^/maintenance$#', 'h_maintenance_list', 'read'],
        ['POST', '#^/maintenance$#', 'h_maintenance_save', 'write'],
        ['PUT', '#^/maintenance/' . $id . '$#', 'h_maintenance_save', 'write'],
        ['DELETE', '#^/maintenance/' . $id . '$#', 'h_maintenance_delete', 'write'],
        ['GET', '#^/events$#', 'h_events_list', 'read'],
        ['POST', '#^/events/ack$#', 'h_events_ack', 'write'],
        ['GET', '#^/commands$#', 'h_commands_list', 'read'],
        ['DELETE', '#^/commands/' . $id . '$#', 'h_command_delete', 'write'],
        ['GET', '#^/devices/sessions$#', 'h_device_sessions', 'read'],
    ];
}

/* Writable unit columns + their sanity limits. */
function unit_field_map(): array {
    return ['name' => 'str', 'unique_id' => 'str', 'hw_type' => 'str', 'device_password' => 'str',
        'phone' => 'str', 'model' => 'str', 'plate' => 'str', 'vin' => 'str', 'trailer' => 'str',
        'group_id' => 'str', 'tags' => 'str', 'driver_id' => 'str', 'asset_type' => 'str',
        'fuel_rate' => 'num', 'fuel_price' => 'num', 'cost_per_km' => 'num', 'odometer_km' => 'num',
        'engine_hours' => 'num', 'speed_limit' => 'int', 'parking_timeout_s' => 'int',
        'min_trip_s' => 'int', 'min_trip_km' => 'num', 'min_park_s' => 'int', 'max_jump_km' => 'num',
        'enabled' => 'bool'];
}

/* Full unit projection: the live shape plus the configuration fields a form needs.
   Shared by GET /api/units/{id} and the save response so both always agree. */
function unit_full_shape(array $u): array {
    return unit_live_shape($u) + [
        'vin' => $u['vin'], 'trailer' => $u['trailer'], 'hw_type' => $u['hw_type'],
        'device_password' => $u['device_password'], 'fuel_price' => (float)$u['fuel_price'],
        'cost_per_km' => (float)$u['cost_per_km'], 'speed_limit' => (int)$u['speed_limit'],
        'parking_timeout_s' => (int)$u['parking_timeout_s'], 'min_trip_s' => (int)$u['min_trip_s'],
        'min_trip_km' => (float)$u['min_trip_km'], 'min_park_s' => (int)$u['min_park_s'],
        'max_jump_km' => (float)$u['max_jump_km'], 'created_at' => iso($u['created_at']),
        'last_ip' => $u['last_ip'], 'online' => (int)$u['online'],
        'custom_fields' => jdec($u['custom_fields_json'] ?? null, [])];
}

function h_units_list($user, $p): array {
    $accountId = scope_account($user);
    $f = ['account_id' => $accountId, 'q' => (string)qp('q', ''), 'group_id' => (string)qp('group_id', ''),
        'status' => (string)qp('status', ''), 'tag' => (string)qp('tag', '')];
    if (qp('enabled') !== null && qp('enabled') !== '') $f['enabled'] = (int)qp('enabled');
    if (qp('ids')) $f['ids'] = array_map('trim', explode(',', (string)qp('ids')));
    $units = units_list($f, $user);
    return ['units' => array_map('unit_live_shape', $units), 'count' => count($units)];
}

function h_unit_get($user, $p): array {
    $u = unit_by_id((string)$p['id']);
    need(unit_allowed($user, (string)$u['id']), 'You do not have access to this unit', 403);
    if (!is_super($user) && scope_account($user) !== $u['account_id']) throw new ApiError('Unit not found', 404);
    $st = db()->prepare('SELECT * FROM sensors WHERE unit_id = ? ORDER BY name');
    $st->execute([$u['id']]);
    $sensors = $st->fetchAll();
    $st = db()->prepare('SELECT * FROM maintenance WHERE unit_id = ? ORDER BY name');
    $st->execute([$u['id']]);
    $maint = array_map(fn($m) => $m + ['status' => maintenance_status($m, $u)], $st->fetchAll());
    $st = db()->prepare('SELECT * FROM commands WHERE unit_id = ? ORDER BY created_at DESC LIMIT 20');
    $st->execute([$u['id']]);
    return ['unit' => unit_full_shape($u),
        'sensors' => $sensors, 'maintenance' => $maint,
        'commands' => array_map(fn($c) => ['id' => $c['id'], 'type' => $c['type'], 'state' => $c['state'],
            'created_at' => iso($c['created_at']), 'sent_at' => iso($c['sent_at']), 'acked_at' => iso($c['acked_at']),
            'result' => $c['result'], 'payload' => jdec($c['payload_json'] ?? null, [])], $st->fetchAll())];
}

function h_units_save($user, $p): array {
    $accountId = scope_account($user);
    $id = (string)($p['id'] ?? '');
    $map = unit_field_map();
    $fields = [];
    foreach ($map as $key => $type) {
        $raw = bval($key, null);
        if ($raw === null) continue;
        if ($type === 'num') $fields[$key] = (float)$raw;
        elseif ($type === 'int') $fields[$key] = (int)$raw;
        elseif ($type === 'bool') $fields[$key] = (int)(bool)(is_string($raw) ? in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true) : $raw);
        else { $v = trim((string)$raw); $fields[$key] = $v === '' ? null : $v; }
    }
    if (bval('custom_fields', null) !== null) $fields['custom_fields_json'] = jenc(bval('custom_fields', []));
    /* asset type (lorry, motorcycle, bicycle …) — validate what was sent */
    $assetTypeGiven = array_key_exists('asset_type', $fields);
    if ($assetTypeGiven && (string)$fields['asset_type'] !== '') {
        need(asset_type_valid((string)$fields['asset_type']) !== '',
            'Unknown asset type: ' . $fields['asset_type']
            . ' (choose one of: ' . implode(', ', array_keys(asset_types())) . ')', 400);
    }

    if ($id === '') {
        need(!empty($fields['name']), 'Unit name is required');
        need(!empty($fields['unique_id']), 'Device unique_id (IMEI / IPS login) is required');
        $account = is_super($user) ? (string)(bstr('account_id', (string)$accountId) ?: '') : (string)$accountId;
        need($account !== '', 'Choose the account this unit belongs to (account_id)');
        /* the plan's unit allowance is enforced here (402 → upgrade prompt) */
        billing_check_unit_limit($account);
        /* new units inherit the fleet's type captured at registration */
        if (!$assetTypeGiven || (string)$fields['asset_type'] === '') {
            $accRow = account_by_id($account);
            $fields['asset_type'] = asset_type_valid((string)($accRow['default_asset_type'] ?? '')) ?: null;
        }
        /* columns that are NOT NULL in the schema must never receive null when a
           form leaves the field empty */
        if (empty($fields['device_password'])) $fields['device_password'] = 'track';
        if (empty($fields['hw_type'])) $fields['hw_type'] = 'ips';
        $fields['account_id'] = $account;
        $fields['created_at'] = now_dt();
        $st = db()->prepare('SELECT id FROM units WHERE unique_id = ?');
        $st->execute([$fields['unique_id']]);
        need(!$st->fetch(), 'A unit with that unique_id already exists');
        $id = uid('unt');
        $fields['id'] = $id;
        $cols = array_keys($fields);
        db()->prepare('INSERT INTO units (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($fields));
        /* default sensors every new tracker gets */
        $defaults = [['Fuel level', 'fuel_level', 'fuel', 'L'], ['Ignition', 'ignition', 'ign', ''],
            ['Power voltage', 'voltage', 'pwr', 'V'], ['Temperature', 'temperature', 'temp', 'C']];
        $ins = db()->prepare('INSERT INTO sensors (id,unit_id,account_id,name,type,param,units,coefficient,offset_val,enabled,created_at)
                              VALUES (?,?,?,?,?,?,?,1,0,1,?)');
        foreach ($defaults as $sd) $ins->execute([uid('sns'), $id, $account, $sd[0], $sd[1], $sd[2], $sd[3], now_dt()]);
        audit('unit_created', 'unit', $id, ['name' => $fields['name'], 'unique_id' => $fields['unique_id']]);
    } else {
        $unit = unit_by_id($id);
        need(unit_allowed($user, $id), 'You do not have access to this unit', 403);
        if (!is_super($user)) need($unit['account_id'] === $accountId, 'Unit not found', 404);
        if (isset($fields['unique_id'])) {
            $st = db()->prepare('SELECT id FROM units WHERE unique_id = ? AND id <> ?');
            $st->execute([$fields['unique_id'], $id]);
            need(!$st->fetch(), 'Another unit already uses that unique_id');
        }
        if (is_super($user) && bstr('account_id', '') !== '') $fields['account_id'] = bstr('account_id');
        /* never null out the NOT NULL device columns when a form field is empty */
        if (array_key_exists('device_password', $fields) && empty($fields['device_password'])) unset($fields['device_password']);
        if (array_key_exists('hw_type', $fields) && empty($fields['hw_type'])) unset($fields['hw_type']);
        need($fields, 'Nothing to update');
        $set = [];
        $vals = [];
        foreach ($fields as $k => $v) { $set[] = "`$k` = ?"; $vals[] = $v; }
        $vals[] = $id;
        db()->prepare('UPDATE units SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        audit('unit_updated', 'unit', $id, array_keys($fields));
    }
    return ['unit' => unit_full_shape(unit_by_id($id)), 'message' => 'Unit saved'];
}

function h_unit_delete($user, $p): array {
    $id = (string)$p['id'];
    $unit = unit_by_id($id);
    need(unit_allowed($user, $id), 'You do not have access to this unit', 403);
    if (!is_super($user)) need($unit['account_id'] === scope_account($user), 'Unit not found', 404);
    foreach (['messages', 'trips', 'sensors', 'maintenance', 'commands', 'geofence_events', 'events'] as $t) {
        db()->prepare("DELETE FROM $t WHERE unit_id = ?")->execute([$id]);
    }
    db()->prepare('DELETE FROM device_sessions WHERE unit_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM units WHERE id = ?')->execute([$id]);
    audit('unit_deleted', 'unit', $id, ['name' => $unit['name']]);
    return ['message' => 'Unit deleted'];
}

function h_unit_command($user, $p): array {
    $id = (string)$p['id'];
    $unit = unit_by_id($id);
    need(unit_allowed($user, $id), 'You do not have access to this unit', 403);
    need($unit['account_id'] === scope_account($user) || is_super($user), 'Unit not found', 404);
    $type = bstr('type', 'custom_message');
    $allowed = ['custom_message', 'engine_block', 'engine_unblock', 'reboot', 'query_position', 'set_interval'];
    need(in_array($type, $allowed, true), 'Unsupported command type: ' . $type);
    $payload = ['text' => bstr('text', '')];
    if ($type === 'set_interval') $payload['seconds'] = bint('seconds', 60);
    $cmdId = uid('cmd');
    db()->prepare('INSERT INTO commands (id,account_id,unit_id,type,payload_json,state,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$cmdId, $unit['account_id'], $id, $type, jenc($payload), 'pending', $user['id'], now_dt()]);
    audit('command_queued', 'unit', $id, ['type' => $type, 'payload' => $payload]);
    return ['command_id' => $cmdId, 'state' => 'pending',
        'message' => (int)$unit['online'] === 1
            ? 'Command queued — the IPS server delivers it on its next poll (a few seconds).'
            : 'Command queued. The unit is offline; it will be delivered when the device connects.'];
}

/* Track / message history for one unit. */
function h_unit_messages($user, $p): array {
    $id = (string)$p['id'];
    $unit = unit_by_id($id);
    need(unit_allowed($user, $id), 'You do not have access to this unit', 403);
    need($unit['account_id'] === scope_account($user) || is_super($user), 'Unit not found', 404);
    $limit = min(20000, max(1, (int)qp('limit', 5000)));
    $sql = 'SELECT ts,lat,lon,speed,course,alt,sats,hdop,ignition,fuel,voltage,params_json,valid,source
            FROM messages WHERE unit_id = ?';
    $prm = [$id];
    if (qp('from')) { $sql .= ' AND ts >= ?'; $prm[] = dt_param(qp('from')); }
    if (qp('to')) { $sql .= ' AND ts <= ?'; $prm[] = dt_param(qp('to')); }
    if (qp('valid') !== null && qp('valid') !== '') { $sql .= ' AND valid = ?'; $prm[] = (int)qp('valid'); }
    $sql .= ' ORDER BY ts ASC LIMIT ' . $limit;
    $st = db()->prepare($sql);
    $st->execute($prm);
    $points = [];
    foreach ($st->fetchAll() as $m) {
        $points[] = ['ts' => iso($m['ts']), 'lat' => (float)$m['lat'], 'lon' => (float)$m['lon'],
            'speed' => (float)$m['speed'], 'course' => (float)$m['course'], 'alt' => (float)$m['alt'],
            'sats' => (int)$m['sats'], 'hdop' => $m['hdop'] === null ? null : (float)$m['hdop'],
            'ignition' => $m['ignition'] === null ? null : (int)$m['ignition'],
            'fuel' => $m['fuel'] === null ? null : (float)$m['fuel'],
            'voltage' => $m['voltage'] === null ? null : (float)$m['voltage'],
            'valid' => (int)$m['valid'], 'source' => $m['source'],
            'params' => jdec($m['params_json'] ?? null, [])];
    }
    $first = $points[0] ?? null;
    $last = $points ? $points[count($points) - 1] : null;
    return ['unit' => unit_live_shape($unit), 'points' => $points, 'count' => count($points),
        'start_address' => $first ? address_of($first['lat'], $first['lon']) : null,
        'end_address' => $last ? address_of($last['lat'], $last['lon']) : null];
}

/* Stored trips/parkings, optionally re-detected on the fly. */
function h_unit_trips($user, $p): array {
    $id = (string)$p['id'];
    $unit = unit_by_id($id);
    need(unit_allowed($user, $id), 'You do not have access to this unit', 403);
    need($unit['account_id'] === scope_account($user) || is_super($user), 'Unit not found', 404);
    $from = dt_param(qp('from'), date('Y-m-d H:i:s', time() - 86400));
    $to = dt_param(qp('to'), now_dt());
    if (qp('detect') !== null && qp('detect') !== '0') detect_trips($id, $from, $to, true);
    $rows = trips_list([$id], $from, $to);
    return ['unit' => unit_live_shape($unit), 'trips' => array_map(fn($t) => [
        'id' => $t['id'], 'kind' => $t['kind'], 'start' => iso($t['start_ts']), 'end' => iso($t['end_ts']),
        'start_lat' => (float)$t['start_lat'], 'start_lon' => (float)$t['start_lon'],
        'end_lat' => (float)$t['end_lat'], 'end_lon' => (float)$t['end_lon'],
        'start_address' => $t['start_address'], 'end_address' => $t['end_address'],
        'distance_km' => (float)$t['distance_km'], 'max_speed' => (float)$t['max_speed'],
        'avg_speed' => (float)$t['avg_speed'], 'fuel_used' => (float)$t['fuel_used'],
        'duration_s' => (int)$t['duration_s'], 'moving_s' => (int)$t['moving_s'], 'idle_s' => (int)$t['idle_s'],
        'stops' => (int)$t['stops'], 'duration_text' => fmt_duration($t['duration_s']),
    ], $rows), 'count' => count($rows)];
}

function h_unit_trips_detect($user, $p): array {
    $id = (string)$p['id'];
    $unit = unit_by_id($id);
    need(unit_allowed($user, $id), 'You do not have access to this unit', 403);
    need($unit['account_id'] === scope_account($user) || is_super($user), 'Unit not found', 404);
    $from = dt_param(bval('from', null), date('Y-m-d H:i:s', time() - 86400));
    $to = dt_param(bval('to', null), now_dt());
    $r = detect_trips($id, $from, $to, true);
    audit('trips_detected', 'unit', $id, ['from' => $from, 'to' => $to, 'trips' => count($r['trips'])]);
    return ['trips' => count($r['trips']), 'parkings' => count($r['parkings']), 'messages' => $r['messages']];
}

function h_unit_fuel($user, $p): array {
    $id = (string)$p['id'];
    $unit = unit_by_id($id);
    need(unit_allowed($user, $id), 'You do not have access to this unit', 403);
    need($unit['account_id'] === scope_account($user) || is_super($user), 'Unit not found', 404);
    $from = dt_param(qp('from'), date('Y-m-d H:i:s', time() - 7 * 86400));
    $to = dt_param(qp('to'), now_dt());
    $events = fuel_events($id, $from, $to, (float)qp('min_change', 10));
    return ['events' => array_map(fn($e) => $e + ['start' => iso($e['start_ts']), 'end' => iso($e['end_ts'])], $events),
        'count' => count($events)];
}

function h_unit_speeding($user, $p): array {
    $id = (string)$p['id'];
    $unit = unit_by_id($id);
    need(unit_allowed($user, $id), 'You do not have access to this unit', 403);
    need($unit['account_id'] === scope_account($user) || is_super($user), 'Unit not found', 404);
    $from = dt_param(qp('from'), date('Y-m-d H:i:s', time() - 7 * 86400));
    $to = dt_param(qp('to'), now_dt());
    $limit = (qp('limit') !== null && qp('limit') !== '') ? (float)qp('limit') : null;
    $eps = speed_violations($id, $from, $to, $limit);
    return ['violations' => array_map(fn($e) => $e + ['start' => iso($e['start_ts']), 'end' => iso($e['end_ts']),
        'duration_text' => fmt_duration($e['duration_s'])], $eps), 'count' => count($eps)];
}

function h_unit_sensors($user, $p): array {
    $id = (string)$p['id'];
    $unit = unit_by_id($id);
    need(unit_allowed($user, $id), 'You do not have access to this unit', 403);
    $st = db()->prepare('SELECT * FROM sensors WHERE unit_id = ? ORDER BY name');
    $st->execute([$id]);
    return ['sensors' => $st->fetchAll()];
}

function h_sensor_save($user, $p): array {
    $id = (string)($p['id'] ?? '');
    $unitId = (string)bval('unit_id', '');
    if ($id !== '') {
        $st = db()->prepare('SELECT * FROM sensors WHERE id = ?');
        $st->execute([$id]);
        $s = $st->fetch();
        need($s, 'Sensor not found', 404);
        $unitId = (string)$s['unit_id'];
    }
    $unit = unit_by_id($unitId);
    need(unit_allowed($user, $unitId), 'You do not have access to this unit', 403);
    need($unit['account_id'] === scope_account($user) || is_super($user), 'Unit not found', 404);
    $minV = bval('min_val', null); $maxV = bval('max_val', null);
    $fields = ['name' => bstr('name', 'Sensor'), 'type' => bstr('type', 'custom'), 'param' => bstr('param', ''),
        'units' => bstr('units', '') ?: null, 'coefficient' => bfloat('coefficient', 1),
        'offset_val' => bfloat('offset_val', 0),
        'min_val' => ($minV === null || $minV === '') ? null : (float)$minV,
        'max_val' => ($maxV === null || $maxV === '') ? null : (float)$maxV,
        'color' => bstr('color', '#2f9cf4'), 'enabled' => bint('enabled', 1) ? 1 : 0];
    need($fields['param'] !== '', 'The device parameter name is required (e.g. fuel, ign, pwr, temp)');
    if ($id === '') {
        $id = uid('sns');
        db()->prepare('INSERT INTO sensors (id,unit_id,account_id,name,type,param,units,coefficient,offset_val,min_val,max_val,color,enabled,created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $unitId, $unit['account_id'], $fields['name'], $fields['type'], $fields['param'],
                $fields['units'], $fields['coefficient'], $fields['offset_val'], $fields['min_val'],
                $fields['max_val'], $fields['color'], $fields['enabled'], now_dt()]);
    } else {
        db()->prepare('UPDATE sensors SET name=?, type=?, param=?, units=?, coefficient=?, offset_val=?, min_val=?, max_val=?, color=?, enabled=? WHERE id=?')
            ->execute([$fields['name'], $fields['type'], $fields['param'], $fields['units'], $fields['coefficient'],
                $fields['offset_val'], $fields['min_val'], $fields['max_val'], $fields['color'], $fields['enabled'], $id]);
    }
    audit('sensor_saved', 'sensor', $id, $fields);
    return ['sensor_id' => $id, 'message' => 'Sensor saved'];
}

function h_sensor_delete($user, $p): array {
    $id = (string)$p['id'];
    $st = db()->prepare('SELECT * FROM sensors WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    need($s, 'Sensor not found', 404);
    $unit = unit_by_id((string)$s['unit_id']);
    need(unit_allowed($user, (string)$unit['id']), 'You do not have access to this unit', 403);
    db()->prepare('DELETE FROM sensors WHERE id = ?')->execute([$id]);
    audit('sensor_deleted', 'sensor', $id, ['name' => $s['name']]);
    return ['message' => 'Sensor deleted'];
}

/* ---------------------------------------------------------------- groups */
function h_groups_list($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT g.*, (SELECT COUNT(*) FROM units u WHERE u.group_id = g.id) AS units FROM unit_groups g WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND g.account_id = ?'; $prm[] = $accountId; }
    $sql .= ' ORDER BY g.name';
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['groups' => array_map(fn($g) => ['id' => $g['id'], 'name' => $g['name'], 'color' => $g['color'],
        'parent_id' => $g['parent_id'], 'units' => (int)$g['units']], $st->fetchAll())];
}
function h_group_save($user, $p): array {
    $accountId = scope_account($user);
    $id = (string)($p['id'] ?? '');
    need((string)$accountId !== '' || is_super($user), 'account_id is required');
    $account = is_super($user) ? (bstr('account_id', (string)$accountId) ?: null) : $accountId;
    need($account !== '', 'account_id is required');
    $name = bstr('name', '');
    need($name !== '', 'Group name is required');
    if ($id === '') {
        $id = uid('grp');
        db()->prepare('INSERT INTO unit_groups (id,account_id,parent_id,name,color,created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $account, bstr('parent_id', '') ?: null, $name, bstr('color', '#2f9cf4'), now_dt()]);
        audit('group_created', 'group', $id, ['name' => $name]);
    } else {
        $st = db()->prepare('SELECT * FROM unit_groups WHERE id = ?');
        $st->execute([$id]);
        $g = $st->fetch();
        need($g, 'Group not found', 404);
        need(is_super($user) || $g['account_id'] === $accountId, 'Group not found', 404);
        db()->prepare('UPDATE unit_groups SET name = ?, color = ?, parent_id = ? WHERE id = ?')
            ->execute([$name, bstr('color', (string)$g['color']), bstr('parent_id', '') ?: null, $id]);
        audit('group_updated', 'group', $id, ['name' => $name]);
    }
    return ['group_id' => $id, 'message' => 'Group saved'];
}
function h_group_delete($user, $p): array {
    $id = (string)$p['id'];
    $st = db()->prepare('SELECT * FROM unit_groups WHERE id = ?');
    $st->execute([$id]);
    $g = $st->fetch();
    need($g, 'Group not found', 404);
    need(is_super($user) || $g['account_id'] === scope_account($user), 'Group not found', 404);
    db()->prepare('UPDATE units SET group_id = NULL WHERE group_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM unit_groups WHERE id = ?')->execute([$id]);
    audit('group_deleted', 'group', $id, ['name' => $g['name']]);
    return ['message' => 'Group deleted'];
}

/* --------------------------------------------------------------- drivers */
function h_drivers_list($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT d.*, (SELECT COUNT(*) FROM units u WHERE u.driver_id = d.id) AS units FROM drivers d WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND d.account_id = ?'; $prm[] = $accountId; }
    $sql .= ' ORDER BY d.name';
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['drivers' => array_map(fn($d) => ['id' => $d['id'], 'name' => $d['name'], 'phone' => $d['phone'],
        'licence' => $d['licence'], 'id_number' => $d['id_number'], 'address' => $d['address'],
        'notes' => $d['notes'], 'units' => (int)$d['units'], 'created_at' => iso($d['created_at'])], $st->fetchAll())];
}
function h_driver_save($user, $p): array {
    $accountId = scope_account($user);
    $id = (string)($p['id'] ?? '');
    $account = is_super($user) ? (bstr('account_id', (string)$accountId) ?: null) : $accountId;
    need($account !== '', 'account_id is required');
    $name = bstr('name', '');
    need($name !== '', 'Driver name is required');
    $f = [$name, bstr('phone', '') ?: null, bstr('licence', '') ?: null, bstr('id_number', '') ?: null,
        bstr('address', '') ?: null, bstr('notes', '') ?: null];
    if ($id === '') {
        $id = uid('drv');
        db()->prepare('INSERT INTO drivers (id,account_id,name,phone,licence,id_number,address,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge([$id, $account], $f, [now_dt()]));
        audit('driver_created', 'driver', $id, ['name' => $name]);
    } else {
        $st = db()->prepare('SELECT * FROM drivers WHERE id = ?');
        $st->execute([$id]);
        $d = $st->fetch();
        need($d, 'Driver not found', 404);
        need(is_super($user) || $d['account_id'] === $accountId, 'Driver not found', 404);
        db()->prepare('UPDATE drivers SET name=?, phone=?, licence=?, id_number=?, address=?, notes=? WHERE id=?')
            ->execute(array_merge($f, [$id]));
        audit('driver_updated', 'driver', $id, ['name' => $name]);
    }
    return ['driver_id' => $id, 'message' => 'Driver saved'];
}
function h_driver_delete($user, $p): array {
    $id = (string)$p['id'];
    $st = db()->prepare('SELECT * FROM drivers WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    need($d, 'Driver not found', 404);
    need(is_super($user) || $d['account_id'] === scope_account($user), 'Driver not found', 404);
    db()->prepare('UPDATE units SET driver_id = NULL WHERE driver_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM drivers WHERE id = ?')->execute([$id]);
    audit('driver_deleted', 'driver', $id, ['name' => $d['name']]);
    return ['message' => 'Driver deleted'];
}

/* ----------------------------------------------------- service intervals */
function h_maintenance_list($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT m.*, u.name AS unit_name, u.odometer_km, u.engine_hours FROM maintenance m
            JOIN units u ON u.id = m.unit_id WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND m.account_id = ?'; $prm[] = $accountId; }
    if (qp('unit_id')) { $sql .= ' AND m.unit_id = ?'; $prm[] = qp('unit_id'); }
    $sql .= ' ORDER BY u.name, m.name';
    $st = db()->prepare($sql);
    $st->execute($prm);
    $rows = [];
    foreach ($st->fetchAll() as $m) {
        $status = maintenance_status($m, ['odometer_km' => $m['odometer_km'], 'engine_hours' => $m['engine_hours']]);
        $rows[] = ['id' => $m['id'], 'unit_id' => $m['unit_id'], 'unit' => $m['unit_name'], 'name' => $m['name'],
            'interval_km' => $m['interval_km'] === null ? null : (float)$m['interval_km'],
            'interval_hours' => $m['interval_hours'] === null ? null : (float)$m['interval_hours'],
            'interval_days' => $m['interval_days'] === null ? null : (int)$m['interval_days'],
            'last_service_km' => $m['last_service_km'] === null ? null : (float)$m['last_service_km'],
            'last_service_hours' => $m['last_service_hours'] === null ? null : (float)$m['last_service_hours'],
            'last_service_date' => $m['last_service_date'], 'cost' => $m['cost'] === null ? null : (float)$m['cost'],
            'notes' => $m['notes'], 'remaining' => $status['text'], 'due' => $status['due']];
    }
    return ['items' => $rows];
}
function h_maintenance_save($user, $p): array {
    $accountId = scope_account($user);
    $id = (string)($p['id'] ?? '');
    $unitId = bstr('unit_id', '');
    if ($id !== '') {
        $st = db()->prepare('SELECT * FROM maintenance WHERE id = ?');
        $st->execute([$id]);
        $m = $st->fetch();
        need($m, 'Maintenance item not found', 404);
        $unitId = (string)$m['unit_id'];
    }
    $unit = unit_by_id($unitId);
    need(is_super($user) || $unit['account_id'] === $accountId, 'Unit not found', 404);
    need(unit_allowed($user, $unitId), 'You do not have access to this unit', 403);
    $name = bstr('name', '');
    need($name !== '', 'Service name is required');
    $num = function (string $k) {
        $v = bval($k, null);
        return ($v === null || $v === '') ? null : (float)$v;
    };
    $days = $num('interval_days');
    $f = [$unitId, $name, $num('interval_km'), $num('interval_hours'), $days === null ? null : (int)$days,
        $num('last_service_km'), $num('last_service_hours'), bstr('last_service_date', '') ?: null,
        $num('cost'), bstr('notes', '') ?: null];
    if ($id === '') {
        $id = uid('mnt');
        db()->prepare('INSERT INTO maintenance (id,account_id,unit_id,name,interval_km,interval_hours,interval_days,
                       last_service_km,last_service_hours,last_service_date,cost,notes,created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge([$id, $unit['account_id']], $f, [now_dt()]));
        audit('maintenance_created', 'maintenance', $id, ['unit' => $unit['name'], 'name' => $name]);
    } else {
        db()->prepare('UPDATE maintenance SET unit_id=?, name=?, interval_km=?, interval_hours=?, interval_days=?,
                       last_service_km=?, last_service_hours=?, last_service_date=?, cost=?, notes=? WHERE id=?')
            ->execute(array_merge($f, [$id]));
        audit('maintenance_updated', 'maintenance', $id, ['unit' => $unit['name'], 'name' => $name]);
    }
    return ['maintenance_id' => $id, 'message' => 'Service interval saved'];
}
function h_maintenance_delete($user, $p): array {
    $id = (string)$p['id'];
    $st = db()->prepare('SELECT * FROM maintenance WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    need($m, 'Maintenance item not found', 404);
    need(is_super($user) || $m['account_id'] === scope_account($user), 'Maintenance item not found', 404);
    db()->prepare('DELETE FROM maintenance WHERE id = ?')->execute([$id]);
    audit('maintenance_deleted', 'maintenance', $id, ['name' => $m['name']]);
    return ['message' => 'Service interval deleted'];
}

/* ---------------------------------------------------------------- events */
function h_events_list($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT e.*, u.name AS unit_name FROM events e LEFT JOIN units u ON u.id = e.unit_id WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND e.account_id = ?'; $prm[] = $accountId; }
    if (qp('unit_id')) { $sql .= ' AND e.unit_id = ?'; $prm[] = qp('unit_id'); }
    if (qp('type')) { $sql .= ' AND e.type = ?'; $prm[] = qp('type'); }
    if (qp('severity')) { $sql .= ' AND e.severity = ?'; $prm[] = qp('severity'); }
    if (qp('acknowledged') !== null && qp('acknowledged') !== '') { $sql .= ' AND e.acknowledged = ?'; $prm[] = (int)qp('acknowledged'); }
    if (qp('from')) { $sql .= ' AND e.ts >= ?'; $prm[] = dt_param(qp('from')); }
    if (qp('to')) { $sql .= ' AND e.ts <= ?'; $prm[] = dt_param(qp('to')); }
    [$scope, $scopePr] = unit_scope_sql($user, 'e.unit_id');
    $sql .= $scope;
    $prm = array_merge($prm, $scopePr);
    $sql .= ' ORDER BY e.ts DESC LIMIT ' . min(2000, max(1, (int)qp('limit', 300)));
    $st = db()->prepare($sql);
    $st->execute($prm);
    $rows = array_map(fn($e) => ['id' => (int)$e['id'], 'ts' => iso($e['ts']), 'type' => $e['type'],
        'severity' => $e['severity'], 'text' => $e['text'], 'unit' => $e['unit_name'], 'unit_id' => $e['unit_id'],
        'lat' => $e['lat'] === null ? null : (float)$e['lat'], 'lon' => $e['lon'] === null ? null : (float)$e['lon'],
        'speed' => $e['speed'] === null ? null : (float)$e['speed'], 'acknowledged' => (int)$e['acknowledged'],
        'address' => address_of($e['lat'], $e['lon']), 'extra' => jdec($e['extra_json'] ?? null, [])], $st->fetchAll());
    return ['events' => $rows, 'count' => count($rows)];
}
function h_events_ack($user, $p): array {
    require_write($user);
    $ids = int_list('ids');
    if (!$ids) {                                    // no ids = acknowledge everything
        $accountId = scope_account($user);
        $sql = 'UPDATE events SET acknowledged = 1 WHERE acknowledged = 0';
        $prm = [];
        if ($accountId) { $sql .= ' AND account_id = ?'; $prm[] = $accountId; }
        db()->prepare($sql)->execute($prm);
    } else {
        db()->prepare('UPDATE events SET acknowledged = 1 WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
    }
    audit('events_acknowledged', 'event', null, ['ids' => $ids ?: 'all']);
    return ['message' => 'Events acknowledged'];
}

/* -------------------------------------------------------------- commands */
function h_commands_list($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT c.*, u.name AS unit_name FROM commands c JOIN units u ON u.id = c.unit_id WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND c.account_id = ?'; $prm[] = $accountId; }
    if (qp('unit_id')) { $sql .= ' AND c.unit_id = ?'; $prm[] = qp('unit_id'); }
    if (qp('state')) { $sql .= ' AND c.state = ?'; $prm[] = qp('state'); }
    $sql .= ' ORDER BY c.created_at DESC LIMIT ' . min(500, max(1, (int)qp('limit', 100)));
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['commands' => array_map(fn($c) => ['id' => $c['id'], 'unit_id' => $c['unit_id'], 'unit' => $c['unit_name'],
        'type' => $c['type'], 'state' => $c['state'], 'payload' => jdec($c['payload_json'] ?? null, []),
        'text' => ips_command_text($c), 'created_at' => iso($c['created_at']), 'sent_at' => iso($c['sent_at']),
        'acked_at' => iso($c['acked_at']), 'result' => $c['result']], $st->fetchAll())];
}
function h_command_delete($user, $p): array {
    require_write($user);
    $id = (string)$p['id'];
    $st = db()->prepare('SELECT * FROM commands WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    need($c, 'Command not found', 404);
    need(is_super($user) || $c['account_id'] === scope_account($user), 'Command not found', 404);
    db()->prepare('DELETE FROM commands WHERE id = ?')->execute([$id]);
    return ['message' => 'Command removed'];
}

/* --------------------------------------------------- device connections */
function h_device_sessions($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT s.*, u.name AS unit_name FROM device_sessions s LEFT JOIN units u ON u.id = s.unit_id WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND u.account_id = ?'; $prm[] = $accountId; }
    if (qp('unit_id')) { $sql .= ' AND s.unit_id = ?'; $prm[] = qp('unit_id'); }
    $sql .= ' ORDER BY s.id DESC LIMIT ' . min(500, max(1, (int)qp('limit', 100)));
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['sessions' => array_map(fn($s) => ['id' => (int)$s['id'], 'unit_id' => $s['unit_id'],
        'unit' => $s['unit_name'], 'unique_id' => $s['unique_id'], 'protocol' => $s['protocol'],
        'login_ts' => iso($s['login_ts']), 'last_ts' => iso($s['last_ts']), 'logout_ts' => iso($s['logout_ts']),
        'packets' => (int)$s['packets'], 'ip' => $s['ip'], 'online' => $s['logout_ts'] === null], $st->fetchAll())];
}
