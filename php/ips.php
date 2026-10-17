<?php
/* =====================================================================
   KIVU Tracker — php/ips.php
   Wialon IPS 1.1 (text) protocol codec, shared by the TCP server
   (php/tracker.php), the device simulator (tools/simulator.php) and the
   HTTP ingest endpoint.

   Packets (CRLF terminated):
     #L#login;password            device -> server   -> #AL#1 / #AL#0
     #D#date;time;lat1;lat2;lon1;lon2;speed;course;alt;sats;params
                                  device -> server   -> #AD#1 / #AD#0
     #D#date;time;lat;lon;speed;course;alt;sats;params   (decimal-degrees
                                  variant, accepted for convenience)
     #SD#date;time;text           device -> server   -> #ASD#1  (SMS-like text)
     #P#date;time                 device -> server   -> #AP#1   (keep-alive)
     #B#... black-box block       device -> server   -> #AB#1
     #M#date;time;text            server -> device   -> #AM#1 / #AM#0
   ===================================================================== */
declare(strict_types=1);

const IPS_CRLF = "\r\n";

/* Splits a raw packet into its '#'-delimited fields. */
function ips_split(string $packet): array {
    $p = trim($packet, "\r\n");
    if ($p === '') return [];
    if ($p[0] === '#') $p = substr($p, 1);
    return explode('#', $p);
}
function ips_num($v): ?float {
    $v = trim((string)$v);
    if ($v === '' || !is_numeric($v)) return null;
    return (float)$v;
}

/* Parses one packet. Returns ['type' => 'D'|'L'|'SD'|'P'|'B'|'M'|'?'] + fields. */
function ips_parse_packet(string $packet, string $dataType = 'D'): array {
    $parts = ips_split($packet);
    $type = $parts[0] ?? '';
    if ($type === '') return ['type' => '?', 'raw' => $packet];
    $rest = array_slice($parts, 1);
    /* acknowledgements sent by the device (#AM#1 ack a message, #AL#1 login ok...) */
    if (strlen($type) === 2 && $type[0] === 'A' && ctype_alpha($type[1])) {
        return ['type' => 'ACK', 'of' => strtoupper($type[1]),
            'ok' => trim((string)($rest[0] ?? '1')) === '1', 'raw' => $packet];
    }
    switch ($type) {
        case 'L':
            /* #L#<login>;<password> — the credentials share the field */
            $creds = explode(';', $rest[0] ?? '', 2);
            return ['type' => 'L', 'login' => trim($creds[0] ?? ''), 'password' => trim($creds[1] ?? ''),
                'raw' => $packet];
        case 'P':
            return ['type' => 'P', 'date' => $rest[0] ?? '', 'time' => $rest[1] ?? '', 'raw' => $packet];
        case 'SD':
        case 'M':
            /* #SD#<date>;<time>;<text> — the three parts share one '#' field */
            $f = explode(';', $rest[0] ?? '', 3);
            return ['type' => $type, 'date' => trim($f[0] ?? ''), 'time' => trim($f[1] ?? ''),
                'text' => (string)($f[2] ?? ''), 'raw' => $packet];
        case 'B':                                  // black box block: same payload as #D#
            $data = ips_parse_data(implode(';', $rest), 'D');
            $data['type'] = 'B';                   // keep the wire type so the right ack is returned
            return $data;
        case 'D':
            return ips_parse_data(implode(';', $rest), $dataType);
        default:
            return ['type' => '?', 'raw' => $packet];
    }
}

/* Parses the "date;time;lat;lon;speed;course;alt;sats;[params]" payload. */
function ips_parse_data(string $payload, string $type = 'D'): array {
    $f = explode(';', $payload);
    $count = count($f);
    if ($count < 6) return ['type' => '?', 'raw' => $payload, 'error' => 'short packet'];

    $date = trim($f[0]); $time = trim($f[1]);
    $ts = now_dt();
    if (preg_match('/^\d{8}$/', $date) && preg_match('/^\d{6}$/', $time)) {
        $ts = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ' '
            . substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':' . substr($time, 4, 2);
    }

    /* 10+ fields = degrees + minutes (standard IPS); 8 fields = decimal degrees */
    if ($count >= 10) {
        $latDeg = ips_num($f[2]) ?? 0.0; $latMin = ips_num($f[3]) ?? 0.0;
        $lonDeg = ips_num($f[4]) ?? 0.0; $lonMin = ips_num($f[5]) ?? 0.0;
        $lat = $latDeg < 0 ? $latDeg - $latMin / 60 : $latDeg + $latMin / 60;
        $lon = $lonDeg < 0 ? $lonDeg - $lonMin / 60 : $lonDeg + $lonMin / 60;
        $speed = ips_num($f[6]) ?? 0.0; $course = ips_num($f[7]) ?? 0.0;
        $alt = ips_num($f[8]) ?? 0.0; $sats = (int)(ips_num($f[9]) ?? 0);
        $paramsRaw = $count >= 11 ? implode(';', array_slice($f, 10)) : '';
    } else {
        $lat = ips_num($f[2]) ?? 0.0; $lon = ips_num($f[3]) ?? 0.0;
        $speed = ips_num($f[4]) ?? 0.0; $course = ips_num($f[5]) ?? 0.0;
        $alt = ips_num($f[6]) ?? 0.0; $sats = (int)(ips_num($f[7]) ?? 0);
        $paramsRaw = $count >= 9 ? implode(';', array_slice($f, 8)) : '';
    }

    $out = ['type' => 'D', 'ts' => $ts, 'lat' => $lat, 'lon' => $lon, 'speed' => $speed,
        'course' => $course, 'alt' => $alt, 'sats' => $sats, 'params' => ips_parse_params($paramsRaw),
        'raw' => $payload, 'source' => 'ips'];
    if ($type === 'json') $out['source'] = 'api';
    return $out;
}

