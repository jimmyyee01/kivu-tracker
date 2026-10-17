/* =====================================================================
   KIVU Tracker — public/js/admin.js
   Administration console: units (+sensors, service), geofences with map
   drawing, notification rules, drivers, groups, users, device log and
   the audit trail.
   ===================================================================== */
'use strict';

const Admin = {
  map: null, draw: null,
  units: [], drivers: [], groups: [], geofences: [], notifications: [], users: [], accountUnits: []
};

document.addEventListener('DOMContentLoaded', async function () {
  const meta = await boot('admin');
  if (!meta) return;

  if ($('#fenceMap')) {
    Admin.map = MapCore.create($('#fenceMap'), {
      lat: meta.defaults.lat, lon: meta.defaults.lon, zoom: 11, tiles: meta.map_tiles
    });
    Admin.map.on('click', p => onMapClick(p));
    window.addEventListener('resize', () => Admin.map.invalidateSize());
  }

  initTabs($('#adminTabs').parentElement);
  wireButtons();
  $('#adminTabs').addEventListener('click', ev => {
    const tab = ev.target.closest ? ev.target.closest('.tab') : null;
    if (!tab) return;
    const pane = tab.dataset.pane;
    if (pane === 'paneUnits') loadUnits();
    else if (pane === 'paneGeofences') {
      loadGeofences();
      if (Admin.map) setTimeout(() => Admin.map.invalidateSize(), 80);
    } else if (pane === 'paneNotifications') loadNotifications();
    else if (pane === 'paneDrivers') loadDrivers();
    else if (pane === 'paneMaintenance') loadMaintenance();
    else if (pane === 'paneGroups') loadGroups();
    else if (pane === 'paneUsers') loadUsers();
    else if (pane === 'paneDevices') loadDevices();
    else if (pane === 'paneAudit') loadAudit();
  });
  await refreshMetaOptions();
  await Promise.all([loadUnits(), loadGroups(), loadDrivers(), loadGeofences(), loadNotifications(), loadUsers(), loadMaintenance()]);
  loadDevices();
  loadAudit();
});

/* ---------------------------------------------------------------- helpers */
function actionsCell(id, extra) {
  return (extra || '')
    + '<button class="btn xs" data-act="edit" data-id="' + h(id) + '">Edit</button> '
    + '<button class="btn xs danger" data-act="del" data-id="' + h(id) + '">Delete</button>';
}
function unitOptions() { return Admin.accountUnits.map(u => ({ value: u.id, label: u.name })); }
function groupOptions() {
  return [{ value: '', label: '— no group —' }].concat(Admin.groups.map(g => ({ value: g.id, label: g.name })));
}
function driverOptions() {
  return [{ value: '', label: '— no driver —' }].concat(Admin.drivers.map(d => ({ value: d.id, label: d.name })));
}
function unitName(id) {
  const u = Admin.accountUnits.find(x => String(x.id) === String(id));
  return u ? u.name : (id || '—');
}
async function refreshMetaOptions() {
  try {
    const meta = await API.get('/api/meta', { silent: true });
    Admin.accountUnits = meta.units || [];
    State.units = meta.units || [];
    State.groups = meta.groups || [];
    State.geofences = meta.geofences || [];
  } catch (e) { /* ignore */ }
}

function wireButtons() {
  $('#btnNewUnit').addEventListener('click', () => editUnit(null));
  $('#unitFilter').addEventListener('input', debounce(renderUnits, 200));
  $('#btnNewGroup').addEventListener('click', () => editGroup(null));
  $('#btnNewDriver').addEventListener('click', () => editDriver(null));
  $('#btnNewUser').addEventListener('click', () => editUser(null));
  $('#btnNewMaintenance').addEventListener('click', () => editMaintenance(null));
  $('#btnNewNotification').addEventListener('click', () => editNotification(null));
  $('#btnNewGeofence').addEventListener('click', () => editGeofence(null));
  $('#btnDrawCircle').addEventListener('click', () => startDraw('circle'));
  $('#btnDrawPolygon').addEventListener('click', () => startDraw('polygon'));
  $('#btnDrawPolyline').addEventListener('click', () => startDraw('polyline'));
  $('#btnCancelDraw').addEventListener('click', () => {
    Admin.draw = null;
    if (Admin.map) Admin.map.clearPreview();
    $('#drawHint').textContent = 'Drawing cancelled.';
  });

  /* one delegated handler for every Edit / Delete / Test button */
  document.body.addEventListener('click', async ev => {
    const btn = ev.target && ev.target.closest ? ev.target.closest('button[data-act]') : null;
    if (!btn) return;
    const id = btn.dataset.id;
    const act = btn.dataset.act;
    const pane = btn.closest('.tabpane');
    const which = pane ? pane.id : '';
    if (which === 'paneUnits') {
      if (act === 'edit') return editUnit(id);
      if (act === 'del') return deleteRow('unit', id, 'units');
    } else if (which === 'paneGroups') {
      if (act === 'edit') return editGroup(id);
      if (act === 'del') return deleteRow('group', id, 'groups');
    } else if (which === 'paneDrivers') {
      if (act === 'edit') return editDriver(id);
      if (act === 'del') return deleteRow('driver', id, 'drivers');
    } else if (which === 'paneUsers') {
      if (act === 'edit') return editUser(id);
      if (act === 'del') return deleteRow('user', id, 'users');
    } else if (which === 'paneMaintenance') {
      if (act === 'edit') return editMaintenance(id);
      if (act === 'del') return deleteRow('maintenance', id, 'maintenance');
    } else if (which === 'paneNotifications') {
      if (act === 'edit') return editNotification(id);
      if (act === 'del') return deleteRow('notification', id, 'notifications');
      if (act === 'test') return testNotification(id);
    } else if (which === 'paneGeofences') {
      if (act === 'edit') return editGeofence(id);
      if (act === 'del') return deleteRow('geofence', id, 'geofences');
      if (act === 'view') return focusGeofence(id);
    }
  });
}
async function deleteRow(kind, id, plural) {
  const ok = await confirmDialog('Delete this ' + kind + '? This cannot be undone.');
  if (!ok) return;
  try {
    await API.del('/api/' + plural + '/' + encodeURIComponent(id));
    toast(kind.charAt(0).toUpperCase() + kind.slice(1) + ' deleted', 'ok');
    if (plural === 'units') { await refreshMetaOptions(); await loadUnits(); }
    else if (plural === 'groups') { await refreshMetaOptions(); await loadGroups(); }
    else if (plural === 'drivers') await loadDrivers();
    else if (plural === 'users') await loadUsers();
    else if (plural === 'maintenance') await loadMaintenance();
    else if (plural === 'notifications') await loadNotifications();
    else if (plural === 'geofences') { await refreshMetaOptions(); await loadGeofences(); }
  } catch (e) { /* toast shown */ }
}

