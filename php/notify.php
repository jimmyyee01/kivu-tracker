<?php
/* =====================================================================
   KIVU Tracker — php/notify.php
   Geofence enter/exit detection, notification rules, event log and the
   notification actions (in-console push, real webhook via cURL, e-mail /
   SMS queueing — see README for the outbound-delivery notes).
   ===================================================================== */
declare(strict_types=1);

function notifications_of_account(string $accountId): array {
    static $cache = [];
    if (!isset($cache[$accountId])) {
        $st = db()->prepare('SELECT * FROM notifications WHERE account_id = ? AND enabled = 1 ORDER BY name');
        $st->execute([$accountId]);
        $cache[$accountId] = $st->fetchAll();
    }
    return $cache[$accountId];
}
function notification_by_id(string $id): array {
    $st = db()->prepare('SELECT * FROM notifications WHERE id = ?');
    $st->execute([$id]);
    $n = $st->fetch();
    need($n, 'Notification not found', 404);
    return $n;
}
function geofence_by_id(string $id): array {
    $st = db()->prepare('SELECT * FROM geofences WHERE id = ?');
    $st->execute([$id]);
    $g = $st->fetch();
    need($g, 'Geofence not found', 404);
    return $g;
}
function notif_matches_unit(array $n, string $unitId): bool {
    $ids = jdec($n['unit_ids_json'] ?? null, []);
    if (!is_array($ids) || !$ids) return true;         // empty list = all units
    return in_array($unitId, array_map('strval', $ids), true);
}
function notif_matches_geofence(array $n, string $geofenceId): bool {
    $ids = jdec($n['geofence_ids_json'] ?? null, []);
    if (!is_array($ids) || !$ids) return true;
    return in_array($geofenceId, array_map('strval', $ids), true);
}

function render_template(string $tpl, array $vars): string {
    $out = $tpl;
    foreach ($vars as $k => $v) {
        $out = str_replace(['{' . $k . '}', '{{' . $k . '}}'], (string)$v, $out);
    }
    return $out;
}
function notification_default_text(string $type): string {
    $map = [
        'speed' => 'Speed violation: {speed} km/h (limit {limit} km/h) at {address}',
        'geofence_enter' => 'Entered geofence "{geofence}" at {address}',
        'geofence_exit' => 'Left geofence "{geofence}" at {address}',
        'ignition_on' => 'Ignition ON at {address}',
        'ignition_off' => 'Ignition OFF at {address}',
        'sos' => 'SOS / panic button pressed at {address}',
        'fuel_drop' => 'Fuel drop of {change} L detected at {address}',
        'fuel_fill' => 'Fuel refill of {change} L detected at {address}',
        'power_cut' => 'External power cut, voltage {voltage} V at {address}',
        'offline' => 'No data for {age} (last seen {last_time})',
        'movement' => 'Movement started at {address}',
        'idle' => 'Idle for {duration} with engine running at {address}',
        'maintenance' => 'Maintenance due: {item} ({remaining})',
    ];
    return $map[$type] ?? '{unit}: ' . $type . ' at {address}';
}

/* Writes an event row and runs the notification's actions. */
function fire_notification(array $n, array $unit, array $ctx): int {
    $type = (string)($ctx['type'] ?? $n['type']);
    $vars = array_merge([
        'unit' => $unit['name'], 'unique_id' => $unit['unique_id'],
        'ts' => date('Y-m-d H:i:s'), 'address' => address_of($ctx['lat'] ?? null, $ctx['lon'] ?? null),
        'lat' => $ctx['lat'] ?? '', 'lon' => $ctx['lon'] ?? '', 'speed' => $ctx['speed'] ?? 0,
        'geofence' => $ctx['geofence'] ?? '', 'change' => $ctx['change'] ?? 0,
        'limit' => $ctx['limit'] ?? '', 'voltage' => $ctx['voltage'] ?? '',
        'age' => $ctx['age'] ?? '', 'last_time' => $ctx['last_time'] ?? '',
        'duration' => $ctx['duration'] ?? '', 'item' => $ctx['item'] ?? '', 'remaining' => $ctx['remaining'] ?? '',
    ], $ctx['vars'] ?? []);
    $tpl = trim((string)$n['text_template']) !== '' ? (string)$n['text_template'] : notification_default_text($type);
    $text = $unit['name'] . ': ' . render_template($tpl, $vars);
    $severity = (string)($ctx['severity'] ?? ($n['severity'] ?: 'warning'));
    $st = db()->prepare('INSERT INTO events (account_id,unit_id,notification_id,geofence_id,ts,type,text,lat,lon,speed,severity,acknowledged,extra_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?)');
    $st->execute([$unit['account_id'], $unit['id'], $n['id'], $ctx['geofence_id'] ?? null, now_dt(), $type, $text,
        $ctx['lat'] ?? null, $ctx['lon'] ?? null, $ctx['speed'] ?? null, $severity,
        !empty($ctx['extra']) ? jenc($ctx['extra']) : null]);
    $eventId = (int)db()->lastInsertId();
    db()->prepare('UPDATE notifications SET last_fired = ?, fired_count = fired_count + 1 WHERE id = ?')
        ->execute([now_dt(), $n['id']]);

    $actions = jdec($n['actions_json'] ?? null, ['push']);
    if (!is_array($actions) || !$actions) $actions = ['push'];
    $payload = ['event_id' => $eventId, 'type' => $type, 'unit' => $unit['name'], 'unit_id' => $unit['id'],
        'text' => $text, 'severity' => $severity, 'lat' => $ctx['lat'] ?? null, 'lon' => $ctx['lon'] ?? null,
        'speed' => $ctx['speed'] ?? null, 'ts' => date('c'), 'notification' => $n['name']];
    foreach ($actions as $a) {
        if ($a === 'webhook' && !empty($n['webhook_url'])) {
            $r = fire_webhook((string)$n['webhook_url'], $payload);
            audit('notification_webhook', 'notification', $n['id'],
                ['url' => $n['webhook_url'], 'ok' => $r['ok'], 'status' => $r['status'] ?? null]);
        } elseif ($a === 'email' && !empty($n['email_to'])) {
            audit('notification_email_queued', 'notification', $n['id'], ['to' => $n['email_to'], 'subject' => $text]);
        } elseif ($a === 'sms' && !empty($n['phone_to'])) {
            audit('notification_sms_queued', 'notification', $n['id'], ['to' => $n['phone_to'], 'text' => $text]);
        }
    }
    return $eventId;
}

