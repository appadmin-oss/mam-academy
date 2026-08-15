/* ============================================================
   MAM Command Palette + quick-add (Advanced Modernization §4.1/§4.2)
   ------------------------------------------------------------
   ⌘K / Ctrl-K (or the floating + button) opens a palette that jumps
   to any screen or runs a quick action. Commands are collected from
   the portal's own sidebar nav (so every portal gets navigation for
   free) plus universal actions; portals add their own via
   window.MAM_CMDK.register([{ title, icon, run }]).
   Framework-free, self-contained, keyboard-first.
   ============================================================ */
(function () {
  'use strict';
  if (window.MAM_CMDK) { return; }
  var extra = [], overlay = null, input = null, listEl = null, items = [], allCmds = [], active = 0;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function register(cmds) { (cmds || []).forEach(function (c) { if (c && c.title && typeof c.run === 'function') extra.push(c); }); }

  function collect() {
    var cmds = [], seen = {};
    document.querySelectorAll('.portal-nav a, .portal-nav-link').forEach(function (a) {
      var label = (a.textContent || '').trim().replace(/\s+/g, ' ');
      if (!label || a.getAttribute('data-action') === 'logout') { return; }
      if (seen[label]) { return; } seen[label] = 1;
      var ic = a.querySelector('i');
      cmds.push({ title: 'Go to ' + label, icon: ic ? ic.className : 'fa-solid fa-arrow-right', run: function () { a.click(); } });
    });
    extra.forEach(function (c) { cmds.push(c); });
    cmds.push({ title: 'Toggle dark mode', icon: 'fa-solid fa-circle-half-stroke', run: function () { var b = document.querySelector('[data-action="theme-toggle"]'); if (b) b.click(); } });
    cmds.push({ title: 'Display & access settings', icon: 'fa-solid fa-universal-access', run: function () { var b = document.querySelector('[data-action="a11y-menu"]'); if (b) b.click(); } });
    cmds.push({ title: 'Sign out', icon: 'fa-solid fa-right-from-bracket', run: function () { if (window.MAM_PORTAL && window.MAM_PORTAL.logout) window.MAM_PORTAL.logout(); } });
    return cmds;
  }

  function build() {
    overlay = document.createElement('div');
    overlay.className = 'cmdk-overlay';
    overlay.innerHTML =
      '<div class="cmdk" role="dialog" aria-modal="true" aria-label="Command palette">'
      + '<div class="cmdk-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>'
      + '<input type="text" placeholder="Search actions — jump to a screen or run a command" aria-label="Search commands" autocomplete="off"></div>'
      + '<ul class="cmdk-list" role="listbox"></ul>'
      + '<div class="cmdk-foot"><span><kbd>↑</kbd><kbd>↓</kbd> move</span><span><kbd>↵</kbd> run</span><span><kbd>esc</kbd> close</span></div>'
      + '</div>';
    document.body.appendChild(overlay);
    input = overlay.querySelector('input');
    listEl = overlay.querySelector('.cmdk-list');
    input.addEventListener('input', refilter);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    listEl.addEventListener('click', function (e) { var li = e.target.closest('[data-i]'); if (li) run(+li.getAttribute('data-i')); });
    listEl.addEventListener('mousemove', function (e) { var li = e.target.closest('[data-i]'); if (li) { active = +li.getAttribute('data-i'); paintActive(); } });
  }

  function open() {
    if (!overlay) build();
    allCmds = collect();
    overlay.classList.add('open');
    input.value = ''; refilter(); input.focus();
  }
  function close() { if (overlay) overlay.classList.remove('open'); }

  function refilter() {
    var q = (input.value || '').toLowerCase().trim();
    items = allCmds.filter(function (c) { return !q || c.title.toLowerCase().indexOf(q) >= 0; });
    active = 0; renderList();
  }
  function renderList() {
    listEl.innerHTML = items.length ? items.map(function (c, i) {
      return '<li role="option" data-i="' + i + '"' + (i === active ? ' class="active"' : '') + '><i class="' + esc(c.icon) + '" aria-hidden="true"></i><span>' + esc(c.title) + '</span></li>';
    }).join('') : '<li class="cmdk-empty">No matching actions</li>';
  }
  function paintActive() {
    var lis = listEl.querySelectorAll('[data-i]');
    for (var i = 0; i < lis.length; i++) { lis[i].classList.toggle('active', +lis[i].getAttribute('data-i') === active); }
  }
  function move(d) { if (!items.length) return; active = (active + d + items.length) % items.length; paintActive(); var el = listEl.querySelector('.active'); if (el) el.scrollIntoView({ block: 'nearest' }); }
  function run(i) { var c = items[i]; close(); if (c && c.run) setTimeout(c.run, 0); }

  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); (overlay && overlay.classList.contains('open')) ? close() : open(); return; }
    if (!overlay || !overlay.classList.contains('open')) { return; }
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    else if (e.key === 'Enter') { e.preventDefault(); run(active); }
  });
  document.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('[data-action="cmdk"]') : null; if (b) { e.preventDefault(); open(); } });

  function mountFab() {
    if (document.querySelector('.cmdk-fab')) { return; }
    var fab = document.createElement('button');
    fab.type = 'button'; fab.className = 'cmdk-fab'; fab.setAttribute('data-action', 'cmdk');
    fab.setAttribute('aria-label', 'Quick actions (Ctrl+K)'); fab.title = 'Quick actions — Ctrl/⌘ K';
    fab.innerHTML = '<i class="fa-solid fa-bolt" aria-hidden="true"></i>';
    document.body.appendChild(fab);
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', mountFab); } else { mountFab(); }

  window.MAM_CMDK = { open: open, close: close, register: register };
})();
