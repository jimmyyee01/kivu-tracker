/* =====================================================================
   KIVU Tracker — public/js/reports.js
   Report builder + viewer. Every report type shares one JSON shape
   (summary tiles / tables / charts) so a single renderer covers them all,
   with CSV download, printable output and saved templates.
   ===================================================================== */
'use strict';

const Reports = { type: '', report: null, templates: [], eventTypes: [] };

document.addEventListener('DOMContentLoaded', async function () {
  const meta = await boot('reports');
  if (!meta) return;

  Reports.eventTypes = meta.notification_types || [];
  buildMenu(meta.report_types || []);
  buildPresets();
  buildUnitSelect();
  buildEventTypes();
  loadTemplates();

  const params = new URLSearchParams(location.search);
  const wanted = params.get('unit');
  if (wanted) {
    Array.prototype.slice.call($('#repUnits').options).forEach(o => {
      o.selected = String(o.value) === String(wanted);
    });
  }
  const wantedType = params.get('type');
  const types = meta.report_types || [];
  const first = types.length ? types[0].id : 'unit_summary';
  selectType(types.some(t => t.id === wantedType) ? wantedType : first);

  $('#btnRun').addEventListener('click', () => run());
  $('#btnCsv').addEventListener('click', downloadCsv);
  $('#btnPrint').addEventListener('click', () => {
    if (!Reports.report) { toast('Run a report first', 'warn'); return; }
    printNode($('#reportOut'), Reports.report.type + ' report');
  });
  $('#btnSaveTemplate').addEventListener('click', saveTemplate);
  $('#repGroup').addEventListener('change', () => run());
});

/* ------------------------------------------------------------------ menu */
function buildMenu(types) {
  const box = $('#reportMenu');
  const groups = {};
  types.forEach(t => { (groups[t.group] = groups[t.group] || []).push(t); });
  Object.keys(groups).forEach(g => {
    box.appendChild(el('div', { class: 'group', text: g }));
    groups[g].forEach(t => {
      const n = el('div', { class: 'node', dataset: { type: t.id }, title: t.hint || '' });
      n.appendChild(el('div', { class: 'name', text: t.name }));
      n.addEventListener('click', () => selectType(t.id));
      box.appendChild(n);
    });
  });
}
function selectType(id) {
  if (!id) return;
  Reports.type = id;
  $$('#reportMenu .node').forEach(n => n.classList.toggle('active', n.dataset.type === id));
  run();
}
function buildPresets() {
  const box = $('#repPresets');
  rangePresets().forEach(p => {
    const b = el('button', { class: 'btn sm' + (p.id === '24h' ? ' active' : ''), text: p.label });
    b.addEventListener('click', () => {
      $$('#repPresets .btn').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      $('#repFrom').value = isoLocal(p.from);
      $('#repTo').value = isoLocal(p.to);
      run();
    });
    box.appendChild(b);
  });
  const now = new Date();
  $('#repFrom').value = isoLocal(new Date(now.getTime() - 86400000));
  $('#repTo').value = isoLocal(now);
}
function buildUnitSelect() {
  const sel = $('#repUnits');
  State.units.forEach(u => sel.appendChild(el('option', { value: u.id }, h(u.name))));
}
function buildEventTypes() {
  const sel = $('#repEventType');
  Reports.eventTypes.forEach(t => sel.appendChild(el('option', { value: t }, h(eventLabel(t)))));
}