/* Real outbound HTTP notification (cURL is bundled with PHP). */
function fire_webhook(string $url, array $payload): array {
    if (!preg_match('#^https?://#i', $url)) return ['ok' => false, 'error' => 'invalid url'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => jenc($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $err === '' && $status >= 200 && $status < 300, 'status' => $status,
        'error' => $err ?: null, 'body' => is_string($body) ? substr($body, 0, 300) : null];
}

/* Reads a canonical parameter out of a stored message row. */
function msg_param(?array $row, array $names): ?float {
    if (!$row) return null;
    return param_value(jdec($row['params_json'] ?? null, []), $names);
}

/* ---------------------------------------------------------------- fences */
function geofence_check(array $unit, array $m, ?array $prev): array {
    $out = [];
    if ($m['lat'] === null || $m['lon'] === null) return $out;
    $geos = geofences_list((string)$unit['account_id'], true);
    if (!$geos) return $out;
    $notifs = notifications_of_account((string)$unit['account_id']);
    foreach ($geos as $g) {
        $inside = geo_contains($g, (float)$m['lat'], (float)$m['lon']);
        $wasIn = ($prev && $prev['lat'] !== null)
            ? geo_contains($g, (float)$prev['lat'], (float)$prev['lon']) : false;
        if ($inside === $wasIn) continue;
        $ev = $inside ? 'enter' : 'exit';
        db()->prepare('INSERT INTO geofence_events (account_id,geofence_id,geofence_name,unit_id,event,ts,lat,lon) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$unit['account_id'], $g['id'], $g['name'], $unit['id'], $ev, $m['ts'], $m['lat'], $m['lon']]);
        $gfeId = (int)db()->lastInsertId();
        $out[] = ['id' => $gfeId, 'geofence_id' => $g['id'], 'name' => $g['name'], 'event' => $ev,
            'ts' => iso($m['ts']), 'lat' => (float)$m['lat'], 'lon' => (float)$m['lon']];
        foreach ($notifs as $n) {
            if ($n['type'] !== 'geofence') continue;
            $p = jdec($n['params_json'] ?? null, []);
            $wanted = isset($p['events']) && is_array($p['events']) ? $p['events'] : ['enter', 'exit'];
            if (!in_array($ev, $wanted, true)) continue;
            if (!notif_matches_unit($n, (string)$unit['id']) || !notif_matches_geofence($n, (string)$g['id'])) continue;
            $out[] = ['event_id' => fire_notification($n, $unit, [
                'type' => 'geofence_' . $ev, 'geofence' => $g['name'], 'geofence_id' => $g['id'],
                'lat' => (float)$m['lat'], 'lon' => (float)$m['lon'], 'speed' => (float)$m['speed'],
                'extra' => ['geofence_event' => $ev],
            ])];
        }
    }
    return $out;
}

/* Rising/falling-edge rules evaluated on every incoming packet. */
function notification_rules(array $unit, array $m, ?array $prev, array $geoEvents): array {
    $fired = [];
    $notifs = notifications_of_account((string)$unit['account_id']);
    if (!$notifs) return $fired;
    $movingSpeed = (float)setting('moving_speed_kmh', 5);
    $prevIgn = $prev ? ($prev['ignition'] === null ? null : (int)$prev['ignition']) : null;
    $prevVolt = $prev && $prev['voltage'] !== null ? (float)$prev['voltage'] : null;
    $prevFuel = $prev && $prev['fuel'] !== null ? (float)$prev['fuel'] : null;
    $prevSpeed = $prev ? (float)$prev['speed'] : null;
    $prevSos = msg_param($prev, PARAM_SOS);

    foreach ($notifs as $n) {
        if (!notif_matches_unit($n, (string)$unit['id'])) continue;
        $p = jdec($n['params_json'] ?? null, []);
        $ctx = null;
        switch ($n['type']) {
            case 'speed':
                $limit = (float)($p['speed_limit'] ?? $unit['speed_limit']);
                if (!empty($p['use_geofence_limit'])) {
                    foreach (geofences_list((string)$unit['account_id'], true) as $g) {
                        if ($g['speed_limit'] !== null && geo_contains($g, (float)$m['lat'], (float)$m['lon'])) {
                            $limit = min($limit, (float)$g['speed_limit']);
                        }
                    }
                }
                if ($m['speed'] > $limit && ($prevSpeed === null || $prevSpeed <= $limit)) {
                    $ctx = ['limit' => $limit, 'speed' => (float)$m['speed']];
                }
                break;
            case 'ignition':
                if ($m['ignition'] !== null && $prevIgn !== null && (int)$m['ignition'] !== $prevIgn) {
                    $want = ((int)$m['ignition'] === 1) ? 'on' : 'off';
                    $sel = isset($p['events']) && is_array($p['events']) ? $p['events'] : ['on', 'off'];
                    if (in_array($want, $sel, true)) $ctx = ['type' => 'ignition_' . $want];
                }
                break;
            case 'sos':
                if ($m['sos'] !== null && $m['sos'] > 0 && ($prevSos === null || $prevSos <= 0)) {
                    $ctx = ['type' => 'sos', 'severity' => 'critical'];
                }
                break;
            case 'power_cut':
                $min = (float)($p['min_voltage'] ?? 11.0);
                if ($m['voltage'] !== null && (float)$m['voltage'] < $min && ($prevVolt === null || $prevVolt >= $min)) {
                    $ctx = ['voltage' => (float)$m['voltage'], 'severity' => 'critical'];
                }
                break;
            case 'fuel_drop':
            case 'fuel_fill':
                $change = (float)($p['min_change'] ?? 10);
                if ($m['fuel'] !== null && $prevFuel !== null) {
                    $diff = $prevFuel - (float)$m['fuel'];
                    if ($n['type'] === 'fuel_drop' && $diff >= $change) $ctx = ['change' => round($diff, 1)];
                    if ($n['type'] === 'fuel_fill' && -$diff >= $change) $ctx = ['change' => round(-$diff, 1)];
                }
                break;
            case 'movement':
                if ($m['speed'] > $movingSpeed && ($prevSpeed === null || $prevSpeed <= $movingSpeed)) {
                    $ctx = ['type' => 'movement'];
                }
                break;
            default:
                continue 2;   // geofence / idle / offline / maintenance are handled elsewhere
        }
        if ($ctx === null) continue;
        $fired[] = ['event_id' => fire_notification($n, $unit, array_merge([
            'lat' => (float)$m['lat'], 'lon' => (float)$m['lon'], 'speed' => (float)$m['speed'],
        ], $ctx, ['type' => $ctx['type'] ?? $n['type']]))];
    }
    return $fired;
}

/* ------------------------------------------------- scheduled (slow) rules */
function notifications_of_type(string $type, ?string $accountId = null): array {
    $sql = 'SELECT * FROM notifications WHERE enabled = 1 AND type = ?';
    $p = [$type];
    if ($accountId) { $sql .= ' AND account_id = ?'; $p[] = $accountId; }
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}
/* No-data ("connection lost") alerts, evaluated by /api/tasks/run + cron. */
function check_offline_notifications(?string $accountId = null): int {
    $fired = 0;
    foreach (notifications_of_type('offline', $accountId) as $n) {
        $p = jdec($n['params_json'] ?? null, []);
        $timeout = (int)($p['timeout_s'] ?? 900);
        foreach (units_list(['account_id' => $n['account_id']], null) as $u) {
            if (!notif_matches_unit($n, (string)$u['id'])) continue;
            if (empty($u['last_time'])) continue;
            $age = time() - (int)strtotime((string)$u['last_time']);
            if ($age < $timeout) continue;
            $st = db()->prepare("SELECT COUNT(*) FROM events WHERE unit_id = ? AND type = 'offline' AND ts >= ?");
            $st->execute([$u['id'], $u['last_time']]);
            if ((int)$st->fetchColumn() > 0) continue;      // already alerted for this silence
            fire_notification($n, $u, ['type' => 'offline', 'lat' => $u['last_lat'], 'lon' => $u['last_lon'],
                'age' => fmt_duration($age), 'last_time' => (string)$u['last_time'], 'severity' => $n['severity']]);
            $fired++;
        }
    }
    return $fired;
}
/* Service-interval alerts (km / engine hours / days). */
function check_maintenance_notifications(?string $accountId = null): int {
    $fired = 0;
    foreach (notifications_of_type('maintenance', $accountId) as $n) {
        $sql = 'SELECT m.*, u.name AS unit_name FROM maintenance m JOIN units u ON u.id = m.unit_id WHERE 1=1';
        $p = [];
        if ($n['account_id']) { $sql .= ' AND m.account_id = ?'; $p[] = $n['account_id']; }
        $st = db()->prepare($sql);
        $st->execute($p);
        foreach ($st->fetchAll() as $item) {
            $unit = unit_by_id((string)$item['unit_id']);
            if (!notif_matches_unit($n, (string)$unit['id'])) continue;
            $due = maintenance_status($item, $unit);
            if (!$due['due']) continue;
            $st2 = db()->prepare("SELECT COUNT(*) FROM events WHERE unit_id = ? AND type = 'maintenance' AND ts >= ?");
            $st2->execute([$unit['id'], date('Y-m-d H:i:s', time() - 86400)]);
            if ((int)$st2->fetchColumn() > 0) continue;     // at most one alert a day
            fire_notification($n, $unit, ['type' => 'maintenance', 'item' => $item['name'],
                'remaining' => $due['text'], 'lat' => $unit['last_lat'], 'lon' => $unit['last_lon'],
                'severity' => $n['severity']]);
            $fired++;
        }
    }
    return $fired;
}
/* Used by the maintenance report and the alert above. */
function maintenance_status(array $item, array $unit): array {
    $parts = [];
    $due = false;
    if ($item['interval_km'] !== null && (float)$item['interval_km'] > 0) {
        $base = $item['last_service_km'] === null ? (float)$unit['odometer_km'] : (float)$item['last_service_km'];
        $left = $base + (float)$item['interval_km'] - (float)$unit['odometer_km'];
        $parts[] = sprintf('%.0f km left', $left);
        if ($left <= 0) $due = true;
    }
    if ($item['interval_hours'] !== null && (float)$item['interval_hours'] > 0) {
        $base = $item['last_service_hours'] === null ? (float)$unit['engine_hours'] : (float)$item['last_service_hours'];
        $left = $base + (float)$item['interval_hours'] - (float)$unit['engine_hours'];
        $parts[] = sprintf('%.1f h left', $left);
        if ($left <= 0) $due = true;
    }
    if ($item['interval_days'] !== null && (int)$item['interval_days'] > 0 && !empty($item['last_service_date'])) {
        $left = (int)floor(((int)strtotime((string)$item['last_service_date']) + (int)$item['interval_days'] * 86400 - time()) / 86400);
        $parts[] = $left . ' days left';
        if ($left <= 0) $due = true;
    }
    return ['due' => $due, 'text' => $parts ? implode(', ', $parts) : 'n/a'];
}
/* Long-idle alert — called by the trip detector. */
function notify_idle(array $unit, int $idleSeconds, $lat, $lon): void {
    foreach (notifications_of_account((string)$unit['account_id']) as $n) {
        if ($n['type'] !== 'idle') continue;
        if (!notif_matches_unit($n, (string)$unit['id'])) continue;
        $p = jdec($n['params_json'] ?? null, []);
        $minSec = (int)(($p['idle_min'] ?? 20) * 60);
        if ($idleSeconds < $minSec) continue;
        fire_notification($n, $unit, ['type' => 'idle', 'lat' => $lat, 'lon' => $lon,
            'duration' => fmt_duration($idleSeconds), 'severity' => $n['severity']]);
    }
}
/* Everything that has to run on a timer (called by /api/tasks/run or cPanel cron). */
function run_scheduled_checks(?string $accountId = null): array {
    return [
        'offline' => check_offline_notifications($accountId),
        'maintenance' => check_maintenance_notifications($accountId),
        'geofence_sweep_units' => mark_offline_units($accountId),
    ];
}
/* Flags units that stopped sending data as offline (feeds the map colours). */
function mark_offline_units(?string $accountId = null): int {
    $timeout = (int)setting('online_timeout_s', 300);
    $sql = 'UPDATE units SET online = 0, moving = 0 WHERE online = 1 AND (last_time IS NULL OR last_time < ?)';
    $p = [date('Y-m-d H:i:s', time() - $timeout)];
    if ($accountId) { $sql .= ' AND account_id = ?'; $p[] = $accountId; }
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->rowCount();
}
