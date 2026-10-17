/* =====================================================================
   KIVU Tracker — public/js/super.js
   Platform console (super administrator): accounts, platform settings,
   service health, device connections and the audit trail.
   ===================================================================== */
'use strict';

const Super = { accounts: [], settings: {} };

document.addEventListener('DOMContentLoaded', async function () {
  await boot('super');
  if (!State.user || State.user.role !== 'super') {
    /* explain instead of bouncing the user back to the console */
    const tabs = $('#superTabs');
    if (tabs) tabs.remove();
    const host = $('.tabbody') || document.body;
    host.innerHTML = '';
    const card = el('div', { class: 'card pad', style: 'max-width:600px;margin:24px auto' });
    card.appendChild(el('h2', null, 'Accounts console is limited to the super administrator'));
    card.appendChild(el('p', { class: 'muted' },
      'This page creates accounts (resources) and edits platform-wide settings. You are signed in as '
      + h((State.user && State.user.username) || 'unknown') + ' ('
      + h((State.user && State.user.role) || 'no role') + ').'));
    const row = el('div', { class: 'row wrap' });
    row.appendChild(el('a', { class: 'btn primary', href: '/app' }, 'Go to monitoring'));
    row.appendChild(el('a', { class: 'btn', href: '/admin' }, 'Administration'));
    row.appendChild(el('a', { class: 'btn ghost', href: '/reports' }, 'Reports'));
    card.appendChild(row);
    card.appendChild(el('p', { class: 'hint', style: 'margin-top:12px' },
      'Account administrators manage their own units, geofences, notification rules, users and reports under Administration.'));
    host.appendChild(card);
    return;
  }
  initTabs($('#superTabs').parentElement);
  $('#btnNewAccount').addEventListener('click', () => editAccount(null));
  $('#btnSaveSettings').addEventListener('click', saveSettings);
  $('#btnRunJobs').addEventListener('click', runJobs);
  $('#btnReloadHealth').addEventListener('click', loadHealth);
  $('#btnReloadBilling').addEventListener('click', () => loadBilling(true));
  $('#btnNewPlan').addEventListener('click', () => editPlan(null));
  $('#btnNewPortal').addEventListener('click', newPortal);
  $('#btnLogoUpload').addEventListener('click', uploadLogo);
  $('#btnLogoRemove').addEventListener('click', removeLogo);
  $('#btnRunFinance').addEventListener('click', () => loadFinance(true));
  $('#btnFinanceAlerts').addEventListener('click', () => loadAlerts(true));
  $$('#paneReports button[data-csv]').forEach(b => b.addEventListener('click',
    () => financeCsv(b.dataset.csv)));
  $('#btnSaveGateways').addEventListener('click', saveGateways);
  $('#superTabs').addEventListener('click', ev => {
    const tab = ev.target.closest ? ev.target.closest('.tab') : null;
    if (!tab) return;
    if (tab.dataset.pane === 'paneAccounts') loadAccounts();
    if (tab.dataset.pane === 'paneBilling') loadBilling();
    if (tab.dataset.pane === 'paneSettings') loadSettings();
    if (tab.dataset.pane === 'paneSystem') { loadHealth(); loadDevices(); loadAudit(); renderDeviceCatalogue(); }
    if (tab.dataset.pane === 'paneReports') loadFinance();
  });
  await loadAccounts();
  await loadSettings();
  loadBilling();
  loadHealth();
  loadDevices();
  loadAudit();
});

/* -------------------------------------------------------- plans & billing */
const Billing = { data: null, payments: [] };

async function loadBilling(showToast) {
  let data;
  try {
    data = await API.get('/api/billing/overview', { silent: true });
  } catch (e) {
    $('#billingHint').textContent = 'Billing overview unavailable: ' + e.message;
    return;
  }
  Billing.data = data;
  Billing.payments = data.payments || [];
  const t = data.totals || {};
  const box = $('#billingTotals');
  box.innerHTML = '';
  tiles(box, [
    { label: 'Accounts', value: String(t.accounts || 0), color: 'violet' },
    { label: 'Trialing', value: String(t.trialing || 0), color: 'blue' },
    { label: 'Active', value: String(t.active || 0), tone: 'ok' },
    { label: 'Expired / due', value: String(t.expired || 0),
      tone: (t.expired || 0) > 0 ? 'danger' : 'neutral' },
    { label: 'Trial ending ≤2d', value: String(t.trial_ending_soon || 0),
      tone: (t.trial_ending_soon || 0) > 0 ? 'warn' : 'neutral' },
    { label: 'MRR (active)', value: fmt.num(t.mrr || 0, 2), color: 'teal' },
    { label: 'Collected', value: fmt.num(t.collected || 0, 2), tone: 'ok' },
    { label: 'Paid invoices', value: String(t.paid_invoices || 0), color: 'cyan' }
  ]);
  const s = data.settings || {};
  $('#billingHint').textContent = 'Free trial: ' + s.trial_days + ' day(s) · self-service signup: '
    + (s.signup_enabled ? 'enabled' : 'disabled') + ' · gateway mode: ' + s.gateway_mode
    + ' · invoice prefix: ' + s.invoice_prefix + '. Configure the gateways below; webhook URLs are shown there.';
  renderSubscriptions(data);
  renderPlans(data);
  renderPayments(data);
  renderReminders(data);
  renderPortals(data);
  renderGateways(data);
  if (showToast) toast('Billing refreshed', 'ok');
}
function renderSubscriptions(data) {
  renderTable($('#subsTable'), {
    columns: [{ key: 'name', label: 'Account' }, { key: 'status', label: 'Status' },
      { key: 'plan', label: 'Plan' }, { key: 'units', label: 'Units', type: 'number' },
      { key: 'units_limit', label: 'Limit', type: 'number' },
      { key: 'profile_ok', label: 'Billing details', type: 'html' },
      { key: 'auto_renew', label: 'Auto-bill' }, { key: 'pm_gateway', label: 'Method' },
      { key: 'trial_days_left', label: 'Trial left', type: 'number' },
      { key: 'period_end', label: 'Paid until', type: 'datetime' },
      { key: 'signup_source', label: 'Created by' },
      { key: 'contact_email', label: 'Contact' },
      { key: 'actions', label: '', type: 'html' }],
    rows: (data.accounts || []).map(a => Object.assign({}, a, {
      status: '<span class="chip ' + (a.status === 'active' || a.status === 'comp' ? 'ok'
        : (a.status === 'trialing' ? 'info' : 'danger')) + '">' + h(a.status) + '</span>',
      profile_ok: a.profile_ok
        ? '<span class="chip ok">complete</span>'
        : '<span class="chip warn">incomplete</span>',
      auto_renew: a.auto_renew ? (a.pm_gateway ? 'yes' : 'no method') : 'off',
      pm_gateway: a.pm_gateway || '—',
      trial_days_left: a.status === 'trialing' ? a.trial_days_left : '',
      actions: '<button class="btn xs" data-bill="' + h(a.id) + '">Manage</button>'}))
  });
  $$('#subsTable button[data-bill]').forEach(btn => {
    btn.addEventListener('click', () => editSubscription(btn.dataset.bill));
  });
}
function renderPlans(data) {
  renderTable($('#plansTable'), {
    columns: [{ key: 'name', label: 'Plan' }, { key: 'code', label: 'Code' },
      { key: 'price', label: 'Price', type: 'number' }, { key: 'currency', label: 'Cur.' },
      { key: 'interval', label: 'Interval' }, { key: 'unit_limit', label: 'Units', type: 'number' },
      { key: 'extra_unit_price', label: 'Extra device', type: 'number' },
      { key: 'storage_days', label: 'History (d)', type: 'number' },
      { key: 'trial_days', label: 'Trial (d)', type: 'number' },
      { key: 'enabled', label: 'On' },
      { key: 'actions', label: '', type: 'html' }],
    rows: (data.plans || []).map(p => Object.assign({}, p, {
      enabled: p.enabled ? 'yes' : 'no',
      actions: '<button class="btn xs" data-plan="' + h(p.id) + '">Edit</button> '
        + '<button class="btn xs danger" data-del="' + h(p.id) + '">Delete</button>'}))
  });
  $$('#plansTable button[data-plan]').forEach(b => b.addEventListener('click', () => editPlan(b.dataset.plan)));
  $$('#plansTable button[data-del]').forEach(b => b.addEventListener('click', async () => {
    const plan = (Billing.data.plans || []).find(p => p.id === b.dataset.del) || {};
    if (!await confirmDialog('Delete the plan "' + (plan.name || '') + '"? Plans in use are disabled instead.', 'Delete')) return;
    try {
      const r = await API.del('/api/billing/plans/' + b.dataset.del);
      toast(r.message, 'ok');
      loadBilling();
    } catch (e) { /* shown */ }
  }));
}
function renderPayments(data) {
  renderTable($('#paymentsTable'), {
    columns: [{ key: 'invoice_no', label: 'Invoice' }, { key: 'account_id', label: 'Account' },
      { key: 'created_at', label: 'Created', type: 'datetime' }, { key: 'gateway', label: 'Gateway' },
      { key: 'method', label: 'Method' }, { key: 'amount', label: 'Amount', type: 'money' },
      { key: 'currency', label: 'Cur.' }, { key: 'status', label: 'Status' },
      { key: 'paid_at', label: 'Paid', type: 'datetime' }, { key: 'gateway_ref', label: 'Reference' },
      { key: 'actions', label: '', type: 'html' }],
    rows: (data.payments || []).map(p => Object.assign({}, p, {
      actions: p.status === 'pending'
        ? '<button class="btn xs ok" data-pay-act="mark_paid" data-pay="' + h(p.id) + '">Mark paid</button> '
          + '<button class="btn xs" data-pay-act="cancel" data-pay="' + h(p.id) + '">Cancel</button>'
        : (p.status === 'paid' ? '<button class="btn xs danger" data-pay-act="refund" data-pay="' + h(p.id) + '">Refund</button>' : '')}))
  });
  $$('#paymentsTable button[data-pay-act]').forEach(b => b.addEventListener('click', async () => {
    if (b.dataset.payAct === 'refund'
      && !await confirmDialog('Mark this invoice as refunded? The subscription is not changed.', 'Refund')) return;
    try {
      const r = await API.post('/api/billing/payments/' + b.dataset.pay, { action: b.dataset.payAct });
      toast(r.message, 'ok', 6000);
      loadBilling();
    } catch (e) { /* shown */ }
  }));
}

