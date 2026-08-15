/**
 * MAM Academy — drag-to-reschedule week grid (Advanced Modernization §4.3).
 * ─────────────────────────────────────────────────────────────
 * A Mon–Sun × hour grid of a cohort's sessions. Drag a session card onto a slot
 * to move it; the caller commits the move through the EXISTING
 * POST /api/instructor/sessions {action:'reschedule'} endpoint, which already
 * does ownership checks and returns instructor/room conflicts. No new backend,
 * no migration — this is a second way to drive a control that already exists.
 *
 * ── TIME IS NAIVE LOCAL, DELIBERATELY ────────────────────────────────────────
 * The server stores and returns 'YYYY-MM-DD HH:MM:SS' with no zone, meaning
 * academy wall-clock time. Every date here is parsed and formatted with string
 * arithmetic and a Date built from explicit Y/M/D parts — never
 * `new Date('2026-08-15 09:00:00')`, whose behaviour is implementation-defined,
 * and never with a 'Z' suffix, which silently shifts a 09:00 class by the
 * viewer's offset. A tutor in Lagos and one in London must see the same slot.
 *
 * ── ACCESSIBILITY ────────────────────────────────────────────────────────────
 * Dragging is an ENHANCEMENT, never the only path: the list view keeps its
 * Reschedule button, and every card here is a real <button> that opens the same
 * prompt on click or Enter. Pointer-only rescheduling would lock out keyboard
 * and screen-reader users entirely.
 */
