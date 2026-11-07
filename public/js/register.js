/* =====================================================================
   KIVU Tracker — public/js/register.js
   Self-service signup: the account form only -> POST /api/signup
   (5-day free trial, signed in straight away). No plan is chosen here:
   the plans are offered in Billing once the trial has ended.
   ===================================================================== */
'use strict';

const Register = { trialDays: 5, assetTypes: {} };


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
  Register.trialDays = data.trial_days || 5;
  Register.assetTypes = data.asset_types || {};
  if (data.platform) $('#brand').textContent = data.platform;
  brandingRemember(data.logo || '');
  setPoweredBy(data.powered_by || '');
  applyBranding();
  $('#trialBadge').textContent = Register.trialDays + '-day';
  renderAssetTypes();
  $('#formNote').textContent = Register.trialDays + '-day free trial · no plan or card needed · '
    + 'the plans appear when the trial ends.';
  if (data.signup_enabled === false) {
    $('#signupClosed').style.display = 'flex';
    $('#submitBtn').disabled = true;
  }
  $('#signupForm').addEventListener('submit', submit);
});

async function submit(ev) {
  ev.preventDefault();
  const form = readForm($('#signupForm'));
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
    $('#doneCard').style.display = 'block';
    const when = res.trial_ends_at ? fmt.dt(res.trial_ends_at) : (res.trial_days + ' days');
    $('#doneText').innerHTML = 'Welcome <b>' + h(res.user.full_name || res.user.username) + '</b>! '
      + 'The account <b>' + h(res.account.name) + '</b> is on a ' + res.trial_days + '-day free trial'
      + ' (until ' + h(when) + ').';
    $('#doneHint').textContent = 'When the trial ends the console shows the plans — pick one to keep tracking; '
      + 'your units and history stay exactly as they are. Next: add your devices in Administration → Units — new '
      + 'units already carry the asset type you picked. Device password: track.';
    toast(res.message || 'Trial started', 'ok');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (e) {
    btn.disabled = false;
    btn.textContent = 'Create my account & start the trial';
    if (e.data && e.data.error) toast(e.data.error, 'error', 6000);
  }
}
