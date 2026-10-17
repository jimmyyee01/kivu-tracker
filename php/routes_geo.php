<?php
/* =====================================================================
   KIVU Tracker — php/routes_geo.php
   Geofences (circle / polygon / polyline) and notification rules.
   ===================================================================== */
declare(strict_types=1);

function routes_geo(): array {
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    return [
        ['GET', '#^/geofences$#', 'h_geofences_list', 'read'],
        ['POST', '#^/geofences$#', 'h_geofence_save', 'write'],
        ['PUT', '#^/geofences/' . $id . '$#', 'h_geofence_save', 'write'],
        ['DELETE', '#^/geofences/' . $id . '$#', 'h_geofence_delete', 'write'],
        ['GET', '#^/geofences/' . $id . '/events$#', 'h_geofence_events', 'read'],
        ['GET', '#^/geofence-state$#', 'h_geofence_state', 'read'],
        ['GET', '#^/notifications$#', 'h_notifications_list', 'read'],
        ['POST', '#^/notifications$#', 'h_notification_save', 'write'],
        ['PUT', '#^/notifications/' . $id . '$#', 'h_notification_save', 'write'],
        ['DELETE', '#^/notifications/' . $id . '$#', 'h_notification_delete', 'write'],
        ['POST', '#^/notifications/' . $id . '/test$#', 'h_notification_test', 'write'],
    ];
}

function geofence_shape(array $g): array {
    $pts = jdec($g['points_json'] ?? null, []);
    $out = ['id' => $g['id'], 'account_id' => $g['account_id'], 'name' => $g['name'], 'type' => $g['type'],
        'points' => $pts, 'radius_m' => $g['radius_m'] === null ? null : (float)$g['radius_m'],
        'color' => $g['color'], 'width' => (int)$g['width'], 'group_id' => $g['group_id'],
        'description' => $g['description'], 'speed_limit' => $g['speed_limit'] === null ? null : (int)$g['speed_limit'],
        'enabled' => (int)$g['enabled'], 'created_at' => iso($g['created_at'])];
    if ($g['type'] === 'polyline') {
        $out['length_km'] = round(geo_polyline_length_m($pts) / 1000, 3);
    } elseif ($g['type'] === 'polygon' && count($pts) >= 3) {
        /* shoelace area on a local equirectangular projection */
        $n = count($pts);
        $refLat = 0.0;
        foreach ($pts as $pt) $refLat += (float)$pt[0];
        $refLat /= max(1, $n);
        $mLat = 111132.92; $mLon = 111320.0 * cos(deg2rad($refLat));
        $area = 0.0;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $x1 = (float)$pts[$j][1] * $mLon; $y1 = (float)$pts[$j][0] * $mLat;
            $x2 = (float)$pts[$i][1] * $mLon; $y2 = (float)$pts[$i][0] * $mLat;
            $area += $x1 * $y2 - $x2 * $y1;
        }
        $out['area_km2'] = round(abs($area) / 2 / 1e6, 4);
    }
    $out['bbox'] = geo_bbox($pts ? [$pts] : []);
    return $out;
}

function h_geofences_list($user, $p): array {
    $accountId = scope_account($user);
    $rows = geofences_list($accountId, qp('enabled') === '1');
    if (qp('q')) {
        $q = strtolower((string)qp('q'));
        $rows = array_values(array_filter($rows, fn($g) => strpos(strtolower((string)$g['name']), $q) !== false));
    }
    return ['geofences' => array_map('geofence_shape', $rows)];
}

