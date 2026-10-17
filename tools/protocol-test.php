<?php
/* =====================================================================
   KIVU Tracker — tools/protocol-test.php
   Deep test of the Wialon IPS 1.1 server (php/tracker.php) with a real
   TCP client: it starts the tracker on a spare port, logs in, streams
   packets, sends malformed data and checks both the byte-level replies
   and the database effects (messages, sessions, commands, events).

       php tools/protocol-test.php              # port 20399
       php tools/protocol-test.php --port=20400 --keep

   Exits non-zero when a check fails, so it can run in CI or a cron job.
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/php/config.php';
require_once dirname(__DIR__) . '/php/engine.php';
require_once dirname(__DIR__) . '/php/ips.php';

$port = 20399;
$keep = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--port=(\d+)$/', $a, $m)) $port = (int)$m[1];
    if ($a === '--keep') $keep = true;
}
$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else {
        $fail++; $failures[] = $name . ($detail !== '' ? " — $detail" : '');
        echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}
function section(string $t): void { echo "\n== $t ==\n"; }

/* --------------------------------------------------- device test fixture */
$uid = 'PROTO-TEST-01';
db()->prepare('DELETE FROM units WHERE unique_id = ?')->execute([$uid]);
$account = db()->query('SELECT id FROM accounts ORDER BY id LIMIT 1')->fetchColumn();
if (!$account) { fwrite(STDERR, "No account in the database — run install.php first.\n"); exit(1); }
$unitId = 'unt_proto_test';
db()->prepare('INSERT INTO units (id,account_id,name,unique_id,device_password,hw_type,created_at)
               VALUES (?,?,?,?,?,?,?)')
    ->execute([$unitId, $account, 'Protocol test unit', $uid, 'protosecret', 'ips', now_dt()]);
db()->prepare('DELETE FROM messages WHERE unit_id = ?')->execute([$unitId]);
db()->prepare('DELETE FROM device_sessions WHERE unique_id = ?')->execute([$uid]);

/* --------------------------------------------------------- start tracker */
/* Nothing else may own the port, otherwise a stale tracker answers us. */
$busy = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);
if ($busy) {
    fclose($busy);
    fwrite(STDERR, "Port $port is already in use — stop the leftover tracker process"
        . " or run this test with --port=<other>.\n");
    exit(1);
}
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/php/tracker.php')
    . ' --port=' . $port . ' --verbose';
$desc = [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
         1 => ['file', dirname(__DIR__) . '/data/protocol-tracker.log', 'w'],
         2 => ['file', dirname(__DIR__) . '/data/protocol-tracker.err', 'w']];
$tracker = proc_open($cmd, $desc, $pipes);
/* Always kill the daemon by pid: proc_terminate() alone is unreliable on Windows,
   and the reported pid is the wrapper shell, so kill the whole process tree. */
function tracker_stop($proc): void {
    if (!$proc) return;
    $status = proc_get_status($proc);
    $pid = (int)($status['pid'] ?? 0);
    if ($pid > 0 && PHP_OS_FAMILY === 'Windows') @exec('taskkill /F /T /PID ' . $pid . ' 2>&1');
    @proc_terminate($proc);
    usleep(300000);
    @proc_close($proc);
}
$listening = false;
for ($i = 0; $i < 40; $i++) {
    usleep(250000);
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);
    if ($probe) { fclose($probe); $listening = true; break; }
}
echo "Wialon IPS protocol test — tracker on 127.0.0.1:$port\n";
if (!$listening) {
    fwrite(STDERR, "The tracker did not start listening on port $port.\n");
    if (!$keep) tracker_stop($tracker);
    exit(1);
}

