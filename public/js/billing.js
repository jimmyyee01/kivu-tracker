/* =====================================================================
   KIVU Tracker — public/js/billing.js
   Client billing: subscription state, plan grid with every configured
   gateway (card / mobile money / bank / sandbox), invoices and the
   confirmation poll used after a gateway redirects the client back.
   ===================================================================== */
'use strict';

const Billing = { data: null };

document.addEventListener('DOMContentLoaded', async function () {
  const meta = await boot('billing');
  if (!meta) return;
  $('#btnReload').addEventListener('click', () => load(true));
  $('#btnCancel').addEventListener('click', toggleCancel);
  $('#btnSaveDetails').addEventListener('click', saveDetails);
  await load();
  await handleReturn();
});

async function load(showToast) {
  let data;
  try {
    data = await API.get('/api/billing');
  } catch (e) {
    if (e.status === 403) return renderSuperNotice();
    throw e;
  }
  Billing.data = data;
  State.billing = data.billing || {};
  renderStatus(data.billing || {}, data);
  renderPlans(data);
  renderInvoices(data);
  renderDetails(data);
  renderProfileBanner(data);
  renderMethods(data);
  const renewBox = $('#autoRenewBox');
  if (renewBox) renewBox.checked = data.auto_renew !== false;
  if (showToast) toast('Billing refreshed', 'ok');
}
function renderSuperNotice() {
  $('#planTitle').textContent = 'Billing lives inside a client account';
  $('#statusChip').textContent = 'platform admin';
  $('#statusChip').className = 'chip info';
  $('#statusNote').textContent = 'You are signed in as the platform administrator, which has no account of its own. '
    + 'Accounts → Plans & billing manages every client subscription, invoice and gateway credential.';
  $('#planGrid').innerHTML = '';
  $('#detailsForm').innerHTML = '';
  renderTable($('#invoiceTable'), { columns: [{ key: 'invoice_no', label: 'Invoice' }], rows: [] });
}

function renderStatus(b, data) {
  const plan = b.plan || {};
  $('#planTitle').textContent = plan.name ? plan.name + ' plan' : 'Free trial';
  const chip = $('#statusChip');
  const labels = { trialing: 'Free trial', active: 'Active', past_due: 'Payment due', expired: 'Expired',
    suspended: 'Suspended', comp: 'Complimentary' };
  chip.textContent = labels[b.status] || b.status;
  chip.className = 'chip ' + (b.status === 'active' || b.status === 'comp' ? 'ok'
    : (b.status === 'trialing' ? 'info' : 'danger'));
  const u = data.usage || {};
  $('#usageChip').textContent = (u.units || 0) + ' / ' + (u.units_limit || 0) + ' units · '
    + (u.storage_days || 0) + ' days history';

  let note = '';
  if (b.status === 'trialing') {
    note = 'Trial ends ' + (b.trial_ends_at ? fmt.dt(b.trial_ends_at) : 'soon') + ' — '
      + (b.trial_days_left || 0) + ' day(s) left. Pick a plan below to continue without interruption.';
  } else if (b.status === 'active') {
    note = 'Current period ends ' + (b.period_end ? fmt.dt(b.period_end) : '—')
      + (b.cancel_at_period_end ? ' — auto-renewal is cancelled.' : ' — renews automatically.');
  } else if (b.status === 'past_due') {
    note = 'The last invoice has not been paid — ' + (b.grace_days_left || 0)
      + ' day(s) of grace left. Pay below to keep tracking.';
  } else if (b.status === 'expired') {
    note = b.reason || 'The subscription has ended. Tracking resumes as soon as a payment is confirmed.';
  } else if (b.status === 'comp') {
    note = 'This account is complimentary — no billing applies.';
  }
  $('#statusNote').textContent = note;
  $('#btnCancel').style.display = b.status === 'active' && !b.cancel_at_period_end ? '' : 'none';

  const box = $('#billingStats');
  box.innerHTML = '';
  const statusTone = { active: 'ok', trialing: 'info', past_due: 'warn', expired: 'danger',
    suspended: 'danger', comp: 'violet' }[b.status] || 'info';
  tiles(box, [
    { label: 'Status', value: labels[b.status] || b.status, tone: statusTone },
    { label: 'Units', value: (u.units || 0) + ' / ' + (u.units_limit || 0), color: 'violet' },
    { label: 'Trial days left', value: String(b.trial_days_left || 0),
      tone: (b.trial_days_left || 0) <= 2 ? 'warn' : 'info' },
    { label: 'Days remaining', value: String(b.days_left || 0),
      tone: (b.days_left || 0) <= 7 ? 'warn' : 'ok' },
    { label: 'Currency', value: data.account.currency || 'USD', color: 'cyan' },
    { label: 'Invoices', value: String((data.invoices || []).length), color: 'orange' }
  ]);

  /* flexible overage: extra devices beyond the plan limit, billed next invoice */
  const ov = data.overage || {};
  const ovNote = document.getElementById('overageNote');
  if (ovNote) {
    ovNote.textContent = ov.extra_units > 0
      ? 'Extra devices: you are ' + ov.extra_units + ' over the ' + ov.unit_limit + '-device limit — '
        + fmt.money(ov.amount, ov.currency) + ' (' + fmt.money(ov.extra_unit_price, ov.currency)
        + ' each) will be added to your next invoice, automatically.'
      : '';
  }

  /* what the reminder e-mails promise the client */
  const rm = data.reminders || {};
  const rNote = $('#remindersNote');
  if (rNote) {
    rNote.textContent = !rm.enabled
      ? 'Payment reminders are switched off — pay before the date above to avoid deactivation.'
      : (rm.recipient ? 'We e-mail ' + rm.recipient + ' ' : 'We e-mail your account contact ')
        + (rm.days || []).join(', ') + ' day(s) before deactivation, so the fleet never goes offline'
        + ' unexpectedly.';
  }
}