function h_geofence_save($user, $p): array {
    $accountId = scope_account($user);
    $id = (string)($p['id'] ?? '');
    $account = $accountId;
    if ($id === '') {
        if (is_super($user) && bstr('account_id', '') !== '') $account = bstr('account_id');
        need((string)$account !== '', 'account_id is required');
    }
    $type = bstr('type', 'polygon');
    need(in_array($type, ['circle', 'polygon', 'polyline'], true), 'Geofence type must be circle, polygon or polyline');
    $raw = bval('points', []);
    need(is_array($raw), 'points must be an array of [lat, lon] pairs');
    $points = [];
    foreach ($raw as $pt) {
        if (!is_array($pt) || count($pt) < 2) continue;
        $lat = (float)$pt[0]; $lon = (float)$pt[1];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) throw new ApiError('Invalid coordinate: ' . jenc($pt));
        $points[] = [$lat, $lon];
    }
    $radius = bval('radius_m', null);
    if ($type === 'circle') {
        need(count($points) === 1, 'A circle geofence needs exactly one centre point');
        need($radius !== null && (float)$radius > 0, 'A circle geofence needs radius_m');
    } elseif ($type === 'polygon') {
        need(count($points) >= 3, 'A polygon geofence needs at least 3 points');
    } else {
        need(count($points) >= 2, 'A polyline geofence needs at least 2 points');
    }
    $speedLimit = bval('speed_limit', null);
    $fields = [
        'name' => bstr('name', ''), 'type' => $type, 'points_json' => jenc($points),
        'radius_m' => $type === 'circle' ? (float)$radius : null,
        'color' => bstr('color', '#2f9cf4'), 'width' => max(1, min(10, bint('width', 2))),
        'group_id' => bstr('group_id', '') ?: null, 'description' => bstr('description', '') ?: null,
        'speed_limit' => ($speedLimit === null || $speedLimit === '') ? null : (int)$speedLimit,
        'enabled' => bint('enabled', 1) ? 1 : 0,
    ];
    need($fields['name'] !== '', 'Geofence name is required');
    if ($id === '') {
        $id = uid('geo');
        $fields['account_id'] = $account;
        $fields['created_at'] = now_dt();
        $fields['id'] = $id;
        $cols = array_keys($fields);
        db()->prepare('INSERT INTO geofences (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($fields));
        audit('geofence_created', 'geofence', $id, ['name' => $fields['name'], 'type' => $type]);
    } else {
        $g = geofence_by_id($id);
        need(is_super($user) || $g['account_id'] === $accountId, 'Geofence not found', 404);
        $set = []; $vals = [];
        foreach ($fields as $k => $v) { $set[] = "`$k` = ?"; $vals[] = $v; }
        $vals[] = $id;
        db()->prepare('UPDATE geofences SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        audit('geofence_updated', 'geofence', $id, ['name' => $fields['name']]);
    }
    return ['geofence' => geofence_shape(geofence_by_id($id)), 'message' => 'Geofence saved'];
}

function h_geofence_delete($user, $p): array {
    $id = (string)$p['id'];
    $g = geofence_by_id($id);
    need(is_super($user) || $g['account_id'] === scope_account($user), 'Geofence not found', 404);
    db()->prepare('DELETE FROM geofence_events WHERE geofence_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM geofences WHERE id = ?')->execute([$id]);
    audit('geofence_deleted', 'geofence', $id, ['name' => $g['name']]);
    return ['message' => 'Geofence deleted'];
}

function h_geofence_events($user, $p): array {
    $id = (string)$p['id'];
    $g = geofence_by_id($id);
    need(is_super($user) || $g['account_id'] === scope_account($user), 'Geofence not found', 404);
    $sql = 'SELECT e.*, u.name AS unit_name FROM geofence_events e JOIN units u ON u.id = e.unit_id
            WHERE e.geofence_id = ?';
    $prm = [$id];
    if (qp('from')) { $sql .= ' AND e.ts >= ?'; $prm[] = dt_param(qp('from')); }
    if (qp('to')) { $sql .= ' AND e.ts <= ?'; $prm[] = dt_param(qp('to')); }
    $sql .= ' ORDER BY e.ts DESC LIMIT ' . min(5000, max(1, (int)qp('limit', 500)));
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['geofence' => geofence_shape($g), 'events' => array_map(fn($e) => ['id' => (int)$e['id'],
        'unit' => $e['unit_name'], 'unit_id' => $e['unit_id'], 'event' => $e['event'], 'ts' => iso($e['ts']),
        'lat' => $e['lat'] === null ? null : (float)$e['lat'], 'lon' => $e['lon'] === null ? null : (float)$e['lon'],
        'address' => address_of($e['lat'], $e['lon'])], $st->fetchAll())];
}

/* Which units are inside each enabled geofence right now. */
function h_geofence_state($user, $p): array {
    $accountId = scope_account($user);
    $units = units_list(['account_id' => $accountId], $user);
    $out = [];
    foreach (geofences_list($accountId, true) as $g) {
        $inside = [];
        foreach ($units as $u) {
            if ($u['last_lat'] === null) continue;
            if (geo_contains($g, (float)$u['last_lat'], (float)$u['last_lon'])) {
                $inside[] = ['id' => $u['id'], 'name' => $u['name'], 'status' => unit_state($u)['status'],
                    'speed' => (float)$u['last_speed'], 'last_time' => iso($u['last_time'])];
            }
        }
        $out[] = ['id' => $g['id'], 'name' => $g['name'], 'type' => $g['type'], 'color' => $g['color'],
            'units_inside' => $inside, 'count' => count($inside)];
    }
    return ['geofences' => $out];
}

/* ------------------------------------------------------ notifications --- */
function notif_shape(array $n): array {
    return ['id' => $n['id'], 'name' => $n['name'], 'type' => $n['type'],
        'unit_ids' => jdec($n['unit_ids_json'] ?? null, []), 'geofence_ids' => jdec($n['geofence_ids_json'] ?? null, []),
        'params' => jdec($n['params_json'] ?? null, []), 'text_template' => $n['text_template'],
        'actions' => jdec($n['actions_json'] ?? null, ['push']), 'webhook_url' => $n['webhook_url'],
        'email_to' => $n['email_to'], 'phone_to' => $n['phone_to'], 'severity' => $n['severity'],
        'enabled' => (int)$n['enabled'], 'last_fired' => iso($n['last_fired']),
        'fired_count' => (int)$n['fired_count'], 'created_at' => iso($n['created_at'])];
}
function h_notifications_list($user, $p): array {
    $accountId = scope_account($user);
    $sql = 'SELECT * FROM notifications WHERE 1=1';
    $prm = [];
    if ($accountId) { $sql .= ' AND account_id = ?'; $prm[] = $accountId; }
    if (qp('type')) { $sql .= ' AND type = ?'; $prm[] = qp('type'); }
    $sql .= ' ORDER BY name';
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['notifications' => array_map('notif_shape', $st->fetchAll())];
}
function h_notification_save($user, $p): array {
    $accountId = scope_account($user);
    $id = (string)($p['id'] ?? '');
    $account = $accountId;
    if ($id === '') {
        if (is_super($user) && bstr('account_id', '') !== '') $account = bstr('account_id');
        need((string)$account !== '', 'account_id is required');
    }
    $type = bstr('type', 'speed');
    $allowed = ['speed', 'geofence', 'ignition', 'sos', 'fuel_drop', 'fuel_fill', 'idle', 'power_cut',
        'offline', 'movement', 'maintenance'];
    need(in_array($type, $allowed, true), 'Unknown notification type: ' . $type);
    $name = bstr('name', '');
    need($name !== '', 'Notification name is required');
    $params = bval('params', []);
    if (!is_array($params)) $params = [];
    $actions = list_of('actions');
    if (!$actions) $actions = ['push'];
    $fields = [
        'name' => $name, 'type' => $type,
        'unit_ids_json' => jenc(list_of('units')),
        'geofence_ids_json' => jenc(list_of('geofences')),
        'params_json' => jenc($params),
        'text_template' => bstr('text_template', '') ?: null,
        'actions_json' => jenc($actions),
        'webhook_url' => bstr('webhook_url', '') ?: null,
        'email_to' => bstr('email_to', '') ?: null,
        'phone_to' => bstr('phone_to', '') ?: null,
        'severity' => in_array(bstr('severity', 'warning'), ['info', 'warning', 'critical'], true)
            ? bstr('severity', 'warning') : 'warning',
        'enabled' => bint('enabled', 1) ? 1 : 0,
    ];
    if ($id === '') {
        $id = uid('ntf');
        $fields['account_id'] = $account;
        $fields['created_at'] = now_dt();
        $fields['id'] = $id;
        $cols = array_keys($fields);
        db()->prepare('INSERT INTO notifications (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($fields));
        audit('notification_created', 'notification', $id, ['name' => $name, 'type' => $type]);
    } else {
        $n = notification_by_id($id);
        need(is_super($user) || $n['account_id'] === $accountId, 'Notification not found', 404);
        $set = []; $vals = [];
        foreach ($fields as $k => $v) { $set[] = "`$k` = ?"; $vals[] = $v; }
        $vals[] = $id;
        db()->prepare('UPDATE notifications SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        audit('notification_updated', 'notification', $id, ['name' => $name]);
    }
    return ['notification' => notif_shape(notification_by_id($id)), 'message' => 'Notification saved'];
}
function h_notification_delete($user, $p): array {
    $id = (string)$p['id'];
    $n = notification_by_id($id);
    need(is_super($user) || $n['account_id'] === scope_account($user), 'Notification not found', 404);
    db()->prepare('DELETE FROM notifications WHERE id = ?')->execute([$id]);
    audit('notification_deleted', 'notification', $id, ['name' => $n['name']]);
    return ['message' => 'Notification deleted'];
}
/* Fires the rule once against a unit so the text/webhook can be verified. */
function h_notification_test($user, $p): array {
    $n = notification_by_id((string)$p['id']);
    need(is_super($user) || $n['account_id'] === scope_account($user), 'Notification not found', 404);
    $unitId = bstr('unit_id', '');
    $unit = null;
    if ($unitId !== '') $unit = unit_by_id($unitId);
    else {
        $list = units_list(['account_id' => $n['account_id']], null);
        need($list, 'Create a unit first', 400);
        $unit = $list[0];
    }
    $lat = $unit['last_lat'] ?? (float)setting('default_lat', -1.2921);
    $lon = $unit['last_lon'] ?? (float)setting('default_lon', 36.8219);
    $eventId = fire_notification($n, $unit, ['type' => $n['type'], 'lat' => (float)$lat, 'lon' => (float)$lon,
        'speed' => (float)$unit['last_speed'], 'vars' => ['unit' => $unit['name'] . ' [TEST]']]);
    $webhook = null;
    if (!empty($n['webhook_url']) && in_array('webhook', jdec($n['actions_json'] ?? null, []), true)) {
        $webhook = fire_webhook((string)$n['webhook_url'], ['test' => true, 'notification' => $n['name'],
            'unit' => $unit['name'], 'type' => $n['type'], 'ts' => date('c')]);
    }
    return ['event_id' => $eventId, 'unit' => $unit['name'], 'webhook' => $webhook,
        'message' => 'Test event created' . ($webhook ? ' — webhook ' . ($webhook['ok'] ? 'delivered' : 'failed') : '')];
}
