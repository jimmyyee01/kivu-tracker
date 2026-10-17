/* =====================================================================
   KIVU Tracker — tools/ui-super-billing.js
   Driver for tools/ui-super-billing.html. Loads the real super console,
   opens Plans & billing, toggles the sandbox payment integration off via
   the per-gateway switch, saves, verifies through the API, switches it
   back on and reports the outcome in #harness-out.
   Development aid only — see the header of ui-super-billing.html.
   ===================================================================== */
'use strict';

document.addEventListener('DOMContentLoaded', function () {
  const out = document.getElementById('harness-out');
  const log = m => { out.textContent += m + '\n'; };
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const toasts = () => Array.prototype.slice.call(document.querySelectorAll('.toast'))
    .map(t => t.textContent).join(' || ');
  const gwSwitch = () => document.querySelector('#gatewayForm select[name="sandbox.enabled"]');

  (async function run() {
    try {
      /* 1. wait for the console to boot */
      let booted = false;
      for (let i = 0; i < 100; i++) {
        await wait(150);
        if (State && State.user) { booted = true; break; }
      }
      log('STEP1 boot: role=' + ((State && State.user) ? State.user.role : '(none)'));
      /* the top bar must offer the Android download to every signed-in role */
      const appLink = document.querySelector('.topbar a.navlink[href="/download"]');
      log('STEP1b download menu link: ' + (appLink ? JSON.stringify(appLink.textContent) : 'MISSING'));
      if (!booted || !State.user || State.user.role !== 'super') {
        log('RESULT FAILED: super console did not boot with a super session');
        log('HARNESS DONE'); return;
      }

      /* 2. open the Plans & billing tab like a user */
      const tab = document.querySelector('#superTabs .tab[data-pane="paneBilling"]');
      log('STEP2 billing tab present: ' + !!tab);
      if (tab) tab.click();
      let cards = 0;
      for (let i = 0; i < 100; i++) {
        await wait(150);
        cards = document.querySelectorAll('#gatewayForm .card').length;
        if (cards) break;
      }
      log('STEP3 gateway cards rendered: ' + cards);
      if (!cards) {
        log('RESULT FAILED: no gateway cards rendered (gateway_meta missing?)');
        log('HARNESS DONE'); return;
      }

      /* 3. inspect the per-gateway switches */
      const swSandbox = gwSwitch();
      const swStripe = document.querySelector('#gatewayForm select[name="stripe.enabled"]');
      const sandboxCard = swSandbox ? swSandbox.closest('.card') : null;
      log('STEP4 switches: sandbox=' + !!swSandbox + ' (value=' + (swSandbox ? swSandbox.value : '-')
        + ') stripe=' + !!swStripe
        + ' | sandbox chip=' + (sandboxCard
          ? JSON.stringify((sandboxCard.querySelector('.chip') || {}).textContent) : 'n/a')
        + ' | webhookHint=' + JSON.stringify((document.getElementById('webhookHint') || {}).textContent));
      /* the payment-reminder card must render as well (switch, offsets, transport) */
      const remCard = Array.prototype.slice.call(document.querySelectorAll('#gatewayForm .card'))
        .filter(c => /Payment reminders/.test(c.textContent))[0];
      const remSwitch = document.querySelector('#gatewayForm select[name="reminders_enabled"]');
      const remDays = document.querySelector('#gatewayForm input[name="reminder_days"]');
      const remHint = (document.getElementById('remindersHint') || {}).textContent || '';
      const remRows = document.querySelectorAll('#remindersTable tbody tr').length;
      log('STEP4b reminders UI: card=' + !!remCard + ' switch=' + !!remSwitch
        + ' offsets=' + (remDays ? remDays.value : 'missing')
        + ' tableRows=' + remRows + ' hint=' + JSON.stringify(remHint.slice(0, 90)));
      if (!swSandbox || !swStripe || !remCard || !remSwitch || !remDays) {
        log('RESULT FAILED: the per-gateway on/off switches or the reminder card are missing');
        log('HARNESS DONE'); return;
      }

      /* 4. switch the sandbox integration OFF and save the form */
      const before = await API.get('/api/plans', { silent: true });
      const beforeRow = (before.gateways || []).find(g => g.code === 'sandbox') || {};
      log('STEP5 API before: enabled=' + beforeRow.enabled + ' active=' + beforeRow.active);
      swSandbox.value = '0';
      document.getElementById('btnSaveGateways').click();
      let saved = false;
      for (let i = 0; i < 80; i++) {
        await wait(200);
        if (/Saved/i.test(toasts())) { saved = true; break; }
      }
      const off = await API.get('/api/plans', { silent: true });
      const offRow = (off.gateways || []).find(g => g.code === 'sandbox') || {};
      log('STEP6 saved=' + saved + ' | API after save: enabled=' + offRow.enabled
        + ' active=' + offRow.active + ' | toasts=' + JSON.stringify(toasts()));

      /* 5. the console must show it as switched off (and clients must lose it) */
      for (let i = 0; i < 40; i++) { await wait(150); if (gwSwitch() && gwSwitch().value === '0') break; }
      const chipNow = (gwSwitch() && gwSwitch().closest('.card'))
        ? (gwSwitch().closest('.card').querySelector('.chip') || {}).textContent : '(no card)';
      log('STEP7 console shows switch=' + (gwSwitch() ? gwSwitch().value : 'missing')
        + ' chip=' + JSON.stringify(chipNow));

      /* 6. switch it back on and verify the offer returns */
      gwSwitch().value = '1';
      document.getElementById('btnSaveGateways').click();
      let saved2 = false;
      for (let i = 0; i < 80; i++) {
        await wait(200);
        if (/Saved/i.test(toasts())) { saved2 = true; break; }
      }
      const back = await API.get('/api/plans', { silent: true });
      const backRow = (back.gateways || []).find(g => g.code === 'sandbox') || {};
      log('STEP8 re-enabled: saved=' + saved2 + ' enabled=' + backRow.enabled
        + ' active=' + backRow.active);

      const ok = beforeRow.active === true
        && offRow.enabled === false && offRow.active === false
        && /switched off/i.test(chipNow || '')
        && backRow.enabled === true && backRow.active === true
        && remCard && remSwitch && remDays && remHint.length > 0;
      log('STEP8b gateway switch: ' + (ok ? 'PASS' : 'FAILED')
        + ' — the super admin can deactivate and reactivate a payment integration');
      log('TOASTS: ' + (toasts() || '(none)'));

      /* 7. the Financial reports tab: four reports + CSV buttons + alerts */
      const repTab = document.querySelector('#superTabs .tab[data-pane="paneReports"]');
      log('STEP9 reports tab present: ' + !!repTab);
      if (repTab) repTab.click();
      for (let i = 0; i < 100; i++) {
        await wait(200);
        if (document.querySelectorAll('#finFinancial table tbody tr').length) break;
      }
      const finRows = document.querySelectorAll('#finFinancial table tbody tr').length;
      const invRows = document.querySelectorAll('#finInvoicing table tbody tr').length;
      const perfRows = document.querySelectorAll('#finPerformance table tbody tr').length;
      const alertBox = document.getElementById('finAlerts');
      const alertTxt = alertBox ? (alertBox.textContent || '').trim() : '';
      const csvBtns = document.querySelectorAll('#paneReports button[data-csv]');
      /* KPI tiles must be colour-coded (one class per metric, cycling the palette) */
      const tileKeys = sel => Array.prototype.slice
        .call(document.querySelectorAll(sel + ' .tile'))
        .map(t => (t.className.match(/k-[a-z]+/) || ['none'])[0]);
      const billColors = tileKeys('#billingTotals');
      const finColors = tileKeys('#finTotals');
      const distinct = a => Array.from(new Set(a)).length;
      log('STEP10 reports rendered: financial=' + finRows + ' invoicing=' + invRows
        + ' performance=' + perfRows + ' alertText=' + JSON.stringify(alertTxt.slice(0, 70))
        + ' csvButtons=' + csvBtns.length);
      log('STEP10b tile colours: billing=' + JSON.stringify(billColors) + ' (' + distinct(billColors)
        + ' distinct) finance=' + JSON.stringify(finColors) + ' (' + distinct(finColors) + ' distinct)');

      /* the CSV export must really download (authenticated blob) */
      let csvText = '';
      try {
        const r = await fetch('/api/finance/performance?format=csv',
          { headers: { 'X-Auth-Token': API.token() } });
        csvText = await r.text();
      } catch (e) { csvText = 'ERR ' + e.message; }
      log('STEP11 csv download: ' + JSON.stringify(csvText.slice(0, 60)));
      const csvOk = /utilisation_pct/.test(csvText);

      /* 8. the System tab must list the supported devices */
      const sysTab = document.querySelector('#superTabs .tab[data-pane="paneSystem"]');
      if (sysTab) sysTab.click();
      for (let i = 0; i < 60; i++) {
        await wait(200);
        if (document.querySelectorAll('#deviceCatalogue table tbody tr').length) break;
      }
      const devRows = document.querySelectorAll('#deviceCatalogue table tbody tr').length;
      const devHint = (document.getElementById('deviceCatalogueHint') || {}).textContent || '';
      log('STEP12 supported devices listed: rows=' + devRows + ' hint=' + JSON.stringify(devHint.slice(0, 80)));

      /* 9. the Settings tab must offer the platform logo (upload + preview) */
      const setTab = document.querySelector('#superTabs .tab[data-pane="paneSettings"]');
      if (setTab) {
        setTab.click();
        for (let i = 0; i < 60; i++) {
          await wait(150);
          if (document.querySelectorAll('#settingsForm .field').length) break;
        }
      }
      const logoInput = document.getElementById('logoFile');
      const logoPrev = document.getElementById('logoPreview');
      const logoBtn = document.getElementById('btnLogoUpload');
      const logoRemove = document.getElementById('btnLogoRemove');
      const logoHint = (document.getElementById('logoHint') || {}).textContent || '';
      log('STEP13 logo settings: fileInput=' + !!logoInput + ' preview=' + !!logoPrev
        + ' uploadBtn=' + !!logoBtn + ' removeBtn=' + !!logoRemove
        + ' accept=' + JSON.stringify(logoInput ? logoInput.accept.slice(0, 40) : '')
        + ' hint=' + JSON.stringify(logoHint.slice(0, 70)));

      log((finRows > 0 && invRows >= 0 && perfRows > 0 && csvOk && devRows >= 3
        && distinct(billColors) >= 3 && distinct(finColors) >= 3 && !!appLink
        && !!logoInput && !!logoBtn && !!logoRemove && !!logoPrev
        ? 'RESULT PASS' : 'RESULT FAILED')
        + ' — reports pane, alerts, CSV export, the device catalogue, the colour-coded KPI'
        + ' tiles, the Android download link and the logo settings all work');
      log('HARNESS DONE');
    } catch (e) {
      log('HARNESS EXCEPTION: ' + (e && e.message ? e.message : e));
      log('HARNESS DONE');
    }
  })();
});
