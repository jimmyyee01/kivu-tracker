/* =====================================================================
   KIVU Tracker — public/js/register.js
   Self-service signup: plan picker + account form -> POST /api/signup
   (5-day free trial, signed in straight away).
   ===================================================================== */
'use strict';

const Register = { plans: [], gateways: [], chosen: '', trialDays: 5, assetTypes: {} };

/* Asset types from /api/plans — the glyph doubles as the map/tree icon. */
function renderAssetTypes() {
  const sel = $('#asset_type');
  if (!sel) return;
  sel.innerHTML = '';
  Object.keys(Register.assetTypes).forEach(code => {
    const t = Register.assetTypes[code];
    const opt = el('option', { value: code }, (t.glyph || '') + ' ' + t.label);
    sel.appendChild(opt);
  });
}

document.addEventListener('DOMContentLoaded', async function () {
  let data = null;
  try {
    data = await API.get('/api/plans', { silent: true });
  } catch (e) {
    $('#formNote').textContent = 'Cannot reach the server — reload the page.';
    return;
  }
  Register.plans = data.plans || [];
  Register.gateways = data.gateways || [];
  Register.trialDays = data.trial_days || 5;
  Register.assetTypes = data.asset_types || {};
  if (data.platform) $('#brand').textContent = data.platform;
  brandingRemember(data.logo || '');
  applyBranding();
  $('#trialBadge').textContent = Register.trialDays + '-day';
  renderAssetTypes();
  renderPlans();
  if (data.signup_enabled === false) {
    $('#signupClosed').style.display = 'flex';
    $('#submitBtn').disabled = true;
  }
  $('#signupForm').addEventListener('submit', submit);
});

function renderPlans() {
  const grid = $('#planGrid');
  grid.innerHTML = '';
  if (!Register.plans.length) {
    grid.innerHTML = '<p class="muted">No plans have been published yet.</p>';
    return;
  }
  if (!Register.chosen) Register.chosen = Register.plans[0].id;
  Register.plans.forEach(p => {
    const card = el('div', { class: 'plan-card' + (p.id === Register.chosen ? ' current' : '') });
    card.appendChild(el('h3', null, h(p.name)));
    card.appendChild(el('div', { class: 'tagline' }, h(p.tagline || '')));
    card.appendChild(el('div', { class: 'plan-price' },
      h(fmt.money(p.price, p.currency)) + '<small> / ' + h(p.interval_label) + '</small>'));
    const ul = el('ul', { class: 'plan-features' });
    (p.features || []).forEach(f => ul.appendChild(el('li', null, h(f))));
    card.appendChild(ul);
    const btn = el('button', { class: 'btn ' + (p.id === Register.chosen ? 'primary' : ''), type: 'button' },
      p.id === Register.chosen ? 'Selected' : 'Choose ' + p.name);
    btn.addEventListener('click', () => { Register.chosen = p.id; renderPlans(); });
    const row = el('div', { class: 'plan-actions' });
    row.appendChild(btn);
    card.appendChild(row);
    grid.appendChild(card);
  });
  $('#formNote').textContent = Register.trialDays + '-day free trial on '
    + (Register.plans.find(p => p.id === Register.chosen) || {}).name + ' · cancel any time.';
}

async function submit(ev) {
  ev.preventDefault();
  const form = readForm($('#signupForm'));
  form.plan_code = (Register.plans.find(p => p.id === Register.chosen) || {}).code || '';
  if (!form.company || !form.email || !form.password) {
    toast('Company, e-mail and password are required', 'warn');
    return;
  }
  const btn = $('#submitBtn');
  btn.disabled = true;
  btn.textContent = 'Creating your account…';
  try {
    const res = await API.post('/api/signup', form, { noRedirect: true });
    API.setToken(res.token);
    $('#signupForm').style.display = 'none';
    $('#planGrid').style.display = 'none';
    $('#doneCard').style.display = 'block';
    const when = res.trial_ends_at ? fmt.dt(res.trial_ends_at) : (res.trial_days + ' days');
    $('#doneText').innerHTML = 'Welcome <b>' + h(res.user.full_name || res.user.username) + '</b>! '
      + 'The account <b>' + h(res.account.name) + '</b> is on a ' + res.trial_days + '-day free trial'
      + ' (until ' + h(when) + ').';
    $('#doneHint').textContent = 'Next: 1. add your billing details — needed for the invoice and the automatic '
      + 'renewal after the trial. 2. add your devices in Administration → Units — new units already carry the '
      + 'asset type you picked. Device password: track.';
    toast(res.message || 'Trial started', 'ok');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (e) {
    btn.disabled = false;
    btn.textContent = 'Create my account & start the trial';
    if (e.data && e.data.error) toast(e.data.error, 'error', 6000);
  }
}