/* Recent payment reminders: what was e-mailed, to whom, and when. */
function renderReminders(data) {
  const s = data.settings || {};
  const rows = data.reminders || [];
  const hint = $('#remindersHint');
  if (hint) {
    hint.textContent = s.reminders_enabled
      ? 'E-mailed ' + (s.reminder_days || '14,5,2') + ' day(s) before an account is deactivated (trial end,'
        + ' paid period end or the end of the grace window) · transport: ' + (s.mail_transport || 'auto')
        + ' · ' + rows.length + ' reminder(s) on record. Configure the SMTP host below so real mail leaves'
        + ' the server.'
      : 'Payment reminders are switched off. Turn them on above to warn clients before deactivation.';
  }
  const chip = st => '<span class="chip ' + (st === 'sent' ? 'ok' : (st === 'failed' ? 'danger' : 'info'))
    + '">' + h(st) + '</span>';
  renderTable($('#remindersTable'), {
    columns: [{ key: 'created_at', label: 'Sent', type: 'datetime' },
      { key: 'account_name', label: 'Account' }, { key: 'kind', label: 'Notice' },
      { key: 'days_before', label: 'Offset (d)', type: 'number' },
      { key: 'days_left', label: 'Days left', type: 'number' },
      { key: 'recipient', label: 'To' }, { key: 'channel', label: 'Via' },
      { key: 'status', label: 'Status', type: 'html' },
      { key: 'sent_at', label: 'When', type: 'datetime' },
      { key: 'error', label: 'Error' }],
    rows: rows.map(r => Object.assign({}, r, { status: chip(r.status) }))
  });
}

