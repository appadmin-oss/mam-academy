/* ─────────────────────────────────────────────────────────────────────────────
   MAM Academy — the guardian portal (redesign, 19 Aug 2026)

   Six screens from the canonical design (`Portals Mobile.dc.html` #3b
   `data-phone="guardianv2"`): dashboard · children · reports · finance ·
   consents · messages, plus the More sheet's own destinations.

   ── Two rules this file is built around ─────────────────────────────────────

   1. **No inline styles, anywhere.** Every visual decision lives in
      `assets/css/portal-v2.css` as a named component. A template here that
      reached for `style="…"` would put a value the designer chose in a place
      nobody looks when they change it — which is how the previous portals ended
      up with three definitions of a card.

   2. **Never an optimistic tick.** A write goes busy, then reports what actually
      happened. Offline, it says "Saved on this phone" only when it really was
      queued; otherwise it says the truth, which is that it did not save. The
      design's refusal 7, and its reason: a dishonest success state loses the
      record, and a family that discovers a lost consent stops trusting the
      screen that showed it.

   Extracted to its own file rather than inlined. The other three dashboards
   carry ~4,800 lines of inline `<script>` between them, which is why a change to
   one of them cannot be reviewed as a diff.
   ───────────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  var P   = window.MAM_PORTAL || {};
  var API = window.MAM_API;
  var esc = P.escapeHtml || function (s) { return String(s == null ? '' : s); };

  /* State. `child` is the selected child id; the design pins the switcher above
     the rail because a multi-child household is the DEFAULT case. */
  var S = { children: [], child: null, dash: null, screen: 'dashboard', busy: false };

  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var main = $('#main');

  /* ── Small helpers ──────────────────────────────────────────────────────── */

  function fmtDate(v) {
    if (!v) return '';
    var d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(String(v).slice(0, 10));
    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function fmtDateTime(v) {
    if (!v) return '';
    var d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(String(v));
    return d.toLocaleString(undefined, { weekday: 'long', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  }
  function initials(name) {
    return String(name || '?').trim().split(/\s+/).slice(0, 2)
      .map(function (w) { return w.charAt(0).toUpperCase(); }).join('') || '?';
  }
  function plural(n, one, many) { return n === 1 ? one : many; }

  /* Feedback. `tone` is 'info' | 'good' | 'bad'; the icon and the words carry the
     meaning as well as the colour, because colour alone is not a signal (§4). */
  var toastTimer = null;
  /* ⚠️ An ERROR is not a polite status, and it does not time out. The element
     is declared `role="status" aria-live="polite"`, which a screen reader
     queues until it is idle — right for "Saved", wrong for "that did not
     save". A failure is swapped to `alert`/assertive and stays until it is
     dismissed, because it is often the only record that the thing the reader
     pressed did not happen. Successes still clear themselves. */
  function toast(msg, tone) {
    var el = $('#toast'), txt = $('#toastText'), ico = el.querySelector('i'), bad = tone === 'bad';
    txt.textContent = msg;
    el.className = 'p-toast' + (bad ? ' is-bad' : tone === 'good' ? ' is-good' : '');
    ico.className = 'fa-solid ' + (bad ? 'fa-triangle-exclamation'
                     : tone === 'good' ? 'fa-circle-check' : 'fa-circle-info');
    el.setAttribute('role', bad ? 'alert' : 'status');
    el.setAttribute('aria-live', bad ? 'assertive' : 'polite');
    var close = $('#toastClose');
    if (close) close.hidden = !bad;
    el.hidden = false;
    if (toastTimer) clearTimeout(toastTimer);
    if (!bad) toastTimer = setTimeout(function () { el.hidden = true; }, 5000);
  }

  /**
   * A write, with an honest report of what happened.
   *
   * The button goes busy for the whole round trip and is never given a success
   * appearance the server has not confirmed. On a network failure the message
   * distinguishes the two cases that matter to a family: we could not reach the
   * academy (nothing saved, try again) versus we saved it here (it will go up).
   * This portal does not yet hold an IndexedDB queue — the student Train loop is
   * where the design puts that — so it says the first, which is true.
   */
  function write(btn, req, onOk) {
    if (S.busy) return;
    S.busy = true;
    var label = null;
    if (btn) { btn.setAttribute('data-busy', '1'); label = btn.innerHTML; btn.innerHTML = '<i class="fa-solid fa-circle-notch p-spin" aria-hidden="true"></i>Saving…'; }
    return req.then(function (r) {
      if (btn) { btn.removeAttribute('data-busy'); btn.innerHTML = label; }
      S.busy = false;
      if (onOk) onOk(r);
      return r;
    }).catch(function (err) {
      if (btn) { btn.removeAttribute('data-busy'); btn.innerHTML = label; }
      S.busy = false;
      if (!navigator.onLine) {
        toast('You are offline, so this has NOT been saved. Nothing has been sent to the academy — '
            + 'try again when you have data.', 'bad');
      } else {
        toast((P.errText ? P.errText(err, 'That did not save.') : 'That did not save.'), 'bad');
      }
      throw err;
    });
  }

  /* ── Screens ────────────────────────────────────────────────────────────── */

  function selectedChild() {
    if (!S.children.length) return null;
    var found = S.children.filter(function (c) { return Number(c.student_id) === Number(S.child); })[0];
    return found || S.children[0];
  }

  /**
   * Set the screen's content, and move the focus target onto its heading.
   *
   * It used to do a second job — a `paintBars()` pass that wrote each
   * competency bar's width into a `style` attribute after render. That is gone:
   * the widths are a `[data-pct="N"]` table in portal-v2.css, so a bar is the
   * right length the moment the markup exists.
   *
   * Worth keeping the reason it was fragile. Most of these renders are
   * asynchronous, so `paintBars()` called at the end of the router ran against
   * the LOADING state and never against the real content — measured in
   * Chromium, every competency bar drew full width regardless of the mark,
   * which is the single most misleading thing this screen could do. Routing it
   * through one function fixed that; stating the length in CSS means there is
   * no second pass left to forget, and an unmatched bar is `width: 0` rather
   * than full.
   */
  function paint(html) {
    main.innerHTML = html;
    var head = main.querySelector('.p-h1') || main.querySelector('.p-back');
    if (head) head.setAttribute('tabindex', '-1');
  }

  function loading(what) {
    return '<div class="p-empty"><i class="fa-solid fa-circle-notch p-spin" aria-hidden="true"></i>'
         + '<p>' + esc(what) + '</p></div>';
  }
  function empty(icon, text) {
    return '<div class="p-empty"><i class="fa-solid fa-' + esc(icon) + '" aria-hidden="true"></i>'
         + '<p>' + text + '</p></div>';
  }
  function h1(title, sub) {
    return '<h1 class="p-h1">' + esc(title) + '</h1>'
         + (sub ? '<p class="p-meta p-lead">' + sub + '</p>' : '<div class="p-lead"></div>');
  }
  function backTo(screen, label) {
    return '<button type="button" class="p-back" data-screen="' + esc(screen) + '">'
         + '<i class="fa-solid fa-chevron-left" aria-hidden="true"></i>' + esc(label) + '</button>';
  }

  /* ── 1 · Dashboard ─────────────────────────────────────────────────────── */

  function renderDashboard() {
    var d = S.dash;
    if (!d) { paint(loading('Loading your children…')); return; }
    var out = [];

    /* "Action required" — money at the top, because it has a deadline. The card
       states the total and then names each child's part, exactly as the design
       does: a household needs to know the figure AND what it is made of. */
    if (d.owed && d.owed.length) {
      d.owed.forEach(function (o) {
        var parts = (d.needs_you || []).filter(function (n) { return n.kind === 'money'; });
        out.push('<section class="p-card is-urgent">'
          + '<h2 class="p-label is-bad">Action required</h2>'
          + '<div class="p-fig p-money">' + esc(o.display) + '</div>'
          + '<div class="p-body">' + parts.map(function (p) {
              return esc(p.title) + ' · ' + esc(p.detail);
            }).join('<br>') + '</div>'
          + '<button type="button" class="p-action is-pay" data-screen="finance">'
          + '<i class="fa-solid fa-lock" aria-hidden="true"></i>'
          + (S.children.length > 1 ? 'Pay for your children' : 'Go to Finance') + '</button>'
          + '</section>');
      });
    }

    /* "This week" — a report released, a consent to decide, the next class. */
    var week = [];
    (d.this_week || []).forEach(function (w) {
      week.push('<button type="button" class="p-row is-tall" data-screen="' + esc(w.goto)
        + '" data-child="' + esc(w.student_id) + '">'
        + '<i class="fa-solid fa-' + esc(w.icon) + ' p-row-ico is-accent" aria-hidden="true"></i>'
        + '<span class="p-row-main"><span class="p-title">' + esc(w.title) + '</span>'
        + '<span class="p-caption">' + esc(w.detail) + '</span></span>'
        + '<i class="fa-solid fa-chevron-right p-row-go" aria-hidden="true"></i></button>');
    });
    (d.needs_you || []).filter(function (n) { return n.kind === 'consent'; }).forEach(function (n) {
      week.push('<button type="button" class="p-row is-tall" data-screen="consents"'
        + ' data-child="' + esc(n.student_id) + '">'
        + '<i class="fa-solid fa-file-signature p-row-ico is-warn" aria-hidden="true"></i>'
        + '<span class="p-row-main"><span class="p-title">' + esc(n.title) + '</span>'
        + '<span class="p-caption">' + esc(n.detail) + '</span></span>'
        + '<i class="fa-solid fa-chevron-right p-row-go" aria-hidden="true"></i></button>');
    });
    var nextClasses = (d.children || []).filter(function (c) { return c.next_class; });
    if (nextClasses.length) {
      var subParts = [];
      if (nextClasses[0].next_class.room) subParts.push(esc(nextClasses[0].next_class.room));
      nextClasses.slice(1).forEach(function (c) {
        subParts.push(esc(c.student_name) + ', ' + esc(fmtDateTime(c.next_class.at)));
      });
      week.push('<div class="p-row is-tall">'
        + '<i class="fa-solid fa-calendar-day p-row-ico" aria-hidden="true"></i>'
        + '<span class="p-row-main"><span class="p-title">'
        + esc(nextClasses[0].student_name) + ', ' + esc(fmtDateTime(nextClasses[0].next_class.at))
        + '</span><span class="p-caption">' + subParts.join(' · ') + '</span></span></div>');
    }
    if (week.length) {
      out.push('<section class="p-card is-flush"><div class="p-card-head">'
        + '<h2 class="p-label">This week</h2></div><div class="p-rows">' + week.join('') + '</div></section>');
    }

    // The children, as one row into the Children screen.
    if (S.children.length) {
      var names = S.children.map(function (c) { return c.student_name; });
      var joined = names.length > 1
        ? names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1]
        : names[0];
      out.push('<button type="button" class="p-card p-row is-tall" data-screen="children">'
        + '<i class="fa-solid fa-children p-row-ico" aria-hidden="true"></i>'
        + '<span class="p-row-main"><span class="p-title">' + esc(joined) + '</span>'
        + '<span class="p-caption">Attendance, progress and reports</span></span>'
        + '<i class="fa-solid fa-chevron-right p-row-go" aria-hidden="true"></i></button>');
    }

    /* "What you cannot see, and why". The design's own panel, and it is not
       decoration: a boundary a family discovers by finding an empty screen reads
       as a bug or as concealment, while one stated in a sentence reads as the
       policy it is. */
    if (d.boundaries && d.boundaries.length) {
      out.push('<section class="p-card is-quiet">'
        + '<h2 class="p-title">What you can see, and what stays theirs</h2>'
        + '<div class="p-gap-t"></div>'
        + d.boundaries.map(function (b) {
            return '<p class="p-body">' + esc(b.why) + '</p>';
          }).join('<div class="p-gap-t"></div>')
        + '</section>');
    }

    if (!out.length) {
      out.push(empty('children', 'No children are linked to your account yet. The academy office links a '
        + 'child to you once their place is confirmed.'));
    }
    paint('<div class="p-stack">' + out.join('') + '</div>');
  }

  /* ── 2 · Children ──────────────────────────────────────────────────────── */

  function renderChildren() {
    if (!S.children.length) {
      paint(h1('My Children') + empty('children',
        'No children are linked to your account yet. The academy office links a child to you '
        + 'once their place is confirmed.'));
      return;
    }
    var cards = S.children.map(function (c) {
      var w = c.weekly || {}, band = w.mastery_band || {};

      /* The BAND, never a percentage. `mastery_band.band` is null below the
         coverage floor, and the tile then says "Not enough yet" — which is a
         different statement from a low score and must not be rendered as one. */
      var bandTile;
      if (band.band && band.band.label) {
        bandTile = '<div class="p-kpi"><b>' + esc(band.band.label) + '</b><span>band</span></div>';
      } else {
        bandTile = '<div class="p-kpi"><b class="is-none">Not enough yet</b><span>band</span></div>';
      }

      var att = w.attendance_rate;
      var attTile = '<div class="p-kpi"><b' + (att == null ? ' class="is-none"' : '') + '>'
        + (att == null ? '—' : Math.round(att) + '%') + '</b><span>attended</span></div>';

      var credits = c.credits_available;
      var credTile = '<div class="p-kpi"><b' + (credits == null ? ' class="is-none"' : '') + '>'
        + (credits == null ? '—' : credits) + '</b><span>credits left</span></div>';

      return '<section class="p-card">'
        + '<div class="p-who">'
        +   '<div class="p-avatar" aria-hidden="true">' + esc(initials(c.student_name)) + '</div>'
        +   '<div class="p-who-main"><div class="p-name">' + esc(c.student_name) + '</div>'
        +     '<div class="p-caption">'
        +     esc([c.course_name, c.level_name].filter(Boolean).join(', ') || c.legacy_class || (c.relationship || 'guardian'))
        +     (c.enrollment_ref ? '<br>Student number ' + esc(c.enrollment_ref) : '') + '</div></div>'
        + '</div>'
        + '<div class="p-kpis">' + attTile + bandTile + credTile + '</div>'
        + (band.headline ? '<p class="p-caption p-gap-t">' + esc(band.headline) + '</p>' : '')
        + '<button type="button" class="p-action" data-screen="reports" data-child="' + esc(c.student_id) + '">'
        + 'Open ' + esc(c.student_name) + '’s report</button>'
        + '<button type="button" class="p-action is-alt" data-screen="finance" data-child="' + esc(c.student_id) + '">'
        + 'Fees for ' + esc(c.student_name) + '</button>'
        + '</section>';
    });
    paint(h1('My Children') + '<div class="p-stack">' + cards.join('') + '</div>');
  }

  /* ── 3 · Reports ───────────────────────────────────────────────────────── */

  function renderReports() {
    var c = selectedChild();
    if (!c) { paint(h1('Termly report') + empty('file-lines', 'No children are linked to your account yet.')); return; }
    paint(backTo('children', 'My Children') + loading('Loading the report…'));

    API.request('parent/reports?student_id=' + encodeURIComponent(c.student_id), { method: 'GET' })
      .then(function (r) {
        if (r.scope_denied) {
          paint(backTo('children', 'My Children') + h1('Termly report')
            + empty('lock', 'Your access for ' + esc(c.student_name) + ' is set to <strong>billing only</strong>, '
              + 'which covers invoices and receipts. The academy office can widen it.'));
          return;
        }
        if (!r.reports.length) {
          paint(backTo('children', 'My Children') + h1('Termly report')
            + empty('hourglass-half', 'No report has been published for ' + esc(c.student_name) + ' yet. '
              + 'A report appears here <strong>only after the examinations panel has ratified it</strong> — '
              + 'a provisional mark you could see and then have changed would be worse than one that '
              + 'arrives a week later.'));
          return;
        }
        paint(backTo('children', 'My Children') + r.reports.map(reportCard).join('<div class="p-gap-t"></div>'));
      })
      .catch(function (err) {
        paint(backTo('children', 'My Children')
          + empty('triangle-exclamation', esc(P.errText ? P.errText(err, 'Could not load the report.') : 'Could not load the report.')));
      });
  }

  function reportCard(rep) {
    /* An unevidenced competency is VISIBLE and NAMED, never a silent zero and
       never dropped — dropping it would let a reader assume the report covers
       everything. It is not eight separate rows, though: ⚠️ MEASURED at 390,
       repeating "First assessed next term" under each of them filled 563px of
       a 1,788px report — 31% of the page — and buried the eight names it
       exists to show. The caveat is stated ONCE, under the set. The student's
       "Where I stand" shows the same competencies the same way, which is why
       `.p-none` is a shared primitive rather than either portal's own. */
    var unmarked = (rep.criteria || []).filter(function (c) { return c.level === 'insufficient'; });
    var crit = (rep.criteria || []).filter(function (c) { return c.level !== 'insufficient'; }).map(function (c) {
      /* Clamped and ROUNDED to the integer the `[data-pct="N"]` table in
         portal-v2.css matches on. This used to be applied as
         `el.style.width` after paint; a `style` attribute is banned in this
         repo, and a length belongs in the stylesheet even when the number
         reaching it is data. A fractional value matches no rule and draws an
         empty track, which is why the rounding is here. */
      var pct = Math.max(0, Math.min(100, Math.round(Number(c.pct) || 0)));
      return '<div class="p-crit">'
        + '<div class="p-crit-head"><span class="p-crit-name">' + esc(c.label) + '</span>'
        + '<span class="p-crit-grade">' + esc(c.grade_label || '') + '</span></div>'
        + '<div class="p-bar" role="img" aria-label="' + esc(c.label) + ': ' + esc(c.grade_label || '')
        + ', ' + pct + ' per cent"><span class="is-' + esc(c.level) + '" data-pct="' + pct + '"></span></div>'
        + '</div>';
    }).join('');

    if (unmarked.length) {
      crit += '<div class="p-crit p-insufficient">'
        + '<div class="p-crit-head"><span class="p-crit-name">Not assessed yet</span>'
        + '<span class="p-crit-grade">' + unmarked.length + '</span></div>'
        + '<p class="p-caption">First assessed next term. None of these is counted as a zero.</p>'
        + '<div class="p-none-list p-gap-t">' + unmarked.map(function (c) {
            return '<span class="p-none">' + esc(c.label) + '</span>';
          }).join('') + '</div></div>';
    }

    var cov = rep.coverage || {};
    var legend = rep.legend || {};

    return '<section class="p-stack">'
      + '<div>'
      +   '<h1 class="p-h1">Termly report</h1>'
      +   '<p class="p-meta p-lead">' + esc(rep.student_name)
      +   (rep.student_number ? ' · ' + esc(rep.student_number) : '') + ' · ' + esc(rep.term_label) + '<br>'
      +   (rep.ratified_by
            ? 'Ratified by ' + esc(rep.ratified_by) + ', ' + esc(fmtDate(rep.ratified_at))
            : 'Ratified ' + esc(fmtDate(rep.ratified_at))) + '</p>'
      + '</div>'

      + '<div class="p-card">'
      +   '<h2 class="p-label">' + esc([rep.course_name, rep.level_name].filter(Boolean).join(' · ') || 'Overall')
      +   (rep.level_equiv ? ' (≈ ' + esc(rep.level_equiv) + ')' : '') + '</h2>'
      +   '<div class="p-gap-t"></div>' + crit
      +   '<div class="p-card-foot">'
      +     '<h3 class="p-label">Grading legend</h3>'
      +     '<div class="p-legend p-gap-t">' + (legend.grades || []).map(esc).join(' · ') + '<br>'
      +       (rep.band
              ? 'Overall band <b>' + esc(rep.band.label) + '</b> from ' + esc(cov.evidenced) + ' of '
                + esc(cov.total) + ' assessed parts (' + esc(cov.weight_pct) + '% of the weight).'
              : 'No overall band yet: ' + esc(cov.evidenced) + ' of ' + esc(cov.total)
                + ' parts assessed (' + esc(cov.weight_pct) + '% of the weight), which is not enough to '
                + 'report one honestly.')
      +       '<br>' + esc(legend.note || '') + '</div>'
      +   '</div>'
      + '</div>'

      + (rep.comment
          ? '<div class="p-card"><h2 class="p-label">Instructor’s comment</h2>'
            + '<p class="p-prose p-gap-t">' + esc(rep.comment) + '</p>'
            + '<p class="p-caption p-gap-t">' + esc(rep.instructor || 'Instructor')
            + (rep.attendance ? ' · ' + esc(rep.attendance) : '') + '</p></div>'
          : '')

      + '<div class="p-card is-flush"><div class="p-rows">'
      +   '<button type="button" class="p-row" data-print="1">'
      +     '<i class="fa-solid fa-print p-row-ico is-bad" aria-hidden="true"></i>'
      +     '<span class="p-row-main"><span class="p-title">Print or save this report</span></span>'
      +   '</button>'
      +   '<button type="button" class="p-row" data-ask="' + esc(rep.student_id) + '">'
      +     '<i class="fa-solid fa-comments p-row-ico is-good" aria-hidden="true"></i>'
      +     '<span class="p-row-main"><span class="p-title">Ask about this report</span></span>'
      +     '<i class="fa-solid fa-chevron-right p-row-go" aria-hidden="true"></i>'
      +   '</button>'
      +   (rep.appeal && rep.appeal.open
          ? '<button type="button" class="p-row" data-appeal="' + esc(rep.id) + '">'
            + '<i class="fa-solid fa-scale-balanced p-row-ico" aria-hidden="true"></i>'
            + '<span class="p-row-main"><span class="p-title">Appeal a mark</span></span>'
            + '<span class="p-caption">' + esc(rep.appeal.days_left) + ' '
            + plural(rep.appeal.days_left, 'day', 'days') + ' left</span>'
            + '<i class="fa-solid fa-chevron-right p-row-go" aria-hidden="true"></i></button>'
          : '<div class="p-row"><i class="fa-solid fa-scale-balanced p-row-ico" aria-hidden="true"></i>'
            + '<span class="p-row-main"><span class="p-title">Appeal a mark</span>'
            + '<span class="p-caption">The three-month window has closed. The office can still help.</span>'
            + '</span></div>')
      + '</div></div>'

      + ((rep.my_appeals || []).length
          ? '<div class="p-card"><h2 class="p-label">Your appeals on this report</h2>'
            + '<div class="p-gap-t"></div>'
            + rep.my_appeals.map(function (a) {
                return '<div class="p-line"><span>' + esc(fmtDate(a.opened_at)) + ' · ' + esc(a.reason) + '</span>'
                  + '<span class="p-chip ' + (a.status === 'upheld' ? 'is-good' : a.status === 'open' ? 'is-warn' : '')
                  + '">' + esc(a.status) + '</span></div>'
                  + (a.outcome_note ? '<p class="p-caption">' + esc(a.outcome_note) + '</p>' : '');
              }).join('')
            + '</div>'
          : '')
      + '</section>';
  }

  /* ── 4 · Finance ───────────────────────────────────────────────────────── */

  function renderFinance() {
    var c = selectedChild();
    if (!c) { paint(h1('Finance') + empty('credit-card', 'No children are linked to your account yet.')); return; }
    paint(backTo('children', 'My Children') + h1('Finance', 'Statement of account')
      + loading('Loading the statement…'));

    API.request('parent/fees?student_id=' + encodeURIComponent(c.student_id), { method: 'GET' })
      .then(function (r) {
        var out = [];
        S.bank = r.bank || null;

        // Due now — the design's own card, with each stage of the arithmetic.
        var live = (r.invoices || []).filter(function (i) {
          return i.status === 'issued' || i.status === 'part_paid';
        });
        if (live.length) {
          live.forEach(function (inv) { out.push(invoiceCard(inv)); });
        } else {
          out.push('<div class="p-card is-quiet"><h2 class="p-label">Due now</h2>'
            + '<div class="p-fig p-money-lg p-gap-t">' + esc(r.owed || '—') + '</div>'
            + '<p class="p-body">Nothing is outstanding. Thank you.</p></div>');
        }

        // Receipts.
        if ((r.receipts || []).length) {
          out.push('<section class="p-card is-flush"><div class="p-card-head">'
            + '<h2 class="p-label">Receipts</h2></div><div class="p-rows">'
            + r.receipts.map(function (p) {
                return '<div class="p-row"><span class="p-row-main">'
                  + '<span class="p-title p-num">' + esc(p.amount) + '</span>'
                  + '<span class="p-caption">' + esc(c.student_name) + ' · ' + esc(p.purpose)
                  + ' · ' + esc(fmtDate(p.at))
                  + (p.charged ? ' · charged ' + esc(p.charged) : '')
                  + (p.unpriced ? ' · not converted' : '') + '</span></span>'
                  + '<span class="p-chip is-good">Paid</span></div>';
              }).join('')
            + '</div></section>');
        }

        // The refund rule that applies today, and the assurance.
        if (r.refund_rule) {
          out.push('<section class="p-card is-quiet"><h2 class="p-title">If you withdraw a child</h2>'
            + '<p class="p-body p-gap-t">Refunds follow the published week-by-week table. '
            + (r.refund_rule.percent != null
                ? 'Today, ' + (r.refund_rule.week ? 'week ' + esc(r.refund_rule.week) + ', ' : '')
                  + 'a withdrawal would refund <strong>' + esc(r.refund_rule.percent) + '%</strong>. '
                : '')
            + 'Lagos time decides the date.</p></section>');
        }
        out.push('<div class="p-notice is-good"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i>'
          + '<span>' + esc(r.assurance || '') + '</span></div>');
        if (r.dunning) {
          out.push('<p class="p-caption">Reminders go out on the due date and again after '
            + esc((r.dunning.reminder_days || []).slice(1).join(' and ')) + ' days, then there is a '
            + esc(r.dunning.grace_days) + '-day grace window. After that the next step is '
            + esc(r.dunning.next_step) + ' — never a hold on your child’s lessons.</p>');
        }

        paint(backTo('children', 'My Children')
          + h1('Finance', 'Statement of account for ' + esc(c.student_name))
          + '<div class="p-stack">' + out.join('') + '</div>');
      })
      .catch(function (err) {
        paint(h1('Finance')
          + empty('triangle-exclamation', esc(P.errText ? P.errText(err, 'Could not load the statement.') : 'Could not load the statement.')));
      });
  }

  function invoiceCard(inv) {
    var lines = (inv.lines || []).map(function (l) {
      return '<div class="p-line"><span>' + esc(l.label) + '</span><b>' + esc(l.amount) + '</b></div>'
        + (l.waiver
            ? '<div class="p-line"><span>&nbsp;&nbsp;waived · ' + esc(l.waiver_reason || '') + '</span>'
              + '<b class="is-credit">−' + esc(l.waiver) + '</b></div>'
            : '');
    }).join('');
    var credits = (inv.credits || []).map(function (cr) {
      return '<div class="p-line"><span>' + esc(cr.kind === 'bursary' ? 'Bursary' : 'Scholarship')
        + '</span><b class="is-credit">−' + esc(cr.amount) + '</b></div>';
    }).join('');
    var sibling = inv.sibling
      ? '<div class="p-line"><span>Sibling discount</span><b class="is-credit">−' + esc(inv.sibling) + '</b></div>'
      : '';

    var overdue = inv.overdue_days != null;
    return '<section class="p-card' + (overdue ? ' is-urgent' : '') + '">'
      + '<h2 class="p-label' + (overdue ? ' is-bad' : '') + '">'
      + (overdue ? 'Overdue ' + esc(inv.overdue_days) + ' ' + plural(inv.overdue_days, 'day', 'days') : 'Due now')
      + '</h2>'
      + '<div class="p-fig p-money-lg">' + esc(inv.due) + '</div>'
      + '<p class="p-caption p-lead">' + esc(inv.ref) + ' · ' + esc(inv.term_label)
      + (inv.due_on ? ' · due ' + esc(fmtDate(inv.due_on)) : '') + '</p>'
      + lines + credits + sibling
      + '<div class="p-line"><span class="p-strong">Total</span><b>' + esc(inv.total) + '</b></div>'

      /* The charge, and the two rates kept apart. The FROZEN rate is what an audit
         reconciles against; the LIVE one is what the gateway is about to take, and
         it is labelled an estimate because the rate it settles at is the payment's
         own. Conflating them is how a May statement disagrees with itself. */
      + (inv.charge
          ? '<div class="p-notice p-gap-t"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>'
            + '<span>You will be charged <strong>' + esc(inv.charge.amount) + '</strong>. The '
            + esc(inv.due) + ' is the amount owed; this figure uses today’s rate of '
            + esc(inv.charge.rate_label) + ' and is an estimate until the payment clears.'
            /* ⚠️ Only when the two rates DIFFER. The frozen rate is what the
               invoice was issued at and today's is what the estimate uses;
               when they are the same the sentence printed "₦1,500 to $1"
               twice in four lines, which reads as a fault rather than as
               reassurance. */
            + (inv.frozen_rate_label && inv.frozen_rate_label !== inv.charge.rate_label
                ? ' This invoice was issued at ' + esc(inv.frozen_rate_label) + '.' : '')
            + '</span></div>'
          : '')

      /* A transfer the family reported is still being checked: say so ABOVE the
         Pay button, or the likeliest next thing is the same money sent twice. */
      + ((inv.claims || []).some(function (k) { return k.status === 'pending'; })
          ? '<div class="p-notice p-gap-t"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i><span>You reported a transfer for this invoice. '
            + 'The office is checking it against the bank statement — please wait for that before paying again.</span></div>'
          : '')
      + '<button type="button" class="p-action is-pay" data-pay="' + esc(inv.id) + '">'
      + '<i class="fa-solid fa-lock" aria-hidden="true"></i>Pay '
      + esc(inv.charge ? inv.charge.amount : inv.due) + '</button>'
      + '<div class="p-methods"><span>Card</span><span>Transfer</span><span>USSD</span></div>'
      + (inv.plan
          ? '<div class="p-card-foot"><h3 class="p-label">' + (inv.plan.approved === false
                ? 'Payment plan requested — the office will confirm the dates' : 'Your payment plan') + '</h3>'
            + inv.plan.schedule.map(function (i) {
                return '<div class="p-line"><span>Instalment ' + esc(i.seq) + ' · ' + esc(fmtDate(i.due_on))
                  + '</span><b>' + esc(i.amount) + '</b>'
                  + (i.status === 'paid' ? ' <span class="p-chip is-good">Paid</span>' : '') + '</div>';
              }).join('')
            /* Only an AGREED plan offers its next instalment: a requested one is
               a proposal, and paying against it would read as the office having
               accepted dates it has not. Never more than what is owed — the
               server refuses that too, but the button should not offer it. */
            + (function () {
                if (inv.plan.approved !== true) return '';
                var next = inv.plan.schedule.filter(function (i) { return i.status !== 'paid'; })[0];
                if (!next || !(next.amount_minor > 0) || next.amount_minor >= inv.due_minor) return '';
                return '<button type="button" class="p-action is-alt" data-pay="' + esc(inv.id)
                  + '" data-amount="' + esc(next.amount_minor) + '">Pay instalment ' + esc(next.seq)
                  + ' · ' + esc(next.amount) + '</button>';
              })()
            + '</div>'
          : '<button type="button" class="p-action is-alt" data-plan="' + esc(inv.id) + '">'
            + 'Pay in 3 instalments instead</button>')
      + transferBlock(inv)
      + queryBlock(inv)
      + '</section>';
  }

  /* Question an invoice, propose a different amount or instalments, or use a
     discount code (fee-review.php). The office answers on this card; a
     counter-offer is accepted or declined here too. Reminders stop while a
     question is open, and the card says so. */
  var QUERY_WORDS = { open: 'Waiting for the office', answered: 'Answered', accepted: 'Agreed', countered: 'The office made an offer', declined: 'Not agreed', withdrawn: 'Withdrawn' };
  function queryBlock(inv) {
    var qs = inv.queries || [];
    var open = qs.some(function (q) { return q.status === 'open' || q.status === 'countered'; });
    var rows = qs.map(function (q) {
      return '<div class="p-row"><span class="p-row-main"><span class="p-title">' + esc(q.message) + '</span>'
        + '<span class="p-caption">' + (q.proposed ? 'You proposed ' + esc(q.proposed) + '. ' : '') + (q.instalments ? 'You proposed ' + esc(q.instalments) + ' instalments. ' : '')
        + (q.reply ? 'The office: ' + esc(q.reply) : '') + '</span>'
        + (q.status === 'countered'
            ? '<span class="p-caption"><strong>Offer: ' + (q.counter ? esc(q.counter) : '') + (q.counter_instalments ? ' ' + esc(q.counter_instalments) + ' instalments' : '') + '</strong></span>'
              + '<span class="p-actions"><button type="button" class="p-action" data-qrespond="' + esc(q.id) + '" data-accept="1">Accept the offer</button>'
              + '<button type="button" class="p-action p-outline" data-qrespond="' + esc(q.id) + '" data-accept="0">Decline</button></span>'
            : '') + '</span>'
        + '<span class="p-chip' + (q.status === 'accepted' ? ' is-good' : '') + '">' + esc(QUERY_WORDS[q.status] || q.status) + '</span></div>';
    }).join('');
    return '<div class="p-card-foot">'
      + (rows ? '<h3 class="p-label">What you asked</h3><div class="p-rows">' + rows + '</div>' : '')
      + (open ? '<p class="p-caption">Reminders about this invoice are paused until the office has answered.</p>'
        : '<details class="p-gap-t"><summary class="p-label">Question this invoice</summary>'
          + '<div class="p-field p-gap-t"><label for="qk' + inv.id + '">What would you like?</label><select id="qk' + inv.id + '">'
          + '<option value="question">To ask a question</option><option value="amount">To pay a different amount</option><option value="plan">To pay in instalments</option></select></div>'
          + '<div class="p-field"><label for="qm' + inv.id + '">Tell the office</label><textarea id="qm' + inv.id + '" maxlength="3000"></textarea></div>'
          + '<div class="p-field"><label for="qa' + inv.id + '">Total you propose (if a different amount)</label><input id="qa' + inv.id + '" inputmode="decimal"></div>'
          + '<div class="p-field"><label for="qi' + inv.id + '">Instalments (2 to 6, if paying in parts)</label><input id="qi' + inv.id + '" type="number" min="2" max="6"></div>'
          + '<button type="button" class="p-action p-outline" data-qsend="' + esc(inv.id) + '">Send to the office</button></details>')
      + '<details class="p-gap-t"><summary class="p-label">Have a discount code?</summary>'
      + '<div class="p-field p-gap-t"><label for="qc' + inv.id + '">Code</label><input id="qc' + inv.id + '" maxlength="24" autocapitalize="characters"></div>'
      + '<button type="button" class="p-action p-outline" data-qcode="' + esc(inv.id) + '">Use the code</button></details>'
      + '</div>';
  }

  /* "I paid by bank transfer." Most families pay this way, and the bank details
     used to say "email the proof" with nothing on the screen to show it had
     arrived. The report is a CLAIM: nothing is credited until the office has
     seen the money on the statement, and the card says which state it is in. */
  var CLAIM_WORDS = { pending: 'Being checked', verified: 'Received', rejected: 'Not confirmed' };
  var CLAIM_SAYS  = { pending: 'The office is checking it against the bank statement.', verified: 'On the account.', rejected: '' };
  function transferBlock(inv) {
    var claims = inv.claims || [];
    /* The receipts list's row shape, which wraps: a .p-line put the chip beside
       the sentence and squeezed the sentence into a column one word wide. */
    var rows = claims.map(function (k) {
      return '<div class="p-row"><span class="p-row-main">'
        + '<span class="p-title p-num">' + esc(k.amount) + '</span>'
        + '<span class="p-caption">Transfer sent ' + esc(fmtDate(k.paid_on)) + '. '
        + esc(k.status === 'rejected' ? (k.reject_reason || '') : (CLAIM_SAYS[k.status] || '')) + '</span></span>'
        + '<span class="p-chip' + (k.status === 'verified' ? ' is-good' : k.status === 'rejected' ? ' is-bad' : '') + '">'
        + esc(CLAIM_WORDS[k.status] || k.status) + '</span></div>';
    }).join('');
    var waiting = claims.some(function (k) { return k.status === 'pending'; });
    return (rows ? '<div class="p-card-foot"><h3 class="p-label">Transfers you reported</h3><div class="p-rows">' + rows + '</div></div>' : '')
      + (waiting ? ''
         : '<div data-transfer-slot="' + esc(inv.id) + '"><button type="button" class="p-action p-outline" data-transfer-open="'
           + esc(inv.id) + '" data-due="' + esc(inv.due_minor) + '">I paid by bank transfer</button></div>');
  }

  function transferForm(invId, dueMinor) {
    var b = S.bank || {};
    var today = new Date().toISOString().slice(0, 10);
    var due = (Number(dueMinor) / 100).toFixed(2);
    return '<div class="p-card-foot"><h3 class="p-label">Tell us about your transfer</h3>'
      + (b.account_number ? '<p class="p-caption p-gap-t">The academy\u2019s account: ' + esc(b.account_name) + ' · '
          + esc(b.bank) + ' · <span class="p-num">' + esc(b.account_number) + '</span></p>' : '')
      + '<div class="p-field p-gap-t"><label for="tfAmt' + invId + '">Amount you sent</label>'
      + '<input id="tfAmt' + invId + '" inputmode="decimal" value="' + esc(due) + '"></div>'
      + '<div class="p-field"><label for="tfOn' + invId + '">Date of the transfer</label>'
      + '<input id="tfOn' + invId + '" type="date" max="' + today + '" value="' + today + '"></div>'
      + '<div class="p-field"><label for="tfRef' + invId + '">Transaction reference or session ID, from the slip or the bank alert</label>'
      + '<input id="tfRef' + invId + '" autocomplete="off"></div>'
      + '<div class="p-field"><label for="tfBank' + invId + '">Bank you sent it from</label>'
      + '<input id="tfBank' + invId + '" autocomplete="off"></div>'
      + '<div class="p-field"><label for="tfName' + invId + '">Name on that account</label>'
      + '<input id="tfName' + invId + '" autocomplete="name"></div>'
      + '<div class="p-field"><label for="tfFile' + invId + '">Photo or PDF of the slip, receipt or alert</label>'
      + '<input id="tfFile' + invId + '" type="file" accept="image/jpeg,image/png,image/webp,application/pdf"></div>'
      + '<div class="p-field"><label for="tfNote' + invId + '">Anything the office should know (optional)</label>'
      + '<input id="tfNote' + invId + '" autocomplete="off"></div>'
      + '<p class="p-caption">We check it against the bank statement, usually within a day, and email you either way. '
      + 'Nothing about your child\u2019s lessons or report waits on it.</p>'
      + '<button type="button" class="p-action" data-transfer-send="' + esc(invId) + '">Send to the office</button></div>';
  }

  /* ── 5 · Consents ──────────────────────────────────────────────────────── */

  function renderConsents() {
    var c = selectedChild();
    if (!c) { paint(h1('Consents & forms') + empty('file-signature', 'No children are linked to your account yet.')); return; }
    if (!allows(c, 'consents')) {
      paint(h1('Consents & forms')
        + empty('lock', 'Consents are held by the guardian with <strong>Full access</strong> for '
          + esc(c.student_name) + '. The academy office can change who that is.'));
      return;
    }
    paint(h1('Consents & forms',
      'What you have agreed to, and when. You can withdraw any of these at any time.')
      + loading('Loading consents…'));

    API.request('parent/child?student_id=' + encodeURIComponent(c.student_id), { method: 'GET' })
      .then(function (d) {
        var con = d.consent || {};
        var items = [
          { key: 'photo', icon: 'camera', title: 'Photography at academy events',
            blurb: 'Photographs of ' + esc(c.student_name) + ' performing, used in the academy’s own '
                 + 'newsletter and website. Not shared with anyone else and not used for advertising.',
            note: 'Saying no changes nothing else about the event — ' + esc(c.student_name) + ' still performs.' },
          { key: 'media', icon: 'video', title: 'Recording online lessons',
            blurb: 'Recordings are kept for the term and seen only by ' + esc(c.student_name)
                 + ', you, and their instructor.',
            note: '' },
          { key: 'data', icon: 'shield-halved', title: 'Holding ' + esc(c.student_name) + '’s personal data',
            blurb: 'Reconfirmed each session while they are under 18.',
            note: '' }
        ];
        var cards = items.map(function (it) {
          var v = con[it.key];
          var decided = v === true || v === 1 || v === false || v === 0;
          var yes = v === true || v === 1;
          return '<section class="p-card' + (decided ? '' : ' is-urgent') + '">'
            + '<div class="p-who"><i class="fa-solid fa-' + esc(it.icon)
            +   ' p-row-ico ' + (decided ? (yes ? 'is-good' : '') : 'is-bad') + '" aria-hidden="true"></i>'
            +   '<span class="p-who-main"><span class="p-title">' + it.title + '</span></span></div>'
            + '<p class="p-body p-gap-t">' + it.blurb + '</p>'
            + (decided
                ? '<p class="p-caption p-gap-t">' + (yes ? 'Agreed' : 'Declined')
                  + (con.updated_at ? ' ' + esc(fmtDate(con.updated_at)) : '') + '.</p>'
                  + '<button type="button" class="p-ghost p-gap-t" data-consent="' + esc(it.key)
                  + '" data-consent-value="' + (yes ? '0' : '1') + '">'
                  + (yes ? 'Withdraw this' : 'Change to yes') + '</button>'
                : '<div class="p-choice p-gap-t">'
                  + '<button type="button" class="p-action" data-consent="' + esc(it.key) + '" data-consent-value="1">I agree</button>'
                  + '<button type="button" class="p-action p-outline" data-consent="' + esc(it.key) + '" data-consent-value="0">No, thank you</button>'
                  + '</div>')
            + (it.note ? '<p class="p-caption p-gap-t">' + it.note + '</p>' : '')
            + '</section>';
        });

        cards.push('<section class="p-card"><div class="p-who">'
          + '<i class="fa-solid fa-file-contract p-row-ico" aria-hidden="true"></i>'
          + '<span class="p-who-main"><span class="p-title">Enrolment agreement</span></span></div>'
          + '<p class="p-body p-gap-t">The fee schedule, the refund table and the complaints route. '
          + 'The safeguarding policy and the academy’s Designated Safeguarding Officer are named in it.</p>'
          + '<button type="button" class="p-ghost p-gap-t" data-screen="safeguarding">Who to tell</button>'
          + '</section>');

        paint(h1('Consents & forms',
          'What you have agreed to, and when. You can withdraw any of these at any time.')
          + '<div class="p-stack-sm">' + cards.join('') + '</div>');
      })
      .catch(function (err) {
        paint(h1('Consents & forms')
          + empty('triangle-exclamation', esc(P.errText ? P.errText(err, 'Could not load consents.') : 'Could not load consents.')));
      });
  }

  /* ── 6 · Messages ──────────────────────────────────────────────────────── */

  function renderMessages() {
    paint(h1('Messages') + loading('Loading your messages…'));
    API.request('parent/threads', { method: 'GET' }).then(function (r) {
      var out = [];
      if ((r.threads || []).length) {
        r.threads.forEach(function (t) {
          out.push('<button type="button" class="p-card is-thread" data-thread="' + esc(t.id) + '">'
            + '<div class="p-line"><span class="p-title">' + esc(t.last ? t.last.author : t.subject) + '</span>'
            + '<span class="p-caption">' + esc(fmtDate(t.at)) + '</span></div>'
            + '<p class="p-body p-gap-t">' + esc(t.last ? t.last.body : t.subject) + '</p>'
            + '<p class="p-caption">' + esc(t.subject) + ' · about ' + esc(t.student_name)
            + (t.unread ? ' · <span class="p-chip is-accent">New</span>' : '') + '</p>'
            + '</button>');
        });
      } else {
        out.push(empty('comments', 'No messages yet. When an instructor or the office writes to you '
          + 'about one of your children, it will appear here.'));
      }

      out.push('<button type="button" class="p-action" data-new-thread="1">'
        + '<i class="fa-solid fa-pen" aria-hidden="true"></i>Write to the academy</button>');

      // How we reach you, and the sentence that makes the toggles safe.
      var ch = r.channels || {};
      out.push('<section class="p-card is-quiet"><h2 class="p-title">How we reach you</h2>'
        + '<div class="p-gap-t"></div>'
        + ['whatsapp', 'email', 'sms'].map(function (k) {
            var on = !!ch[k];
            var label = k === 'whatsapp' ? 'WhatsApp' : k === 'sms' ? 'SMS' : 'Email';
            return '<button type="button" class="p-switch" role="switch" aria-checked="' + (on ? 'true' : 'false')
              + '" data-channel="' + k + '"><span>' + label + '</span>'
              + '<span class="p-track" aria-hidden="true"></span></button>';
          }).join('')
        + '<p class="p-caption p-gap-t">Anything about safeguarding or money reaches you on every '
        + 'channel we can use, whatever these settings say.</p>'
        + '</section>');

      paint(h1('Messages') + '<div class="p-stack-sm">' + out.join('') + '</div>');
    }).catch(function (err) {
      paint(h1('Messages')
        + empty('triangle-exclamation', esc(P.errText ? P.errText(err, 'Could not load your messages.') : 'Could not load your messages.')));
    });
  }

  function renderThread(id) {
    paint(backTo('messages', 'Messages') + loading('Loading the conversation…'));
    API.request('parent/threads?id=' + encodeURIComponent(id), { method: 'GET' }).then(function (r) {
      var t = r.thread;
      paint(backTo('messages', 'Messages')
        + h1(t.subject, 'About ' + esc(t.student_name)
            + ' · ' + esc(t.participants.map(function (p) { return p.name; }).join(', ')))
        + '<div class="p-stack-sm">'
        + t.messages.map(function (m) {
            return '<div class="p-card' + (m.author_role === 'guardian' ? ' is-quiet' : ' is-thread') + '">'
              + '<div class="p-line"><span class="p-title">' + esc(m.author_name) + '</span>'
              + '<span class="p-caption">' + esc(fmtDateTime(m.created_at)) + '</span></div>'
              + '<p class="p-body p-gap-t">' + esc(m.body) + '</p></div>';
          }).join('')
        + (t.status === 'open'
            ? '<div class="p-card"><label class="p-field" for="replyBody">'
              + '<span class="p-sr">Your reply</span></label>'
              + '<div class="p-field"><label for="replyBody">Your reply</label>'
              + '<textarea id="replyBody" data-reply-for="' + esc(t.id) + '"></textarea></div>'
              + '<button type="button" class="p-action" data-reply="' + esc(t.id) + '">Send</button></div>'
            : '<div class="p-notice"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>'
              + '<span>This conversation has been closed. The office can reopen it.</span></div>')
        + '</div>');
    }).catch(function (err) {
      paint(backTo('messages', 'Messages')
        + empty('triangle-exclamation', esc(P.errText ? P.errText(err, 'Could not load the conversation.') : 'Could not load the conversation.')));
    });
  }

  /* ── More-sheet destinations ────────────────────────────────────────────── */

  function renderMakeup() {
    var c = selectedChild();
    if (!c) { paint(h1('Make-up lessons') + empty('ticket', 'No children are linked to your account yet.')); return; }
    paint(h1('Make-up lessons') + loading('Loading credits…'));
    API.request('parent/makeup?student_id=' + encodeURIComponent(c.student_id), { method: 'GET' })
      .then(function (r) {
        if (r.scope_denied) {
          paint(h1('Make-up lessons')
            + empty('lock', 'Your access for ' + esc(c.student_name) + ' is set to billing only. '
              + 'Make-up lessons are part of the timetable.')
            + '<div class="p-gap-t"></div>' + policyCard(r.policy));
          return;
        }
        paint(h1('Make-up lessons', 'For ' + esc(c.student_name))
          + '<div class="p-stack">'
          + '<section class="p-card"><div class="p-kpis">'
          +   '<div class="p-kpi"><b>' + esc(r.available) + '</b><span>available now</span></div>'
          +   '<div class="p-kpi"><b>' + esc(r.allowance_left) + '</b><span>of your ' + esc(r.allowance) + '</span></div>'
          +   '<div class="p-kpi"><b>' + esc(r.academy_credits) + '</b><span>from us</span></div>'
          + '</div>'
          + '<p class="p-caption p-gap-t">The middle figure counts only the lessons <strong>you</strong> '
          + 'cancelled. Anything we cancelled is the third figure and never comes out of your allowance.</p>'
          + ((r.credits || []).length
              ? '<div class="p-card-foot">' + r.credits.map(function (cr) {
                  return '<div class="p-line"><span>' + esc(cr.reason.replace(/_/g, ' ')) + '</span>'
                    + '<span class="p-caption">expires ' + esc(fmtDate(cr.expires_on)) + '</span></div>';
                }).join('') + '</div>'
              : '<p class="p-body p-gap-t">No credits at the moment.</p>')
          + '</section>'
          + policyCard(r.policy) + '</div>');
      })
      .catch(function (err) {
        paint(h1('Make-up lessons')
          + empty('triangle-exclamation', esc(P.errText ? P.errText(err, 'Could not load credits.') : 'Could not load credits.')));
      });
  }

  function policyCard(pol) {
    if (!pol) return '';
    return '<section class="p-card is-quiet"><h2 class="p-title">The rules, in full</h2>'
      + '<div class="p-gap-t"></div>'
      + (pol.rules || []).map(function (r) { return '<p class="p-body">· ' + esc(r) + '</p>'; }).join('')
      + '</section>';
  }

  function renderBursary() {
    var c = selectedChild();
    if (!c) { paint(h1('Apply for a bursary') + empty('hand-holding-dollar', 'No children are linked to your account yet.')); return; }
    paint(h1('Apply for a bursary') + loading('Loading…'));
    API.request('parent/bursary?student_id=' + encodeURIComponent(c.student_id), { method: 'GET' })
      .then(function (r) {
        var open = (r.applications || []).filter(function (a) {
          return a.status === 'submitted' || a.status === 'assessing';
        })[0];

        var body = '<div class="p-notice is-good"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>'
          + '<span>' + esc(r.assurance || '') + '</span></div>';

        if (open) {
          body += '<section class="p-card"><h2 class="p-label">Your application</h2>'
            + '<p class="p-body p-gap-t">Submitted for ' + esc(c.student_name) + ' on the '
            + esc(open.band_label) + ' band. Status: <strong>' + esc(open.status) + '</strong>.</p>'
            + '<p class="p-caption p-gap-t">We will write to you when the committee has decided.</p></section>';
        } else {
          body += '<section class="p-card">'
            + '<h2 class="p-title">Which band describes your household?</h2>'
            + '<p class="p-body p-gap-t">We ask for a band rather than a written account of your '
            + 'circumstances. The table below is published, so two families who declare the same '
            + 'thing get the same answer.</p>'
            + '<div class="p-field p-gap-t"><label for="bBand">Monthly household income</label>'
            + '<select id="bBand">'
            + (r.bands || []).map(function (b) {
                return '<option value="' + esc(b.code) + '">' + esc(b.label)
                  + (b.indicative_pct ? ' — up to ' + esc(b.indicative_pct) + '% off' : '') + '</option>';
              }).join('')
            + '</select></div>'
            + '<div class="p-field"><label for="bHouse">People in your household (optional)</label>'
            + '<input id="bHouse" type="number" min="1" max="20" inputmode="numeric"></div>'
            + '<div class="p-field"><label for="bDeps">Children of yours at the academy</label>'
            + '<input id="bDeps" type="number" min="1" max="10" value="' + esc(S.children.length) + '" inputmode="numeric"></div>'
            + '<button type="button" class="p-action" data-bursary="' + esc(c.student_id) + '">Send my application</button>'
            + '</section>';
        }

        var past = (r.applications || []).filter(function (a) {
          return a.status !== 'submitted' && a.status !== 'assessing';
        });
        if (past.length) {
          body += '<section class="p-card is-flush"><div class="p-card-head"><h2 class="p-label">Earlier applications</h2></div>'
            + '<div class="p-rows">' + past.map(function (a) {
                return '<div class="p-row"><span class="p-row-main">'
                  + '<span class="p-title">' + esc(a.band_label) + '</span>'
                  + '<span class="p-caption">' + esc(fmtDate(a.decided_at || a.created_at))
                  + (a.outcome_note ? ' · ' + esc(a.outcome_note) : '') + '</span></span>'
                  + '<span class="p-chip ' + (a.status === 'awarded' ? 'is-good' : '') + '">'
                  + esc(a.status) + (a.awarded_pct ? ' ' + esc(a.awarded_pct) + '%' : '') + '</span></div>';
              }).join('') + '</div></section>';
        }

        paint(h1('Apply for a bursary', 'For ' + esc(c.student_name))
          + '<div class="p-stack">' + body + '</div>');
      })
      .catch(function (err) {
        paint(h1('Apply for a bursary')
          + empty('triangle-exclamation', esc(P.errText ? P.errText(err, 'Could not load the bursary page.') : 'Could not load the bursary page.')));
      });
  }

  function renderTimetable() {
    var c = selectedChild();
    if (!c) { paint(h1('Timetable') + empty('calendar-days', 'No children are linked to your account yet.')); return; }
    paint(h1('Timetable & term dates') + loading('Loading the timetable…'));
    API.request('parent/child?student_id=' + encodeURIComponent(c.student_id), { method: 'GET' })
      .then(function (d) {
        var up = d.upcoming_sessions || [];
        paint(h1('Timetable & term dates', 'For ' + esc(c.student_name))
          + (up.length
              ? '<section class="p-card is-flush"><div class="p-card-head"><h2 class="p-label">Coming up</h2></div>'
                + '<div class="p-rows">' + up.map(function (s) {
                    return '<div class="p-row"><i class="fa-solid fa-calendar-day p-row-ico" aria-hidden="true"></i>'
                      + '<span class="p-row-main"><span class="p-title">' + esc(s.title || 'Session') + '</span>'
                      + '<span class="p-caption">' + esc(fmtDateTime(s.scheduled_at))
                      + (s.cohort_title ? ' · ' + esc(s.cohort_title) : '') + '</span></span></div>';
                  }).join('') + '</div></section>'
              : empty('calendar-days', 'Nothing is scheduled for ' + esc(c.student_name) + ' at the moment. '
                  + 'The academy office can tell you when the next term’s timetable is published.')));
      })
      .catch(function (err) {
        paint(h1('Timetable & term dates')
          + empty('triangle-exclamation', esc(P.errText ? P.errText(err, 'Could not load the timetable.') : 'Could not load the timetable.')));
      });
  }

  /* Safeguarding. Static on purpose: a named person and a route, not a form.
     The design refuses a message queue here because a concern that looks
     answered when it is only filed is worse than one with a phone number. */
  function renderSafeguarding() {
    paint(h1('Safeguarding & who to tell',
      'If you are worried about a child, tell us. You do not need to be certain.')
      + '<div class="p-stack">'
      + '<div class="p-notice is-warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>'
      + '<span>If a child is in immediate danger, contact the Lagos State emergency line on '
      + '<strong>112</strong> or the Lagos State Domestic and Sexual Violence Agency on '
      + '<strong>0813 796 0048</strong> first, then tell us.</span></div>'
      + '<section class="p-card"><h2 class="p-title">The academy’s Designated Safeguarding Officer</h2>'
      + '<p class="p-body p-gap-t">Every school and child-centred institution in Lagos State must name '
      + 'one, and ours is named in your enrolment agreement together with their direct contact. '
      + 'They keep the records and report to the Ministry of Youth and Social Development.</p>'
      + '<button type="button" class="p-action" data-screen="concern">Tell the safeguarding officer now</button>'
      + '<p class="p-caption p-gap-t">It goes straight to the officer, and nobody else reads it.</p>'
      + '<a class="p-ghost p-gap-t" href="/contact">Contact the academy office</a></section>'
      + '<section class="p-card is-quiet"><h2 class="p-title">What happens next</h2>'
      + '<p class="p-body p-gap-t">A concern is recorded, and the Designated Safeguarding Officer decides '
      + 'within one working day whether it is referred on. You will be told what was done unless telling '
      + 'you would put the child at greater risk — which is the only reason we would not.</p>'
      + '<p class="p-caption p-gap-t">Anything about safeguarding reaches you on every channel we can use, '
      + 'whatever your notification settings say.</p></section>'
      + '</div>');
  }

  function renderComplaints() {
    paint(h1('Complaints & appeals', 'Three stages, and none of them costs you anything.')
      + '<div class="p-stack">'
      + '<section class="p-card"><h2 class="p-label">1 · The instructor or the office</h2>'
      + '<p class="p-body p-gap-t">Most things are settled here, usually within a couple of days. '
      + 'The Messages screen is the written record if you want one.</p>'
      + '<button type="button" class="p-ghost p-gap-t" data-screen="messages">Write to the academy</button></section>'
      + '<section class="p-card"><h2 class="p-label">2 · A formal complaint</h2>'
      + '<p class="p-body p-gap-t">Put in writing to the academy office. You will get an acknowledgement '
      + 'within three working days and an answer within fifteen.</p></section>'
      + '<section class="p-card"><h2 class="p-label">3 · A mark appeal</h2>'
      + '<p class="p-body p-gap-t">A mark on a ratified report can be appealed for three months from the '
      + 'day it was published. The appeal is on the report itself — open it and use '
      + '“Appeal a mark”.</p>'
      + '<button type="button" class="p-ghost p-gap-t" data-screen="reports">Go to reports</button></section>'
      + '<p class="p-caption">Nothing about a child’s lessons, report or safeguarding record is '
      + 'affected by a complaint or by an unpaid fee.</p>'
      + '</div>');
  }

  function renderA11y() {
    var r = document.documentElement;
    var cur = {
      text: r.getAttribute('data-text') || 'md',
      ease: r.getAttribute('data-ease') === '1',
      lowdata: r.getAttribute('data-lowdata') === '1',
      theme: r.getAttribute('data-theme') || 'system'
    };
    paint(h1('Display & access', 'These settings are kept on this device.')
      + '<div class="p-stack">'
      + '<section class="p-card"><h2 class="p-title" id="tsLabel">Text size</h2>'
      + '<div class="p-seg p-gap-t" role="group" aria-labelledby="tsLabel">'
      + [['md', 'Normal'], ['lg', 'Larger'], ['xl', 'Largest']].map(function (o) {
          return '<button type="button" data-text-size="' + o[0] + '" aria-selected="'
            + (cur.text === o[0] ? 'true' : 'false') + '">' + o[1] + '</button>';
        }).join('') + '</div></section>'
      + '<section class="p-card"><h2 class="p-title" id="thLabel">Appearance</h2>'
      + '<div class="p-seg p-gap-t" role="group" aria-labelledby="thLabel">'
      + [['system', 'Match my device'], ['light', 'Light'], ['dark', 'Dark']].map(function (o) {
          return '<button type="button" data-theme-set="' + o[0] + '" aria-selected="'
            + (cur.theme === o[0] ? 'true' : 'false') + '">' + o[1] + '</button>';
        }).join('') + '</div></section>'
      + '<section class="p-card">'
      + '<button type="button" class="p-switch" role="switch" aria-checked="' + (cur.ease ? 'true' : 'false')
      + '" data-toggle="ease"><span>Easier to read</span><span class="p-track" aria-hidden="true"></span></button>'
      + '<p class="p-caption">Wider spacing, more line height, and a face with clearer letter shapes.</p>'
      + '<button type="button" class="p-switch" role="switch" aria-checked="' + (cur.lowdata ? 'true' : 'false')
      + '" data-toggle="lowdata"><span>Use less data</span><span class="p-track" aria-hidden="true"></span></button>'
      + '<p class="p-caption">Skips the web fonts and the photographs. On a metered connection the fonts '
      + 'are the largest thing this page downloads.</p>'
      + '</section></div>');
  }

  function allows(child, what) {
    var scope = child && child.scope ? child.scope : 'standard';
    var grants = {
      billing:  ['invoices', 'receipts', 'payments', 'announcements'],
      standard: ['invoices', 'receipts', 'payments', 'announcements', 'reports', 'attendance', 'timetable', 'messages_staff'],
      full:     ['invoices', 'receipts', 'payments', 'announcements', 'reports', 'attendance', 'timetable', 'messages_staff', 'consents', 'act_for_child', 'data_requests']
    };
    /* A client-side mirror, for CHOOSING WHAT TO DRAW ONLY. Every one of these is
       also enforced server-side — the design says "not by hiding UI" — so a stale
       copy here can misdraw a screen but can never grant anything. */
    return (grants[scope] || grants.standard).indexOf(what) !== -1;
  }

  /* ── Router ─────────────────────────────────────────────────────────────── */

  var SCREENS = {
    dashboard: renderDashboard, children: renderChildren, reports: renderReports,
    finance: renderFinance, consents: renderConsents, messages: renderMessages,
    makeup: renderMakeup, bursary: renderBursary, timetable: renderTimetable,
    safeguarding: renderSafeguarding, complaints: renderComplaints, a11y: renderA11y, space: renderSpace, concern: renderConcern, growth: renderGrowth
  };
  /* The selected child's growth, read-only for a family except where the server allows otherwise. */
  function renderGrowth() {
    var c = selectedChild();
    if (!c) { paint(h1('Growth') + empty('arrow-trend-up', 'No children are linked to your account yet.')); return; }
    MAM_GROWTH.render(main, { toast: toast, paint: paint, student: c.student_id, back: function () { go('children'); } });
  }
  /* Class spaces (read-only for a guardian — the server refuses any write) and
     the safeguarding form: shared with the other portals (portal-spaces.js). */
  function renderSpace() { MAM_SPACES.mount(main, { toast: toast, paint: paint }); }
  function renderConcern() { MAM_SPACES.concernForm(main, { toast: toast, paint: paint, back: function () { go('safeguarding'); } }); }
  var TITLES = {
    dashboard: 'Home', children: 'My Children', reports: 'Reports', finance: 'Finance',
    consents: 'Consents', messages: 'Messages', makeup: 'Make-up lessons',
    bursary: 'Bursary', timetable: 'Timetable', safeguarding: 'Safeguarding',
    complaints: 'Complaints', a11y: 'Display & access', space: 'Class', concern: 'Tell the safeguarding officer', growth: 'Growth'
  };
  /* Which bottom-bar tab owns each destination, so a More-sheet screen does not
     leave every tab looking inactive. */
  var TAB_OF = {
    dashboard: 'dashboard', children: 'children', reports: 'reports', finance: 'finance',
    consents: null, messages: null, makeup: null, bursary: null, timetable: null,
    safeguarding: null, complaints: null, a11y: null, space: null, concern: null, growth: 'children'
  };

  function go(screen, childId) {
    if (!SCREENS[screen]) screen = 'dashboard';
    if (childId != null && childId !== '') S.child = Number(childId);
    S.screen = screen;
    closeSheet();
    $$('#nav button[data-screen]').forEach(function (b) {
      if (TAB_OF[screen] && b.getAttribute('data-screen') === TAB_OF[screen]) b.setAttribute('aria-current', 'page');
      else b.removeAttribute('aria-current');
    });
    document.title = TITLES[screen] + ' · Guardian portal · MAM Academy';
    var hash = '#' + screen + (S.child ? '/' + S.child : '');
    if (location.hash !== hash) history.replaceState(null, '', hash);
    SCREENS[screen]();
    renderSwitcher();
    // Focus the heading so a keyboard or screen-reader user lands on the new
    // screen rather than back at the top of the nav.
    var head = main.querySelector('.p-h1') || main.querySelector('.p-back');
    if (head) { head.setAttribute('tabindex', '-1'); head.focus({ preventScroll: true }); }
    main.scrollTop = 0;
    window.scrollTo(0, 0);
  }

  var sheetReturn = null;
  function openSheet(trigger) {
    var sh = $('#sheet');
    sheetReturn = trigger || document.activeElement;
    sh.hidden = false;
    var first = sh.querySelector('.p-row');
    if (first) first.focus();
  }
  function closeSheet() {
    var sh = $('#sheet');
    if (sh.hidden) { sheetReturn = null; return; }
    sh.hidden = true;
    /* Focus returns to the control that opened it. Without this it falls to
       <body> and the reader is put back at the top of the document. */
    if (sheetReturn && document.contains(sheetReturn)) sheetReturn.focus();
    sheetReturn = null;
  }

  /* ── Events. One delegated listener, so a re-render never loses a binding. ── */

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-screen],[data-sheet-open],[data-sheet-close],[data-child],'
      + '[data-thread],[data-reply],[data-consent],[data-channel],[data-plan],[data-pay],'
      + '[data-appeal],[data-ask],[data-bursary],[data-new-thread],[data-print],[data-transfer-open],[data-transfer-send],'
      + '[data-text-size],[data-theme-set],[data-toggle],[data-qsend],[data-qcode],[data-qrespond],#signOut');
    if (!t) return;

    if (t.id === 'signOut') {
      e.preventDefault();
      if (window.MAM_AUTH && MAM_AUTH.logout) MAM_AUTH.logout();
      else API.request('auth/logout', { method: 'POST' }).then(function () { location.href = '/portal/login'; });
      return;
    }
    if (t.hasAttribute('data-sheet-open'))  { e.preventDefault(); openSheet(t); return; }
    if (t.hasAttribute('data-sheet-close')) { e.preventDefault(); closeSheet(); return; }
    if (t.closest('#toastClose')) { e.preventDefault(); $('#toast').hidden = true; return; }
    if (t.hasAttribute('data-print'))       { e.preventDefault(); window.print(); return; }

    if (t.hasAttribute('data-screen')) {
      e.preventDefault();
      go(t.getAttribute('data-screen'), t.getAttribute('data-child'));
      return;
    }
    if (t.hasAttribute('data-thread')) { e.preventDefault(); renderThread(t.getAttribute('data-thread')); return; }

    /* Invoice queries and codes (fee-review.php). Each write re-renders Finance from the server. */
    if (t.hasAttribute('data-qsend') || t.hasAttribute('data-qcode') || t.hasAttribute('data-qrespond')) {
      e.preventDefault();
      var qid = t.getAttribute('data-qsend') || t.getAttribute('data-qcode');
      var val = function (p) { var el = document.getElementById(p + qid); return el ? el.value : ''; };
      var body = t.hasAttribute('data-qsend')
        ? { action: 'raise', invoice_id: Number(qid), kind: val('qk'), message: val('qm'), amount: val('qa'), instalments: Number(val('qi')) || null }
        : t.hasAttribute('data-qcode') ? { action: 'code', invoice_id: Number(qid), code: val('qc') }
        : { action: 'respond', query_id: Number(t.getAttribute('data-qrespond')), accept: t.getAttribute('data-accept') === '1' };
      write(t, API.request('parent/fee-query', { method: 'POST', body: body }), function (r) { if (r && r.message) toast(r.message, 'good'); renderFinance(); });
      return;
    }

    if (t.hasAttribute('data-ask')) {
      e.preventDefault();
      newThread(t.getAttribute('data-ask'), 'About the termly report', 'academic');
      return;
    }
    if (t.hasAttribute('data-new-thread')) {
      e.preventDefault();
      var c = selectedChild();
      if (c) newThread(c.student_id, '', 'general');
      return;
    }
    if (t.hasAttribute('data-reply')) {
      e.preventDefault();
      var box = $('#replyBody');
      var body = box ? box.value.trim() : '';
      if (!body) { toast('Write something first.', 'bad'); return; }
      write(t, API.request('parent/thread-post', { method: 'POST', body: { thread_id: Number(t.getAttribute('data-reply')), body: body } }),
        function () { toast('Sent.', 'good'); renderThread(t.getAttribute('data-reply')); });
      return;
    }

    if (t.hasAttribute('data-consent')) {
      e.preventDefault();
      var c2 = selectedChild();
      if (!c2) return;
      var key = t.getAttribute('data-consent');
      var val = t.getAttribute('data-consent-value') === '1';
      // The server takes the WHOLE consent set, so read the current switches and
      // send them with this one changed — a partial write would clear the others.
      API.request('parent/child?student_id=' + encodeURIComponent(c2.student_id), { method: 'GET' })
        .then(function (d) {
          var con = d.consent || {}, payload = { student_id: c2.student_id };
          ['media', 'photo', 'data'].forEach(function (k) { payload[k] = !!con[k]; });
          payload[key] = val;
          if (con.safeguarding_contact) payload.safeguarding_contact = con.safeguarding_contact;
          return write(t, API.request('parent/consent', { method: 'POST', body: payload }), function () {
            toast(val ? 'Recorded — thank you.' : 'Recorded. Nothing else changes.', 'good');
            renderConsents();
          });
        }).catch(function () { /* write() has already reported it honestly */ });
      return;
    }

    if (t.hasAttribute('data-channel')) {
      e.preventDefault();
      var on = t.getAttribute('aria-checked') === 'true';
      var payload = {};
      $$('[data-channel]').forEach(function (b) {
        payload[b.getAttribute('data-channel')] = b === t ? !on : b.getAttribute('aria-checked') === 'true';
      });
      write(null, API.request('parent/channels', { method: 'POST', body: payload }), function (r) {
        t.setAttribute('aria-checked', on ? 'false' : 'true');
        toast('Saved. Safeguarding and money still reach you on every channel we can use.', 'good');
      });
      return;
    }

    if (t.hasAttribute('data-plan')) {
      e.preventDefault();
      write(t, API.request('parent/plan', { method: 'POST', body: { invoice_id: Number(t.getAttribute('data-plan')), instalments: 3 } }),
        function () { toast('Requested. The office will confirm the dates with you.', 'good'); renderFinance(); });
      return;
    }
    if (t.hasAttribute('data-pay')) {
      e.preventDefault();
      /* The server opens the charge and hands back the gateway's own checkout
         page; settlement happens when the browser comes back (`?paid=`) or by
         webhook, whichever lands first. The amount is never decided here — at
         most it names an instalment, and the server checks that against what
         is owed. */
      var amt = t.getAttribute('data-amount');
      write(t, API.request('parent/fee-pay', { method: 'POST', body: {
          invoice_id: Number(t.getAttribute('data-pay')),
          amount_minor: amt ? Number(amt) : null } }),
        function (r) {
          if (r && r.authorization_url) {
            toast('Opening the secure checkout for ' + (r.amount_ngn || 'this payment') + '…', 'info');
            location.href = r.authorization_url;
          }
        }).catch(function (err) {
          var code = (err && err.body && err.body.error) || '';
          if (code === 'online_payment_unavailable') {
            toast('Online payment is not switched on yet. The academy office will send you bank details '
              + 'for this invoice. Nothing about your child’s lessons or report is affected in the meantime.', 'info');
          }
        });
      return;
    }

    if (t.hasAttribute('data-appeal')) {
      e.preventDefault();
      var reason = window.prompt('What would you like the panel to look at again?');
      if (reason == null || !reason.trim()) return;
      write(t, API.request('parent/appeal', { method: 'POST', body: { report_id: Number(t.getAttribute('data-appeal')), reason: reason.trim() } }),
        function () { toast('Your appeal has been recorded. The panel will write to you.', 'good'); renderReports(); });
      return;
    }

    if (t.hasAttribute('data-transfer-open')) {
      e.preventDefault();
      var tid = t.getAttribute('data-transfer-open');
      var slot = main.querySelector('[data-transfer-slot="' + tid + '"]');
      if (slot) {
        slot.innerHTML = transferForm(tid, t.getAttribute('data-due'));
        var first = slot.querySelector('input');
        if (first) first.focus();
      }
      return;
    }
    if (t.hasAttribute('data-transfer-send')) {
      e.preventDefault();
      var iid = t.getAttribute('data-transfer-send');
      var val = function (k) { var el = $('#' + k + iid); return el ? el.value.trim() : ''; };
      var fileEl = $('#tfFile' + iid);
      var f = fileEl && fileEl.files && fileEl.files[0];
      if (!val('tfRef')) { toast('Type the transaction reference from the slip or the bank alert.', 'bad'); return; }
      if (!f) { toast('Attach a photo or PDF of the slip, receipt or bank alert.', 'bad'); return; }
      if (f.size > 5 * 1024 * 1024) { toast('That file is larger than 5 MB. A phone photo of the slip is enough.', 'bad'); return; }
      var rd = new FileReader();
      rd.onerror = function () { toast('That file could not be read. Try attaching it again.', 'bad'); };
      rd.onload = function () {
        write(t, API.request('parent/fee-transfer', { method: 'POST', body: {
            invoice_id: Number(iid), amount: val('tfAmt'), paid_on: val('tfOn'), reference: val('tfRef'),
            bank_name: val('tfBank'), payer_name: val('tfName'), note: val('tfNote'), evidence: String(rd.result) } }),
          function () {
            toast('Sent. The office will check it against the bank statement and email you either way.', 'good');
            renderFinance();
          }).catch(function (err) {
            var d = err && err.body && err.body.detail;
            if (d) toast(d, 'bad');
          });
      };
      rd.readAsDataURL(f);
      return;
    }

    if (t.hasAttribute('data-bursary')) {
      e.preventDefault();
      var band = $('#bBand') ? $('#bBand').value : '';
      if (!band) { toast('Choose a band first.', 'bad'); return; }
      var payload2 = { student_id: Number(t.getAttribute('data-bursary')), income_band: band };
      if ($('#bHouse') && $('#bHouse').value) payload2.household_size = Number($('#bHouse').value);
      if ($('#bDeps') && $('#bDeps').value) payload2.dependants_in_academy = Number($('#bDeps').value);
      write(t, API.request('parent/bursary', { method: 'POST', body: payload2 }),
        function () { toast('Sent. This does not affect anything else about your child’s place.', 'good'); renderBursary(); });
      return;
    }

    if (t.hasAttribute('data-text-size') || t.hasAttribute('data-theme-set') || t.hasAttribute('data-toggle')) {
      e.preventDefault();
      var root = document.documentElement, prefs = {};
      try { prefs = JSON.parse(localStorage.getItem('mam-guardian-a11y') || '{}'); } catch (er) { prefs = {}; }
      if (t.hasAttribute('data-text-size')) {
        var v = t.getAttribute('data-text-size');
        prefs.text = v === 'md' ? null : v;
        if (prefs.text) root.setAttribute('data-text', prefs.text); else root.removeAttribute('data-text');
      }
      if (t.hasAttribute('data-theme-set')) {
        var th = t.getAttribute('data-theme-set');
        prefs.theme = th === 'system' ? null : th;
        if (prefs.theme) root.setAttribute('data-theme', prefs.theme);
        else if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) root.setAttribute('data-theme', 'dark');
        else root.removeAttribute('data-theme');
      }
      if (t.hasAttribute('data-toggle')) {
        var k2 = t.getAttribute('data-toggle');
        prefs[k2] = !prefs[k2];
        var attr = k2 === 'ease' ? 'data-ease' : 'data-lowdata';
        if (prefs[k2]) root.setAttribute(attr, '1'); else root.removeAttribute(attr);
      }
      try { localStorage.setItem('mam-guardian-a11y', JSON.stringify(prefs)); } catch (er) {}
      renderA11y();
      return;
    }
  });

  function newThread(studentId, subject, kind) {
    var subj = subject || window.prompt('What is it about? (a short subject line)');
    if (subj == null || !String(subj).trim()) return;
    var body = window.prompt('Your message');
    if (body == null || !String(body).trim()) return;
    write(null, API.request('parent/thread-post', {
      method: 'POST',
      body: { student_id: Number(studentId), subject: String(subj).trim(), kind: kind || 'general', body: String(body).trim() }
    }), function (r) { toast('Sent.', 'good'); renderThread(r.thread_id); });
  }

  /* Escape closes the sheet; the sheet traps Tab while it is open. */
  document.addEventListener('keydown', function (e) {
    var sh = $('#sheet');
    if (sh.hidden) return;
    if (e.key === 'Escape') { closeSheet(); return; }
    if (e.key !== 'Tab') return;
    var items = $$('button, [href], textarea, select, input', sh).filter(function (el) { return !el.disabled; });
    if (!items.length) return;
    var first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  window.addEventListener('hashchange', function () {
    var parts = location.hash.replace('#', '').split('/');
    if (SCREENS[parts[0]] && parts[0] !== S.screen) go(parts[0], parts[1]);
  });

  /* A read may be stale, but it must be LABELLED stale (§2.4). */
  window.addEventListener('offline', function () {
    toast('You are offline. What is on screen is the last thing we loaded, and nothing you '
      + 'change will be saved until you have data again.', 'bad');
  });

  /* ── Boot ───────────────────────────────────────────────────────────────── */

  function renderSwitcher() {
    var sw = $('#switcher'), seg = $('#switcherSeg');
    if (S.children.length < 2) { sw.hidden = true; return; }
    sw.hidden = false;
    seg.innerHTML = S.children.map(function (c) {
      return '<button type="button" data-child="' + esc(c.student_id) + '" data-screen="' + esc(S.screen)
        + '" aria-selected="' + (Number(c.student_id) === Number(S.child) ? 'true' : 'false') + '">'
        + esc(String(c.student_name).split(/\s+/)[0]) + '</button>';
    }).join('');
  }

  API.request('parent/dashboard', { method: 'GET' })
    .then(function (d) {
      S.dash = d;
      S.children = d.children || [];
      if (S.children.length && !S.child) S.child = Number(S.children[0].student_id);

      $('#whoName').textContent = d.guardian.name || 'Guardian';
      var scope = S.children.length ? (S.children[0].scope || 'standard') : null;
      $('#whoMeta').textContent = 'Guardian'
        + (scope ? ' · ' + (scope === 'full' ? 'full access' : scope === 'billing' ? 'billing only' : 'standard access') : '')
        + ' · ' + S.children.length + ' ' + plural(S.children.length, 'child', 'children');

      var owed = (d.owed || []).length;
      var fb = $('#financeBadge');
      if (owed) { fb.hidden = false; fb.textContent = String(owed); fb.setAttribute('aria-label', owed + ' unpaid ' + plural(owed, 'invoice', 'invoices')); }
      var unread = d.unread_messages || 0;
      var mb = $('#msgBadge');
      /* Every badge carries its own accessible name. On mobile it paints as a
         bare dot, so without one a screen reader announces nothing at all —
         and `#bell`'s label below describes the BUTTON, not this. */
      if (unread) { mb.hidden = false; mb.textContent = String(unread);
        mb.setAttribute('aria-label', unread + ' unread ' + plural(unread, 'message', 'messages')); }
      $('#bell').setAttribute('aria-label', unread
        ? unread + ' unread ' + plural(unread, 'message', 'messages')
        : 'Messages from the academy');
      var pendingConsent = S.children.reduce(function (n, c) { return n + (c.consents_pending || 0); }, 0);
      if (pendingConsent) { var cb = $('#consentBadge'); cb.hidden = false;
        cb.textContent = String(pendingConsent);
        cb.setAttribute('aria-label', pendingConsent + ' ' + plural(pendingConsent, 'consent', 'consents') + ' to give'); }
      var mk = S.children[0];
      if (mk && mk.credits_available != null) {
        $('#makeupCount').textContent = mk.credits_available + ' · ' + (mk.credits_allowance_left != null ? mk.credits_allowance_left : '');
      }

      renderSwitcher();
      /* Back from the gateway. Ask the academy — never the address bar — whether
         it went through: `?paid=` only says which charge to ask about. The query
         is dropped first so a refresh does not ask again. */
      var qs = new URLSearchParams(location.search);
      var paidRef = qs.get('paid');
      if (paidRef) {
        var payChild = qs.get('child');
        history.replaceState(null, '', location.pathname + '#finance' + (payChild ? '/' + payChild : ''));
        go('finance', payChild);
        API.request('payment/verify', { method: 'POST', body: { reference: paidRef, provider: 'paystack' } })
          .then(function () {
            toast('Payment received — thank you. A receipt is on its way to your email.', 'good');
            if (S.screen === 'finance') renderFinance();
          })
          .catch(function (err) {
            var code = (err && err.body && err.body.error) || '';
            toast(code === 'verification_failed'
              ? 'That payment did not go through, so nothing has been taken. You can try again below.'
              : 'We could not confirm that payment yet. If money left your account it will show here '
                + 'within a few minutes — please do not pay twice.', 'bad');
          });
        return;
      }
      var parts = location.hash.replace('#', '').split('/');
      go(SCREENS[parts[0]] ? parts[0] : 'dashboard', parts[1]);
    })
    .catch(function (err) {
      var code = (err && err.body && err.body.error) || '';
      if (code === 'unauthorized' || code === 'forbidden' || (err && err.status === 401)) {
        location.href = '/portal/login?next=' + encodeURIComponent('/portal/parent/');
        return;
      }
      paint(empty('triangle-exclamation',
        esc(P.errText ? P.errText(err, 'Could not load your account.') : 'Could not load your account.')
        + '<br><br>If this keeps happening, the academy office can help.'));
    });
})();
