/* =====================================================================
   KIVU Tracker — tools/ui-reseller.js
   Driver for tools/ui-reseller.html. Boots the real reseller console with
   a reseller session and verifies the portal card, the colour-coded KPI
   tiles, the client-account table and the API behind them.
   Development aid only — see the header of ui-reseller.html.
   ===================================================================== */
'use strict';

document.addEventListener('DOMContentLoaded', function () {
  const out = document.getElementById('harness-out');
  const log = m => { out.textContent += m + '\n'; };
  const wait = ms => new Promise(r => setTimeout(r, ms));
  window.addEventListener('error', e => log('WINDOW ERROR: ' + e.message + ' @ ' + e.filename + ':' + e.lineno));
  window.addEventListener('unhandledrejection', e => log('REJECTION: '
    + ((e.reason && e.reason.message) || e.reason)));

  (async function run() {
    try {
      /* 1. the console must boot (topbar + meta for a reseller role) */
      log('STEP0 token in this context: ' + (API.hasToken() ? 'yes' : 'no'));
      try {
        const m = await API.get('/api/meta', { silent: true });
        log('STEP0b direct meta: role=' + ((m.user || {}).role) + ' reseller=' + m.reseller
          + ' units=' + (m.units || []).length + ' theme=' + ((m.settings || {}).theme_mode));
      } catch (e) { log('STEP0b direct meta FAILED: ' + e.message); }
      try {
        const m2 = await boot('reseller');
        log('STEP0c manual boot: meta=' + !!m2 + ' userRole=' + ((State.user || {}).role || 'none'));
      } catch (e) { log('STEP0c manual boot THREW: ' + e.message); }
      let booted = false;
      for (let i = 0; i < 120; i++) {
        await wait(150);
        if (State && State.user) { booted = true; break; }
      }
      log('STEP1 boot: role=' + ((State && State.user) ? State.user.role : '(none)')
        + ' resellerMeta=' + !!((State && State.meta) ? State.meta.reseller : false)
        + ' units=' + ((State && State.units) ? State.units.length : '-'));
      if (!booted || !State.user || State.user.role !== 'reseller') {
        log('RESULT FAILED: the reseller console did not boot with a reseller session');
        log('HARNESS DONE'); return;
      }

      /* 2. the portal card + KPI tiles */
      let tiles = 0;
      for (let i = 0; i < 120; i++) {
        await wait(150);
        tiles = document.querySelectorAll('#portalStats .tile').length;
        if (tiles) break;
      }
      const keys = Array.prototype.slice.call(document.querySelectorAll('#portalStats .tile'))
        .map(t => (t.className.match(/k-[a-z]+/) || ['none'])[0]);
      const distinct = Array.from(new Set(keys)).length;
      log('STEP2 portal: title=' + JSON.stringify(($('#portalTitle') || {}).textContent)
        + ' chip=' + JSON.stringify(($('#portalChip') || {}).textContent));
      log('STEP3 tiles=' + tiles + ' colours=' + JSON.stringify(keys) + ' distinct=' + distinct);
      log('STEP4 note: ' + JSON.stringify(($('#portalNote') || {}).textContent || ''));

      /* 3. the client accounts table comes from /api/reseller/state */
      let rows = 0;
      for (let i = 0; i < 60; i++) {
        await wait(150);
        rows = document.querySelectorAll('#accountsTable tbody tr').length;
        if (rows) break;
      }
      const state = await API.get('/api/reseller/state', { silent: true });
      log('STEP5 accounts table rows=' + rows + ' chip='
        + JSON.stringify(($('#accountChip') || {}).textContent)
        + ' api accounts=' + ((state.accounts || []).length)
        + ' can_create=' + state.can_create
        + ' days_left=' + ((state.portal || {}).days_left));
      const header = Array.prototype.slice.call(document.querySelectorAll('#accountsTable thead th'))
        .map(th => th.textContent).join(' | ');
      log('STEP6 table columns: ' + header);

      const ok = tiles >= 5 && distinct >= 3
        && (state.portal || {}).status === 'active'
        && (state.portal || {}).days_left >= 300;
      log((ok ? 'RESULT PASS' : 'RESULT FAILED')
        + ' — the reseller portal renders its licence state, colour-coded KPIs and client accounts');
      log('HARNESS DONE');
    } catch (e) {
      log('HARNESS EXCEPTION: ' + (e && e.message ? e.message : String(e)));
      log('HARNESS DONE');
    }
  })();
});
