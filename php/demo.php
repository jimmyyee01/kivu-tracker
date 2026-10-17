<?php
/* =====================================================================
   KIVU Tracker — php/demo.php
   Demo content: the offline gazetteer (places), demo routes and a
   realistic track generator. Used by install.php (?fresh=1) and by
   tools/gen_history.php to create history for any unit.
   ===================================================================== */
declare(strict_types=1);

/* Places are how addresses are resolved without any external API. */
function demo_places_seed(): int {
    $places = [
        ['Nairobi CBD', 'city', -1.286389, 36.817223, 'Kenya'],
        ['Westlands', 'suburb', -1.267400, 36.807100, 'Kenya'],
        ['Karen', 'suburb', -1.319300, 36.706900, 'Kenya'],
        ['JKIA Cargo Terminal', 'airport', -1.319200, 36.927800, 'Kenya'],
        ['Embakasi', 'suburb', -1.309000, 36.893000, 'Kenya'],
        ['Industrial Area', 'suburb', -1.305000, 36.840000, 'Kenya'],
        ['Ruiru', 'town', -1.145000, 36.960000, 'Kenya'],
        ['Thika', 'town', -1.033300, 37.069300, 'Kenya'],
        ['Machakos', 'town', -1.517700, 37.263400, 'Kenya'],
        ['Athi River', 'town', -1.456500, 36.978500, 'Kenya'],
        ['Ngong', 'town', -1.360000, 36.660000, 'Kenya'],
        ['Kikuyu', 'town', -1.246000, 36.668000, 'Kenya'],
        ['Rongai', 'town', -1.396000, 36.745000, 'Kenya'],
        ['Limuru', 'town', -1.112000, 36.642000, 'Kenya'],
        ['Naivasha', 'town', -0.716700, 36.431000, 'Kenya'],
        ['Nakuru', 'city', -0.303100, 36.080000, 'Kenya'],
        ['Mombasa', 'city', -4.043500, 39.668200, 'Kenya'],
        ['Kisumu', 'city', -0.091700, 34.768000, 'Kenya'],
        ['Eldoret', 'city', 0.514300, 35.269800, 'Kenya'],
        ['Nairobi Inland Container Depot', 'depot', -1.310000, 36.860000, 'Kenya'],
        ['Acme Central Depot', 'depot', -1.296500, 36.826000, 'Kenya'],
        ['Safari Tours Yard', 'depot', -1.275500, 36.812000, 'Kenya'],
        ['Dagoretti Market', 'poi', -1.299000, 36.750000, 'Kenya'],
        ['Gikomba Market', 'poi', -1.283000, 36.833000, 'Kenya'],
        ['Two Rivers Mall', 'poi', -1.219000, 36.808000, 'Kenya'],
        ['SGR Nairobi Terminus', 'poi', -1.417000, 36.950000, 'Kenya'],
        ['Kenyatta National Hospital', 'poi', -1.301000, 36.807000, 'Kenya'],
        ['Kibera', 'suburb', -1.313300, 36.789200, 'Kenya'],
        ['South B', 'suburb', -1.310000, 36.833000, 'Kenya'],
        ['Kahawa', 'suburb', -1.190000, 36.920000, 'Kenya'],
    ];
    $st = db()->prepare('INSERT INTO places (name,kind,lat,lon,country) VALUES (?,?,?,?,?)');
    $n = 0;
    foreach ($places as $p) {
        $check = db()->prepare('SELECT id FROM places WHERE name = ? LIMIT 1');
        $check->execute([$p[0]]);
        if ($check->fetch()) continue;
        $st->execute($p);
        $n++;
    }
    return $n;
}

/* Named demo routes (waypoints as [lat, lon]). */
function demo_routes(): array {
    return [
        'nairobi_city' => [
            [-1.2965, 36.8260], [-1.2921, 36.8219], [-1.2864, 36.8172], [-1.2830, 36.8330],
            [-1.3050, 36.8400], [-1.3100, 36.8600], [-1.3090, 36.8930], [-1.3193, 36.9278],
        ],
        'thika_road' => [
            [-1.2965, 36.8260], [-1.2674, 36.8071], [-1.2190, 36.8080], [-1.1900, 36.9200],
            [-1.1450, 36.9600], [-1.0333, 37.0693],
        ],
        'western_loop' => [
            [-1.2755, 36.8120], [-1.2674, 36.8071], [-1.2460, 36.6680], [-1.1120, 36.6420],
            [-0.7167, 36.4310], [-0.3031, 36.0800],
        ],
        'southern_loop' => [
            [-1.2965, 36.8260], [-1.3050, 36.8400], [-1.3100, 36.8600], [-1.4170, 36.9500],
            [-1.4565, 36.9785], [-1.5177, 37.2634], [-1.3960, 36.7450], [-1.3193, 36.7069],
            [-1.3133, 36.7892], [-1.2965, 36.8260],
        ],
        'coast_run' => [
            [-1.4170, 36.9500], [-1.4565, 36.9785], [-2.0000, 37.5000], [-3.2000, 38.5000],
            [-4.0435, 39.6682],
        ],
    ];
}

