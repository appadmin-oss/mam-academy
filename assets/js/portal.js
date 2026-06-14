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
          window.location.href = '/portal/login?next=' + encodeURIComponent(window.location.pathname);
          throw new Error('unauthenticated');
        }
        return r.user;
      });
    },

    logout: function () {
      return window.MAM_API.request('auth/logout', { method: 'POST' })
        .catch(function () {}) // ignore — we still redirect
        .then(function () { window.location.href = '/portal/login'; });
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

    /** Progressive a11y upgrade for the sidebar tabs: ARIA tablist semantics +
        keyboard navigation (arrow/Home/End to move focus, Enter/Space to
        activate), roving tabindex, and a panel labelled by the active tab.
        Purely additive — the existing click→show() path is untouched. Returns a
        sync(tab) the caller runs whenever the active tab changes, so ARIA stays
        correct for clicks, hash changes, and programmatic activation alike. */
    enhanceTabs: function (opts) {
      opts = opts || {};
      var list = opts.list || $('.portal-nav');
      var panel = opts.panel || $('#content');
      var onActivate = typeof opts.onActivate === 'function' ? opts.onActivate : function () {};
      if (!list) return function () {};
      var tabsEls = $$('[data-tab]', list);
      if (!tabsEls.length) return function () {};
      list.setAttribute('role', 'tablist');
      if (panel) { panel.setAttribute('role', 'tabpanel'); if (!panel.hasAttribute('tabindex')) panel.setAttribute('tabindex', '0'); }
      tabsEls.forEach(function (t) {
        t.setAttribute('role', 'tab');
        if (!t.id) t.id = 'ptab-' + t.getAttribute('data-tab');
        if (panel && panel.id) t.setAttribute('aria-controls', panel.id);
        var active = t.classList.contains('active');
        t.setAttribute('aria-selected', active ? 'true' : 'false');
        t.setAttribute('tabindex', active ? '0' : '-1');
      });
      function focusAt(i) { i = (i + tabsEls.length) % tabsEls.length; if (tabsEls[i]) tabsEls[i].focus(); }
      on(list, 'keydown', function (e) {
        var idx = tabsEls.indexOf(document.activeElement);
        if (idx < 0) return;
        switch (e.key) {
          case 'ArrowRight': case 'ArrowDown': e.preventDefault(); focusAt(idx + 1); break;
          case 'ArrowLeft':  case 'ArrowUp':   e.preventDefault(); focusAt(idx - 1); break;
          case 'Home': e.preventDefault(); focusAt(0); break;
          case 'End':  e.preventDefault(); focusAt(tabsEls.length - 1); break;
          case 'Enter': case ' ': e.preventDefault(); onActivate(tabsEls[idx].getAttribute('data-tab')); break;
        }
      });
      return function sync(active) {
        tabsEls.forEach(function (t) {
          var sel = t.getAttribute('data-tab') === active;
          t.setAttribute('aria-selected', sel ? 'true' : 'false');
          t.setAttribute('tabindex', sel ? '0' : '-1');
          if (sel && panel && t.id) panel.setAttribute('aria-labelledby', t.id);
        });
      };
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
