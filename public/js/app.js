/* =====================================================================
   KIVU Tracker — public/js/app.js
   Monitoring console: unit tree, live map, track playback, trips,
   events, raw messages and device commands.
   ===================================================================== */
'use strict';

const App = {
  map: null,
  units: [],
  selected: null,
  follow: true,
  showFences: true,
  timer: null,
  liveEvery: 15,
  track: { points: [], playing: false, idx: 0, raf: null },
  range: null
};

document.addEventListener('DOMContentLoaded', async function () {
  const meta = await boot('app');
  if (!meta) return;

  App.map = MapCore.create($('#map'), {
    lat: meta.defaults.lat, lon: meta.defaults.lon, zoom: meta.defaults.zoom, tiles: meta.map_tiles
  });
  /* free map styles: platform default unless the user picked another layer */
  applyMapStyle(App.map);
  const toolbar = document.querySelector('.map-toolbar');
  if (toolbar && $('#btnFitAll')) {
    toolbar.appendChild(mapStyleSelect(url => {
      if (App.map && typeof App.map.setTiles === 'function') App.map.setTiles(url);
    }));
  }
  $('#mapBackend').textContent = 'map: ' + App.map.backend;
  App.map.on('click', p => {
    $('#mapStatus').innerHTML = '<b>Coordinates</b> ' + p.lat.toFixed(6) + ', ' + p.lon.toFixed(6);
  });

  initTabs($('.inspector'));
  initToolbar();
  buildInfoPane();
  buildTrackPane();
  buildTripsPane();
  buildEventsPane();
  buildMessagesPane();
  buildCommandsPane();

  await App.refresh();
  startLive();
  window.addEventListener('resize', () => App.map.invalidateSize());
});

/* ------------------------------------------------------------- live data */
function startLive() {
  if (App.timer) clearInterval(App.timer);
  if (App.liveEvery > 0) App.timer = setInterval(() => App.refresh(true), App.liveEvery * 1000);
}
App.refresh = async function () {
  try {
    const units = await loadUnitsLive({ q: $('#unitSearch').value, status: $('#statusFilter').value });
    App.units = units;
    const sel = units.find(u => App.selected && String(u.id) === String(App.selected.id)) || units[0] || null;
    App.selected = sel;
    renderUnitTree($('#unitTree'), {
      units: units,
      selected: sel ? sel.id : '',
      onSelect: u => App.selectUnit(u)
    });
    $('#unitCount').textContent = units.length;
    App.map.setUnits(units.filter(u => u.lat !== null), u => App.selectUnit(u));
    if (App.showFences) App.drawFences();
    renderFleetStats(units);
    if (sel) {
      renderInspectorStatus(sel);
      $('#inspectorTitle').textContent = sel.name;
    }
    if (App.follow && sel && sel.lat !== null && !App.track.points.length) {
      App.map.setView(sel.lat, sel.lon, Math.max(12, App.map.getView().zoom));
    }
    $('#mapStatus').innerHTML = (sel ? '<b>' + h(sel.name) + '</b> — ' + h(sel.address || 'no position')
      : 'No units in this account') + ' &middot; updated ' + new Date().toLocaleTimeString();
  } catch (e) { /* toast already shown */ }
};

App.selectUnit = function (u) {
  App.selected = u;
  State.selectedUnit = u.id;
  store.set('selectedUnit', u.id);
  $$('#unitTree .node').forEach(n => n.classList.toggle('active', n.dataset.unit === String(u.id)));
  $('#inspectorTitle').textContent = u.name;
  renderInspectorStatus(u);
  renderInfoPane(u);
  if (u.lat !== null) App.map.setView(u.lat, u.lon, Math.max(13, App.map.getView().zoom));
  App.loadEvents();
  App.loadMessages();
  App.loadCommands();
  loadTrips(false);
};