/* Cumulative-distance sampling along a route (drives out and back). */
function demo_route_sampler(array $route): callable {
    $cum = [0.0];
    for ($i = 1; $i < count($route); $i++) {
        $cum[] = $cum[$i - 1] + geo_dist_m((float)$route[$i - 1][0], (float)$route[$i - 1][1],
            (float)$route[$i][0], (float)$route[$i][1]);
    }
    $total = end($cum);
    return function (float $d) use ($route, $cum, $total): array {
        if ($total <= 0) return [$route[0][0], $route[0][1]];
        $p = fmod($d, 2 * $total);
        if ($p > $total) $p = 2 * $total - $p;                 // out-and-back
        for ($i = 1; $i < count($cum); $i++) {
            if ($p <= $cum[$i]) {
                $seg = max(1e-6, $cum[$i] - $cum[$i - 1]);
                $t = ($p - $cum[$i - 1]) / $seg;
                return [(float)$route[$i - 1][0] + $t * ((float)$route[$i][0] - (float)$route[$i - 1][0]),
                        (float)$route[$i - 1][1] + $t * ((float)$route[$i][1] - (float)$route[$i - 1][1])];
            }
        }
        return [$route[count($route) - 1][0], $route[count($route) - 1][1]];
    };
}

/* Simulates a realistic driving day and pushes it through the ingest engine,
   so geofence events, notifications, trips and reports are all real.
   Options: phase (offset along the route), fuel_start, refill_at, drain_at,
   interval (seconds), id_prefix. */
function demo_generate_history(string $unitId, string $fromTs, string $toTs, array $route,
                               int $intervalS = 120, array $opts = []): array {
    $unit = unit_by_id($unitId);
    $sampler = demo_route_sampler($route);
    $start = (int)strtotime($fromTs);
    $end = (int)strtotime($toTs);
    if ($end <= $start) return ['points' => 0, 'events' => 0, 'geofence_events' => 0];
    $dist = (float)($opts['phase'] ?? 0) * 1000;                 // metres along the route
    $fuel = (float)($opts['fuel_start'] ?? 140.0);
    $period = max(60, $end - $start);
    $refillAt = isset($opts['refill_at']) ? (float)$opts['refill_at'] : null;
    $drainAt = isset($opts['drain_at']) ? (float)$opts['drain_at'] : null;
    $refilled = false; $drained = false;
    $points = 0; $events = 0; $geofence = 0;
    $baseSpeed = (float)($opts['base_speed'] ?? 45.0);

    for ($t = $start; $t <= $end; $t += $intervalS) {
        $elapsed = $t - $start;
        $cycle = $elapsed % 3600;
        $parked = $cycle < 600;                                   // 10-minute stop every hour
        if ($parked) {
            $speed = 0.0;
            $ignition = $cycle < 480 ? 0 : 1;                      // engine off, then idling
        } else {
            $speed = max(0.0, $baseSpeed + 22 * sin($elapsed / 900.0) - 6 * cos($elapsed / 240.0));
            $ignition = 1;
        }
        if ($speed > 0) $dist += $speed / 3.6 * $intervalS;
        [$lat, $lon] = $sampler($dist);

        if ($ignition) $fuel -= 0.11 * ($intervalS / 60.0);
        if ($refillAt !== null && !$refilled && $elapsed >= $period * $refillAt) { $fuel += 60.0; $refilled = true; }
        if ($drainAt !== null && !$drained && $elapsed >= $period * $drainAt) { $fuel = max(5.0, $fuel - 35.0); $drained = true; }
        $fuel = max(3.0, min(400.0, $fuel));

        $params = [
            'ign' => $ignition,
            'fuel' => round($fuel, 1),
            'pwr' => $ignition ? 13.9 : 12.6,
            'hdop' => round(0.8 + 0.4 * sin($elapsed / 300.0), 2),
            'temp' => round(22 + 6 * sin($elapsed / 3600.0), 1),
            'sats' => 9 + (int)round(3 * sin($elapsed / 120.0)),
        ];
        $res = ingest_point($unit, [
            'ts' => date('Y-m-d H:i:s', $t), 'lat' => $lat, 'lon' => $lon, 'speed' => round($speed, 1),
            'course' => round(fmod(($dist / 40), 360), 1), 'alt' => 1650 + 40 * sin($elapsed / 1800.0),
            'sats' => $params['sats'], 'params' => $params, 'source' => 'demo',
        ]);
        $points++;
        $events += count($res['events'] ?? []);
        $geofence += count($res['geofence_events'] ?? []);
        if ($points % 100 === 0) $unit = unit_by_id($unitId);      // keep live state fresh
    }
    return ['points' => $points, 'events' => $events, 'geofence_events' => $geofence,
        'from' => date('Y-m-d H:i:s', $start), 'to' => date('Y-m-d H:i:s', $end)];
}