/* Reseller portals: the full-year licence, the annual invoice and the renew. */
function renderPortals(data) {
  const list = data.portals || [];
  const price = data.portal_price !== undefined ? data.portal_price
    : ((data.settings || {}).portal_price || 0);
  const hint = $('#portalsHint');
  if (hint) {
    hint.textContent = 'A portal is a reseller workspace paid for a FULL YEAR ('
      + fmt.money(price, 'USD') + '). The owner signs in at /reseller and creates client accounts; '
      + 'until the year is paid the portal stays pending, and the sweep expires it when the year ends.';
  }
  renderTable($('#portalsTable'), {
    columns: [{ key: 'name', label: 'Portal' }, { key: 'status', label: 'Status' },
      { key: 'price', label: 'Year price', type: 'number' }, { key: 'currency', label: 'Cur.' },
      { key: 'period_end', label: 'Paid until', type: 'datetime' },
      { key: 'days_left', label: 'Days left', type: 'number' },
      { key: 'contact_email', label: 'Contact' },
      { key: 'actions', label: '', type: 'html' }],
    rows: list.map(p => Object.assign({}, p, {
      status: '<span class="chip ' + (p.status === 'active' ? 'ok'
        : (p.status === 'pending' ? 'warn' : 'danger')) + '">' + h(p.status) + '</span>',
      actions: '<button class="btn xs" data-portal-renew="' + h(p.id) + '">Renew year</button> '
        + '<button class="btn xs danger" data-portal-del="' + h(p.id) + '">Delete</button>'}))
  });
  $$('#portalsTable button[data-portal-renew]').forEach(b => b.addEventListener('click', async () => {
    try {
      const r = await API.post('/api/portals/' + b.dataset.portalRenew + '/renew', { gateway: 'sandbox' });
      toast(r.checkout_url ? 'Renewal invoice created — open the payment link' : r.message, 'ok', 7000);
      if (r.checkout_url) window.open(r.checkout_url, '_blank');
      loadBilling();
    } catch (e) { /* shown */ }
  }));
  $$('#portalsTable button[data-portal-del]').forEach(b => b.addEventListener('click', async () => {
    if (!await confirmDialog('Delete this portal? Its reseller login is removed (client accounts must '
      + 'be deleted first).', 'Delete')) return;
    try {
      const r = await API.del('/api/portals/' + b.dataset.portalDel);
      toast(r.message, 'ok');
      loadBilling();
    } catch (e) { /* shown */ }
  }));
}
function newPortal() {
  const gw = (Billing.data && Billing.data.gateways) || {};
  const opts = Object.keys(gw).filter(c => gw[c].active && c !== 'bank')
    .map(c => ({ value: c, label: gw[c].short }));
  if (!opts.length) opts.push({ value: 'sandbox', label: 'Sandbox' });
  formModal({
    title: 'New reseller portal (full year)',
    wide: true,
    fields: [
      { name: 'name', label: 'Portal name', required: true, placeholder: 'Skyline Tracking' },
      { name: 'contact_email', label: 'Contact e-mail', required: true },
      { name: 'owner_username', label: 'Reseller login username', required: true },
      { name: 'owner_password', label: 'Reseller login password', required: true },
      { name: 'gateway', label: 'Collect the full year with', type: 'select', options: opts },
      { name: 'phone', label: 'Mobile-money number (only for M-Pesa / MoMo / Airtel)',
        help: 'Ignored by card, PayPal and sandbox gateways' }
    ],
    saveLabel: 'Create portal & invoice the year',
    onSave: async form => {
      const r = await API.post('/api/portals', form);
      toast(r.message, 'ok', 8000);
      if (r.checkout_url) window.open(r.checkout_url, '_blank');
      loadBilling();
    }
  });
}
/* Devices the platform supports (System tab). */
function renderDeviceCatalogue() {
  const list = (State.meta && State.meta.device_catalogue) || [];
  const hint = $('#deviceCatalogueHint');
  if (hint) {
    hint.textContent = 'Protocols built into this server: the Wialon IPS 1.1 TCP daemon on port '
      + ((State.meta && State.meta.settings && State.meta.settings.ips_port) || 20332)
      + ' and the HTTP ingest endpoint. Pick the hardware type per unit — the catalogue above is what '
      + 'the unit form offers.';
  }
  renderTable($('#deviceCatalogue'), {
    columns: [{ key: 'code', label: 'Type' }, { key: 'label', label: 'Device / protocol' },
      { key: 'protocol', label: 'How it connects' }, { key: 'native', label: 'Native' },
      { key: 'examples', label: 'Examples' }],
    rows: list.map(d => Object.assign({}, d, { native: d.native ? 'yes — daemon' : 'gateway' }))
  });
}

/* --------------------------------------- financial / invoicing reports --- */
function financeQuery(extra) {
  const from = $('#finFrom') && $('#finFrom').value;
  const to = $('#finTo') && $('#finTo').value;
  const p = new URLSearchParams();
  if (from) p.set('from', from);
  if (to) p.set('to', to);
  Object.keys(extra || {}).forEach(k => p.set(k, extra[k]));
  return p.toString();
}
async function loadFinance(showToast) {
  const q = financeQuery();
  try {
    const [fin, inv, perf] = await Promise.all([
      API.get('/api/finance/financial?' + q, { silent: true }),
      API.get('/api/finance/invoicing?' + q, { silent: true }),
      API.get('/api/finance/performance?' + q, { silent: true })
    ]);
    renderFinancial(fin);
    renderInvoicing(inv);
    renderPerformance(perf);
    await loadAlerts();
    if (showToast) toast('Reports refreshed', 'ok');
  } catch (e) { /* shown */ }
}
/* CSV export — same authenticated-blob pattern as the per-account reports. */
function financeCsv(base) {
  const url = base + '?' + financeQuery() + (financeQuery() ? '&' : '') + 'format=csv';
  fetch(url, { headers: { 'X-Auth-Token': API.token() } })
    .then(async r => {
      if (!r.ok) throw new Error((await r.text()).slice(0, 200));
      const blob = await r.blob();
      const name = (base.split('/').pop() || 'report') + '-report.csv';
      const a = el('a', { href: URL.createObjectURL(blob), download: name });
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
      toast('CSV downloaded', 'ok');
    })
    .catch(e => toast('CSV export failed: ' + e.message, 'err'));
}
async function loadAlerts(showToast) {
  let data;
  try {
    data = await API.get('/api/finance/alerts?' + financeQuery(), { silent: true });
  } catch (e) {
    $('#finAlerts').textContent = 'Alerts unavailable: ' + e.message;
    return;
  }
  renderAlerts(data);
  if (showToast) toast(data.count + ' alert(s)', data.count ? 'warn' : 'ok');
}

