/* ============================================================
   MAM Academy — Portal shared JS
   ============================================================ */
(function () {
  'use strict';

  var $  = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var on = function (el, ev, fn) { if (el) el.addEventListener(ev, fn, false); };

  function fmtDate(s) {
    if (!s) return '—';
    var d = new Date(s.replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return s;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }
  function fmtDateTime(s) {
    if (!s) return '—';
    var d = new Date(s.replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return s;
    return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }
  function fmtMoney(amount, currency) {
    var cur = (currency || 'USD').toUpperCase();
    var sym = { USD: '$', NGN: '₦', GBP: '£', EUR: '€' }[cur] || cur + ' ';
    return sym + Number(amount || 0).toLocaleString('en-US');
  }
  function initials(name) {
    if (!name) return '?';
    return name.split(' ').filter(Boolean).slice(0, 2).map(function (p) { return p[0].toUpperCase(); }).join('');
  }
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  window.MAM_PORTAL = {
    fmtDate: fmtDate, fmtDateTime: fmtDateTime, fmtMoney: fmtMoney,
    initials: initials, escapeHtml: escapeHtml,

    requireAuth: function (allowedRoles) {
      return window.MAM_API.request('auth/me', { method: 'GET' }).then(function (r) {
        if (!r.authenticated || (allowedRoles && allowedRoles.indexOf(r.user.role) < 0)) {
          window.location.href = '/portal/login.html?next=' + encodeURIComponent(window.location.pathname);
          throw new Error('unauthenticated');
        }
        return r.user;
      });
    },

    logout: function () {
      return window.MAM_API.request('auth/logout', { method: 'POST' })
        .catch(function () {}) // ignore — we still redirect
        .then(function () { window.location.href = '/portal/login.html'; });
    },

    mountUserCard: function (user) {
      var card = $('#userCard'); if (!card) return;
      card.innerHTML = ''
        + '<div class="portal-avatar">' + (user.avatar_url ? '<img src="' + escapeHtml(user.avatar_url) + '" alt="">' : escapeHtml(initials(user.name || user.email))) + '</div>'
        + '<div><div class="portal-user-name">' + escapeHtml(user.name || user.email) + '</div>'
        + '<div class="portal-user-role">' + escapeHtml(user.role) + '</div></div>';
    },

    sidebarToggle: function () {
      var btn = $('#sidebarToggle');
      var side = $('.portal-side');
      if (!btn || !side) return;
      on(btn, 'click', function () { side.classList.toggle('open'); });
      on(document, 'click', function (e) {
        if (window.innerWidth > 880) return;
        if (!side.contains(e.target) && !btn.contains(e.target)) side.classList.remove('open');
      });
    },

    /** Patch the existing main.js API helper to add credentials and base path */
    init: function () {
      // window.MAM_API already adds same-origin credentials + auto CSRF.
      // Wire up logout / sidebar / footer year on every portal page.
      $$('[data-action=logout]').forEach(function (b) { on(b, 'click', function (e) { e.preventDefault(); window.MAM_PORTAL.logout(); }); });
      window.MAM_PORTAL.sidebarToggle();
      $$('[data-year]').forEach(function (e) { e.textContent = new Date().getFullYear(); });
    },
  };
})();