/* ----------------------------------------------------------------- units */
async function loadUnits() {
  const data = await API.get('/api/units');
  Admin.units = data.units || [];
  renderUnits();
}
function renderUnits() {
  const q = ($('#unitFilter').value || '').toLowerCase();
  const rows = Admin.units
    .filter(u => !q || [u.name, u.plate, u.unique_id, u.tags, u.model, u.group_name]
      .some(v => String(v || '').toLowerCase().indexOf(q) >= 0))
    .map(u => ({
      name: u.name, asset: assetLabel(u.asset_type), plate: u.plate || '', unique_id: u.unique_id,
      group: u.group_name || '',
      driver: u.driver_name || '', status: (STATUS[u.status] || STATUS.never).label,
      speed: u.speed, mileage: u.mileage, last_time: u.last_time, tags: u.tags || '',
      actions: actionsCell(u.id)
    }));
  $('#unitCountChip').textContent = Admin.units.length + ' units';
  renderTable($('#unitsTable'), {
    columns: [
      { key: 'name', label: 'Name' }, { key: 'asset', label: 'Type' }, { key: 'plate', label: 'Plate' },
      { key: 'unique_id', label: 'Device ID' }, { key: 'group', label: 'Group' },
      { key: 'driver', label: 'Driver' }, { key: 'status', label: 'Status' },
      { key: 'speed', label: 'Speed', type: 'speed' }, { key: 'mileage', label: 'Odometer', type: 'km' },
      { key: 'last_time', label: 'Last data', type: 'datetime' }, { key: 'actions', label: '', type: 'html' }
    ],
    rows: rows
  });
}
function unitFields() {
  return [
    { name: 'name', label: 'Unit name', required: true, placeholder: 'Truck 07 — Volvo FH' },
    { name: 'asset_type', label: 'Asset type (icon on the map)', type: 'select',
      options: assetOptions(),
      help: 'Captured at registration for the whole fleet — override it per unit' },
    { name: 'unique_id', label: 'Device unique ID (IMEI / IPS login)', required: true, placeholder: '356938035643899' },
    { name: 'device_password', label: 'Device password (IPS login password)',
      value: 'track', help: 'Devices log in with this password — leave the default unless your tracker uses another one' },
    { name: 'hw_type', label: 'Hardware type', type: 'select', options: hwTypeOptions() },
    { name: 'phone', label: 'SIM / phone' }, { name: 'model', label: 'Model' },
    { name: 'plate', label: 'Plate' }, { name: 'vin', label: 'VIN' },
    { name: 'trailer', label: 'Trailer' }, { name: 'tags', label: 'Tags (comma separated)' },
    { name: 'group_id', label: 'Group', type: 'select', options: groupOptions() },
    { name: 'driver_id', label: 'Driver', type: 'select', options: driverOptions() },
    { name: 'fuel_rate', label: 'Fuel consumption (L/100 km)', type: 'number', step: '0.1' },
    { name: 'fuel_price', label: 'Fuel price per litre', type: 'number', step: '0.01' },
    { name: 'cost_per_km', label: 'Cost per km', type: 'number', step: '0.01' },
    { name: 'odometer_km', label: 'Odometer (km)', type: 'number', step: '0.1' },
    { name: 'engine_hours', label: 'Engine hours', type: 'number', step: '0.1' },
    { name: 'speed_limit', label: 'Speed limit (km/h)', type: 'number' },
    { name: 'min_trip_s', label: 'Min trip duration (s)', type: 'number' },
    { name: 'min_trip_km', label: 'Min trip distance (km)', type: 'number', step: '0.1' },
    { name: 'min_park_s', label: 'Min parking duration (s)', type: 'number' },
    { name: 'parking_timeout_s', label: 'Parking timeout (s)', type: 'number' },
    { name: 'max_jump_km', label: 'Max GPS jump (km)', type: 'number', step: '0.1' },
    { name: 'enabled', label: 'Enabled', type: 'checkbox', value: 1 }
  ];
}
async function editUnit(id) {
  let data = null;
  if (id) { try { data = await API.get('/api/units/' + encodeURIComponent(id)); } catch (e) { return; } }
  const u = data ? data.unit : {};
  if (!id && !u.asset_type && State.meta && State.meta.default_asset_type) {
    u.asset_type = State.meta.default_asset_type;      // the type picked at registration
  }
  const m = formModal({
    title: id ? 'Unit — ' + u.name : 'New unit',
    wide: true, values: u, fields: unitFields(),
    saveLabel: id ? 'Save unit' : 'Create unit',
    onSave: async form => {
      form.group_id = form.group_id || null;
      form.driver_id = form.driver_id || null;
      const res = id ? await API.put('/api/units/' + encodeURIComponent(id), form)
        : await API.post('/api/units', form);
      toast(res.message || 'Unit saved', 'ok');
      await refreshMetaOptions();
      await loadUnits();
    }
  });
  if (m && id && data) renderSensorEditor(m, id, data.sensors || []);
}

