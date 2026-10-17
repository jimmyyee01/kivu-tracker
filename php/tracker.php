<?php
/* =====================================================================
   KIVU Tracker — php/tracker.php
   Production-grade Wialon IPS 1.1 TCP server (default port 20332).
   Accepts real GPS trackers, stores their telemetry, evaluates geofences
   and notification rules, and pushes queued control commands back to the
   device. Runs as a CLI daemon:

       php php/tracker.php                 # port from settings (20332)
       php php/tracker.php --port=20332 --verbose
       php php/tracker.php --once=20       # exit after 20 data packets (tests)
       php php/tracker.php --minutes=5     # exit after 5 minutes (tests)

   On Windows:  start /b php php/tracker.php
   On Linux:    nohup php php/tracker.php > tracker.log 2>&1 &
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('tracker.php must be started from the command line');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/ips.php';
require_once __DIR__ . '/ingest.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/trips.php';
require_once __DIR__ . '/payments.php';   // subscription state gates device ingestion

/* ------------------------------------------------------------ arguments */
$opts = ['port' => null, 'once' => 0, 'minutes' => 0, 'verbose' => false, 'idle-timeout' => 300];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--verbose' || $a === '-v') { $opts['verbose'] = true; continue; }
    if (preg_match('/^--([a-z-]+)=(.+)$/', $a, $m)) { $opts[$m[1]] = $m[2]; }
}
$port = (int)($opts['port'] ?: setting('ips_port', 20332));
$once = (int)$opts['once'];
$maxSeconds = (int)$opts['minutes'] * 60;
$idleTimeout = (int)$opts['idle-timeout'];

