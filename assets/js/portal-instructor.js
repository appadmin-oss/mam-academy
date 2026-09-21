/* ─────────────────────────────────────────────────────────────────────────────
   MAM Academy — the instructor portal (redesign, 19 Sep 2026)

   Seven screens from the canonical design (`portals-mobile.dc.html`
   `data-phone="instructorv2"`, and `portals-desktop.dc.html`
   `data-portal="instructor"` for the rail): home · register · marking ·
   teachbacks · report · students · earnings.

   ── The rules this file is built around ─────────────────────────────────────

   1. **No inline styles, anywhere.** Every visual decision lives in
      `assets/css/portal-v2.css` as a named component. A template here that
      reached for `style="…"` would put a value the designer chose in a place
      nobody looks when they change it — which is how the previous portals ended
      up with three definitions of a card.

   2. **Never an optimistic tick** (design refusal 6). A write goes busy, then
      reports what actually happened. The register is the sharp case: each mark
      is its own request precisely so a dropped signal loses ONE mark rather
      than a register, and the screen says so in the design's own words.

   3. **The refusals are the server's, and this file does not re-derive them.**
      The Students screen renders the BAND and the SENTENCE the provider sends
      and has no branch that could compute a risk score; the audio-only marking
      rule is `show_video` from `GET /api/instructor/marking-sheet`, decided
      server-side, with the artifact URL withheld entirely from a technical-only
      sheet. A client-side flag is one devtools toggle away, and the instructor
      most likely to flip it is the one the control exists for.

   One request per screen — `GET /api/instructor/portal?screen=…`. The readers
   are on Nigerian mobile data, where six round trips to paint a dashboard is
   the difference between a screen that appears and one that unfolds.

   Extracted to its own file rather than inlined: the three legacy dashboards
   carry ~4,800 lines of inline `<script>` between them, which is why a change
   to one of them cannot be reviewed as a diff.
   ───────────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  var P   = window.MAM_PORTAL || {};
  var API = window.MAM_API;
  var esc = P.escapeHtml || function (s) { return String(s == null ? '' : s); };

  var S = {
    screen: 'home', home: null, sessionId: null,
    marking: null, sheet: null, marks: {}, comment: '',
    students: [], filter: '', reportStudent: null, busy: false
  };

  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var main = $('#main');

  /* ── Small helpers ──────────────────────────────────────────────────────── */

  function fmtTime(v) {
    if (!v) return '';
    var d = new Date(String(v).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(String(v)) ? '' : 'Z'));
    return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }
  /* The "Rest of today" column is a FIGURE in a 58px track, and the design
     prints "5:30" there — no meridiem. MEASURED: with one, "8:30 PM" wraps to
     two lines and the row grows by 22px. The meridiem stays on the session
     card's meta line, where there is room for it and it is read once. */
  function fmtClock(v) {
    if (!v) return '';
    var d = new Date(String(v).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(String(v)) ? '' : 'Z'));
    return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
  }
  function fmtDate(v) {
    if (!v) return '';
    var d = new Date(String(v).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(String(v)) ? '' : 'Z'));
    return isNaN(d) ? '' : d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function initials(name) {
    return String(name || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase();
  }
  function plural(n, one, many) { return n === 1 ? one : many; }
  function money(cents) {
    var v = (Number(cents) || 0) / 100;
    return '$' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /* "Starts in 8 minutes" is the design's headline. The provider sends MINUTES,
     not a string, so a session already running reads as what it is rather than
     as a negative number or an error. */
  function startsIn(min) {
    var m = Number(min);
    if (isNaN(m)) return 'Scheduled';
    if (m > 90)  return 'Later today';
    if (m > 1)   return 'Starts in ' + m + ' minutes';
    if (m >= 0)  return 'Starts now';
    if (m > -90) return 'Started ' + Math.abs(m) + ' ' + plural(Math.abs(m), 'minute', 'minutes') + ' ago';
    return 'Earlier today';
  }

  var toastTimer = null;
  /* ⚠️ An ERROR is not a polite status, and it does not time out. The element
     is declared `role="status" aria-live="polite"`, which a screen reader
     queues until it is idle — right for "Saved", wrong for "that did not
     save". A failure is swapped to `alert`/assertive and stays until it is
     dismissed, because it is often the only record that the thing the reader
     pressed did not happen. Successes still clear themselves. */
  function toast(msg, tone) {
    var t = $('#toast'), bad = tone === 'bad';
    $('#toastText').textContent = msg;
    t.className = 'p-toast' + (tone ? ' is-' + tone : '');
    t.setAttribute('role', bad ? 'alert' : 'status');
    t.setAttribute('aria-live', bad ? 'assertive' : 'polite');
    var close = $('#toastClose');
    if (close) close.hidden = !bad;
    t.hidden = false;
    clearTimeout(toastTimer);
    if (!bad) toastTimer = setTimeout(function () { t.hidden = true; }, 5200);
  }

  /**
   * Every write on this portal goes through here.
   *
   * The control goes busy and STAYS busy until the server answers; there is no
   * branch that paints success before the response. That is design refusal 6,
   * and its reason is that a dishonest success state loses the record — an
   * instructor who saw a register tick and later finds it empty stops trusting
   * the screen, which is the one thing a register cannot afford.
   */
  function write(btn, req, onOk, okMsg) {
    if (S.busy) return;
    S.busy = true;
    var label = btn ? btn.innerHTML : null;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch p-spin" aria-hidden="true"></i> Saving…'; }
    req.then(function (r) {
      S.busy = false;
      if (btn) { btn.disabled = false; btn.innerHTML = label; }
      if (okMsg) toast(okMsg, 'good');
      if (onOk) onOk(r);
    }).catch(function (err) {
      S.busy = false;
      if (btn) { btn.disabled = false; btn.innerHTML = label; }
      toast((P.errText ? P.errText(err, 'That did not save.') : 'That did not save.'), 'bad');
    });
  }

  /* ⚠️ Focus moves on the PAINT, not on the navigation — every screen here
     fetches, so `go()` returns while `#main` still holds the previous screen.
     One-shot, because `paint()` also runs after a write (each register mark
     re-renders the roster) and pulling focus to the heading there would throw
     an instructor out of the row they are marking. */
  var pendingFocus = false;
  function paint(html) {
    main.innerHTML = html;
    main.scrollTop = 0;
    if (pendingFocus) {
      pendingFocus = false;
      var head = main.querySelector('.p-h1') || main.querySelector('.p-back');
      if (head) { head.setAttribute('tabindex', '-1'); head.focus({ preventScroll: true }); }
    }
  }
  function loading(what) {
    return '<div class="p-empty"><i class="fa-solid fa-circle-notch p-spin" aria-hidden="true"></i><p>' + esc(what) + '</p></div>';
  }
  function empty(icon, text) {
    return '<div class="p-empty"><i class="fa-solid fa-' + icon + '" aria-hidden="true"></i><p>' + text + '</p></div>';
  }
  function h1(title, sub) {
    return '<h1 class="p-h1">' + esc(title) + '</h1>'
      + (sub ? '<p class="p-body p-lead">' + esc(sub) + '</p>' : '');
  }
  function backTo(screen, label) {
    return '<button type="button" class="p-ghost p-lead" data-screen="' + esc(screen) + '">'
      + '<i class="fa-solid fa-chevron-left" aria-hidden="true"></i>' + esc(label) + '</button>';
  }
  function errOf(err, fallback) {
    return esc(P.errText ? P.errText(err, fallback) : fallback);
  }
  function screenReq(screen, extra) {
    return API.request('instructor/portal?screen=' + screen + (extra || ''), { method: 'GET' });
  }

  /* ── 1 · Home ───────────────────────────────────────────────────────────── */

  function renderHome() {
    paint(loading('Loading today…'));
    screenReq('home').then(function (d) {
      S.home = d;
      var n = d.next;
      var html = '<div class="p-stack">';

      if (n) {
        S.sessionId = Number(n.id);
        var meta = [fmtTime(n.scheduled_at), n.room, n.cohort_title,
                    (n.roster != null ? n.roster + ' ' + plural(Number(n.roster), 'student', 'students') : null)]
                   .filter(Boolean).join(' · ');
        html += '<section class="i-next">'
          + '<div class="p-label">' + esc(startsIn(n.starts_in_min)) + '</div>'
          + '<h2 class="i-next-title">' + esc(n.title || n.cohort_title || 'Your next class') + '</h2>'
          + '<div class="i-next-meta">' + esc(meta) + '</div>'
          + '<div class="i-next-acts">'
          + '<button type="button" class="is-wide" data-screen="register" data-session="' + esc(n.id) + '">'
          + (n.register_taken ? 'Open the register' : 'Take the register') + '</button>'
          + '<button type="button" class="is-icon" data-qr="' + esc(n.id) + '" aria-label="Show the check-in QR code">'
          + '<i class="fa-solid fa-qrcode" aria-hidden="true"></i></button>'
          + '</div></section>';
      } else {
        html += '<section class="p-card">' + h1('Nothing scheduled', 'No more classes today. Anything waiting is below.') + '</section>';
      }

      var acts = d.actions || [];
      if (acts.length) {
        var overdue = acts.filter(function (a) { return Number(a.overdue_days) > 0; }).length;
        html += '<section class="p-card is-flush' + (overdue ? ' is-urgent' : '') + '">'
          + '<div class="p-card-head p-line">'
          + '<span class="p-label">Action required</span>'
          + (overdue ? '<span class="p-num p-bad p-title">' + overdue + ' overdue</span>' : '')
          + '</div><div class="p-rows">'
          + acts.map(actionRow).join('')
          + '</div></section>';
      }

      var later = d.later || [];
      if (later.length) {
        html += '<section class="p-card is-flush">'
          + '<div class="p-card-head"><span class="p-label">Rest of today</span></div>'
          + '<div class="p-rows">' + later.map(function (s) {
            var sub = [s.room, (s.roster != null ? s.roster + ' ' + plural(Number(s.roster), 'student', 'students') : null),
                       s.mode === 'online' ? 'Online' : null].filter(Boolean).join(' · ');
            return '<button type="button" class="p-row" data-screen="register" data-session="' + esc(s.id) + '">'
              + '<span class="i-when">' + esc(fmtClock(s.scheduled_at)) + '</span>'
              + '<span class="p-row-main"><span class="p-title">' + esc(s.title || s.cohort_title || 'Class') + '</span>'
              + (sub ? '<span class="p-caption">' + esc(sub) + '</span>' : '') + '</span></button>';
          }).join('') + '</div></section>';
      }

      html += '</div>';
      paint(html);
    }).catch(function (err) { paint(empty('triangle-exclamation', errOf(err, 'Could not load today.'))); });
  }

  /* An action row is a BUTTON when there is a screen to go to and a plain row
     when there is not — a control that looks tappable and does nothing is how a
     queue stops being believed. */
  function actionRow(a) {
    var to = { marking: 'marking', teachbacks: 'teachbacks', report: 'report', reschedule: null, makeup: null }[a.kind];
    var late = Number(a.overdue_days) > 0;
    var age = a.overdue_days != null
      ? (late ? a.overdue_days + 'd late' : 'Today')
      : '';
    var inner = '<i class="fa-solid fa-' + (late ? 'circle-exclamation' : (a.kind === 'report' ? 'file-pen' : 'clock'))
      + ' p-row-ico' + (late ? ' is-bad' : (a.kind === 'report' ? ' is-warn' : '')) + '" aria-hidden="true"></i>'
      + '<span class="p-row-main"><span class="p-title">' + esc(a.label || '') + '</span>'
      + (a.detail ? '<span class="p-caption">' + esc(a.detail) + '</span>' : '') + '</span>'
      + (age ? '<span class="p-caption ' + (late ? 'p-bad p-strong' : '') + '">' + esc(age) + '</span>' : '');
    return to
      ? '<button type="button" class="p-row' + (late ? ' is-bad-bg' : '') + '" data-screen="' + esc(to) + '">' + inner + '</button>'
      : '<div class="p-row' + (late ? ' is-bad-bg' : '') + '">' + inner + '</div>';
  }

  /* ── 2 · Register ───────────────────────────────────────────────────────── */

  var MARKS = [['present', 'Present'], ['late', 'Late'], ['absent', 'Absent']];

  function renderRegister(sessionId) {
    if (sessionId) S.sessionId = Number(sessionId);
    paint(loading('Loading the register…'));
    screenReq('register', S.sessionId ? '&session=' + encodeURIComponent(S.sessionId) : '')
      .then(function (d) {
        if (!d.session) { paint(h1('Register') + empty('calendar-xmark', 'No class to take a register for just now.')); return; }
        S.sessionId = Number(d.session.id);
        var sub = [d.session.cohort_title, fmtTime(d.session.scheduled_at),
                   (d.total + ' ' + plural(d.total, 'student', 'students'))].filter(Boolean).join(' · ');
        /* ⚠️ "Saved N of M" was a `<button class="p-primary">` with NO handler
           anywhere — a dead control, and the most prominent thing on the
           screen. It is a READOUT: each mark saves on its own, so there is
           nothing to press, and dressing the progress figure as the primary
           action made the one real action ("All present") look secondary to
           it. Now a status with a bar, beside the action. */
        var pct = d.total > 0 ? Math.round(d.marked / d.total * 100) : 0;
        paint(h1('Register', sub)
          + '<div class="i-regbar p-lead">'
          + '<button type="button" class="p-outline" id="allPresent">All present</button>'
          + '<div class="i-saved" role="status">'
          +   '<span class="p-caption"><b class="p-num">' + d.marked + ' of ' + d.total + '</b> marked</span>'
          +   '<span class="p-bar"><span class="' + (d.marked === d.total ? 'is-strong' : 'is-secure')
          +     '" data-pct="' + pct + '"></span></span>'
          + '</div>'
          + '</div>'
          + '<section class="p-card is-flush">' + (d.roster || []).map(rosterRow).join('') + '</section>'
          + '<div class="p-notice p-gap-t"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>'
          + '<span>' + esc(d.save_notice) + '</span></div>');
      })
      .catch(function (err) { paint(h1('Register') + empty('triangle-exclamation', errOf(err, 'Could not load the register.'))); });
  }

  function rosterRow(r) {
    var st = r.status || '';
    var absent = st === 'absent' || st === 'unauthorised' || st === 'excused';
    /* "Checked herself in, 3:58 PM" — a mark the instructor did not make must
       not look like one they did. */
    var note = r.self_checked ? 'Checked in at ' + fmtTime(r.checked_in_at)
      : (st === 'late' && r.checked_in_at) ? 'Arrived ' + fmtTime(r.checked_in_at)
      : r.notes ? r.notes
      : r.marked ? 'Marked' : 'Not marked';
    return '<div class="i-roster-row" data-student="' + esc(r.student_id) + '">'
      + '<div class="i-roster-head"><div class="p-row-main">'
      + '<div class="p-name">' + esc(r.name) + '</div>'
      + '<div class="p-caption' + (r.self_checked ? ' p-good' : '') + '">' + esc(note) + '</div>'
      + '</div></div>'
      + '<div class="i-marks" role="group" aria-label="Attendance for ' + esc(r.name) + '">'
      + MARKS.map(function (m) {
        return '<button type="button" data-mark="' + m[0] + '" data-student="' + esc(r.student_id) + '"'
          + ' aria-pressed="' + (st === m[0] || (m[0] === 'absent' && absent) ? 'true' : 'false') + '">' + m[1] + '</button>';
      }).join('') + '</div>'
      /* The second question is asked only once the first is answered "absent" —
       * authorised vs unauthorised is meaningless for a student who was there. */
      + (absent
        ? '<div class="i-auth" role="group" aria-label="Was the absence authorised for ' + esc(r.name) + '">'
          + '<button type="button" data-auth="excused" data-student="' + esc(r.student_id) + '" aria-pressed="' + (st === 'excused' ? 'true' : 'false') + '">Authorised</button>'
          + '<button type="button" data-auth="unauthorised" data-student="' + esc(r.student_id) + '" aria-pressed="' + (st === 'unauthorised' ? 'true' : 'false') + '">Unauthorised</button>'
          + '</div>'
        : '')
      + '</div>';
  }

  /* Each mark is its OWN request. That is not a performance choice — it is what
     makes the design's promise true ("a dropped signal can't lose the register"). */
  function markOne(btn, studentId, status) {
    write(btn, API.request('instructor/session-attendance', {
      method: 'POST',
      body: { session_id: S.sessionId, student_id: Number(studentId), status: status }
    }), function () { renderRegister(S.sessionId); });
  }

  /* ── 3 · Marking ────────────────────────────────────────────────────────── */

  function renderMarking() {
    paint(loading('Loading the marking queue…'));
    screenReq('marking').then(function (d) {
      S.marking = d;
      var q = d.queue || [];
      if (!q.length) { paint(h1('Marking') + empty('circle-check', 'Nothing waiting to be marked.')); return; }
      paint(h1('Marking', q.length + ' ' + plural(q.length, 'performance', 'performances') + ' waiting, oldest first')
        + '<section class="p-card is-flush"><div class="p-rows">'
        + q.map(function (s) {
          var sub = [s.title, s.days != null ? s.days + 'd waiting' : null].filter(Boolean).join(' · ');
          return '<button type="button" class="p-row" data-sheet-mark="' + esc(s.id) + '">'
            + '<span class="p-avatar" aria-hidden="true">' + esc(initials(s.student_name)) + '</span>'
            + '<span class="p-row-main"><span class="p-title">' + esc(s.student_name) + '</span>'
            + (sub ? '<span class="p-caption">' + esc(sub) + '</span>' : '') + '</span>'
            + (s.second_marker ? '<span class="p-chip">2nd marker</span>' : '')
            + '<i class="fa-solid fa-chevron-right p-row-go" aria-hidden="true"></i></button>';
        }).join('')
        + '</div></section>'
        + '<div class="p-notice p-gap-t"><i class="fa-solid fa-headphones" aria-hidden="true"></i>'
        + '<span>' + esc(d.audio_only_notice) + '</span></div>');
    }).catch(function (err) { paint(h1('Marking') + empty('triangle-exclamation', errOf(err, 'Could not load the queue.'))); });
  }

  /**
   * Grade entry for one submission.
   *
   * ⚠️ `show_video` and the artifact URL are the SERVER's. A technical-only
   * sheet arrives with no artifact URL at all — the video is not hidden here,
   * it was never sent. Nothing in this function may re-derive either.
   */
  function renderGrade(submissionId) {
    paint(loading('Loading the marking sheet…'));
    API.request('instructor/marking-sheet?submission=' + encodeURIComponent(submissionId), { method: 'GET' })
      .then(function (sheet) {
        S.sheet = sheet; S.marks = {}; S.comment = '';
        var crit = sheet.criteria || [];
        /* Technical criteria come first — the server sorts them that way so the
           technical marks are committed before the video is ever shown. The
           first one is therefore the one being marked now. */
        var first = crit[0];
        var showVideo = !!(first && first.show_video);

        var html = backTo('marking', 'Marking')
          + '<h1 class="p-h1">Grade entry</h1>'
          + '<p class="p-body p-lead">' + esc([sheet.student_name, sheet.title].filter(Boolean).join(' · ')) + '</p>'
          + '<div class="p-stack">';

        /* The player, and the sentence that makes the control legible rather
           than a malfunction. Both are constants from the server. */
        /* ⚠️ The heading states what is ACTUALLY being played, and it is read off
           the criterion the server put first — not off "does any criterion
           allow video", which said "Audio and video" directly above a notice
           explaining that the video was hidden. The notice only prints when the
           control is in force; a bias control that appears beside video the
           marker can see reads as a malfunction. */
        html += '<section class="p-card">'
          + '<div class="p-line p-lead"><span class="p-title">'
          + (showVideo ? 'Audio and video' : 'Listen without the video') + '</span></div>'
          + '<div class="i-player">'
          + '<button type="button" class="i-play" id="gradePlay" aria-label="Play the performance">'
          + '<i class="fa-solid fa-play" aria-hidden="true"></i></button>'
          + '<div class="p-row-main"><div class="i-wave" aria-hidden="true"></div>'
          + '<div class="p-caption p-num">'
          + (!sheet.artifact_url ? 'withheld for this criterion' : (showVideo ? 'audio and video' : 'audio only'))
          + '</div></div></div>'
          + (showVideo ? '' : '<p class="p-meta p-gap-t">' + esc(sheet.audio_only_notice || '') + '</p>')
          + '</section>';

        if (first) html += criterionCard(first);

        if (crit.length > 1) {
          html += '<section class="p-card is-flush"><div class="p-rows">'
            + crit.slice(1).map(function (c) {
              return '<div class="p-row"><span class="p-row-main"><span class="p-title">' + esc(c.label) + '</span></span>'
                + '<span class="p-caption">' + (c.show_video ? 'Video shown for this one' : 'Not marked') + '</span></div>';
            }).join('') + '</div></section>';
        }

        html += '<section class="p-card">'
          + '<div class="p-field"><label for="gradeComment">Comment to the student</label>'
          + '<textarea id="gradeComment" rows="3" placeholder="What to do next, not what went wrong"></textarea></div>'
          + '<div class="p-card-foot">'
          + '<div class="p-line"><span class="p-row-main"><span class="p-title">Send to a second marker</span>'
          + '<span class="p-caption">' + esc(sheet.second_marker
              ? 'This one is double-marked — 1 in 8 is, to check agreement'
              : '1 in 8 is double-marked to check agreement') + '</span></span></div>'
          + '</div>'
          + '<button type="button" class="p-action p-primary p-gap-t" id="gradeSave">Save and open the next student</button>'
          + '<p class="p-meta p-gap-t">' + esc(sheet.publication_notice || '') + '</p>'
          + '</section></div>';
        paint(html);
      })
      .catch(function (err) { paint(backTo('marking', 'Marking') + empty('triangle-exclamation', errOf(err, 'Could not open the marking sheet.'))); });
  }

  function criterionCard(c) {
    /* `mam_marking_bands()` returns DESCENDING — Distinction first — because
       `mam_marking_band_of()` walks it to find the first band a score reaches,
       and that order is load-bearing there. The design reads left-to-right as
       Pass · Merit · Distinction, so the copy is sorted here rather than the
       lib being reordered under its own lookup. */
    var bands = (c.bands || []).slice().sort(function (a, b) { return Number(a.low) - Number(b.low); });
    return '<section class="p-card" data-crit="' + esc(c.key) + '">'
      + '<div class="p-line p-lead"><span class="p-name">' + esc(c.label) + '</span>'
      + '<span class="p-caption">out of ' + esc(c.max) + '</span></div>'
      + '<div class="i-bands" role="group" aria-label="Band for ' + esc(c.label) + '">'
      + bands.map(function (b) {
        return '<button type="button" data-band="' + esc(b.band) + '" data-lo="' + esc(b.low) + '"'
          + ' aria-pressed="false">' + esc(b.label) + '<br>' + esc(b.low) + '–' + esc(b.high) + '</button>';
      }).join('')
      /* "Not assessed" is NOT a fourth grade — it is the absence of one, and a
         real 0 is an assessment where not marking is not. */
      + '<button type="button" class="is-none" data-band="none" aria-pressed="false">Not<br>assessed</button>'
      + '</div>'
      + '<div class="i-desc" id="critDesc">Choose a band to see its descriptor.</div>'
      + '<div class="i-step"><span class="p-body">Mark</span>'
      + '<button type="button" data-step="-1" aria-label="One mark lower">−</button>'
      + '<span class="i-step-val" id="markVal" aria-live="polite">—</span>'
      + '<button type="button" data-step="1" aria-label="One mark higher">+</button></div>'
      + '</section>';
  }

  /* ── 4 · Teach-backs ────────────────────────────────────────────────────── */

  function renderTeachbacks() {
    paint(loading('Loading teach-backs…'));
    screenReq('teachbacks').then(function (d) {
      var q = d.queue || [];
      if (!q.length) {
        paint(h1('Teach-backs') + empty('circle-check', 'Nothing waiting.')
          + '<div class="p-notice p-gap-t"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>' + esc(d.notice) + '</span></div>');
        return;
      }
      var first = q[0];
      paint(h1('Teach-backs', q.length + ' waiting · about two minutes each')
        + '<div class="p-stack">'
        + '<section class="p-card is-focus" data-tb="' + esc(first.id) + '">'
        + '<div class="p-who p-lead"><span class="p-avatar" aria-hidden="true">' + esc(initials(first.student_name)) + '</span>'
        + '<span class="p-who-main"><span class="p-name">' + esc(first.student_name) + '</span>'
        + '<span class="p-caption">' + esc([first.week_label, first.topic].filter(Boolean).join(' · ')) + '</span></span></div>'
        + '<div class="i-player p-lead"><button type="button" class="i-play" aria-label="Play the recording">'
        + '<i class="fa-solid fa-play" aria-hidden="true"></i></button>'
        + '<div class="i-wave p-row-main" aria-hidden="true"></div></div>'
        + (first.self_checks ? '<div class="i-desc p-lead"><div class="p-label">They told you</div>'
            + '<div class="p-body">' + esc(first.self_checks) + '</div></div>' : '')
        + '<div class="p-title p-lead">Did the explanation hold up?</div>'
        + '<div class="i-bands" role="group" aria-label="How the explanation held up">'
        /* The design asks "Did the explanation hold up?" in three words; the
           server's vocabulary is `MAM_TEACHBACK_DECISIONS`. The mapping is
           stated once, here, rather than the words being changed to match the
           column or the column being widened to match the words. */
        + [['more_prep', 'Not yet'], ['clarify', 'Mostly'], ['ready', 'Fully']].map(function (o) {
          return '<button type="button" data-tbverdict="' + o[0] + '" aria-pressed="false">' + o[1] + '</button>';
        }).join('') + '</div>'
        + '<div class="p-field p-gap-t"><label for="tbNote">Your reply</label>'
        + '<textarea id="tbNote" rows="3" placeholder="One sentence back — what to say differently next time"></textarea></div>'
        + '<button type="button" class="p-action p-primary p-gap-t" id="tbSend">Send and open the next</button>'
        + '<p class="p-meta p-gap-t">' + esc(d.notice) + '</p>'
        + '</section>'
        + (q.length > 1 ? '<section class="p-card is-flush"><div class="p-rows">'
            + q.slice(1).map(function (t) {
              return '<div class="p-row"><span class="p-avatar is-sm" aria-hidden="true">' + esc(initials(t.student_name)) + '</span>'
                + '<span class="p-row-main"><span class="p-title">' + esc(t.student_name) + '</span></span>'
                + '<span class="p-caption">' + esc(fmtDate(t.submitted_at)) + '</span></div>';
            }).join('') + '</div></section>' : '')
        + '</div>');
    }).catch(function (err) { paint(h1('Teach-backs') + empty('triangle-exclamation', errOf(err, 'Could not load teach-backs.'))); });
  }

  /* ── 5 · Termly report ──────────────────────────────────────────────────── */

  function renderReport(studentId) {
    paint(loading('Loading the report…'));
    screenReq('report', studentId ? '&student=' + encodeURIComponent(studentId) : '')
      .then(function (d) {
        var pr = d.progress || {};
        if (!d.student) {
          paint(h1('Termly report', pr.written + ' of ' + pr.total + ' written')
            + '<section class="p-card is-flush"><div class="p-rows">'
            + (d.students || []).map(function (s) {
              return '<button type="button" class="p-row" data-report="' + esc(s.id) + '">'
                + '<span class="p-avatar" aria-hidden="true">' + esc(initials(s.name)) + '</span>'
                + '<span class="p-row-main"><span class="p-title">' + esc(s.name) + '</span></span>'
                + '<i class="fa-solid fa-chevron-right p-row-go" aria-hidden="true"></i></button>';
            }).join('') + '</div></section>');
          return;
        }
        S.reportStudent = d.student;
        var k = d.student.known || {};
        var rows = [['Instrument', k.instrument], ['Working level', k.working_level],
                    ['Attendance', k.attendance], ['Repertoire', k.repertoire], ['Assessment', k.assessment]];
        paint(backTo('report', 'All students')
          + h1('Termly report', [d.student.name, d.student.term_label, pr.written + ' of ' + pr.total + ' written'].filter(Boolean).join(' · '))
          + '<div class="p-stack">'
          + '<section class="p-card"><div class="p-label p-lead">Filled in for you</div>'
          + '<dl class="i-known">' + rows.map(function (r) {
            return '<div><dt>' + esc(r[0]) + '</dt><dd' + (r[1] ? '' : ' class="is-none"') + '>'
              + esc(r[1] || 'Not recorded yet') + '</dd></div>';
          }).join('') + '</dl></section>'
          + '<section class="p-card">'
          + '<div class="p-field"><label for="teacherComment">Your comment</label>'
          + '<textarea id="teacherComment" rows="4" placeholder="Specific, and pointed at the next term">'
          + esc(d.student.comment || '') + '</textarea></div>'
          + '<div class="i-desc p-gap-t"><span class="p-strong">Two prompts:</span> ' + esc(d.prompts) + '</div>'
          + '<button type="button" class="p-action p-primary p-gap-t" id="reportSend">Send to the Examinations panel</button>'
          + '<p class="p-meta p-gap-t">' + esc(d.notice) + '</p>'
          + '</section></div>');
      })
      .catch(function (err) { paint(h1('Termly report') + empty('triangle-exclamation', errOf(err, 'Could not load the report.'))); });
  }

  /* ── 6 · Students ───────────────────────────────────────────────────────── */

  /**
   * ⚠️ Bands and sentences. There is no risk score here — the design: "a label
   * a child could be given is not a substitute for you ringing a guardian."
   * The provider sends `band_label` and `attention`, and this function has no
   * branch that computes, sorts by, or colours a rating.
   */
  function renderStudents() {
    paint(loading('Loading your students…'));
    screenReq('students').then(function (d) {
      S.students = d.students || [];
      paintStudents();
    }).catch(function (err) { paint(h1('Students') + empty('triangle-exclamation', errOf(err, 'Could not load your students.'))); });
  }

  function paintStudents() {
    var q = S.filter.toLowerCase();
    var list = S.students.filter(function (s) { return !q || String(s.name).toLowerCase().indexOf(q) !== -1; });
    paint(h1('Students')
      + '<div class="i-search p-lead"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>'
      + '<input type="search" id="stuSearch" value="' + esc(S.filter) + '" aria-label="Search your students"'
      + ' placeholder="Search ' + S.students.length + ' ' + plural(S.students.length, 'student', 'students') + '"></div>'
      + (list.length
        ? '<section class="p-card is-flush"><div class="p-rows">' + list.map(function (s) {
            return '<div class="p-row is-tall">'
              + '<span class="p-avatar" aria-hidden="true">' + esc(initials(s.name)) + '</span>'
              + '<span class="p-row-main"><span class="p-title">' + esc(s.name) + '</span>'
              /* The design's caption is the standing facts; the attention
                 sentence REPLACES them when there is one, because a row that
                 needs a person should not also be reciting an attendance
                 percentage. */
              + '<span class="p-caption">' + (s.attention
                  ? '<span class="p-accent">' + esc(s.attention) + '</span>'
                  : esc(s.summary || '')) + '</span></span>'
              + '<span class="p-caption p-strong">' + esc(s.band_label) + '</span></div>';
          }).join('') + '</div></section>'
        : empty('magnifying-glass', 'No student matches that.'))
      + '<div class="p-notice p-gap-t"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>'
      + 'Bands, and a plain sentence where something needs a person. There is no risk score here — a label a child '
      + 'could be given is not a substitute for you ringing a guardian.</span></div>');
    var inp = $('#stuSearch');
    if (inp && S.filter) { inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
  }

  /* ── 7 · Earnings & clearance ───────────────────────────────────────────── */

  function renderEarnings() {
    paint(loading('Loading your statement…'));
    screenReq('earnings').then(function (d) {
      var p = d.punctuality || {};
      var cl = d.clearance || {};
      var items = (cl.records || cl.items || []);
      var html = h1('Earnings', d.statement_notice)
        + '<div class="p-stack">'
        + '<section class="p-card"><div class="p-label">Balance</div>'
        + '<div class="p-fig p-money">' + esc(money(d.balance_cents)) + '</div></section>';

      var led = d.ledger || [];
      html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Statement</span></div><div class="p-rows">';
      html += led.length ? led.map(function (l) {
        var amt = Number(l.amount_cents || l.amount || 0);
        return '<div class="p-row"><span class="p-row-main"><span class="p-title">' + esc(l.reason_label || l.reason) + '</span>'
          + '<span class="p-caption">' + esc(fmtDate(l.created_at)) + '</span></span>'
          + '<span class="p-num p-strong' + (amt > 0 ? ' p-good' : '') + '">'
          + (amt > 0 ? '+' : '') + esc(money(amt)) + '</span></div>';
      }).join('') : '<div class="p-row"><span class="p-row-main"><span class="p-caption">Nothing recorded yet.</span></span></div>';

      /* ⚠️ A recorded lateness is shown AT ZERO, never omitted. An instructor
         who sees nothing cannot tell whether the academy noticed, disagreed, or
         never looked — and Labour Act s.5(1) is why there is no deduction. */
      if (p.recorded) {
        html += '<div class="p-row"><span class="p-row-main">'
          + '<span class="p-title">' + p.recorded + ' late ' + plural(p.recorded, 'arrival', 'arrivals') + ' recorded</span>'
          + '<span class="p-caption">' + esc(p.notice) + '</span></span>'
          + '<span class="p-num p-strong">' + esc(money(p.deducted_cents)) + '</span></div>';
      }
      html += '</div></section>';

      if (items.length) {
        html += '<section class="p-card is-flush"><div class="p-rows">' + items.map(function (v) {
          var ok = String(v.state || v.status || '') === 'valid' || v.valid === true;
          return '<div class="p-row"><i class="fa-solid fa-' + (ok ? 'shield-halved p-row-ico is-good' : 'triangle-exclamation p-row-ico is-warn') + '" aria-hidden="true"></i>'
            + '<span class="p-row-main"><span class="p-title">' + esc(v.label || v.clearance_type || 'Clearance') + '</span>'
            + '<span class="p-caption' + (ok ? '' : ' p-warn p-strong') + '">'
            + esc(v.expires_on ? 'Valid to ' + fmtDate(v.expires_on) : (v.note || '')) + '</span></span></div>';
        }).join('') + '</div></section>';
      }
      paint(html + '</div>');
    }).catch(function (err) { paint(h1('Earnings') + empty('triangle-exclamation', errOf(err, 'Could not load your statement.'))); });
  }

  /* ── Display & access ───────────────────────────────────────────────────── */

  /**
   * The same screen the guardian portal has, against the SAME storage key.
   *
   * That is deliberate and not a shortcut: it is one person's preference about
   * their own eyes, not a per-portal setting, and an instructor who is also a
   * guardian would otherwise have to set larger text twice and wonder which
   * one took. The pre-paint block in the markup reads the same key, so a
   * reload never shows one frame of the other theme.
   */
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

  /* ── Routing ────────────────────────────────────────────────────────────── */

  var SCREENS = {
    home: renderHome, register: renderRegister, marking: renderMarking,
    teachbacks: renderTeachbacks, report: renderReport, students: renderStudents,
    earnings: renderEarnings, a11y: renderA11y
  };
  /* Which bar item lights for a screen the bar does not itself carry. */
  var TAB_OF = { home: 'home', register: 'register', marking: 'marking', students: 'students' };

  function go(screen, arg) {
    if (!SCREENS[screen]) screen = 'home';
    S.screen = screen;
    closeSheet();
    $$('#nav button[data-screen]').forEach(function (b) {
      var on = b.dataset.screen === TAB_OF[screen];
      if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
    });
    location.hash = screen + (arg ? '/' + arg : '');
    pendingFocus = true;
    SCREENS[screen](arg);
  }

  /* The sheet claims `aria-modal="true"`, so it has to behave like one: focus
     moves in, Tab is trapped, Escape closes, and focus returns to the control
     that opened it. Declaring the role without the behaviour tells a screen
     reader the rest of the page is inert when it is not. */
  var sheetReturn = null;
  function openSheet(trigger) {
    var sh = $('#sheet');
    if (!sh) return;
    sheetReturn = trigger || document.activeElement;
    sh.hidden = false;
    var first = sh.querySelector('.p-row');
    if (first) first.focus();
  }
  function closeSheet() {
    var s = $('#sheet');
    if (!s || s.hidden) { sheetReturn = null; return; }
    s.hidden = true;
    if (sheetReturn && document.contains(sheetReturn)) sheetReturn.focus();
    sheetReturn = null;
  }

  document.addEventListener('keydown', function (e) {
    var sh = $('#sheet');
    if (!sh || sh.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); closeSheet(); return; }
    if (e.key !== 'Tab') return;
    var items = $$('button, [href], textarea, select, input', sh).filter(function (el) { return !el.disabled; });
    if (!items.length) return;
    var first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  /* ── Events. One delegated listener; every control is a real <button>. ───── */

  document.addEventListener('click', function (e) {
    var t = e.target;

    var sheetOpen = t.closest && t.closest('[data-sheet-open]');
    if (sheetOpen) { openSheet(sheetOpen); return; }
    if (t.closest && t.closest('[data-sheet-close]')) { closeSheet(); return; }
    if (t.closest && t.closest('#toastClose')) { $('#toast').hidden = true; return; }

    var nav = t.closest && t.closest('[data-screen]');
    if (nav) { go(nav.dataset.screen, nav.dataset.session || nav.dataset.student || null); return; }

    var mk = t.closest && t.closest('[data-sheet-mark]');
    if (mk) { S.screen = 'marking'; renderGrade(mk.dataset.sheetMark); return; }

    var rep = t.closest && t.closest('[data-report]');
    if (rep) { go('report', rep.dataset.report); return; }

    var mark = t.closest && t.closest('.i-marks button');
    if (mark) { markOne(mark, mark.dataset.student, mark.dataset.mark); return; }

    var auth = t.closest && t.closest('.i-auth button');
    if (auth) { markOne(auth, auth.dataset.student, auth.dataset.auth); return; }

    if (t.closest && t.closest('#allPresent')) {
      write(t.closest('#allPresent'),
        API.request('instructor/session-checkin-all', { method: 'POST', body: { session_id: S.sessionId } }),
        function () { renderRegister(S.sessionId); }, 'Everyone marked present.');
      return;
    }

    var band = t.closest && t.closest('.i-bands button');
    if (band) {
      var group = band.parentNode;
      $$('button', group).forEach(function (b) { b.setAttribute('aria-pressed', b === band ? 'true' : 'false'); });
      if (band.dataset.band) chooseBand(band);
      return;
    }

    var step = t.closest && t.closest('.i-step button');
    if (step) { stepMark(Number(step.dataset.step)); return; }

    if (t.closest && t.closest('#gradeSave')) {
      /* The marks recorded so far go as the rubric; `null` for a criterion
         means Not assessed and travels as null, never as 0. The outcome is
         'accepted' — this screen marks work, it does not reject it; a
         resubmission is a different decision made on a different screen. */
      var gbtn = t.closest('#gradeSave');
      write(gbtn, API.request('instructor/feedback', {
        method: 'POST',
        body: {
          submission_id: S.sheet && S.sheet.submission_id,
          outcome: 'accepted',
          comments: ($('#gradeComment') || {}).value || '',
          rubric: S.marks
        }
      }), function () {
        /* "…and open the next student": back to the queue, which is now one
           shorter, and straight into its head. */
        renderMarking();
      }, 'Mark saved. It stays unpublished until the panel ratifies it.');
      return;
    }

    if (t.closest && t.closest('#tbSend')) {
      var tbBtn = t.closest('#tbSend');
      var card = document.querySelector('[data-tb]');
      var chosen = document.querySelector('.i-bands button[data-tbverdict][aria-pressed="true"]');
      if (!chosen) { toast('Choose whether the explanation held up first.', 'bad'); return; }
      write(tbBtn, API.request('instructor/preclass', {
        method: 'POST',
        body: {
          action: 'review',
          teachback_id: card && Number(card.dataset.tb),
          decision: chosen.dataset.tbverdict,
          feedback: ($('#tbNote') || {}).value || ''
        }
      }), function () { renderTeachbacks(); }, 'Sent. Formative only — it does not enter the band.');
      return;
    }

    if (t.closest && t.closest('#reportSend')) {
      var btn = t.closest('#reportSend');
      write(btn, API.request('instructor/report', {
        method: 'POST',
        body: {
          student_id: S.reportStudent && S.reportStudent.id,
          cohort_id: S.reportStudent && S.reportStudent.cohort_id,
          comment: ($('#teacherComment') || {}).value || ''
        }
      }), null, 'Sent to the Examinations panel.');
      return;
    }

    if (t.hasAttribute && (t.hasAttribute('data-text-size') || t.hasAttribute('data-theme-set') || t.hasAttribute('data-toggle'))) {
      e.preventDefault();
      var root = document.documentElement, prefs = {};
      try { prefs = JSON.parse(localStorage.getItem('mam-guardian-a11y') || '{}'); } catch (er) { prefs = {}; }
      if (t.hasAttribute('data-text-size')) {
        var tv = t.getAttribute('data-text-size');
        prefs.text = tv === 'md' ? null : tv;
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
        var tk = t.getAttribute('data-toggle');
        prefs[tk] = !prefs[tk];
        var attr = tk === 'ease' ? 'data-ease' : 'data-lowdata';
        if (prefs[tk]) root.setAttribute(attr, '1'); else root.removeAttribute(attr);
      }
      try { localStorage.setItem('mam-guardian-a11y', JSON.stringify(prefs)); } catch (er) {}
      renderA11y();
      return;
    }

    if (t.closest && t.closest('#signOut')) {
      API.request('auth/logout', { method: 'POST' }).catch(function () {})
        .then(function () { location.href = '/portal/login'; });
    }
  });

  document.addEventListener('input', function (e) {
    if (e.target && e.target.id === 'stuSearch') { S.filter = e.target.value; paintStudents(); }
  });

  /* A band selects the mark at the BOTTOM of its range, and the stepper moves
     within it. The descriptor is shown because a marker choosing "Merit" is
     choosing a description, and the range is what stops it drifting. */
  function chooseBand(btn) {
    var card = btn.closest('[data-crit]');
    if (!card) return;
    var key = card.dataset.crit;
    if (btn.dataset.band === 'none') {
      S.marks[key] = null;
      $('#markVal').textContent = '—';
      $('#critDesc').textContent = 'Not assessed. This is not a zero — a real 0 is an assessment, and not marking is not.';
      return;
    }
    var lo = Number(btn.dataset.lo);
    S.marks[key] = lo;
    $('#markVal').textContent = String(lo);
    var crit = (S.sheet.criteria || []).filter(function (c) { return c.key === key; })[0];
    var b = ((crit && crit.bands) || []).filter(function (x) { return String(x.band) === btn.dataset.band; })[0];
    /* The design prints a per-BAND descriptor. The marking lib has no such
       field — it carries one `help` line per CRITERION — so what is shown is
       the band, its range, and that help. Inventing a descriptor per band would
       be putting words in an examiner's mouth, and a band boundary is a pass or
       a fail for a real child. Flagged in the report as an open question. */
    $('#critDesc').innerHTML = '<span class="p-strong">' + esc((b && b.label) || '') + ' · '
      + esc((b && b.low) + '–' + (b && b.high)) + '</span>'
      + (crit && crit.help ? ' ' + esc(crit.help) : '');
  }

  function stepMark(delta) {
    var card = $('[data-crit]');
    if (!card) return;
    var key = card.dataset.crit;
    if (S.marks[key] == null) return;
    var crit = (S.sheet.criteria || []).filter(function (c) { return c.key === key; })[0];
    var max = crit ? Number(crit.max) : 30;
    S.marks[key] = Math.max(0, Math.min(max, S.marks[key] + delta));
    $('#markVal').textContent = String(S.marks[key]);
  }

  window.addEventListener('hashchange', function () {
    var parts = location.hash.replace('#', '').split('/');
    if (parts[0] && parts[0] !== S.screen) go(parts[0], parts[1]);
  });

  window.addEventListener('offline', function () {
    toast('You are offline. What is on screen is the last thing we loaded, and nothing you '
      + 'change will be saved until you have data again.', 'bad');
  });

  /* ── Boot ───────────────────────────────────────────────────────────────── */

  screenReq('home')
    .then(function (d) {
      S.home = d;
      var me = d.me || {};
      $('#whoName').textContent = me.name || 'Instructor';
      $('#whoMeta').textContent = ['Instructor', d.term_label].filter(Boolean).join(' · ');
      $('#railName').textContent = me.name || 'Instructor';
      $('#railInitials').textContent = initials(me.name);

      /* The rail's clearance chip. It stays HIDDEN until the earnings payload
         has actually said something — a vetting state is not a thing to guess
         at, and an empty chip reading "Cleared" would be the worst possible
         default. */
      screenReq('earnings').then(function (e) {
        var items = (e.clearance && (e.clearance.records || e.clearance.items)) || [];
        if (!items.length) return;
        var lapsed = items.filter(function (v) {
          return !(String(v.state || v.status || '') === 'valid' || v.valid === true);
        });
        var chip = $('#railClear');
        chip.hidden = false;
        if (lapsed.length) {
          chip.className = 'p-rail-clear is-warn';
          $('#railClearText').textContent = lapsed.length + ' clearance ' + plural(lapsed.length, 'item', 'items') + ' to renew';
        } else {
          var soonest = items.map(function (v) { return v.expires_on; }).filter(Boolean).sort()[0];
          $('#railClearText').textContent = soonest ? 'Cleared to ' + fmtDate(soonest) : 'Cleared';
        }
      }).catch(function () { /* the rail chip is never worth an error state */ });

      return screenReq('more').catch(function () { return null; });
    })
    .then(function (counts) {
      if (counts) {
        if (counts.marking) {
          var nb = $('#markBadge'); nb.hidden = false;
          nb.setAttribute('aria-label', counts.marking + ' waiting to be marked');
          var sb = $('#sheetMarkBadge'); sb.hidden = false; sb.textContent = String(counts.marking);
        }
        if (counts.teachbacks) {
          var tb = $('#sheetTbBadge'); tb.hidden = false; tb.textContent = String(counts.teachbacks);
        }
      }
      var parts = location.hash.replace('#', '').split('/');
      go(SCREENS[parts[0]] ? parts[0] : 'home', parts[1]);
    })
    .catch(function (err) {
      var code = (err && err.body && err.body.error) || '';
      if (code === 'unauthorized' || code === 'forbidden' || (err && err.status === 401)) {
        location.href = '/portal/login?next=' + encodeURIComponent('/portal/instructor/');
        return;
      }
      paint(empty('triangle-exclamation', errOf(err, 'Could not load your teaching.')
        + '<br><br>If this keeps happening, the academy office can help.'));
    });
})();
