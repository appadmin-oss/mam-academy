/*
 * MAM Academy public site (2026-11 redesign) — the behaviour every redesigned page
 * shares: the Programmes panel, the home header's two layers, the intake strip, the
 * question accordion, the newsletter form and the handoff's effects layer
 * (specs/effects.js: card spotlight, smart header, scroll-spy).
 *
 * The markup was ported from the handoff's page sources and keeps their inline
 * styles, so where a state changes a style this file writes the same style string
 * the source's renderVals() returned. That is deliberate: it is what lets the build
 * be verified against the design with Faithful rather than by eye.
 *
 * Everything here is progressive: with no script the panel's links are still
 * reachable from the footer, every answer in the accordion is in the markup, and
 * the newsletter form posts.
 */
(function () {
  'use strict';
  var d = document, root = d.documentElement;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function $$(s, el) { return Array.prototype.slice.call((el || d).querySelectorAll(s)); }

  /* ---------- the Programmes panel ---------- */
  var panel = d.getElementById('programmes-panel');
  var toggles = $$('[data-act="menu"]');
  var lastToggle = null;
  function setMenu(open, focusBack) {
    if (!panel) return;
    panel.hidden = !open;
    toggles.forEach(function (b) {
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
      var chev = b.querySelector('svg[style*="rotate"]');
      if (chev) chev.style.transform = 'rotate(' + (open ? '180deg' : '0deg') + ')';
    });
    root.classList.toggle('menu-open', open);
    layers();
    if (open) { var first = panel.querySelector('a'); if (first) first.focus({ preventScroll: true }); }
    else if (focusBack && lastToggle) lastToggle.focus();
  }
  toggles.forEach(function (b) {
    b.addEventListener('click', function () { lastToggle = b; setMenu(panel && panel.hidden, true); });
  });
  d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel && !panel.hidden) setMenu(false, true); });
  if (panel) panel.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('a')) setMenu(false); });
  d.addEventListener('click', function (e) {
    if (!panel || panel.hidden) return;
    if (!panel.contains(e.target) && !(e.target.closest && e.target.closest('[data-act="menu"]'))) setMenu(false);
  });

  /* ---------- home: the minimal layer over the hero, the full bar after 560px ---------- */
  var min = d.querySelector('[data-layer="min"]'), full = d.querySelector('[data-layer="full"]');
  var MIN_ON = 'position: absolute; left: 0; right: 0; top: 0; transition: opacity .5s var(--out), transform .75s var(--out); opacity: 1; transform: none; animation: heroIn 1s .2s var(--soft) both';
  var MIN_OFF = 'position: absolute; left: 0; right: 0; top: 0; transition: opacity .5s var(--out), transform .75s var(--out); opacity: 0; transform: translateY(-12px); pointer-events: none; visibility: hidden';
  var FULL_ON = 'position: absolute; left: 0; right: 0; top: 0; background: #FAF8F4; transition: transform .75s var(--out), box-shadow .5s ease, visibility 0s 0s; transform: translateY(0); visibility: visible; box-shadow: 0 1px 0 rgba(33,26,19,.08)';
  var FULL_OFF = 'position: absolute; left: 0; right: 0; top: 0; background: #FAF8F4; transition: transform .75s var(--out), box-shadow .5s ease, visibility 0s .75s; transform: translateY(-110%); visibility: hidden; box-shadow: none';
  var solid = null;
  function layers() {
    if (!min || !full) return;
    var s = (window.scrollY || 0) > 560 || (panel && !panel.hidden);
    if (s === solid) return; solid = s;
    min.setAttribute('style', s ? MIN_OFF : MIN_ON);
    full.setAttribute('style', s ? FULL_ON : FULL_OFF);
  }
  if (min && full) { window.addEventListener('scroll', layers, { passive: true }); layers(); }

  /* ---------- inner pages: the bar's hairline once the page has moved ---------- */
  var bar = d.querySelector('header[data-smart]');
  if (bar) {
    var lined = null;
    var line = function () { var s = (window.scrollY || 0) > 8 || (panel && !panel.hidden); if (s !== lined) { lined = s; bar.style.boxShadow = s ? '0 1px 0 rgba(33,26,19,.10)' : 'none'; } };
    window.addEventListener('scroll', line, { passive: true }); line();
  }

  /* ---------- the intake strip: dismissing it squares the hero's top corners ---------- */
  $$('[data-act="dismiss-bar"]').forEach(function (b) {
    b.addEventListener('click', function () {
      var strip = b.closest('[role="region"]'); if (strip) strip.hidden = true;
      var wrap = d.querySelector('[data-hero-wrap]'), hero = d.querySelector('[data-hero]');
      if (wrap) wrap.style.background = 'transparent';
      if (hero) hero.style.borderRadius = '0';
      var skip = d.querySelector('.skip'); if (skip) skip.focus();
    });
  });

  /* ---------- questions: one open at a time ---------- */
  var faqs = $$('[data-act="faq"]');
  function faq(open) {
    faqs.forEach(function (b, i) {
      var on = i === open, region = d.getElementById(b.getAttribute('aria-controls'));
      var icon = b.querySelector('span[aria-hidden]');
      b.setAttribute('aria-expanded', on ? 'true' : 'false');
      if (region) { region.style.gridTemplateRows = on ? '1fr' : '0fr'; region.style.opacity = on ? '1' : '0'; region.inert = !on; }
      if (icon) icon.style.transform = 'rotate(' + (on ? '45deg' : '0deg') + ')';
    });
  }
  if (faqs.length) {
    var openFaq = faqs.findIndex(function (b) { return b.getAttribute('aria-expanded') === 'true'; });
    faq(openFaq);
    faqs.forEach(function (b, i) { b.addEventListener('click', function () { openFaq = openFaq === i ? -1 : i; faq(openFaq); }); });
  }

  /* ---------- print buttons ---------- */
  $$('[data-act="print"]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

  /* ---------- newsletter: the CSRF-gated endpoint, with the result said out loud ---------- */
  $$('form[data-newsletter]').forEach(function (form) {
    var status = form.querySelector('[data-newsletter-status]');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = (form.querySelector('input[type=email]') || {}).value || '';
      var say = function (t) { if (status) status.textContent = t; };
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { say('Enter an email address like name@example.com.'); return; }
      say('Subscribing…');
      fetch('/api/csrf', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        var token = j && (j.token || (j.data && j.data.token)) || '';
        return fetch('/api/newsletter', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
          body: JSON.stringify({ email: email, source: 'site-footer' }) });
      }).then(function (r) {
        if (r.ok) { say('You are subscribed. Look out for our next note.'); form.reset(); }
        else if (r.status === 429) say('Too many tries from this connection. Please try again in a few minutes.');
        else say('That did not go through. Please try again, or write to academy@mamcentre.org.');
      }).catch(function () { say('You appear to be offline. Please try again when you are connected.'); });
    });
  });

  /* ---------- effects layer (specs/effects.js) ---------- */
  d.addEventListener('pointermove', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('.lift, .zoom, .bento > *, [data-spot]') : null; if (!t) return;
    if (!t.classList.contains('spot')) {
      if (getComputedStyle(t).position === 'static') t.style.position = 'relative';
      var m = getComputedStyle(t).backgroundColor.match(/\d+/g);
      if (m && (+m[0] + +m[1] + +m[2]) < 200) t.style.setProperty('--spot', 'rgba(242,169,59,.22)');
      t.classList.add('spot');
    }
    var r = t.getBoundingClientRect(); t.style.setProperty('--mx', (e.clientX - r.left) + 'px'); t.style.setProperty('--my', (e.clientY - r.top) + 'px');
  }, { passive: true });
  if (bar && !reduce) {
    var last = window.scrollY || 0;
    window.addEventListener('scroll', function () {
      var y = window.scrollY || 0; if (Math.abs(y - last) < 6) return;
      root.classList.toggle('fx-hide', y > last && y > 520 && (!panel || panel.hidden)); last = y;
    }, { passive: true });
  }
  var spy = d.querySelector('nav[aria-label="On this page"]');
  if (spy && window.IntersectionObserver) {
    var links = $$('a[href^="#"]', spy), map = {};
    links.forEach(function (a) { var el = d.getElementById(a.getAttribute('href').slice(1)); if (el) map[el.id] = a; });
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        if (!en.isIntersecting) return;
        links.forEach(function (a) { a.classList.remove('on'); a.removeAttribute('aria-current'); });
        var a = map[en.target.id]; if (!a) return;
        a.classList.add('on'); a.setAttribute('aria-current', 'location');
        var ul = a.closest('div'); if (ul && ul.scrollWidth > ul.clientWidth) ul.scrollTo({ left: a.offsetLeft - 40, behavior: reduce ? 'auto' : 'smooth' });
      });
    }, { rootMargin: '-40% 0px -55% 0px' });
    Object.keys(map).forEach(function (id) { io.observe(d.getElementById(id)); });
  }
  /* ---------- the cookie notice (partials/site/a11y.php) ----------
     "mam.cookie" is the key MAM_PRIVACY.mayMeasure() reads, so a decline here is
     the decline every measurement on the site honours. Not answering is not a yes. */
  var ck = d.querySelector('[data-cookie]');
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
  if (ck) {
    // On a page still on the previous design the notice joins the dock (main.js), so it
    // stacks with the Apply bar instead of sitting on top of it.
    if (typeof window.mamDock === 'function') { window.mamDock().appendChild(ck); ck.classList.add('ck--dock'); }
    // While the notice is up, its height is published as --ck-h so the accessibility
    // launcher can stand on top of it on a phone (a11y.css) rather than overlap it.
    var root = d.documentElement;
    var sizeCk = function () { root.style.setProperty('--ck-h', ck.offsetHeight + 'px'); };
    var hideCk = function () { ck.hidden = true; root.classList.remove('ck-on'); window.removeEventListener('resize', sizeCk); };
    var showCk = function (focus) {
      ck.hidden = false; root.classList.add('ck-on'); sizeCk(); window.addEventListener('resize', sizeCk);
      if (focus) { var b = ck.querySelector('button'); if (b) b.focus(); }
    };
    if (!store('mam.cookie')) setTimeout(function () { showCk(false); }, reduce ? 0 : 1200);
    ck.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-cookie-choice]'); if (!b) return;
      store('mam.cookie', b.getAttribute('data-cookie-choice')); hideCk();
    });
    $$('[data-cookie-open]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); showCk(true); }); });
  }

  /* ---------- prices in your currency ----------
     The footer's switch and the Programmes estimator are one choice ("mam.currency",
     the key the rest of the site reads too). Rates are the server's (site_fees()),
     the ones a charge is made at, never a third-party feed. On a page still on the
     previous design main.js paints instead, from the same key. */
  var F = window.MAM_SITE && window.MAM_SITE.fees;
  if (!window.MAM_CCY && F && F.rates) {
    var SYM = { USD: '$', NGN: '₦', GBP: '£' };
    var cur = store('mam.currency');
    if (!SYM[cur] || !F.rates[cur]) cur = 'USD';
    var paint = function () {
      var r = F.rates[cur] || 1;
      $$('[data-amt-usd]').forEach(function (el) {
        el.textContent = SYM[cur] + Math.round(parseFloat(el.getAttribute('data-amt-usd')) * r).toLocaleString('en-US');
      });
      $$('.cur-sw [data-currency]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-currency') === cur ? 'true' : 'false'); });
    };
    window.MAM_SITE.currency = function (c) {
      if (c === undefined) return cur;
      if (!SYM[c] || !F.rates[c] || c === cur) return cur;
      cur = c; store('mam.currency', c); paint();
      try { d.dispatchEvent(new CustomEvent('mam:currency', { detail: { currency: c } })); } catch (e) { /* old browser */ }
      return cur;
    };
    $$('.cur-sw [data-currency]').forEach(function (b) { b.addEventListener('click', function () { window.MAM_SITE.currency(b.getAttribute('data-currency')); }); });
    $$('.cur-sw [data-currency]').forEach(function (b) { if (!F.rates[b.getAttribute('data-currency')]) b.hidden = true; });
    paint();
  }
})();
