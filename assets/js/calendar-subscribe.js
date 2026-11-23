/* Calendar subscriptions in the portals (api/lib/calendar-feeds.php).
   Mounts into every [data-cal-subscribe]: one row per calendar this person can
   subscribe to. A calendar that has no link yet offers "Get my calendar link"
   (POST /api/calendar/mine); one that has shows Google / Apple / Outlook buttons,
   which need no account linking and no API key. Builds DOM nodes — no innerHTML
   with data, no inline style. */
(function () {
  'use strict';
  var NAMES = { google: 'Google Calendar', apple: 'Apple / iPhone', outlook: 'Outlook' };
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function safe(u) { return /^(https:|webcal:)/.test(String(u || '')) ? String(u) : '#'; }
  function feedsOf(d) { return (d && (d.feeds || (d.data && d.data.feeds))) || []; }

  function render(box, feeds, note) {
    box.textContent = '';
    if (!feeds.length) return;
    box.appendChild(el('div', 'p-label p-sheet-head', 'Your calendar'));
    feeds.forEach(function (f) {
      if (!f.links) {
        var b = el('button', 'p-row'); b.type = 'button';
        var ico = el('i', 'fa-solid fa-calendar-plus p-row-ico'); ico.setAttribute('aria-hidden', 'true'); b.appendChild(ico);
        var main = el('span', 'p-row-main'); main.appendChild(el('span', 'p-title', f.label));
        main.appendChild(el('span', 'p-caption', 'Get a link for Google, Apple or Outlook — moves and cancellations update by themselves'));
        b.appendChild(main);
        b.addEventListener('click', function () {
          b.disabled = true;
          window.MAM_API.request('calendar/mine', { method: 'POST', body: { kind: f.kind } })
            .then(function (d) { render(box, feedsOf(d), f.kind); })
            .catch(function () { b.disabled = false; main.lastChild.textContent = 'That did not work — try again in a moment.'; });
        });
        box.appendChild(b);
        return;
      }
      var wrap = el('div', 'p-row'); wrap.setAttribute('role', 'group'); wrap.setAttribute('aria-label', 'Subscribe: ' + f.label);
      var m = el('span', 'p-row-main'); m.appendChild(el('span', 'p-title', f.label));
      var acts = el('span', 'p-caption');
      ['google', 'apple', 'outlook'].forEach(function (k, i) {
        if (i) acts.appendChild(document.createTextNode(' · '));
        var a = el('a', '', NAMES[k]); a.href = safe(f.links[k]);
        if (k !== 'apple') { a.target = '_blank'; a.rel = 'noopener'; }
        acts.appendChild(a);
      });
      m.appendChild(acts);
      if (note === f.kind) m.appendChild(el('span', 'p-caption', 'Link ready. Keep it private — anyone with it sees this calendar.'));
      wrap.appendChild(m);
      box.appendChild(wrap);
    });
  }

  function mount() {
    var boxes = document.querySelectorAll('[data-cal-subscribe]');
    if (!boxes.length || !window.MAM_API) return;
    window.MAM_API.request('calendar/mine').then(function (d) {
      Array.prototype.forEach.call(boxes, function (b) { render(b, feedsOf(d)); });
    }).catch(function () { /* signed out or offline: show nothing rather than a dead button */ });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
