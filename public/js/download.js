/* =====================================================================
   KIVU Tracker — /download  (public)
   Renders the Android app info from /api/app (version, size, checksum,
   install steps) and wires the download / copy / e-mail actions.
   ===================================================================== */
'use strict';

document.addEventListener('DOMContentLoaded', async function () {
  const topbar = $('#topbar');
  /* signed-in visitors keep their normal menu (which now has "Get the app") */
  if (API.hasToken() && typeof renderTopBar === 'function') renderTopBar('download');
  else if (topbar) topbar.remove();
  let data;
  try {
    data = await API.get('/api/app', { silent: true });
  } catch (e) {
    $('#appAvailability').className = 'chip danger';
    $('#appAvailability').textContent = 'app info unavailable';
    $('#downloadNote').textContent = 'The server did not answer /api/app: ' + e.message;
    return;
  }
  renderApp(data.app || {});
  brandingRemember((data.app || {}).logo || '');
  setPoweredBy((data.app || {}).powered_by || '');
  applyBranding();
});

function renderApp(app) {
  const abs = function (u) { return new URL(u || '/download/kivu-tracker.apk', location.origin).href; };
  const downloadUrl = abs(app.url);

  $('#appTitle').textContent = app.name || 'KIVU Tracker for Android';
  $('#appVersionChip').textContent = 'v' + (app.version || '1.0.0');
  const av = $('#appAvailability');
  av.className = 'chip ' + (app.available ? 'ok' : 'warn');
  av.textContent = app.available ? 'ready to download' : 'not published yet';

  /* colour-coded facts about the package */
  tiles($('#appStats'), [
    { label: 'Version', value: app.version || '1.0.0', color: 'blue' },
    { label: 'Size', value: app.size || (app.available ? 'available' : '—'),
      color: 'violet' },
    { label: 'Needs Android', value: app.min_android || '6.0 (API 23)', color: 'cyan' },
    { label: 'Package', value: app.package || 'com.kivutracker.app', color: 'teal' },
    { label: 'Your server', value: app.server_url || location.origin, color: 'green' },
    { label: 'Updated', value: app.updated_at ? fmt.dt(app.updated_at) : '—', color: 'amber' }
  ]);

  $('#appHash').textContent = app.sha256
    ? 'SHA-256 ' + app.sha256 + ' — compare it after downloading.'
    : 'The checksum appears here once the package is published on this server.';
  $('#appWhatsNew').textContent = app.whats_new
    ? "What's new: " + app.whats_new
    : (app.available ? '' : 'The administrator has not published the package yet — the app is built '
      + 'from android/ and copied into public/download/ (see android/README.md).');
  $('#trackerLine').textContent = 'Create the unit in Administration → Units, then post to '
    + (app.ingest_url || (location.origin + '/api/ingest')) + ' from the app.';

  const btn = $('#btnDownload');
  btn.disabled = !app.available;
  if (!app.available) btn.textContent = 'APK not published yet';
  btn.addEventListener('click', () => { location.href = downloadUrl; });

  $('#btnCopyLink').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(downloadUrl);
      toast('Download link copied', 'ok');
    } catch (e) {
      toast('Copy failed — the link is: ' + downloadUrl, 'warn', 8000);
    }
  });
  $('#btnSendMail').href = 'mailto:?subject=' + encodeURIComponent('KIVU Tracker for Android')
    + '&body=' + encodeURIComponent('Open this on the phone to install the app:\n' + downloadUrl + '\n');
  $('#downloadNote').textContent = app.available
    ? 'Served by this server' + (app.hosted ? ' (hosted elsewhere)' : '') + '.'
    : 'Waiting for public/download/kivu-tracker.apk.';

  const steps = $('#installSteps');
  (app.install_steps || []).forEach((s, i) => {
    steps.appendChild(el('li', { value: String(i + 1) }, h(s)));
  });
  const perms = $('#appPermissions');
  (app.permissions || []).forEach(p => perms.appendChild(el('li', null, h(p))));
  const feats = $('#appFeatures');
  (app.features || []).forEach(f => feats.appendChild(el('li', null, h(f))));
}
