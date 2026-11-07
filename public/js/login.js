/* =====================================================================
   KIVU Tracker — public/js/login.js
   Sign-in page: stores the session token and sends the user to the console.
   ===================================================================== */
'use strict';

(function () {
  if (API.hasToken()) {
    /* already signed in — verify the token once before skipping the form */
    API.get('/api/auth/me', { silent: true }).then(() => { location.href = '/app'; })
      .catch(() => { API.setToken(''); });
  }

  const form = $('#loginForm');
  const errorBox = $('#error');
  const btn = $('#submitBtn');

  const params = new URLSearchParams(location.search);
  if (params.get('expired')) errorBox.textContent = 'Your session expired — please sign in again.';

  /* version + branding from the public health endpoint */
  API.get('/api/health', { silent: true }).then(info => {
    if (info && info.app) {
      $('#title').textContent = info.app;
      document.title = 'Sign in — ' + info.app;
      $('#subtitle').textContent = 'GPS tracking & fleet management console v' + info.version;
      brandingRemember(info.logo || '');
      setPoweredBy(info.powered_by || '');
      applyBranding();
    }
  }).catch(() => {});

  form.addEventListener('submit', async e => {
    e.preventDefault();
    errorBox.textContent = '';
    btn.disabled = true;
    btn.textContent = 'Signing in…';
    try {
      const res = await API.login($('#username').value.trim(), $('#password').value);
      API.setToken(res.token);
      location.href = '/app';
    } catch (err) {
      errorBox.textContent = err.message;
      btn.disabled = false;
      btn.textContent = 'Sign in';
    }
  });
})();