function renderSensorEditor(m, unitId, sensors) {
  const box = el('div');
  box.appendChild(el('h3', { style: 'margin-top:16px' }, 'Sensors'));
  const addRow = el('div', { class: 'row', style: 'margin-bottom:8px' });
  const addBtn = el('button', { class: 'btn sm primary', text: '+ Add sensor' });
  addBtn.addEventListener('click', e => { e.stopPropagation(); editSensor(null, unitId); });
  addRow.appendChild(addBtn);
  box.appendChild(addRow);
  const holder = el('div');
  box.appendChild(holder);
  renderTable(holder, {
    columns: [{ key: 'name', label: 'Name' }, { key: 'param', label: 'Parameter' },
      { key: 'units', label: 'Units' }, { key: 'coefficient', label: 'Coefficient', type: 'number' },
      { key: 'actions', label: '', type: 'html' }],
    rows: sensors.map(s => Object.assign({}, s, {
      actions: '<button class="btn xs" data-act="edit-sensor" data-id="' + h(s.id) + '">Edit</button> '
        + '<button class="btn xs danger" data-act="del-sensor" data-id="' + h(s.id) + '">Delete</button>'
    }))
  });
  m.body.appendChild(box);
  holder.addEventListener('click', async ev => {
    const btn = ev.target.closest ? ev.target.closest('button[data-act]') : null;
    if (!btn) return;
    ev.stopPropagation();
    if (btn.dataset.act === 'edit-sensor') editSensor(btn.dataset.id, unitId);
    if (btn.dataset.act === 'del-sensor') {
      const ok = await confirmDialog('Delete this sensor?');
      if (!ok) return;
      try {
        await API.del('/api/sensors/' + encodeURIComponent(btn.dataset.id));
        toast('Sensor deleted', 'ok');
        const tr = btn.closest('tr');
        if (tr) tr.remove();
      } catch (e) { /* shown */ }
    }
  });
}
function sensorFields() {
  return [
    { name: 'name', label: 'Sensor name', required: true },
    { name: 'type', label: 'Type', type: 'select', options: [
      { value: 'fuel_level', label: 'fuel_level' }, { value: 'ignition', label: 'ignition' },
      { value: 'temperature', label: 'temperature' }, { value: 'voltage', label: 'voltage' },
      { value: 'counter', label: 'counter' }, { value: 'custom', label: 'custom' }] },
    { name: 'param', label: 'Device parameter', required: true,
      help: 'IPS parameter name — e.g. fuel, F1, ign, pwr, temp, adc1' },
    { name: 'units', label: 'Units' },
    { name: 'coefficient', label: 'Coefficient', type: 'number', step: '0.0001' },
    { name: 'offset_val', label: 'Offset', type: 'number', step: '0.0001' },
    { name: 'min_val', label: 'Minimum alarm', type: 'number', step: 'any' },
    { name: 'max_val', label: 'Maximum alarm', type: 'number', step: 'any' },
    { name: 'color', label: 'Colour', type: 'color' },
    { name: 'enabled', label: 'Enabled', type: 'checkbox' }
  ];
}
async function editSensor(sensorId, unitId) {
  let values = { coefficient: 1, offset_val: 0, color: '#2f9cf4', enabled: 1, type: 'custom' };
  if (sensorId) {
    try {
      const data = await API.get('/api/units/' + encodeURIComponent(unitId) + '/sensors');
      values = (data.sensors || []).find(s => String(s.id) === String(sensorId)) || values;
    } catch (e) { return; }
  }
  formModal({
    title: sensorId ? 'Sensor — ' + values.name : 'New sensor',
    values: values, fields: sensorFields(), saveLabel: 'Save sensor',
    onSave: async form => {
      if (!sensorId) form.unit_id = unitId;
      const res = sensorId ? await API.put('/api/sensors/' + encodeURIComponent(sensorId), form)
        : await API.post('/api/units/' + encodeURIComponent(unitId) + '/sensors', form);
      toast(res.message || 'Sensor saved', 'ok');
    }
  });
}

/* ---------------------------------------------------------------- groups */
async function loadGroups() {
  const data = await API.get('/api/groups');
  Admin.groups = data.groups || [];
  renderGroups();
}
function renderGroups() {
  renderTable($('#groupsTable'), {
    columns: [{ key: 'name', label: 'Group' }, { key: 'color', label: 'Colour' },
      { key: 'units', label: 'Units', type: 'number' }, { key: 'actions', label: '', type: 'html' }],
    rows: Admin.groups.map(g => Object.assign({}, g, { actions: actionsCell(g.id) }))
  });
}
function editGroup(id) {
  const g = Admin.groups.find(x => String(x.id) === String(id)) || {};
  formModal({
    title: id ? 'Group — ' + g.name : 'New group',
    values: g,
    fields: [{ name: 'name', label: 'Group name', required: true },
      { name: 'color', label: 'Colour', type: 'color', value: '#2f9cf4' }],
    onSave: async form => {
      const res = id ? await API.put('/api/groups/' + encodeURIComponent(id), form)
        : await API.post('/api/groups', form);
      toast(res.message || 'Group saved', 'ok');
      await refreshMetaOptions();
      await loadGroups();
    }
  });
}

