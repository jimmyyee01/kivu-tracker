/* =====================================================================
   KIVU Tracker — public/js/login.js
   Sign-in page: stores the session token, shows the demo accounts and
   sends the user to the console.
   ===================================================================== */
'use strict';

(function () {
  const DEMO = [
    { label: 'Super admin (all accounts)', username: 'superadmin', password: 'SuperAdmin@123' },
    { label: 'Account admin — Acme Logistics', username: 'admin', password: 'Admin@123' },
    { label: 'Operator — Acme Logistics', username: 'operator', password: 'Operator@123' },
    { label: 'Viewer — 2 units only', username: 'viewer', password: 'Viewer@123' },
    { label: 'Second account — Safari Tours', username: 'safari', password: 'Admin@123' }
  ];

  if (API.hasToken()) {
    /* already signed in — verify the token once before skipping the form */
    API.get('/api/auth/me', { silent: true }).then(() => { location.href = '/app'; })
      .catch(() => { API.setToken(''); });
  }

  const form = $('#loginForm');
  const errorBox = $('#error');
  const btn = $('#submitBtn');

  const demoBox = $('#demoLogins');
  DEMO.forEach(d => {
    const b = el('button', { class: 'btn sm', type: 'button' });
    b.appendChild(el('span', null, h(d.label)));
    b.appendChild(el('span', { class: 'mono dim' }, h(d.username + ' / ' + d.password)));
    b.addEventListener('click', () => {
      $('#username').value = d.username;
      $('#password').value = d.password;
      form.requestSubmit();
    });
    demoBox.appendChild(b);
  });

  const params = new URLSearchParams(location.search);
  if (params.get('expired')) errorBox.textContent = 'Your session expired — please sign in again.';

  /* version + IPS port from the public health endpoint */
  API.get('/api/health', { silent: true }).then(info => {
    if (info && info.app) {
      $('#title').textContent = info.app;
      document.title = 'Sign in — ' + info.app;
      $('#subtitle').textContent = 'GPS tracking & fleet management console v' + info.version;
      brandingRemember(info.logo || '');
      applyBranding();
      $('#ipsPort').textContent = info.ips_port;
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
