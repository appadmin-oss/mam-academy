/* MAM Academy — admin console behaviour (assets/js/admin-console.js)
 *
 * The console is server-rendered, so everything here is enhancement: the rail
 * collapse, the row-density toggle, the ⌘K palette and the go-to keyboard
 * chords. Nothing on the page depends on this file having loaded — a screen
 * still renders, every link still navigates, every form still posts.
 *
 * A registrar works this console for hours, so the mouse is the fallback, not
 * the interface (the design says so, and it is why the chords exist).
 */
(function () {
  'use strict';

  var CFG = window.MAM_CONSOLE || { destinations: [], screen: '' };
  var root = document.documentElement;

  /* ── Preferences. A cookie, not a setting: one registrar's row density must
        not change another's console. Same-site, a year, no personal data. ─── */
  function remember(name, value) {
    document.cookie = name + '=' + encodeURIComponent(value) +
      '; path=/; max-age=31536000; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
  }

  /* ── Rail ─────────────────────────────────────────────────────────────── */
  var rail = document.getElementById('ac-rail');
  var stick = document.getElementById('ac-railstick');
  var toggle = document.getElementById('ac-railtoggle');

  if (rail && stick) {
    var onScroll = function () { stick.classList.toggle('is-lifted', rail.scrollTop > 4); };
    rail.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  if (toggle) {
    toggle.addEventListener('click', function () {
      var next = root.getAttribute('data-rail') === 'collapsed' ? 'open' : 'collapsed';
      root.setAttribute('data-rail', next);
      remember('mam_ac_rail', next);
      var collapsed = next === 'collapsed';
      toggle.setAttribute('aria-pressed', String(collapsed));
      toggle.title = collapsed ? 'Expand the sidebar' : 'Collapse the sidebar';
      toggle.setAttribute('aria-label', toggle.title);
      toggle.innerHTML = '<i class="fa-solid fa-angles-' + (collapsed ? 'right' : 'left') + '"></i>';
    });
  }

  /* Off-canvas rail below 900px. */
  var hamburger = document.getElementById('ac-hamburger');
  var navscrim = document.getElementById('ac-navscrim');
  function setNav(open) {
    if (open) root.setAttribute('data-nav', 'open'); else root.removeAttribute('data-nav');
    if (navscrim) navscrim.hidden = !open;
  }
  if (hamburger) hamburger.addEventListener('click', function () { setNav(root.getAttribute('data-nav') !== 'open'); });
  if (navscrim) navscrim.addEventListener('click', function () { setNav(false); });

  /* ── Row density ──────────────────────────────────────────────────────── */
  Array.prototype.forEach.call(document.querySelectorAll('[data-density]'), function (btn) {
    btn.addEventListener('click', function () {
      var v = btn.getAttribute('data-density');
      root.setAttribute('data-density', v);
      remember('mam_ac_density', v);
      Array.prototype.forEach.call(document.querySelectorAll('[data-density]'), function (b) {
        b.setAttribute('aria-pressed', String(b === btn));
      });
    });
  });

  /* ── ⌘K palette ───────────────────────────────────────────────────────── */
  var overlay = document.getElementById('ac-palette');
  var input = document.getElementById('ac-q');
  var list = document.getElementById('ac-presults');
  var opener = document.getElementById('ac-open-palette');
  var lastFocus = null;
  var active = -1;
  var seq = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* Highlight the matched run, escaped first so a name containing markup is
     text and never markup. */
  function mark(text, q) {
    var t = esc(text);
    if (!q) return t;
    var i = t.toLowerCase().indexOf(esc(q).toLowerCase());
    if (i < 0) return t;
    return t.slice(0, i) + '<mark>' + t.slice(i, i + q.length) + '</mark>' + t.slice(i + q.length);
  }

  function openPalette() {
    if (!overlay) return;
    lastFocus = document.activeElement;
    overlay.hidden = false;
    input.value = '';
    render([], '');
    input.focus();
  }
  function closePalette() {
    if (!overlay || overlay.hidden) return;
    overlay.hidden = true;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-ac-close]'), function (b) {
    b.addEventListener('click', closePalette);
  });
  if (opener) opener.addEventListener('click', openPalette);

  /* Matches on the SECTION name or its AREA name — the handoff requires both,
     because with 22 destinations demoted to a second level, "finance" is how
     somebody looks for Price books when they cannot remember what it is
     called. */
  function destinationMatches(q) {
    var needle = q.toLowerCase();
    return (CFG.destinations || []).filter(function (d) {
      if (!needle) return true;
      return d.label.toLowerCase().indexOf(needle) >= 0
        || d.key.indexOf(needle) === 0
        || (d.area || '').toLowerCase().indexOf(needle) >= 0;
    }).slice(0, 6);
  }

  function render(results, q) {
    if (!list) return;
    var html = '';
    var dests = destinationMatches(q);
    if (dests.length) {
      html += '<div class="ac-palettegroup">Go to</div>';
      dests.forEach(function (d) {
        html += '<a class="ac-presult" href="?v=' + encodeURIComponent(d.key) + '">' +
          '<i class="fa-solid fa-arrow-right"></i><span>' + mark(d.label, q) + '</span>' +
          '<span class="ac-pmeta">section</span></a>';
      });
    }
    (results || []).forEach(function (group) {
      if (!group.items || !group.items.length) return;
      html += '<div class="ac-palettegroup">' + esc(group.label) + '</div>';
      group.items.forEach(function (it) {
        html += '<a class="ac-presult" href="' + esc(it.href) + '">' +
          '<i class="fa-solid ' + esc(it.icon || 'fa-circle') + '"></i>' +
          '<span>' + mark(it.title, q) + '</span>' +
          '<span class="ac-pmeta">' + esc(it.meta || '') + '</span></a>';
      });
    });
    if (!html) {
      html = '<div class="ac-empty"><div class="ac-empty-t">Nothing matched</div>' +
        '<div>Try a name, an application reference or an invoice number.</div></div>';
    }
    list.innerHTML = html;
    active = -1;
  }

  function items() { return list ? list.querySelectorAll('.ac-presult') : []; }

  function move(delta) {
    var rows = items();
    if (!rows.length) return;
    if (active >= 0 && rows[active]) rows[active].classList.remove('is-active');
    active = (active + delta + rows.length) % rows.length;
    rows[active].classList.add('is-active');
    rows[active].scrollIntoView({ block: 'nearest' });
  }

  var timer = null;
  if (input) {
    input.addEventListener('input', function () {
      var q = input.value.trim();
      render([], q);
      if (q.length < 2) return;
      clearTimeout(timer);
      var mine = ++seq;
      timer = setTimeout(function () {
        fetch('/api/admin/search?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) {
            if (!data || mine !== seq) return;
            render(groupsFrom(data), q);
          })
          .catch(function () { /* a failed lookup leaves the destinations list, which still works */ });
      }, 140);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter') {
        var rows = items();
        var target = active >= 0 ? rows[active] : rows[0];
        if (target) { e.preventDefault(); target.click(); }
      }
    });
  }

  /* Shape the admin search payload into palette groups. The endpoint returns
     whatever it returns; anything unrecognised is skipped rather than guessed. */
  function groupsFrom(data) {
    var out = [];
    function push(label, rows, icon, href, meta) {
      if (!rows || !rows.length) return;
      out.push({
        label: label,
        items: rows.slice(0, 6).map(function (r) {
          return { title: r.name || r.title || r.reference || r.email || ('#' + r.id),
                   meta: meta(r), icon: icon, href: href(r) };
        })
      });
    }
    push('People', data.users, 'fa-user',
      function (r) { return '?v=students&q=' + encodeURIComponent(r.email || r.name || ''); },
      function (r) { return r.role || r.email || ''; });
    push('Applications', data.applications, 'fa-inbox',
      function (r) { return '?v=admissions&app=' + encodeURIComponent(r.id); },
      function (r) { return r.reference || r.status || ''; });
    push('Payments', data.payments, 'fa-file-invoice',
      function () { return '?v=ledger'; },
      function (r) { return (r.reference || '') + ' ' + (r.status || ''); });
    return out;
  }

  /* ── Keyboard: ⌘K / Ctrl-K, "/" to search, "g" then a letter to go. ───── */
  var chord = null;
  var chordTimer = null;

  document.addEventListener('keydown', function (e) {
    var t = e.target;
    var typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);

    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault(); openPalette(); return;
    }
    if (e.key === 'Escape') { closePalette(); setNav(false); return; }
    if (typing) return;

    if (e.key === '/') { e.preventDefault(); openPalette(); return; }
    if (e.key === '[' || e.key === ']') { if (toggle) { e.preventDefault(); toggle.click(); } return; }

    if (chord === 'g') {
      clearTimeout(chordTimer); chord = null;
      /* G then D A S T C F P R G — the nine AREAS, per the handoff's keyboard
         table. This used to take the first DESTINATION whose key began with
         the letter, which across 31 keys is arbitrary: `G C` reached whichever
         of calendar / charges / cohorts / comms / curriculum happened to be
         registered first, and that answer changed whenever the rail was
         reordered. */
      var k = e.key.toLowerCase();
      var to = (CFG.areas || []).filter(function (a) { return a.letter === k; })[0];
      if (to) { e.preventDefault(); location.href = '?v=' + encodeURIComponent(to.home); }
      return;
    }
    if (e.key === 'g' || e.key === 'G') {
      chord = 'g';
      clearTimeout(chordTimer);
      chordTimer = setTimeout(function () { chord = null; }, 1400);
    }
  });

  /* ── Trap Tab inside the palette while it is open. ────────────────────── */
  if (overlay) {
    overlay.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var focusables = overlay.querySelectorAll('a[href], button:not([disabled]), input, [tabindex]:not([tabindex="-1"])');
      if (!focusables.length) return;
      var first = focusables[0], last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }

  /* ── Drawers rendered inline by a screen. A drawer is a URL parameter, so it
        survives a reload and can be linked to; closing one removes the
        parameter rather than hiding a node. ─────────────────────────────── */
  Array.prototype.forEach.call(document.querySelectorAll('[data-ac-drawer-close]'), function (b) {
    b.addEventListener('click', function () {
      var param = b.getAttribute('data-ac-drawer-close');
      var u = new URL(location.href);
      u.searchParams.delete(param);
      location.href = u.pathname + '?' + u.searchParams.toString();
    });
  });

  /* Clean ?ok=/?err= out of the address bar so a reload does not re-flash a
     message about something that already happened. */
  if (window.history && history.replaceState && /[?&](ok|err)=/.test(location.search)) {
    var u2 = new URL(location.href);
    u2.searchParams.delete('ok'); u2.searchParams.delete('err');
    history.replaceState(null, '', u2.pathname + (u2.searchParams.toString() ? '?' + u2.searchParams.toString() : ''));
  }
})();
