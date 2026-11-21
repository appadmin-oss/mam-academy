/*
 * The accessibility panel (partials/site/a11y.php), on every page.
 *
 * State is one JSON object in localStorage "mam.a11y": { size: 0–4, contrast, mute, links,
 * focus, cursor, font, spacing, guide, still, rate }. site_a11y_boot() applies it before
 * the first paint; this file keeps the page and the controls in step with it. The old
 * toolbar's keys (mam.contrast, mam.fontScale) are read once so nobody loses a setting.
 *
 * The panel is a modal dialog and behaves like one: focus moves in, Tab stays inside,
 * Escape closes, focus returns to what opened it. Alt+Shift+A opens it from anywhere.
 */
(function () {
  'use strict';
  var d = document, root = d.documentElement, KEY = 'mam.a11y';
  var panel = d.getElementById('a11y-panel');
  if (!panel) return;
  var scrim = d.querySelector('.a11y-scrim'), guide = d.querySelector('.a11y-guide');
  var status = panel.querySelector('[data-a11y-status]');
  var FLAGS = ['contrast', 'mute', 'links', 'focus', 'cursor', 'font', 'spacing', 'guide', 'still'];
  var NAMES = { contrast: 'Higher contrast', mute: 'Muted colours', links: 'Underline links', focus: 'Strong focus ring', cursor: 'Large pointer', font: 'Readable typeface', spacing: 'More space', guide: 'Reading guide', still: 'Stop movement' };
  var $$ = function (s, el) { return Array.prototype.slice.call((el || d).querySelectorAll(s)); };

  function load() {
    var s = {};
    try { s = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { s = {}; }
    try {
      if (!localStorage.getItem(KEY)) {   // the old toolbar's choices, carried over once
        if (localStorage.getItem('mam.contrast') === 'high') s.contrast = true;
        var f = parseFloat(localStorage.getItem('mam.fontScale') || '1');
        if (f > 1.02) s.size = f >= 1.2 ? 2 : 1;
      }
    } catch (e) { /* storage off: defaults */ }
    return s;
  }
  var st = load();
  function save() { try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) { /* this visit only */ } }

  var cap = panel.querySelector('[data-a11y-cap]');
  function zoom() {
    // The factor is capped so the page never lays out narrower than 320px (shell.php).
    var capped = window.mamA11yZoom ? window.mamA11yZoom(st.size || 0) : 0;
    if (cap) {
      cap.hidden = !capped;
      if (capped) cap.textContent = 'On a screen this narrow the page grows to ' + Math.round(capped * 100) + '%, the most it can without scrolling sideways. Your device’s own text size setting goes further.';
    }
  }
  window.addEventListener('resize', zoom);
  function apply() {
    root.setAttribute('data-a11y-size', String(st.size || 0));
    zoom();
    FLAGS.forEach(function (k) { root.classList.toggle('a11y-' + k, !!st[k]); });
    // The previous design's own high-contrast palette, on the pages that still use it.
    if (st.contrast) root.setAttribute('data-contrast', 'high'); else if (root.getAttribute('data-contrast') === 'high') root.setAttribute('data-contrast', 'normal');
    if (st.font && !d.getElementById('a11y-font-css')) {
      var l = d.createElement('link'); l.id = 'a11y-font-css'; l.rel = 'stylesheet';
      l.href = 'https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&display=swap';
      d.head.appendChild(l);
    }
    if (guide) guide.hidden = !st.guide;
    $$('[data-a11y]', panel).forEach(function (b) { b.setAttribute('aria-pressed', st[b.getAttribute('data-a11y')] ? 'true' : 'false'); });
    $$('[data-a11y-size]', panel).forEach(function (b) { var on = +b.getAttribute('data-a11y-size') === (st.size || 0); b.setAttribute('aria-checked', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; });
    $$('[data-a11y-rate]', panel).forEach(function (b) { var on = +b.getAttribute('data-a11y-rate') === (st.rate || 1); b.setAttribute('aria-checked', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; });
    try { d.dispatchEvent(new CustomEvent('mam:a11y', { detail: st })); } catch (e) { /* old browser */ }
  }
  var say = function (t) { if (status) { status.textContent = ''; setTimeout(function () { status.textContent = t; }, 30); } };

  /* ---------- opening and closing ---------- */
  var opener = null;
  function open(from) {
    opener = from || d.activeElement;
    panel.hidden = false; if (scrim) scrim.hidden = false;
    var first = panel.querySelector('[data-a11y-size][aria-checked="true"]') || panel.querySelector('button');
    if (first) first.focus();
  }
  function close() {
    panel.hidden = true; if (scrim) scrim.hidden = true;
    if (opener && opener.focus) opener.focus();
  }
  d.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-a11y-open],[data-a11y-close]') : null; if (!t) return;
    e.preventDefault();
    if (t.hasAttribute('data-a11y-open')) open(t); else close();
  });
  d.addEventListener('keydown', function (e) {
    if (e.altKey && e.shiftKey && (e.key === 'A' || e.key === 'a')) { e.preventDefault(); if (panel.hidden) open(); else close(); return; }
    if (panel.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'Tab') {   // keep Tab inside the dialog
      var f = $$('button:not([hidden]),a[href],[tabindex="0"]', panel).filter(function (x) { return x.offsetParent !== null; });
      if (!f.length) return;
      if (e.shiftKey && d.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && d.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
    var r = e.target.closest && e.target.closest('[role="radio"]');
    if (r && panel.contains(r) && /^Arrow(Left|Right|Up|Down)$/.test(e.key)) {
      var g = $$('[role="radio"]', r.parentNode), i = g.indexOf(r), n = g[(i + (/Right|Down/.test(e.key) ? 1 : -1) + g.length) % g.length];
      e.preventDefault(); n.click(); n.focus();
    }
  });

  /* ---------- the controls ---------- */
  panel.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('button') : null; if (!t) return;
    if (t.hasAttribute('data-a11y')) {
      var k = t.getAttribute('data-a11y'); st[k] = !st[k]; save(); apply();
      say(NAMES[k] + (st[k] ? ' on' : ' off'));
    } else if (t.hasAttribute('data-a11y-size')) {
      st.size = +t.getAttribute('data-a11y-size'); save(); apply(); say('Text size ' + t.textContent);
    } else if (t.hasAttribute('data-a11y-rate')) {
      st.rate = +t.getAttribute('data-a11y-rate'); save(); apply();
    } else if (t.hasAttribute('data-a11y-reset')) {
      stopReading(); st = {}; save(); apply(); say('All accessibility settings reset');
    } else if (t.hasAttribute('data-a11y-read')) {
      if (reading) stopReading(); else readPage();
    }
  });

  /* ---------- reading guide: follows the pointer, and the keyboard ---------- */
  function placeGuide(y) { if (guide && st.guide) guide.style.top = Math.max(0, y - guide.offsetHeight / 2) + 'px'; }
  d.addEventListener('pointermove', function (e) { placeGuide(e.clientY); }, { passive: true });
  d.addEventListener('focusin', function (e) { if (!st.guide || panel.contains(e.target)) return; var r = e.target.getBoundingClientRect(); placeGuide(r.top + r.height / 2); });

  /* ---------- read aloud: the device's own voice, paragraph by paragraph ---------- */
  var reading = false, queue = [], current = null;
  var readBtn = panel.querySelector('[data-a11y-read]');
  if (!('speechSynthesis' in window)) {
    if (readBtn) readBtn.hidden = true;
    var note = panel.querySelector('[data-a11y-read-note]'); if (note) note.textContent = 'This browser cannot read pages aloud. A screen reader, or your device’s own “speak screen”, will.';
  }
  function blocks() {
    var main = d.getElementById('main') || d.body;
    return $$('h1,h2,h3,p,li,dt,dd,blockquote', main).filter(function (el) {
      if (el.closest('[hidden],[aria-hidden="true"],nav,.a11y-panel')) return false;
      var t = (el.innerText || '').trim(); if (!t) return false;
      return !el.querySelector('h1,h2,h3,p,li');   // the innermost text only, once
    });
  }
  function mark(el) { if (current) current.classList.remove('a11y-reading'); current = el; if (el) { el.classList.add('a11y-reading'); el.scrollIntoView({ block: 'center', behavior: st.still ? 'auto' : 'smooth' }); } }
  function speakNext() {
    if (!reading) return;
    var el = queue.shift();
    if (!el) { stopReading(); say('Finished reading'); return; }
    var u = new SpeechSynthesisUtterance(typeof el === 'string' ? el : el.innerText);
    u.lang = d.documentElement.lang || 'en'; u.rate = st.rate || 1;
    u.onend = speakNext; u.onerror = speakNext;
    if (typeof el !== 'string') mark(el);
    speechSynthesis.speak(u);
  }
  function readPage() {
    var sel = String(window.getSelection ? window.getSelection() : '').trim();
    queue = sel ? [sel] : blocks();
    if (!queue.length) return;
    reading = true; speechSynthesis.cancel();
    if (readBtn) { readBtn.textContent = 'Stop reading'; readBtn.setAttribute('aria-pressed', 'true'); }
    close(); speakNext();
  }
  function stopReading() {
    reading = false; queue = []; mark(null);
    try { speechSynthesis.cancel(); } catch (e) { /* not speaking */ }
    if (readBtn) { readBtn.textContent = 'Read this page aloud'; readBtn.setAttribute('aria-pressed', 'false'); }
  }
  d.addEventListener('keydown', function (e) { if (reading && e.key === 'Escape') stopReading(); });

  /* ---------- the launcher rides above main.js's dock (older pages' Apply bar) ---------- */
  var launch = d.querySelector('.a11y-launch');
  function dodge() {
    var dock = d.getElementById('mamDock'); if (!launch || !dock) return;
    var h = 0; Array.prototype.forEach.call(dock.children, function (c) { if (c.offsetHeight) h = Math.max(h, window.innerHeight - c.getBoundingClientRect().top); });
    launch.style.bottom = (h ? h + 12 : 16) + 'px';
  }
  if (launch && window.ResizeObserver) {
    var ro = new ResizeObserver(dodge), watch = function () { var dock = d.getElementById('mamDock'); if (dock) { ro.observe(dock); dodge(); return true; } };
    if (!watch()) setTimeout(watch, 1500);
  }

  apply();
})();
