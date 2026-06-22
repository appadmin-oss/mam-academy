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

  // ── Shared portal-kit UI builders (P1) ──
  // One canonical implementation of the panel / KPI / empty / skeleton markup
  // both SPAs (and new views like My Learning) build on, so components stop
  // being re-implemented per portal. Markup matches the existing .panel/.kpi/
  // .empty-state CSS, so they're drop-in.
  function uiKpi(label, value, sub, cls) {
    return '<div class="kpi"><div class="kpi-label">' + escapeHtml(label) + '</div>'
      + '<div class="kpi-value ' + (cls || '') + '">' + value + '</div>'
      + '<div class="kpi-sub">' + escapeHtml(sub || '') + '</div></div>';
  }
  function uiEmpty(icon, msg) {
    return '<div class="empty-state"><i class="fa-solid fa-' + (icon || 'inbox') + '" aria-hidden="true"></i>' + escapeHtml(msg || 'Nothing here yet.') + '</div>';
  }
  function uiPanel(title, bodyHtml, actionHtml) {
    return '<section class="panel"><div class="panel-head"><h2>' + escapeHtml(title) + '</h2>' + (actionHtml || '') + '</div>' + (bodyHtml || '') + '</section>';
  }
  function uiSkeleton(rows) {
    rows = rows || 3; var s = '';
    for (var i = 0; i < rows; i++) s += '<div style="height:14px;border-radius:7px;background:rgba(255,255,255,.08);margin:12px 0;width:' + (100 - i * 12) + '%"></div>';
    return '<div class="panel-body" aria-busy="true" aria-label="Loading">' + s + '</div>';
  }

  window.MAM_PORTAL = {
    fmtDate: fmtDate, fmtDateTime: fmtDateTime, fmtMoney: fmtMoney,
    initials: initials, escapeHtml: escapeHtml,
    kpi: uiKpi, empty: uiEmpty, panel: uiPanel, skeleton: uiSkeleton,

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

    /** Notification centre (L3): a bell + dropdown mounted into [data-notif-bell].
        Self-contained (injects its own styles once), owner-scoped, and entirely
        best-effort — a failed fetch never disturbs the portal. Polls lightly. */
    notifications: function () {
      var host = $('[data-notif-bell]');
      if (!host || !window.MAM_API) return;
      if (!document.getElementById('mam-notif-style')) {
        var st = document.createElement('style');
        st.id = 'mam-notif-style';
        st.textContent = [
          '.mam-notif{position:relative;display:inline-block}',
          '.mam-notif-btn{position:relative;background:transparent;border:1px solid var(--border,rgba(255,255,255,.16));color:inherit;border-radius:10px;width:42px;height:42px;cursor:pointer;font-size:1rem}',
          '.mam-notif-badge{position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#E11D48;color:#fff;font-size:.66rem;line-height:18px;text-align:center;font-weight:700;display:none}',
          '.mam-notif-panel{position:absolute;right:0;top:50px;width:344px;max-width:86vw;max-height:62vh;overflow:auto;background:var(--card,#1A120B);border:1px solid var(--border,rgba(255,255,255,.16));border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.5);z-index:60;display:none}',
          '.mam-notif-panel.open{display:block}',
          '.mam-notif-head{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--border,rgba(255,255,255,.12));font-weight:700;font-size:.9rem}',
          '.mam-notif-head button{background:transparent;border:0;color:var(--gold,#FBBF24);cursor:pointer;font-size:.76rem}',
          '.mam-notif-item{display:block;padding:11px 14px;border-bottom:1px solid var(--border,rgba(255,255,255,.07));color:inherit;text-decoration:none;cursor:pointer}',
          '.mam-notif-item:hover{background:rgba(255,255,255,.05)}',
          '.mam-notif-item.unread{background:rgba(251,191,36,.08)}',
          '.mam-notif-title{font-weight:600;font-size:.85rem;margin-bottom:2px}',
          '.mam-notif-body{font-size:.77rem;opacity:.75}',
          '.mam-notif-time{font-size:.67rem;opacity:.5;margin-top:3px}',
          '.mam-notif-empty{padding:22px 14px;text-align:center;opacity:.6;font-size:.82rem}'
        ].join('');
        document.head.appendChild(st);
      }
      host.classList.add('mam-notif');
      host.innerHTML = '<button class="mam-notif-btn" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false"><i class="fa-solid fa-bell" aria-hidden="true"></i><span class="mam-notif-badge" aria-hidden="true">0</span></button>'
        + '<div class="mam-notif-panel" role="dialog" aria-label="Notifications"><div class="mam-notif-head"><span>Notifications</span><button type="button" data-notif-clear>Mark all read</button></div><div data-notif-list><div class="mam-notif-empty">Loading…</div></div></div>';
      var btn = $('.mam-notif-btn', host), badge = $('.mam-notif-badge', host),
          panel = $('.mam-notif-panel', host), listEl = $('[data-notif-list]', host);
      function setBadge(u) { u = u || 0; badge.style.display = u > 0 ? 'block' : 'none'; badge.textContent = u > 99 ? '99+' : String(u); }
      // Only same-origin paths or http(s) are navigable — never javascript:/data: etc.
      function safeUrl(u) { u = String(u || ''); return /^(https?:\/\/|\/(?!\/))/i.test(u) ? u : '#'; }
      function render(data) {
        var items = (data && data.notifications) || [];
        setBadge(data && data.unread);
        if (!items.length) { listEl.innerHTML = '<div class="mam-notif-empty">You’re all caught up.</div>'; return; }
        listEl.innerHTML = items.map(function (n) {
          return '<a class="mam-notif-item' + (n.read_at ? '' : ' unread') + '" data-id="' + n.id + '" href="' + escapeHtml(safeUrl(n.url)) + '">'
            + '<div class="mam-notif-title">' + escapeHtml(n.title) + '</div>'
            + (n.body ? '<div class="mam-notif-body">' + escapeHtml(n.body) + '</div>' : '')
            + '<div class="mam-notif-time">' + fmtDateTime(n.created_at) + '</div></a>';
        }).join('');
      }
      function load() { window.MAM_API.request('notifications', { method: 'GET' }).then(render).catch(function () {}); }
      on(btn, 'click', function (e) {
        e.stopPropagation();
        var open = panel.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) load();
      });
      on(document, 'click', function (e) { if (!host.contains(e.target)) { panel.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); } });
      on(listEl, 'click', function (e) {
        var a = e.target.closest ? e.target.closest('[data-id]') : null;
        if (!a) return;
        e.preventDefault();
        var id = Number(a.getAttribute('data-id')), href = a.getAttribute('href');
        // Record the read FIRST, then navigate, so the count is correct on return.
        window.MAM_API.request('notifications/read', { method: 'POST', body: { id: id } })
          .catch(function () {})
          .then(function () { var safe = safeUrl(href); if (safe !== '#') window.location.href = safe; else load(); });
      });
      on($('[data-notif-clear]', host), 'click', function (e) {
        e.preventDefault(); e.stopPropagation();
        window.MAM_API.request('notifications/read', { method: 'POST', body: { all: true } }).catch(function () {}).then(load);
      });
      load();
      setInterval(load, 60000);
    },

    /** Active-session management (P5): renders into [data-sessions] with revoke
        controls. Call after the host element is in the DOM (e.g. profile render).
        Self-contained + best-effort. */
    mountSessions: function () {
      var host = document.querySelector('[data-sessions]');
      if (!host || !window.MAM_API) return;
      function render(sessions) {
        sessions = sessions || [];
        if (!sessions.length) { host.innerHTML = '<p class="empty-state">No active sessions.</p>'; return; }
        var rows = sessions.map(function (s) {
          return '<tr><td>' + escapeHtml(s.ua || 'Unknown device') + (s.current ? ' <span class="pill">this device</span>' : '') + '</td>'
            + '<td>' + escapeHtml(s.ip || '') + '</td><td>' + escapeHtml((s.created_at || '').slice(0, 16)) + '</td>'
            + '<td>' + (s.current ? '<span class="muted">—</span>' : '<button class="btn btn-ghost" type="button" data-revoke="' + escapeHtml(s.id) + '">Revoke</button>') + '</td></tr>';
        }).join('');
        host.innerHTML = '<table class="ptable"><thead><tr><th>Device</th><th>IP</th><th>Signed in</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>'
          + (sessions.length > 1 ? '<div style="margin-top:12px"><button class="btn btn-ghost" type="button" data-revoke-others>Sign out everywhere else</button></div>' : '');
      }
      function load() { window.MAM_API.request('auth/sessions', { method: 'GET' }).then(function (r) { render(r.sessions); }).catch(function () {}); }
      on(host, 'click', function (e) {
        var rv = e.target.closest ? e.target.closest('[data-revoke]') : null;
        var ro = e.target.closest ? e.target.closest('[data-revoke-others]') : null;
        if (rv) { window.MAM_API.request('auth/revoke-session', { method: 'POST', body: { id: rv.getAttribute('data-revoke') } }).then(function (r) { render(r.sessions); }).catch(function () {}); }
        else if (ro) { window.MAM_API.request('auth/revoke-others', { method: 'POST', body: {} }).then(function (r) { render(r.sessions); }).catch(function () {}); }
      });
      load();
    },

    /** Patch the existing main.js API helper to add credentials and base path */
    init: function () {
      // window.MAM_API already adds same-origin credentials + auto CSRF.
      // Wire up logout / sidebar / footer year on every portal page.
      $$('[data-action=logout]').forEach(function (b) { on(b, 'click', function (e) { e.preventDefault(); window.MAM_PORTAL.logout(); }); });
      window.MAM_PORTAL.sidebarToggle();
      window.MAM_PORTAL.notifications();
      $$('[data-year]').forEach(function (e) { e.textContent = new Date().getFullYear(); });
    },
  };
})();