/* --------------------------------------------------------------- drivers */
async function loadDrivers() {
  const data = await API.get('/api/drivers');
  Admin.drivers = data.drivers || [];
  renderTable($('#driversTable'), {
    columns: [{ key: 'name', label: 'Driver' }, { key: 'phone', label: 'Phone' },
      { key: 'licence', label: 'Licence' }, { key: 'id_number', label: 'ID number' },
      { key: 'address', label: 'Address' }, { key: 'units', label: 'Units', type: 'number' },
      { key: 'actions', label: '', type: 'html' }],
    rows: Admin.drivers.map(d => Object.assign({}, d, { actions: actionsCell(d.id) }))
  });
}
function editDriver(id) {
  const d = Admin.drivers.find(x => String(x.id) === String(id)) || {};
  formModal({
    title: id ? 'Driver — ' + d.name : 'New driver',
    values: d,
    fields: [{ name: 'name', label: 'Full name', required: true },
      { name: 'phone', label: 'Phone' }, { name: 'licence', label: 'Driving licence' },
      { name: 'id_number', label: 'ID / passport number' }, { name: 'address', label: 'Address' },
      { name: 'notes', label: 'Notes', type: 'textarea' }],
    onSave: async form => {
      const res = id ? await API.put('/api/drivers/' + encodeURIComponent(id), form)
        : await API.post('/api/drivers', form);
      toast(res.message || 'Driver saved', 'ok');
      await loadDrivers();
      await refreshMetaOptions();
      await loadUnits();
    }
  });
}

/* ---------------------------------------------------------- service items */
async function loadMaintenance() {
  const data = await API.get('/api/maintenance');
  Admin.maintenance = data.items || [];
  renderTable($('#maintenanceTable'), {
    columns: [{ key: 'unit', label: 'Unit' }, { key: 'name', label: 'Service' },
      { key: 'interval_km', label: 'Interval', type: 'km' },
      { key: 'interval_hours', label: 'Interval (h)', type: 'number' },
      { key: 'interval_days', label: 'Days', type: 'number' },
      { key: 'last_service_date', label: 'Last service' },
      { key: 'remaining', label: 'Remaining' },
      { key: 'status', label: 'Status', type: 'badge' },
      { key: 'cost', label: 'Cost', type: 'money' },
      { key: 'actions', label: '', type: 'html' }],
    rows: Admin.maintenance.map(m => Object.assign({}, m, {
      status: m.due ? 'danger' : 'ok',
      actions: '<button class="btn xs" data-act="edit" data-id="' + h(m.id) + '">Edit</button> '
        + '<button class="btn xs danger" data-act="del" data-id="' + h(m.id) + '">Delete</button>'
    }))
  });
}
function editMaintenance(id) {
  const m = (Admin.maintenance || []).find(x => String(x.id) === String(id)) || {};
  formModal({
    title: id ? 'Service — ' + m.name : 'New service interval',
    values: m,
    fields: [{ name: 'unit_id', label: 'Unit', type: 'select', options: unitOptions(), required: true },
      { name: 'name', label: 'Service name', required: true, placeholder: 'Oil & filter' },
      { name: 'interval_km', label: 'Every (km)', type: 'number', step: '1' },
      { name: 'interval_hours', label: 'Every (engine hours)', type: 'number', step: '0.1' },
      { name: 'interval_days', label: 'Every (days)', type: 'number' },
      { name: 'last_service_km', label: 'Odometer at last service (km)', type: 'number', step: '0.1' },
      { name: 'last_service_hours', label: 'Engine hours at last service', type: 'number', step: '0.1' },
      { name: 'last_service_date', label: 'Last service date', type: 'date' },
      { name: 'cost', label: 'Cost', type: 'number', step: '0.01' },
      { name: 'notes', label: 'Notes', type: 'textarea' }],
    onSave: async form => {
      const res = id ? await API.put('/api/maintenance/' + encodeURIComponent(id), form)
        : await API.post('/api/maintenance', form);
      toast(res.message || 'Service interval saved', 'ok');
      await loadMaintenance();
    }
  });
}