function renderPlans(data) {
  const grid = $('#planGrid');
  grid.innerHTML = '';
  const currentId = (data.billing || {}).plan_id;
  const usable = (data.plans || []).filter(p => (p.gateways || []).length).length;
  $('#gatewayNote').textContent = usable
    ? 'Pay with card (Visa / Mastercard), mobile money (M-Pesa, Pesapal, Flutterwave), PayPal or bank transfer — '
      + 'whichever gateways the administrator enabled. Sandbox simulations move no money.'
    : 'No payment gateway is configured yet — ask the platform administrator to add API keys under '
      + 'Accounts → Plans & billing → Gateway credentials.';
  (data.plans || []).forEach(p => {
    const card = el('div', { class: 'plan-card' + (p.id === currentId ? ' current' : '') });
    card.appendChild(el('h3', null, h(p.name)));
    card.appendChild(el('div', { class: 'tagline' }, h(p.tagline || '')));
    card.appendChild(el('div', { class: 'plan-price' },
      h(fmt.money(p.price, p.currency)) + '<small> / ' + h(p.interval_label) + '</small>'));
    const ul = el('ul', { class: 'plan-features' });
    (p.features || []).forEach(f => ul.appendChild(el('li', null, h(f))));
    card.appendChild(ul);
    const row = el('div', { class: 'plan-actions' });
    if (p.id === currentId) row.appendChild(el('span', { class: 'chip ok' }, 'Current plan'));
    (p.gateways || []).forEach(gw => {
      const btn = el('button', { class: 'btn ' + (gw.sandbox ? '' : 'primary') + ' sm',
        title: gw.label + ' — ' + gw.methods_label }, 'Pay with ' + gw.short);
      btn.addEventListener('click', () => checkout(p, gw));
      row.appendChild(btn);
    });
    if (!(p.gateways || []).length) {
      row.appendChild(el('span', { class: 'hint' }, 'No gateway can charge ' + p.currency + ' yet'));
    }
    card.appendChild(row);
    grid.appendChild(card);
  });
}
function renderInvoices(data) {
  const currentId = (data.billing || {}).plan_id;
  renderTable($('#invoiceTable'), {
    columns: [{ key: 'invoice_no', label: 'Invoice' }, { key: 'created_at', label: 'Created', type: 'datetime' },
      { key: 'plan_id', label: 'Plan' }, { key: 'gateway', label: 'Gateway' },
      { key: 'amount', label: 'Amount', type: 'money' }, { key: 'currency', label: 'Cur.' },
      { key: 'status', label: 'Status' }, { key: 'paid_at', label: 'Paid', type: 'datetime' },
      { key: 'actions', label: '', type: 'html' }],
    rows: (data.invoices || []).map(i => Object.assign({}, i, {
      plan_id: i.plan_id === currentId ? 'Current' : (i.plan_id || ''),
      actions: i.status === 'pending'
        ? '<button class="btn xs" data-check="' + h(i.id) + '">Check</button> '
          + (i.checkout_url && i.gateway === 'sandbox'
            ? '<a class="btn xs" href="' + h(i.checkout_url) + '">Pay</a>' : '') : ''})),
    empty: 'No invoices yet — the first one appears when you choose a plan.'
  });
  $$('#invoiceTable button[data-check]').forEach(btn => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        const r = await API.post('/api/billing/pay/' + btn.dataset.check + '/status', {});
        toast(r.message, r.settled ? 'ok' : 'warn', 6000);
        await load();
      } catch (e) { btn.disabled = false; }
    });
  });
}
function renderDetails(data) {
  const a = data.account || {};
  const p = data.profile || {};
  const box = $('#detailsForm');
  box.innerHTML = '';
  [['contact_name', 'Company / contact name', 'text'], ['billing_email', 'Billing e-mail', 'email'],
   ['contact_phone', 'Billing phone (used for M-Pesa)', 'tel'],
   ['billing_address', 'Billing address (street)', 'text'],
   ['billing_city', 'City', 'text'], ['billing_country', 'Country', 'text']].forEach(f => {
    const w = el('div', { class: 'field', style: 'max-width:420px' });
    w.appendChild(el('label', null, h(f[1])));
    const inp = el('input', { type: f[2], name: f[0] });
    inp.value = (a[f[0]] !== undefined && a[f[0]] !== null && a[f[0]] !== '') ? a[f[0]]
      : (p[f[0]] || '');
    w.appendChild(inp);
    box.appendChild(w);
  });
}
/* Amber banner until name + billing e-mail are complete (checkout refuses
   without them, so surface it before the client hits the error). */