function renderFinancial(f) {
  const t = f.totals || {};
  $('#finHint').textContent = 'Range ' + f.from + ' → ' + f.to
    + ' · invoices: ' + JSON.stringify(t.invoices || {})
    + ' · MRR ' + fmt.num(t.mrr || 0, 2) + ' · portal revenue ' + fmt.num(t.portal_revenue || 0, 2)
    + ' · new accounts ' + (t.new_accounts || 0);
  const box = $('#finTotals');
  box.innerHTML = '';
  tiles(box, [
    { label: 'Collected (all currencies)', value: fmt.num(t.collected || 0, 2), tone: 'ok' },
    { label: 'MRR (active)', value: fmt.num(t.mrr || 0, 2), color: 'teal' },
    { label: 'Active accounts', value: String(t.active_accounts || 0), color: 'blue' },
    { label: 'New accounts', value: String(t.new_accounts || 0), color: 'violet' },
    { label: 'Portal revenue', value: fmt.num(t.portal_revenue || 0, 2), color: 'amber' }
  ]);
  renderTable($('#finFinancial'), {
    columns: [{ key: 'currency', label: 'Currency' }, { key: 'collected', label: 'Collected', type: 'number' },
      { key: 'pending', label: 'Outstanding', type: 'number' }, { key: 'failed', label: 'Failed', type: 'number' },
      { key: 'refunded', label: 'Refunded', type: 'number' },
      { key: 'paid_count', label: 'Paid inv.', type: 'number' }],
    rows: f.by_currency || []
  });
  /* gateway + plan mix, appended under the currency table */
  const host = $('#finFinancial');
  const old = document.getElementById('finMix');
  if (old) old.remove();
  const mix = el('div', { id: 'finMix', style: 'margin-top:10px' });
  host.parentNode.insertBefore(mix, host.nextSibling);
  const gwHost = el('div');
  const planHost = el('div', { style: 'margin-top:8px' });
  mix.appendChild(gwHost);
  mix.appendChild(planHost);
  renderTable(gwHost, {
    columns: [{ key: 'gateway', label: 'Gateway' }, { key: 'collected', label: 'Collected', type: 'number' },
      { key: 'currency', label: 'Cur.' }, { key: 'invoices', label: 'Invoices', type: 'number' }],
    rows: f.by_gateway || []
  });
  renderTable(planHost, {
    columns: [{ key: 'plan', label: 'Plan' }, { key: 'collected', label: 'Collected', type: 'number' },
      { key: 'currency', label: 'Cur.' }, { key: 'payments', label: 'Paid invoices', type: 'number' }],
    rows: f.by_plan || []
  });
}
function renderInvoicing(inv) {
  const a = inv.aging || {};
  renderTable($('#finInvoicing'), {
    columns: [{ key: 'invoice_no', label: 'Invoice' }, { key: 'account', label: 'Account' },
      { key: 'gateway', label: 'Gateway' }, { key: 'amount', label: 'Amount', type: 'number' },
      { key: 'currency', label: 'Cur.' }, { key: 'status', label: 'Status' },
      { key: 'age_days', label: 'Age (d)', type: 'number' },
      { key: 'created_at', label: 'Created', type: 'datetime' },
      { key: 'paid_at', label: 'Paid', type: 'datetime' }, { key: 'failure_reason', label: 'Note' }],
    rows: (inv.rows || []).slice(0, 60)
  });
  const chip = el('p', { class: 'hint' }, 'Ageing of unpaid invoices — 0-7d: ' + (a.b0_7 || 0)
    + ' · 8-30d: ' + (a.b8_30 || 0) + ' · 31-60d: ' + (a.b31_60 || 0) + ' · 60d+: ' + (a.b60 || 0)
    + ' · totals: ' + (inv.totals || []).map(x => x.status + ' ' + x.invoices).join(', '));
  const host = $('#finInvoicing');
  if (host && host.parentNode) host.parentNode.insertBefore(chip, host);
}
function renderAlerts(data) {
  const box = $('#finAlerts');
  box.innerHTML = '';
  const list = data.alerts || [];
  if (!list.length) {
    box.appendChild(el('p', { class: 'hint' }, 'No invoicing alerts — every invoice, trial and portal looks fine.'));
    return;
  }
  list.slice(0, 40).forEach(x => {
    const row = el('div', { class: 'row wrap', style: 'gap:8px;align-items:center;margin-top:4px' });
    row.appendChild(el('span', { class: 'chip ' + (x.severity === 'danger' ? 'danger'
      : (x.severity === 'warn' ? 'warn' : 'info')) }, h(x.kind)));
    row.appendChild(el('span', null, h(x.message)));
    box.appendChild(row);
  });
}
function renderPerformance(pf) {
  const p = pf.platform || {};
  renderTable($('#finPerformance'), {
    columns: [{ key: 'account', label: 'Account' }, { key: 'status', label: 'Status' },
      { key: 'plan', label: 'Plan' }, { key: 'units', label: 'Units', type: 'number' },
      { key: 'units_online', label: 'Online', type: 'number' },
      { key: 'units_limit', label: 'Limit', type: 'number' },
      { key: 'utilisation_pct', label: 'Use %', type: 'number' },
      { key: 'messages', label: 'Positions', type: 'number' },
      { key: 'trips', label: 'Trips', type: 'number' },
      { key: 'distance_km', label: 'Distance km', type: 'number' },
      { key: 'revenue', label: 'Revenue', type: 'number' },
      { key: 'currency', label: 'Cur.' }],
    rows: pf.accounts || []
  });
  const note = el('p', { class: 'hint' }, 'Platform: ' + (p.accounts || 0) + ' accounts · '
    + (p.units || 0) + ' units (' + (p.units_online || 0) + ' online, ' + (p.online_pct || 0) + '%) · '
    + (p.messages || 0) + ' positions (' + (p.messages_per_day || 0) + '/day, '
    + (p.positions_per_unit_day || 0) + ' per unit/day) · ' + (p.trips || 0) + ' trips · '
    + fmt.num(p.distance_km || 0, 1) + ' km over ' + (pf.span_days || 0) + ' day(s).');
  const host = $('#finPerformance');
  if (host && host.parentNode) host.parentNode.insertBefore(note, host);
}

/* Gateway credentials: only the last 4 characters of a secret are ever sent
   back, so echoing a masked value means "leave it unchanged". */
