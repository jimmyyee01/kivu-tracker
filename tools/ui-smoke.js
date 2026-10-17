/* =====================================================================
   KIVU Tracker — tools/ui-smoke.js
   Driver for tools/ui-smoke.html. Loads the real admin console, clicks
   "+ New unit", fills the dialog (leaving the device password blank on
   purpose) and reports what happened in #harness-out.
   Development aid only — see the header of ui-smoke.html.
   ===================================================================== */
'use strict';

document.addEventListener('DOMContentLoaded', function () {
  const out = document.getElementById('harness-out');
  const log = m => { out.textContent += m + '\n'; };
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const toasts = () => Array.prototype.slice.call(document.querySelectorAll('.toast'))
    .map(t => t.textContent).join(' || ');

  (async function run() {
    try {
      /* 1. wait for the console to boot (metadata + unit table) */
      let rows0 = 0;
      for (let i = 0; i < 100; i++) {
        await wait(150);
        rows0 = document.querySelectorAll('#unitsTable tbody tr').length;
        if (rows0) break;
      }
      const picker = document.getElementById('accountPicker');
      log('STEP1 boot: role=' + (State.user && State.user.role)
        + ' rows=' + rows0
        + ' accountContext=' + (State.accountId || '(none)')
        + ' picker=' + (picker ? picker.value : 'absent'));
      if (!rows0) { log('RESULT FAILED: the unit table never rendered'); log('HARNESS DONE'); return; }

      /* 2. click "+ New unit" like a user */
      document.getElementById('btnNewUnit').click();
      await wait(400);
      const dlg = document.querySelector('.modal-back');
      log('STEP2 dialog open: ' + !!dlg);
      if (!dlg) { log('RESULT FAILED: the New unit dialog did not open'); log('HARNESS DONE'); return; }
      const box = dlg.querySelector('.modal-body');
      log('STEP3 dialog fields: ' + box.querySelectorAll('input,select,textarea').length
        + ' | name=' + !!box.querySelector('input[name="name"]')
        + ' unique_id=' + !!box.querySelector('input[name="unique_id"]')
        + ' passwordPrefilled=' + JSON.stringify((box.querySelector('input[name="device_password"]') || {}).value));

      /* 3. fill it in; the device password is cleared on purpose (the bug case) */
      const uid = 'HARNESS-' + Date.now().toString().slice(-6);
      box.querySelector('input[name="unique_id"]').value = uid;
      box.querySelector('input[name="name"]').value = 'Harness device ' + uid;
      const pw = box.querySelector('input[name="device_password"]');
      if (pw) pw.value = '';
      log('STEP4 saving unique_id=' + uid + ' password=' + JSON.stringify(pw ? pw.value : null));
      dlg.querySelector('.modal-foot button.primary').click();

      /* 4. wait for the dialog to close and the table to grow */
      let closed = false;
      for (let i = 0; i < 80; i++) {
        await wait(200);
        if (!document.querySelector('.modal-back')) { closed = true; break; }
      }
      log('STEP5 dialog closed: ' + closed);
      for (let i = 0; i < 80; i++) {
        await wait(200);
        if (document.querySelectorAll('#unitsTable tbody tr').length > rows0) break;
        if (/error|required|cannot be null/i.test(toasts())) break;
      }
      const rows1 = document.querySelectorAll('#unitsTable tbody tr').length;
      log('STEP6 rows ' + rows0 + ' -> ' + rows1);
      log('TOASTS: ' + (toasts() || '(none)'));

      /* 5. confirm through the API and clean up */
      const found = await API.get('/api/units?q=' + encodeURIComponent(uid), { silent: true });
      const unit = (found.units || [])[0];
      log('API lookup: ' + (unit ? unit.id + ' / ' + unit.name : 'not found'));
      if (unit) {
        const detail = await API.get('/api/units/' + encodeURIComponent(unit.id), { silent: true });
        log('API device password: ' + JSON.stringify(detail.unit.device_password)
          + ' | sensors=' + ((detail.sensors || []).length));
        await API.del('/api/units/' + encodeURIComponent(unit.id), { silent: true });
        log('CLEANUP: harness unit deleted');
      }
      log('RESULT ' + (rows1 > rows0 && unit
        ? 'PASS — a new device can be added from the UI'
        : 'FAILED — the device was not added'));
    } catch (e) {
      log('HARNESS EXCEPTION: ' + (e && e.message ? e.message : String(e)));
    }
    log('HARNESS DONE');
  })();
});
