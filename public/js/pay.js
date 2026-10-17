/* =====================================================================
   KIVU Tracker — public/js/pay.js
   The simulated ("sandbox") gateway page: /pay/<invoice id>. It is only
   reachable for invoices created with the sandbox driver — real gateways
   use their own hosted pages and confirm through /api/webhooks/<gw>.
   ===================================================================== */
'use strict';

const Pay = { id: '' };

document.addEventListener('DOMContentLoaded', async function () {
  Pay.id = (location.pathname.split('/').pop() || '').trim();
  if (!Pay.id) { document.body.innerHTML = '<p style="padding:20px">Missing invoice id.</p>'; return; }
  let data;
  try {
    data = await API.get('/api/pay/' + encodeURIComponent(Pay.id), { silent: true, noRedirect: true });
  } catch (e) {
    document.body.innerHTML = '<div class="pay-wrap"><div class="pay-card"><h2>Invoice not found</h2>'
      + '<p class="muted">' + h(e.message) + '</p><a class="btn" href="/billing">Back to billing</a></div></div>';
    return;
  }
  const p = data.payment;
  $('#brand').textContent = (State.meta && State.meta.platform) || data.platform || 'KIVU Tracker';
  brandingRemember(data.logo || '');
  applyBranding();
  $('#invoiceNo').textContent = p.invoice_no;
  $('#planName').textContent = (data.plan && data.plan.name) || '—';
  $('#accountName').textContent = data.account || '—';
  $('#amount').textContent = fmt.money(p.amount, p.currency);
  if (!data.simulated) $('#gatewayTitle').textContent = data.gateway_label;
  if (p.status === 'paid') return showDone('This invoice is already paid', 'Invoice ' + p.invoice_no
    + ' was settled on ' + fmt.dt(p.paid_at) + '.');
  if (p.status === 'canceled') $('#payNote').textContent = 'This invoice was cancelled.';
  $('#btnPay').addEventListener('click', () => complete('paid'));
  $('#btnFail').addEventListener('click', () => complete('failed'));
  $('#btnCancel').addEventListener('click', () => { location.href = '/billing?payment=' + Pay.id + '&status=cancel'; });
  if (!data.simulated) {
    /* a live invoice should not be settled here */
    $('#payBody').style.display = 'none';
    $('#payNote').textContent = 'This invoice belongs to a live gateway — pay it on the gateway page you were sent to.';
    showDone('Live gateway invoice', 'Return to billing to check the payment status.');
  }
});

async function complete(outcome) {
  const btn = $('#btnPay');
  btn.disabled = true;
  try {
    const r = await API.post('/api/pay/' + encodeURIComponent(Pay.id) + '/complete', { outcome: outcome },
      { noRedirect: true });
    if (outcome === 'paid') {
      showDone('Payment approved', r.message + (r.period_end ? ' Active until ' + fmt.dt(r.period_end) + '.' : ''));
    } else if (outcome === 'failed') {
      showDone('Payment declined', 'The invoice stays pending so the client can try again with another method.');
    }
  } catch (e) {
    btn.disabled = false;
    toast(e.message, 'error', 6000);
  }
}
function showDone(title, text) {
  $('#payBody').style.display = 'none';
  $('#payDone').style.display = 'block';
  $('#doneTitle').textContent = title;
  $('#doneText').textContent = text;
}
