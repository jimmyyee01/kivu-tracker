/* =====================================================================
   KIVU Tracker — tools/ui-billing.js
   Browser smoke test for the billing screens (development only).

   Each stage loads the REAL page in a same-origin <iframe> (so it runs
   exactly as a user sees it) and the harness drives it:

      1. /register      plan cards render, the form submits, a 5-day trial starts
      2. /pay/<id>      the simulated gateway approves the invoice
      3. /billing       subscription state, plan grid and invoices render

   Results are printed into #harness-out, which makes the whole thing
   runnable without a click:

     chrome --headless=new --disable-gpu --virtual-time-budget=30000 ^
       --dump-dom "http://127.0.0.1:8099/tools/ui-billing.html?tag=abc123"
   ===================================================================== */
'use strict';

document.addEventListener('DOMContentLoaded', function () {
  const out = document.getElementById('harness-out');
  const log = m => { out.textContent += m + '\n'; };
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const tag = new URLSearchParams(location.search).get('tag') || String(Date.now()).slice(-6);

  function mount(url) {
    return new Promise((resolve, reject) => {
      const frame = document.createElement('iframe');
      frame.style.cssText = 'width:100%;height:620px;border:1px solid #263149;border-radius:8px;margin:6px 0';
      frame.src = url;
      frame.onload = () => resolve(frame);
      frame.onerror = () => reject(new Error('cannot load ' + url));
      const box = document.getElementById('frames');
      box.innerHTML = '';
      box.appendChild(frame);
    });
  }
  const q = (frame, sel) => frame.contentWindow.document.querySelector(sel);
  const qa = (frame, sel) => Array.from(frame.contentWindow.document.querySelectorAll(sel));
  /* `const API` in a page script is frame-scoped, so reach it through eval */
  const api = frame => frame.contentWindow.eval('API');

  (async function run() {
    try {
      /* ---------- 1. self-service registration (5-day trial) ---------- */
      const reg = await mount('/register');
      const w = reg.contentWindow;
      let plans = 0;
      for (let i = 0; i < 80; i++) {
        await wait(150);
        plans = qa(reg, '#planGrid .plan-card').length;
        if (plans) break;
      }
      log('STEP1 /register: plan cards=' + plans + ' | note='
        + ((q(reg, '#formNote') || {}).textContent || '').slice(0, 70));
      log('STEP2 plans: ' + qa(reg, '#planGrid .plan-card h3').map(x => x.textContent).join(', '));
      const field = n => q(reg, '#signupForm [name="' + n + '"]');
      field('company').value = 'Harness Fleet ' + tag;
      field('email').value = 'harness' + tag + '@example.com';
      field('full_name').value = 'Harness Owner';
      field('phone').value = '254700' + tag;
      field('password').value = 'Harness@123';
      q(reg, '#submitBtn').click();
      let signed = false;
      for (let i = 0; i < 100; i++) {
        await wait(150);
        const card = q(reg, '#doneCard');
        if (card && card.style.display !== 'none') { signed = true; break; }
      }
      log('STEP3 signup completed=' + signed + ' | '
        + ((q(reg, '#doneText') || {}).textContent || '').slice(0, 130));
      log('STEP4 token stored=' + (w.localStorage.getItem('kt_token') ? 'yes' : 'no'));
      const billing0 = await api(reg).get('/api/billing');
      const accountId = (billing0.account || {}).id || '';
      log('STEP4b account id=' + accountId + ' plan=' + (billing0.billing || {}).plan_code);

      /* ---------- 2. simulated gateway page ---------- */
      const co = await api(reg).post('/api/billing/checkout', { plan_code: 'starter', gateway: 'sandbox' });
      log('STEP5 checkout: invoice=' + co.payment.invoice_no + ' amount=' + co.payment.amount
        + ' ' + co.payment.currency + ' url=' + co.checkout_url);
      const pay = await mount('/pay/' + co.payment.id);
      let amount = '';
      for (let i = 0; i < 80; i++) {
        await wait(150);
        amount = (q(pay, '#amount') || {}).textContent || '';
        if (amount && amount !== '—') break;
      }
      log('STEP6 /pay page: invoice=' + (q(pay, '#invoiceNo') || {}).textContent
        + ' plan=' + (q(pay, '#planName') || {}).textContent + ' amount=' + amount);
      q(pay, '#btnPay').click();
      let approved = false;
      for (let i = 0; i < 80; i++) {
        await wait(150);
        const done = q(pay, '#payDone');
        if (done && done.style.display !== 'none') { approved = true; break; }
      }
      log('STEP7 sandbox approve=' + approved + ' | '
        + ((q(pay, '#doneText') || {}).textContent || '').slice(0, 120));

      /* ---------- 3. billing console ---------- */
      const bill = await mount('/billing');
      let rows = 0;
      for (let i = 0; i < 100; i++) {
        await wait(150);
        rows = qa(bill, '#planGrid .plan-card').length;
        if (rows) break;
      }
      log('STEP8 /billing: plans=' + rows + ' title=' + (q(bill, '#planTitle') || {}).textContent
        + ' chip=' + (q(bill, '#statusChip') || {}).textContent);
      log('STEP9 gateway buttons: ' + qa(bill, '#planGrid .plan-actions button').map(b => b.textContent).join(' | '));
      log('STEP10 stats: ' + qa(bill, '#billingStats .tile').map(s => s.textContent.replace(/\s+/g, ' ')).join(' · '));
      /* the KPI tiles must each carry their own colour class (k-blue, k-green, …) */
      const tileKeys = sel => qa(bill, sel + ' .tile').map(t => (t.className.match(/k-[a-z]+/) || ['none'])[0]);
      const billColors = tileKeys('#billingStats');
      const distinct = Array.from(new Set(billColors)).length;
      log('STEP10b billing tile colours: ' + JSON.stringify(billColors)
        + ' distinct=' + distinct);
      log('STEP11 invoice rows=' + qa(bill, '#invoiceTable tbody tr').length + ' first='
        + ((q(bill, '#invoiceTable tbody tr') || {}).textContent || '').replace(/\s+/g, ' ').slice(0, 90));
      log('STEP12 reminder promise: '
        + JSON.stringify(((q(bill, '#remindersNote') || {}).textContent || '').slice(0, 140)));
      const b = await api(bill).get('/api/billing');
      log('RESULT status=' + b.billing.status + ' plan=' + b.billing.plan_code
        + ' days_left=' + b.billing.days_left + ' allowed=' + b.billing.allowed
        + ' | tileColours=' + distinct + ' distinct');

      /* tear the test account down again (super administrator, demo credentials) */
      try {
        const result = await bill.contentWindow.eval(
          "(async () => {"
          + " const r = await API.login('superadmin', 'SuperAdmin@123');"
          + " API.setToken(r.token);"
          + " const out = await API.del('/api/accounts/' + " + JSON.stringify(accountId) + " + '?force=1');"
          + " return out.message || 'deleted'; })()");
        log('CLEANUP: test account removed — ' + result);
      } catch (e) {
        log('CLEANUP skipped: ' + (e && e.message ? e.message : String(e)));
      }
      log('HARNESS DONE');
    } catch (e) {
      log('HARNESS EXCEPTION: ' + (e && e.message ? e.message : String(e)));
      log('HARNESS DONE');
    }
  })();
});