function renderGateways(data) {
  const box = $('#gatewayForm');
  box.innerHTML = '';
  const meta = Billing.data.gateway_meta || {};
  const cfg = data.gateways || {};
  const urls = Billing.data.webhook_urls || {};
  $('#webhookHint').textContent = Object.keys(urls).length
    ? 'Webhook / callback URLs: ' + Object.values(urls).join('  ·  ') : '';

  const general = el('div', { class: 'card pad', style: 'margin-bottom:10px' });
  general.appendChild(el('h4', { style: 'margin:0 0 8px' }, 'Trial &amp; registration'));
  const s = data.settings || {};
  const row = el('div', { class: 'row wrap', style: 'align-items:flex-start' });
  [['trial_days', 'Free trial (days)', 'number', s.trial_days],
   ['invoice_prefix', 'Invoice prefix', 'text', s.invoice_prefix],
   ['site_url', 'Public base URL (webhooks / return links)', 'text', s.site_url || '']].forEach(f => {
    const w = el('div', { class: 'field', style: 'min-width:240px;flex:1' });
    w.appendChild(el('label', null, h(f[1])));
    const inp = el('input', { type: f[2], name: f[0], step: '1' });
    inp.value = f[3] === undefined || f[3] === null ? '' : f[3];
    w.appendChild(inp);
    row.appendChild(w);
  });
  const modeWrap = el('div', { class: 'field', style: 'min-width:200px' });
  modeWrap.appendChild(el('label', null, 'Gateway mode'));
  modeWrap.appendChild(selectBox('gateway_mode', [
    { value: 'sandbox', label: 'sandbox (test keys)' },
    { value: 'live', label: 'live (real money)' }], s.gateway_mode || 'sandbox'));
  row.appendChild(modeWrap);
  const signWrap = el('div', { class: 'field', style: 'min-width:180px' });
  signWrap.appendChild(el('label', null, 'Self-service signup'));
  signWrap.appendChild(selectBox('signup_enabled', [{ value: '1', label: 'enabled' },
    { value: '0', label: 'disabled' }], s.signup_enabled ? '1' : '0'));
  row.appendChild(signWrap);
  general.appendChild(row);
  box.appendChild(general);

  /* Payment reminders: activation switch, offsets and the e-mail transport */
  const s2 = data.settings || {};
  const m = s2.mail || {};
  const rem = el('div', { class: 'card pad', style: 'margin-bottom:10px' });
  rem.appendChild(el('h4', { style: 'margin:0 0 8px' }, 'Payment reminders (e-mail)'));
  const remRow = el('div', { class: 'row wrap', style: 'align-items:flex-start' });
  const onW = el('div', { class: 'field', style: 'min-width:170px' });
  onW.appendChild(el('label', null, 'Reminders'));
  onW.appendChild(selectBox('reminders_enabled', [
    { value: '1', label: 'enabled' }, { value: '0', label: 'disabled' }],
    s2.reminders_enabled ? '1' : '0'));
  remRow.appendChild(onW);
  const daysW = el('div', { class: 'field', style: 'min-width:230px' });
  daysW.appendChild(el('label', null, 'Days before deactivation (e.g. 14,5,2)'));
  const daysInp = el('input', { type: 'text', name: 'reminder_days', placeholder: '14,5,2' });
  daysInp.value = s2.reminder_days || '14,5,2';
  daysW.appendChild(daysInp);
  remRow.appendChild(daysW);
  const fromW = el('div', { class: 'field', style: 'min-width:230px;flex:1' });
  fromW.appendChild(el('label', null, 'From address'));
  const fromInp = el('input', { type: 'text', name: 'mail.from', placeholder: 'billing@your-domain.com' });
  fromInp.value = m.from || '';
  fromW.appendChild(fromInp);
  remRow.appendChild(fromW);
  const fnW = el('div', { class: 'field', style: 'min-width:200px;flex:1' });
  fnW.appendChild(el('label', null, 'From name'));
  const fnInp = el('input', { type: 'text', name: 'mail.from_name' });
  fnInp.value = m.from_name || '';
  fnW.appendChild(fnInp);
  remRow.appendChild(fnW);
  rem.appendChild(remRow);
  const remRow2 = el('div', { class: 'row wrap', style: 'align-items:flex-start;margin-top:8px' });
  const trW = el('div', { class: 'field', style: 'min-width:230px' });
  trW.appendChild(el('label', null, 'Transport'));
  trW.appendChild(selectBox('mail.transport', [
    { value: 'auto', label: 'auto (SMTP if set, else PHP mail())' },
    { value: 'smtp', label: 'smtp (built-in client)' },
    { value: 'mail', label: 'PHP mail()' },
    { value: 'log', label: 'log only (data/mail.log)' }], m.transport || 'auto'));
  remRow2.appendChild(trW);
  const hW = el('div', { class: 'field', style: 'min-width:220px;flex:1' });
  hW.appendChild(el('label', null, 'SMTP host'));
  const hInp = el('input', { type: 'text', name: 'mail.smtp_host', placeholder: 'smtp.mailprovider.com' });
  hInp.value = m.smtp_host || '';
  hW.appendChild(hInp);
  remRow2.appendChild(hW);
  const pW = el('div', { class: 'field', style: 'min-width:110px' });
  pW.appendChild(el('label', null, 'SMTP port'));
  const pInp = el('input', { type: 'text', name: 'mail.smtp_port', placeholder: '587' });
  pInp.value = m.smtp_port || 587;
  pW.appendChild(pInp);
  remRow2.appendChild(pW);
  const eW = el('div', { class: 'field', style: 'min-width:150px' });
  eW.appendChild(el('label', null, 'Encryption'));
  eW.appendChild(selectBox('mail.smtp_encryption', [
    { value: 'tls', label: 'STARTTLS' }, { value: 'ssl', label: 'SSL' },
    { value: 'none', label: 'none' }], m.smtp_encryption || 'tls'));
  remRow2.appendChild(eW);
  const uW = el('div', { class: 'field', style: 'min-width:190px;flex:1' });
  uW.appendChild(el('label', null, 'SMTP user'));
  const uInp = el('input', { type: 'text', name: 'mail.smtp_user', autocomplete: 'off' });
  uInp.value = m.smtp_user || '';
  uW.appendChild(uInp);
  remRow2.appendChild(uW);
  const pwW = el('div', { class: 'field', style: 'min-width:190px;flex:1' });
  pwW.appendChild(el('label', null, 'SMTP password (masked echo = unchanged)'));
  const pwInp = el('input', { type: 'text', name: 'mail.smtp_pass', autocomplete: 'off' });
  pwInp.value = m.smtp_pass || '';
  pwW.appendChild(pwInp);
  remRow2.appendChild(pwW);
  rem.appendChild(remRow2);
  rem.appendChild(el('p', { class: 'hint' },
    'Reminders are sent ' + (s2.reminder_days || '14,5,2')
    + ' day(s) before deactivation, once each per countdown. Effective transport: '
    + (s2.mail_transport || 'auto') + '. The IPS daemon sweeps hourly; a cron calling /api/tasks/run'
    + ' does the same.'));
  box.appendChild(rem);

  Object.keys(meta).forEach(code => {
    const m = meta[code];
    const c = cfg[code] || {};
    const enabled = c.enabled !== false && c.enabled !== 0;
    const card = el('div', { class: 'card pad', style: 'margin-bottom:10px' });
    const head = el('div', { class: 'row wrap' });
    head.appendChild(el('b', null, h(m.label)));
    /* three-state status chip: off > not configured > active */
    const status = !enabled ? ['switched off', 'warn']
      : (c.configured ? ['active', 'ok'] : ['not configured', 'warn']);
    head.appendChild(el('span', { class: 'chip ' + status[1] }, status[0]));
    head.appendChild(el('span', { class: 'chip' }, h(m.methods_label)));
    if (code === 'sandbox') head.appendChild(el('span', { class: 'badge-sandbox' }, 'no money moves'));
    if (m.docs) {
      head.appendChild(el('a', { class: 'btn xs ghost', href: m.docs, target: '_blank', rel: 'noopener' },
        'Get API keys'));
    }
    /* the super admin's on/off switch for this payment method */
    const toggle = el('div', { class: 'field', style: 'min-width:170px;margin-left:auto' });
    toggle.appendChild(el('label', null, 'Payments'));
    toggle.appendChild(selectBox(code + '.enabled', [
      { value: '1', label: 'enabled' },
      { value: '0', label: 'disabled' }], enabled ? '1' : '0'));
    head.appendChild(toggle);
    card.appendChild(head);
    const grid = el('div', { class: 'row wrap', style: 'align-items:flex-start;margin-top:8px' });
    Object.keys(m.fields).forEach(key => {
      const w = el('div', { class: 'field', style: 'min-width:250px;flex:1' });
      w.appendChild(el('label', null, h(m.fields[key])));
      const inp = el('input', { type: 'text', name: code + '.' + key, autocomplete: 'off' });
      inp.value = c[key] === undefined || c[key] === null ? '' : c[key];
      w.appendChild(inp);
      grid.appendChild(w);
    });
    const baseWrap = el('div', { class: 'field', style: 'min-width:250px;flex:1' });
    baseWrap.appendChild(el('label', null, 'API base URL (blank = default)'));
    const baseInp = el('input', { type: 'text', name: code + '.base', placeholder: m.base || '' });
    baseInp.value = c.base && c.base !== m.base ? c.base : '';
    baseWrap.appendChild(baseInp);
    grid.appendChild(baseWrap);
    if (code !== 'sandbox' && code !== 'bank') {
      const envWrap = el('div', { class: 'field', style: 'min-width:150px' });
      envWrap.appendChild(el('label', null, 'Environment'));
      envWrap.appendChild(selectBox(code + '.env', [{ value: 'sandbox', label: 'sandbox' },
        { value: 'live', label: 'live' }], c.env || 'sandbox'));
      grid.appendChild(envWrap);
    }
    card.appendChild(grid);
    if (urls[code]) card.appendChild(el('p', { class: 'hint' }, 'Webhook URL: ' + urls[code]));
    box.appendChild(card);
  });
}