/* ------------------------------------------------------------ geofences -- */
async function loadGeofences() {
  const data = await API.get('/api/geofences');
  Admin.geofences = data.geofences || [];
  renderGeofences();
  if (Admin.map) Admin.map.showGeofences(Admin.geofences, g => editGeofence(g.id));
}
function geofenceSize(g) {
  if (g.type === 'circle') return fmt.num(g.radius_m, 0) + ' m radius';
  if (g.type === 'polyline') return fmt.km(g.length_km) + ' long';
  return fmt.num(g.area_km2, 3) + ' km²';
}
function renderGeofences() {
  renderTable($('#geofencesTable'), {
    columns: [{ key: 'name', label: 'Name' }, { key: 'type', label: 'Type' },
      { key: 'size', label: 'Size' }, { key: 'speed_limit', label: 'Limit', type: 'speed' },
      { key: 'enabled', label: 'On' }, { key: 'actions', label: '', type: 'html' }],
    rows: Admin.geofences.map(g => Object.assign({}, g, {
      size: geofenceSize(g), enabled: g.enabled ? 'yes' : 'no',
      actions: '<button class="btn xs" data-act="view" data-id="' + h(g.id) + '">Show</button> '
        + '<button class="btn xs" data-act="edit" data-id="' + h(g.id) + '">Edit</button> '
        + '<button class="btn xs danger" data-act="del" data-id="' + h(g.id) + '">Del</button>'
    }))
  });
}
function focusGeofence(id) {
  const g = Admin.geofences.find(x => String(x.id) === String(id));
  if (!g || !g.points.length || !Admin.map) return;
  if (g.type === 'circle') {
    Admin.map.setView(g.points[0][0], g.points[0][1], 13);
    Admin.map.drawPreview({ type: 'circle', points: [g.points[0]], radius_m: g.radius_m, color: g.color });
  } else {
    Admin.map.fitPoints(g.points.map(p => ({ lat: Number(p[0]), lon: Number(p[1]) })));
    Admin.map.drawPreview({ type: g.type, points: g.points, color: g.color });
  }
}
/* ---- drawing tools ----------------------------------------------------- */
function startDraw(type) {
  if (!Admin.map) return;
  Admin.draw = { type: type, points: [], radius_m: 0 };
  Admin.map.drawMode(true);
  $('#drawHint').textContent = type === 'circle'
    ? 'Click the centre on the map, then click the edge to set the radius.'
    : 'Click on the map to add points — a “Finish drawing” button appears.';
  if (!$('#btnFinishDraw')) {
    const b = el('button', { class: 'btn sm primary', id: 'btnFinishDraw', text: 'Finish drawing' });
    b.addEventListener('click', () => finishDraw());
    $('#drawHint').parentElement.insertBefore(b, $('#drawHint'));
  }
}
function onMapClick(p) {
  const d = Admin.draw;
  if (!d) return;
  if (d.type === 'circle') {
    if (!d.points.length) {
      d.points = [[p.lat, p.lon]];
      $('#drawHint').textContent = 'Now click the edge of the circle to set the radius.';
    } else {
      d.radius_m = Math.round(distanceM(d.points[0], [p.lat, p.lon]));
      $('#drawHint').textContent = 'Radius ' + d.radius_m + ' m — press Finish drawing.';
    }
  } else {
    d.points.push([p.lat, p.lon]);
    $('#drawHint').textContent = d.points.length + ' point(s) — press Finish drawing when done.';
  }
  Admin.map.drawPreview({ type: d.type, points: d.points, radius_m: d.radius_m, color: '#f1c40f' });
}
function distanceM(a, b) {
  const R = 6371000, dLat = (b[0] - a[0]) * Math.PI / 180, dLon = (b[1] - a[1]) * Math.PI / 180;
  const x = Math.sin(dLat / 2) ** 2
    + Math.cos(a[0] * Math.PI / 180) * Math.cos(b[0] * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(x)));
}
function finishDraw() {
  const d = Admin.draw;
  if (!d) return;
  const enough = d.type === 'circle' ? (d.points.length === 1 && d.radius_m > 0)
    : (d.type === 'polygon' ? d.points.length >= 3 : d.points.length >= 2);
  if (!enough) { toast('Not enough geometry yet — add the missing points', 'warn'); return; }
  Admin.draw = null;
  Admin.map.drawMode(false);
  const b = $('#btnFinishDraw');
  if (b) b.remove();
  $('#drawHint').textContent = 'Shape captured — name it and save.';
  if (Admin.editor && Admin.editor.setGeometry) {
    Admin.editor.setGeometry(d.type, d.points.map(p => p.slice()), d.radius_m);
  } else {
    editGeofence(null, { type: d.type, points: d.points, radius_m: d.radius_m });
  }
}

function editGeofence(id, preset) {
  const g = id ? (Admin.geofences.find(x => String(x.id) === String(id)) || {}) : (preset || {});
  let points = (g.points || []).map(p => p.slice());
  let radius = g.radius_m || 0;
  const values = {
    name: g.name || '', type: g.type || 'polygon', color: g.color || '#2f9cf4',
    width: g.width || 2, description: g.description || '',
    speed_limit: g.speed_limit === null || g.speed_limit === undefined ? '' : g.speed_limit,
    enabled: g.enabled === undefined ? 1 : g.enabled
  };
  const body = el('div');
  body.appendChild(el('div', { class: 'hint', style: 'margin-bottom:10px' },
    'Use “Draw circle / polygon / route” on the toolbar above the map, then click the map to place the shape. '
    + 'The geometry you draw is picked up here automatically.'));
  const mk = (label, input, help) => {
    const w = el('div', { class: 'field' });
    w.appendChild(el('label', null, h(label)));
    w.appendChild(input);
    if (help) w.appendChild(el('div', { class: 'hint' }, h(help)));
    body.appendChild(w);
    return input;
  };
  const nameInput = mk('Geofence name *', el('input', { type: 'text', name: 'name' }));
  nameInput.value = values.name;
  const typeSelect = mk('Type', selectBox('type', [
    { value: 'circle', label: 'Circle' }, { value: 'polygon', label: 'Polygon' },
    { value: 'polyline', label: 'Route (polyline)' }], values.type));
  const radiusInput = mk('Radius (m) — circles only',
    el('input', { type: 'number', name: 'radius_m', min: 20, step: 10 }), 'Click two points on the map to set it.');
  radiusInput.value = radius || '';
  const colorInput = mk('Colour', el('input', { type: 'color', name: 'color' }));
  colorInput.value = values.color;
  const widthInput = mk('Line width', el('input', { type: 'number', name: 'width', min: 1, max: 10 }));
  widthInput.value = values.width;
  const speedInput = mk('Speed limit inside (km/h, blank = none)',
    el('input', { type: 'number', name: 'speed_limit', min: 0 }));
  speedInput.value = values.speed_limit;
  const descInput = mk('Description', el('textarea', { name: 'description', rows: 2 }));
  descInput.value = values.description;
  const enabledInput = el('input', { type: 'checkbox', name: 'enabled' });
  enabledInput.checked = !!values.enabled;
  const enLabel = el('label', { class: 'row' });
  enLabel.appendChild(enabledInput);
  enLabel.appendChild(el('span', null, ' Enabled'));
  body.appendChild(enLabel);
  const geoInfo = el('div', { class: 'hint', style: 'margin-top:10px' });
  body.appendChild(geoInfo);

  function refreshInfo() {
    if (typeSelect.value === 'circle' && points.length) {
      geoInfo.textContent = 'Centre: ' + points[0][0].toFixed(5) + ', ' + points[0][1].toFixed(5)
        + ' — radius ' + (radiusInput.value || 0) + ' m';
    } else if (points.length) {
      geoInfo.textContent = points.length + ' point(s): ' + points.map(p =>
        p[0].toFixed(4) + ',' + p[1].toFixed(4)).slice(0, 4).join(' | ') + (points.length > 4 ? ' …' : '');
    } else {
      geoInfo.textContent = 'No geometry yet.';
    }
  }
  refreshInfo();
  radiusInput.addEventListener('input', () => {
    radius = Number(radiusInput.value) || 0;
    if (Admin.map && typeSelect.value === 'circle' && points.length) {
      Admin.map.drawPreview({ type: 'circle', points: points, radius_m: radius, color: colorInput.value });
    }
    refreshInfo();
  });
  typeSelect.addEventListener('change', () => {
    if (typeSelect.value !== 'circle') radiusInput.value = '';
    refreshInfo();
  });

  Admin.editor = {
    setGeometry: function (type, pts, r) {
      points = pts;
      radius = r || 0;
      typeSelect.value = type;
      if (r) radiusInput.value = r;
      refreshInfo();
    }
  };
  const m = modal({
    title: id ? 'Geofence — ' + values.name : 'New geofence',
    body: body, wide: true,
    buttons: [
      { label: 'Cancel', class: 'ghost' },
      { label: 'Save geofence', class: 'primary', onClick: async () => {
        const name = nameInput.value.trim();
        if (!name) { toast('The geofence needs a name', 'warn'); return false; }
        const type = typeSelect.value;
        if (!points.length) { toast('Draw the shape on the map first', 'warn'); return false; }
        const payload = {
          name: name, type: type, points: points,
          radius_m: type === 'circle' ? Number(radiusInput.value || 0) : null,
          color: colorInput.value, width: Number(widthInput.value || 2),
          description: descInput.value,
          speed_limit: speedInput.value === '' ? null : Number(speedInput.value),
          enabled: enabledInput.checked ? 1 : 0
        };
        if (type === 'circle' && !payload.radius_m) { toast('Set the circle radius (m)', 'warn'); return false; }
        try {
          const res = id ? await API.put('/api/geofences/' + encodeURIComponent(id), payload)
            : await API.post('/api/geofences', payload);
          toast(res.message || 'Geofence saved', 'ok');
          Admin.editor = null;
          Admin.map.clearPreview();
          await refreshMetaOptions();
          await loadGeofences();
          return true;
        } catch (e) { return false; }
      } }
    ]
  });
  const origClose = m.close;
  m.close = function () { Admin.editor = null; if (Admin.map) Admin.map.clearPreview(); origClose.call(m); };
  if (id) focusGeofence(id);
}