/* ------------------------------------------------------------ sidebar UI */
function renderFleetStats(units) {
  const counts = { moving: 0, idle: 0, parked: 0, offline: 0, never: 0 };
  units.forEach(u => { counts[u.status] = (counts[u.status] || 0) + 1; });
  $('#fleetStats').innerHTML = Object.keys(counts).map(k =>
    '<span class="chip" style="margin:1px">' + '<span class="dot" style="background:' + STATUS[k].color + '"></span> '
    + counts[k] + ' ' + h(STATUS[k].label.split(' ')[0]) + '</span>').join('');
}
function renderInspectorStatus(u) {
  const st = STATUS[u.status] || STATUS.never;
  const chip = $('#inspectorStatus');
  chip.textContent = st.label;
  chip.className = 'chip right ' + (u.status === 'moving' ? 'ok' : u.status === 'idle' ? 'warn'
    : u.status === 'offline' ? 'danger' : 'info');
}
function kv(rows) {
  return '<table class="data" style="font-size:12.5px">' + rows.map(r =>
    '<tr><td class="muted nowrap">' + h(r[0]) + '</td><td>' + r[1] + '</td></tr>').join('') + '</table>';
}

/* --------------------------------------------------------------- toolbar */
function initToolbar() {
  $('#unitSearch').addEventListener('input', debounce(() => App.refresh(), 300));
  $('#statusFilter').addEventListener('change', () => App.refresh());
  $('#refreshBtn').addEventListener('click', () => App.refresh());
  $('#btnFitAll').addEventListener('click', () => {
    const pts = App.units.filter(u => u.lat !== null).map(u => ({ lat: u.lat, lon: u.lon }));
    if (pts.length) App.map.fitPoints(pts);
  });
  $('#btnFollow').addEventListener('click', () => {
    App.follow = !App.follow;
    $('#btnFollow').textContent = 'Follow: ' + (App.follow ? 'on' : 'off');
    $('#btnFollow').classList.toggle('active', App.follow);
  });
  $('#btnTrack').addEventListener('click', () => {
    if (App.track.points.length) App.clearTrack(); else App.loadTrack();
  });
  $('#btnFences').addEventListener('click', () => {
    App.showFences = !App.showFences;
    $('#btnFences').classList.toggle('active', App.showFences);
    if (App.showFences) App.drawFences(); else App.map.showGeofences([]);
  });
  $('#btnLive').addEventListener('click', () => {
    const steps = [5, 15, 30, 60, 0];
    const next = steps[(steps.indexOf(App.liveEvery) + 1) % steps.length];
    App.liveEvery = next;
    $('#btnLive').textContent = next ? ('Live ' + next + 's') : 'Live off';
    $('#btnLive').classList.toggle('active', next > 0);
    startLive();
  });
}
App.drawFences = async function () {
  try {
    const data = await API.get('/api/geofences?enabled=1', { silent: true });
    App.map.showGeofences(data.geofences || []);
  } catch (e) { /* ignore */ }
};