/* ------------------------------------------------------------ tcp helper */
function device(?int $connectPort = null, float $timeout = 4.0) {
    static $port;
    if ($port === null) $port = $connectPort;
    $sock = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, $timeout);
    if (!$sock) return null;
    stream_set_timeout($sock, (int)$timeout, 500000);
    stream_set_blocking($sock, true);
    return $sock;
}
function send($sock, string $payload): void { @fwrite($sock, $payload); usleep(150000); }
function read($sock): string {
    $out = '';
    $end = microtime(true) + 1.2;
    while (microtime(true) < $end) {
        $chunk = @fread($sock, 8192);
        if ($chunk === false || $chunk === '') { usleep(60000); continue; }
        $out .= $chunk;
        if (strpos($out, "\n") !== false) break;
    }
    return trim($out);
}
function msgCount(string $unitId): int {
    $st = db()->prepare('SELECT COUNT(*) FROM messages WHERE unit_id = ?');
    $st->execute([$unitId]);
    return (int)$st->fetchColumn();
}
function unitRow(string $unitId): array {
    $st = db()->prepare('SELECT * FROM units WHERE id = ?');
    $st->execute([$unitId]);
    return $st->fetch() ?: [];
}

/* ------------------------------------------------------- 1. login phase */
section('Login handshake');
$sock = device($port);
check('TCP connection accepted', $sock !== null, 'could not connect');
if (!$sock) exit(1);

send($sock, "#D#" . date('Ymd;His') . ";-1.29;36.82;0;0;1650;9\r\n");
check('data sent before login is refused (#AD#0)', read($sock) === '#AD#0');

send($sock, "#L#$uid;wrongpass\r\n");
check('unknown password returns #AL#0', read($sock) === '#AL#0');
send($sock, "#L#UNKNOWN-DEVICE;protosecret\r\n");
check('unknown device returns #AL#0', read($sock) === '#AL#0');
send($sock, "#L#$uid;protosecret\r\n");
check('correct credentials return #AL#1', read($sock) === '#AL#1');
$row = unitRow($unitId);
check('the unit is flagged online on login', (int)($row['online'] ?? 0) === 1);
check('a device session row was created',
    (int)db()->query("SELECT COUNT(*) FROM device_sessions WHERE unique_id = '$uid'")->fetchColumn() === 1);
check('the device IP is recorded on the unit', (string)($row['last_ip'] ?? '') !== '');
section('Data, short data, ping & black box');
$before = msgCount($unitId);
send($sock, ips_build_data(time(), -1.2965, 36.8260, 42.0, 90, 1650, 9, ['ign' => 1, 'fuel' => 120.5, 'pwr' => 13.9]));
check('a standard #D# packet is acknowledged (#AD#1)', read($sock) === '#AD#1');
check('exactly one message was stored', msgCount($unitId) === $before + 1, 'messages ' . msgCount($unitId));
$last = db()->query("SELECT * FROM messages WHERE unit_id = '$unitId' ORDER BY id DESC LIMIT 1")->fetch();
check('degrees+minutes coordinates decode correctly',
    abs((float)$last['lat'] + 1.2965) < 0.0003 && abs((float)$last['lon'] - 36.8260) < 0.0003,
    'lat ' . $last['lat'] . ' lon ' . $last['lon']);
check('canonical parameters are extracted (ignition/fuel/voltage)',
    (int)$last['ignition'] === 1 && abs((float)$last['fuel'] - 120.5) < 0.01 && abs((float)$last['voltage'] - 13.9) < 0.01,
    'ign ' . $last['ignition'] . ' fuel ' . $last['fuel'] . ' volt ' . $last['voltage']);
check('raw parameters are preserved as JSON', strpos((string)$last['params_json'], '"fuel":"120.5"') !== false
    || strpos((string)$last['params_json'], '"fuel":120.5') !== false, (string)$last['params_json']);
check('the live position was updated', abs((float)unitRow($unitId)['last_lat'] + 1.2965) < 0.0003);

send($sock, ips_build_data(time(), -1.3000, 36.8300, 12.0, 45, 1600, 7, ['ign' => 1], 'dec'));
check('the 8-field decimal-degrees variant is accepted', read($sock) === '#AD#1');
$last = db()->query("SELECT * FROM messages WHERE unit_id = '$unitId' ORDER BY id DESC LIMIT 1")->fetch();
check('decimal-degrees coordinates decode correctly',
    abs((float)$last['lat'] + 1.3000) < 0.0003 && abs((float)$last['lon'] - 36.8300) < 0.0003,
    'lat ' . $last['lat'] . ' lon ' . $last['lon']);