async function saveGateways() {
  const raw = readForm($('#gatewayForm'));
  const payload = { gateways: {} };
  const top = ['trial_days', 'invoice_prefix', 'gateway_mode', 'signup_enabled', 'site_url',
    'reminders_enabled', 'reminder_days'];
  Object.keys(raw).forEach(k => {
    if (k.indexOf('mail.') === 0) return;                       // collected below
    if (top.indexOf(k) >= 0) {
      payload[k] = raw[k];
      return;
    }
    const parts = k.split('.');
    if (parts.length !== 2) return;
    if (!payload.gateways[parts[0]]) payload.gateways[parts[0]] = {};
    payload.gateways[parts[0]][parts[1]] = raw[k];
  });
  payload.signup_enabled = String(payload.signup_enabled) === '1';
  if (payload.reminders_enabled !== undefined) {
    payload.reminders_enabled = String(payload.reminders_enabled) === '1';
  }
  /* per-gateway on/off switch arrives as select values 1/0 */
  Object.keys(payload.gateways).forEach(code => {
    const g = payload.gateways[code];
    if (g.enabled !== undefined) g.enabled = String(g.enabled) === '1';
  });
  const mailOut = {};
  Object.keys(raw).forEach(k => {
    if (k.indexOf('mail.') === 0) mailOut[k.slice(5)] = raw[k];
  });
  if (Object.keys(mailOut).length) payload.mail = mailOut;
  try {
    const r = await API.put('/api/billing/settings', payload);
    toast(r.message || 'Saved', 'ok');
    loadBilling();
  } catch (e) { /* shown */ }
}

function editPlan(id) {
  const p = id ? (Billing.data.plans || []).find(x => x.id === id) : null;
  formModal({
    title: p ? 'Edit plan — ' + p.name : 'New plan',
    wide: true,
    fields: [
      { name: 'name', label: 'Plan name', value: p ? p.name : '' },
      { name: 'code', label: 'Code (lowercase id used by the API)', value: p ? p.code : '' },
      { name: 'tagline', label: 'Short description', value: p ? p.tagline : '' },
      { name: 'price', label: 'Price per interval', type: 'number', step: '0.01', value: p ? p.price : 19 },
      { name: 'currency', label: 'Currency (USD, KES, UGX…)', value: p ? p.currency : 'USD' },
      { name: 'interval', label: 'Interval', type: 'select',
        options: [{ value: 'month', label: 'month' }, { value: 'year', label: 'year' }],
        value: p ? p.interval : 'month' },
      { name: 'unit_limit', label: 'Unit limit', type: 'number', value: p ? p.unit_limit : 10 },
      { name: 'extra_unit_price', label: 'Extra device price per period (0 = plan caps devices)',
        type: 'number', step: '0.01', value: p ? p.extra_unit_price : 0,
        help: 'Paid accounts can keep adding devices — each extra is added to the next invoice' },
      { name: 'storage_days', label: 'History kept (days)', type: 'number', value: p ? p.storage_days : 90 },
      { name: 'trial_days', label: 'Free trial for this plan (days)', type: 'number',
        value: p ? p.trial_days : ((Billing.data && Billing.data.settings) || {}).trial_days },
      { name: 'sort_order', label: 'Sort order', type: 'number', value: p ? p.sort_order : 0 },
      { name: 'features', label: 'Features (one per line)', type: 'textarea', rows: 4,
        value: p && p.features ? p.features.join('\n') : '' },
      { name: 'enabled', label: 'Enabled', type: 'checkbox', value: p ? p.enabled : 1 }
    ],
    onSave: async form => {
      const features = String(form.features || '').split('\n').map(s => s.trim()).filter(s => s !== '');
      const payload = Object.assign({}, form, { features: features });
      const res = id ? await API.put('/api/billing/plans/' + id, payload)
        : await API.post('/api/billing/plans', payload);
      toast(res.message || 'Plan saved', 'ok');
      await loadBilling();
    }
  });
}

function editSubscription(accountId) {
  const a = (Billing.data.accounts || []).find(x => x.id === accountId);
  if (!a) return;
  const body = el('div');
  body.appendChild(el('p', { class: 'muted' }, h(a.name) + ' — status ' + h(a.status)
    + (a.period_end ? ', paid until ' + fmt.dt(a.period_end) : '')
    + (a.status === 'trialing' ? ', trial left ' + a.trial_days_left + ' day(s)' : '')));
  const planWrap = el('div', { class: 'field' });
  planWrap.appendChild(el('label', null, 'Plan'));
  planWrap.appendChild(selectBox('plan_id', (Billing.data.plans || []).map(p => ({ value: p.id, label: p.name })),
    a.plan_id || ''));
  body.appendChild(planWrap);
  const daysWrap = el('div', { class: 'field' });
  daysWrap.appendChild(el('label', null, 'Days (for extend trial / add period)'));
  const days = el('input', { type: 'number', name: 'days', value: 30 });
  daysWrap.appendChild(days);
  body.appendChild(daysWrap);
  const row = el('div', { class: 'row wrap' });
  const act = async (action, extra) => {
    try {
      const r = await API.post('/api/accounts/' + accountId + '/billing',
        Object.assign({ action: action, days: Number(days.value) || 0 }, extra || {}));
      toast(r.message + ' — status now ' + r.billing.status, 'ok', 6000);
      m.close();
      loadBilling();
      loadAccounts();
    } catch (e) { /* shown */ }
  };
  [['set_plan', 'Apply plan'], ['extend_trial', 'Extend trial'], ['extend_period', 'Add paid period'],
   ['activate', 'Activate 30 days'], ['comp', 'Complimentary'], ['suspend', 'Suspend'],
   ['send_reminder', 'Send reminder'], ['cancel', 'Cancel renewal']].forEach(b => {
    const btn = el('button', { class: 'btn sm ' + (b[0] === 'suspend' ? 'danger' : ''), text: b[1] });
    btn.addEventListener('click', () => {
      if (b[0] === 'cancel') return act('cancel', { cancel: true });
      if (b[0] === 'comp') return act('comp');
      if (b[0] === 'set_plan') {
        const sel = $('select[name="plan_id"]', body);
        return act('set_plan', { plan_id: sel ? sel.value : '' });
      }
      return act(b[0]);
    });
    row.appendChild(btn);
  });
  body.appendChild(row);
  const m = modal({ title: 'Manage subscription', body: body, wide: true,
    buttons: [{ label: 'Close', class: 'ghost' }] });
}

/* ------------------------------------------------------------- accounts */