/* --------------------------------------------------------- notifications */
async function loadNotifications() {
  const data = await API.get('/api/notifications');
  Admin.notifications = data.notifications || [];
  renderNotifications();
}
function notifParamsText(n) {
  const p = n.params || {};
  const parts = [];
  if (p.speed_limit !== undefined) parts.push('limit ' + p.speed_limit + ' km/h');
  if (p.use_geofence_limit) parts.push('geofence limits');
  if (p.min_change !== undefined) parts.push('min ' + p.min_change + ' L');
  if (p.idle_min !== undefined) parts.push('idle ' + p.idle_min + ' min');
  if (p.timeout_s !== undefined) parts.push('no data ' + Math.round(p.timeout_s / 60) + ' min');
  if (p.events) parts.push('on ' + p.events.join('/'));
  if (p.min_voltage !== undefined) parts.push('below ' + p.min_voltage + ' V');
  return parts.join(', ') || '—';
}
function renderNotifications() {
  $('#notificationCountChip').textContent = Admin.notifications.length + ' rules';
  renderTable($('#notificationsTable'), {
    columns: [{ key: 'name', label: 'Notification' }, { key: 'type', label: 'Type' },
      { key: 'scope', label: 'Applies to' }, { key: 'params', label: 'Trigger' },
      { key: 'actions_txt', label: 'Actions' }, { key: 'severity', label: 'Severity', type: 'badge' },
      { key: 'fired_count', label: 'Fired', type: 'number' },
      { key: 'last_fired', label: 'Last fired', type: 'datetime' },
      { key: 'btns', label: '', type: 'html' }],
    rows: Admin.notifications.map(n => Object.assign({}, n, {
      params: notifParamsText(n),
      actions_txt: (n.actions || []).join(', '),
      scope: ((n.unit_ids && n.unit_ids.length) ? n.unit_ids.length + ' unit(s)' : 'all units')
        + ((n.geofence_ids && n.geofence_ids.length) ? ', ' + n.geofence_ids.length + ' geofence(s)' : ''),
      btns: '<button class="btn xs" data-act="test" data-id="' + h(n.id) + '">Test</button> '
        + '<button class="btn xs" data-act="edit" data-id="' + h(n.id) + '">Edit</button> '
        + '<button class="btn xs danger" data-act="del" data-id="' + h(n.id) + '">Del</button>'
    }))
  });
}
async function testNotification(id) {
  try {
    const r = await API.post('/api/notifications/' + encodeURIComponent(id) + '/test', {});
    toast(r.message || 'Test event created', (r.webhook && r.webhook.ok === false) ? 'warn' : 'ok');
    await loadNotifications();
  } catch (e) { /* shown */ }
}

