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
        'harsh' => '{mode}: {rate} km/h/s at {address} ({speed} km/h)',
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
        } elseif ($a === 'email') {
            foreach (alert_email_recipients($n, $unit) as $mail) {
                $subject = '[' . (string)setting('platform_name', APP_NAME) . '] ' . $text;
                $r = mail_send($mail, $subject, alert_email_html($text, $type, $severity, $unit, $vars, $eventId));
                audit('notification_email_sent', 'notification', $n['id'],
                    ['to' => $mail, 'ok' => $r['ok'], 'error' => $r['error'] ?? null]);
            }
        } elseif ($a === 'sms' && !empty($n['phone_to'])) {
            audit('notification_sms_queued', 'notification', $n['id'], ['to' => $n['phone_to'], 'text' => $text]);
        }
    }
    /* mobile push channel: instant Telegram (works while the app is closed) */
    $tlat = is_numeric($vars['lat'] ?? null) ? (float)$vars['lat'] : null;
    $tlon = is_numeric($vars['lon'] ?? null) ? (float)$vars['lon'] : null;
    $tloc = ($tlat !== null && $tlon !== null) ? "\n" . sprintf('https://maps.google.com/?q=%s,%s', $tlat, $tlon) : '';
    $tok = telegram_send('KIVU ' . strtoupper((string)$type) . ' - ' . (string)$unit['name'] . "\n" . (string)$text . $tloc
        . "\nAcknowledge in the app to stop the repeat alerts.");
    audit('notification_telegram', 'notification', $n['id'], ['ok' => $tok]);
    /* native web push: OS banner at the top of the screen, tap opens the app */
    $pok = push_send_all('KIVU ' . strtoupper((string)$type) . ' - ' . (string)$unit['name'],
        (string)$text . $tloc, '/app', 'kivu-e' . (int)$eventId, (string)($unit['account_id'] ?? ''));
    audit('notification_push', 'notification', $n['id'], ['ok' => $pok > 0, 'sent' => $pok]);
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
            case 'harsh':
                if (!$prev) break;
                $dt = (int)strtotime((string)$m['ts']) - (int)strtotime((string)$prev['ts']);
                if ($dt < 2 || $dt > 120) break;                 // sane window between fixes
                $rate = ((float)$m['speed'] - (float)$prev['speed']) / $dt;   // km/h per second
                $thr = (float)($p['threshold'] ?? 10);           // default 10 km/h/s (~2.8 m/s2)
                if (abs($rate) < $thr) break;
                $cool = (int)($p['cooldown_s'] ?? 60);
                $stc = db()->prepare("SELECT COUNT(*) FROM events WHERE unit_id = ? AND type = 'harsh' AND ts >= ?");
                $stc->execute([$unit['id'], date('Y-m-d H:i:s', time() - $cool)]);
                if ((int)$stc->fetchColumn() > 0) break;         // cooldown: one per minute
                $ctx = ['type' => 'harsh', 'severity' => 'warning',
                    'vars' => ['mode' => $rate < 0 ? 'Harsh braking' : 'Rapid acceleration',
                               'rate' => round(abs($rate), 1)],
                    'extra' => ['mode' => $rate < 0 ? 'braking' : 'acceleration',
                                'rate' => round(abs($rate), 1),
                                'from_speed' => (float)$prev['speed'], 'to_speed' => (float)$m['speed'],
                                'window_s' => $dt]];
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
        /* Super admin's platform interval (Settings) wins when set; 0 falls back
           to each rule's own timeout_s (default 15 min). Default: 10 minutes. */
        $globalMin = (int)setting('offline_alert_min', 10);
        $timeout = $globalMin > 0 ? $globalMin * 60 : max(60, (int)($p['timeout_s'] ?? 900));
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

/* Recipients: the rule's email_to plus every enabled admin of the unit's
   own account — alerts never cross account boundaries. */
function alert_email_recipients(array $n, array $unit): array {
    $list = [];
    foreach (explode(',', (string)($n['email_to'] ?? '')) as $e) {
        $e = trim($e);
        if ($e !== '' && strpos($e, '@') !== false) $list[] = $e;
    }
    $st = db()->prepare("SELECT email FROM users WHERE account_id = ? AND role = 'admin' AND enabled = 1 AND email IS NOT NULL AND email <> ''");
    $st->execute([(string)$unit['account_id']]);
    foreach ($st->fetchAll() as $r) $list[] = trim((string)$r['email']);
    /* super admins receive every account's alerts in one inbox */
    $st = db()->prepare("SELECT email FROM users WHERE role = 'super' AND enabled = 1 AND email IS NOT NULL AND email <> ''");
    $st->execute();
    foreach ($st->fetchAll() as $r) $list[] = trim((string)$r['email']);
    return array_values(array_unique($list));
}
function alert_email_html(string $text, string $type, string $severity, array $unit, array $vars, int $eventId): string {
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $color = ['info' => '#2f9cf4', 'warning' => '#f1c40f', 'critical' => '#e74c3c'][$severity] ?? '#f1c40f';
    $driver = trim((string)($unit['driver_name'] ?? ''));
    $coord = trim((string)($vars['lat'] ?? '')) !== ''
        ? (string)$vars['lat'] . ', ' . (string)($vars['lon'] ?? '') : '';
    $rows = [
        ['Device', (string)$unit['name']],
        ['Driver', $driver !== '' ? $driver : '-'],
        ['Alert', $text],
        ['Type', $type],
        ['When', date('Y-m-d H:i:s') . ' (server time)'],
        ['Location', trim((string)($vars['address'] ?? '')) !== '' ? (string)$vars['address'] : '-'],
        ['Coordinates', $coord],
        ['Severity', $severity],
    ];
    $body = '';
    foreach ($rows as $row) {
        $body .= '<tr><td style="padding:6px 12px;color:#6b7280;white-space:nowrap"><b>' . $esc($row[0]) . '</b></td>'
            . '<td style="padding:6px 12px">' . $esc($row[1]) . '</td></tr>';
    }
    $name = (string)setting('platform_name', APP_NAME);
    return '<div style="font-family:Arial,Helvetica,sans-serif;background:#f4f6fa;padding:18px">'
        . '<div style="max-width:580px;margin:0 auto;background:#fff;border:1px solid #e3e8f0;border-radius:10px;overflow:hidden">'
        . '<div style="background:' . $color . ';color:#fff;padding:12px 16px;font-weight:700">' . $esc($name) . ' alert</div>'
        . '<table style="border-collapse:collapse;width:100%;font-size:13px;color:#1f2937">' . $body . '</table>'
        . '<div style="padding:10px 16px;color:#6b7280;font-size:11.5px;border-top:1px solid #eef1f6">'
        . 'Event #' . $eventId . ' &mdash; sent by ' . $esc($name) . '</div></div></div>';
}

