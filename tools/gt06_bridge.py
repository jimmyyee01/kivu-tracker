#!/usr/bin/env python3
"""GT06 -> KIVU Tracker bridge: ACKs GT06 logins, decodes fixes, POSTs /api/ingest."""
import json, logging, os, socket, struct, subprocess, threading, time, urllib.request

HOST, PORT = "0.0.0.0", 20332
INGEST = "http://127.0.0.1:8099/api/ingest"
DBH, DBN, DBU, DBP = "127.0.0.1", "kivu_tracker", "root", "CHANGE_ME_DB_STRONG"
GPS_TYPES = {0x10, 0x11, 0x12, 0x16, 0x1A, 0x22, 0x25, 0x26, 0x27, 0x2D, 0x31, 0x32, 0x37}

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("gt06")

def crc16_x25(data):
    crc = 0xFFFF
    for b in data:
        crc ^= b
        for _ in range(8):
            crc = ((crc >> 1) ^ 0x8408) if crc & 1 else (crc >> 1)
    return (crc ^ 0xFFFF) & 0xFFFF


def _frame_start(buf):
    hits = [i for i in (buf.find(b"\x78\x78"), buf.find(b"\x79\x79")) if i >= 0]
    return min(hits) if hits else -1


def _frame_total(buf):
    if buf[:2] == b"\x79\x79":
        if len(buf) < 4:
            return None
        return (buf[2] << 8 | buf[3]) + 6
    if len(buf) < 3:
        return None
    return buf[2] + 5


def load_units():
    cmd = ["mysql", "-h" + DBH, "-u" + DBU, "-p" + DBP, DBN, "-N", "-B",
           "-e", "SELECT unique_id, device_password FROM units WHERE enabled=1"]
    out = subprocess.run(cmd, capture_output=True, text=True).stdout
    return [(p[0].strip(), p[1].strip()) for p in
            (l.split("\t") for l in out.splitlines()) if len(p) >= 2]

def bcd_digits(b):
    s = ""
    for x in b:
        hi, lo = x >> 4, x & 0x0F
        if hi > 9 or lo > 9:
            return None
        s += "%d%d" % (hi, lo)
    return s

def match_unit(digits, rows):
    """Exact match first, then the LONGEST prefix match."""
    for uid, pw in rows:
        if digits == uid:
            return uid, pw
    best = None
    for uid, pw in rows:
        if uid and (digits.startswith(uid) or uid.startswith(digits)):
            if best is None or len(uid) > len(best[0]):
                best = (uid, pw)
    if best:
        return best
    st = lambda s: s.lstrip("0")
    for uid, pw in rows:
        if st(uid) and st(uid) == st(digits):
            return uid, pw
    return None, None

def ack(msg_type, serial):
    body = bytes([0x05, msg_type]) + serial
    return b"\x78\x78" + body + struct.pack(">H", crc16_x25(body)) + b"\x0d\x0a"

def decode_gps(content):
    layouts = [(0, 7), (0, 6), (2, 8)]      # (datetime offset, lat offset)
    for dt, lo in layouts:
        if len(content) < lo + 8:
            continue
        yy, mm, dd, hh, mi, ss = content[dt:dt + 6]
        if not (1 <= mm <= 12 and 1 <= dd <= 31 and hh <= 23 and mi <= 59 and ss <= 59):
            continue
        lat_i, lon_i = struct.unpack('>II', content[lo:lo + 8])
        lat, lon = lat_i / 60.0 / 30000.0, lon_i / 60.0 / 30000.0
        if abs(lat) > 90 or abs(lon) > 180 or (lat == 0 and lon == 0):
            continue
        gps = content[lo - 1] if lo >= 1 else 0
        speed = content[lo + 8] if len(content) > lo + 8 else 0
        course = content[lo + 9] if len(content) > lo + 9 else 0
        return {"ts": "20%02d-%02d-%02d %02d:%02d:%02d" % (yy, mm, dd, hh, mi, ss),
                "lat": round(lat, 6), "lon": round(lon, 6), "speed": speed,
                "course": course, "sats": gps & 0x0F,
                "params": {"gps_info": gps}}
    return None

def ingest(uid, pw, point):
    body = json.dumps({"unique_id": uid, "password": pw, "points": [point]}).encode()
    req = urllib.request.Request(INGEST, data=body, headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=8) as r:
            return r.read().decode()[:400]
    except Exception as e:
        return "ERROR " + str(e)


STATE_FILE = "/home/administrator/kivu-tracker/data/bridge_state.json"
ENGINE_OFF_AFTER_S = 300


def ignition_for(unit_key, speed):
    """Motion-derived ignition: moving -> 1; stopped < 5 min -> 1 (idle);
    stopped longer -> 0 (parked). Swap for a real ACC bit once identified."""
    try:
        st = json.load(open(STATE_FILE))
    except Exception:
        st = {}
    now = time.time()
    rec = st.get(unit_key, {})
    if speed > 0:
        rec["last_move"] = now
        ign = 1
    else:
        ign = 1 if (now - float(rec.get("last_move", 0))) < ENGINE_OFF_AFTER_S else 0
    rec["ign"] = ign
    rec["seen"] = int(now)
    st[unit_key] = rec
    try:
        json.dump(st, open(STATE_FILE, "w"))
    except Exception:
        pass
    return ign



