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
    if (P && P.topTitle) P.topTitle(main);
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
        /* Under `enforce`, an uncleared teacher of a class with children gets no
           roster (vetting.php). Said in words — an empty register reads as a class
           nobody is enrolled in. */
        if (d.refused) { paint(h1('Register', d.session.cohort_title || '') + empty('lock', esc(d.save_notice))); return; }
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
          /* The online class: the call itself counts who was there and for how
             long (live-classes.php); "Take the register from the call" is on that page. */
          + '<a class="p-outline" href="/portal/live.html?session=' + esc(S.sessionId) + '"><i class="fa-solid fa-video" aria-hidden="true"></i> Online class</a>'
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
      + collectLine(r)
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
      var q = d.queue || [], lq = d.lessons || [], xq = d.exams || [], sq = d.spaces || [];
      if (!q.length && !lq.length && !xq.length && !sq.length) { paint(h1('Marking') + empty('circle-check', 'Nothing waiting to be marked.')); return; }
      if (!q.length) {
        var other = lq.length + xq.length + sq.length;
        paint(h1('Marking', other + ' ' + plural(other, 'item', 'items') + ' waiting, oldest first') + lessonMarking(lq) + examMarking(xq) + spaceMarking(sq));
        return;
      }
      paint(h1('Marking', q.length + ' ' + plural(q.length, 'performance', 'performances') + ' waiting, oldest first')
        + lessonMarking(lq) + examMarking(xq) + spaceMarking(sq)
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
   * Work handed in to a class space (api/modules/community/lib/spaces.php) from
   * the classes this teacher teaches. The mark is the space's own review — the
   * same record the space shows — so it posts to /api/spaces, not a copy.
   */
  function spaceMarking(sq) {
    if (!sq.length) return '';
    return '<h2 class="p-label p-gap-t">Class space work</h2>' + sq.map(function (w) {
      var sub = [w.space_title, w.submitted_at ? 'handed in ' + String(w.submitted_at).slice(0, 10) : null].filter(Boolean).join(' · ');
      return '<section class="p-card i-lsn" data-space-card="' + esc(w.id) + '">'
        + '<div class="p-title">' + esc(w.student_name || 'A student') + ' — ' + esc(w.task_title || 'Handed-in work') + '</div>'
        + (sub ? '<p class="p-caption">' + esc(sub) + '</p>' : '')
        + '<div class="i-lsn-row p-gap-t"><label class="p-field"><span>' + esc('Mark out of ' + w.max_score) + '</span>'
        + '<input type="number" inputmode="numeric" min="0" max="' + esc(w.max_score) + '" step="1" data-space-score></label></div>'
        + '<label class="p-field p-gap-t"><span>What you would say to them</span><textarea maxlength="3000" data-space-feedback></textarea></label>'
        + '<button type="button" class="p-primary" data-space-mark="' + esc(w.id) + '">Save the mark</button></section>';
    }).join('');
  }

  /**
   * Written answers to lesson questions (api/modules/learning/lib/lessons.php). Each attempt is
   * a card: the question, what a good answer looks like, the student's answer
   * and a mark out of the question's worth. Only the questions a person marks
   * arrive here — multiple choice and put-in-order were marked by the server
   * and are not the teacher's to change.
   */
  function lessonMarking(lq) {
    if (!lq.length) return '';
    return '<h2 class="p-label p-gap-t">Lesson answers</h2>' + lq.map(function (a) {
      return '<section class="p-card i-lsn" data-lsn-card="' + esc(a.progress_id) + '">'
        + '<div class="p-title">' + esc(a.student_name) + '</div>'
        + '<p class="p-caption">' + esc([a.course_name, 'Week ' + a.week + ' · ' + a.title].join(' · ')) + '</p>'
        + a.items.map(function (it) {
          var ans = it.answer == null ? '' : String(it.answer);
          var shown = it.kind === 'evidence' && /^https:\/\//i.test(ans)
            ? '<a href="' + esc(ans) + '" target="_blank" rel="noopener">Open the recording or photo</a>'
            : esc(ans);
          return '<div class="i-lsn-item p-gap-t" data-check="' + esc(it.check_id) + '">'
            + '<div class="p-label">' + esc(it.prompt) + '</div>'
            + (it.rubric ? '<p class="p-caption">' + esc('A good answer: ' + it.rubric) + '</p>' : '')
            + '<p class="p-body i-lsn-ans">' + shown + '</p>'
            /* The assistant's mark is a suggestion the teacher can change; a
               held answer is one a person must read — say which, plainly. */
            + (it.held ? '<p class="p-notice is-warn"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i><span>Held for a person to read: the words may be about the child\'s safety. The safeguarding lead has been told.</span></p>' : '')
            + (it.ai_awarded != null ? '<p class="p-caption">' + esc('The assistant gave ' + it.ai_awarded + ' of ' + it.points + ' — change it if you disagree.') + '</p>' : '')
            + '<div class="i-lsn-row"><label class="p-field"><span>' + esc('Mark out of ' + it.points) + '</span>'
            + '<input type="number" inputmode="decimal" min="0" max="' + esc(it.points) + '" step="0.5" data-award' + (it.ai_awarded != null ? ' value="' + esc(it.ai_awarded) + '"' : '') + '></label>'
            + '<label class="p-field"><span>A line for the student (optional)</span><input type="text" maxlength="1000" data-comment></label></div></div>';
        }).join('')
        + '<label class="p-field p-gap-t"><span>A note on the whole attempt (optional)</span><textarea maxlength="2000" data-feedback></textarea></label>'
        + '<button type="button" class="p-primary" data-lsn-mark="' + esc(a.progress_id) + '">Save the marks</button></section>';
    }).join('');
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
          /* Where the report is, in the panel's words. A ratified report is the
             family's record and is not edited (the route refuses), so the send
             button is replaced by the fact rather than left to fail. */
          + (d.student.status ? '<p class="p-meta p-gap-t">' + esc({
              draft: 'With the Examinations panel — not yet moderated. You can still change your comment.',
              moderating: 'Being moderated by the Examinations panel. You can still change your comment.',
              ratified: 'Ratified and released to the student and their guardians. It can no longer be edited.'
            }[d.student.status] || '') + '</p>' : '')
          + (d.student.status === 'ratified' ? ''
            : '<button type="button" class="p-action p-primary p-gap-t" id="reportSend">'
              + (d.student.status ? 'Update and resend to the panel' : 'Send to the Examinations panel') + '</button>')
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
            return '<button type="button" class="p-row is-tall" data-screen="lessons" data-student="' + esc(s.id) + '">'
              + '<span class="p-avatar" aria-hidden="true">' + esc(initials(s.name)) + '</span>'
              + '<span class="p-row-main"><span class="p-title">' + esc(s.name) + '</span>'
              /* The design's caption is the standing facts; the attention
                 sentence REPLACES them when there is one, because a row that
                 needs a person should not also be reciting an attendance
                 percentage. */
              + '<span class="p-caption">' + (s.attention
                  ? '<span class="p-accent">' + esc(s.attention) + '</span>'
                  : esc(s.summary || '')) + '</span></span>'
              + '<span class="p-caption p-strong">' + esc(s.band_label) + '</span>'
              + '<i class="fa-solid fa-chevron-right p-row-go" aria-hidden="true"></i></button>';
          }).join('') + '</div></section>'
        : empty('magnifying-glass', 'No student matches that.'))
      + '<div class="p-notice p-gap-t"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>'
      + 'Bands, and a plain sentence where something needs a person. There is no risk score here — a label a child '
      + 'could be given is not a substitute for you ringing a guardian.</span></div>');
    var inp = $('#stuSearch');
    if (inp && S.filter) { inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
  }

  /* ── 6b · One student's lessons ─────────────────────────────────────────────

     The lessons a teacher teaches this child, and where they stand on each
     (api/modules/learning/lib/lessons.php). A lesson done in the room is ticked here — it is the
     only record a question-free syllabus week can have. An untick takes back
     only a tick: a lesson the student did online stays theirs, and the server
     refuses it, so this screen offers it only where it can work. */
  function lessonState(l) {
    if (l.state === 'completed') return l.ticked ? 'Ticked done' : 'Done online' + (l.best_pct != null ? ' · ' + l.best_pct + '%' : '');
    if (l.pending) return 'Answers waiting for you';
    if (l.state === 'attempted') return 'Tried' + (l.best_pct != null ? ' · best ' + l.best_pct + '%' : '');
    if (l.state === 'started') return 'Opened';
    return 'Not started';
  }
  /**
   * Exam sittings (lesson-exams.php): written answers to mark and an appeal to
   * answer. Only the written questions are shown — the machine-marked ones are
   * not the teacher's to change. An appeal needs a sentence back, upheld or not.
   */
  function examMarking(xq) {
    if (!xq.length) return '';
    return '<h2 class="p-label p-gap-t">Exams</h2>' + xq.map(function (x) {
      return '<section class="p-card i-lsn" data-ex-card="' + esc(x.sitting_id) + '">'
        + '<div class="p-title">' + esc(x.student_name) + '</div>'
        + '<p class="p-caption">' + esc([x.course_name, x.kind === 'trial' ? 'Course trial' : (x.lesson_title || 'Lesson exam')].join(' · ')) + '</p>'
        + (x.appeal ? '<div class="p-notice p-gap-t"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i><span>' + esc('Appeal: ' + x.appeal) + '</span></div>' : '')
        + x.items.map(function (it) {
          return '<div class="i-lsn-item p-gap-t" data-check="' + esc(it.check_id) + '">'
            + '<div class="p-label">' + esc(it.prompt) + '</div>'
            + (it.rubric ? '<p class="p-caption">' + esc('A good answer: ' + it.rubric) + '</p>' : '')
            + '<p class="p-body i-lsn-ans">' + esc(it.answer == null ? '(no answer)' : String(it.answer)) + '</p>'
            + (it.hold ? '<p class="p-notice is-warn"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i><span>Held for a person to read: the words may be about the child\'s safety.</span></p>' : '')
            + '<div class="i-lsn-row"><label class="p-field"><span>' + esc('Mark out of ' + it.points) + '</span>'
            + '<input type="number" inputmode="decimal" min="0" max="' + esc(it.points) + '" step="0.5" data-award' + (it.awarded != null ? ' value="' + esc(it.awarded) + '"' : '') + '></label>'
            + '<label class="p-field"><span>A line for the student (optional)</span><input type="text" maxlength="600" data-comment></label></div></div>';
        }).join('')
        + (x.appeal ? '<label class="p-field p-gap-t"><span>Your answer to the appeal</span><textarea maxlength="2000" data-appeal-outcome></textarea></label>' : '')
        + '<button type="button" class="p-primary p-gap-t" data-ex-mark="' + esc(x.sitting_id) + '">Save</button></section>';
    }).join('');
  }

  function renderStudentLessons(studentId) {
    paint(loading('Loading their lessons…'));
    API.request('instructor/lessons?student=' + encodeURIComponent(studentId || ''), { method: 'GET' }).then(function (d) {
      var html = backTo('students', 'Students') + h1((d.student && d.student.name) || 'Student', 'Lessons on the courses you teach them')
        + '<div class="p-actions p-gap-t"><button type="button" class="p-outline" data-screen="growth" data-student="' + esc(studentId) + '"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i> Growth, practice and goals</button>'
        + '<button type="button" class="p-outline" data-screen="incidents" data-student="' + esc(studentId) + '"><i class="fa-solid fa-kit-medical" aria-hidden="true"></i> Incidents</button>'
        + '<button type="button" class="p-outline" data-screen="collect" data-student="' + esc(studentId) + '"><i class="fa-solid fa-person-walking-arrow-right" aria-hidden="true"></i> Who may collect them</button></div>';
      var cs = d.courses || [];
      if (!cs.length) { paint(html + empty('book-open', 'No published lessons on the courses you teach them yet.')); return; }
      var lv = d.levels || [];
      paint(html + cs.map(function (c) {
        var t = c.tier || {};
        var curLv = c.placed_level_id;
        return '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">'
          + esc(c.course_name + (c.level_name ? ' · ' + c.level_name : '')) + '</span>'
          + '<span class="p-caption p-num">' + esc(c.done + ' of ' + c.total + ' done') + '</span></div>'
          + (t.name ? '<p class="p-caption i-lsn-pad">' + esc('Tier: ' + (t.tier ? t.name : 'Not started') + ' · ' + t.next) + '</p>' : '')
          /* Placement is the LESSON level, never the class or the price. */
          + (lv.length ? '<div class="i-lsn-place i-lsn-pad"><label class="p-field"><span>Lesson level</span><select data-place-level="' + esc(c.course_id) + '">'
            + lv.map(function (x) { return '<option value="' + esc(x.id) + '"' + (x.id === curLv ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('')
            + '</select></label><label class="p-field"><span>Why (optional)</span><input type="text" maxlength="300" data-place-reason="' + esc(c.course_id) + '"></label>'
            + '<button type="button" class="p-outline" data-place="' + esc(c.course_id) + '" data-student-id="' + esc(d.student.id) + '">Move</button></div>'
            + '<p class="p-caption i-lsn-pad">Moves which lessons they work on. Their class, its places and the fees do not change.</p>' : '')
          + '<div class="p-rows">'
          + c.lessons.map(function (l) {
            var act = l.state !== 'completed'
              ? '<button type="button" class="p-outline i-lsn-tick" data-lsn-tick="' + esc(l.id) + '" data-student-id="' + esc(d.student.id) + '" data-done="1"'
                + ' aria-label="' + esc('Tick “' + l.title + '” done') + '">Tick done</button>'
              : (l.ticked ? '<button type="button" class="p-outline i-lsn-tick" data-lsn-tick="' + esc(l.id) + '" data-student-id="' + esc(d.student.id) + '" data-done="0"'
                + ' aria-label="' + esc('Take back the tick on “' + l.title + '”') + '">Untick</button>' : '');
            var where = l.stage === 'catchup' ? ' · catch-up' : (l.stage === 'early' ? ' · opened early' : '');
            return '<div class="p-row"><span class="i-lsn-wk p-num">' + esc('W' + l.week) + '</span>'
              + '<span class="p-row-main"><span class="p-title">' + esc(l.title) + '</span>'
              + '<span class="p-caption">' + esc(lessonState(l) + where + (l.late ? ' · overdue' : '') + (l.syllabus_only ? ' · no questions' : '')) + '</span></span>' + act + '</div>';
          }).join('') + '</div>'
          /* A teacher can SEND material for a lesson; the curriculum office decides whether it goes live. */
          + '<details class="i-lsn-pad i-lsn-mat"><summary>Send a material for one of these lessons</summary>'
          + '<label class="p-field p-gap-t"><span>Lesson</span><select data-mat-lesson>' + c.lessons.map(function (l) {
            return '<option value="' + esc(l.id) + '">' + esc('Week ' + l.week + ' · ' + l.title) + '</option>'; }).join('') + '</select></label>'
          + '<label class="p-field"><span>Title</span><input type="text" maxlength="200" data-mat-title></label>'
          + '<label class="p-field"><span>Link (https://)</span><input type="url" inputmode="url" placeholder="https://" data-mat-url></label>'
          + '<button type="button" class="p-outline p-gap-t" data-mat-send>Send for approval</button></details></section>';
      }).join(''));
    }).catch(function (err) { paint(backTo('students', 'Students') + h1('Lessons') + empty('triangle-exclamation', errOf(err, 'Could not load their lessons.'))); });
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
  function renderA11y() { paint(P.a11yHtml()); }   // the screen lives in the shared kit (portal.js)

  /* Who took this child home (safeguarding.php, v104). Asked only of a child who
     was in the room for an in-person class; the server sends `collect: null`
     where there is nothing to ask. A collection by somebody NOT on the child's
     list is written in the warning colour — the officer has already been told. */
  function collectLine(r) {
    var c = r.collect;
    if (!c) return '';
    if (c.done) {
      var words = { authorised: '', not_listed: ' — not on the list; the safeguarding officer was told', no_list: ' — no list on file', adult: '' };
      return '<p class="p-caption i-collect' + (c.done.verdict === 'not_listed' ? ' p-bad' : '') + '">Collected by ' + esc(c.done.name)
        + ' at ' + esc(fmtTime(c.done.at)) + esc(words[c.done.verdict] || '') + '</p>';
    }
    return '<p class="p-caption i-collect">' + (c.on_file ? '' : 'No collectors on file. ')
      + '<button type="button" class="p-link" data-screen="collect" data-student="' + esc(r.student_id) + '.' + esc(S.sessionId) + '">'
      + 'Record who collected ' + esc((r.name || '').split(' ')[0]) + '</button></p>';
  }

  /* One child's collectors, and — reached from the register — the sign-out
     itself. arg is "studentId" or "studentId.sessionId". */
  function renderCollect(arg) {
    var bits = String(arg || '').split('.'), sid = Number(bits[0]), sess = Number(bits[1] || 0);
    S.collect = { student: sid, session: sess };
    paint(loading('Loading…'));
    API.request('collectors?student=' + encodeURIComponent(sid) + (sess ? '&session=' + encodeURIComponent(sess) : ''), { method: 'GET' }).then(function (d) {
      var name = (d.student && d.student.name) || 'This student', first = name.split(' ')[0];
      var back = sess
        ? '<button type="button" class="p-back" data-screen="register" data-session="' + esc(sess) + '"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Register</button>'
        : '<button type="button" class="p-back" data-screen="lessons" data-student="' + esc(sid) + '"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Back</button>';
      var list = d.collectors || [];
      var html = back + h1(name, sess ? 'Who is taking ' + first + ' home?' : 'Who may collect ' + first) + '<div class="p-stack">';
      if (sess && d.collected) {
        html += '<section class="p-card"><p class="p-body">Already recorded: collected by <b>' + esc(d.collected.collector_name)
          + '</b> at ' + esc(fmtTime(d.collected.collected_at)) + '.</p></section>';
      } else if (sess) {
        html += '<section class="p-card"><h2 class="p-title">On the list</h2>'
          + (list.length
            ? '<div class="p-stack">' + list.map(function (c) {
                return '<button type="button" class="p-outline i-collector" data-collect-by="' + esc(c.id) + '">' + esc(c.name)
                  + (c.relation ? ' · ' + esc(c.relation) : '') + (c.phone ? ' · ' + esc(c.phone) : '') + '</button>';
              }).join('') + '</div>'
            : '<p class="p-body">Nobody is on ' + esc(first) + '\'s list yet. Record who collected them below, then add the family\'s collectors so the register can check next time.</p>')
          + '</section>'
          + '<section class="p-card"><h2 class="p-title">Somebody else</h2>'
          + '<div class="p-field"><label for="colName">Their name</label><input id="colName" maxlength="160" autocomplete="off"></div>'
          + (list.length
            ? '<div class="p-field"><label for="colWhy">How you checked it was all right</label><input id="colWhy" maxlength="500" placeholder="I phoned the mother on her number on file"></div>'
              + '<p class="p-caption">They are not on the list, so the safeguarding officer is told as soon as you record this.</p>'
            : '')
          + '<button type="button" class="p-primary" data-collect-other="1">Record it</button></section>';
      }
      /* From the register the job is the sign-out, so the list editor folds away;
         from the student's page it is the job, so it is open. */
      html += (sess ? '<details class="p-card i-col-edit"><summary class="p-title">Change who may collect ' + esc(first) + '</summary>'
                    : '<section class="p-card"><h2 class="p-title">Who may collect ' + esc(first) + ' (up to ' + esc(d.max) + ')</h2>')
        + '<p class="p-caption">The family sees this list in their portal and asks the academy to change it. Phone numbers stay with staff and the family.</p>'
        + '<div class="p-stack">';
      var rows = list.slice();
      var upto = Math.min(d.max || 8, rows.length + 2);
      while (rows.length < upto) rows.push({});
      rows.forEach(function (c, i) {
        html += '<fieldset class="p-field i-col-row"><legend class="p-caption">Person ' + (i + 1) + '</legend>'
          + '<label for="cn' + i + '">Name</label><input id="cn' + i + '" data-col-name value="' + esc(c.name || '') + '" maxlength="160">'
          + '<label for="cr' + i + '">Relationship</label><input id="cr' + i + '" data-col-rel value="' + esc(c.relation || '') + '" maxlength="60">'
          + '<label for="cp' + i + '">Phone</label><input id="cp' + i + '" data-col-phone type="tel" value="' + esc(c.phone || '') + '" maxlength="24"></fieldset>';
      });
      html += '</div><p class="p-caption">To take somebody off, clear their name.</p>'
        + '<button type="button" class="p-outline" data-collect-save="1">Save the list</button>' + (sess ? '</details>' : '</section>');
      if ((d.recent || []).length) {
        html += '<section class="p-card"><h2 class="p-title">Recent sign-outs</h2>' + d.recent.map(function (c) {
          return '<p class="p-caption' + (c.verdict === 'not_listed' ? ' p-bad' : '') + '">' + esc(fmtTime(c.collected_at)) + ' · ' + esc(c.collector_name)
            + (c.verdict === 'not_listed' ? ' (not on the list)' : '') + (c.recorded_by_name ? ' · recorded by ' + esc(c.recorded_by_name) : '') + '</p>';
        }).join('') + '</section>';
      }
      paint(html + '</div>');
    }).catch(function (err) { paint(h1('Collection') + empty('triangle-exclamation', errOf(err, 'Could not load the collectors.'))); });
  }

  /* ── Routing ────────────────────────────────────────────────────────────── */

  var SCREENS = {
    home: renderHome, register: renderRegister, marking: renderMarking,
    teachbacks: renderTeachbacks, report: renderReport, students: renderStudents, lessons: renderStudentLessons,
    earnings: renderEarnings, a11y: renderA11y, space: renderSpace, concern: renderConcern, growth: renderGrowth, incidents: renderIncidents, collect: renderCollect
  };
  /* Incidents for one student (safeguarding.php). A safeguarding one goes to the
     officer, who alone decides who is told; its details come back restricted. */
  function renderIncidents(studentId) {
    S.incStudent = studentId;
    paint(loading('Loading…'));
    API.request('incidents?student=' + encodeURIComponent(studentId), { method: 'GET' }).then(function (d) {
      var kinds = d.kinds || {};
      var html = '<button type="button" class="p-back" data-screen="lessons" data-student="' + esc(studentId) + '"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Back</button>'
        + h1('Incidents', 'First aid, health, behaviour, collection — and anything that worries you') + '<div class="p-stack">';
      (d.incidents || []).forEach(function (i) {
        html += '<section class="p-card"><h2 class="p-title">' + esc(kinds[i.kind] || i.kind) + ' · ' + esc(fmtTime(i.happened_at)) + '</h2>'
          + '<p class="p-body">' + esc(i.what) + '</p>'
          + '<p class="p-caption">' + (i.status === 'closed' ? 'Closed' + (i.outcome ? ': ' + esc(i.outcome) : '') : 'Open') + (i.told_at ? ' · family told' : '') + '</p>'
          + (i.kind !== 'safeguarding' && i.status === 'open'
             ? '<div class="p-actions">' + (!i.told_at && i.family_note ? '<button type="button" class="p-outline" data-inc-tell="' + esc(i.id) + '">Tell the family</button>' : '')
               + '<button type="button" class="p-outline" data-inc-close="' + esc(i.id) + '">Close</button></div>' : '')
          + '</section>';
      });
      html += '<section class="p-card"><h2 class="p-title">Record an incident</h2>'
        + '<div class="p-field"><label for="incKind">What kind</label><select id="incKind">'
        + Object.keys(kinds).map(function (k) { return '<option value="' + esc(k) + '">' + esc(kinds[k]) + '</option>'; }).join('') + '</select></div>'
        + '<div class="p-field"><label for="incWhat">What happened (stays with staff)</label><textarea id="incWhat" maxlength="4000"></textarea></div>'
        + '<div class="p-field"><label for="incDone">What you did</label><input id="incDone" maxlength="2000"></div>'
        + '<div class="p-field"><label for="incFamily">What the family will be told (not for safeguarding)</label><textarea id="incFamily" maxlength="1000"></textarea></div>'
        + '<button type="button" class="p-primary" data-inc-save="' + esc(studentId) + '">Record it</button>'
        + '<p class="p-caption p-gap-t">Choose Safeguarding for anything that worries you about the child\'s safety: it goes straight to the safeguarding officer, who decides who is told.</p></section></div>';
      paint(html);
    }).catch(function (err) { paint(h1('Incidents') + empty('triangle-exclamation', errOf(err, 'Could not load incidents.'))); });
  }
  /* One student's growth — practice, attendance, marks, goals, and (teachers only) the adaptive suggestion. */
  function renderGrowth(studentId) { MAM_GROWTH.render(main, { toast: toast, paint: paint, student: studentId, back: function () { go('lessons', studentId); } }); }
  /* Class spaces and the safeguarding form: shared (assets/js/portal-spaces.js). */
  function renderSpace() { MAM_SPACES.mount(main, { toast: toast, paint: paint }); }
  function renderConcern() { MAM_SPACES.concernForm(main, { toast: toast, paint: paint }); }
  /* Which bar item lights for a screen the bar does not itself carry. */
  var TAB_OF = { home: 'home', register: 'register', marking: 'marking', students: 'students', lessons: 'students' };

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
    /* Save the marks on one lesson attempt. A blank mark is left unmarked, not
       zero — the server keeps that answer waiting. */
    var tk = e.target && e.target.closest && e.target.closest('[data-lsn-tick]');
    if (tk) {
      var sid = tk.dataset.studentId;
      write(tk, API.request('instructor/lessons', { method: 'POST', body: { action: 'tick', lesson_id: Number(tk.dataset.lsnTick),
        student_id: Number(sid), done: tk.dataset.done === '1' } }),
        function () { renderStudentLessons(sid); }, tk.dataset.done === '1' ? 'Ticked done.' : 'Tick taken back.');
      return;
    }
    var col = e.target && e.target.closest && e.target.closest('[data-collect-by],[data-collect-other],[data-collect-save]');
    if (col && S.collect) {
      var C = S.collect;
      var again = function () { renderCollect(C.student + (C.session ? '.' + C.session : '')); };
      if (col.dataset.collectSave) {
        var people = $$('.i-col-row').map(function (f) {
          return { name: (f.querySelector('[data-col-name]') || {}).value || '', relation: (f.querySelector('[data-col-rel]') || {}).value || '',
                   phone: (f.querySelector('[data-col-phone]') || {}).value || '' };
        });
        write(col, API.request('collectors', { method: 'POST', body: { action: 'save', student_id: C.student, collectors: people } }), again, 'List saved.');
      } else {
        var cb = { action: 'collect', student_id: C.student, session_id: C.session || null };
        if (col.dataset.collectBy) cb.collector_id = Number(col.dataset.collectBy);
        else { cb.other_name = ($('#colName') || {}).value || ''; cb.reason = ($('#colWhy') || {}).value || ''; }
        write(col, API.request('collectors', { method: 'POST', body: cb }), function (r) {
          if (r && r.verdict === 'not_listed') toast('Recorded. The safeguarding officer has been told.', 'bad');
          else toast('Recorded.', 'good');
          if (C.session) go('register', C.session); else again();
        }, null);
      }
      return;
    }
    var inc = e.target && e.target.closest && e.target.closest('[data-inc-save],[data-inc-tell],[data-inc-close]');
    if (inc) {
      var sidI = inc.dataset.incSave || S.incStudent, body;
      if (inc.dataset.incSave) {
        S.incStudent = sidI;
        body = { action: 'record', student_id: Number(sidI), kind: ($('#incKind') || {}).value, what: ($('#incWhat') || {}).value,
          action_taken: ($('#incDone') || {}).value, family_note: ($('#incFamily') || {}).value };
      } else if (inc.dataset.incTell) {
        body = { action: 'tell', id: Number(inc.dataset.incTell) };
      } else {
        var oc = window.prompt('How did it end?'); if (!oc) return;
        body = { action: 'close', id: Number(inc.dataset.incClose), outcome: oc };
      }
      write(inc, API.request('incidents', { method: 'POST', body: body }), function (r) {
        if (r && r.message) toast(r.message, r.told === false ? 'bad' : 'good');
        renderIncidents(S.incStudent || sidI);
      }, inc.dataset.incSave ? 'Recorded.' : null);
      return;
    }
    var pb = e.target && e.target.closest && e.target.closest('[data-place]');
    if (pb) {
      var cidP = pb.dataset.place, sidP = pb.dataset.studentId;
      write(pb, API.request('instructor/lessons', { method: 'POST', body: { action: 'place', student_id: Number(sidP), course_id: Number(cidP),
        level_id: Number((document.querySelector('[data-place-level="' + cidP + '"]') || {}).value || 0),
        reason: (document.querySelector('[data-place-reason="' + cidP + '"]') || {}).value || '' } }),
        function () { renderStudentLessons(sidP); }, 'Lesson level changed.');
      return;
    }
    var ms = e.target && e.target.closest && e.target.closest('[data-mat-send]');
    if (ms) {
      var box = ms.closest('details');
      write(ms, API.request('instructor/lessons', { method: 'POST', body: { action: 'material',
        lesson_id: Number((box.querySelector('[data-mat-lesson]') || {}).value || 0),
        title: (box.querySelector('[data-mat-title]') || {}).value || '', url: (box.querySelector('[data-mat-url]') || {}).value || '' } }),
        function () { box.querySelector('[data-mat-title]').value = ''; box.querySelector('[data-mat-url]').value = ''; }, 'Sent — the curriculum office will review it.');
      return;
    }
    var xm = e.target && e.target.closest && e.target.closest('[data-ex-mark]');
    if (xm) {
      var xc = xm.closest('[data-ex-card]'), xa = {}, xcm = {};
      Array.prototype.slice.call(xc.querySelectorAll('[data-check]')).forEach(function (it) {
        var v = (it.querySelector('[data-award]') || {}).value;
        if (v !== '' && v != null) xa[it.dataset.check] = Number(v);
        var c = (it.querySelector('[data-comment]') || {}).value;
        if (c) xcm[it.dataset.check] = c;
      });
      var ao = xc.querySelector('[data-appeal-outcome]');
      write(xm, API.request('instructor/lessons', { method: 'POST', body: { action: 'exam_mark', exam_id: Number(xm.dataset.exMark),
        awards: xa, comments: xcm, appeal_outcome: ao ? ao.value : '' } }),
        function () { renderMarking(); }, 'Saved — the student has been told.');
      return;
    }
    var lm = e.target && e.target.closest && e.target.closest('[data-lsn-mark]');
    var spm = e.target.closest && e.target.closest('[data-space-mark]');
    if (spm) {
      var spCard = spm.closest('[data-space-card]');
      var spScore = (spCard.querySelector('[data-space-score]') || {}).value;
      if (spScore === '' || spScore == null) { toast('Give a mark first.', 'bad'); return; }
      write(spm, API.request('spaces', { method: 'POST', body: { action: 'review', post_id: Number(spm.dataset.spaceMark),
        score: Number(spScore), feedback: (spCard.querySelector('[data-space-feedback]') || {}).value || '' } }),
        function () { renderMarking(); }, 'Marked — the student has been told.');
      return;
    }
    if (lm) {
      var card = lm.closest('[data-lsn-card]'), awards = {}, comments = {};
      Array.prototype.slice.call(card.querySelectorAll('[data-check]')).forEach(function (it) {
        var v = (it.querySelector('[data-award]') || {}).value;
        if (v !== '' && v != null) awards[it.dataset.check] = Number(v);
        var c = (it.querySelector('[data-comment]') || {}).value;
        if (c) comments[it.dataset.check] = c;
      });
      write(lm, API.request('instructor/lessons', { method: 'POST', body: { action: 'mark', progress_id: Number(lm.dataset.lsnMark),
        awards: awards, comments: comments, feedback: (card.querySelector('[data-feedback]') || {}).value || '' } }),
        function () { renderMarking(); }, 'Marks saved — the student has been told.');
      return;
    }
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
      }), function () { renderReport(S.reportStudent && S.reportStudent.id); }, 'Sent to the Examinations panel.');
      return;
    }

    if (P.a11yHandle && P.a11yHandle(t)) { e.preventDefault(); renderA11y(); return; }

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
      P.identity(me.name || 'Instructor', ['Instructor', d.term_label].filter(Boolean).join(' · '));
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
