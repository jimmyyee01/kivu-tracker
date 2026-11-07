#!/usr/bin/env node
/* =====================================================================
   KIVU Tracker — tools/map-providers-check.js
   Verifies the monitor-page map provider list in public/js/common.js.

   For every entry in MAP_STYLES it:
     1. checks the record (id / label / maxZoom / {z},{x},{y} tokens),
     2. checks the legacy ids still resolve (saved "kivu_map" values),
     3. fetches one real tile and expects an image back.

   Only the tile providers need the network — the check is read-only and
   never touches the database or the API.

       node tools/map-providers-check.js
       node tools/map-providers-check.js --lat -1.2921 --lon 36.8219 --zoom 12

   Needs Node (this machine has a portable copy under
   %LOCALAPPDATA%\Cline\nodejs-portable). Exit code 1 if a check fails.
   ===================================================================== */
'use strict';

const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
function arg(name, fallback) {
  const i = argv.indexOf(name);
  return i >= 0 && argv[i + 1] !== undefined ? Number(argv[i + 1]) : fallback;
}
/* Default probe point: Nairobi (the fleet's home city). */
const LAT = arg('--lat', -1.2921);
const LON = arg('--lon', 36.8219);
const ZOOM = arg('--zoom', 12);

/* "US only" providers (USGS) 404 outside the USA — probe those in the US. */
const US_ONLY = ['usgs'];
const US_LAT = 38.8977, US_LON = -77.0365;

const commonPath = path.join(__dirname, '..', 'public', 'js', 'common.js');
const src = fs.readFileSync(commonPath, 'utf8');
const block = src.match(/const MAP_STYLES = (\[[\s\S]*?\n\]);/);
if (!block) {
  console.error('FAIL  MAP_STYLES not found in ' + commonPath);
  process.exit(1);
}
const MAP_STYLES = eval(block[1]);   // eslint-disable-line no-eval

const LEGACY = ['default', 'osm', 'topo', 'hot', 'cyclosm', 'carto_light', 'carto_dark'];
const ids = new Set();
const problems = [];
MAP_STYLES.forEach(s => {
  if (!s.id || !s.label || s.url === undefined) problems.push('bad record: ' + JSON.stringify(s));
  if (ids.has(s.id)) problems.push('duplicate id: ' + s.id);
  ids.add(s.id);
  if (!s.maxZoom) problems.push(s.id + ': missing maxZoom');
  if (s.url) ['{z}', '{x}', '{y}'].forEach(t => {
    if (s.url.indexOf(t) < 0) problems.push(s.id + ': url missing ' + t);
  });
});
LEGACY.forEach(id => { if (!ids.has(id)) problems.push('legacy id lost: ' + id); });

console.log('styles: ' + MAP_STYLES.length + '  (' + problems.length + ' structure problem(s))');
problems.forEach(p => console.log('  FAIL  ' + p));

/* z/x/y for a lat/lon at a given zoom (web-mercator, 256 px tiles) */
function tileAt(lat, lon, z) {
  const n = Math.pow(2, z);
  const x = Math.floor((lon + 180) / 360 * n);
  const s = Math.max(-0.99999, Math.min(0.99999, Math.sin(lat * Math.PI / 180)));
  const y = Math.floor((0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * n);
  return { x: x, y: y };
}
function fill(url, lat, lon, z) {
  const t = tileAt(lat, lon, z);
  return url.replace('{s}', 'a').replace('{z}', z)
    .replace('{x}', t.x).replace('{y}', t.y).replace('{r}', '');
}

(async () => {
  let ok = 0, bad = problems.length;
  for (const s of MAP_STYLES) {
    if (!s.url) { console.log('  SKIP  ' + s.id.padEnd(15) + 'platform default'); continue; }
    const us = US_ONLY.indexOf(s.id) >= 0;
    const z = Math.min(s.maxZoom || 19, ZOOM);
    const url = fill(s.url, us ? US_LAT : LAT, us ? US_LON : LON, z);
    try {
      const r = await fetch(url, {
        headers: {
          'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) KIVU-Tracker-check/1.0',
          Referer: 'https://kivu-tracker.app/'      /* providers that block hotlinking */
        },
        signal: AbortSignal.timeout(10000)
      });
      const ct = r.headers.get('content-type') || '-';
      const good = r.ok && /image/.test(ct);
      if (good) ok++; else bad++;
      console.log('  ' + (good ? 'PASS' : 'FAIL') + '  ' + s.id.padEnd(15) + r.status + '  ' + ct
        + (us ? '  (US probe)' : ''));
    } catch (e) {
      bad++;
      console.log('  FAIL  ' + s.id.padEnd(15) + 'ERR  ' + e.message);
    }
  }
  console.log('tiles: ' + ok + ' ok, ' + bad + ' problem(s)');
  process.exit(bad ? 1 : 0);
})();
