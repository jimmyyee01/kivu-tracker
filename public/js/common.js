/* =====================================================================
   KIVU Tracker — public/js/common.js
   Shared frontend library: API client, formatting, DOM helpers, toasts,
   modals, tables, tabs, date ranges, the unit tree and the top bar.
   Loaded by every page (no build step, no dependencies).
   ===================================================================== */
'use strict';

/* ------------------------------------------------------------ API client */
const API = (function () {
  let token = localStorage.getItem('kt_token') || '';

  /* A super administrator works inside one chosen account (see the account
     switcher in the top bar); the choice travels as ?account_id= so every
     endpoint is scoped server-side. Other roles ignore the extra parameter. */
  function scopePath(path) {
    try {
      if (!State.accountId || typeof path !== 'string' || path.indexOf('/api/') !== 0) return path;
      if (path.indexOf('account_id=') >= 0) return path;
      return path + (path.indexOf('?') >= 0 ? '&' : '?') + 'account_id=' + encodeURIComponent(State.accountId);
    } catch (e) { return path; }
  }

  function setToken(t) {
    token = t || '';
    if (token) localStorage.setItem('kt_token', token);
    else localStorage.removeItem('kt_token');
  }
  async function request(method, path, body, opts) {
    opts = opts || {};
    const headers = {};
    if (token) headers['X-Auth-Token'] = token;
    if (body !== undefined && body !== null && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(scopePath(path), {
      method: method,
      headers: headers,
      body: body === undefined || body === null ? undefined
        : (body instanceof FormData ? body : JSON.stringify(body))
    });
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch (e) { data = { raw: text }; }
    if (!res.ok || (data && data.ok === false)) {
      const msg = (data && data.error) ? data.error : ('HTTP ' + res.status);
      if (res.status === 401 && !opts.noRedirect && location.pathname !== '/login') {
        setToken('');
        location.href = '/login?expired=1';
      }
      const err = new Error(msg);
      err.status = res.status;
      err.data = data;
      /* HTTP 402 = the trial/subscription ended: offer the upgrade instead of a raw error */
      if (res.status === 402 && data && data.billing && typeof showPaymentRequired === 'function') {
        showPaymentRequired(msg);
      } else if (!opts.silent) toast(msg, 'error');
      throw err;
    }
    return data;
  }
  return {
    get: (p, o) => request('GET', p, null, o),
    post: (p, b, o) => request('POST', p, b, o),
    put: (p, b, o) => request('PUT', p, b, o),
    del: (p, o) => request('DELETE', p, null, o),
    setToken: setToken,
    token: () => token,
    hasToken: () => !!token,
    login: (username, password) => request('POST', '/api/auth/login', { username, password }, { noRedirect: true }),
    logout: async () => { try { await request('POST', '/api/auth/logout', {}, { silent: true }); } catch (e) {} setToken(''); },
    raw: request
  };
})();

/* ---------------------------------------------------------------- helpers */
const $ = (sel, root) => (root || document).querySelector(sel);
const $$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));
function h(s) {
  return String(s === null || s === undefined ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function el(tag, attrs, html) {
  const n = document.createElement(tag);
  if (attrs) Object.keys(attrs).forEach(k => {
    if (k === 'class') n.className = attrs[k];
    else if (k === 'text') n.textContent = attrs[k];
    else if (k === 'style') n.setAttribute('style', attrs[k]);
    else if (k.indexOf('on') === 0 && typeof attrs[k] === 'function') n.addEventListener(k.slice(2), attrs[k]);
    else if (k === 'dataset') Object.keys(attrs[k]).forEach(d => n.dataset[d] = attrs[k][d]);
    else n.setAttribute(k, attrs[k]);
  });
  if (html !== undefined && html !== null) {
    if (html instanceof Node) n.appendChild(html); else n.innerHTML = html;
  }
  return n;
}
function qs(obj) {
  const parts = [];
  Object.keys(obj || {}).forEach(k => {
    const v = obj[k];
    if (v === undefined || v === null || v === '') return;
    if (Array.isArray(v)) parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v.join(',')));
    else parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
  });
  return parts.length ? '?' + parts.join('&') : '';
}
function debounce(fn, ms) {
  let t = null;
  return function () {
    const args = arguments, self = this;
    clearTimeout(t);
    t = setTimeout(() => fn.apply(self, args), ms || 250);
  };
}
const store = {
  get(k, d) { try { const v = localStorage.getItem('wc_' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
  set(k, v) { try { localStorage.setItem('wc_' + k, JSON.stringify(v)); } catch (e) {} },
  del(k) { localStorage.removeItem('wc_' + k); }
};

/* ------------------------------------------------------------ formatting */
const fmt = {
  num(v, d) {
    if (v === null || v === undefined || isNaN(v)) return '';
    const n = Number(v);
    return n.toLocaleString(undefined, {
      minimumFractionDigits: d === undefined ? 0 : d,
      maximumFractionDigits: d === undefined ? 2 : d
    });
  },
  km(v) { return v === null || v === undefined ? '' : fmt.num(v, 2) + ' km'; },
  speed(v) { return v === null || v === undefined ? '' : fmt.num(v, 1) + ' km/h'; },
  liters(v) { return v === null || v === undefined ? '' : fmt.num(v, 1) + ' L'; },
  hours(v) { return v === null || v === undefined ? '' : fmt.num(v, 2) + ' h'; },
  money(v, cur) { return v === null || v === undefined ? '' : fmt.num(v, 2) + (cur ? ' ' + cur : ''); },
  pct(v) { return v === null || v === undefined ? '' : fmt.num(v, 1) + ' %'; },
  duration(sec) {
    if (sec === null || sec === undefined || sec === '') return '';
    let s = Math.round(Number(sec));
    const sign = s < 0 ? '-' : '';
    s = Math.abs(s);
    const d = Math.floor(s / 86400), hh = Math.floor((s % 86400) / 3600),
      mm = Math.floor((s % 3600) / 60), ss = s % 60;
    if (d > 0) return sign + d + 'd ' + hh + ':' + String(mm).padStart(2, '0');
    if (hh > 0) return sign + hh + ':' + String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
    return sign + mm + ':' + String(ss).padStart(2, '0');
  },
  dt(v) {
    if (!v) return '';
    const d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d)) return String(v);
    return d.toLocaleString(undefined, { year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit' });
  },
  dtShort(v) {
    if (!v) return '';
    const d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d)) return String(v);
    const sameDay = d.toDateString() === new Date().toDateString();
    return sameDay
      ? d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
      : d.toLocaleString(undefined, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
  },
  ago(v) {
    if (!v) return '';
    const t = new Date(String(v).replace(' ', 'T')).getTime();
    if (isNaN(t)) return '';
    const s = Math.max(0, Math.round((Date.now() - t) / 1000));
    if (s < 60) return s + 's ago';
    if (s < 3600) return Math.round(s / 60) + 'm ago';
    if (s < 86400) return Math.round(s / 3600) + 'h ago';
    return Math.round(s / 86400) + 'd ago';
  },
  cell(v, type) {
    if (v === null || v === undefined || v === '') return '';
    switch (type) {
      case 'km': return fmt.km(v);
      case 'speed': return fmt.speed(v);
      case 'liters': return fmt.liters(v);
      case 'hours': return fmt.hours(v);
      case 'duration': return fmt.duration(v);
      case 'datetime': return fmt.dt(v);
      case 'money': return fmt.money(v);
      case 'percent': return fmt.pct(v);
      case 'number': return fmt.num(v, 2);
      case 'html': return v;
      default: return h(v);
    }
  }
};

/* --------------------------------------------------------------- toasts */
function toast(message, type, ms) {
  let box = $('.toasts');
  if (!box) { box = el('div', { class: 'toasts' }); document.body.appendChild(box); }
  const t = el('div', { class: 'toast ' + (type || '') }, h(message));
  box.appendChild(t);
  setTimeout(() => {
    t.style.opacity = '0';
    setTimeout(() => t.remove(), 250);
  }, ms || (type === 'error' ? 6000 : 3200));
}

/* --------------------------------------------------------------- modals */
function modal(opts) {
  opts = opts || {};
  const back = el('div', { class: 'modal-back' });
  const card = el('div', { class: 'modal' + (opts.wide ? ' wide' : '') });
  const head = el('div', { class: 'modal-head' }, '<h3>' + h(opts.title || '') + '</h3>');
  const closeBtn = el('button', { class: 'btn ghost right', text: '\u2715' });
  head.appendChild(closeBtn);
  const body = el('div', { class: 'modal-body' });
  if (opts.body instanceof Node) body.appendChild(opts.body); else body.innerHTML = opts.body || '';
  card.appendChild(head); card.appendChild(body);
  const footer = el('div', { class: 'modal-foot hidden' });
  const api = {
    body: body,
    close() { back.remove(); document.removeEventListener('keydown', onKey); },
    setBusy(b) { $$('button', footer).forEach(x => { x.disabled = !!b; }); }
  };
  (opts.buttons || []).forEach(b => {
    const btn = el('button', { class: 'btn ' + (b.class || ''), text: b.label });
    btn.addEventListener('click', async () => {
      if (typeof b.onClick === 'function') {
        const r = await b.onClick(api);
        if (r !== false) api.close();
      } else api.close();
    });
    footer.appendChild(btn);
  });
  if ((opts.buttons || []).length) { footer.classList.remove('hidden'); card.appendChild(footer); }
  closeBtn.addEventListener('click', () => api.close());
  back.addEventListener('click', e => { if (e.target === back) api.close(); });
  function onKey(e) { if (e.key === 'Escape') api.close(); }
  document.addEventListener('keydown', onKey);
  back.appendChild(card);
  document.body.appendChild(back);
  return api;
}
function confirmDialog(message, confirmLabel) {
  return new Promise(resolve => {
    let done = false;
    const m = modal({
      title: 'Please confirm',
      body: '<p>' + h(message) + '</p>',
      buttons: [
        { label: 'Cancel', class: 'ghost', onClick: () => { done = true; resolve(false); } },
        { label: confirmLabel || 'Delete', class: 'danger', onClick: () => { done = true; resolve(true); } }
      ]
    });
    const orig = m.close;
    m.close = function () { orig.call(m); if (!done) resolve(false); };
  });
}

/* ------------------------------------------------------------ form tools */
function readForm(root) {
  const out = {};
  $$('input, select, textarea', root).forEach(f => {
    if (!f.name) return;
    if (f.type === 'checkbox') out[f.name] = f.checked;
    else if (f.type === 'radio') { if (f.checked) out[f.name] = f.value; }
    else if (f.tagName === 'SELECT' && f.multiple) {
      out[f.name] = Array.prototype.slice.call(f.options).filter(o => o.selected).map(o => o.value);
    }
    else if (f.type === 'number' || f.dataset.numeric === '1') out[f.name] = f.value === '' ? null : Number(f.value);
    else out[f.name] = f.value;
  });
  return out;
}
function fillForm(root, data) {
  $$('input, select, textarea', root).forEach(f => {
    if (!f.name || !data || !(f.name in data)) return;
    const v = data[f.name];
    if (f.type === 'checkbox') f.checked = !!v && v !== '0' && v !== 0;
    else f.value = v === null || v === undefined ? '' : v;
  });
}
function selectBox(name, options, selected, onChange) {
  const sel = el('select', { name: name });
  (options || []).forEach(o => {
    const value = o.value === undefined ? o.id : o.value;
    const label = o.label === undefined ? o.name : o.label;
    const opt = el('option', { value: value }, h(label));
    if (String(selected) === String(value)) opt.selected = true;
    sel.appendChild(opt);
  });
  if (onChange) sel.addEventListener('change', onChange);
  return sel;
}
function multiSelect(name, options, selected, size) {
  const sel = el('select', { name: name, multiple: 'multiple', size: size || 6 });
  const chosen = (selected || []).map(String);
  (options || []).forEach(o => {
    const value = o.value === undefined ? o.id : o.value;
    const opt = el('option', { value: value }, h(o.label === undefined ? o.name : o.label));
    if (chosen.indexOf(String(value)) >= 0) opt.selected = true;
    sel.appendChild(opt);
  });
  return sel;
}

/* ---------------------------------------------------------------- tables */
function renderTable(container, table) {
  const wrap = el('div', { class: 'table-wrap' });
  const t = el('table', { class: 'data' });
  const tr = el('tr');
  (table.columns || []).forEach(c => tr.appendChild(el('th', { text: c.label })));
  const thead = el('thead'); thead.appendChild(tr); t.appendChild(thead);
  const tbody = el('tbody');
  const rows = table.rows || [];
  if (!rows.length) {
    tbody.appendChild(el('tr', null,
      '<td colspan="' + (table.columns || []).length + '" class="muted center-text">No data</td>'));
  }
  rows.forEach((r, idx) => {
    const rtr = el('tr');
    if (table.onRowClick) {
      rtr.style.cursor = 'pointer';
      rtr.addEventListener('click', () => table.onRowClick(r, idx));
    }
    (table.columns || []).forEach(c => {
      const isNum = ['km', 'speed', 'liters', 'hours', 'money', 'number', 'percent'].indexOf(c.type) >= 0;
      const td = el('td', { class: isNum ? 'num' : '' });
      const val = r[c.key];
      td.innerHTML = c.type === 'badge'
        ? '<span class="chip ' + h(String(val || '').toLowerCase()) + '">' + h(val) + '</span>'
        : fmt.cell(val, c.type);
      rtr.appendChild(td);
    });
    tbody.appendChild(rtr);
  });
  t.appendChild(tbody);
  if (table.totals) {
    const tf = el('tfoot'); const ftr = el('tr');
    (table.columns || []).forEach((c, i) => {
      const v = (i === 0 && !table.totals[c.key]) ? 'TOTAL' : table.totals[c.key];
      const isNum = ['km', 'speed', 'liters', 'hours', 'money', 'number', 'percent', 'duration'].indexOf(c.type) >= 0;
      ftr.appendChild(el('td', { class: isNum ? 'num' : '' }, fmt.cell(v, c.type)));
    });
    tf.appendChild(ftr); t.appendChild(tf);
  }
  wrap.appendChild(t);
  container.innerHTML = '';
  container.appendChild(wrap);
  return wrap;
}
/* Colour-coded KPI tiles — shared by every dashboard (monitoring, admin,
   reports, billing, super, reseller). A metric can name its colour
   (`color: 'green'`) or state its meaning (`tone: 'warn'`); otherwise the
   palette cycles in order so neighbouring tiles never look alike. */
const TILE_COLORS = ['blue', 'green', 'violet', 'amber', 'cyan', 'orange', 'pink', 'teal', 'red'];
const TILE_TONES = { ok: 'green', good: 'green', up: 'green', positive: 'green', warn: 'amber',
  warning: 'amber', danger: 'red', bad: 'red', error: 'red', down: 'red', info: 'blue',
  neutral: 'violet', money: 'teal' };
function tileColor(s, i) {
  const want = String((s && (s.color || TILE_TONES[s.tone || ''])) || '').toLowerCase();
  return TILE_COLORS.indexOf(want) >= 0 ? want : TILE_COLORS[(i || 0) % TILE_COLORS.length];
}
function tiles(container, summary) {
  container.innerHTML = '';
  const row = el('div', { class: 'tile-row' });
  (summary || []).forEach((s, i) => {
    row.appendChild(el('div', { class: 'tile k-' + tileColor(s, i) },
      '<div class="l">' + h(s.label) + '</div><div class="v">' + h(s.value) + '</div>'));
  });
  container.appendChild(row);
  return row;
}

/* ------------------------------------------------------------------ tabs */
function initTabs(root) {
  const tabs = $$('.tabs .tab', root);
  tabs.forEach(tab => tab.addEventListener('click', () => {
    tabs.forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    $$('.tabpane', root).forEach(p => p.classList.remove('active'));
    const pane = $('#' + tab.dataset.pane, root);
    if (pane) pane.classList.add('active');
  }));
}

/* ----------------------------------------------------------- date ranges */
function rangePresets() {
  const now = new Date();
  const midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const day = 86400000;
  return [
    { id: 'today', label: 'Today', from: midnight, to: now },
    { id: 'yesterday', label: 'Yesterday', from: new Date(midnight - day), to: new Date(midnight - 1000) },
    { id: '24h', label: 'Last 24 hours', from: new Date(Date.now() - day), to: now },
    { id: '7d', label: 'Last 7 days', from: new Date(Date.now() - 7 * day), to: now },
    { id: '30d', label: 'Last 30 days', from: new Date(Date.now() - 30 * day), to: now }
  ];
}
function isoLocal(d) {
  const p = n => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T'
    + p(d.getHours()) + ':' + p(d.getMinutes());
}

/* ---------------------------------------------------------------- export */
function csvDownload(filename, rows) {
  const csv = rows.map(r => r.map(v => {
    const s = v === null || v === undefined ? '' : String(v);
    return /[",\n;]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }).join(',')).join('\r\n');
  const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
  const a = el('a', { href: URL.createObjectURL(blob), download: filename });
  document.body.appendChild(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}
function printNode(node, title) {
  const w = window.open('', '_blank');
  if (!w) { toast('Allow pop-ups to print the report', 'warn'); return; }
  const css = Array.prototype.map.call(document.querySelectorAll('link[rel=stylesheet]'), l => l.href);
  w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + h(title || 'Report') + '</title>'
    + css.map(u => '<link rel="stylesheet" href="' + u + '">').join('')
    + '</head><body style="background:#fff;padding:18px">'
    + '<div class="report">' + node.innerHTML + '</div></body></html>');
  w.document.close();
  setTimeout(() => { w.focus(); w.print(); }, 400);
}

/* ------------------------------------------------- generic form in a modal */
/* fields: [{name,label,type,value,options,required,help,step,min,max,rows}] */
function formModal(opts) {
  const body = el('div');
  const values = opts.values || {};
  (opts.fields || []).forEach(f => {
    const wrap = el('div', { class: 'field' });
    wrap.appendChild(el('label', null, h(f.label) + (f.required ? ' *' : '')));
    const val = values[f.name] !== undefined && values[f.name] !== null ? values[f.name]
      : (f.value !== undefined ? f.value : '');
    let input;
    if (f.type === 'select') input = selectBox(f.name, f.options || [], val);
    else if (f.type === 'textarea') {
      input = el('textarea', { name: f.name, rows: f.rows || 3 });
      input.value = val;
    } else if (f.type === 'checkbox') {
      input = el('input', { type: 'checkbox', name: f.name });
      input.checked = !!val && val !== '0' && val !== 0;
    } else if (f.type === 'multiselect') {
      input = multiSelect(f.name, f.options || [], f.selected || [], f.size || 6);
    } else {
      input = el('input', { type: f.type || 'text', name: f.name, step: f.step,
        min: f.min, max: f.max, placeholder: f.placeholder || '' });
      input.value = val;
    }
    wrap.appendChild(input);
    if (f.help) wrap.appendChild(el('div', { class: 'hint' }, h(f.help)));
    body.appendChild(wrap);
  });
  return modal({
    title: opts.title,
    body: body,
    wide: !!opts.wide,
    buttons: [
      { label: 'Cancel', class: 'ghost' },
      { label: opts.saveLabel || 'Save', class: 'primary', onClick: async m => {
        m.setBusy(true);
        try {
          const ok = await opts.onSave(readForm(body));
          return ok !== false;
        } catch (e) { m.setBusy(false); return false; }
      } }
    ]
  });
}
/* values of a <select multiple> inside a form */
function selectedValues(root, name) {
  const sel = root.querySelector('select[name="' + name + '"]');
  if (!sel) return [];
  return Array.prototype.slice.call(sel.options).filter(o => o.selected).map(o => o.value);
}

/* ------------------------------------------------------------------ state */
const State = {
  user: null, meta: null, units: [], groups: [], geofences: [], reportTypes: [],
  selectedUnit: store.get('selectedUnit', ''),
  accountId: store.get('accountId', ''),        // super-admin account context
  currency: 'USD', onlineTimeout: 300
};

/* A token in the URL (?token=…) is adopted once and then removed from the
   address bar. Useful for kiosk/embedded dashboards and for automated
   headless page tests, which cannot pre-seed localStorage. */
(function adoptTokenFromUrl() {
  try {
    const params = new URLSearchParams(location.search);
    const t = params.get('token');
    if (!t) return;
    API.setToken(t);
    params.delete('token');
    const q = params.toString();
    history.replaceState(null, '', location.pathname + (q ? '?' + q : '') + (location.hash || ''));
  } catch (e) { /* older browsers: ignore */ }
})();
async function loadMeta() {
  State.meta = await API.get('/api/meta');
  State.user = State.meta.user;
  setPoweredBy(State.meta.powered_by || '');
  State.units = State.meta.units || [];
  State.groups = State.meta.groups || [];
  State.geofences = State.meta.geofences || [];
  State.reportTypes = State.meta.report_types || [];
  State.currency = (State.meta.settings && State.meta.settings.currency) || 'USD';
  State.onlineTimeout = (State.meta.settings && State.meta.settings.online_timeout_s) || 300;
  applyTheme();
  return State.meta;
}
async function loadUnitsLive(extra) {
  const data = await API.get('/api/units/live' + qs(extra || {}));
  State.units = data.units || [];
  return State.units;
}

/* Colours / labels shared by the map, the tree and the inspector. */
const STATUS = {
  moving: { color: '#2ecc71', label: 'Moving' },
  idle: { color: '#f1c40f', label: 'Idle (engine on)' },
  parked: { color: '#2f9cf4', label: 'Parked' },
  offline: { color: '#7d8598', label: 'Offline' },
  never: { color: '#8b93a7', label: 'No data' }
};
const EVENT_LABELS = {
  speed: 'Speed', movement: 'Movement start', ignition_on: 'Ignition ON', ignition_off: 'Ignition OFF',
  sos: 'SOS', fuel_drop: 'Fuel drop', fuel_fill: 'Refuel', power_cut: 'Power cut', idle: 'Long idle',
  offline: 'No data', maintenance: 'Service due', geofence_enter: 'Geofence enter',
  geofence_exit: 'Geofence exit', text_message: 'Message'
};
function eventLabel(type) { return EVENT_LABELS[type] || type; }
function severityClass(s) { return s === 'critical' ? 'danger' : (s === 'warning' ? 'warn' : 'info'); }

/* ---------------------------------------------------------- branding ----- */
/* The platform logo (Platform settings → Logo) replaces the default CSS mark
   in every brand spot: the top bar, the login/landing/sign-up/pay headers — and
   it becomes the browser favicon and the home-screen icon. Public pages get it
   from /api/health & /api/plans, consoles from /api/meta; the last known value
   is cached so a cold page still shows it. */
function brandingLogo() {
  const meta = (State.meta && State.meta.logo) || '';
  return meta || store.get('logo', '') || '';
}
function brandingRemember(logo) {
  if (logo !== undefined && logo !== null) store.set('logo', String(logo));
  updateSupportAssistantLogo();
}
function brandingSig() {          /* cache-buster so a replaced file is not stale */
  const l = brandingLogo();
  const m = /logo-([0-9a-f]{8,})/i.exec(l);
  return m ? '?v=' + m[1].slice(0, 10) : '';
}
function applyBranding(root) {
  const logo = brandingLogo();
  const scope = root || document;
  $$('span.logo', scope).forEach(span => {
    let img = span.nextElementSibling;
    if (!(img && img.tagName === 'IMG' && img.classList.contains('logo-img'))) img = null;
    if (!logo) {
      span.style.display = '';
      if (img) img.remove();
      return;
    }
    span.style.display = 'none';
    if (!img) {
      img = el('img', { class: 'logo-img', alt: (State.meta && State.meta.platform) || 'Logo' });
      span.parentNode.insertBefore(img, span.nextSibling);
    }
    const src = logo + brandingSig();
    if (img.getAttribute('src') !== src) img.src = src;
  });
  /* favicon + apple-touch icon follow the same image */
  const head = document.head;
  let icon = document.getElementById('brandIcon');
  let touch = document.getElementById('brandTouch');
  if (logo) {
    if (!icon) { icon = el('link', { id: 'brandIcon', rel: 'icon' }); head.appendChild(icon); }
    if (!touch) { touch = el('link', { id: 'brandTouch', rel: 'apple-touch-icon' }); head.appendChild(touch); }
    icon.href = logo + brandingSig();
    touch.href = logo + brandingSig();
  } else {
    if (icon) icon.remove();
    if (touch) touch.remove();
  }
  updateSupportAssistantLogo();
}
document.addEventListener('DOMContentLoaded', () => { try { applyBranding(); } catch (e) { /* ignore */ } });

function setPoweredBy(value) {
  const text = String(value || '').trim();
  store.set('poweredBy', text);
  renderPoweredBy(text);
}
function renderPoweredBy(value) {
  const text = String(value || '').trim();
  let footer = document.getElementById('platformPoweredBy');
  if (!text) {
    if (footer) footer.remove();
    return;
  }
  if (!footer) {
    footer = el('footer', { id: 'platformPoweredBy', class: 'platform-powered-by', role: 'contentinfo' });
    document.body.appendChild(footer);
  }
  footer.textContent = text;
}
document.addEventListener('DOMContentLoaded', () => {
  renderPoweredBy(store.get('poweredBy', ''));
});

/* In-app client support. Answers are local guidance only; questions and account
   data are never sent to an external AI service. */
function supportAnswer(question) {
  const q = question.toLowerCase();
  if (/\b(hello|hi|hey)\b/.test(q)) {
    return 'Hi! I can guide you through common KIVU Tracker tasks. Choose a suggested question or ask about devices, users, reports, login, or billing.';
  }
  if (/\b(viewer|view.?only|read.?only|permission|permissions|rights|unit access)\b/.test(q)) {
    return 'An account admin can open Administration → Users, create or edit a user, choose “viewer — read only”, and select the units they may see. Leave unit access unselected to allow all units in that account. Viewers can read but cannot make changes.';
  }
  if (/\b(gps|tracker|device|imei|offline|connect|not reporting)\b/.test(q)) {
    return 'Add the vehicle under Administration → Units → New unit. Enter its device ID and matching device password. For the built-in Wialon IPS 1.1 server, configure the tracker to send TCP to your server on port 20332; confirm the tracker supports IPS, the daemon is running, and TCP 20332 is open. Other protocols need a compatible gateway.';
  }
  if (/\b(report|export|csv|trip)\b/.test(q)) {
    return 'Open Reports to choose a report type and time range. Run the report, then use its CSV export option when available. Reports only include units you are allowed to access.';
  }
  if (/\b(bill|billing|payment|invoice|trial|subscription|plan)\b/.test(q)) {
    return 'Open Billing to check your plan, trial or subscription, invoices, and payment options. If your account is expired or payment is blocked, ask your account administrator for help.';
  }
  if (/\b(user|users|account|invite|create)\b/.test(q)) {
    return 'An account admin can manage users in Administration → Users. Create a user, assign a role, and optionally limit access to selected units. Only a platform super-admin can manage other accounts.';
  }
  if (/\b(login|log in|sign in|password|locked out)\b/.test(q)) {
    return 'Check that you are using your KIVU Tracker username and password at the sign-in page. If you forgot your password, ask your account administrator to reset it; never share your password in this support panel.';
  }
  return 'I’m the in-app help guide, not a live AI agent. I can help with device setup, read-only users, reports, login, and billing. Please don’t enter passwords, GPS locations, or other sensitive information here. For account-specific help, contact your account administrator.';
}

function initSupportAssistant() {
  if (document.getElementById('supportAssistant')) return;
  const root = el('aside', { id: 'supportAssistant', class: 'support-assistant no-print' });
  const toggle = el('button', { id: 'supportAssistantToggle', class: 'support-toggle', type: 'button',
    'aria-label': 'Open KIVU Tracker support assistant', 'aria-expanded': 'false', 'aria-controls': 'supportAssistantPanel' });
  toggle.appendChild(el('span', { id: 'supportAssistantFallback', class: 'support-toggle-mark',
    'aria-hidden': 'true', text: 'K' }));
  toggle.appendChild(el('img', { id: 'supportAssistantLogo', class: 'support-toggle-logo',
    alt: '', hidden: 'hidden', 'aria-hidden': 'true' }));
  const panel = el('section', { id: 'supportAssistantPanel', class: 'support-panel', hidden: 'hidden',
    'aria-label': 'KIVU Tracker support assistant', role: 'region' });
  const heading = el('div', { class: 'support-heading' });
  const title = el('div');
  title.appendChild(el('strong', { text: 'KIVU Support' }));
  title.appendChild(el('span', { class: 'support-status', text: 'In-app help guide · not live AI' }));
  heading.appendChild(title);
  const close = el('button', { class: 'support-close', type: 'button', text: 'Close',
    'aria-label': 'Close support assistant' });
  heading.appendChild(close);
  panel.appendChild(heading);

  const messages = el('div', { class: 'support-messages', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' });
  messages.appendChild(el('p', { class: 'support-message assistant', text:
    'Hello! I can point you to common tasks in KIVU Tracker. Choose a question or type what you need help with.' }));
  panel.appendChild(messages);

  const suggestions = el('div', { class: 'support-suggestions', 'aria-label': 'Suggested questions' });
  [
    'How do I add a GPS tracker?',
    'How do I give someone view-only access?',
    'Where can I find reports?'
  ].forEach(text => {
    const button = el('button', { type: 'button', class: 'support-suggestion', text: text });
    button.addEventListener('click', () => askSupportQuestion(text));
    suggestions.appendChild(button);
  });
  panel.appendChild(suggestions);

  const form = el('form', { class: 'support-form' });
  const input = el('input', { id: 'supportQuestion', type: 'text', maxlength: '240',
    placeholder: 'Ask about using the system…', 'aria-label': 'Ask a support question', autocomplete: 'off' });
  const send = el('button', { type: 'submit', class: 'btn primary sm', text: 'Ask' });
  form.appendChild(input);
  form.appendChild(send);
  panel.appendChild(form);
  panel.appendChild(el('p', { class: 'support-privacy', text:
    'Answers are built in. Don’t enter passwords or sensitive data; nothing is sent to an AI service.' }));
  root.appendChild(panel);
  root.appendChild(toggle);
  document.body.appendChild(root);
  updateSupportAssistantLogo();

  function setOpen(open) {
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) input.focus();
    else toggle.focus();
  }
  toggle.addEventListener('click', () => setOpen(panel.hidden));
  close.addEventListener('click', () => setOpen(false));
  panel.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); setOpen(false); }
  });
  form.addEventListener('submit', event => {
    event.preventDefault();
    askSupportQuestion(input.value);
    input.value = '';
    input.focus();
  });

  function askSupportQuestion(question) {
    const text = String(question || '').trim();
    if (!text) { input.focus(); return; }
    messages.appendChild(el('p', { class: 'support-message user', text: text }));
    messages.appendChild(el('p', { class: 'support-message assistant', text: supportAnswer(text) }));
    messages.scrollTop = messages.scrollHeight;
  }
}
function updateSupportAssistantLogo() {
  const image = document.getElementById('supportAssistantLogo');
  const fallback = document.getElementById('supportAssistantFallback');
  if (!image || !fallback) return;
  const logo = brandingLogo();
  if (!logo) {
    image.hidden = true;
    fallback.hidden = false;
    image.removeAttribute('src');
    return;
  }
  const src = logo + brandingSig();
  if (image.getAttribute('src') !== src) {
    image.onload = () => { image.hidden = false; fallback.hidden = true; };
    image.onerror = () => { image.hidden = true; fallback.hidden = false; };
    image.src = src;
  } else if (image.complete && image.naturalWidth > 0) {
    image.hidden = false;
    fallback.hidden = true;
  }
}
document.addEventListener('DOMContentLoaded', initSupportAssistant);

/* ---------------------------------------------------------------- top bar */
function renderTopBar(active) {
  const role = State.user ? State.user.role : '';
  const bar = el('div', { class: 'topbar no-print' });
  bar.appendChild(el('div', { class: 'brand' }, '<span class="logo"></span><span>'
    + h((State.meta && State.meta.platform) || 'KIVU Tracker') + '</span>'));
  const nav = el('nav', { class: 'topbar-nav', id: 'primaryNavigation', 'aria-label': 'Main navigation' });
  const navToggle = el('button', {
    class: 'btn sm ghost nav-toggle', type: 'button', 'aria-expanded': 'false',
    'aria-controls': 'primaryNavigation', 'aria-label': 'Open navigation menu'
  }, 'Menu');
  navToggle.addEventListener('click', () => {
    const expanded = bar.classList.toggle('mobile-open');
    navToggle.setAttribute('aria-expanded', String(expanded));
    navToggle.setAttribute('aria-label', expanded ? 'Close navigation menu' : 'Open navigation menu');
  });
  nav.addEventListener('click', event => {
    if (!event.target.closest('a')) return;
    bar.classList.remove('mobile-open');
    navToggle.setAttribute('aria-expanded', 'false');
    navToggle.setAttribute('aria-label', 'Open navigation menu');
  });
  bar.appendChild(navToggle);
  const links = [
    { href: '/app', label: 'Monitoring', match: 'app', roles: ['super', 'admin', 'operator', 'viewer'] },
    { href: '/reports', label: 'Reports', match: 'reports', roles: ['super', 'admin', 'operator', 'viewer'] },
    { href: '/admin', label: 'Administration', match: 'admin', roles: ['super', 'admin', 'operator'] },
    { href: '/billing', label: 'Billing', match: 'billing', roles: ['super', 'admin'] },
    { href: '/super', label: 'Accounts', match: 'super', roles: ['super'] },
    { href: '/reseller', label: 'My portal', match: 'reseller', roles: ['reseller'] },
    { href: '/download', label: 'Get the app', match: 'download',
      roles: ['super', 'admin', 'operator', 'viewer', 'reseller'] }
  ];
  links.forEach(l => {
    if (l.roles.indexOf(role) < 0) return;
    nav.appendChild(el('a', { class: 'navlink' + (active === l.match ? ' active' : ''), href: l.href }, h(l.label)));
  });
  bar.appendChild(nav);
  bar.appendChild(el('div', { class: 'spacer' }));
  /* theme switch — rendered always, hidden when the super admin locked the
     theme (theme_mode = dark / light disables both modes platform-wide) */
  const themeBtn = el('button', { class: 'btn sm ghost', id: 'themeBtn', type: 'button' });
  themeBtn.addEventListener('click', toggleTheme);
  bar.appendChild(themeBtn);
  if (role === 'super' && active !== 'super') bar.appendChild(accountPicker());
  bar.appendChild(el('div', { class: 'who' },
    '<b>' + h((State.user && (State.user.full_name || State.user.username)) || '') + '</b>'
    + h(role + (State.accountName ? ' · ' + State.accountName : ''))));
  const logout = el('button', { class: 'btn sm ghost', text: 'Sign out' });
  logout.addEventListener('click', async () => { await API.logout(); location.href = '/login'; });
  bar.appendChild(logout);
  const host = $('#topbar');
  if (host) host.replaceWith(bar); else document.body.insertBefore(bar, document.body.firstChild);
  applyTheme();
  applyBranding(bar);
  return bar;
}

/* ---------------------------------------------------------------- themes */
/* Platform policy (settings.theme_mode): "user" lets every signed-in user
   pick dark or light (persisted per browser); "dark" / "light" locks the
   whole platform to one theme and hides the switch — that is how both modes
   can be disabled. */
const Theme = {
  mode: function () {
    return (State.meta && State.meta.settings && State.meta.settings.theme_mode) || 'user';
  },
  pref: function () {
    try { return localStorage.getItem('kivu_theme') || 'dark'; } catch (e) { return 'dark'; }
  },
  setPref: function (t) {
    try { localStorage.setItem('kivu_theme', t); } catch (e) { /* private mode */ }
  },
  effective: function () {
    const m = Theme.mode();
    return (m === 'dark' || m === 'light') ? m : Theme.pref();
  }
};
function applyTheme() {
  document.documentElement.setAttribute('data-theme', Theme.effective());
  const btn = document.getElementById('themeBtn');
  if (!btn) return;
  const locked = Theme.mode() !== 'user';
  btn.style.display = locked ? 'none' : '';
  btn.textContent = Theme.effective() === 'light' ? '🌙' : '☀️';
  btn.title = locked ? 'The platform theme is set by the administrator'
    : 'Switch between dark and light mode';
}
function toggleTheme() {
  Theme.setPref(Theme.effective() === 'light' ? 'dark' : 'light');
  applyTheme();
}

/* Super administrators pick the account (resource) they are working in.
   The choice is persisted and appended to every API call as ?account_id=. */
function accountPicker() {
  const wrap = el('div', { class: 'row', style: 'gap:6px' });
  wrap.appendChild(el('span', { class: 'tiny dim nowrap' }, 'Account'));
  const sel = el('select', { id: 'accountPicker', style: 'width:auto;max-width:220px' });
  sel.appendChild(el('option', { value: '' }, '…'));
  wrap.appendChild(sel);
  API.get('/api/accounts', { silent: true }).then(data => {
    const list = data.accounts || [];
    sel.innerHTML = '';
    if (!list.length) {
      sel.appendChild(el('option', { value: '' }, 'no accounts yet'));
      return;
    }
    list.forEach(a => {
      const o = el('option', { value: a.id }, h(a.name));
      if (String(a.id) === String(State.accountId)) o.selected = true;
      sel.appendChild(o);
    });
    if (!State.accountId) {
      /* first visit: default to the first account, reload once so every panel starts scoped */
      State.accountId = String(list[0].id);
      store.set('accountId', State.accountId);
      if (!sessionStorage.getItem('kt_account_boot')) {
        sessionStorage.setItem('kt_account_boot', '1');
        location.reload();
      }
    }
    sel.addEventListener('change', () => {
      State.accountId = sel.value;
      State.accountName = (list.find(a => String(a.id) === sel.value) || {}).name || '';
      store.set('accountId', sel.value);
      location.reload();
    });
  }).catch(() => {
    sel.innerHTML = '';
    sel.appendChild(el('option', { value: '' }, 'accounts unavailable'));
  });
  return wrap;
}
/* Guard used by every authenticated page. */
async function boot(active) {
  if (!API.hasToken()) { location.href = '/login'; return null; }
  try { await loadMeta(); }
  catch (e) { API.setToken(''); location.href = '/login'; return null; }
  renderTopBar(active);
  if (active !== 'billing') renderBillingBanner();
  if (!State.selectedUnit && State.units.length) State.selectedUnit = String(State.units[0].id);
  return State.meta;
}

/* ------------------------------------------------------------- billing UX --
   Trial countdown / expired banner shown on every console page, plus the
   upgrade prompt used when the API answers "402 payment required". */
function billingBannerText(b) {
  if (!b) return '';
  if (b.status === 'trialing') {
    if (b.trial_days_left <= 0) return 'Your free trial has ended — choose a plan to keep tracking.';
    if (b.trial_days_left <= 3) {
      return 'Free trial: ' + b.trial_days_left + ' day' + (b.trial_days_left === 1 ? '' : 's')
        + ' left. Choose a plan to keep tracking without interruption.';
    }
    return '';
  }
  if (b.status === 'past_due') {
    return 'Payment not received — ' + (b.grace_days_left || 0) + ' day(s) of grace left before tracking stops.';
  }
  if (b.status === 'expired') return b.reason || 'Your subscription has ended — renew to keep tracking.';
  if (b.status === 'suspended') return 'This account is suspended — contact support.';
  return '';
}
function renderBillingBanner() {
  const b = State.billing || (State.meta && State.meta.billing);
  const text = billingBannerText(b);
  if (!text) return null;
  const danger = b.status === 'expired' || b.status === 'suspended';
  const bar = el('div', { class: 'banner ' + (danger ? 'danger' : 'warn'), id: 'billingBanner' });
  bar.appendChild(el('span', null, text));
  bar.appendChild(el('span', { class: 'spacer' }));
  if (!b.super) bar.appendChild(el('a', { class: 'btn sm primary', href: '/billing' }, 'Choose a plan'));
  const host = $('#topbar') ? $('#topbar').nextSibling : null;
  if (host && host.parentNode) host.parentNode.insertBefore(bar, host);
  else document.body.insertBefore(bar, document.body.firstChild);
  return bar;
}
/* Modal shown when the API refuses a change because the trial/plan ended. */
let _payRequiredOpen = false;
function showPaymentRequired(message) {
  if (_payRequiredOpen) return null;
  _payRequiredOpen = true;
  const body = el('div');
  body.appendChild(el('p', null, message || 'This account needs an active plan for that action.'));
  body.appendChild(el('p', { class: 'hint' }, 'Read-only access (maps, tracks, reports and CSV export) stays available, '
    + 'and devices resume as soon as a plan is active.'));
  const when = el('div', { class: 'row wrap' });
  when.appendChild(el('a', { class: 'btn primary', href: '/billing' }, 'Choose a plan →'));
  when.appendChild(el('a', { class: 'btn ghost', href: '/reports' }, 'Open reports'));
  body.appendChild(when);
  const m = modal({
    title: 'Subscription required', body: body,
    buttons: [{ label: 'Close', class: 'ghost' }]
  });
  const orig = m.close;
  m.close = function () { _payRequiredOpen = false; orig.call(m); };
  return m;
}

/* ------------------------------------------------------------- map styles - */
/* Extra FREE tile layers. The platform map (settings.map_tiles) stays the
   default ("Platform default"); the choice is remembered per browser. */
const MAP_STYLES = [
  { id: 'default', label: 'Map: platform default', url: '' },
  { id: 'osm', label: 'OpenStreetMap (standard)', url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' },
  { id: 'topo', label: 'OpenTopoMap (terrain)', url: 'https://tile.opentopomap.org/{z}/{x}/{y}.png' },
  { id: 'hot', label: 'OSM Humanitarian', url: 'https://a.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png' },
  { id: 'cyclosm', label: 'CyclOSM (cycling)', url: 'https://a.tile-cyclosm.openstreetmap.fr/cyclosm/{z}/{x}/{y}.png' },
  { id: 'carto_light', label: 'Carto Positron (light)', url: 'https://a.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png' },
  { id: 'carto_dark', label: 'Carto Dark Matter (dark)', url: 'https://a.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png' }
];
function mapStyleId() {
  try { return localStorage.getItem('kivu_map') || 'default'; } catch (e) { return 'default'; }
}
/* '' means "use the platform tiles" (the default). */
function mapStyleUrl() {
  const s = MAP_STYLES.find(x => x.id === mapStyleId()) || MAP_STYLES[0];
  return s.url || '';
}
/* The picker itself — drop it into any toolbar. */
function mapStyleSelect(onPick) {
  const sel = el('select', { id: 'mapStyle', title: 'Map style',
    style: 'width:auto;max-width:200px;padding:5px 7px' });
  MAP_STYLES.forEach(s => sel.appendChild(el('option', { value: s.id }, s.label)));
  sel.value = mapStyleId();
  sel.addEventListener('change', () => {
    try { localStorage.setItem('kivu_map', sel.value); } catch (e) { /* ignore */ }
    if (onPick) onPick(mapStyleUrl());
  });
  return sel;
}
function applyMapStyle(map) {
  if (!map || typeof map.setTiles !== 'function') return;
  const url = mapStyleUrl() || (State.meta && State.meta.map_tiles) || '';
  try { map.setTiles(url); } catch (e) { /* canvas backend may ignore tiles */ }
}

/* One glyph per asset type (captured at registration / on the unit) — used
   by the map markers, the unit tree and the console tables. Mirrors the
   server catalogue in php/config.php so icons work fully offline. */
const ASSET_TYPES = [
  ['lorry', 'Lorry / truck', '🚚'], ['motorcycle', 'Motorcycle', '🏍'],
  ['bicycle', 'Bicycle', '🚲'], ['car', 'Car', '🚗'], ['van', 'Van / minibus', '🚐'],
  ['bus', 'Bus', '🚌'], ['tractor', 'Tractor', '🚜'], ['boat', 'Boat', '🚢'],
  ['machine', 'Machine / plant', '🏗'], ['other', 'Other asset', '📦']
];
const ASSET_GLYPHS = {};
ASSET_TYPES.forEach(t => { ASSET_GLYPHS[t[0]] = t[2]; });
function assetGlyph(type) { return (type && ASSET_GLYPHS[type]) || ''; }
function assetLabel(type) {
  const t = ASSET_TYPES.find(x => x[0] === type);
  return t ? (t[2] + ' ' + t[1]) : (type || '');
}
function assetOptions() { return ASSET_TYPES.map(t => ({ value: t[0], label: t[2] + ' ' + t[1] })); }

/* Hardware types come from the platform's supported-device catalogue
   (/api/meta → device_catalogue), with a safe fallback for old sessions. */
function hwTypeOptions() {
  const list = (State.meta && State.meta.device_catalogue) || [];
  if (!list.length) return [{ value: 'ips', label: 'ips — Wialon IPS 1.1 tracker' }];
  return list.map(d => ({ value: d.code, label: d.code + ' — ' + d.label }));
}

/* -------------------------------------------------------------- unit tree */
function renderUnitTree(container, opts) {
  opts = opts || {};
  container.innerHTML = '';
  const wrap = el('div', { class: 'tree' });
  const list = (opts.units || State.units).slice();
  const groups = {};
  list.forEach(u => {
    const g = u.group_name || (u.group_id ? u.group_id : 'Ungrouped');
    (groups[g] = groups[g] || []).push(u);
  });
  Object.keys(groups).sort().forEach(g => {
    wrap.appendChild(el('div', { class: 'group' }, h(g) + ' (' + groups[g].length + ')'));
    groups[g].sort((a, b) => String(a.name).localeCompare(String(b.name))).forEach(u => {
      const st = STATUS[u.status] || STATUS.never;
      const node = el('div', {
        class: 'node' + (String(u.id) === String(opts.selected || State.selectedUnit) ? ' active' : ''),
        dataset: { unit: u.id }
      });
      node.appendChild(el('span', { class: 'dot', style: 'background:' + (u.color || st.color) }));
      const glyph = assetGlyph(u.asset_type);
      if (glyph) node.appendChild(el('span', { class: 'asset-ico', title: assetLabel(u.asset_type) }, glyph));
      node.appendChild(el('div', { class: 'name' }, h(u.name)));
      node.appendChild(el('span', { class: 'meta' },
        h(u.speed > 1 ? fmt.num(u.speed, 0) + ' km/h' : fmt.ago(u.last_time))));
      node.addEventListener('click', () => {
        $$('.node', wrap).forEach(n => n.classList.remove('active'));
        node.classList.add('active');
        if (opts.onSelect) opts.onSelect(u);
      });
      wrap.appendChild(node);
    });
  });
  if (!list.length) wrap.appendChild(el('div', { class: 'pad muted' }, 'No units yet.'));
  container.appendChild(wrap);
  return wrap;
}