/* "ign:1,hdop:1.2,fuel:120.5" -> ['ign' => '1', 'hdop' => '1.2', ...] */
function ips_parse_params(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    $params = [];
    foreach (explode(',', $raw) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        $kv = explode(':', $chunk, 2);
        if (count($kv) === 2) $params[trim($kv[0])] = trim($kv[1]);
        else $params[$chunk] = '1';
    }
    return $params;
}

/* ------------------------------------------------------------- responses */
function ips_response(string $type, bool $ok = true): string {
    $map = ['L' => 'AL', 'D' => 'AD', 'SD' => 'ASD', 'P' => 'AP', 'B' => 'AB', 'M' => 'AM'];
    $code = $type === 'P' ? '1' : ($ok ? '1' : '0');
    return '#' . ($map[$type] ?? 'A') . '#' . $code . IPS_CRLF;
}

/* -------------------------------------------------------------- builders */
function ips_stamp($ts = null): array {
    $t = $ts === null ? time() : (is_numeric($ts) ? (int)$ts : (int)strtotime((string)$ts));
    return [date('Ymd', $t), date('His', $t)];
}
function ips_build_login(string $login, string $password = ''): string {
    return '#L#' . $login . ';' . $password . IPS_CRLF;
}
/* $format 'dm' = degrees+minutes (real devices), 'dec' = decimal degrees. */
function ips_build_data($ts, float $lat, float $lon, float $speed = 0, float $course = 0,
                        float $alt = 0, int $sats = 9, array $params = [], string $format = 'dm'): string {
    [$d, $t] = ips_stamp($ts);
    if ($format === 'dec') {
        $body = sprintf('%s;%s;%.6f;%.6f;%d;%d;%d;%d', $d, $t, $lat, $lon, (int)round($speed), (int)round($course),
            (int)round($alt), $sats);
    } else {
        $latDeg = (int)floor(abs($lat)); $latMin = (abs($lat) - $latDeg) * 60;
        $lonDeg = (int)floor(abs($lon)); $lonMin = (abs($lon) - $lonDeg) * 60;
        if ($lat < 0) $latDeg = -$latDeg;
        if ($lon < 0) $lonDeg = -$lonDeg;
        $body = sprintf('%s;%s;%d;%.4f;%d;%.4f;%d;%d;%d;%d', $d, $t, $latDeg, $latMin, $lonDeg, $lonMin,
            (int)round($speed), (int)round($course), (int)round($alt), $sats);
    }
    $pairs = [];
    foreach ($params as $k => $v) $pairs[] = $k . ':' . $v;
    if ($pairs) $body .= ';' . implode(',', $pairs);
    return '#D#' . $body . IPS_CRLF;
}
function ips_build_ping($ts = null): string {
    [$d, $t] = ips_stamp($ts);
    return '#P#' . $d . ';' . $t . IPS_CRLF;
}
function ips_build_short_data(string $text, $ts = null): string {
    [$d, $t] = ips_stamp($ts);
    return '#SD#' . $d . ';' . $t . ';' . $text . IPS_CRLF;
}
/* Server -> device message (carries custom messages and control commands). */
function ips_build_message(string $text, $ts = null): string {
    [$d, $t] = ips_stamp($ts);
    return '#M#' . $d . ';' . $t . ';' . $text . IPS_CRLF;
}
/* Turns a queued command row into the text sent to the device. */
function ips_command_text(array $cmd): string {
    $p = jdec($cmd['payload_json'] ?? null, []);
    switch ($cmd['type']) {
        case 'custom_message': return (string)($p['text'] ?? 'message');
        case 'engine_block':   return (string)($p['text'] ?? 'engine_block');
        case 'engine_unblock': return (string)($p['text'] ?? 'engine_unblock');
        case 'reboot':         return (string)($p['text'] ?? 'reboot');
        case 'query_position': return (string)($p['text'] ?? 'query_position');
        case 'set_interval':   return (string)($p['text'] ?? ('set_interval ' . ($p['seconds'] ?? 60)));
        default:               return (string)($p['text'] ?? $cmd['type']);
    }
}
