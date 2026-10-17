/* =====================================================================
   KIVU Tracker — public/js/reseller.js
   The reseller console: portal status (full-year licence) + the client
   accounts inside the portal. Everything else is the platform's business.
   ===================================================================== */
'use strict';

const Reseller = { data: null };

document.addEventListener('DOMContentLoaded', async function () {
  const meta = await boot('reseller');
  if (!meta) return;
  $('#btnReload').addEventListener('click', () => load(true));
  $('#btnNewAccount').addEventListener('click', () => editAccount(null));
  await load();
});

async function load(showToast) {
  let data;
  try {
    data = await API.get('/api/reseller/state');
  } catch (e) {
    $('#portalNote').textContent = 'Cannot load your portal: ' + e.message;
    return;
  }
  Reseller.data = data;
  renderPortal(data);
  renderAccounts(data);
  if (showToast) toast('Refreshed', 'ok');
}

function renderPortal(data) {
  const p = data.portal || {};
  $('#portalTitle').textContent = p.name || 'Your portal';
  const chip = $('#portalChip');
  const labels = { active: 'Active', pending: 'Full year not paid yet', expired: 'Year ended', suspended: 'Suspended' };
  chip.textContent = labels[p.status] || p.status;
  chip.className = 'chip ' + (p.status === 'active' ? 'ok' : (p.status === 'pending' ? 'warn' : 'danger'));
  $('#portalNote').textContent = p.status === 'active'
    ? 'Licensed until ' + fmt.dt(p.period_end) + ' (' + (p.days_left || 0) + ' day(s) left) — '
      + (data.can_create ? 'you can create client accounts.' : 'renewal required to keep managing accounts.')
    : 'This portal needs the full-year payment (' + fmt.money(p.price || 0, p.currency || 'USD')
      + ') before you can create client accounts — the platform administrator sends the invoice.';
  $('#btnNewAccount').disabled = !data.can_create;
  /* colour-coded portal KPIs (same palette as the other dashboards) */
  const rows = data.accounts || [];
  const counts = { active: 0, trialing: 0, expired: 0 };
  rows.forEach(a => { if (counts[a.status] !== undefined) counts[a.status]++; });
  const units = rows.reduce((n, a) => n + (Number(a.units) || 0), 0);
  tiles($('#portalStats'), [
    { label: 'Client accounts', value: String(rows.length), color: 'blue' },
    { label: 'Active', value: String(counts.active), tone: 'ok' },
    { label: 'Trialing', value: String(counts.trialing), color: 'violet' },
    { label: 'Expired', value: String(counts.expired),
      tone: counts.expired > 0 ? 'danger' : 'neutral' },
    { label: 'Devices managed', value: String(units), color: 'cyan' },
    { label: 'Year days left', value: String(p.days_left || 0),
      tone: (p.status === 'active' && (p.days_left || 0) > 30) ? 'ok'
        : ((p.days_left || 0) > 0 ? 'warn' : 'danger') },
    { label: 'Year price', value: fmt.money(p.price || 0, p.currency || 'USD'), color: 'teal' }
  ]);
}

function renderAccounts(data) {
  const rows = data.accounts || [];
  $('#accountChip').textContent = rows.length + ' client account(s)';
  renderTable($('#accountsTable'), {
    columns: [{ key: 'name', label: 'Account' }, { key: 'status', label: 'Status' },
      { key: 'plan', label: 'Plan' }, { key: 'devices', label: 'Devices' },
      { key: 'contact_email', label: 'Contact' },
      { key: 'trial_ends_at', label: 'Trial ends', type: 'datetime' },
      { key: 'period_end', label: 'Paid until', type: 'datetime' }],
    rows: rows.map(a => Object.assign({}, a, {
      devices: (a.units || 0) + ' / ' + (a.units_limit || 0),
      status: '<span class="chip ' + (a.status === 'active' || a.status === 'comp' ? 'ok'
        : (a.status === 'trialing' ? 'info' : 'danger')) + '">' + h(a.status) + '</span>' }))
  });
}

function planOptions() {
  return ((Reseller.data && Reseller.data.plans) || [])
    .filter(p => p.enabled)
    .map(p => ({ value: p.id, label: p.name + ' — ' + fmt.money(p.price, p.currency)
      + '/' + p.interval_label + ' · ' + p.unit_limit + ' units' }));
}

function editAccount(id) {
  formModal({
    title: 'New client account',
    wide: true,
    fields: [
      { name: 'name', label: 'Account / fleet name', required: true },
      { name: 'plan_id', label: 'Plan', type: 'select', options: planOptions() },
      { name: 'contact_email', label: 'Client contact e-mail', required: true },
      { name: 'admin_username', label: 'Client admin username', required: true },
      { name: 'admin_password', label: 'Client admin password', required: true,
        help: 'The client signs in with this username and password' }
    ],
    saveLabel: 'Create account',
    onSave: async form => {
      const res = await API.post('/api/reseller/accounts', form);
      toast(res.message || 'Account created', 'ok');
      await load();
    }
  });
}