function tlog(string $msg): void {
    global $opts;
    if (!$opts['verbose'] && strpos($msg, 'ERR') === false && strpos($msg, 'WARN') === false) return;
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}
function tlog_always(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

/* --------------------------------------------------------------- server */
ensure_schema();
$errno = 0; $errstr = '';
$server = @stream_socket_server('tcp://0.0.0.0:' . $port, $errno, $errstr);
if (!$server) {
    tlog_always("ERR cannot listen on port $port: $errstr ($errno)");
    exit(1);
}
stream_set_blocking($server, false);
tlog_always("KIVU Tracker IPS tracker listening on 0.0.0.0:$port (protocol IPS 1.1, " . setting('platform_name', APP_NAME) . ')');

$clients = [];            // id => connection state
$startedAt = time();
$lastCommandPoll = 0;
$lastScheduled = 0;
$lastReminderSweep = 0;
$dataPackets = 0;
$nextId = 1;

/* Command queue: pushes pending commands to connected devices. */
function tracker_poll_commands(array &$clients): void {
    if (!$clients) return;
    $unitIds = [];
    foreach ($clients as $c) { if (!empty($c['unit_id'])) $unitIds[$c['unit_id']] = true; }
    if (!$unitIds) return;
    $ids = array_keys($unitIds);
    $sql = "SELECT * FROM commands WHERE state = 'pending' AND unit_id IN ("
         . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY created_at LIMIT 50';
    $st = db()->prepare($sql);
    $st->execute($ids);
    foreach ($st->fetchAll() as $cmd) {
        foreach ($clients as $id => $c) {
            if (($c['unit_id'] ?? null) !== $cmd['unit_id']) continue;
            $text = ips_command_text($cmd);
            @fwrite($clients[$id]['sock'], ips_build_message($text));
            db()->prepare("UPDATE commands SET state = 'sent', sent_at = ? WHERE id = ?")->execute([now_dt(), $cmd['id']]);
            tlog("cmd {$cmd['type']} -> unit {$cmd['unit_id']}: $text");
        }
    }
}

/* A device just logged in: bind the socket to a unit row. */
function tracker_login(array &$clients, int $cid, string $login, string $password): bool {
    $st = db()->prepare('SELECT * FROM units WHERE unique_id = ?');
    $st->execute([$login]);
    $unit = $st->fetch();
    if (!$unit) {
        tlog("WARN unknown device login '$login' from " . $clients[$cid]['ip']);
        return false;
    }
    $expected = (string)$unit['device_password'];
    if ($expected !== '' && $password !== $expected) {
        tlog("WARN bad password for unit '{$unit['name']}' from " . $clients[$cid]['ip']);
        return false;
    }
    db()->prepare('INSERT INTO device_sessions (unit_id,unique_id,protocol,login_ts,last_ts,packets,ip) VALUES (?,?,?,?,?,0,?)')
        ->execute([$unit['id'], $unit['unique_id'], 'ips', now_dt(), now_dt(), $clients[$cid]['ip']]);
    $clients[$cid]['unit_id'] = $unit['id'];
    $clients[$cid]['session_id'] = (int)db()->lastInsertId();
    $clients[$cid]['unit'] = $unit;
    db()->prepare('UPDATE units SET online = 1, last_ip = ? WHERE id = ?')->execute([$clients[$cid]['ip'], $unit['id']]);
    tlog("login OK: {$unit['name']} ({$unit['unique_id']}) from " . $clients[$cid]['ip']);
    return true;
}
function tracker_logout(array &$clients, int $cid): void {
    $c = $clients[$cid] ?? null;
    if ($c && !empty($c['session_id'])) {
        db()->prepare('UPDATE device_sessions SET logout_ts = ?, last_ts = ? WHERE id = ?')
            ->execute([now_dt(), now_dt(), $c['session_id']]);
    }
    if ($c && !empty($c['unit_id'])) {
        tlog("disconnect: unit {$c['unit_id']} after {$c['packets']} packets");
    }
}

/* Handles one complete CRLF-terminated packet from a device. */
function tracker_handle_packet(array &$clients, int $cid, string $packet): void {
    global $dataPackets;
    $p = ips_parse_packet($packet);
    switch ($p['type']) {
        case 'L':
            $ok = tracker_login($clients, $cid, (string)$p['login'], (string)$p['password']);
            @fwrite($clients[$cid]['sock'], ips_response('L', $ok));
            break;
        case 'D':
        case 'B':                                  // black-box block carries the same payload
            if (empty($clients[$cid]['unit_id'])) {
                tlog('WARN data before login from ' . $clients[$cid]['ip']);
                @fwrite($clients[$cid]['sock'], ips_response($p['type'], false));
                break;
            }
            $unit = unit_by_id((string)$clients[$cid]['unit_id']);
            try {
                $res = ingest_point($unit, $p + ['ip' => $clients[$cid]['ip']]);
                $dataPackets++;
                $clients[$cid]['packets']++;
                if (!empty($clients[$cid]['session_id'])) {
                    db()->prepare('UPDATE device_sessions SET last_ts = ?, packets = packets + 1 WHERE id = ?')
                        ->execute([now_dt(), $clients[$cid]['session_id']]);
                }
                foreach ($res['geofence_events'] as $gfe) {
                    if (isset($gfe['event'])) tlog("unit {$unit['name']} {$gfe['event']} geofence '{$gfe['name']}'");
                }
            } catch (Throwable $e) {
                tlog('ERR ingest failed: ' . $e->getMessage());
                @fwrite($clients[$cid]['sock'], ips_response($p['type'], false));
                break;
            }
            @fwrite($clients[$cid]['sock'], ips_response($p['type'], true));
            break;
        case 'SD':
            if (!empty($clients[$cid]['unit_id'])) {
                $unit = unit_by_id((string)$clients[$cid]['unit_id']);
                $text = trim((string)$p['text']);
                if ($text !== '') {
                    db()->prepare('INSERT INTO events (account_id,unit_id,ts,type,text,severity,acknowledged) VALUES (?,?,?,?,?,?,0)')
                        ->execute([$unit['account_id'], $unit['id'], now_dt(), 'text_message',
                            $unit['name'] . ': ' . substr($text, 0, 400), 'info']);
                }
                @fwrite($clients[$cid]['sock'], ips_response('SD', true));
            } else {
                @fwrite($clients[$cid]['sock'], ips_response('SD', false));
            }
            break;
        case 'P':
            if (!empty($clients[$cid]['session_id'])) {
                db()->prepare('UPDATE device_sessions SET last_ts = ? WHERE id = ?')
                    ->execute([now_dt(), $clients[$cid]['session_id']]);
            }
            @fwrite($clients[$cid]['sock'], ips_response('P', true));
            break;
        case 'M':                                  // device acknowledging our command
            if (!empty($clients[$cid]['unit_id'])) {
                $ok = trim((string)($p['text'] ?? '1')) === '1';
                db()->prepare("UPDATE commands SET state = ?, acked_at = ?, result = ?
                               WHERE unit_id = ? AND state = 'sent' ORDER BY sent_at DESC LIMIT 1")
                    ->execute([$ok ? 'acked' : 'failed', now_dt(), $ok ? 'ack' : 'nack', $clients[$cid]['unit_id']]);
                tlog('command ' . ($ok ? 'acked' : 'rejected') . ' by unit ' . $clients[$cid]['unit_id']);
            }
            @fwrite($clients[$cid]['sock'], ips_response('M', true));
            break;
        case 'ACK':                                // e.g. "#AM#1" — device confirmed a message
            if (!empty($clients[$cid]['unit_id']) && $p['of'] === 'M') {
                db()->prepare("UPDATE commands SET state = ?, acked_at = ?, result = ?
                               WHERE unit_id = ? AND state = 'sent' ORDER BY sent_at DESC LIMIT 1")
                    ->execute([$p['ok'] ? 'acked' : 'failed', now_dt(), $p['ok'] ? 'ack' : 'nack',
                        $clients[$cid]['unit_id']]);
                tlog('command ' . ($p['ok'] ? 'acked' : 'rejected') . ' by unit ' . $clients[$cid]['unit_id']);
            }
            break;
        default:
            tlog('WARN unparsed packet from ' . $clients[$cid]['ip'] . ': ' . substr($packet, 0, 120));
    }
}

/* ------------------------------------------------------------- main loop */
$stop = false;
while (!$stop) {
    $now = time();
    if ($maxSeconds && $now - $startedAt >= $maxSeconds) { tlog_always('time limit reached, stopping'); break; }
    if ($once && $dataPackets >= $once) { tlog_always("received $dataPackets data packet(s), stopping"); break; }

    $read = [$server];
    $sockMap = [];
    foreach ($clients as $id => $c) { $read[] = $c['sock']; $sockMap[(int)$c['sock']] = $id; }
    $write = null; $except = null;
    $n = @stream_select($read, $write, $except, 1);
    if ($n === false) { usleep(200000); continue; }
    if ($n > 0) {
        foreach ($read as $sock) {
            if ($sock === $server) {
                $conn = @stream_socket_accept($server, 0);
                if ($conn) {
                    stream_set_blocking($conn, false);
                    $clients[$nextId] = ['sock' => $conn, 'ip' => (string)@stream_socket_get_name($conn, true),
                        'buffer' => '', 'unit_id' => null, 'session_id' => null, 'unit' => null,
                        'last' => $now, 'packets' => 0];
                    tlog('connect #' . $nextId . ' from ' . $clients[$nextId]['ip']);
                    $nextId++;
                }
                continue;
            }
            $cid = $sockMap[(int)$sock] ?? null;
            if ($cid === null || !isset($clients[$cid])) continue;
            $data = @fread($sock, 8192);
            if ($data === false || $data === '') {
                if (@feof($sock)) {
                    tracker_logout($clients, $cid);
                    @fclose($sock);
                    unset($clients[$cid]);
                }
                continue;
            }
            $clients[$cid]['buffer'] .= $data;
            $clients[$cid]['last'] = $now;
            while (($pos = strpos($clients[$cid]['buffer'], "\n")) !== false) {
                $line = substr($clients[$cid]['buffer'], 0, $pos);
                $clients[$cid]['buffer'] = substr($clients[$cid]['buffer'], $pos + 1);
                $line = trim($line);
                if ($line !== '') tracker_handle_packet($clients, $cid, $line);
                if ($once && $dataPackets >= $once) { $stop = true; break; }
            }
        }
    }

    if ($now - $lastCommandPoll >= 3) {
        try {
            tracker_poll_commands($clients);
            /* commands the device never confirmed are reported as failed */
            db()->prepare("UPDATE commands SET state = 'failed', result = 'timeout (no ack)'
                           WHERE state = 'sent' AND sent_at < ?")
                ->execute([date('Y-m-d H:i:s', time() - 300)]);
        } catch (Throwable $e) { tlog('ERR command poll: ' . $e->getMessage()); }
        $lastCommandPoll = $now;
    }
    if ($now - $lastScheduled >= 60) {
        try {
            $r = run_scheduled_checks(null);
            if ($r['offline'] || $r['maintenance']) tlog('scheduled checks: ' . jenc($r));
        } catch (Throwable $e) { tlog('ERR scheduled checks: ' . $e->getMessage()); }
        $lastScheduled = $now;
    }
    if ($now - $lastReminderSweep >= 3600) {
        /* payment reminders (14 / 5 / 2 days before deactivation) — hourly, so
           unattended deployments get them without a cron job */
        try {
            $rm = billing_reminders_sweep();
            if (!empty($rm['sent']) || !empty($rm['failed'])) {
                tlog('payment reminders: ' . jenc($rm));
            }
        } catch (Throwable $e) { tlog('ERR payment reminders: ' . $e->getMessage()); }
        $lastReminderSweep = $now;
    }
    foreach ($clients as $id => $c) {
        if ($now - $c['last'] > $idleTimeout) {
            tlog('idle timeout for connection #' . $id);
            tracker_logout($clients, $id);
            @fclose($c['sock']);
            unset($clients[$id]);
        }
    }
    usleep(100000);
}
foreach ($clients as $id => $c) { tracker_logout($clients, $id); @fclose($c['sock']); }
@fclose($server);
tlog_always("tracker stopped ($dataPackets data packet(s) this run)");