/* -------------------------------------------------------------- info pane */
function buildInfoPane() {
  $('#paneInfo').innerHTML = '<p class="muted">Select a unit to see its telemetry.</p>';
}
async function renderInfoPane(unit) {
  const box = $('#paneInfo');
  box.innerHTML = '<p class="muted">Loading unit…</p>';
  let data = null;
  try { data = await API.get('/api/units/' + encodeURIComponent(unit.id)); } catch (e) { return; }
  const u = Object.assign({}, unit, data.unit);
  const rows = [
    ['Status', '<span class="dot" style="background:' + (u.color || '#888') + '"></span> ' + h(u.status_label || '')],
    ['Speed', fmt.speed(u.speed)],
    ['Ignition', u.ignition === null || u.ignition === undefined ? '—' : (u.ignition ? 'ON' : 'OFF')],
    ['Satellites', u.sats === null || u.sats === undefined ? '—' : u.sats],
    ['Fuel level', u.fuel === null || u.fuel === undefined ? '—' : fmt.liters(u.fuel)],
    ['Board voltage', u.voltage === null || u.voltage === undefined ? '—' : fmt.num(u.voltage, 1) + ' V'],
    ['Altitude', u.altitude === null || u.altitude === undefined ? '—' : fmt.num(u.altitude, 0) + ' m'],
    ['Location', h(u.address || '')],
    ['Coordinates', u.lat === null || u.lat === undefined ? '—' : Number(u.lat).toFixed(6) + ', ' + Number(u.lon).toFixed(6)],
    ['Last data', h(fmt.dt(u.last_time)) + ' <span class="dim">(' + h(fmt.ago(u.last_time)) + ')</span>'],
    ['Odometer', fmt.km(u.mileage)],
    ['Engine hours', fmt.hours(u.engine_hours)],
    ['Driver', h(u.driver_name || '—')],
    ['Plate / model', h((u.plate || '—') + ' / ' + (u.model || '—'))],
    ['Group', h(u.group_name || '—')],
    ['Tags', h(u.tags || '—')],
    ['Device ID (IMEI)', '<span class="mono">' + h(u.unique_id) + '</span>'],
    ['Device password', '<span class="mono">' + h(u.device_password || '') + '</span>'],
    ['Speed limit', u.speed_limit ? u.speed_limit + ' km/h' : '—'],
    ['Fuel rate', u.fuel_rate ? u.fuel_rate + ' L/100 km' : '—'],
    ['Last packet from', '<span class="mono">' + h(u.last_ip || '—') + '</span>']
  ];
  let html = kv(rows);
  if ((data.sensors || []).length) {
    html += '<h3 style="margin-top:14px">Sensors</h3>' + kv(data.sensors.map(s =>
      [s.name, '<span class="mono">' + h(s.param) + '</span>'
        + (s.units ? ' <span class="dim">(' + h(s.units) + ')</span>' : '') + ' &times;' + h(s.coefficient)]));
  }
  if ((data.maintenance || []).length) {
    html += '<h3 style="margin-top:14px">Service intervals</h3>' + kv(data.maintenance.map(m =>
      [m.name, (m.status && m.status.due ? '<span class="chip danger">DUE</span> ' : '')
        + h(m.status ? m.status.text : '')]));
  }
  html += '<div class="row wrap" style="margin-top:14px">'
    + '<button class="btn sm" id="infoTrack">Show track</button>'
    + '<button class="btn sm" id="infoTrips">Detect trips</button>'
    + '<a class="btn sm" href="/reports?unit=' + encodeURIComponent(u.id) + '">Reports</a></div>';
  box.innerHTML = html;
  const t = $('#infoTrack');
  if (t) t.addEventListener('click', () => App.loadTrack());
  const d = $('#infoTrips');
  if (d) d.addEventListener('click', () => loadTrips(true));
}

/* ------------------------------------------------------------- track pane */
function buildTrackPane() {
  const presets = rangePresets();
  $('#paneTrack').innerHTML =
    '<div class="field"><label>Time range</label><div class="btn-group" id="rangeGroup">'
    + presets.map(p => '<button class="btn sm' + (p.id === '24h' ? ' active' : '') + '" data-preset="'
      + p.id + '">' + h(p.label) + '</button>').join('') + '</div></div>'
    + '<div class="field"><label>From</label><input type="datetime-local" id="trackFrom"></div>'
    + '<div class="field"><label>To</label><input type="datetime-local" id="trackTo"></div>'
    + '<div class="row wrap"><button class="btn primary sm" id="btnLoadTrack">Load track</button>'
    + '<button class="btn sm" id="btnClearTrack">Clear</button>'
    + '<button class="btn sm" id="btnFitTrack">Fit view</button></div>'
    + '<div id="trackSummary" style="margin-top:12px"></div>'
    + '<div id="trackChart" class="charts" style="margin-top:12px"></div>';

  const from = new Date(Date.now() - 86400000), to = new Date();
  $('#trackFrom').value = isoLocal(from);
  $('#trackTo').value = isoLocal(to);
  App.range = { from: isoLocal(from), to: isoLocal(to) };

  $$('#rangeGroup .btn').forEach(b => b.addEventListener('click', () => {
    const p = rangePresets().find(x => x.id === b.dataset.preset);
    if (!p) return;
    $$('#rangeGroup .btn').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
    $('#trackFrom').value = isoLocal(p.from);
    $('#trackTo').value = isoLocal(p.to);
    App.range = { from: isoLocal(p.from), to: isoLocal(p.to) };
    App.loadTrack();
  }));
  $('#btnLoadTrack').addEventListener('click', () => {
    App.range = { from: $('#trackFrom').value, to: $('#trackTo').value };
    App.loadTrack();
  });
  $('#btnClearTrack').addEventListener('click', () => App.clearTrack());
  $('#btnFitTrack').addEventListener('click', () => App.fitTrack());
  $('#playBtn').addEventListener('click', () => App.togglePlay());
  $('#hideTimeline').addEventListener('click', () => {
    $('#timeline').classList.add('hidden');
    App.stopPlay();
    App.map.setTrackPlayer(null);
  });
  $('#timeSlider').addEventListener('input', e => App.showAt(Number(e.target.value)));
}
App.rangeQuery = function () {
  const r = App.range || { from: isoLocal(new Date(Date.now() - 86400000)), to: isoLocal(new Date()) };
  return (r.from ? '&from=' + encodeURIComponent(r.from.replace('T', ' ')) : '')
    + (r.to ? '&to=' + encodeURIComponent(r.to.replace('T', ' ')) : '');
};
App.loadTrack = async function () {
  if (!App.selected) { toast('Select a unit first', 'warn'); return; }
  try {
    const data = await API.get('/api/units/' + encodeURIComponent(App.selected.id)
      + '/messages?limit=20000' + App.rangeQuery());
    App.track.points = (data.points || []).map(p => ({ ts: p.ts, lat: p.lat, lon: p.lon,
      speed: p.speed, ignition: p.ignition, fuel: p.fuel }));
    $('#btnTrack').classList.add('active');
    App.map.setTrack(App.track.points, { color: '#2f9cf4', weight: 3 });
    App.fitTrack();
    renderTrackSummary(data);
    drawTrackChart(App.track.points);
    if (App.track.points.length) {
      $('#timeline').classList.remove('hidden');
      $('#timeSlider').max = String(App.track.points.length - 1);
      $('#timeSlider').value = '0';
      App.showAt(0);
    } else {
      toast('No data for this unit in the selected range', 'warn');
    }
  } catch (e) { /* toast shown */ }
};
App.clearTrack = function () {
  App.stopPlay();
  App.track.points = [];
  App.map.setTrack([]);
  App.map.setTrackPlayer(null);
  $('#btnTrack').classList.remove('active');
  $('#timeline').classList.add('hidden');
  $('#trackSummary').innerHTML = '';
  $('#trackChart').innerHTML = '';
};
App.fitTrack = function () {
  if (App.track.points.length) App.map.fitPoints(App.track.points);
};

