/* =====================================================================
   KIVU Tracker — public/js/track.js
   Focused single-unit tracking page (ideal for a viewer login that is
   restricted to one unit): live position, track, trips, events and the
   unit's telemetry — no administration controls.
   ===================================================================== */
'use strict';

const Track = { map: null, unitId: '', unit: null, range: null, timer: null, live: true };

document.addEventListener('DOMContentLoaded', async function () {
  const meta = await boot('app');
  if (!meta) return;

  Track.map = MapCore.create($('#map'), {
    lat: meta.defaults.lat, lon: meta.defaults.lon, zoom: meta.defaults.zoom, tiles: meta.map_tiles
  });
  initTabs($('#tkTrips').parentElement.parentElement);

  const sel = $('#unitSelect');
  State.units.forEach(u => sel.appendChild(el('option', { value: u.id }, h(u.name))));
  const params = new URLSearchParams(location.search);
  const inPath = location.pathname.replace(/^\/track\/?/, '');
  const wanted = params.get('unit') || inPath || '';
  if (wanted && State.units.some(u => String(u.id) === wanted)) sel.value = wanted;
  else if (State.selectedUnit) sel.value = State.selectedUnit;

  buildPresets();
  sel.addEventListener('change', () => { Track.unitId = sel.value; load(); });
  $('#btnLoad').addEventListener('click', () => load());
  $('#btnLive').addEventListener('click', () => {
    Track.live = !Track.live;
    $('#btnLive').textContent = 'Live: ' + (Track.live ? 'on' : 'off');
    $('#btnLive').classList.toggle('active', Track.live);
    startLive();
  });

  Track.unitId = sel.value || ((State.units[0] || {}).id || '');
  await load();
  startLive();
  window.addEventListener('resize', () => Track.map.invalidateSize());
});

function buildPresets() {
  rangePresets().forEach(p => {
    const b = el('button', { class: 'btn sm' + (p.id === '24h' ? ' active' : ''), text: p.label });
    b.addEventListener('click', () => {
      $$('#tkPresets .btn').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      Track.range = { from: p.from, to: p.to };
      load();
    });
    $('#tkPresets').appendChild(b);
  });
  Track.range = { from: new Date(Date.now() - 86400000), to: new Date() };
}
function rangeQuery() {
  const r = Track.range || { from: new Date(Date.now() - 86400000), to: new Date() };
  return '&from=' + encodeURIComponent(isoLocal(r.from).replace('T', ' '))
    + '&to=' + encodeURIComponent(isoLocal(r.to).replace('T', ' '));
}
function startLive() {
  if (Track.timer) clearInterval(Track.timer);
  if (Track.live) Track.timer = setInterval(refreshLive, 20000);
}
async function refreshLive() {
  if (!Track.unitId) return;
  try {
    const data = await API.get('/api/units/live', { silent: true });
    const u = (data.units || []).find(x => String(x.id) === String(Track.unitId));
    if (!u) return;
    Track.unit = u;
    if (u.lat !== null && u.lat !== undefined) {
      Track.map.updateUnit(Object.assign({}, u, { label: '' }), null);
      Track.map.setView(u.lat, u.lon, Math.max(12, Track.map.getView().zoom));
    }
    renderSummary(u);
    $('#mapStatus').innerHTML = '<b>' + h(u.name) + '</b> — ' + h(u.status_label) + ' · '
      + h(u.address || '') + ' · updated ' + h(fmt.ago(u.last_time));
  } catch (e) { /* ignore */ }
}

async function load() {
  if (!Track.unitId) { toast('No unit available for this account', 'warn'); return; }
  $('#tkTrips').innerHTML = '<p class="muted">Loading…</p>';
  try {
    const id = encodeURIComponent(Track.unitId);
    const [unitData, msg, trips] = await Promise.all([
      API.get('/api/units/' + id),
      API.get('/api/units/' + id + '/messages?limit=20000' + rangeQuery()),
      API.get('/api/units/' + id + '/trips?detect=0' + rangeQuery())
    ]);
    Track.unit = Object.assign({}, msg.unit, unitData.unit);
    renderSummary(Track.unit);
    renderInfo(unitData);
    renderTrips(trips.trips || []);
    renderEvents();
    const points = (msg.points || []).map(p => ({ lat: p.lat, lon: p.lon, ts: p.ts, speed: p.speed }));
    Track.map.setTrack(points, { color: '#2f9cf4', weight: 3 });
    if (points.length) Track.map.fitPoints(points);
    if (Track.unit.lat !== null && Track.unit.lat !== undefined) {
      Track.map.setUnits([Object.assign({}, Track.unit, { label: '' })], null);
    }
    $('#mapStatus').innerHTML = '<b>' + h(Track.unit.name) + '</b> — ' + points.length
      + ' track points · ' + h(Track.unit.address || '');
    const path = '/track/' + encodeURIComponent(Track.unitId);
    if (location.pathname !== path) history.replaceState(null, '', path);
  } catch (e) {
    $('#tkTrips').innerHTML = '';
  }
}