/* ------------------------------------------------- telegram (mobile push) --- */
/* Config lives inside settings.billing_json -> {"telegram": {...}} next to mail. */
function telegram_config(): array {
    $cfg = jdec(setting('billing_json', null), []);
    $t = is_array($cfg['telegram'] ?? null) ? $cfg['telegram'] : [];
    return [
        'token' => trim((string)($t['token'] ?? '')),
        'chat_id' => trim((string)($t['chat_id'] ?? '')),
        'enabled' => !empty($t['enabled']),
    ];
}
/* Real outbound Telegram message via their HTTPS bot API (no public ports needed). */
function telegram_send(string $text): bool {
    $t = telegram_config();
    if (!$t['enabled'] || $t['token'] === '' || $t['chat_id'] === '') return false;
    $ch = curl_init('https://api.telegram.org/bot' . $t['token'] . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => jenc(['chat_id' => $t['chat_id'], 'text' => $text,
            'disable_web_page_preview' => true]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return str_contains($body, '"ok":true');
}

/* --------------------------------------------------- web push (PWA install) --- */
/* VAPID keypair lives in billing_json.vapid {pub, priv} next to telegram/mail. */
function push_vapid(): array {
    $cfg = jdec(setting('billing_json', null), []);
    $v = is_array($cfg['vapid'] ?? null) ? $cfg['vapid'] : [];
    return ['pub' => trim((string)($v['pub'] ?? '')), 'priv' => trim((string)($v['priv'] ?? ''))];
}
function h_push_vapid(?array $user, array $params): array {
    $v = push_vapid();
    return ['publicKey' => $v['pub'], 'enabled' => $v['pub'] !== ''];
}
function h_push_subscribe(?array $user, array $params): array {
    need($user !== null, 'Authentication required', 401);
    $b = jdec((string)file_get_contents('php://input'), []);
    $endpoint = trim((string)($b['endpoint'] ?? ''));
    $p256 = trim((string)($b['keys']['p256dh'] ?? ''));
    $auth = trim((string)($b['keys']['auth'] ?? ''));
    need($endpoint !== '' && $p256 !== '' && $auth !== '', 'Invalid subscription', 400);
    db()->prepare('REPLACE INTO push_subs (user_id, endpoint, endpoint_hash, p256dh, auth, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([(int)$user['id'], $endpoint, hash('sha256', $endpoint), $p256, $auth, now_dt()]);
    return ['subscribed' => true];
}
function h_push_unsubscribe(?array $user, array $params): array {
    $b = jdec((string)file_get_contents('php://input'), []);
    $endpoint = trim((string)($b['endpoint'] ?? ''));
    if ($endpoint !== '') {
        db()->prepare('DELETE FROM push_subs WHERE endpoint_hash = ?')->execute([hash('sha256', $endpoint)]);
    }
    return ['subscribed' => false];
}
/* One OS notification to every subscribed device of this account (+super admins). */
function push_send_all(string $title, string $body, string $url, string $tag = 'kivu-alert', string $accountId = ''): int {
    $v = push_vapid();
    $script = __DIR__ . '/../tools/push_send.py';
    if ($v['pub'] === '' || $v['priv'] === '' || !is_file($script)) return 0;
    $st = db()->prepare('SELECT p.endpoint, p.p256dh, p.auth FROM push_subs p
        JOIN users u ON u.id = p.user_id
        WHERE u.enabled = 1 AND (u.role = ? OR u.account_id = ?)');
    $st->execute(['super', $accountId]);
    $payload = jenc(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag]);
    $sent = 0;
    foreach ($st->fetchAll() as $row) {
        $blob = base64_encode(jenc([
            'priv' => $v['priv'], 'endpoint' => $row['endpoint'],
            'p256dh' => $row['p256dh'], 'auth' => $row['auth'], 'payload' => $payload,
        ]));
        $out = trim((string)shell_exec('python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($blob) . ' 2>/dev/null'));
        if ($out === 'sent') $sent++;
        elseif (str_contains($out, '410') || str_contains($out, '404')) {
            db()->prepare('DELETE FROM push_subs WHERE endpoint_hash = ?')->execute([hash('sha256', $row['endpoint'])]);
        }
    }
    return $sent;
}