function renderTrackSummary(data) {
  const pts = data.points || [];
  if (!pts.length) { $('#trackSummary').innerHTML = '<p class="muted">No data in this range.</p>'; return; }
  let dist = 0, maxSpeed = 0;
  for (let i = 1; i < pts.length; i++) {
    dist += haversineKm(pts[i - 1].lat, pts[i - 1].lon, pts[i].lat, pts[i].lon);
    maxSpeed = Math.max(maxSpeed, pts[i].speed);
  }
  maxSpeed = Math.max(maxSpeed, pts[0].speed);
  const duration = (new Date(pts[pts.length - 1].ts) - new Date(pts[0].ts)) / 1000;
  $('#trackSummary').innerHTML = kv([
    ['Points', fmt.num(pts.length, 0)],
    ['Start', h(fmt.dt(pts[0].ts)) + ' · ' + h(data.start_address || '')],
    ['End', h(fmt.dt(pts[pts.length - 1].ts)) + ' · ' + h(data.end_address || '')],
    ['Duration', fmt.duration(duration)],
    ['Distance (raw track)', fmt.km(dist)],
    ['Max speed', fmt.speed(maxSpeed)]
  ]);
}
function drawTrackChart(points) {
  if (points.length < 3) { $('#trackChart').innerHTML = ''; return; }
  Charts.render($('#trackChart'), [
    { id: 'speed', title: 'Speed profile (km/h)', type: 'line',
      labels: points.map(p => fmt.dtShort(p.ts)),
      series: [{ name: 'km/h', data: points.map(p => Math.round(p.speed * 10) / 10) }] }
  ]);
}
function haversineKm(lat1, lon1, lat2, lon2) {
  const R = 6371, dLat = (lat2 - lat1) * Math.PI / 180, dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat / 2) ** 2
    + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
}
App.showAt = function (idx) {
  const p = App.track.points[idx];
  if (!p) return;
  App.track.idx = idx;
  App.map.setTrackPlayer(p);
  $('#timeSlider').value = String(idx);
  $('#timeLabel').textContent = fmt.dt(p.ts) + ' · ' + fmt.num(p.speed, 1) + ' km/h'
    + (p.ignition === null || p.ignition === undefined ? '' : ' · ign ' + (p.ignition ? 'ON' : 'OFF'));
  if (App.follow) App.map.setView(p.lat, p.lon, App.map.getView().zoom);
};
App.togglePlay = function () {
  if (App.track.playing) { App.stopPlay(); return; }
  if (!App.track.points.length) return;
  App.track.playing = true;
  $('#playBtn').textContent = '❚❚';
  const speed = Number($('#playSpeed').value) || 2;
  const tick = () => {
    if (!App.track.playing) return;
    const next = App.track.idx + Math.max(1, Math.round(speed));
    if (next >= App.track.points.length - 1) {
      App.showAt(App.track.points.length - 1);
      App.stopPlay();
      return;
    }
    App.showAt(next);
    App.track.raf = setTimeout(tick, 120);
  };
  App.track.raf = setTimeout(tick, 120);
};
App.stopPlay = function () {
  App.track.playing = false;
  if (App.track.raf) clearTimeout(App.track.raf);
  App.track.raf = null;
  const b = $('#playBtn');
  if (b) b.textContent = '▶';
};