$msgsBeforeSd = msgCount($unitId);
send($sock, "#SD#" . date('Ymd;His') . ";driver arrived at the depot\r\n");
check('short data is acknowledged (#ASD#1)', read($sock) === '#ASD#1');
$tm = db()->query("SELECT text FROM events WHERE unit_id = '$unitId' AND type = 'text_message' ORDER BY id DESC LIMIT 1")->fetch();
check('short data becomes a text_message event', $tm && strpos((string)$tm['text'], 'driver arrived at the depot') !== false,
    $tm ? (string)$tm['text'] : 'no text_message event');
check('short data does not create positions', msgCount($unitId) === $msgsBeforeSd,
    'messages ' . msgCount($unitId));

$msgsBeforePing = msgCount($unitId);
send($sock, "#P#" . date('Ymd;His') . "\r\n");
check('ping is answered with #AP#1', read($sock) === '#AP#1');
check('ping does not create a position', msgCount($unitId) === $msgsBeforePing);

send($sock, "#B#" . date('Ymd;His') . ";-1.3100;36.8400;25;180;1610;8;ign:1,fuel:110\r\n");
$boxAck = read($sock);
check('a black-box block is acknowledged (#AB#1)', $boxAck === '#AB#1', 'got ' . json_encode($boxAck));
check('the black-box position was stored', abs((float)db()->query(
    "SELECT lat FROM messages WHERE unit_id = '$unitId' ORDER BY id DESC LIMIT 1")->fetchColumn() + 1.31) < 0.0003);

section('Robustness against malformed input');
send($sock, "#ZZ#total nonsense;with;fields\r\n");
send($sock, "not-even-a-packet\r\n");
send($sock, "#D#garbage\r\n");
check('garbage is answered with a refusal, not a crash', in_array(read($sock), ['#AD#0', ''], true));
$msgsAfterGarbage = msgCount($unitId);
send($sock, ips_build_data(time(), -1.2965, 36.8260, 30.0, 90, 1650, 9, ['ign' => 1]));
check('the server still works after malformed input', read($sock) === '#AD#1');
check('garbage did not create positions', msgCount($unitId) === $msgsAfterGarbage + 1);

section('GPS jump filtering over the wire');
$liveBefore = unitRow($unitId);
send($sock, ips_build_data(time(), 40.0, -3.0, 30.0, 90, 700, 9, ['ign' => 1]));
check('the far-away packet is still acknowledged', read($sock) === '#AD#1');
$jumpRow = db()->query("SELECT * FROM messages WHERE unit_id = '$unitId' ORDER BY id DESC LIMIT 1")->fetch();
check('the far-away packet is stored as invalid (valid = 0)', (int)$jumpRow['valid'] === 0);
check('the jump did not move the live position',
    abs((float)unitRow($unitId)['last_lat'] - (float)$liveBefore['last_lat']) < 0.00001,
    'live lat ' . unitRow($unitId)['last_lat']);

section('Server → device commands (queue, delivery, ack/nack)');
$cmdId = 'cmd_proto_ack';
db()->prepare('INSERT INTO commands (id,account_id,unit_id,type,payload_json,state,created_at) VALUES (?,?,?,?,?,?,?)')
    ->execute([$cmdId, $account, $unitId, 'custom_message', jenc(['text' => 'report status']), 'pending', now_dt()]);
$delivered = '';
for ($i = 0; $i < 24; $i++) {           // the tracker polls the queue every 3 s
    usleep(500000);
    $line = read($sock);
    if ($line !== '') { $delivered = $line; break; }
}
check('the queued command is pushed to the device (#M#)', strpos($delivered, '#M#') === 0, 'got "' . $delivered . '"');
check('the command text is carried in the packet', strpos($delivered, 'report status') !== false, $delivered);
check('the command is marked as sent', (string)db()->query(
    "SELECT state FROM commands WHERE id = '$cmdId'")->fetchColumn() === 'sent');