function notifField(label, input, help, box) {
  const w = el('div', { class: 'field' });
  w.appendChild(el('label', null, h(label)));
  w.appendChild(input);
  if (help) w.appendChild(el('div', { class: 'hint' }, h(help)));
  box.appendChild(w);
  return input;
}
function editNotification(id) {
  const n = id ? (Admin.notifications.find(x => String(x.id) === String(id)) || {}) : {};
  const params = n.params || {};
  const body = el('div');
  const nameInput = notifField('Notification name *', el('input', { type: 'text', name: 'name' }), null, body);
  nameInput.value = n.name || '';
  const typeSelect = notifField('Type', selectBox('type', [
    { value: 'speed', label: 'Speed violation' }, { value: 'geofence', label: 'Geofence enter/exit' },
    { value: 'movement', label: 'Movement started' }, { value: 'ignition', label: 'Ignition on/off' },
    { value: 'idle', label: 'Long idling' }, { value: 'sos', label: 'SOS / panic' },
    { value: 'fuel_drop', label: 'Fuel drop (theft)' }, { value: 'fuel_fill', label: 'Refuelling' },
    { value: 'power_cut', label: 'Power cut' }, { value: 'offline', label: 'No data (offline)' },
    { value: 'maintenance', label: 'Service interval due' }], n.type || 'speed'), null, body);
  const speedInput = notifField('Speed limit (km/h)',
    el('input', { type: 'number', name: 'speed_limit', min: 1 }), null, body);
  speedInput.value = params.speed_limit === undefined ? '' : params.speed_limit;
  const changeInput = notifField('Minimum change (litres)',
    el('input', { type: 'number', name: 'min_change', min: 1 }), null, body);
  changeInput.value = params.min_change === undefined ? '' : params.min_change;
  const idleInput = notifField('Idle longer than (minutes)',
    el('input', { type: 'number', name: 'idle_min', min: 1 }), null, body);
  idleInput.value = params.idle_min === undefined ? '' : params.idle_min;
  const timeoutInput = notifField('No data for (minutes)',
    el('input', { type: 'number', name: 'timeout_min', min: 1 }), null, body);
  timeoutInput.value = params.timeout_s === undefined ? '' : Math.round(params.timeout_s / 60);
  const voltInput = notifField('Power cut below (V)',
    el('input', { type: 'number', name: 'min_voltage', step: '0.1' }), null, body);
  voltInput.value = params.min_voltage === undefined ? '' : params.min_voltage;

  const geoWrap = el('div', { class: 'field' });
  geoWrap.appendChild(el('label', null, 'Geofence events'));
  const enterBox = el('input', { type: 'checkbox', name: 'ev_enter' });
  enterBox.checked = !params.events || params.events.indexOf('enter') >= 0;
  const exitBox = el('input', { type: 'checkbox', name: 'ev_exit' });
  exitBox.checked = !params.events || params.events.indexOf('exit') >= 0;
  const er = el('label', { class: 'row' });
  er.appendChild(enterBox); er.appendChild(el('span', null, ' Enter'));
  const xr = el('label', { class: 'row' });
  xr.appendChild(exitBox); xr.appendChild(el('span', null, ' Exit'));
  geoWrap.appendChild(er); geoWrap.appendChild(xr);
  body.appendChild(geoWrap);

  const useFenceWrap = el('label', { class: 'row' });
  const useFence = el('input', { type: 'checkbox', name: 'use_geofence_limit' });
  useFence.checked = !!params.use_geofence_limit;
  useFenceWrap.appendChild(useFence);
  useFenceWrap.appendChild(el('span', null, ' Also apply geofence speed limits'));
  body.appendChild(useFenceWrap);

  notifField('Units (empty = all units)',
    multiSelect('units', unitOptions(), n.unit_ids || [], 5), null, body);
  notifField('Geofences (empty = all geofences)',
    multiSelect('geofences', Admin.geofences.map(g => ({ value: g.id, label: g.name })), n.geofence_ids || [], 5),
    null, body);
  const textInput = notifField('Message template', el('input', { type: 'text', name: 'text_template' }),
    'Placeholders: {unit} {speed} {limit} {address} {geofence} {change} {voltage} {duration} {age}', body);
  textInput.value = n.text_template || '';

  const actionsWrap = el('div', { class: 'field' });
  actionsWrap.appendChild(el('label', null, 'Actions'));
  ['push', 'webhook', 'email', 'sms'].forEach((a, i) => {
    const cb = el('input', { type: 'checkbox', name: 'actions', value: a, id: 'act_' + a + i });
    cb.checked = (n.actions || ['push']).indexOf(a) >= 0;
    const lb = el('label', { class: 'row' });
    lb.appendChild(cb);
    lb.appendChild(el('span', null, ' ' + a + (a === 'webhook' ? ' — real HTTP POST'
      : (a === 'push' ? ' — shown in the console' : ' — recorded in the audit log'))));
    actionsWrap.appendChild(lb);
  });
  body.appendChild(actionsWrap);
  const hookInput = notifField('Webhook URL', el('input', { type: 'text', name: 'webhook_url' }),
    'Sent as JSON: event id, type, unit, text, severity, lat/lon, timestamp.', body);
  hookInput.value = n.webhook_url || '';
  const emailInput = notifField('E-mail recipient', el('input', { type: 'text', name: 'email_to' }), null, body);
  emailInput.value = n.email_to || '';
  const phoneInput = notifField('SMS recipient', el('input', { type: 'text', name: 'phone_to' }), null, body);
  phoneInput.value = n.phone_to || '';
  const sevSelect = notifField('Severity', selectBox('severity', [
    { value: 'info', label: 'info' }, { value: 'warning', label: 'warning' },
    { value: 'critical', label: 'critical' }], n.severity || 'warning'), null, body);
  const enabledInput = el('input', { type: 'checkbox', name: 'enabled' });
  enabledInput.checked = n.enabled === undefined ? true : !!n.enabled;
  const enL = el('label', { class: 'row' });
  enL.appendChild(enabledInput);
  enL.appendChild(el('span', null, ' Enabled'));
  body.appendChild(enL);
  modal({
    title: id ? 'Notification — ' + n.name : 'New notification',
    body: body, wide: true,
    buttons: [
      { label: 'Cancel', class: 'ghost' },
      { label: 'Save rule', class: 'primary', onClick: async () => {
        const p = {};
        if (speedInput.value !== '') p.speed_limit = Number(speedInput.value);
        if (changeInput.value !== '') p.min_change = Number(changeInput.value);
        if (idleInput.value !== '') p.idle_min = Number(idleInput.value);
        if (timeoutInput.value !== '') p.timeout_s = Number(timeoutInput.value) * 60;
        if (voltInput.value !== '') p.min_voltage = Number(voltInput.value);
        const evs = [];
        if (enterBox.checked) evs.push('enter');
        if (exitBox.checked) evs.push('exit');
        if (evs.length) p.events = evs;
        if (useFence.checked) p.use_geofence_limit = true;
        const actions = Array.prototype.slice.call(body.querySelectorAll('input[name="actions"]'))
          .filter(c => c.checked).map(c => c.value);
        const payload = {
          name: nameInput.value.trim(), type: typeSelect.value, params: p,
          units: selectedValues(body, 'units'), geofences: selectedValues(body, 'geofences'),
          text_template: textInput.value, actions: actions,
          webhook_url: hookInput.value, email_to: emailInput.value, phone_to: phoneInput.value,
          severity: sevSelect.value, enabled: enabledInput.checked ? 1 : 0
        };
        if (!payload.name) { toast('The rule needs a name', 'warn'); return false; }
        try {
          const res = id ? await API.put('/api/notifications/' + encodeURIComponent(id), payload)
            : await API.post('/api/notifications', payload);
          toast(res.message || 'Notification saved', 'ok');
          await loadNotifications();
          return true;
        } catch (e) { return false; }
      } }
    ]
  });
}

