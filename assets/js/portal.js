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
  // Today's date as YYYY-MM-DD in the user's LOCAL timezone. toISOString() is UTC,
  // which rolls to "tomorrow" for evening users in positive-offset zones (e.g. WAT).
  function today() {
    var d = new Date();
    var m = String(d.getMonth() + 1).padStart(2, '0'), day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }
  function fmtDateTime(s) {
    if (!s) return '—';
    var d = new Date(s.replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return s;
    return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }
  // Literal formatter: the amount is ALREADY in `currency`. Used for factual
  // records such as a completed payment in its settlement currency — never
  // converts. MAM_CCY.symbol widens symbol coverage beyond the old 4-currency map.
  function fmtMoney(amount, currency) {
    var cur = (currency || 'USD').toUpperCase();
    var sym = (window.MAM_CCY ? window.MAM_CCY.symbol(cur) : ({ USD: '$', NGN: '₦', GBP: '£', EUR: '€' }[cur])) || cur + ' ';
    return sym + Number(amount || 0).toLocaleString('en-US');
  }
  // Display a USD accounting figure (a price, total or store-credit balance) in
  // the visitor's local currency — mirrors the public store/tuition pages. USD is
  // the canonical unit, so this is a display-only conversion at the live rate.
  function fmtUsd(usd) {
    return window.MAM_CCY ? window.MAM_CCY.format(usd, window.MAM_CCY.detect()) : fmtMoney(usd, 'USD');
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

  // Progressive disclosure for long tables. Given an array of <tr>…</tr> strings,
  // returns tbody-inner HTML that shows only the first `opts.initial` rows plus a
  // "Show all N" control; the remaining rows render collapsed (hidden) and reveal
  // in one click (delegated handler wired in init()). Keeps big rosters/attendance
  // lists fast to paint and easy to scan without a full pagination widget.
  // `colspan` must span the table's columns so the control row sits flush.
  var _pagerSeq = 0;
  function pagedTbody(rows, colspan, opts) {
    opts = opts || {};
    var initial = opts.initial || 12;
    rows = rows || [];
    if (rows.length <= initial) return rows.join('');
    var id = 'pg' + (_pagerSeq++);
    var head = rows.slice(0, initial).join('');
    var tail = rows.slice(initial).map(function (r) {
      return r.replace(/^(\s*)<tr/i, '$1<tr hidden data-pg="' + id + '"');
    }).join('');
    var ctl = '<tr class="pager-ctl" data-pg-ctl="' + id + '"><td colspan="' + (colspan || 3) + '" style="text-align:center;padding:14px">'
      + '<button type="button" class="btn btn-ghost" data-pg-more="' + id + '">Show all ' + rows.length + ' ' + escapeHtml(opts.noun || 'rows') + '</button></td></tr>';
    return head + ctl + tail;
  }

  window.MAM_PORTAL = {
    fmtDate: fmtDate, fmtDateTime: fmtDateTime, fmtMoney: fmtMoney, fmtUsd: fmtUsd, today: today,
    initials: initials, escapeHtml: escapeHtml,
    kpi: uiKpi, empty: uiEmpty, panel: uiPanel, skeleton: uiSkeleton, pagedTbody: pagedTbody,

    requireAuth: function (allowedRoles) {
      return window.MAM_API.request('auth/me', { method: 'GET' }).then(function (r) {
        // Check ALL of the user's roles, not just the primary one, so a user who
        // is e.g. both student + instructor isn't bounced from either portal.
        var held = (r.user && r.user.roles && r.user.roles.length) ? r.user.roles : (r.user ? [r.user.role] : []);
        var ok = !allowedRoles || held.some(function (role) { return allowedRoles.indexOf(role) >= 0; });
        if (!r.authenticated || !ok) {
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

    /** Mobile drawer for the portal sidebar: scrim + body scroll-lock + Escape
        + close-on-navigate + ARIA state. Desktop (>880px) is untouched. */
    sidebarToggle: function () {
      var btn = $('#sidebarToggle');
      var side = $('.portal-side');
      if (!btn || !side) return;
      // Scrim behind the open drawer (created once, shared).
      var scrim = document.createElement('div');
      scrim.className = 'portal-scrim';
      document.body.appendChild(scrim);
      if (!side.id) side.id = 'portalSide';
      btn.setAttribute('aria-controls', side.id);
      btn.setAttribute('aria-expanded', 'false');
      function setOpen(open) {
        side.classList.toggle('open', open);
        scrim.classList.toggle('show', open);
        btn.setAttribute('aria-expanded', String(open));
        document.body.style.overflow = open ? 'hidden' : '';
      }
      on(btn, 'click', function () { setOpen(!side.classList.contains('open')); });
      on(scrim, 'click', function () { setOpen(false); });
      on(document, 'keydown', function (e) { if (e.key === 'Escape' && side.classList.contains('open')) setOpen(false); });
      // Tapping a nav item switches the SPA tab behind the drawer — close it so
      // the user actually sees the tab they chose.
      on(side, 'click', function (e) {
        if (window.innerWidth > 880) return;
        if (e.target.closest && e.target.closest('a, button')) setOpen(false);
      });
      // Never strand an open drawer (and a locked body) across a rotate/resize
      // to the desktop layout, where the drawer becomes a static column.
      on(window, 'resize', function () {
        if (window.innerWidth > 880 && side.classList.contains('open')) setOpen(false);
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
      // Reveal collapsed rows for any pagedTbody() control (delegated, once).
      on(document, 'click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-pg-more]') : null;
        if (!b) return;
        var id = b.getAttribute('data-pg-more');
        $$('[data-pg="' + id + '"]').forEach(function (tr) { tr.hidden = false; });
        var ctl = document.querySelector('[data-pg-ctl="' + id + '"]');
        if (ctl && ctl.parentNode) ctl.parentNode.removeChild(ctl);
      });
      window.MAM_PORTAL.sidebarToggle();
      window.MAM_PORTAL.notifications();
      $$('[data-year]').forEach(function (e) { e.textContent = new Date().getFullYear(); });
    },
  };
})();

/* ============================================================
   Portal theme toggle — light ⇄ dark, persisted.
   The pre-paint <head> script sets the initial data-portal-theme on
   <html>; this wires the sidebar control and keeps its label/icon in
   sync. Self-contained and delegated, so it works on every portal shell
   regardless of that page's boot sequence.
   ============================================================ */
(function () {
  'use strict';
  var root = document.documentElement;
  function current() { return root.getAttribute('data-portal-theme') === 'dark' ? 'dark' : 'light'; }
  function sync() {
    var dark = current() === 'dark';
    var btns = document.querySelectorAll('[data-action="theme-toggle"]');
    for (var i = 0; i < btns.length; i++) {
      var b = btns[i];
      var lbl = b.querySelector('[data-theme-label]');
      var ic = b.querySelector('i');
      if (lbl) { lbl.textContent = dark ? 'Light mode' : 'Dark mode'; }
      if (ic) { ic.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon'; }
      b.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
  }
  function apply(mode) {
    root.setAttribute('data-portal-theme', mode);
    try { localStorage.setItem('mam-portal-theme', mode); } catch (e) {}
    sync();
  }
  document.addEventListener('click', function (e) {
    var b = (e.target && e.target.closest) ? e.target.closest('[data-action="theme-toggle"]') : null;
    if (!b) { return; }
    e.preventDefault();
    apply(current() === 'dark' ? 'light' : 'dark');
  }, false);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sync, false);
  } else {
    sync();
  }
})();

/* ============================================================
   Display & access settings (Advanced Modernization §7.2, §7.4).
   Text size, dyslexia-friendly font, and a low-bandwidth mode that
   reduces motion and hides non-essential imagery. Persisted to
   localStorage; the pre-paint <head> script applies the classes on
   load so there is no flash. Self-contained + delegated.
   ============================================================ */
(function () {
  'use strict';
  var root = document.documentElement, KEY = 'mam-a11y', panel = null;
  function read() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } }
  function write(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }
  function apply(s) {
    root.classList.toggle('a11y-lowbw', !!s.lowbw);
    root.classList.toggle('a11y-dyslexia', !!s.dyslexia);
    root.classList.toggle('a11y-text-lg', s.textSize === 'lg');
    root.classList.toggle('a11y-text-xl', s.textSize === 'xl');
  }
  function esc(x) { return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function build() {
    var el = document.createElement('div');
    el.className = 'a11y-pop';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Display and access settings');
    el.innerHTML =
      '<div class="a11y-pop-head"><strong><i class="fa-solid fa-universal-access" aria-hidden="true"></i> Display &amp; access</strong>'
      + '<button type="button" class="a11y-x" data-a11y-close aria-label="Close">&times;</button></div>'
      + '<div class="a11y-row"><span>Text size</span><div class="a11y-seg" role="group" aria-label="Text size">'
      + '<button type="button" data-a11y="textSize" data-val="normal" style="font-size:.8rem">A</button>'
      + '<button type="button" data-a11y="textSize" data-val="lg" style="font-size:1rem">A+</button>'
      + '<button type="button" data-a11y="textSize" data-val="xl" style="font-size:1.2rem">A++</button></div></div>'
      + '<div class="a11y-row"><span>Dyslexia-friendly font</span><button type="button" class="a11y-switch" data-a11y="dyslexia" role="switch"><span class="track"><span class="knob"></span></span></button></div>'
      + '<div class="a11y-row"><span>Low-bandwidth mode</span><button type="button" class="a11y-switch" data-a11y="lowbw" role="switch"><span class="track"><span class="knob"></span></span></button></div>'
      + '<p class="a11y-note">Low-bandwidth reduces motion and hides non-essential images. All settings stay on this device.</p>';
    document.body.appendChild(el);
    return el;
  }
  function render() {
    if (!panel) return;
    var s = read();
    var segs = panel.querySelectorAll('[data-a11y="textSize"]');
    for (var i = 0; i < segs.length; i++) {
      var on = (segs[i].getAttribute('data-val') === (s.textSize || 'normal'));
      segs[i].classList.toggle('active', on);
      segs[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    ['dyslexia', 'lowbw'].forEach(function (k) {
      var b = panel.querySelector('[data-a11y="' + k + '"]');
      if (b) { b.classList.toggle('on', !!s[k]); b.setAttribute('aria-checked', s[k] ? 'true' : 'false'); }
    });
  }
  function open() { if (!panel) panel = build(); panel.classList.add('open'); render(); }
  function close() { if (panel) panel.classList.remove('open'); }
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-action="a11y-menu"]') : null;
    if (t) { e.preventDefault(); if (panel && panel.classList.contains('open')) { close(); } else { open(); } return; }
    if (e.target.closest && e.target.closest('[data-a11y-close]')) { close(); return; }
    var c = e.target.closest ? e.target.closest('[data-a11y]') : null;
    if (c) {
      e.preventDefault();
      var s = read(), k = c.getAttribute('data-a11y');
      if (k === 'textSize') { s.textSize = c.getAttribute('data-val'); }
      else { s[k] = !s[k]; }
      write(s); apply(s); render(); return;
    }
    // click-away closes
    if (panel && panel.classList.contains('open') && !e.target.closest('.a11y-pop')) { close(); }
  }, false);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); }, false);
  apply(read()); // belt-and-braces if the pre-paint script was skipped
})();

/* ============================================================
   Continue where you left off (Advanced Modernization §4.10).
   Remembers the last tab per portal and, on a fresh visit with no
   explicit hash, restores it — so returning lands you where you were.
   Runs at parse time (before each portal's inline init reads the hash).
   ============================================================ */
(function () {
  'use strict';
  var key = 'mam-lasttab:' + location.pathname;
  function store() { if (location.hash && location.hash.length > 1) { try { localStorage.setItem(key, location.hash); } catch (e) {} } }
  if (!location.hash || location.hash.length <= 1) {
    try { var last = localStorage.getItem(key); if (last && /^#[a-z0-9-]+$/i.test(last)) { location.hash = last; } } catch (e) {}
  } else { store(); }
  window.addEventListener('hashchange', store, false);
})();