function renderProfileBanner(data) {
  const box = $('#detailsBanner');
  if (!box) return;
  const p = data.profile || {};
  box.innerHTML = '';
  if (p.complete) return;
  const missing = (p.missing || []).map(m => ({
    contact_name: 'contact name', billing_email: 'billing e-mail'
  }[m] || m)).join(', ');
  const bar = el('div', { class: 'banner warn' },
    'Complete your billing details (' + (missing || 'missing information') + ') — they are required '
    + 'before the first payment and for the renewal reminders. ');
  const btn = el('button', { class: 'btn sm', text: 'Add details now' });
  btn.addEventListener('click', () => {
    const d = document.getElementById('details');
    if (d) d.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  bar.appendChild(btn);
  box.appendChild(bar);
}
/* Saved payment methods — the instrument automatic billing will charge. */
function renderMethods(data) {
  const box = $('#methodBox');
  if (!box) return;
  box.innerHTML = '';
  const list = data.methods || [];
  if (!list.length) {
    box.appendChild(el('p', { class: 'hint' },
      'No payment method saved yet — one is stored automatically with your first payment '
      + '(or the M-Pesa number), and automatic billing uses it at renewal.'));
    return;
  }
  list.forEach(m => {
    const row = el('div', { class: 'row', style: 'gap:10px;align-items:center;margin-top:6px' });
    const label = (m.brand || m.gateway) + (m.last4 ? ' •••• ' + m.last4 : (m.phone ? ' ' + m.phone : ''));
    row.appendChild(el('span', { class: 'chip' + (m.is_default ? ' ok' : '') }, h(label)));
    row.appendChild(el('span', { class: 'hint' }, h(m.gateway)
      + (m.is_default ? ' — used for automatic billing' : '')));
    const del = el('button', { class: 'btn xs danger', text: 'Remove' });
    del.addEventListener('click', async () => {
      if (!await confirmDialog('Remove this saved payment method? Renewals will need a manual payment.')) return;
      try {
        const r = await API.del('/api/billing/methods/' + encodeURIComponent(m.id));
        toast(r.message, 'ok', 6000);
        await load();
      } catch (e) { /* shown */ }
    });
    row.appendChild(del);
    box.appendChild(row);
  });
}
async function saveDetails() {
  try {
    const payload = readForm($('#detailsForm'));
    const renewBox = $('#autoRenewBox');
    if (renewBox) payload.auto_renew = renewBox.checked;
    const r = await API.put('/api/billing/account', payload);
    toast(r.message || 'Saved', 'ok');
    await load();
  } catch (e) { /* shown */ }
}

/* --------------------------------------------------------------- checkout - */
async function checkout(plan, gw) {
  const phone = gw.phone_required ? (Billing.data.account.contact_phone || '') : '';
  if (gw.phone_required && !phone) {
    return promptPhone(plan, gw);
  }
  return startPayment(plan, gw, phone);
}
function promptPhone(plan, gw) {
  const body = el('form');
  const w = el('div', { class: 'field' });
  w.appendChild(el('label', null, 'M-Pesa phone number (2547…)'));
  const inp = el('input', { type: 'tel', name: 'phone', placeholder: '254712345678' });
  w.appendChild(inp);
  body.appendChild(w);
  body.appendChild(el('p', { class: 'hint' },
    'The amount of ' + fmt.money(plan.price, plan.currency) + ' is pushed to this number; enter the M-Pesa PIN on the phone.'));
  return modal({
    title: 'Pay ' + plan.name + ' with ' + gw.short, body: body,
    buttons: [{ label: 'Cancel', class: 'ghost' },
      { label: 'Send STK push', class: 'primary', onClick: async m => {
        const val = inp.value.trim();
        if (!val) { toast('Enter the phone number', 'warn'); return false; }
        await startPayment(plan, gw, val);
        return true;
      } }]
  });
}
async function startPayment(plan, gw, phone) {
  try {
    const r = await API.post('/api/billing/checkout', {
      plan_id: plan.id, gateway: gw.code, phone: phone || undefined });
    toast(r.message || 'Invoice created', 'ok', 7000);
    if (r.checkout_url) {
      window.location.href = r.checkout_url;
      return;
    }
    /* M-Pesa STK push: no redirect, so watch the invoice until it settles */
    if (r.gateway_ref) watchPayment(r.payment.id);
    await load();
  } catch (e) {
    if (e && /billing details/i.test(String(e.message || ''))) {
      toast(e.message, 'warn', 7000);
      const det = document.getElementById('details');
      if (det) det.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    /* the toast/modal already explained it */
  }
}
/* Polls an invoice (M-Pesa push, or a client who closed the browser tab). */
function watchPayment(id, attempt) {
  attempt = attempt || 0;
  if (attempt > 20) return;
  setTimeout(async () => {
    try {
      const r = await API.post('/api/billing/pay/' + id + '/status', {}, { silent: true });
      if (r.settled) {
        toast('Payment confirmed — subscription active', 'ok', 6000);
        await load();
        return;
      }
      if (r.status === 'failed') { toast(r.message || 'Payment failed', 'error'); await load(); return; }
      watchPayment(id, attempt + 1);
    } catch (e) { watchPayment(id, attempt + 1); }
  }, 4000);
}

/* The gateway redirects back with ?payment=<id>&status=… — confirm with the
   gateway (never trust the query string) and report the outcome. */
async function handleReturn() {
  const params = new URLSearchParams(location.search);
  const payment = params.get('payment');
  const status = params.get('status');
  if (!payment) return;
  const note = $('#returnNote');
  note.innerHTML = '';
  const bar = el('div', { class: 'banner info' }, 'Confirming the payment with the gateway…');
  note.appendChild(bar);
  try {
    const r = await API.post('/api/billing/pay/' + payment + '/status', {});
    bar.className = 'banner ' + (r.settled ? 'info' : (status === 'cancel' ? '' : 'warn'));
    bar.textContent = r.settled ? 'Payment confirmed — ' + (r.period_end
      ? 'your plan is active until ' + fmt.dt(r.period_end) + '.'
      : 'your subscription is active.')
      : (status === 'cancel' ? 'Checkout cancelled — the invoice stays pending.' : r.message);
    if (!r.settled && status !== 'cancel') watchPayment(payment);
    history.replaceState(null, '', '/billing');
    await load();
  } catch (e) {
    bar.className = 'banner warn';
    bar.textContent = e.message || 'Could not confirm the payment yet.';
  }
}
async function toggleCancel() {
  const ok = await confirmDialog('Cancel auto-renewal? The plan stays active until the end of the paid period.', 'Cancel renewal');
  if (!ok) return;
  try {
    const r = await API.post('/api/billing/cancel', { cancel: true });
    toast(r.message, 'ok', 6000);
    await load();
  } catch (e) { /* shown */ }
}