/* -------------------------------------------------------------- trips pane */
function buildTripsPane() {
  $('#paneTrips').innerHTML =
    '<div class="row wrap" style="margin-bottom:10px">'
    + '<button class="btn primary sm" id="btnDetectTrips">Detect trips in range</button>'
    + '<button class="btn sm" id="btnExportTrips">CSV</button></div>'
    + '<div id="tripsSummary"></div><div id="tripsTable"></div>';
  $('#btnDetectTrips').addEventListener('click', () => loadTrips(true));
  $('#btnExportTrips').addEventListener('click', exportTrips);
}
async function loadTrips(detect) {
  if (!App.selected) return;
  const id = App.selected.id;
  $('#tripsTable').innerHTML = '<p class="muted">Loading…</p>';
  try {
    if (detect) {
      const r = await API.post('/api/units/' + encodeURIComponent(id) + '/trips/detect',
        { from: (App.range ? App.range.from : '').replace('T', ' '),
          to: (App.range ? App.range.to : '').replace('T', ' ') });
      toast('Detected ' + r.trips + ' trips and ' + r.parkings + ' parking/idle blocks', 'ok');
    }
    const data = await API.get('/api/units/' + encodeURIComponent(id) + '/trips?detect=0' + App.rangeQuery());
    App.trips = data.trips || [];
    let km = 0, moving = 0, idle = 0, fuel = 0;
    App.trips.forEach(t => { km += t.distance_km; moving += t.moving_s; idle += t.idle_s; fuel += t.fuel_used; });
    $('#tripsSummary').innerHTML = kv([
      ['Blocks', String(App.trips.length)],
      ['Mileage', fmt.km(km)],
      ['Moving time', fmt.duration(moving)],
      ['Idle time', fmt.duration(idle)],
      ['Fuel (est.)', fmt.liters(fuel)]
    ]);
    const rows = App.trips.map(t => ({
      kind: t.kind === 'trip' ? 'Trip' : (t.kind === 'idle' ? 'Idle' : 'Parking'),
      start: t.start, end: t.end, duration_s: t.duration_s, distance_km: t.distance_km,
      max_speed: t.max_speed, avg_speed: t.avg_speed, fuel_used: t.fuel_used,
      start_address: t.start_address, end_address: t.end_address
    }));
    renderTable($('#tripsTable'), {
      columns: [
        { key: 'kind', label: 'Type' }, { key: 'start', label: 'Start', type: 'datetime' },
        { key: 'duration_s', label: 'Duration', type: 'duration' },
        { key: 'distance_km', label: 'Mileage', type: 'km' },
        { key: 'max_speed', label: 'Max', type: 'speed' }, { key: 'fuel_used', label: 'Fuel', type: 'liters' },
        { key: 'start_address', label: 'From' }, { key: 'end_address', label: 'To' }
      ],
      rows: rows,
      totals: { distance_km: km, duration_s: moving },
      onRowClick: r => {
        const t = App.trips.find(x => x.start === r.start);
        if (!t) return;
        App.map.setTrack([{ lat: t.start_lat, lon: t.start_lon }, { lat: t.end_lat, lon: t.end_lon }],
          { color: '#2ecc71', weight: 4 });
        App.map.setView(t.start_lat, t.start_lon, 13);
      }
    });
  } catch (e) { $('#tripsTable').innerHTML = ''; }
}
function exportTrips() {
  if (!App.trips || !App.trips.length) { toast('Nothing to export', 'warn'); return; }
  const rows = [['Type', 'Start', 'End', 'Duration', 'Mileage km', 'Max speed', 'Avg speed', 'Fuel L', 'From', 'To']];
  App.trips.forEach(t => rows.push([t.kind, t.start, t.end, fmt.duration(t.duration_s), t.distance_km,
    t.max_speed, t.avg_speed, t.fuel_used, t.start_address, t.end_address]));
  csvDownload('trips-' + (App.selected ? App.selected.id : 'unit') + '.csv', rows);
}