/* Removes the telemetry of one unit (used before regenerating history). */
function demo_clear_unit_history(string $unitId): void {
    db()->prepare('DELETE FROM messages WHERE unit_id = ?')->execute([$unitId]);
    db()->prepare('DELETE FROM trips WHERE unit_id = ?')->execute([$unitId]);
    db()->prepare('DELETE FROM geofence_events WHERE unit_id = ?')->execute([$unitId]);
    db()->prepare('DELETE FROM events WHERE unit_id = ?')->execute([$unitId]);
    db()->prepare('UPDATE units SET odometer_km = 0, engine_hours = 0, last_time = NULL, last_lat = NULL,
                   last_lon = NULL, last_speed = 0, last_fuel = NULL, moving = 0, online = 0 WHERE id = ?')
        ->execute([$unitId]);
}

function demo_wipe(): void {
    foreach (['messages', 'trips', 'geofence_events', 'events', 'commands', 'sensors', 'maintenance', 'drivers',
              'units', 'unit_groups', 'geofences', 'notifications', 'report_templates', 'device_sessions',
              'audit_log', 'sessions', 'users', 'accounts', 'places'] as $t) {
        db()->exec("DELETE FROM `$t`");
    }
    db()->exec('DELETE FROM settings');
}

/* Creates the whole demo world. Returns log lines. */
function demo_seed(bool $fresh = false, int $historyHours = 26, int $intervalS = 120): array {
    $log = [];
    ensure_schema();
    $installed = (int)db()->query('SELECT COUNT(*) FROM units')->fetchColumn();
    if ($installed > 0 && !$fresh) {
        return ['Database already contains ' . $installed . ' unit(s) — nothing seeded. Append ?fresh=1 to wipe and reseed.'];
    }
    if ($fresh) { demo_wipe(); $log[] = 'Existing data wiped (fresh install).'; }

    /* ------------------------------------------------------------ settings */
    $seed = bin2hex(random_bytes(4));
    db()->prepare('INSERT INTO settings (id,platform_name,company_name,support_email,default_currency,map_tiles,
                   default_lat,default_lon,default_zoom,online_timeout_s,moving_speed_kmh,retention_days,ips_port,seed,installed_at)
                   VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(['KIVU Tracker', 'Acme Telematics', 'support@example.com', 'USD',
            'https://tile.openstreetmap.org/{z}/{x}/{y}.png', -1.2921, 36.8219, 11, 300, 5, 90, 20332, $seed, now_dt()]);
    $log[] = 'Platform settings created (IPS port 20332, Nairobi map centre).';

    $log[] = demo_places_seed() . ' places added to the offline geocoder.';

    /* ---------------------------------------------------------------- plans */
    $log[] = demo_seed_plans();

    /* ------------------------------------------------------------ accounts */
    $insAcc = function (string $id, string $name, string $slug, string $planCode, int $limit, string $cur,
                        string $status = 'comp') use ($log) {
        $plan = plan_by_code($planCode);
        db()->prepare('INSERT INTO accounts (id,name,slug,plan,plan_id,status,trial_ends_at,units_limit,storage_days,
                       currency,timezone,contact_email,contact_name,contact_phone,billing_email,signup_source,
                       enabled,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)')
            ->execute([$id, $name, $slug, $planCode, $plan['id'] ?? null, $status,
                $status === 'trialing' ? date('Y-m-d H:i:s', time() + trial_days() * 86400) : null,
                $limit, $plan ? (int)$plan['storage_days'] : 90, $cur, 'Africa/Nairobi',
                'ops@' . $slug . '.example', $name . ' ops', '+254700000' . random_int(100, 999),
                'billing@' . $slug . '.example', 'admin', now_dt()]);
        if ($status !== 'comp') {
            db()->prepare('INSERT INTO subscriptions (id,account_id,plan_id,status,period_start,period_end,created_at,updated_at)
                           VALUES (?,?,?,?,?,?,?,?)')
                ->execute([uid('sub'), $id, $plan['id'] ?? null, $status, now_dt(),
                    date('Y-m-d H:i:s', time() + 30 * 86400), now_dt(), now_dt()]);
        }
    };
    $insAcc('acc_acme', 'Acme Logistics', 'acme-logistics', 'business', 50, 'USD', 'comp');
    $insAcc('acc_tours', 'Safari Tours Ltd', 'safari-tours', 'starter', 20, 'KES', 'comp');
    $log[] = '2 accounts created (Acme Logistics, Safari Tours Ltd).';

    /* --------------------------------------------------------------- users */
    $insUser = function (string $id, ?string $account, string $role, string $username, string $name, string $email,
                         string $password, ?array $accessUnits = null) {
        db()->prepare('INSERT INTO users (id,account_id,username,email,phone,full_name,role,salt,password_hash,
                       access_units_json,enabled,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)')
            ->execute([$id, $account, $username, $email, null, $name, $role, bin2hex(random_bytes(8)),
                password_hash($password, PASSWORD_BCRYPT), $accessUnits === null ? null : jenc($accessUnits), now_dt()]);
    };
    $insUser('usr_super', null, 'super', 'superadmin', 'Platform Super Admin', 'super@example.com', 'SuperAdmin@123');
    $insUser('usr_acme_admin', 'acc_acme', 'admin', 'admin', 'Acme Administrator', 'admin@acme.example', 'Admin@123');
    $insUser('usr_acme_op', 'acc_acme', 'operator', 'operator', 'Acme Dispatcher', 'operator@acme.example', 'Operator@123');
    $insUser('usr_acme_view', 'acc_acme', 'viewer', 'viewer', 'Acme Client (read only)', 'viewer@acme.example', 'Viewer@123',
        ['unt_acme_01', 'unt_acme_02']);
    $insUser('usr_tours_admin', 'acc_tours', 'admin', 'safari', 'Safari Operations', 'ops@safari.example', 'Admin@123');
    $log[] = '5 users created (super / admin / operator / viewer / second-account admin).';

    /* -------------------------------------------------------------- groups */
    foreach ([['grp_long', 'Long haul'], ['grp_city', 'City delivery'], ['grp_tours', 'Tour vans']] as $g) {
        db()->prepare('INSERT INTO unit_groups (id,account_id,parent_id,name,color,created_at) VALUES (?,?,NULL,?,?,?)')
            ->execute([$g[0], $g[0] === 'grp_tours' ? 'acc_tours' : 'acc_acme', $g[1],
                $g[0] === 'grp_tours' ? '#9b59b6' : ($g[0] === 'grp_long' ? '#e67e22' : '#2ecc71'), now_dt()]);
    }
    $log[] = '3 unit groups created.';

    /* -------------------------------------------------------------- drivers */
    $insDriver = function (string $id, string $account, string $name, string $phone, string $licence) {
        db()->prepare('INSERT INTO drivers (id,account_id,name,phone,licence,id_number,address,created_at)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $account, $name, $phone, $licence, strtoupper(bin2hex(random_bytes(4))), null, now_dt()]);
    };
    $insDriver('drv_jk', 'acc_acme', 'Joseph Kamau', '+254700111222', 'DL-KE-8891');
    $insDriver('drv_ma', 'acc_acme', 'Mary Achieng', '+254700333444', 'DL-KE-7742');
    $insDriver('drv_pm', 'acc_acme', 'Peter Mutiso', '+254700555666', 'DL-KE-6613');
    $insDriver('drv_sw', 'acc_tours', 'Sarah Wanjiru', '+254700777888', 'DL-KE-5521');
    $log[] = '4 drivers created.';

    /* ---------------------------------------------------------------- units */
    $units = [
        // id, account, name, unique_id, group, plate, model, driver, speed limit, fuel rate, tags
        ['unt_acme_01', 'acc_acme', 'Truck 01 — Scania R450', '356938035643809', 'grp_long', 'KDA 123X', 'Scania R450', 'drv_jk', 80, 32.0, 'long-haul,truck'],
        ['unt_acme_02', 'acc_acme', 'Truck 02 — Volvo FH16', '356938035643810', 'grp_long', 'KDB 456Y', 'Volvo FH16', 'drv_ma', 80, 34.5, 'long-haul,truck'],
        ['unt_acme_03', 'acc_acme', 'Van 03 — Toyota Hiace', '356938035643811', 'grp_city', 'KDC 789Z', 'Toyota Hiace', 'drv_pm', 90, 11.5, 'city,van'],
        ['unt_acme_04', 'acc_acme', 'Van 04 — Ford Transit', '356938035643812', 'grp_city', 'KDD 321A', 'Ford Transit', 'drv_pm', 90, 12.0, 'city,van'],
        ['unt_acme_05', 'acc_acme', 'Bus 05 — Yutong ZK6122', '356938035643813', 'grp_city', 'KDE 654B', 'Yutong ZK6122', 'drv_jk', 90, 24.0, 'passenger,bus'],
        ['unt_acme_06', 'acc_acme', 'Pickup 06 — Hilux', '356938035643814', 'grp_city', 'KDF 987C', 'Toyota Hilux', 'drv_ma', 100, 9.5, 'service,pickup'],
        ['unt_tours_01', 'acc_tours', 'Safari Van 01', '356938035643815', 'grp_tours', 'KDG 135D', 'Toyota Land Cruiser', 'drv_sw', 90, 14.0, 'tour,4x4'],
        ['unt_tours_02', 'acc_tours', 'Safari Van 02', '356938035643816', 'grp_tours', 'KDH 246E', 'Nissan Patrol', 'drv_sw', 90, 15.5, 'tour,4x4'],
    ];
    $insUnit = db()->prepare('INSERT INTO units (id,account_id,name,unique_id,device_password,hw_type,phone,model,plate,
        group_id,tags,driver_id,fuel_rate,fuel_price,cost_per_km,speed_limit,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($units as $u) {
        // 0 id, 1 account, 2 name, 3 unique_id, 4 group, 5 plate, 6 model, 7 driver, 8 speed_limit, 9 fuel_rate, 10 tags
        $isTour = $u[1] === 'acc_tours';
        $insUnit->execute([
            $u[0], $u[1], $u[2], $u[3], 'track', 'ips',
            '+2547' . random_int(10000000, 99999999), $u[6], $u[5], $u[4], $u[10], $u[7],
            $u[9],                      // fuel_rate L/100km
            $isTour ? 1.35 : 1.55,      // fuel price per litre
            $isTour ? 0.55 : 0.42,      // cost per km
            $u[8],                      // speed limit
            now_dt(),
        ]);
    }
    $log[] = '8 units created (6 for Acme, 2 for Safari Tours). IPS login = unique_id, device password = "track".';

    /* -------------------------------------------------------------- sensors */
    $insSensor = db()->prepare('INSERT INTO sensors (id,unit_id,account_id,name,type,param,units,coefficient,
        offset_val,min_val,max_val,color,enabled,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
    foreach ($units as $u) {
        $acct = $u[1];
        $insSensor->execute([uid('sns'), $u[0], $acct, 'Fuel level', 'fuel_level', 'fuel', 'L', 1, 0, 0, 400, '#f39c12', now_dt()]);
        $insSensor->execute([uid('sns'), $u[0], $acct, 'Ignition', 'ignition', 'ign', '', 1, 0, 0, 1, '#2ecc71', now_dt()]);
        $insSensor->execute([uid('sns'), $u[0], $acct, 'Board voltage', 'voltage', 'pwr', 'V', 1, 0, 0, 30, '#9b59b6', now_dt()]);
        $insSensor->execute([uid('sns'), $u[0], $acct, 'Temperature', 'temperature', 'temp', 'C', 1, 0, -20, 80, '#e74c3c', now_dt()]);
    }
    $log[] = 'Sensors created for every unit (fuel, ignition, voltage, temperature).';

    /* ------------------------------------------------------------ geofences */
    $routes = demo_routes();
    $insGeo = db()->prepare('INSERT INTO geofences (id,account_id,name,type,points_json,radius_m,color,width,
        group_id,description,speed_limit,enabled,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $insGeo->execute(['geo_depot', 'acc_acme', 'Acme Central Depot', 'circle',
        jenc([[-1.2965, 36.8260]]), 350, '#2ecc71', 2, null,
        'Main yard — arrivals and departures are notified.', null, now_dt()]);
    $insGeo->execute(['geo_jkia', 'acc_acme', 'JKIA Cargo Area', 'polygon',
        jenc([[-1.3150, 36.9210], [-1.3090, 36.9300], [-1.3220, 36.9360], [-1.3290, 36.9250]]), null, '#e74c3c', 2,
        null, 'Restricted cargo apron (40 km/h limit inside).', 40, now_dt()]);
    $insGeo->execute(['geo_thika', 'acc_acme', 'Thika Road Corridor', 'polyline',
        jenc($routes['thika_road']), null, '#f1c40f', 4, null,
        'Speed-controlled corridor.', 80, now_dt()]);
    $insGeo->execute(['geo_mombasa_rd', 'acc_acme', 'Mombasa Road Check', 'polygon',
        jenc([[-1.4300, 36.9400], [-1.4600, 36.9900], [-1.4750, 36.9700], [-1.4400, 36.9300]]), null, '#9b59b6', 2,
        null, 'Black-spot zone — 60 km/h limit.', 60, now_dt()]);
    $insGeo->execute(['geo_safari_lodge', 'acc_tours', 'Mara Gate Lodge', 'circle',
        jenc([[-1.5000, 35.2000]]), 800, '#2f9cf4', 2, null, 'Guest pick-up point.', null, now_dt()]);
    $log[] = '5 geofences created (circles, polygons and a polyline corridor).';

    /* -------------------------------------------------------- notifications */
    $insNotif = db()->prepare('INSERT INTO notifications (id,account_id,name,type,unit_ids_json,geofence_ids_json,
        params_json,text_template,actions_json,webhook_url,email_to,phone_to,severity,enabled,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $insNotif->execute(['ntf_speed', 'acc_acme', 'Speed control 80 km/h', 'speed', jenc([]), jenc([]),
        jenc(['speed_limit' => 80, 'use_geofence_limit' => true]), null, jenc(['push']), null, null, null, 'warning', now_dt()]);
    $insNotif->execute(['ntf_depot', 'acc_acme', 'Depot arrival / departure', 'geofence', jenc([]), jenc(['geo_depot']),
        jenc(['events' => ['enter', 'exit']]), null, jenc(['push', 'email']), null, 'ops@acme.example', null, 'info', now_dt()]);
    $insNotif->execute(['ntf_ign', 'acc_acme', 'Ignition on/off (long haul)', 'ignition',
        jenc(['unt_acme_01', 'unt_acme_02']), jenc([]), jenc(['events' => ['on', 'off']]), null,
        jenc(['push']), null, null, null, 'info', now_dt()]);
    $insNotif->execute(['ntf_sos', 'acc_acme', 'SOS / panic button', 'sos', jenc([]), jenc([]), jenc([]),
        null, jenc(['push', 'webhook']), null, null, null, 'critical', now_dt()]);
    $insNotif->execute(['ntf_fuel_drop', 'acc_acme', 'Fuel theft (drop 20 L)', 'fuel_drop', jenc([]), jenc([]),
        jenc(['min_change' => 20]), null, jenc(['push']), null, null, null, 'critical', now_dt()]);
    $insNotif->execute(['ntf_fuel_fill', 'acc_acme', 'Refuelling detected', 'fuel_fill', jenc([]), jenc([]),
        jenc(['min_change' => 40]), null, jenc(['push']), null, null, null, 'info', now_dt()]);
    $insNotif->execute(['ntf_idle', 'acc_acme', 'Long idling (20 min)', 'idle', jenc([]), jenc([]),
        jenc(['idle_min' => 20]), null, jenc(['push']), null, null, null, 'warning', now_dt()]);
    $insNotif->execute(['ntf_offline', 'acc_acme', 'No data for 15 min', 'offline', jenc([]), jenc([]),
        jenc(['timeout_s' => 900]), null, jenc(['push', 'email']), null, 'ops@acme.example', null, 'warning', now_dt()]);
    $insNotif->execute(['ntf_service', 'acc_acme', 'Service interval due', 'maintenance', jenc([]), jenc([]),
        jenc([]), null, jenc(['push', 'email']), null, 'workshop@acme.example', null, 'warning', now_dt()]);
    $insNotif->execute(['ntf_tours_move', 'acc_tours', 'Guest transfer started', 'movement', jenc([]), jenc([]),
        jenc([]), null, jenc(['push']), null, null, null, 'info', now_dt()]);
    $log[] = '10 notification rules created (speed, geofence, ignition, SOS, fuel, idle, offline, service).';

    /* ---------------------------------------------------------- maintenance */
    $insMaint = db()->prepare('INSERT INTO maintenance (id,account_id,unit_id,name,interval_km,interval_hours,
        interval_days,last_service_km,last_service_hours,last_service_date,cost,notes,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([['unt_acme_01', 10000, 8], ['unt_acme_02', 12000, 9], ['unt_acme_05', 8000, 5]] as $m) {
        $insMaint->execute([uid('mnt'), 'acc_acme', $m[0], 'Oil & filter service', $m[1], null, null, 0, 0,
            date('Y-m-d', time() - $m[2] * 86400), 180, 'Every ' . $m[1] . ' km or yearly.', now_dt()]);
        $insMaint->execute([uid('mnt'), 'acc_acme', $m[0], 'Brake inspection', $m[1] * 3, null, 180, 0, null,
            date('Y-m-d', time() - 200 * 86400), 120, 'Half-yearly brake check.', now_dt()]);
    }
    $log[] = 'Service intervals created for 3 units.';

    /* ----------------------------------------------------- report templates */
    $insTpl = db()->prepare('INSERT INTO report_templates (id,account_id,name,type,params_json,created_by,shared,created_at)
        VALUES (?,?,?,?,?,?,1,?)');
    $insTpl->execute(['rpt_daily', 'acc_acme', 'Daily activity', 'activity', jenc(['group' => 'day']), 'usr_acme_admin', now_dt()]);
    $insTpl->execute(['rpt_trips', 'acc_acme', 'Trips — last 7 days', 'trips', jenc([]), 'usr_acme_admin', now_dt()]);
    $insTpl->execute(['rpt_fuel', 'acc_acme', 'Fuel control (fillings & theft)', 'fuel', jenc(['min_change' => 10]), 'usr_acme_admin', now_dt()]);
    $insTpl->execute(['rpt_fleet', 'acc_acme', 'Fleet summary', 'unit_summary', jenc([]), 'usr_acme_admin', now_dt()]);
    $insTpl->execute(['rpt_speed', 'acc_acme', 'Speed violations', 'speed_violations', jenc([]), 'usr_acme_admin', now_dt()]);
    $insTpl->execute(['rpt_geo', 'acc_acme', 'Geofence visits', 'geofences', jenc([]), 'usr_acme_admin', now_dt()]);
    $log[] = '6 report templates created.';

    /* --------------------------------------------------- history simulation */
    $to = now_dt();
    $from = date('Y-m-d H:i:s', time() - $historyHours * 3600);
    $plan = [
        ['unt_acme_01', 'thika_road', 0.4, ['refill_at' => 0.35, 'fuel_start' => 180, 'base_speed' => 52]],
        ['unt_acme_02', 'western_loop', 1.6, ['drain_at' => 0.65, 'fuel_start' => 320, 'base_speed' => 58]],
        ['unt_acme_03', 'nairobi_city', 0.2, ['fuel_start' => 55, 'base_speed' => 26]],
        ['unt_acme_04', 'nairobi_city', 1.1, ['fuel_start' => 48, 'base_speed' => 24]],
        ['unt_acme_05', 'southern_loop', 0.3, ['refill_at' => 0.5, 'fuel_start' => 210, 'base_speed' => 48]],
        ['unt_acme_06', 'nairobi_city', 2.0, ['fuel_start' => 40, 'base_speed' => 30]],
        ['unt_tours_01', 'southern_loop', 0.8, ['fuel_start' => 90, 'base_speed' => 44]],
        ['unt_tours_02', 'western_loop', 0.15, ['fuel_start' => 95, 'base_speed' => 46]],
    ];
    $totalPoints = 0; $totalEvents = 0; $totalGeo = 0;
    foreach ($plan as $step) {
        $res = demo_generate_history($step[0], $from, $to, $routes[$step[1]], $intervalS,
            ['phase' => $step[2]] + $step[3]);
        $totalPoints += $res['points'];
        $totalEvents += $res['events'];
        $totalGeofence = $res['geofence_events'];
        $totalGeo += $totalGeofence;
    }
    $log[] = sprintf('Generated %d telemetry points over %d h (%.2f per unit/hour), %d notification events, %d geofence events.',
        $totalPoints, $historyHours, $intervalS > 0 ? 3600 / $intervalS : 0, $totalEvents, $totalGeo);

    /* ------------------------------------------ trips + scheduled rules run */
    $trips = 0; $parkings = 0;
    foreach ($units as $u) {
        $r = detect_trips($u[0], $from, $to, true);
        $trips += count($r['trips']);
        $parkings += count($r['parkings']);
    }
    $checks = run_scheduled_checks(null);
    $log[] = sprintf('Trip detector: %d trips and %d parking/idle blocks stored. Scheduled rules: %s.',
        $trips, $parkings, jenc($checks));
    $log[] = 'Ready-to-use logins — super: superadmin / SuperAdmin@123 · admin: admin / Admin@123 (Acme) · '
        . 'operator: operator / Operator@123 · viewer: viewer / Viewer@123 (2 units only) · second account: safari / Admin@123.';
    $log[] = 'Devices connect to the IPS server with unique_id + password "track" (e.g. 356938035643809). '
        . 'Start it with: php php/tracker.php --verbose';
    return $log;
}

/* ---------------------------------------------------------------- plans ----
   The catalogue the /register page and the billing console sell. Amounts are
   per `interval` (month/year). The KES plan exists so the East-African mobile
   money gateways (M-Pesa, Pesapal, Flutterwave) can be demonstrated paying in
   local currency; Stripe/PayPal charge the USD plans. */
function demo_seed_plans(): string {
    $plans = [
        ['plan_starter', 'starter', 'Starter', 'Up to 10 vehicles — tracking, trips, geofences, 6 reports',
            19.00, 'USD', 'month', 10, 90, 5, 10,
            ['Up to 10 units', 'Live tracking + history', 'Trips, parkings, fuel events',
             'Geofences & notifications', '6 report types with CSV export', '5-day free trial']],
        ['plan_business', 'business', 'Business', 'Up to 50 vehicles — every report, drivers, maintenance',
            49.00, 'USD', 'month', 50, 180, 5, 20,
            ['Up to 50 units', 'Everything in Starter', 'All 12 report types', 'Drivers & service intervals',
             'Maintenance alerts', 'Priority support']],
        ['plan_business_year', 'business-yearly', 'Business — annual', 'Two months free when you pay for a year',
            490.00, 'USD', 'year', 50, 365, 5, 30,
            ['Up to 50 units', '12 months of storage', 'All 12 report types', 'Two months free']],
        ['plan_fleet_kes', 'fleet-kes', 'Fleet East Africa', 'Pay in KES by M-Pesa, card or bank',
            4900.00, 'KES', 'month', 25, 90, 5, 15,
            ['Up to 25 units', 'Pay with M-Pesa / Pesapal / card', 'Everything in Business',
             'Local currency invoicing']],
        ['plan_enterprise', 'enterprise', 'Enterprise', '250+ vehicles, 12 months of history, SLA',
            149.00, 'USD', 'month', 250, 365, 5, 40,
            ['Up to 250 units', 'Everything in Business', '12 months of storage', 'Account manager & SLA']],
    ];
    $made = 0;
    foreach ($plans as $p) {
        [$id, $code, $name, $tagline, $price, $cur, $interval, $limit, $storage, $trial, $order, $features] = $p;
        /* extra device price per billing period (0 = this plan caps devices) */
        $extra = ['plan_starter' => 3.00, 'plan_business' => 2.00, 'plan_business_year' => 25.00,
            'plan_fleet_kes' => 400.00, 'plan_enterprise' => 2.00][$id] ?? 0;
        $st = db()->prepare('SELECT id FROM plans WHERE code = ?');
        $st->execute([$code]);
        if ($row = $st->fetch()) {
            /* catalogue seeded before extra-device pricing existed: backfill it */
            db()->prepare('UPDATE plans SET extra_unit_price = ? WHERE id = ? AND extra_unit_price = 0')
                ->execute([$extra, (string)$row['id']]);
            continue;                                       // keep the rest of an existing plan intact
        }
        db()->prepare('INSERT INTO plans (id,code,name,tagline,price,currency,`interval`,unit_limit,
                       extra_unit_price,storage_days,trial_days,features_json,sort_order,enabled,created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)')
            ->execute([$id, $code, $name, $tagline, $price, $cur, $interval, $limit, $extra, $storage, $trial,
                jenc($features), $order, now_dt()]);
        $made++;
    }
    return $made . ' subscription plan(s) available on the register and billing pages';
}