async function loadAccounts() {
  const data = await API.get('/api/accounts');
  Super.accounts = data.accounts || [];
  $('#accountChip').textContent = Super.accounts.length + ' accounts';
  const holder = $('#accountsTable');
  renderTable(holder, {
    columns: [{ key: 'name', label: 'Account' }, { key: 'slug', label: 'Slug' },
      { key: 'plan', label: 'Plan' },
      { key: 'status', label: 'Status', type: 'html' },
      { key: 'trial_days_left', label: 'Trial', type: 'number' },
      { key: 'period_end', label: 'Paid until', type: 'datetime' },
      { key: 'units', label: 'Units', type: 'number' },
      { key: 'users', label: 'Users', type: 'number' },
      { key: 'geofences', label: 'Geofences', type: 'number' },
      { key: 'last_data', label: 'Last data', type: 'datetime' },
      { key: 'enabled', label: 'On' },
      { key: 'actions', label: '', type: 'html' }],
    rows: Super.accounts.map(a => Object.assign({}, a, {
      enabled: a.enabled ? 'yes' : 'no',
      status: '<span class="chip ' + (a.status === 'active' || a.status === 'comp' ? 'ok'
        : (a.status === 'trialing' ? 'info' : 'danger')) + '">' + h(a.status) + '</span>',
      trial_days_left: a.status === 'trialing' ? a.trial_days_left : '',
      actions: '<button class="btn xs" data-act="detail" data-id="' + h(a.id) + '">Details</button> '
        + '<button class="btn xs" data-act="edit" data-id="' + h(a.id) + '">Edit</button> '
        + '<button class="btn xs danger" data-act="del" data-id="' + h(a.id) + '">Delete</button>'
    }))
  });
  holder.onclick = async ev => {
    const btn = ev.target.closest ? ev.target.closest('button[data-act]') : null;
    if (!btn) return;
    const id = btn.dataset.id;
    if (btn.dataset.act === 'detail') return loadAccountDetail(id);
    if (btn.dataset.act === 'edit') return editAccount(id);
    if (btn.dataset.act === 'del') {
      const ok = await confirmDialog('Delete this account with all of its units, users and history?');
      if (!ok) return;
      try {
        await API.del('/api/accounts/' + encodeURIComponent(id) + '?force=1');
        toast('Account deleted', 'ok');
        loadAccounts();
        $('#accountDetail').innerHTML = '';
      } catch (e) { /* shown */ }
    }
  };
}

function editAccount(id) {
  const a = id ? (Super.accounts.find(x => String(x.id) === String(id)) || {}) : {};
  formModal({
    title: id ? 'Account — ' + a.name : 'New account',
    values: a, wide: true,
    fields: [
      { name: 'name', label: 'Account name', required: true },
      { name: 'slug', label: 'Slug (URL friendly)', help: 'Leave blank to derive it from the name' },
      { name: 'plan_id', label: 'Plan (sets unit limit, retention and price)', type: 'select',
        options: [{ value: '', label: '— no plan / manual limits —' }].concat(
          ((Billing.data && Billing.data.plans) || []).map(p => ({ value: p.id, label: p.name }))) },
      { name: 'plan', label: 'Legacy plan label', help: 'Kept for reporting; the plan above is what is billed' },
      { name: 'status', label: 'Subscription status', type: 'select', value: 'trialing',
        options: [{ value: 'trialing', label: 'trialing (free trial)' }, { value: 'active', label: 'active (paid)' },
          { value: 'comp', label: 'comp (complimentary)' }, { value: 'suspended', label: 'suspended' },
          { value: 'expired', label: 'expired' }] },
      { name: 'trial_days', label: 'Free trial days', type: 'number',
        value: ((Billing.data && Billing.data.settings) || {}).trial_days || 5 },
      { name: 'units_limit', label: 'Unit limit', type: 'number' },
      { name: 'storage_days', label: 'History retention (days)', type: 'number' },
      { name: 'currency', label: 'Currency' },
      { name: 'timezone', label: 'Timezone' },
      { name: 'contact_email', label: 'Contact e-mail' },
      { name: 'contact_name', label: 'Contact name' },
      { name: 'contact_phone', label: 'Contact phone (for M-Pesa)' },
      { name: 'enabled', label: 'Enabled', type: 'checkbox', value: 1 }
    ].concat(id ? [] : [
      { name: 'admin_username', label: 'First admin username (optional)' },
      { name: 'admin_password', label: 'First admin password (min 6 characters)' },
      { name: 'admin_email', label: 'First admin e-mail' },
      { name: 'admin_full_name', label: 'First admin full name' }
    ]),
    saveLabel: id ? 'Save account' : 'Create account',
    onSave: async form => {
      const res = id ? await API.put('/api/accounts/' + encodeURIComponent(id), form)
        : await API.post('/api/accounts', form);
      toast(res.message + (res.admin_username ? ' — admin "' + res.admin_username + '" created' : '')
        + (res.billing ? ' (' + res.billing.status + ')' : ''), 'ok');
      await loadAccounts();
      loadBilling();
    }
  });
}
async function loadAccountDetail(id) {
  const box = $('#accountDetail');
  box.innerHTML = '<p class="muted">Loading account…</p>';
  try {
    const d = await API.get('/api/accounts/' + encodeURIComponent(id) + '/summary');
    box.innerHTML = '';
    const card = el('div', { class: 'card pad' });
    card.appendChild(el('h2', null, h(d.account.name) + ' <span class="chip info">' + h(d.account.plan) + '</span>'));
    card.appendChild(el('p', { class: 'muted small' },
      'Slug <span class="mono">' + h(d.account.slug) + '</span> · currency ' + h(d.account.currency)
      + ' · timezone ' + h(d.account.timezone) + ' · unit limit ' + d.account.units_limit
      + ' · retention ' + d.account.storage_days + ' days'));
    tiles(card, [
      { label: 'Units', value: String(d.fleet.total), color: 'blue' },
      { label: 'Moving', value: String(d.fleet.moving), tone: 'ok' },
      { label: 'Parked', value: String(d.fleet.parked), color: 'amber' },
      { label: 'Offline', value: String(d.fleet.offline),
        tone: (d.fleet.offline || 0) > 0 ? 'danger' : 'neutral' },
      { label: 'Messages stored', value: fmt.num(d.counters.messages, 0), color: 'violet' },
      { label: 'Messages 24h', value: fmt.num(d.counters.messages_24h, 0), color: 'cyan' },
      { label: 'Active sessions', value: String(d.counters.active_sessions), color: 'teal' }
    ]);
    const holder = el('div');
    card.appendChild(holder);
    renderTable(holder, {
      columns: [{ key: 'name', label: 'Unit' }, { key: 'unique_id', label: 'Device ID' },
        { key: 'status_label', label: 'Status' }, { key: 'speed', label: 'Speed', type: 'speed' },
        { key: 'mileage', label: 'Odometer', type: 'km' },
        { key: 'last_time', label: 'Last data', type: 'datetime' },
        { key: 'address', label: 'Location' }],
      rows: d.units
    });
    box.appendChild(card);
  } catch (e) { box.innerHTML = ''; }
}

/* ------------------------------------------------------- platform logo --- */
/* Preview + upload + remove. The file goes to /api/settings/logo as multipart
   (API.post handles FormData); replacing or removing deletes the old file
   server-side, and every brand spot refreshes through applyBranding(). */
