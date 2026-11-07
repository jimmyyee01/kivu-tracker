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

  /* the left panel: the built-in artwork, or the picture/video a super admin
     picked (Platform settings → Login page media) */
  function applyLoginHero(hero) {
    const mount = $('#loginHero');
    const visual = document.querySelector('.auth-visual');
    if (!mount || !visual || !hero || !hero.url) return;
    /* phones drop the whole panel (max-width 860px), so never spend bandwidth
       downloading a video nobody can see */
    if (!visual.offsetWidth) return;
    /* `version` is the file's timestamp, so a replaced file is not served from
       the browser cache */
    const url = hero.url + (hero.version
      ? (hero.url.indexOf('?') >= 0 ? '&' : '?') + 'v=' + hero.version : '');
    mount.innerHTML = '';
    if (hero.type === 'video') {
      const video = document.createElement('video');
      ['muted', 'loop', 'autoplay', 'playsinline'].forEach(a => video.setAttribute(a, ''));
      video.muted = true;
      video.loop = true;
      video.autoplay = true;
      video.preload = 'auto';
      video.setAttribute('disablepictureinpicture', '');
      video.setAttribute('controlslist', 'nodownload nofullscreen noremoteplayback');
      video.src = url;
      /* Muted playback is always allowed, but a background tab or a phone in
         power saving can refuse the first attempt — retry a few times, and
         restart by hand in case a browser ignores `loop`. */
      let tries = 0;
      const kick = () => {
        if (!video.paused && !video.ended) return;
        const p = video.play();
        if (p && p.catch) p.catch(() => { if (tries++ < 6) setTimeout(kick, 700); });
      };
      video.addEventListener('loadeddata', kick);
      video.addEventListener('canplay', kick);
      video.addEventListener('stalled', kick);
      video.addEventListener('ended', () => { try { video.currentTime = 0; } catch (e) {} kick(); });
      document.addEventListener('visibilitychange', () => { if (!document.hidden) kick(); });
      mount.appendChild(video);
      kick();
    } else {
      const img = document.createElement('img');
      img.src = url;
      img.alt = '';
      img.decoding = 'async';
      mount.appendChild(img);
    }
    visual.classList.add('has-hero');
  }

  /* version + branding from the public health endpoint */
  API.get('/api/health', { silent: true }).then(info => {
    if (info && info.app) {
      $('#title').textContent = info.app;
      document.title = 'Sign in — ' + info.app;
      $('#subtitle').textContent = 'GPS tracking & fleet management console v' + info.version;
      brandingRemember(info.logo || '');
      setPoweredBy(info.powered_by || '');
      applyBranding();
      applyLoginHero(info.login_hero);
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