/* ------------------------------------------------------------- events pane */
function buildEventsPane() {
  const types = ['', 'geofence_enter', 'geofence_exit', 'speed', 'movement', 'ignition_on', 'ignition_off',
    'idle', 'sos', 'fuel_drop', 'fuel_fill', 'power_cut', 'offline', 'maintenance', 'text_message'];
  $('#paneEvents').innerHTML =
    '<div class="row wrap" style="margin-bottom:10px">'
    + '<select id="eventType" style="flex:1">'
    + types.map(t => '<option value="' + t + '">' + h(t ? eventLabel(t) : 'All event types') + '</option>').join('')
    + '</select>'
    + '<select id="eventSeverity" style="width:auto"><option value="">All</option>'
    + '<option value="critical">Critical</option><option value="warning">Warning</option>'
    + '<option value="info">Info</option></select>'
    + '<button class="btn sm" id="btnAckAll">Acknowledge all</button></div>'
    + '<div id="eventsTable"></div>';
  $('#eventType').addEventListener('change', () => App.loadEvents());
  $('#eventSeverity').addEventListener('change', () => App.loadEvents());
  $('#btnAckAll').addEventListener('click', async () => {
    try {
      await API.post('/api/events/ack', {});
      toast('Events acknowledged', 'ok');
      App.loadEvents();
    } catch (e) { /* shown */ }
  });
}
App.loadEvents = async function () {
  const box = $('#eventsTable');
  if (!box) return;
  box.innerHTML = '<p class="muted">Loading…</p>';
  const q = { unit_id: App.selected ? App.selected.id : '', limit: 100 };
  const t = $('#eventType') ? $('#eventType').value : '';
  const s = $('#eventSeverity') ? $('#eventSeverity').value : '';
  if (t) q.type = t;
  if (s) q.severity = s;
  try {
    const data = await API.get('/api/events' + qs(q));
    const rows = (data.events || []).map(e => ({
      ts: e.ts, type: eventLabel(e.type), severity: e.severity, text: e.text, address: e.address,
      _lat: e.lat, _lon: e.lon
    }));
    renderTable(box, {
      columns: [
        { key: 'ts', label: 'Time', type: 'datetime' }, { key: 'type', label: 'Event' },
        { key: 'severity', label: 'Severity', type: 'badge' }, { key: 'text', label: 'Text' },
        { key: 'address', label: 'Location' }
      ],
      rows: rows,
      onRowClick: r => {
        if (r._lat !== null && r._lat !== undefined) App.map.setView(r._lat, r._lon, 15);
      }
    });
  } catch (e) { box.innerHTML = ''; }
}