function renderLogoCard(settings) {
  const url = (settings && settings.logo_url) || '';
  const box = $('#logoPreview');
  if (box) {
    box.innerHTML = '';
    if (url) box.appendChild(el('img', { src: url + brandingSig(), alt: 'Platform logo' }));
    else box.appendChild(el('span', { class: 'empty' }, 'No logo — the default mark is used'));
  }
  const hint = $('#logoHint');
  if (hint) {
    hint.textContent = url
      ? 'Current logo: ' + url + (url.indexOf('/img/branding/') === 0
        ? ' (uploaded file — replacing it deletes the old one)' : ' (external URL)')
      : 'No logo uploaded yet. You can also paste an image URL in “Platform logo URL” below.';
  }
  const log = $('#logoLog');
  if (log) log.innerHTML = '';
  renderTable(log, {
    columns: [{ key: 'when', label: 'Activity' }, { key: 'what', label: 'What' }],
    rows: (Super.logoHistory || [])
  });
}
async function uploadLogo() {
  const input = $('#logoFile');
  if (!input || !input.files || !input.files.length) {
    toast('Choose a PNG/JPG/SVG file first', 'warn');
    return;
  }
  const file = input.files[0];
  const fd = new FormData();
  fd.append('logo', file, file.name);
  const btn = $('#btnLogoUpload');
  if (btn) btn.disabled = true;
  try {
    const res = await API.post('/api/settings/logo', fd);
    Super.logoHistory = [{ when: fmt.dt(new Date().toISOString()),
      what: file.name + ' (' + Math.round(file.size / 1024) + ' KB) uploaded' }]
      .concat(Super.logoHistory || []).slice(0, 8);
    brandingRemember(res.logo_url || '');
    applyBranding();
    toast(res.message || 'Logo uploaded', 'ok');
    input.value = '';
    await loadSettings(false);
  } catch (e) {
    if (btn) btn.disabled = false;
  }
}
async function removeLogo() {
  if (!await confirmDialog('Remove the platform logo and go back to the default mark?', 'Remove')) return;
  try {
    const res = await API.del('/api/settings/logo');
    Super.logoHistory = [{ when: fmt.dt(new Date().toISOString()), what: 'logo removed' }]
      .concat(Super.logoHistory || []).slice(0, 8);
    brandingRemember('');
    applyBranding();
    toast(res.message || 'Logo removed', 'ok');
    await loadSettings(false);
  } catch (e) { /* shown */ }
}

/* -------------------------------------------------------------- settings */
async function loadSettings() {
  const data = await API.get('/api/settings');
  Super.settings = data.settings || {};
  brandingRemember(Super.settings.logo_url || '');
  renderLogoCard(Super.settings);
  const box = $('#settingsForm');
  box.innerHTML = '';
  const fields = [
    ['platform_name', 'Platform name', 'text'],
    ['company_name', 'Company / operator', 'text'],
    ['support_email', 'Support e-mail', 'text'],
    ['powered_by', 'Powered by (footer)', 'textarea'],
    ['default_currency', 'Default currency', 'text'],
    ['map_tiles', 'Map tile URL ({z}/{x}/{y})', 'text'],
    ['default_lat', 'Map centre latitude', 'number'],
    ['default_lon', 'Map centre longitude', 'number'],
    ['default_zoom', 'Default zoom level', 'number'],
    ['online_timeout_s', 'Offline after (seconds)', 'number'],
    ['moving_speed_kmh', 'Moving threshold (km/h)', 'number'],
    ['retention_days', 'Message retention (days)', 'number'],
    ['portal_price', 'Reseller portal price (full year)', 'number'],
    ['ips_port', 'Wialon IPS TCP port', 'number'],
    ['apk_url', 'Android app URL (blank = /download/kivu-tracker.apk)', 'text'],
    ['apk_version', 'Android app version shown on /download', 'text'],
    ['apk_notes', 'Android app release notes ("what\'s new")', 'text']
  ];
  fields.forEach(f => {
    const w = el('div', { class: 'field' });
    w.appendChild(el('label', null, h(f[1])));
    const inp = f[2] === 'textarea'
      ? el('textarea', { name: f[0], rows: '2', maxlength: '180',
          placeholder: 'e.g. Powered by Acme Telematics' })
      : el('input', { type: f[2], name: f[0], step: 'any' });
    const v = Super.settings[f[0]];
    inp.value = v === undefined || v === null ? '' : v;
    w.appendChild(inp);
    if (f[0] === 'powered_by') {
      w.appendChild(el('div', { class: 'hint' }, 'Optional short text shown in the footer across the platform (180 characters max).'));
    }
    box.appendChild(w);
  });
  /* theme policy: "user" allows the dark/light switch, dark/light locks it */
  const tw = el('div', { class: 'field' });
  tw.appendChild(el('label', null, 'Theme'));
  tw.appendChild(selectBox('theme_mode', [
    { value: 'user', label: 'user — everyone can switch dark / light' },
    { value: 'dark', label: 'dark — locked (switch disabled)' },
    { value: 'light', label: 'light — locked (switch disabled)' }
  ], Super.settings.theme_mode || 'user'));
  box.appendChild(tw);
}
async function saveSettings() {
  try {
    const res = await API.put('/api/settings', readForm($('#settingsForm')));
    toast(res.message || 'Settings saved', 'ok');
    Super.settings = res.settings;
    setPoweredBy(Super.settings.powered_by || '');
  } catch (e) { /* shown */ }
}

/* ---------------------------------------------------------------- system */
async function loadHealth() {
  const box = $('#healthBox');
  try {
    const health = await API.get('/api/health');
    box.innerHTML = '';
    tiles(box, [
      { label: 'Application', value: health.app + ' ' + health.version, color: 'violet' },
      { label: 'PHP', value: health.php, color: 'teal' },
      { label: 'Database', value: health.database, tone: health.database === 'ok' ? 'ok' : 'danger' },
      { label: 'Driver', value: health.driver || '', color: 'cyan' },
      { label: 'Units', value: String(health.counters.units), color: 'blue' },
      { label: 'Messages', value: fmt.num(health.counters.messages, 0), color: 'violet' },
      { label: 'IPS port', value: String(health.ips_port), color: 'orange' },
      { label: 'Server time', value: fmt.dt(health.time), color: 'amber' }
    ]);
  } catch (e) { box.innerHTML = '<p class="muted">Health endpoint unavailable.</p>'; }
}
async function runJobs() {
  try {
    const r = await API.post('/api/tasks/run', {});
    toast('Jobs finished: ' + r.trips_detected + ' trips, ' + JSON.stringify(r.checks), 'ok', 6000);
    loadHealth();
    loadAudit();
  } catch (e) { /* shown */ }
}
async function loadDevices() {
  const data = await API.get('/api/devices/sessions?limit=100', { silent: true });
  renderTable($('#deviceLog'), {
    columns: [{ key: 'unit', label: 'Unit' }, { key: 'unique_id', label: 'Device ID' },
      { key: 'protocol', label: 'Protocol' }, { key: 'ip', label: 'IP' },
      { key: 'packets', label: 'Packets', type: 'number' },
      { key: 'login_ts', label: 'Connected', type: 'datetime' },
      { key: 'last_ts', label: 'Last packet', type: 'datetime' },
      { key: 'online', label: 'Online' }],
    rows: (data.sessions || []).map(s => Object.assign({}, s, { online: s.online ? 'yes' : 'no' }))
  });
}
async function loadAudit() {
  const data = await API.get('/api/audit', { silent: true });
  renderTable($('#auditLog'), {
    columns: [{ key: 'ts', label: 'Time', type: 'datetime' }, { key: 'username', label: 'User' },
      { key: 'action', label: 'Action' }, { key: 'entity', label: 'Entity' },
      { key: 'entity_id', label: 'ID' }, { key: 'ip', label: 'IP' }],
    rows: data.entries || []
  });
}