/* ----------------------------------------------------------------- users */
async function loadUsers() {
  const data = await API.get('/api/users');
  Admin.users = data.users || [];
  const chip = $('#userCountChip');
  if (chip) chip.textContent = Admin.users.length + ' users';
  renderTable($('#usersTable'), {
    columns: [{ key: 'username', label: 'Username' }, { key: 'full_name', label: 'Name' },
      { key: 'email', label: 'E-mail' }, { key: 'role', label: 'Role', type: 'badge' },
      { key: 'mask', label: 'Unit access' }, { key: 'enabled', label: 'On' },
      { key: 'last_login', label: 'Last login', type: 'datetime' },
      { key: 'actions', label: '', type: 'html' }],
    rows: Admin.users.map(u => Object.assign({}, u, {
      mask: u.access_units === null ? 'all units' : u.access_units.length + ' selected',
      enabled: u.enabled ? 'yes' : 'no',
      actions: actionsCell(u.id)
    }))
  });
}
function editUser(id) {
  const u = id ? (Admin.users.find(x => String(x.id) === String(id)) || {}) : {};
  const unitOptionsList = unitOptions();
  formModal({
    title: id ? 'User — ' + u.username : 'New user',
    values: u,
    wide: true,
    fields: [
      { name: 'username', label: 'Username', required: true },
      { name: 'full_name', label: 'Full name' },
      { name: 'email', label: 'E-mail' },
      { name: 'phone', label: 'Phone' },
      { name: 'role', label: 'Role', type: 'select', options: [
        { value: 'admin', label: 'admin — full account control' },
        { value: 'operator', label: 'operator — tracking + configuration' },
        { value: 'viewer', label: 'viewer — read only' }], value: 'operator' },
      { name: 'password', label: 'Password',
        help: id ? 'Leave blank to keep the current password' : 'At least 6 characters' },
      { name: 'enabled', label: 'Enabled', type: 'checkbox', value: 1 },
      { name: 'access_units', label: 'Unit access — leave everything unselected for the whole account',
        type: 'multiselect', options: unitOptionsList, selected: u.access_units || [], size: 6 }
    ],
    saveLabel: id ? 'Save user' : 'Create user',
    onSave: async form => {
      if (form.password === '') delete form.password;
      const res = id ? await API.put('/api/users/' + encodeURIComponent(id), form)
        : await API.post('/api/users', form);
      toast(res.message || 'User saved', 'ok');
      await loadUsers();
    }
  });
}

/* ------------------------------------------------------------ device log */
async function loadDevices() {
  const data = await API.get('/api/devices/sessions?limit=100', { silent: true });
  renderTable($('#devicesTable'), {
    columns: [{ key: 'unit', label: 'Unit' }, { key: 'unique_id', label: 'Device ID' },
      { key: 'protocol', label: 'Protocol' }, { key: 'ip', label: 'IP' },
      { key: 'packets', label: 'Packets', type: 'number' },
      { key: 'login_ts', label: 'Connected', type: 'datetime' },
      { key: 'last_ts', label: 'Last packet', type: 'datetime' },
      { key: 'online', label: 'Online' }],
    rows: (data.sessions || []).map(s => Object.assign({}, s, { online: s.online ? 'yes' : 'no' }))
  });
}

/* ---------------------------------------------------------------- audit */
async function loadAudit() {
  const data = await API.get('/api/audit', { silent: true });
  renderTable($('#auditTable'), {
    columns: [{ key: 'ts', label: 'Time', type: 'datetime' }, { key: 'username', label: 'User' },
      { key: 'action', label: 'Action' }, { key: 'entity', label: 'Entity' },
      { key: 'entity_id', label: 'ID' }, { key: 'details', label: 'Details' },
      { key: 'ip', label: 'IP' }],
    rows: (data.entries || []).map(e => Object.assign({}, e, {
      details: e.details ? JSON.stringify(e.details) : ''
    }))
  });
}