(function () {
  'use strict';

  var DAYS  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  var PAD   = function (n) { return (n < 10 ? '0' : '') + n; };

  /* ── pure date helpers (unit-tested in schedule-grid.selftest.cjs) ── */

  /**
   * Parse 'YYYY-MM-DD[ HH:MM[:SS]]' into {y,m,d,hh,mm,ss} or null.
   * Strict on purpose: a malformed value must not silently become "now".
   */
  function parseNaive(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/.exec(String(s || '').trim());
    if (!m) { return null; }
    var o = {
      y: +m[1], m: +m[2], d: +m[3],
      hh: m[4] === undefined ? 0 : +m[4],
      mm: m[5] === undefined ? 0 : +m[5],
      ss: m[6] === undefined ? 0 : +m[6]
    };
    if (o.m < 1 || o.m > 12 || o.d < 1 || o.d > 31 || o.hh > 23 || o.mm > 59 || o.ss > 59) { return null; }
    // Reject a date the calendar does not have (2026-02-30). Building a local
    // Date and reading it back is the cheapest correct check.
    var probe = new Date(o.y, o.m - 1, o.d);
    if (probe.getMonth() !== o.m - 1 || probe.getDate() !== o.d) { return null; }
    return o;
  }

  /** {y,m,d,...} → 'YYYY-MM-DD'. */
  function fmtDate(o) { return o.y + '-' + PAD(o.m) + '-' + PAD(o.d); }

  /** {y,m,d,hh,mm,ss} → 'YYYY-MM-DD HH:MM:SS' — the shape the API expects. */
  function fmtNaive(o) {
    return fmtDate(o) + ' ' + PAD(o.hh || 0) + ':' + PAD(o.mm || 0) + ':' + PAD(o.ss || 0);
  }

  /** Add whole days to a naive date, rolling months/years correctly. */
  function addDays(o, n) {
    var d = new Date(o.y, o.m - 1, o.d + n);
    return { y: d.getFullYear(), m: d.getMonth() + 1, d: d.getDate(), hh: o.hh || 0, mm: o.mm || 0, ss: o.ss || 0 };
  }

  /**
   * The Monday of the week containing `dateStr`. ISO weeks start Monday, which
   * is also how the practice strip and the rest of the portal count — a Sunday
   * start here would put the same session in a different column than the
   * student's own view of their week.
   */
  function weekStart(dateStr) {
    var o = parseNaive(dateStr);
    if (!o) { return null; }
    var dow = new Date(o.y, o.m - 1, o.d).getDay(); // 0 = Sun … 6 = Sat
    var back = (dow + 6) % 7;                        // Mon → 0, Sun → 6
    var s = addDays(o, -back);
    s.hh = 0; s.mm = 0; s.ss = 0;
    return s;
  }

  /** 0-based day column for a datetime within the week starting `ws`, or -1. */
  function dayIndex(ws, dateStr) {
    var o = parseNaive(dateStr);
    if (!o || !ws) { return -1; }
    var a = new Date(ws.y, ws.m - 1, ws.d).getTime();
    var b = new Date(o.y, o.m - 1, o.d).getTime();
    var n = Math.round((b - a) / 86400000);
    return (n >= 0 && n < 7) ? n : -1;
  }

  /** The datetime a drop on (dayIndex, hour) means, as an API-ready string. */
  function slotDateTime(ws, day, hour, minute) {
    if (!ws || day < 0 || day > 6 || hour < 0 || hour > 23) { return null; }
    var o = addDays(ws, day);
    o.hh = hour; o.mm = minute || 0; o.ss = 0;
    return fmtNaive(o);
  }

  /** Minutes from midnight, for placing a card inside its hour row. */
  function minutesOfDay(dateStr) {
    var o = parseNaive(dateStr);
    return o ? o.hh * 60 + o.mm : null;
  }

  /**
   * Do two sessions overlap? Used to tint a slot that already has something in
   * it, so a tutor sees the clash BEFORE dropping rather than as a server
   * response afterwards.
   */
  function overlaps(aAt, aMin, bAt, bMin) {
    var a = minutesOfDay(aAt), b = minutesOfDay(bAt);
    if (a === null || b === null) { return false; }
    var ad = parseNaive(aAt), bd = parseNaive(bAt);
    if (fmtDate(ad) !== fmtDate(bd)) { return false; }
    return a < b + (bMin || 60) && b < a + (aMin || 60);
  }

  /**
   * Bucket sessions into the week's cells. Returns
   * { cells: { 'day:hour': [session…] }, outside: [session…] }.
   * `outside` is everything unscheduled or in another week — it must be
   * SHOWN, not dropped, or a tutor with a mis-dated session sees it vanish.
   */
  function layout(sessions, ws, hourFrom, hourTo) {
    var cells = {}, outside = [];
    (sessions || []).forEach(function (s) {
      var at = s && s.scheduled_at;
      var di = at ? dayIndex(ws, at) : -1;
      var mo = at ? minutesOfDay(at) : null;
      if (di < 0 || mo === null) { outside.push(s); return; }
      var h = Math.floor(mo / 60);
      // A session outside the visible hour band is clamped INTO the band rather
      // than hidden — an 06:00 class on a 08:00–20:00 grid still needs to be
      // draggable, and silently omitting it is how a session gets lost.
      if (h < hourFrom) { h = hourFrom; }
      if (h > hourTo)   { h = hourTo; }
      var k = di + ':' + h;
      (cells[k] = cells[k] || []).push(s);
    });
    return { cells: cells, outside: outside };
  }

  /* ── rendering ── */

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function hourLabel(h) {
    var ap = h < 12 ? 'am' : 'pm';
    var hh = h % 12; if (hh === 0) { hh = 12; }
    return hh + ap;
  }

  /**
   * Render the grid into `host`.
   *
   * opts: {
   *   sessions:  [{id,title,scheduled_at,duration_min,kind,status}],
   *   anchor:    'YYYY-MM-DD' — any date in the week to show,
   *   hourFrom:  default 8, hourTo: default 20,
   *   onMove:    function (sessionId, newScheduledAtString) -> void,
   *   onWeek:    function (newAnchorDateString) -> void
   * }
   */
  function render(host, opts) {
    if (!host) { return; }
    opts = opts || {};
    var from = opts.hourFrom == null ? 8 : opts.hourFrom;
    var to   = opts.hourTo   == null ? 20 : opts.hourTo;
    var ws   = weekStart(opts.anchor || todayStr());
    if (!ws) { ws = weekStart(todayStr()); }
    var lay  = layout(opts.sessions, ws, from, to);
    var end  = addDays(ws, 6);

    var html = '<div class="sg-head">'
      + '<button type="button" class="btn btn-ghost sg-nav" data-sg-week="prev" aria-label="Previous week"><i class="fa-solid fa-chevron-left"></i></button>'
      + '<strong class="sg-range">' + esc(fmtDate(ws)) + ' → ' + esc(fmtDate(end)) + '</strong>'
      + '<button type="button" class="btn btn-ghost sg-nav" data-sg-week="next" aria-label="Next week"><i class="fa-solid fa-chevron-right"></i></button>'
      + '<button type="button" class="btn btn-ghost sg-nav" data-sg-week="today">This week</button>'
      + '</div>'
      + '<p class="sg-hint">Drag a session onto a slot to move it — or press Enter on a session to type a new time.</p>'
      + '<div class="sg-scroll"><table class="sg-grid"><thead><tr><th class="sg-time"><span class="sr-only">Time</span></th>';

    for (var d = 0; d < 7; d++) {
      var col = addDays(ws, d);
      html += '<th scope="col"><span class="sg-dow">' + DAYS[d] + '</span><span class="sg-dom">' + PAD(col.d) + '</span></th>';
    }
    html += '</tr></thead><tbody>';

    for (var h = from; h <= to; h++) {
      html += '<tr><th scope="row" class="sg-time">' + hourLabel(h) + '</th>';
      for (var dd = 0; dd < 7; dd++) {
        var items = lay.cells[dd + ':' + h] || [];
        html += '<td class="sg-slot" data-sg-day="' + dd + '" data-sg-hour="' + h + '">' + items.map(card).join('') + '</td>';
      }
      html += '</tr>';
    }
    html += '</tbody></table></div>';

    if (lay.outside.length) {
      html += '<div class="sg-outside"><div class="sg-outside-label">Not in this week</div>'
        + lay.outside.map(card).join('') + '</div>';
    }
    host.innerHTML = html;
    wire(host, ws, opts);
  }

  function card(s) {
    var when = s.scheduled_at ? String(s.scheduled_at).slice(11, 16) : 'unscheduled';
    var cls  = 'sg-card' + (s.status && s.status !== 'scheduled' ? ' is-' + esc(s.status) : '');
    // A real <button>: focusable, Enter-activatable, announced as an action.
    return '<button type="button" class="' + cls + '" draggable="true"'
      + ' data-sg-id="' + esc(s.id) + '"'
      + ' data-sg-at="' + esc(s.scheduled_at || '') + '"'
      + ' title="' + esc((s.title || ('Session #' + s.id)) + ' · ' + when) + '">'
      + '<span class="sg-card-time">' + esc(when) + '</span>'
      + '<span class="sg-card-title">' + esc(s.title || ('Session #' + s.id)) + '</span>'
      + '</button>';
  }

  function todayStr() {
    var n = new Date();
    return n.getFullYear() + '-' + PAD(n.getMonth() + 1) + '-' + PAD(n.getDate());
  }

  function wire(host, ws, opts) {
    var dragId = null;

    host.querySelectorAll('[data-sg-week]').forEach(function (b) {
      b.addEventListener('click', function () {
        var w = b.getAttribute('data-sg-week');
        var next = w === 'today' ? todayStr() : fmtDate(addDays(ws, w === 'prev' ? -7 : 7));
        if (opts.onWeek) { opts.onWeek(next); }
      });
    });

    host.querySelectorAll('.sg-card').forEach(function (c) {
      c.addEventListener('dragstart', function (e) {
        dragId = c.getAttribute('data-sg-id');
        c.classList.add('is-dragging');
        try {
          e.dataTransfer.effectAllowed = 'move';
          // Firefox will not start a drag unless something is set.
          e.dataTransfer.setData('text/plain', dragId);
        } catch (err) { /* older browsers */ }
      });
      c.addEventListener('dragend', function () { dragId = null; c.classList.remove('is-dragging'); });
      // The keyboard path to the same outcome.
      c.addEventListener('click', function () {
        if (!opts.onMove) { return; }
        var cur = c.getAttribute('data-sg-at') || '';
        var val = window.prompt('New date and time for this session (YYYY-MM-DD HH:MM):', cur.slice(0, 16));
        if (val === null) { return; }
        var p = parseNaive(val);
        if (!p) { window.alert('Please use the format YYYY-MM-DD HH:MM.'); return; }
        opts.onMove(c.getAttribute('data-sg-id'), fmtNaive(p));
      });
    });

    host.querySelectorAll('.sg-slot').forEach(function (cell) {
      cell.addEventListener('dragover', function (e) {
        if (!dragId) { return; }
        e.preventDefault();               // without this the drop never fires
        try { e.dataTransfer.dropEffect = 'move'; } catch (err) {}
        cell.classList.add('is-over');
      });
      cell.addEventListener('dragleave', function () { cell.classList.remove('is-over'); });
      cell.addEventListener('drop', function (e) {
        e.preventDefault();
        cell.classList.remove('is-over');
        var id = dragId || (function () { try { return e.dataTransfer.getData('text/plain'); } catch (err) { return null; } })();
        if (!id || !opts.onMove) { return; }
        var at = slotDateTime(ws, +cell.getAttribute('data-sg-day'), +cell.getAttribute('data-sg-hour'), 0);
        if (at) { opts.onMove(id, at); }
      });
    });
  }

  var api = {
    render: render,
    _internals: {
      parseNaive: parseNaive, fmtNaive: fmtNaive, fmtDate: fmtDate, addDays: addDays,
      weekStart: weekStart, dayIndex: dayIndex, slotDateTime: slotDateTime,
      minutesOfDay: minutesOfDay, overlaps: overlaps, layout: layout, hourLabel: hourLabel
    }
  };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  if (typeof window !== 'undefined') { window.MAM_SCHEDULE = api; }
})();
