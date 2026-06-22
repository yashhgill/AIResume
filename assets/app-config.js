/**
 * Shared frontend config + Google Sign-In helper.
 *
 * GOOGLE_CLIENT_ID below must match GOOGLE_CLIENT_ID in api/config.local.php
 * (server only needs it to check the token "aud" field — it's not a secret,
 * Google Client IDs are meant to be public/visible in frontend code).
 * Get one at https://console.cloud.google.com/apis/credentials
 */
window.APP_CONFIG = {
  GOOGLE_CLIENT_ID: '450907493101-2d0rb0r73mmircpt12fqqpj25ra4effn.apps.googleusercontent.com',

  // Only used when the frontend is deployed on a DIFFERENT domain than the
  // backend (e.g. frontend on Cloudflare Pages, backend on its own host).
  // Leave empty for local dev — same-origin relative paths are used instead.
  // Example: 'https://your-backend-host.com/resume_generator/api'
  PROD_API_BASE: '',
};

window.AR_resolveApiBase = function () {
  var host = window.location.hostname;
  var isLocal = host === 'localhost' || host === '127.0.0.1' || host === '';

  // Cross-domain production deployment (frontend and backend on different hosts)
  if (!isLocal && window.APP_CONFIG.PROD_API_BASE) {
    return window.APP_CONFIG.PROD_API_BASE;
  }

  // Opened directly as a file (no server at all) — best-effort fallback
  if (window.location.protocol === 'file:') {
    return 'http://localhost:8080/resume_generator/api';
  }

  // Same-origin (local dev with `php -S`, or single-host production):
  // works regardless of which port is in use.
  return '/resume_generator/api';
};

/**
 * Renders the "Sign in with Google" button into the given container element
 * (or id string), and redirects to `redirectTo` on success.
 */
window.AR_renderGoogleButton = function (containerIdOrEl, redirectTo) {
  var container = typeof containerIdOrEl === 'string'
    ? document.getElementById(containerIdOrEl)
    : containerIdOrEl;
  if (!container) return;

  if (!window.APP_CONFIG.GOOGLE_CLIENT_ID || window.APP_CONFIG.GOOGLE_CLIENT_ID.indexOf('REPLACE_WITH') === 0) {
    container.innerHTML = '<div class="text-muted small text-center">Google Sign-In not configured yet — set GOOGLE_CLIENT_ID in assets/app-config.js and api/config.local.php.</div>';
    return;
  }

  function handleCredentialResponse(response) {
    var apiBase = window.AR_resolveApiBase();
    fetch(apiBase + '/google_auth.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ credential: response.credential }),
    })
      .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
      .then(function (r) {
        if (r.ok && r.j.token) {
          localStorage.setItem('rg_token', r.j.token);
          window.location.href = redirectTo || 'index.html';
        } else {
          alert('Google sign-in failed: ' + (r.j.error || 'Unknown error'));
        }
      })
      .catch(function (err) {
        console.error(err);
        alert('Network error during Google sign-in.');
      });
  }
  window.AR_handleGoogleCredential = handleCredentialResponse;

  function renderButton() {
    if (typeof google === 'undefined' || !google.accounts) {
      // GIS script not loaded yet — try again shortly.
      setTimeout(renderButton, 200);
      return;
    }
    google.accounts.id.initialize({
      client_id: window.APP_CONFIG.GOOGLE_CLIENT_ID,
      callback: handleCredentialResponse,
    });
    google.accounts.id.renderButton(container, {
      theme: 'outline',
      size: 'large',
      width: 320,
      shape: 'pill',
    });
  }
  renderButton();
};