send($sock, "#AM#1\r\n");
usleep(400000);
$state = db()->query("SELECT state, result FROM commands WHERE id = '$cmdId'")->fetch();
check('the ack (#AM#1) marks the command as acked',
    $state['state'] === 'acked' && $state['result'] === 'ack', jenc($state));

$failId = 'cmd_proto_nack';
db()->prepare('INSERT INTO commands (id,account_id,unit_id,type,payload_json,state,created_at) VALUES (?,?,?,?,?,?,?)')
    ->execute([$failId, $account, $unitId, 'engine_block', jenc(['text' => 'engine_block']), 'pending', now_dt()]);
$delivered2 = '';
for ($i = 0; $i < 24; $i++) {
    usleep(500000);
    $line = read($sock);
    if ($line !== '') { $delivered2 = $line; break; }
}
check('a second command is delivered', strpos($delivered2, '#M#') === 0, 'got "' . $delivered2 . '"');
send($sock, "#AM#0\r\n");
usleep(400000);
$failed = db()->query("SELECT state, result FROM commands WHERE id = '$failId'")->fetch();
check('a refusal (#AM#0) marks the command as failed',
    $failed['state'] === 'failed' && $failed['result'] === 'nack', jenc($failed));

section('Multiple devices & teardown');
$uid2 = 'PROTO-TEST-02';
$unitId2 = 'unt_proto_test2';
db()->prepare('DELETE FROM units WHERE unique_id = ?')->execute([$uid2]);
db()->prepare('INSERT INTO units (id,account_id,name,unique_id,device_password,hw_type,created_at)
               VALUES (?,?,?,?,?,?,?)')
    ->execute([$unitId2, $account, 'Protocol test unit 2', $uid2, 'second', 'ips', now_dt()]);
$sock2 = device($port);
check('a second device can connect', $sock2 !== null);
send($sock2, "#L#$uid2;second\r\n");
check('the second device logs in while the first stays connected', read($sock2) === '#AL#1');
send($sock, ips_build_data(time(), -1.2965, 36.8260, 20.0, 90, 1650, 9, ['ign' => 1]));
check('the first connection still works alongside the second', read($sock) === '#AD#1');
send($sock2, ips_build_data(time(), -4.0400, 39.6700, 20.0, 90, 20, 9, ['ign' => 1]));
check('the second connection ingests its own telemetry', read($sock2) === '#AD#1');
check('each unit keeps its own history', msgCount($unitId) > 0 && msgCount($unitId2) === 1,
    'unit1 ' . msgCount($unitId) . ' unit2 ' . msgCount($unitId2));
fclose($sock2);
usleep(600000);
check('closing a socket ends its session (logout_ts set)', (int)db()->query(
    "SELECT COUNT(*) FROM device_sessions WHERE unique_id = '$uid2' AND logout_ts IS NOT NULL")->fetchColumn() === 1);

echo "\n== Result ==\n  passed: $pass   failed: $fail\n";
if ($fail) {
    foreach ($failures as $f) echo "   - $f\n";
    echo "  Tracker log: data/protocol-tracker.log\n";
}

/* ------------------------------------------------------------- cleanup -- */
fclose($sock);
usleep(400000);
foreach ([$unitId, $unitId2] as $id) {
    foreach (['messages', 'trips', 'events', 'geofence_events', 'commands', 'sensors'] as $t) {
        db()->prepare("DELETE FROM $t WHERE unit_id = ?")->execute([$id]);
    }
    db()->prepare('DELETE FROM units WHERE id = ?')->execute([$id]);
}
db()->prepare('DELETE FROM device_sessions WHERE unique_id IN (?, ?)')->execute([$uid, $uid2]);
if (!$keep && $tracker) {
    tracker_stop($tracker);
    echo "  tracker stopped, test fixtures removed\n";
} else {
    echo "  --keep given: the tracker on port $port is still running\n";
}
exit($fail === 0 ? 0 : 1);