function renderSummary(u) {
  const row = $('#summaryRow');
  row.innerHTML = '';
  const cards = [
    ['Status', u.status_label || ''],
    ['Speed', fmt.speed(u.speed)],
    ['Ignition', u.ignition === null || u.ignition === undefined ? '—' : (u.ignition ? 'ON' : 'OFF')],
    ['Fuel', u.fuel === null || u.fuel === undefined ? '—' : fmt.liters(u.fuel)],
    ['Odometer', fmt.km(u.mileage)],
    ['Engine hours', fmt.hours(u.engine_hours)],
    ['Last data', fmt.dt(u.last_time)],
    ['Location', u.address || '']
  ];
  cards.forEach(c => row.appendChild(el('div', { class: 'card pad', style: 'flex:1;min-width:150px' },
    '<div class="tiny dim">' + h(c[0]) + '</div><div class="bold">' + h(c[1]) + '</div>')));
}
function renderInfo(data) {
  const u = Object.assign({}, data.unit, Track.unit || {});
  const rows = [
    ['Unit', u.name], ['Plate / model', (u.plate || '') + ' / ' + (u.model || '')],
    ['Driver', u.driver_name || '—'], ['Group', u.group_name || '—'],
    ['Device', u.unique_id], ['Speed limit', (u.speed_limit || 0) + ' km/h'],
    ['Odometer', fmt.km(u.mileage)], ['Engine hours', fmt.hours(u.engine_hours)],
    ['Last location', u.address || ''], ['Last data', fmt.dt(u.last_time)]
  ];
  const box = $('#tkInfo');
  box.innerHTML = '<table class="data" style="font-size:12.5px">' + rows.map(r =>
    '<tr><td class="muted nowrap">' + h(r[0]) + '</td><td>' + h(r[1]) + '</td></tr>').join('') + '</table>';
  if ((data.sensors || []).length) {
    box.appendChild(el('h4', { style: 'margin-top:12px' }, 'Sensors'));
    box.appendChild(el('div', { class: 'small muted' },
      h(data.sensors.map(s => s.name + ' (' + s.param + ')').join(', '))));
  }
}
function renderTrips(trips) {
  const box = $('#tkTrips');
  if (!trips.length) {
    box.innerHTML = '<p class="muted">No trips stored for this period. '
      + 'An operator can generate them with “Detect trips” in the monitoring console.</p>';
    return;
  }
  renderTable(box, {
    columns: [{ key: 'kind', label: 'Type' }, { key: 'start', label: 'Start', type: 'datetime' },
      { key: 'duration_s', label: 'Duration', type: 'duration' },
      { key: 'distance_km', label: 'Mileage', type: 'km' },
      { key: 'max_speed', label: 'Max', type: 'speed' },
      { key: 'start_address', label: 'From' }, { key: 'end_address', label: 'To' }],
    rows: trips.map(t => Object.assign({}, t, {
      kind: t.kind === 'trip' ? 'Trip' : (t.kind === 'idle' ? 'Idle' : 'Parking')
    })),
    onRowClick: t => {
      Track.map.setTrack([{ lat: t.start_lat, lon: t.start_lon }, { lat: t.end_lat, lon: t.end_lon }],
        { color: '#2ecc71', weight: 4 });
      Track.map.setView(t.start_lat, t.start_lon, 13);
    }
  });
}
async function renderEvents() {
  const box = $('#tkEvents');
  box.innerHTML = '<p class="muted">Loading…</p>';
  try {
    const data = await API.get('/api/events' + qs({ unit_id: Track.unitId, limit: 100 }));
    renderTable(box, {
      columns: [{ key: 'ts', label: 'Time', type: 'datetime' }, { key: 'type', label: 'Event' },
        { key: 'severity', label: 'Severity', type: 'badge' }, { key: 'text', label: 'Details' },
        { key: 'address', label: 'Location' }],
      rows: (data.events || []).map(e => Object.assign({}, e, { type: eventLabel(e.type) }))
    });
  } catch (e) { box.innerHTML = ''; }
}