/* ------------------------------------------------------------------- run */
function currentParams() {
  const chosen = Array.prototype.slice.call($('#repUnits').options)
    .filter(o => o.selected).map(o => o.value);
  const p = {
    type: Reports.type,
    from: ($('#repFrom').value || '').replace('T', ' ') || '-24 hours',
    to: ($('#repTo').value || '').replace('T', ' ') || 'now',
    group: $('#repGroup').value
  };
  if (chosen.length) p.units = chosen;
  if ($('#repSpeedLimit').value) p.speed_limit = Number($('#repSpeedLimit').value);
  if ($('#repFuel').value) p.min_change = Number($('#repFuel').value);
  if ($('#repEventType').value) p.event_types = [$('#repEventType').value];
  return p;
}
async function run() {
  if (!Reports.type) return;
  $('#reportStatus').textContent = 'Running…';
  try {
    const rep = await API.post('/api/reports/run', currentParams());
    Reports.report = rep;
    render(rep);
    $('#reportStatus').textContent = 'Generated ' + fmt.dt(rep.generated_at) + ' by ' + (rep.generated_by || '');
  } catch (e) {
    $('#reportStatus').textContent = 'Failed — ' + e.message;
  }
}
function render(rep) {
  const out = $('#reportOut');
  out.innerHTML = '';
  const head = el('div', { class: 'report-head' });
  const typeInfo = State.reportTypes.find(t => t.id === rep.type) || {};
  head.appendChild(el('h1', null, h(typeInfo.name || rep.type)));
  head.appendChild(el('div', { class: 'muted small' },
    'Period: <b>' + h(fmt.dt(rep.from)) + '</b> to <b>' + h(fmt.dt(rep.to)) + '</b><br>'
    + 'Units: ' + h((rep.units || []).map(u => u.name).join(', ') || 'none')
    + ' · generated ' + h(fmt.dt(rep.generated_at || '')) + ' by ' + h(rep.generated_by || '')));
  out.appendChild(head);
  tiles(out, rep.summary || []);
  const charts = el('div', { class: 'charts', style: 'margin:14px 0' });
  out.appendChild(charts);
  Charts.render(charts, rep.charts || []);
  (rep.tables || []).forEach(t => {
    out.appendChild(el('h2', null, h(t.title || 'Details')));
    const holder = el('div');
    out.appendChild(holder);
    renderTable(holder, { columns: t.columns || [], rows: t.rows || [], totals: t.totals });
  });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ---------------------------------------------------------------- export */
function downloadCsv() {
  if (!Reports.type) { toast('Pick a report type first', 'warn'); return; }
  const url = '/api/reports/export.csv' + qs(currentParams());
  fetch(url, { headers: { 'X-Auth-Token': API.token() } })
    .then(async r => {
      if (!r.ok) throw new Error((await r.text()).slice(0, 200));
      const blob = await r.blob();
      const a = el('a', { href: URL.createObjectURL(blob), download: 'kivu-' + Reports.type + '.csv' });
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
      toast('CSV downloaded', 'ok');
    })
    .catch(e => toast('CSV export failed: ' + e.message, 'error'));
}

/* ------------------------------------------------------------- templates */
async function loadTemplates() {
  const box = $('#templateList');
  try {
    const data = await API.get('/api/reports/templates');
    Reports.templates = data.templates || [];
  } catch (e) { return; }
  box.innerHTML = '';
  if (!Reports.templates.length) {
    box.appendChild(el('div', { class: 'hint', text: 'No saved templates yet.' }));
    return;
  }
  Reports.templates.forEach(t => {
    const row = el('div', { class: 'row' });
    const b = el('button', { class: 'btn sm grow', style: 'justify-content:flex-start', text: t.name });
    b.addEventListener('click', () => applyTemplate(t));
    const del = el('button', { class: 'btn xs danger', text: '✕' });
    del.addEventListener('click', async () => {
      const ok = await confirmDialog('Delete the template "' + t.name + '"?');
      if (!ok) return;
      try {
        await API.del('/api/reports/templates/' + encodeURIComponent(t.id));
        loadTemplates();
      } catch (e) { /* shown */ }
    });
    row.appendChild(b);
    row.appendChild(del);
    box.appendChild(row);
  });
}
function applyTemplate(t) {
  Reports.type = t.type;
  $$('#reportMenu .node').forEach(n => n.classList.toggle('active', n.dataset.type === t.type));
  const p = t.params || {};
  if (p.from) $('#repFrom').value = String(p.from).replace(' ', 'T').slice(0, 16);
  if (p.to) $('#repTo').value = String(p.to).replace(' ', 'T').slice(0, 16);
  if (p.group) $('#repGroup').value = p.group;
  if (p.speed_limit) $('#repSpeedLimit').value = p.speed_limit;
  if (p.min_change) $('#repFuel').value = p.min_change;
  run();
}
function saveTemplate() {
  if (!Reports.type) { toast('Pick a report type first', 'warn'); return; }
  const typeInfo = State.reportTypes.find(t => t.id === Reports.type) || {};
  formModal({
    title: 'Save report template',
    fields: [{ name: 'name', label: 'Template name', required: true, value: typeInfo.name || 'Report' }],
    saveLabel: 'Save',
    onSave: async form => {
      const res = await API.post('/api/reports/templates',
        { name: form.name, type: Reports.type, params: currentParams() });
      toast(res.message || 'Template saved', 'ok');
      loadTemplates();
    }
  });
}