def handle_ascii(line, sess, conn, addr):
    """EasyTrack / ET06 ASCII frames: *ET,<imei>,<CMD>,<fields>#"""
    txt = line.decode("latin-1", "replace").strip().rstrip("#").strip()
    if not txt:
        return
    log.info("ET %s", txt)
    if not txt.startswith("*"):
        return
    parts = txt[1:].split(",")
    if len(parts) < 3:
        return
    imei, cmd = parts[1].strip(), parts[2].strip()
    uid, pw = match_unit(str(imei), load_units())
    if cmd == "JZ":
        log.info("ET LBS imei=%s -> unit=%s (no position)", imei, uid or "NO MATCH")
        return
    if len(parts) < 11 or parts[3] not in ("A", "V"):
        log.info("ET cmd=%s imei=%s -> unit=%s (no position fields)", cmd, imei, uid or "NO MATCH")
        return
    try:
        latf, lonf = parts[6].strip('"'), parts[7].strip('"')
        lat = int(latf[1:], 16) / 600000.0
        lon = int(lonf[1:], 16) / 600000.0
        if int(latf[0], 16) & 1:
            lat = -lat
        if int(lonf[0], 16) & 8:
            lon = -lon
        speed = int(parts[8], 16) / 100.0
        course = int(parts[9], 16) / 100.0
        status = int(parts[10].strip('"'), 16)
        ign = 1 if (status & 0x00800000) else 0
        dev_time = parts[4] + " " + parts[5]
        fix = parts[3]
    except Exception as e:
        log.warning("ET parse error: %s | %s", e, txt)
        return
    if not uid:
        log.warning("ET LOGIN imei=%s -> NO MATCH", imei)
        return
    if not (abs(lat) <= 90 and abs(lon) <= 180) or (lat == 0 and lon == 0):
        log.warning("ET bad coords %s,%s | %s", lat, lon, txt)
        return
    log.info("ET LOGIN imei=%s -> unit=%s cmd=%s fix=%s ign=%d", imei, uid, cmd, fix, ign)
    pt = {"ts": time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime()),
          "lat": round(lat, 6), "lon": round(lon, 6),
          "speed": round(speed, 1), "course": round(course, 1),
          "params": {"ign": ign, "et_fix": fix, "et_status": hex(status),
                     "et_time": dev_time, "et_cmd": cmd}}
    res = ingest(uid, pw, pt)
    log.info("ET INGEST %s (%s, %s) -> %s", pt["ts"], pt["lat"], pt["lon"], res)


def handle(conn, addr):
    conn.settimeout(900)
    buf, sess = b"", {}
    log.info("connect from %s:%s", addr[0], addr[1])
    try:
        while True:
            data = conn.recv(4096)
            if not data:
                break
            buf += data
            log.info("RAW %d: %s", len(data), data.hex())
            while True:
                if buf[:1] == b"*":
                    nl = buf.find(b"\n")
                    if nl < 0:
                        break
                    handle_ascii(buf[:nl].rstrip(b"\r"), sess, conn, addr)
                    buf = buf[nl + 1:]
                    continue
                i = _frame_start(buf)
                if i < 0:
                    j = buf.find(b"*")
                    if j > 0:
                        log.warning("ascii resync dropped %d bytes: %s", j, buf[:j].hex())
                        buf = buf[j:]
                        continue
                    buf = buf[-1:]
                    break
                if i > 0:
                    log.warning("resync dropped %d bytes: %s", i, buf[:i].hex())
                    buf = buf[i:]
                if len(buf) < 3:
                    break
                total = _frame_total(buf)
                if total is None:
                    break
                if not (8 <= total <= 4096):
                    log.warning("bad length 0x%02X: %s", buf[2], buf[:16].hex())
                    buf = buf[1:]
                    continue
                if len(buf) < total:
                    break
                frame, buf = buf[:total], buf[total:]
                if frame[-2:] != b"\x0d\x0a":
                    log.warning("bad stop: %s", frame.hex())
                    continue
                got = struct.unpack(">H", frame[-4:-2])[0]
                calc = crc16_x25(frame[2:-4])
                if got != calc:
                    log.warning("CRC calc=%04X got=%04X: %s", calc, got, frame.hex())
                    continue
                ext = frame[:2] == b"\x79\x79"
                mtype = frame[4] if ext else frame[3]
                content = frame[5:-4] if ext else frame[4:-4]
                serial = content[-2:] if len(content) >= 2 else b"\x00\x00"
                log.info("%s type=0x%02X len=%d hex=%s", "EXT" if ext else "STD", mtype, len(frame), frame.hex())
                if mtype == 0x01:
                    digits = bcd_digits(content[:8]) if len(content) >= 8 else None
                    uid, pw = match_unit(digits or "", load_units())
                    sess["uid"], sess["pw"] = uid, pw
                    log.info("LOGIN imei=%s -> unit=%s", digits, uid or "NO MATCH")
                    conn.sendall(ack(mtype, serial))
                    continue
                if not ext:
                    conn.sendall(ack(mtype, serial))
                if mtype in GPS_TYPES:
                    pt = decode_gps(content)
                    if pt and sess.get("uid"):
                        pt.setdefault("params", {})["ign"] = ignition_for(
                            sess["uid"], float(pt.get("speed", 0)))
                        res = ingest(sess["uid"], sess["pw"], pt)
                        log.info("INGEST %s %s -> %s", pt["ts"], (pt["lat"], pt["lon"]), res)
                    elif not pt:
                        log.warning("NEED_DECODE type=0x%02X content=%s", mtype, content.hex())
    except Exception as e:
        log.warning("connection error %s: %s", addr, e)
    finally:
        conn.close()
        log.info("disconnect %s:%s", addr[0], addr[1])

def main():
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind((HOST, PORT))
    srv.listen(16)
    log.info("GT06 bridge listening on %s:%d (KIVU IPS daemon on 20333)", HOST, PORT)
    while True:
        c, a = srv.accept()
        threading.Thread(target=handle, args=(c, a), daemon=True).start()

main()