/* ----------------------------------------------------------- messages pane */
function buildMessagesPane() {
  $('#paneMessages').innerHTML =
    '<div class="row wrap" style="margin-bottom:10px">'
    + '<button class="btn primary sm" id="btnLoadMessages">Last 200 messages</button>'
    + '<button class="btn sm" id="btnExportMessages">CSV</button></div>'
    + '<div id="messagesTable"></div>';
  $('#btnLoadMessages').addEventListener('click', () => App.loadMessages());
  $('#btnExportMessages').addEventListener('click', () => {
    if (!App.messages || !App.messages.length) { toast('Nothing to export', 'warn'); return; }
    const rows = [['Time', 'Latitude', 'Longitude', 'Speed', 'Course', 'Altitude', 'Satellites',
      'Ignition', 'Fuel', 'Voltage', 'Valid', 'Source', 'Parameters']];
    App.messages.forEach(m => rows.push([m.ts, m.lat, m.lon, m.speed, m.course, m.alt, m.sats,
      m.ignition, m.fuel, m.voltage, m.valid, m.source, JSON.stringify(m.params)]));
    csvDownload('messages-' + (App.selected ? App.selected.id : 'unit') + '.csv', rows);
  });
}
App.loadMessages = async function () {
  const box = $('#messagesTable');
  if (!box || !App.selected) return;
  box.innerHTML = '<p class="muted">Loading…</p>';
  try {
    const data = await API.get('/api/units/' + encodeURIComponent(App.selected.id)
      + '/messages?limit=200' + App.rangeQuery());
    const pts = (data.points || []).slice().reverse();
    App.messages = pts;
    renderTable(box, {
      columns: [
        { key: 'ts', label: 'Time', type: 'datetime' }, { key: 'speed', label: 'Speed', type: 'speed' },
        { key: 'ignition', label: 'Ign' }, { key: 'fuel', label: 'Fuel', type: 'liters' },
        { key: 'voltage', label: 'Volt' }, { key: 'sats', label: 'Sats' },
        { key: 'lat', label: 'Latitude' }, { key: 'lon', label: 'Longitude' },
        { key: 'source', label: 'Source' }, { key: 'valid', label: 'Valid' },
        { key: 'paramstr', label: 'Parameters' }
      ],
      rows: pts.map(m => Object.assign({}, m, {
        ignition: m.ignition === null || m.ignition === undefined ? '' : (m.ignition ? 'ON' : 'OFF'),
        valid: m.valid ? 'yes' : 'no',
        paramstr: Object.keys(m.params || {}).map(k => k + '=' + m.params[k]).join(', ')
      }))
    });
  } catch (e) { box.innerHTML = ''; }
};

/* ----------------------------------------------------------- commands pane */
function buildCommandsPane() {
  const types = ['custom_message', 'engine_block', 'engine_unblock', 'query_position', 'set_interval', 'reboot'];
  $('#paneCommands').innerHTML =
    '<div class="field"><label>Command to send to the device</label>'
    + '<select id="cmdType">'
    + types.map(t => '<option value="' + t + '">' + h(t.replace(/_/g, ' ')) + '</option>').join('')
    + '</select></div>'
    + '<div class="field"><label>Text / payload</label>'
    + '<input type="text" id="cmdText" placeholder="e.g. report status"></div>'
    + '<div class="row"><button class="btn primary sm" id="btnSendCmd">Queue command</button>'
    + '<button class="btn sm" id="btnLoadCmds">Refresh</button></div>'
    + '<p class="hint" style="margin:8px 0 12px">Delivered over the Wialon IPS connection '
    + '(<span class="mono">#M#</span> packet) within a few seconds while the device is online.</p>'
    + '<div id="commandsTable"></div>';
  $('#btnSendCmd').addEventListener('click', async () => {
    if (!App.selected) { toast('Select a unit first', 'warn'); return; }
    const type = $('#cmdType').value;
    const body = { type: type, text: $('#cmdText').value };
    if (type === 'set_interval') body.seconds = Number($('#cmdText').value) || 60;
    try {
      const r = await API.post('/api/units/' + encodeURIComponent(App.selected.id) + '/command', body);
      toast(r.message || 'Command queued', 'ok');
      App.loadCommands();
    } catch (e) { /* shown */ }
  });
  $('#btnLoadCmds').addEventListener('click', () => App.loadCommands());
}
App.loadCommands = async function () {
  const box = $('#commandsTable');
  if (!box || !App.selected) return;
  try {
    const data = await API.get('/api/commands?unit_id=' + encodeURIComponent(App.selected.id) + '&limit=30');
    renderTable(box, {
      columns: [
        { key: 'created_at', label: 'Queued', type: 'datetime' }, { key: 'type', label: 'Type' },
        { key: 'text', label: 'Payload' }, { key: 'state', label: 'State', type: 'badge' },
        { key: 'acked_at', label: 'Acked', type: 'datetime' }, { key: 'result', label: 'Result' }
      ],
      rows: (data.commands || []).map(c => Object.assign({}, c, {
        type: String(c.type).replace(/_/g, ' '),
        state: c.state === 'acked' ? 'acked' : (c.state === 'failed' ? 'failed' : c.state)
      }))
    });
  } catch (e) { box.innerHTML = ''; }
}
