/* ─────────────────────────────────────────────────────────────────────────────
   MAM Academy — the student portal (redesign, 21 Sep 2026)

   Five destinations from the canonical design (`portals-mobile.dc.html`
   `data-phone="studentv2"` and `HANDOFF-student-guardian.md` §2):
   Home · Train · Learn · Attend · More, plus Fees and Repertoire off the More
   sheet. Mirrors `portal-instructor.js` deliberately — same primitives, same
   write discipline, same a11y storage key — so the two portals cannot drift
   into two dialects of one design system.

   ── The rules this file is built around ─────────────────────────────────────

   1. **No inline styles, anywhere.** Every visual decision is a named
      component in `assets/css/portal-v2.css`. The legacy dashboard this
      replaces carries ~3,100 lines of inline `<script>` and its own private
      copies of three cards; that is why a change to it could not be reviewed
      as a diff.

   2. **Never an optimistic tick.** A control goes busy and stays busy until
      the server answers. The sharp case here is the practice log: a dishonest
      success loses the entry, and a lost entry breaks the streak the whole
      loop is built on — the one number this portal asks a child to trust.

   3. **The refusals are the SERVER's and this file does not re-derive them.**
      There is no branch here that computes a rank, a risk score or a mastery
      figure. `mam_mastery_publishable()` decides what may be shown; the check-in
      window is `mam_checkin_window()`'s five-way `state`, never a client-side
      "15 minutes"; and the unevidenced competencies arrive as ROWS that this
      file renders rather than as an absence it could quietly skip.

   One request per screen — `GET /api/student/portal?screen=…`. The readers are
   on Nigerian mobile data, where six round trips to paint a dashboard is the
   difference between a screen that appears and one that unfolds.
   ───────────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  var P   = window.MAM_PORTAL || {};
  var API = window.MAM_API;
  var esc = P.escapeHtml || function (s) { return String(s == null ? '' : s); };

  var S = {
    screen: 'home', home: null, attend: null, train: null,
    money: null, rep: null, busy: false, rec: null
  };

  var $  = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var main = $('#main');

  /* ── Small helpers ──────────────────────────────────────────────────────── */

  /* Server timestamps are UTC and arrive without a zone (`2026-09-21 14:00:00`).
     Appending `Z` where one is absent is what stops a Lagos student reading a
     2pm class as 1pm — the same trap `mam_db()` pins the MySQL session zone for. */
  function dt(v) {
    if (!v) return null;
    var d = new Date(String(v).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(String(v)) ? '' : 'Z'));
    return isNaN(d) ? null : d;
  }
  /* Times are the academy's (Africa/Lagos) and labelled "WAT" — P.timeLagos. */
  function fmtTime(v) { return P.timeLagos ? P.timeLagos(v) : ''; }
  function fmtDate(v) { return P.dateLagos ? P.dateLagos(v, { year: undefined }) : ''; }
  function fmtDay(v)  {
    var d = dt(v); if (!d) return '';
    var key = P.dayKeyLagos(d), today = P.dayKeyLagos(new Date());
    var days = Math.round((Date.parse(key + 'T00:00:00Z') - Date.parse(today + 'T00:00:00Z')) / 86400000);
    if (days === 0) return 'Today';
    if (days === 1) return 'Tomorrow';
    if (days === -1) return 'Yesterday';
    return P.dateLagos(d, { weekday: 'short', year: undefined });
  }
  function initials(name) {
    return String(name || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase();
  }
  function plural(n, one, many) { return Number(n) === 1 ? one : many; }
  function money(cents) {
    var v = (Number(cents) || 0) / 100;
    return '$' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function mins(m) {
    m = Number(m) || 0;
    if (m < 60) return m + ' min';
    var h = Math.floor(m / 60), r = m % 60;
    return h + 'h' + (r ? ' ' + r + 'm' : '');
  }
  function startsIn(min) {
    var m = Number(min);
    if (isNaN(m)) return 'Scheduled';
    if (m > 1440) return 'Later this week';
    if (m > 90)   return 'Later today';
    if (m > 1)    return 'Starts in ' + m + ' ' + plural(m, 'minute', 'minutes');
    if (m >= 0)   return 'Starts now';
    if (m > -90)  return 'Started ' + Math.abs(m) + ' ' + plural(Math.abs(m), 'minute', 'minutes') + ' ago';
    return 'Earlier today';
  }

  /**
   * ⚠️ An ERROR is not a polite status, and it does not time out.
   *
   * Two defects this replaces. The element is `role="status" aria-live="polite"`,
   * which a screen reader queues until it is idle — right for "Logged", wrong
   * for "that did not save", so the role is swapped to `alert` for a failure
   * and back afterwards. And every message auto-hid after 5.6 seconds with no
   * way to dismiss or recall it: a success may vanish, but an error is often
   * the ONLY record that the thing the student pressed did not happen, and
   * five seconds is not long enough to read a sentence, look at the control it
   * refers to, and act. Failures now stay until dismissed.
   */
  var toastTimer = null;
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
    if (!bad) toastTimer = setTimeout(function () { t.hidden = true; }, 5600);
  }

  /**
   * Every write on this portal goes through here.
   *
   * The control goes busy and STAYS busy until the server answers; there is no
   * branch that paints success before the response. A practice log that "saved"
   * and did not is worse than one that failed loudly — the child keeps the
   * streak in their head and the academy's copy quietly disagrees.
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

  /* A competency bar's width is DATA — it comes from the mark — but a length is
     still a length, so it lives in the stylesheet. `pct()` clamps and ROUNDS to
     the integer the `[data-pct="N"]` table in portal-v2.css matches on; a value
     outside 0..100, or a fractional one, matches nothing and draws an empty
     track, which is why the rounding happens here rather than being assumed.
     ⚠️ There is no `el.style.width` anywhere in this file, deliberately: a
     `style` attribute is banned in this repo, and this is the one place on the
     surface that would ever have wanted one. */
  function pct(v) { return Math.max(0, Math.min(100, Math.round(Number(v) || 0))); }
  /**
   * ⚠️ Focus moves on the paint, NOT on the navigation.
   *
   * Every screen here fetches, so `go()` returns while `#main` still holds the
   * previous screen — focusing a heading there finds the OLD one and the
   * repaint then destroys it, which is the same drop to <body> the move was
   * meant to prevent. MEASURED: it did exactly that. `pendingFocus` is armed
   * by `go()` and consumed by the first paint that follows, so the reader
   * lands on the heading of the screen that actually arrived.
   *
   * It is a one-shot flag on purpose. `paint()` also runs after every write
   * (logging practice re-renders Train), and yanking focus to the heading
   * there would throw a student out of the card they are still working in.
   */
  var pendingFocus = false;
  function paint(html) {
    main.innerHTML = html;
    if (P && P.topTitle) P.topTitle(main);
    main.scrollTop = 0;
    clearTimeout(loadTimer);
    if (pendingFocus) { pendingFocus = false; focusHead(); }
  }

  /**
   * A loading state that appears and vanishes inside 300ms reads as a glitch,
   * not as progress. MEASURED on this machine: a screen repaints in 15–42ms,
   * so every navigation flashed a spinner for about two frames. The reserve
   * is not wasted — on the Nigerian mobile data these screens are built for,
   * the same request is seconds — so the answer is to DELAY the indicator
   * rather than drop it: nothing for 300ms, then the spinner if the payload
   * still has not arrived.
   */
  var loadTimer = null;
  function deferLoading(what) {
    clearTimeout(loadTimer);
    loadTimer = setTimeout(function () { main.innerHTML = loading(what); }, 300);
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
  function errOf(err, fallback) { return esc(P.errText ? P.errText(err, fallback) : fallback); }
  /* Fees and Repertoire are reached from the More sheet, and the bar lights
     NOTHING while they are open — deliberately, because claiming "Home" while
     the reader is looking at an invoice is worse than claiming nothing. That
     leaves them the only screens here with no indication of where they sit,
     so they carry a back control instead. Both other portals already do this
     (`.p-back` / `backTo()`); this one had neither. */
  function backTo(screen, label) {
    return '<button type="button" class="p-back" data-screen="' + esc(screen) + '">'
      + '<i class="fa-solid fa-chevron-left" aria-hidden="true"></i>' + esc(label) + '</button>';
  }
  function notice(text, tone, icon) {
    return '<div class="p-notice' + (tone ? ' is-' + tone : '') + '">'
      + '<i class="fa-solid fa-' + (icon || 'circle-info') + '" aria-hidden="true"></i><span>' + esc(text) + '</span></div>';
  }
  function screenReq(screen, extra) {
    return API.request('student/portal?screen=' + screen + (extra || ''), { method: 'GET' });
  }

  /* ── 1 · Home ───────────────────────────────────────────────────────────── */

  /* "Good morning" is read at a glance and must not be wrong at 7pm. */
  function greeting() {
    var h = new Date().getHours();
    return h < 12 ? 'Good morning' : (h < 17 ? 'Good afternoon' : 'Good evening');
  }

  function renderHome() {
    deferLoading('Loading today…');
    screenReq('home').then(function (d) {
      S.home = d;
      applyIdentity(d);
      var html = '<div class="p-stack">';

      var me = d.me || {};
      html += '<h1 class="p-h1">' + esc(greeting() + (me.first ? ', ' + me.first : '')) + '</h1>';

      /* ⚠️ ORDER. An imminent class outranks the next step, always.
         MEASURED with a class eight minutes away: the provider's next step was
         "Practise today" (correct — the student had already checked in), and
         rendering the step first put a heading reading "NEXT · Practise today"
         directly above a card saying the class starts in eight minutes. The
         step was right and the page was lying about which came first.

         And when the step IS the check-in, the class card already carries that
         button, so the hero is suppressed rather than shown twice. */
      var n = d.next;
      var ns = d.next_step;
      var imminent = n && Number(n.starts_in_min) != null && Number(n.starts_in_min) < 120;

      if (imminent) html += nextClassCard(n, false);
      if (ns && !(imminent && ns.kind === 'checkin')) {
        html += '<section class="s-hero' + (ns.kind === 'clear' ? ' is-calm' : '') + '">'
          + '<div class="p-label">' + esc(ns.kind === 'clear' ? 'Nothing outstanding' : (imminent ? 'After that' : 'Next')) + '</div>'
          + '<h2 class="s-hero-title">' + esc(ns.title) + '</h2>'
          + (ns.body ? '<p class="s-hero-body">' + esc(ns.body) + '</p>' : '')
          + '<div class="s-hero-acts"><button type="button" class="is-wide" data-screen="' + esc(ns.screen) + '">'
          + esc(ns.cta) + '</button></div></section>';
      }
      if (n && !imminent) html += nextClassCard(n, false);

      /* The streak, and the sentence that travels with it. §2.3 refuses a
         cohort board outright; the private note is what keeps this number a
         commitment device rather than the first step back towards one. */
      var pr = d.practice || {};
      html += '<section class="p-card">'
        /* ⚠️ `mam_practice_stats()` computes `weekMin` over a ROLLING seven days
           and `weekDays` over the CALENDAR week (Monday to Sunday). Labelling
           the figure "this week" above those dots states something the dots
           contradict — on a Monday it read "1h 10m this week" over seven empty
           squares. The figure is described for what it actually measures. */
        + '<div class="p-line"><span class="p-label">Practice</span>'
        + '<b class="p-num">' + esc(mins(pr.weekMin)) + ' in 7 days</b></div>'
        + weekDots(pr.weekDays)
        + '<div class="s-streak-line"><span class="s-flame"><i class="fa-solid fa-fire" aria-hidden="true"></i>'
        + esc((Number(pr.streak) || 0) + ' ' + plural(pr.streak, 'day', 'days')) + '</span>'
        + '<button type="button" class="p-ghost" data-screen="train">Log practice</button></div>'
        + '<p class="p-caption s-private"><i class="fa-solid fa-lock" aria-hidden="true"></i> '
        + esc(d.streak_private) + '</p>'
        + '</section>';

      var ann = d.announcements || [];
      if (ann.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">From your class</span></div>'
          + '<div class="p-rows">' + ann.map(function (a) {
            return '<div class="p-row"><span class="p-row-main">'
              + '<span class="p-title">' + esc(a.title || 'Announcement') + '</span>'
              + '<span class="p-caption">' + esc([a.author, a.class_title, fmtDay(a.created_at)].filter(Boolean).join(' · ')) + '</span>'
              + (a.body ? '<span class="p-body s-ann-body">' + esc(a.body) + '</span>' : '')
              + '</span></div>';
          }).join('') + '</div></section>';
      }

      var owed = d.owed || {};
      if (Number(owed.cents) > 0) html += owedCard(owed);

      paint(html + '</div>');
    }).catch(bootError('Could not load today.'));
  }

  /* Mon–Sun, the real week. Dots are not decorative here — they are the only
     place a child sees which day they missed, so the day letters stay visible
     rather than being carried by colour alone. */
  function weekDots(days) {
    if (!days || !days.length) return '';
    return '<div class="s-week" role="img" aria-label="'
      + esc(days.filter(function (x) { return x.on; }).length + ' of 7 days practised this week') + '">'
      + days.map(function (x) {
        return '<span class="s-dot' + (x.on ? ' is-on' : '') + (x.today ? ' is-today' : '') + '">' + esc(x.d) + '</span>';
      }).join('') + '</div>';
  }

  /**
   * The next class, with whatever the window permits.
   *
   * ⚠️ `state` is the SERVER's five-way answer (closed → open →
   * readiness_passed → in_progress → ended). The button exists only in the
   * three states where the door is actually open, and the sentence under it is
   * `mam_checkin_window()`'s own — not a "15 minutes" this file invented.
   */
  function nextClassCard(n, full) {
    var w = n.window || {};
    var st = String(w.state || '');
    var att = n.attendance || null;
    var inWindow = st === 'open' || st === 'readiness_passed' || st === 'in_progress';
    /* ⚠️ The SESSION's title leads, not the module's. `topic` is the lesson the
       session delivers and the two are different strings — MEASURED, the same
       session appeared as "Circle of fifths" here and as "Scales &
       sight-reading" in the register a student had just checked into, which
       reads as two classes. The topic is kept, below, where it adds. */
    var meta = [n.cohort_title, fmtDay(n.scheduled_at), fmtTime(n.scheduled_at),
                n.mode === 'online' ? 'Online' : null].filter(Boolean).join(' · ');
    var topic = n.topic && n.topic !== n.title ? n.topic : null;
    var html = '<section class="s-next">'
      + '<div class="p-label">' + esc(startsIn(n.starts_in_min)) + '</div>'
      + '<h2 class="s-next-title">' + esc(n.title || n.cohort_title || 'Your next class') + '</h2>'
      + '<div class="s-next-meta">' + esc(meta) + '</div>'
      + (topic ? '<div class="s-next-meta">' + esc('This week: ' + topic) + '</div>' : '');

    /* An online or hybrid class is joined from the class page, which counts the
       time in the call itself (live-classes.php) — so it is offered from 15
       minutes before the start, beside (not instead of) checking in. */
    if ((n.mode === 'online' || n.mode === 'hybrid') && (inWindow || Number(n.starts_in_min) <= 15)) {
      html += '<div class="s-next-acts"><button type="button" class="is-wide" data-live="' + esc(n.session_id) + '">'
        + '<i class="fa-solid fa-video" aria-hidden="true"></i> Join the online class</button></div>';
    }
    if (att && att.checked_in_at) {
      html += '<div class="s-next-state"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> '
        + esc('Checked in at ' + fmtTime(att.checked_in_at)
              + (Number(att.minutes_late) > 0 ? ' · ' + att.minutes_late + ' min late' : '')) + '</div>';
      /* Clocking out is the other half of the clock (schema v81) and it lives
         on this card, because the card is what replaced the separate "today"
         row. Without it a student who checks in has no way to record leaving,
         and the duration stored against the session is never written. */
      if (!att.clocked_out_at) {
        html += '<div class="s-next-acts"><button type="button" class="is-quiet" data-clockout="'
          + esc(n.session_id) + '">Clock out</button></div>';
      } else {
        html += '<div class="s-next-note">' + esc('Clocked out at ' + fmtTime(att.clocked_out_at)
          + (att.duration_min != null ? ' · ' + mins(att.duration_min) + ' present' : '')) + '</div>';
      }
    } else if (inWindow) {
      html += '<div class="s-next-acts"><button type="button" class="is-wide" data-checkin="'
        + esc(n.session_id) + '">Check in</button></div>';
    } else if (st === 'closed') {
      html += '<div class="s-next-state">' + esc('Check-in opens ' + fmtTime(w.opens_at)) + '</div>';
    } else if (st === 'ended') {
      html += '<div class="s-next-state">' + esc('That class has finished.') + '</div>';
    }
    if (w.message) html += '<p class="s-next-note">' + esc(w.message) + '</p>';
    html += '</section>';

    if (full && n.prep_instructions) {
      html += '<section class="p-card"><div class="p-label">Before the class</div>'
        + '<p class="p-prose p-gap-t">' + esc(n.prep_instructions) + '</p>'
        + '<div class="p-actions p-gap-t"><button type="button" class="p-outline" data-screen="train">Open the practice room</button></div>'
        + '</section>';
    }
    return html;
  }

  function owedCard(owed) {
    return '<section class="p-card is-urgent"><div class="p-line"><span class="p-label is-bad">Outstanding</span>'
      + '<b class="p-num">' + esc(money(owed.cents)) + '</b></div>'
      + (owed.due_on ? '<p class="p-caption p-gap-t">' + esc('Due ' + fmtDate(owed.due_on)) + '</p>' : '')
      /* ⛔ §3.4: nothing is withheld from a child over money, and the sentence
         sits with the figure rather than in a policy page nobody opens. */
      + '<p class="p-body s-soft">' + esc(owed.notice) + '</p>'
      + '<div class="p-actions p-gap-t"><button type="button" class="p-outline" data-screen="money">See the detail</button></div>'
      + '</section>';
  }

  /* ── 2 · Train ──────────────────────────────────────────────────────────── */

  function renderTrain() {
    deferLoading('Loading the practice room…');
    screenReq('train').then(function (d) {
      S.train = d;
      var html = h1('Train', 'Practise, teach it back, keep it warm.') + '<div class="p-stack">';

      var t = d.target;
      if (t) {
        var done = t.prep === 'completed';
        html += '<section class="p-card' + (done ? '' : ' is-focus') + '">'
          + '<div class="p-line"><span class="p-label">' + esc(t.week_no ? 'Week ' + t.week_no : 'This week') + '</span>'
          + (done ? '<span class="p-chip is-good">Prepared</span>' : '') + '</div>'
          + '<h2 class="p-h2 p-gap-t">' + esc(t.title || 'Your module') + '</h2>'
          + (t.body ? '<p class="p-prose p-gap-t">' + esc(t.body) + '</p>' : '')
          + (t.session_at ? '<p class="p-caption p-gap-t">' + esc('For your class ' + fmtDay(t.session_at) + ', ' + fmtTime(t.session_at)) + '</p>' : '')
          + '<div class="p-actions p-gap-t">'
          + '<button type="button" class="' + (done ? 'p-outline' : 'p-primary') + '" data-prepare="' + esc(t.lesson_id) + '" data-done="' + (done ? '0' : '1') + '">'
          + (done ? 'Mark as not done' : 'I have prepared this') + '</button></div>'
          + '</section>';
      }

      /* The practice log. A number and a focus, nothing else: every extra field
         is a reason not to log at all, and an unlogged day is the failure. */
      var pr = d.practice || {};
      /* ⚠️ The week strip is a DISPLAY and the minute row is an INPUT, and at
         390 they sat 12px apart as two rows of same-sized pills under one
         heading — reading as a single twelve-button control. The strip keeps
         its own label and a rule under it, so the question below is plainly a
         different thing being asked. */
      html += '<section class="p-card"><div class="p-line"><span class="p-label">This week</span>'
        + '<b class="p-num">' + esc(mins(pr.weekMin)) + ' in 7 days</b></div>'
        + weekDots(pr.weekDays)
        + '<div class="s-ask">How long did you practise today?</div>'
        + '<div class="s-mins" role="group" aria-label="How long did you practise today?">'
        + [10, 20, 30, 45, 60].map(function (m) {
            return '<button type="button" data-min="' + m + '" aria-pressed="false">' + m + '</button>';
          }).join('')
        + '</div>'
        + '<label class="p-field p-gap-t"><span>What did you work on? (optional)</span>'
        + '<input type="text" id="pracFocus" maxlength="120" placeholder="Scales, the tricky bar in Book 2…"></label>'
        + '<button type="button" class="p-action" id="pracSave">Log it</button>'
        + '</section>';

      var logs = d.logs || [];
      if (logs.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Recently</span></div>'
          + '<div class="p-rows">' + logs.map(function (l) {
            return '<div class="p-row"><span class="p-row-main">'
              + '<span class="p-title">' + esc(mins(l.minutes)) + '</span>'
              + '<span class="p-caption">' + esc([l.focus, fmtDay(l.logged_on)].filter(Boolean).join(' · ')) + '</span>'
              + '</span></div>';
          }).join('') + '</div></section>';
      }

      /* Teach it back. Three self-checks, formative only — the sentence the
         provider sends is not optional chrome, it is the whole reason this is
         not another graded task. */
      if (t) {
        var tb = d.teachback;
        html += '<section class="p-card"><div class="p-line"><span class="p-label">Teach it back</span>'
          + (tb ? '<span class="p-chip' + (tb.status === 'reviewed' ? ' is-good' : '') + '">' + esc(tb.status || 'sent') + '</span>' : '')
          + '</div>'
          + '<p class="p-body p-gap-t">Explain this week\'s idea as if to someone who has never met it.</p>'
          + '<div class="s-checks">' + (d.teachback_checks || []).map(function (c) {
              return '<button type="button" class="s-check" role="checkbox" aria-checked="false" data-check="' + esc(c.key) + '">'
                + '<span class="s-check-box" aria-hidden="true"><i class="fa-solid fa-check"></i></span>'
                + '<span>' + esc(c.label) + '</span></button>';
            }).join('') + '</div>'
          + '<label class="p-field p-gap-t"><span>In your own words</span>'
          /* An older submission may still carry the appended checklist that
             this screen used to add; strip it on the way in rather than
             handing it back for the student to delete by hand. */
          + '<textarea id="tbBody" maxlength="4000" placeholder="It works because…">'
          + esc(tb && tb.body ? String(tb.body).replace(/\n+—[\s\S]*$/, '').trim() : '') + '</textarea></label>'
          + '<button type="button" class="p-action" id="tbSend" data-lesson="' + esc(t.lesson_id) + '">'
          + (tb ? 'Send it again' : 'Send it') + '</button>'
          + '<p class="p-caption s-soft">' + esc(d.teachback_notice) + '</p>'
          + '</section>';
      }

      /* Keep it warm — spaced retrieval, opt-in, never scored. The interval is
         the honest answer to "how well did that come back", so the labels are
         about recall and not about performance. */
      html += '<section class="p-card"><div class="p-label">Keep it warm</div>'
        + '<p class="p-body p-gap-t">Try last week\'s piece from memory first. Then say how it went.</p>'
        + '<div class="s-warm p-gap-t">' + (d.warm_intervals || []).map(function (w) {
            return '<button type="button" data-warm="' + esc(w.key) + '" data-days="' + esc(w.days) + '">'
              + '<b>' + esc(w.label) + '</b><span>' + esc('again in ' + w.days + ' ' + plural(w.days, 'day', 'days')) + '</span></button>';
          }).join('') + '</div>'
        + '<p class="p-caption s-soft">' + esc(d.warm_notice) + '</p></section>';

      var takes = d.takes || [];
      if (takes.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Your recordings</span></div>'
          + '<div class="p-rows">' + takes.map(function (k) {
            /* ⛔ §2.1: a take carries its audio and its status. There is no
               score here because nothing automatic marks a child's playing. */
            return '<div class="p-row s-take"><span class="p-row-main">'
              + '<span class="p-title">' + esc(k.title || 'Take') + '</span>'
              + '<span class="p-caption">' + esc([fmtDay(k.submitted_at), k.status].filter(Boolean).join(' · ')) + '</span>'
              + (k.artifact_url ? '<audio class="s-audio" controls preload="none" src="' + esc(k.artifact_url) + '"></audio>' : '')
              + '</span></div>';
          }).join('') + '</div></section>';
      }

      paint(html + '</div>');
    }).catch(function (err) { paint(h1('Train') + empty('triangle-exclamation', errOf(err, 'Could not load the practice room.'))); });
  }

  /* ── 3 · Learn ──────────────────────────────────────────────────────────── */

  function renderLearn() {
    deferLoading('Loading where you stand…');
    screenReq('learn').then(function (d) {
      var m = d.mastery;
      var html = h1('Where I stand') + '<div class="p-stack">';

      if (!m) {
        html += '<section class="p-card">' + empty('seedling', 'Nothing has been assessed yet. It will appear here as your work is marked.') + '</section>';
      } else if (!m.sufficient) {
        /* ⛔ §2.2 and gotcha #12: below the coverage floor there is NO
           composite — not a composite with a warning. "Not enough evidence
           yet" is a different statement from a number, and it is the true one. */
        html += '<section class="p-card"><div class="p-label">MAM Mastery Score</div>'
          + '<div class="p-fig s-band is-none">Not yet</div>'
          + '<p class="p-body">' + esc(m.withheld_reason === 'not_assessed'
              ? 'Nothing has been marked yet, so there is no score to show. That is not a zero.'
              : 'There is not enough of your work marked yet for a score that would mean anything. The parts that have been marked are below.') + '</p>'
          + (d.coverage && d.coverage.sentence ? '<p class="p-caption p-gap-t">' + esc(d.coverage.sentence) + '</p>' : '')
          + '</section>';
      } else {
        html += '<section class="p-card"><div class="p-label">MAM Mastery Score</div>'
          + '<div class="p-fig s-band">' + esc(m.band ? m.band.label : '') + '</div>'
          + '<div class="p-title">' + esc(m.level_label || '') + (m.provisional ? ' · provisional' : '') + '</div>'
          + (m.level_blurb ? '<p class="p-body p-gap-t">' + esc(m.level_blurb) + '</p>' : '')
          + (d.coverage && d.coverage.sentence ? '<p class="p-caption p-gap-t">' + esc(d.coverage.sentence) + '</p>' : '')
          + (m.bounds ? '<p class="p-caption">' + esc('With the rest still unmarked, the finished figure could land anywhere from '
              + m.bounds.min + ' to ' + m.bounds.max + '.') + '</p>' : '')
          + '</section>';
      }

      /* §2.2 rule 4 — horizontal bars, one per competency. A radar chart makes
         a shape out of eleven numbers and invites a child to read the shape. */
      var comps = (m && m.components) || [];
      if (comps.length) {
        html += '<section class="p-card"><div class="p-label">What is marked</div><div class="p-gap-t">'
          + comps.map(function (c) {
            var p = pct(c.pct);
            var tone = p >= 80 ? 'is-strong' : (p >= 60 ? 'is-secure' : 'is-working');
            return '<div class="p-crit"><div class="p-crit-head">'
              + '<span class="p-crit-name">' + esc(c.label) + '</span>'
              + '<span class="p-crit-grade p-num">' + esc(p + '%') + '</span></div>'
              + '<div class="p-bar" role="img" aria-label="' + esc(c.label + ': ' + p + ' per cent') + '">'
              + '<span class="' + tone + '" data-pct="' + p + '"></span></div></div>';
          }).join('') + '</div></section>';
      }

      /* §2.2 rule 3 — "not enough evidence yet" is a VISIBLE ROW. A zero here
         would report an unassessed beginner as failing, and silence would let
         them read a thin score as a complete one. */
      var un = d.unevidenced || [];
      if (un.length) {
        /* §2.2 rule 3 keeps these as VISIBLE, NAMED rows — but the explanation
           is written ONCE. MEASURED at 390: repeating it under each of eight
           competencies made the card 1,180px tall and the eight names, which
           are the actual information, read as captions on a wall of identical
           sentences. */
        html += '<section class="p-card is-quiet"><div class="p-label">Not enough evidence yet</div>'
          + '<p class="p-body p-gap-t">These parts of your work have not been marked. They are not counted '
          + 'for you or against you — they are simply not in the score yet.</p>'
          + '<div class="p-none-list p-gap-t">' + un.map(function (c) {
            return '<span class="p-none">' + esc(c.name) + '</span>';
          }).join('') + '</div></section>';
      }

      /* §2.2 rule 5 — the threshold is the academy's convention, and says so. */
      if (d.convention) html += notice(d.convention, null, 'circle-question');

      var att = (m && m.attendance) || null;
      if (att && att.sessions) {
        /* §24: attendance and punctuality are reported BESIDE the score and are
           never components of it — musical ability is not disciplinary record. */
        html += '<section class="p-card"><div class="p-label">Beside the score</div>'
          + '<div class="p-kpis p-gap-t">'
          + kpi(att.attendance_pct == null ? null : Math.round(att.attendance_pct) + '%', 'Attendance')
          + kpi(att.punctuality_pct == null ? null : Math.round(att.punctuality_pct) + '%', 'Punctuality')
          + kpi(att.sessions, 'Sessions')
          + '</div>'
          + '<p class="p-caption p-gap-t">These sit beside your score, never inside it.</p></section>';
      }

      /* Lessons — the syllabus with where this student stands on each one
         (api/modules/learning/lib/lessons.php). The count is lessons DONE out of lessons
         published; a week with no questions is the teacher's to tick off. */
      var lx = d.lesson_extra || {};
      if (lx.open_exam) {
        html += '<section class="p-card"><div class="p-title">You have an exam open</div>'
          + '<p class="p-caption">The clock is running on the server, whether or not this page is open.</p>'
          + '<button type="button" class="p-primary p-gap-t" data-open-exam="' + esc(lx.open_exam) + '">Go back to it</button></section>';
      }
      (d.lessons || []).forEach(function (c) {
        if (!c.lessons || !c.lessons.length) return;
        var t = c.tier || null;
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">'
          + esc(c.course_name + (c.level_name ? ' · ' + c.level_name : '')) + '</span>'
          + '<span class="p-caption p-num">' + esc(c.done + ' of ' + c.total + ' done') + '</span></div>'
          /* The tier is a statement about THIS student on THIS course and what
             the next step is — never a place on a list of classmates. */
          + (t ? '<div class="s-lsn-tier"><span class="p-chip' + (t.tier >= 5 ? ' is-good' : '') + '">' + esc(t.tier ? t.name : 'Not started') + '</span>'
            + '<span class="p-caption">' + esc(t.next) + '</span></div>' : '')
          + (t && t.trial_open ? '<div class="s-lsn-trial"><label class="s-lsn-pledge"><input type="checkbox" data-pledge-trial="' + esc(c.course_id) + '">'
            + '<span>The work will be my own — no notes, no help, no other tabs.</span></label>'
            + '<button type="button" class="p-outline" data-trial="' + esc(c.course_id) + '">Sit the course trial · 30 minutes</button></div>' : '')
          + '<div class="p-rows">' + c.lessons.map(function (l) {
            return '<button type="button" class="p-row s-lsn-row" data-open-lesson="' + esc(l.id) + '">'
              + '<span class="s-wk">' + esc(l.week) + '</span>'
              + '<span class="p-row-main"><span class="p-title">' + esc(l.title) + '</span>'
              + '<span class="p-caption">' + esc([stageWord(l), l.due && l.state !== 'completed' ? (l.late ? 'Was due ' : 'Due ') + l.due : '', l.summary].filter(Boolean).join(' · ')) + '</span></span>'
              + lessonChip(l) + '</button>';
          }).join('') + '</div></section>';
      });
      if ((lx.exams || []).length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Your exams</span></div><div class="p-rows">'
          + lx.exams.map(function (x) {
            var res = x.status === 'open' ? 'Open' : (Number(x.pending) ? 'With your teacher' : (x.band ? x.band + ' · ' + Math.round(x.pct) + '%' : '—'));
            return '<button type="button" class="p-row" data-open-exam="' + esc(x.id) + '"><span class="p-row-main">'
              + '<span class="p-title">' + esc(x.kind === 'trial' ? 'Course trial · ' + x.course_name : (x.lesson_title || 'Lesson exam')) + '</span>'
              + '<span class="p-caption">' + esc(x.submitted_at ? 'Handed in ' + String(x.submitted_at).slice(0, 10) : 'Not handed in yet') + '</span></span>'
              + '<span class="p-chip' + (x.band && x.band !== 'Not yet' ? ' is-good' : '') + '">' + esc(res) + '</span></button>';
          }).join('') + '</div></section>';
      }
      /* Work marked in a class space (spaces.php). Only the student's own —
         marks are private, and the provider takes no other id. */
      var sm = d.space_marks || [];
      if (sm.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Marked in your class space</span></div><div class="p-rows">'
          + sm.map(function (w) {
            return '<div class="p-row s-space-mark"><span class="p-row-main">'
              + '<span class="p-title">' + esc(w.task_title) + '</span>'
              + '<span class="p-caption">' + esc([w.space_title, w.marked_at ? 'Marked ' + String(w.marked_at).slice(0, 10) : ''].filter(Boolean).join(' · ')) + '</span>'
              + (w.feedback ? '<span class="p-body">' + esc(w.feedback) + '</span>' : '')
              + '</span><span class="p-chip p-num">' + esc(w.score + ' of ' + w.max_score) + '</span></div>';
          }).join('') + '</div></section>';
      }
      if ((lx.certificates || []).length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Certificates</span></div><div class="p-rows">'
          + lx.certificates.map(function (ct) {
            return '<div class="p-row"><span class="p-row-main"><span class="p-title">' + esc(ct.title) + '</span>'
              + '<a class="p-caption s-lsn-ref" href="/verify?ref=' + encodeURIComponent(ct.reference) + '" target="_blank" rel="noopener">'
              + esc(ct.reference) + '</a></span></div>';
          }).join('') + '</div></section>';
      }

      var en = d.enrolments || [];
      if (en.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Your courses</span></div>'
          + '<div class="p-rows">' + en.map(function (e) {
            return '<div class="p-row"><span class="p-row-main">'
              + '<span class="p-title">' + esc(e.course) + '</span>'
              + '<span class="p-caption">' + esc([e.level, e.cohort].filter(Boolean).join(' · ')) + '</span></span>'
              + (e.status === 'completed' ? '<span class="p-chip is-good">Completed</span>' : '') + '</div>';
          }).join('') + '</div></section>';
      }

      var wk = d.weeks || [];
      if (wk.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">The weeks ahead</span></div>'
          + '<div class="p-rows">' + wk.map(function (w) {
            return '<div class="p-row"><span class="s-wk">' + esc(w.week_no != null ? w.week_no : '·') + '</span>'
              + '<span class="p-row-main"><span class="p-title">' + esc(w.title || 'Module') + '</span></span></div>';
          }).join('') + '</div></section>';
      }

      paint(html + '</div>');
    }).catch(function (err) { paint(h1('Where I stand') + empty('triangle-exclamation', errOf(err, 'Could not load your results.'))); });
  }

  /* A KPI whose value is unknown reads "—", never 0. An unassessed student is
     not a failing one, and this is the third surface in the repo to say so. */
  function kpi(val, label) {
    var none = val == null || val === '';
    return '<div class="p-kpi"><b class="p-num' + (none ? ' is-none' : '') + '">' + esc(none ? '—' : val) + '</b>'
      + '<span>' + esc(label) + '</span></div>';
  }

  /* ── 4 · Attend ─────────────────────────────────────────────────────────── */

  function renderAttend() {
    deferLoading('Loading your classes…');
    screenReq('attend').then(function (d) {
      S.attend = d;
      var html = h1('Attend') + '<div class="p-stack">';

      /* The "today" row and the next-class card are the SAME session whenever
         today's class is still ahead — showing both listed one class twice,
         once as a record and once as a thing to do. The card wins: it carries
         the clock, the window and the preparation. */
      var t = d.today;
      if (t && d.next && Number(d.next.session_id) === Number(t.session_id)) t = null;
      if (t && t.checked_in) {
        html += '<section class="p-card is-flush"><div class="p-row">'
          + '<i class="fa-solid fa-circle-check p-row-ico is-good" aria-hidden="true"></i>'
          + '<span class="p-row-main"><span class="p-title">' + esc(t.title || 'Today\'s class') + '</span>'
          + '<span class="p-caption">' + esc('Checked in · ' + (t.status || 'present')) + '</span></span>'
          + '<button type="button" class="p-ghost" data-clockout="' + esc(t.session_id) + '">Clock out</button>'
          + '</div></section>';
      }

      if (d.next) html += nextClassCard(d.next, true);
      /* "No classes scheduled" states what and why but offers nothing to do,
         which is the one thing an empty state must not do. The record below
         is the honest next place to look. */
      else if (!t) html += '<section class="p-card">'
        + empty('calendar-xmark', 'No classes scheduled just now. When your instructor books the next one it '
          + 'appears here, and your attendance so far is below.') + '</section>';

      if (d.config && d.config.self_enabled === false) {
        html += notice('Your academy takes the register in class, so there is nothing for you to check in to here.', null, 'clipboard-check');
      }

      html += '<section class="p-card"><div class="p-kpis">'
        + kpi(d.rate == null ? null : Math.round(d.rate) + '%', 'Attendance')
        + kpi(d.week == null ? null : (d.total_weeks ? d.week + ' of ' + d.total_weeks : d.week), 'Week')
        + kpi((d.recent || []).length, 'Marked')
        + '</div></section>';

      var r = d.recent || [];
      if (r.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Your record</span></div>'
          + '<div class="p-rows">' + r.map(function (a) {
            var s = String(a.status || '');
            var tone = (s === 'present' || s === 'excused') ? 'is-good'
              : (s === 'absent' || s === 'unauthorised') ? 'is-bad' : 'is-warn';
            var label = { present: 'Present', late: 'Late', absent: 'Absent', excused: 'Authorised',
                          unauthorised: 'Unauthorised', left_early: 'Left early',
                          technical_failure: 'Technical problem', rescheduled: 'Rescheduled' }[s] || s;
            return '<div class="p-row"><span class="p-row-main">'
              + '<span class="p-title">' + esc(a.title || 'Class') + '</span>'
              + '<span class="p-caption">' + esc([fmtDay(a.scheduled_at),
                  Number(a.minutes_late) > 0 ? a.minutes_late + ' min late' : null,
                  a.mode === 'online' ? 'Online' : null].filter(Boolean).join(' · ')) + '</span></span>'
              + '<span class="p-chip ' + tone + '">' + esc(label) + '</span></div>';
          }).join('') + '</div></section>';
      }

      var mk = d.makeup || [];
      if (mk.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Make-up classes</span></div>'
          + '<div class="p-rows">' + mk.map(function (g) {
            return (g.options || []).map(function (o) {
              return '<div class="p-row"><span class="p-row-main">'
                + '<span class="p-title">' + esc(o.title || o.cohort_title || 'Session') + '</span>'
                + '<span class="p-caption">' + esc([fmtDay(o.scheduled_at), fmtTime(o.scheduled_at), o.instructor_name].filter(Boolean).join(' · ')
                  + ' · for ' + (g.missed && g.missed.title ? g.missed.title : 'a missed class')) + '</span></span></div>';
            }).join('');
          }).join('') + '</div>'
          + '<div class="p-card-foot"><p class="p-caption">' + esc(d.makeup_rule) + '</p></div></section>';
      }

      paint(html + '</div>');
    }).catch(function (err) { paint(h1('Attend') + empty('triangle-exclamation', errOf(err, 'Could not load your classes.'))); });
  }

  /* ── 5 · Fees & charges ─────────────────────────────────────────────────── */

  function renderMoney() {
    deferLoading('Loading your fees…');
    screenReq('money').then(function (d) {
      S.money = d;
      var owed = d.owed || {};
      var led = d.ledger || {};
      /* ⚠️ ONE figure, and it is the whole of what is outstanding. This read
         `$0.00` — the punctuality ledger alone — with an INVOICE's due date
         under it and a row chipped "Overdue" for $485 directly below, so the
         biggest number on the screen answered a different question from the
         date beside it. The two ledgers are named under the total rather than
         silently merged: tuition is the guardian's to settle and a
         punctuality charge is the student's, and a reader who can act on only
         one of them needs to see which is which. */
      var parts = [];
      if (Number(owed.fees_cents) > 0)    parts.push(money(owed.fees_cents) + ' tuition');
      if (Number(owed.charges_cents) > 0) parts.push(money(owed.charges_cents) + ' punctuality');
      var oweing = Number(owed.cents) > 0;
      var html = backTo('home', 'Home') + h1('Fees & charges') + '<div class="p-stack">'
        + '<section class="p-card' + (oweing ? ' is-urgent' : '') + '">'
        + '<div class="p-label' + (oweing ? ' is-bad' : '') + '">Outstanding</div>'
        + '<div class="p-fig p-money">' + esc(money(owed.cents)) + '</div>'
        + (parts.length > 1 ? '<p class="p-caption">' + esc(parts.join('  ·  ')) + '</p>' : '')
        + (owed.due_on && Number(owed.fees_cents) > 0
            ? '<p class="p-caption">' + esc('Tuition due ' + fmtDate(owed.due_on)) + '</p>' : '')
        + '<p class="p-body s-soft">' + esc(owed.notice) + '</p></section>';

      var inv = d.invoices || [];
      if (inv.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Invoices</span></div>'
          + '<div class="p-rows">' + inv.map(function (i) {
            /* `fee_invoices` has no `overdue` status — overdue is DERIVED from
               `due_on` on read, which is what the console does too. */
            var over = i.due_on && ['issued', 'part_paid'].indexOf(String(i.status)) >= 0 && dt(i.due_on) && dt(i.due_on) < new Date();
            return '<div class="p-row"><span class="p-row-main">'
              + '<span class="p-title">' + esc(i.public_ref || ('Invoice ' + i.id)) + '</span>'
              + '<span class="p-caption">' + esc([i.issued_on ? 'Issued ' + fmtDate(i.issued_on) : null,
                  i.due_on ? 'due ' + fmtDate(i.due_on) : null].filter(Boolean).join(' · ')) + '</span></span>'
              + '<span class="p-chip' + (over ? ' is-bad' : (i.status === 'paid' ? ' is-good' : '')) + '">'
              + esc(over ? 'Overdue' : String(i.status || '').replace('_', ' ')) + '</span>'
              /* An adult paying their own invoice (the server sets self_pay). */
              /* Online payment off: no button that cannot open a checkout —
                 the bank account to pay into instead. */
              + (Number(i.self_pay) === 1
                  ? (d.online_pay
                      ? '<button type="button" class="p-action" data-feepay="' + esc(i.id) + '">Pay online</button>'
                      : (d.bank && d.bank.account_number
                          ? '<span class="p-caption">Pay by transfer: ' + esc(d.bank.account_name) + ' · ' + esc(d.bank.bank)
                            + ' · <span class="p-num">' + esc(d.bank.account_number) + '</span> · narration ' + esc(i.public_ref || '') + '</span>'
                          : ''))
                  : '')
              + '</div>';
          }).join('') + '</div></section>';
      }

      var ch = led.charges || [];
      if (ch.length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Punctuality</span></div>'
          + '<div class="p-rows">' + ch.map(function (c) {
            var exc = c.exception;
            /* A charge recorded with charging OFF is amount 0 and is NOT a
               debt. Showing it at zero rather than hiding it is the point —
               otherwise a student cannot tell whether the academy noticed. */
            return '<div class="p-row"><span class="p-row-main">'
              + '<span class="p-title">' + esc(c.session_title || 'Late arrival') + '</span>'
              + '<span class="p-caption">' + esc([fmtDate(c.scheduled_at || c.created_at), c.status,
                  exc ? 'waiver ' + (exc.status || 'requested') : null].filter(Boolean).join(' · ')) + '</span></span>'
              + '<span class="p-num p-strong">' + esc(money(c.amount_usd_cents)) + '</span>'
              /* "Ask" alone does not read out of context, and a button label
                 has to say what happens before it is pressed. */
              + (!exc && ['outstanding', 'disputed'].indexOf(String(c.status)) >= 0
                  ? '<button type="button" class="p-ghost" data-waive="' + esc(c.id) + '">Ask for a review</button>' : '')
              + '</div>'
              + (!exc && ['outstanding', 'disputed'].indexOf(String(c.status)) >= 0
                  ? waiveForm(c) : '');
          }).join('') + '</div>'
          + '<div class="p-card-foot"><p class="p-caption">If a charge is wrong, ask for it to be looked at. Nothing is decided until somebody has.</p></div>'
          + '</section>';
      }

      if (!inv.length && !ch.length && !Number(owed.cents)) {
        html += '<section class="p-card">' + empty('circle-check', 'Nothing owing and nothing charged.') + '</section>';
      }

      paint(html + '</div>');
    }).catch(function (err) { paint(backTo('home', 'Home') + h1('Fees & charges') + empty('triangle-exclamation', errOf(err, 'Could not load your fees.'))); });
  }

  /**
   * The waiver request, as a form beside the charge it disputes.
   *
   * The reason codes are the SERVER's (`MAM_ACCT_EXCEPTION_REASONS`) and
   * arrive in the payload — the client offers exactly those seven and can
   * never invent an eighth. §15 says an instructor OR an admin may raise an
   * exception and a student may raise one against their own charge; the
   * ownership check is at the edge, so all this has to do is ask honestly.
   */
  function waiveForm(c) {
    var reasons = (S.money && S.money.exception_reasons) || {};
    var opts = Object.keys(reasons).map(function (k) {
      return '<option value="' + esc(k) + '">' + esc(reasons[k]) + '</option>';
    }).join('');
    return '<div class="s-waive" id="waive-' + esc(c.id) + '" hidden>'
      + '<label class="p-field"><span>What happened?</span>'
      + '<select id="waiveWhy-' + esc(c.id) + '" aria-describedby="waiveErr-' + esc(c.id) + '">'
      + '<option value="">Choose one…</option>' + opts + '</select></label>'
      + '<p class="p-err" id="waiveErr-' + esc(c.id) + '" hidden></p>'
      + '<label class="p-field"><span>Anything else we should know? (optional)</span>'
      + '<textarea id="waiveNote-' + esc(c.id) + '" maxlength="500" '
      + 'placeholder="The class was moved and nobody told me."></textarea></label>'
      + '<div class="p-actions">'
      + '<button type="button" class="p-outline" data-waive-cancel="' + esc(c.id) + '">Cancel</button>'
      + '<button type="button" class="p-primary" data-waive-send="' + esc(c.id) + '">Send the request</button>'
      + '</div></div>';
  }

  /* An error belongs BESIDE the control that failed, announced, not only in a
     toast at the foot of the screen. `aria-describedby` is already on the
     select, so filling this element is what a screen reader reads out. */
  function fieldError(id, msg) {
    var el = $('#waiveErr-' + id), sel = $('#waiveWhy-' + id);
    if (!el) return;
    el.textContent = msg;
    el.hidden = !msg;
    if (sel) {
      if (msg) sel.setAttribute('aria-invalid', 'true'); else sel.removeAttribute('aria-invalid');
      if (msg) sel.focus();
    }
  }

  /* ── 6 · Repertoire ─────────────────────────────────────────────────────── */

  var REP_STATUS = [['learning', 'Learning'], ['polished', 'Polished'], ['performed', 'Performed']];

  function renderRepertoire() {
    deferLoading('Loading your repertoire…');
    screenReq('repertoire').then(function (d) {
      S.rep = d;
      var list = d.repertoire || [];
      var html = backTo('home', 'Home') + h1('Repertoire', 'Everything you are working on, and everything you have played.')
        + '<div class="p-stack">'
        + '<section class="p-card"><div class="p-label">Add a piece</div>'
        + '<label class="p-field p-gap-t"><span>Title</span><input type="text" id="repTitle" maxlength="160" placeholder="Minuet in G"></label>'
        + '<label class="p-field"><span>Composer (optional)</span><input type="text" id="repComposer" maxlength="160"></label>'
        + '<button type="button" class="p-action" id="repAdd">Add it</button></section>';

      if (list.length) {
        html += '<section class="p-card is-flush"><div class="p-rows">' + list.map(function (r) {
          return '<div class="p-row s-rep" data-rep="' + esc(r.id) + '"><span class="p-row-main">'
            + '<span class="p-title">' + esc(r.title) + '</span>'
            + (r.composer ? '<span class="p-caption">' + esc(r.composer) + '</span>' : '')
            + '<span class="p-seg s-rep-seg" role="group" aria-label="' + esc('Status of ' + r.title) + '">'
            + REP_STATUS.map(function (o) {
                return '<button type="button" data-repstatus="' + o[0] + '" data-rep="' + esc(r.id) + '" aria-selected="'
                  + (String(r.status) === o[0] ? 'true' : 'false') + '">' + o[1] + '</button>';
              }).join('') + '</span></span>'
            + '<button type="button" class="p-ghost" data-repdel="' + esc(r.id) + '" aria-label="' + esc('Remove ' + r.title) + '">'
            + '<i class="fa-solid fa-trash-can" aria-hidden="true"></i></button></div>';
        }).join('') + '</div></section>';
      } else {
        html += '<section class="p-card">' + empty('music', 'Nothing here yet. Add the first piece you are learning.') + '</section>';
      }

      paint(html + '</div>');
    }).catch(function (err) { paint(backTo('home', 'Home') + h1('Repertoire') + empty('triangle-exclamation', errOf(err, 'Could not load your repertoire.'))); });
  }

  /* ── Display & access ───────────────────────────────────────────────────── */

  /**
   * The same screen the guardian and instructor portals have, against the SAME
   * storage key. That is deliberate: it is one person's preference about their
   * own eyes, not a per-portal setting, and a student who is also a guardian
   * would otherwise set larger text twice and wonder which one took.
   */
  function renderA11y() { paint(P.a11yHtml()); }   // the screen lives in the shared kit (portal.js)

  /* ── 3b · A lesson ──────────────────────────────────────────────────────────

     The player for one lesson (api/modules/learning/lib/lessons.php). What it never does is
     decide anything: whether an answer is right, whether the right answer may
     be shown yet, whether the lesson is done — all of that arrives from the
     server. It only collects answers and shows what came back. Nothing here
     needs a drag: put-in-order steps move with buttons, so the question works
     from a keyboard and a screen reader. */

  /** Where a lesson sits for this student: their own level, behind it, or opened early. */
  function stageWord(l) {
    if (l.stage === 'catchup') return 'Catch-up · ' + (l.level_name || 'earlier level') + ' · at your own pace';
    if (l.stage === 'early') return 'Opened early · ' + (l.level_name || 'next level');
    return '';
  }

  function lessonChip(l) {
    if (l.state === 'completed') return '<span class="p-chip is-good">Done</span>';
    if (l.late) return '<span class="p-chip is-warn">Overdue</span>';
    if (l.pending) return '<span class="p-chip">With your teacher</span>';
    if (l.stale) return '<span class="p-chip is-warn">Questions changed</span>';
    if (l.state === 'attempted') return '<span class="p-chip is-warn">' + esc(l.best_pct != null ? 'Best ' + l.best_pct + '%' : 'Try again') + '</span>';
    if (l.state === 'started') return '<span class="p-chip">Started</span>';
    return '';
  }

  function lessonQuestion(c, n, prev) {
    /* Spans, not blocks: for a choice question this IS the fieldset's legend,
       so a screen reader announces the question together with its choices. */
    var head = '<span class="p-label">Question ' + n + ' · ' + esc(c.points + (c.points === 1 ? ' point' : ' points')) + '</span>'
      + '<span class="p-body s-lsn-q">' + esc(c.prompt) + '</span>';
    var name = 'lq' + c.id;
    if (c.kind === 'mcq') {
      return '<fieldset class="s-lsn-check" data-q="' + esc(c.id) + '" data-kind="mcq"><legend class="s-lsn-legend">' + head + '</legend>'
        + '<div class="s-lsn-opts">' + (c.options || []).map(function (o, i) {
          return '<label class="s-lsn-opt"><input type="radio" name="' + name + '" value="' + i + '"'
            + (prev != null && Number(prev) === i ? ' checked' : '') + '><span>' + esc(o) + '</span></label>';
        }).join('') + '</div></fieldset>';
    }
    if (c.kind === 'order') {
      return '<fieldset class="s-lsn-check" data-q="' + esc(c.id) + '" data-kind="order"><legend class="s-lsn-legend">' + head + '</legend>'
        + '<p class="p-caption">Use the arrows to put these in order, first at the top.</p>'
        + '<ol class="s-lsn-order">' + (c.options || []).map(function (o) {
          return '<li data-i="' + esc(o.i) + '"><span class="s-lsn-step">' + esc(o.text) + '</span>'
            + '<button type="button" class="s-lsn-mv" data-move="up" aria-label="' + esc('Move “' + o.text + '” up') + '"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button>'
            + '<button type="button" class="s-lsn-mv" data-move="down" aria-label="' + esc('Move “' + o.text + '” down') + '"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button></li>';
        }).join('') + '</ol></fieldset>';
    }
    if (c.kind === 'evidence') {
      return '<div class="s-lsn-check" data-q="' + esc(c.id) + '" data-kind="evidence"><div class="s-lsn-legend">' + head + '</div>'
        + '<label class="p-field"><span>Link to your recording or photo</span>'
        + '<input type="url" inputmode="url" placeholder="https://" value="' + esc(prev || '') + '"></label></div>';
    }
    return '<div class="s-lsn-check" data-q="' + esc(c.id) + '" data-kind="text"><div class="s-lsn-legend">' + head + '</div>'
      + '<label class="p-field"><span>Your answer</span><textarea maxlength="8000">' + esc(prev || '') + '</textarea></label></div>';
  }

  function lessonReview(l) {
    var r = l.review;
    if (!r || !r.items || !r.items.length) return '';
    var byId = {};
    (l.checks || []).forEach(function (c, i) { byId[c.id] = { n: i + 1, c: c }; });
    var rows = r.items.map(function (it) {
      var q = byId[it.check_id] || { n: '?', c: {} };
      var verdict = it.pending ? 'Waiting for your teacher' : (it.right ? 'Right' : (Number(it.awarded) > 0 ? 'Part right' : 'Not yet'));
      var tone = it.pending ? '' : (it.right ? ' is-good' : ' is-warn');
      var extra = '';
      if (it.slip) extra += '<p class="p-caption">' + esc(it.slip) + '</p>';
      if (it.comment) extra += '<p class="p-caption">' + esc('Your teacher: ' + it.comment) + '</p>';
      if (r.revealed && it.answer != null && q.c.options) extra += '<p class="p-caption">' + esc('The answer: ' + q.c.options[it.answer]) + '</p>';
      if (r.revealed && it.answer_order) extra += '<p class="p-caption">' + esc('The order: ' + it.answer_order.join(' → ')) + '</p>';
      return '<div class="p-row"><span class="p-row-main"><span class="p-title">' + esc('Question ' + q.n) + '</span>' + extra + '</span>'
        + '<span class="p-chip' + tone + '">' + esc(verdict) + '</span></div>';
    }).join('');
    var head = r.revealed ? 'Your last try' : 'Your last try · the answers show after you pass, or after ' + r.reveal_after + ' tries';
    return '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">' + esc(head) + '</span>'
      + '<span class="p-caption p-num">' + esc('Tries: ' + r.attempts) + '</span></div>'
      + (r.feedback ? '<p class="p-body s-lsn-fb">' + esc('Your teacher: ' + r.feedback) + '</p>' : '')
      + '<div class="p-rows">' + rows + '</div></section>';
  }

  function renderLesson(id) {
    deferLoading('Opening the lesson…');
    API.request('student/lessons?id=' + encodeURIComponent(id || ''), { method: 'GET' }).then(function (d) {
      var l = d.lesson || {};
      S.lesson = l;
      var p = l.progress || {};
      var html = backTo('learn', 'Where I stand') + h1(l.title || 'Lesson',
        [l.course_name, l.level_name, 'Week ' + l.week].filter(Boolean).join(' · ')) + '<div class="p-stack">';
      if (p.state === 'completed') html += notice('Done. You can look back at it any time.', 'good', 'circle-check');
      else if (p.pending) html += notice('Your written answers are with your teacher. You will be told when they are marked.', null, 'hourglass-half');
      else if (p.stale) html += notice('The questions have changed since your last try, so that score is from an older version.', 'warn', 'triangle-exclamation');

      if (l.summary || (l.objectives || []).length) {
        html += '<section class="p-card">' + (l.summary ? '<p class="p-body">' + esc(l.summary) + '</p>' : '')
          + ((l.objectives || []).length ? '<div class="p-label p-gap-t">By the end you can</div><ul class="s-lsn-list">'
            + l.objectives.map(function (o) { return '<li>' + esc(o) + '</li>'; }).join('') + '</ul>' : '')
          + (l.duration_mins ? '<p class="p-caption p-gap-t">' + esc('About ' + l.duration_mins + ' minutes') + '</p>' : '')
          + '</section>';
      }
      if (l.body) {
        html += '<section class="p-card">' + String(l.body).split(/\n{2,}/).map(function (para) {
          return '<p class="p-body s-lsn-para">' + esc(para) + '</p>';
        }).join('') + '</section>';
      }
      if ((l.materials || []).length) {
        html += '<section class="p-card is-flush"><div class="p-card-head"><span class="p-label">Materials</span></div><div class="p-rows">'
          + l.materials.map(function (m) {
            var safe = /^https:\/\//i.test(m.url) || /^\/api\/media\//.test(m.url);
            return '<div class="p-row"><span class="p-row-main">'
              + (safe ? '<a class="p-title" href="' + esc(m.url) + '" target="_blank" rel="noopener">' + esc(m.title) + '</a>' : '<span class="p-title">' + esc(m.title) + '</span>')
              + (m.guide ? '<span class="p-caption">' + esc(m.guide) + '</span>' : '') + '</span></div>';
          }).join('') + '</div></section>';
      }

      html += lessonReview(l);

      if (l.syllabus_only) {
        html += notice('This week has no questions. Your teacher ticks it off once you have done it in class.', null, 'chalkboard-user');
      } else if (!p.pending) {
        var prev = {};
        try { prev = (l.review && l.review.items || []).reduce(function (acc, it) { acc[it.check_id] = it.your_answer; return acc; }, {}); } catch (e) { prev = {}; }
        html += '<section class="p-card"><div class="p-label">' + esc(p.state === 'completed' ? 'Try it again' : 'Questions') + '</div>'
          + '<p class="p-caption">' + esc('Pass mark: ' + l.pass_mark + '%. Written answers are marked by your teacher.') + '</p>'
          + '<div class="p-gap-t">' + (l.checks || []).map(function (c, i) {
            return lessonQuestion(c, i + 1, c.kind === 'order' ? null : prev[c.id]);
          }).join('') + '</div>'
          + '<button type="button" class="p-action" id="lsnSend" data-lesson-id="' + esc(l.id) + '">Send my answers</button></section>';
      }
      html += lessonExtras(l);
      paint(html + '</div>');
    }).catch(function (err) {
      paint(backTo('learn', 'Where I stand') + h1('Lesson') + empty('triangle-exclamation', errOf(err, 'Could not open this lesson.')));
    });
  }

  /**
   * Around a lesson: its exam, how it landed, the student's own notes and the
   * study helper (lessons-learn.php, lesson-exams.php). Notes are private —
   * the card says so, because a child writing "I don't get this" needs to know
   * nobody is grading it.
   */
  function lessonExtras(l) {
    var html = '';
    if (!l.syllabus_only) {
      html += '<section class="p-card"><div class="p-label">Lesson exam</div>';
      if (l.exam_open) html += '<p class="p-caption">You already have an exam open — finish that one first. It is on Learn.</p>';
      else html += '<p class="p-caption">Timed by the server, with a few questions from lessons you finished earlier. You can appeal the result once.</p>'
        + '<label class="s-lsn-pledge p-gap-t"><input type="checkbox" id="exPledge"><span>The work will be my own — no notes, no help, no other tabs.</span></label>'
        + '<button type="button" class="p-outline p-gap-t" id="exStart" data-lesson-id="' + esc(l.id) + '">Start the exam</button>';
      html += '</section>';
    }
    if (l.feels) {
      var mine = (l.feedback_mine || {}).feel || '';
      html += '<section class="p-card"><div class="p-label" id="feelLbl">How did this lesson land?</div>'
        + '<p class="p-caption">Your teachers see how a lesson is landing across the class — never who said what.</p>'
        + '<div class="s-lsn-feels" role="group" aria-labelledby="feelLbl">' + Object.keys(l.feels).map(function (k) {
          return '<button type="button" class="p-outline' + (mine === k ? ' is-on' : '') + '" aria-pressed="' + (mine === k ? 'true' : 'false')
            + '" data-feel="' + esc(k) + '" data-lesson-id="' + esc(l.id) + '">' + esc(l.feels[k]) + '</button>';
        }).join('') + '</div></section>';
      html += '<section class="p-card"><div class="p-card-head"><span class="p-label">My notes</span>'
        + '<button type="button" class="p-outline" id="lsnSave" aria-pressed="' + (l.saved ? 'true' : 'false') + '" data-lesson-id="' + esc(l.id) + '">'
        + esc(l.saved ? 'Saved for later' : 'Save for later') + '</button></div>'
        + '<label class="p-field"><span>Only you can read these</span><textarea id="lsnNote" maxlength="8000">' + esc(l.note || '') + '</textarea></label>'
        + '<button type="button" class="p-outline p-gap-t" id="lsnNoteSave" data-lesson-id="' + esc(l.id) + '">Keep my notes</button></section>';
    }
    if (l.helper) {
      html += '<section class="p-card"><div class="p-label">Study helper</div>'
        + '<p class="p-caption">' + esc('Ask about this lesson. It gives hints, not the answers to the questions. ' + l.helper.left + ' left today.') + '</p>'
        + '<label class="p-field p-gap-t"><span>Your question</span><textarea id="hlpQ" maxlength="1000"></textarea></label>'
        + '<button type="button" class="p-outline p-gap-t" id="hlpAsk" data-lesson-id="' + esc(l.id) + '">Ask</button>'
        + '<div class="s-lsn-helper" id="hlpOut" aria-live="polite"></div></section>';
    }
    return html;
  }

  /* ── 3c · An exam ───────────────────────────────────────────────────────────
     The clock is the SERVER's: `seconds_left` arrives with every read, and the
     countdown here only displays it. When it reaches zero the page hands in
     what is on screen; if the page was closed, the server hands in what was
     saved. Answers are saved as the student goes, so a dropped connection
     loses at most the last minute. */
  var exTimer = null;
  function examQuestion(it, n) {
    var head = '<span class="p-label">Question ' + n + ' · ' + esc(it.points + (it.points === 1 ? ' point' : ' points')) + '</span>'
      + '<span class="p-body s-lsn-q">' + esc(it.prompt) + '</span>';
    var name = 'eq' + it.check_id, prev = it.saved;
    if (it.kind === 'mcq') {
      return '<fieldset class="s-lsn-check" data-q="' + esc(it.check_id) + '" data-kind="mcq"><legend class="s-lsn-legend">' + head + '</legend>'
        + '<div class="s-lsn-opts">' + it.options.map(function (o) {
          return '<label class="s-lsn-opt"><input type="radio" name="' + name + '" value="' + esc(o.i) + '"'
            + (prev != null && Number(prev) === o.i ? ' checked' : '') + '><span>' + esc(o.text) + '</span></label>';
        }).join('') + '</div></fieldset>';
    }
    if (it.kind === 'order') {
      var opts = it.options;
      if (Array.isArray(prev) && prev.length === opts.length) {
        var byI = {}; opts.forEach(function (o) { byI[o.i] = o; });
        opts = prev.map(function (i) { return byI[i]; }).filter(Boolean);
      }
      return lessonQuestion({ id: it.check_id, kind: 'order', points: it.points, prompt: it.prompt, options: opts }, n, null);
    }
    return lessonQuestion({ id: it.check_id, kind: it.kind === 'evidence' ? 'evidence' : 'text', points: it.points, prompt: it.prompt }, n, prev);
  }

  function clock(sec) {
    sec = Math.max(0, Math.floor(sec));
    return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0');
  }

  function renderExam(id) {
    clearInterval(exTimer);
    deferLoading('Opening the exam…');
    API.request('student/lessons?exam=' + encodeURIComponent(id || ''), { method: 'GET' }).then(function (d) {
      var x = d.exam || {};
      S.exam = x;
      var title = x.kind === 'trial' ? 'Course trial' : 'Lesson exam';
      var html = backTo('learn', 'Where I stand') + h1(title) + '<div class="p-stack">';
      if (x.status === 'open') {
        html += '<section class="p-card s-ex-clock"><span class="p-label">Time left</span>'
          + '<span class="p-num s-ex-time" id="exTime" role="timer" aria-live="off">' + esc(clock(x.seconds_left)) + '</span>'
          + '<span class="p-caption">Your answers are saved as you go. When the time runs out, what is saved is handed in.</span></section>'
          + '<section class="p-card">' + x.items.map(function (it, i) { return examQuestion(it, i + 1); }).join('')
          + '<div class="p-actions p-gap-t"><button type="button" class="p-outline" id="exSave" data-exam="' + esc(x.id) + '">Save</button>'
          + '<button type="button" class="p-action" id="exSubmit" data-exam="' + esc(x.id) + '">Hand it in</button></div></section>';
        paint(html + '</div>');
        var end = Date.now() + x.seconds_left * 1000, last = Date.now();
        exTimer = setInterval(function () {
          var el = document.getElementById('exTime');
          if (!el) { clearInterval(exTimer); return; }
          var left = (end - Date.now()) / 1000;
          el.textContent = clock(left);
          if (Date.now() - last > 60000) { last = Date.now(); examSave(x.id, true); }
          if (left <= 0) { clearInterval(exTimer); examSubmit(x.id, null); }
        }, 1000);
        return;
      }
      var r = x.result || {};
      if (r.pending) html += notice('Handed in. Your teacher is marking the written answers; you will be told when it is done.', null, 'hourglass-half');
      else html += '<section class="p-card"><div class="p-kpis">' + kpi(r.pct != null ? r.pct + '%' : null, 'Score') + kpi(r.band, 'Result') + '</div></section>';
      html += '<section class="p-card is-flush"><div class="p-rows">' + x.items.map(function (it, i) {
        var m = (r.marks || {})[String(it.check_id)] || {};
        var v = m.hold ? 'With a person' : (m.awarded == null ? 'Waiting' : m.awarded + ' of ' + it.points);
        return '<div class="p-row"><span class="p-row-main"><span class="p-title">' + esc('Question ' + (i + 1)) + '</span>'
          + '<span class="p-caption">' + esc(it.prompt) + '</span>'
          + (m.comment ? '<span class="p-caption">' + esc((m.by === 'ai' ? 'Marking note: ' : 'Your teacher: ') + m.comment) + '</span>' : '')
          + '</span><span class="p-chip">' + esc(v) + '</span></div>';
      }).join('') + '</div></section>';
      var ap = r.appeal || {};
      if (ap.outcome) html += '<section class="p-card"><div class="p-label">Your appeal</div><p class="p-body">' + esc(ap.text || '') + '</p>'
        + '<p class="p-caption p-gap-t">' + esc('The answer: ' + ap.outcome) + '</p></section>';
      else if (ap.text) html += notice('Your appeal is with your teacher.', null, 'scale-balanced');
      else if (ap.open) html += '<section class="p-card"><div class="p-label">Think a mark is wrong?</div>'
        + '<p class="p-caption">You can appeal once, within a week. Say which question and why.</p>'
        + '<label class="p-field p-gap-t"><span>Your appeal</span><textarea id="exAppeal" maxlength="2000"></textarea></label>'
        + '<button type="button" class="p-outline p-gap-t" id="exAppealSend" data-exam="' + esc(x.id) + '">Send the appeal</button></section>';
      paint(html + '</div>');
    }).catch(function (err) {
      paint(backTo('learn', 'Where I stand') + h1('Exam') + empty('triangle-exclamation', errOf(err, 'Could not open this exam.')));
    });
  }

  function examSave(id, quiet) {
    var req = API.request('student/lessons', { method: 'POST', body: { action: 'exam_save', exam_id: Number(id), answers: lessonAnswers() } });
    if (quiet) { req.catch(function () {}); return; }
    write($('#exSave'), req, null, 'Saved.');
  }
  function examSubmit(id, btn) {
    clearInterval(exTimer);
    write(btn, API.request('student/lessons', { method: 'POST', body: { action: 'exam_submit', exam_id: Number(id), answers: lessonAnswers() } }),
      function () { renderExam(id); }, 'Handed in.');
  }

  /** Read every answer off the page. A blank one is sent blank; the server says which are missing. */
  function lessonAnswers() {
    var out = {};
    $$('.s-lsn-check', main).forEach(function (el) {
      var q = el.dataset.q, kind = el.dataset.kind;
      if (kind === 'mcq') { var r = el.querySelector('input[type=radio]:checked'); out[q] = r ? r.value : ''; }
      else if (kind === 'order') out[q] = $$('li', el).map(function (li) { return Number(li.dataset.i); });
      else if (kind === 'evidence') out[q] = (el.querySelector('input') || {}).value || '';
      else out[q] = (el.querySelector('textarea') || {}).value || '';
    });
    return out;
  }

  /* ── Routing ────────────────────────────────────────────────────────────── */

  var SCREENS = {
    home: renderHome, train: renderTrain, learn: renderLearn, attend: renderAttend,
    money: renderMoney, repertoire: renderRepertoire, a11y: renderA11y, lesson: renderLesson, exam: renderExam,
    space: renderSpace, concern: renderConcern, growth: renderGrowth, card: renderCard
  };
  /* The student's own ID card. The redesign never showed it (only the classic
     dashboard did) and the issue email always failed, so no student could
     reach their card. Read from ?screen=card; claimed with a POST. */
  function renderCard() {
    deferLoading('Loading your ID card…');
    screenReq('card').then(function (d) {
      var c = d.card, html = backTo('home', 'Home') + h1('Your ID card', 'Show it at the door and to your instructor for attendance.');
      if (c) {
        var lapsed = c.state && c.state !== 'active';
        html += '<section class="p-card"><div class="p-label">Card number</div>'
          + '<p class="p-title">' + esc(c.display_id || c.serial) + '</p>'
          + '<p class="p-caption">' + (lapsed ? 'This card has expired. Ask the academy office for a new one.' : 'Valid until ' + esc(c.expires_words || c.expires || '')) + '</p>'
          + '<a class="p-action" href="' + esc(c.url) + '" target="_blank" rel="noopener">Open, print or save your card</a></section>';
      } else if (d.eligible) {
        html += '<section class="p-card">' + empty('id-card', 'Your card has not been issued yet. You can get it now — it is also emailed to you.')
          + '<button type="button" class="p-action" id="cardClaim">Get my ID card</button></section>';
      } else {
        html += '<section class="p-card">' + empty('id-card', 'Your card is issued once you are enrolled in a class.') + '</section>';
      }
      paint(html);
    }).catch(function (err) { paint(backTo('home', 'Home') + h1('Your ID card') + empty('triangle-exclamation', errOf(err, 'Could not load your card.'))); });
  }
  function renderGrowth() { MAM_GROWTH.render(main, { toast: toast, paint: paint }); }
  /* The class space and the safeguarding form are shared with the other two
     portals (assets/js/portal-spaces.js); the server decides what each role
     sees, so this portal only hands over its own paint and toast. */
  function renderSpace() { MAM_SPACES.mount(main, { toast: toast, paint: paint }); }
  function renderConcern() { MAM_SPACES.concernForm(main, { toast: toast, paint: paint }); }
  /* Which bar item lights for a screen the bar does not itself carry. Fees and
     Repertoire light NOTHING rather than borrowing another tab's highlight — a
     bar that claims you are on Home while you are reading an invoice is worse
     than a bar that claims nothing. */
  var TAB_OF = { home: 'home', train: 'train', learn: 'learn', attend: 'attend', lesson: 'learn', exam: 'learn' };

  function go(screen, arg) {
    if (!SCREENS[screen]) screen = 'home';
    S.screen = screen;
    closeSheet();
    $$('#nav button[data-screen]').forEach(function (b) {
      var on = b.dataset.screen === TAB_OF[screen];
      if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
    });
    location.hash = screen + (arg ? '/' + arg : '');
    /* Armed BEFORE the render, consumed by the paint it produces. Activating
       an in-content control that navigates (the Home hero's button, "See the
       detail", a sheet row) destroys that control on repaint and the browser
       drops focus to <body> — so the next Tab restarts at the skip link and a
       keyboard user walks the whole nav again to reach the screen they just
       opened. Landing on the new heading is also the only thing that
       announces the screen change to a screen reader. */
    pendingFocus = true;
    SCREENS[screen](arg);
  }

  /**
   * ⚠️ The heading, explicitly — NOT whichever of the two comes first.
   *
   * `querySelector('.p-back, .p-h1')` returns the earlier match in DOCUMENT
   * order regardless of how the selector is written, and the back control is
   * rendered above the heading. So every screen change landed focus on a real
   * `<button>`, which paints a ring: MEASURED, `:focus-visible` matched after
   * a navigation with no keyboard interaction at all, so a mouse user got an
   * orange 2px outline on the back control on every screen. A heading with
   * `tabindex="-1"` is the conventional target precisely because it is not an
   * interactive control, and `.p-h1[tabindex]:focus { outline: none }` is
   * already in the stylesheet for exactly this.
   */
  function focusHead() {
    var head = main.querySelector('.p-h1') || main.querySelector('.p-back');
    if (head) { head.setAttribute('tabindex', '-1'); head.focus({ preventScroll: true }); }
  }

  /**
   * The More sheet is a real modal or it does not claim to be one.
   *
   * ⚠️ The markup carries `role="dialog" aria-modal="true"`, which TELLS a
   * screen reader that everything outside the sheet is inert. Setting
   * `hidden = false` and nothing else made that a lie: focus stayed behind the
   * sheet, Tab walked straight out of it, and Escape did nothing — on a sheet
   * whose rows include Sign out. This is the same mistake the site chrome
   * already removed once (`role="menu"` promising an arrow-key contract the
   * code never implemented); the fix there was to drop the role, and the fix
   * here is to honour it, because a bottom sheet genuinely is modal.
   */
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
    /* Focus goes back where it came from. Without this it falls to <body> and
       the user is returned to the top of the document, which is the same
       defect as the one above with an extra step. */
    if (sheetReturn && document.contains(sheetReturn)) sheetReturn.focus();
    sheetReturn = null;
  }

  /* Escape closes the sheet; the sheet traps Tab while it is open. */
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
    if (!t || !t.closest) return;

    var opener = t.closest('[data-sheet-open]');
    if (opener) { openSheet(opener); return; }
    if (t.closest('[data-sheet-close]')) { closeSheet(); return; }
    if (t.closest('#toastClose')) { $('#toast').hidden = true; return; }

    var nav = t.closest('[data-screen]');
    if (nav) { go(nav.dataset.screen, null); return; }

    var openL = t.closest('[data-open-lesson]');
    if (openL) { go('lesson', openL.dataset.openLesson); return; }
    var openX = t.closest('[data-open-exam]');
    if (openX) { go('exam', openX.dataset.openExam); return; }

    /* Lesson extras: exam, trial, feeling, notes, saved, helper. */
    if (t.closest('#exStart')) {
      var es = t.closest('#exStart');
      write(es, API.request('student/lessons', { method: 'POST', body: { action: 'exam_start', lesson_id: Number(es.dataset.lessonId),
        pledged: !!($('#exPledge') || {}).checked } }), function (r) { go('exam', r.exam.id); });
      return;
    }
    var tr = t.closest('[data-trial]');
    if (tr) {
      var pl = $('[data-pledge-trial="' + tr.dataset.trial + '"]');
      write(tr, API.request('student/lessons', { method: 'POST', body: { action: 'trial_start', course_id: Number(tr.dataset.trial),
        pledged: !!(pl && pl.checked) } }), function (r) { go('exam', r.exam.id); });
      return;
    }
    var fe = t.closest('[data-feel]');
    if (fe) {
      write(fe, API.request('student/lessons', { method: 'POST', body: { action: 'feedback', lesson_id: Number(fe.dataset.lessonId), feel: fe.dataset.feel } }),
        function () {
          $$('[data-feel]', main).forEach(function (b) { var on = b === fe; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        }, 'Thank you — that helps your teachers.');
      return;
    }
    if (t.closest('#lsnNoteSave')) {
      var ns = t.closest('#lsnNoteSave');
      write(ns, API.request('student/lessons', { method: 'POST', body: { action: 'note', lesson_id: Number(ns.dataset.lessonId), note: ($('#lsnNote') || {}).value || '' } }),
        null, 'Notes kept.');
      return;
    }
    if (t.closest('#lsnSave')) {
      var sv = t.closest('#lsnSave'), on = sv.getAttribute('aria-pressed') !== 'true';
      write(sv, API.request('student/lessons', { method: 'POST', body: { action: 'save', lesson_id: Number(sv.dataset.lessonId), saved: on } }),
        function () { sv.setAttribute('aria-pressed', on ? 'true' : 'false'); sv.textContent = on ? 'Saved for later' : 'Save for later'; });
      return;
    }
    if (t.closest('#hlpAsk')) {
      var ha = t.closest('#hlpAsk');
      write(ha, API.request('student/lessons', { method: 'POST', body: { action: 'helper', lesson_id: Number(ha.dataset.lessonId), question: ($('#hlpQ') || {}).value || '' } }),
        function (r) { var o = $('#hlpOut'); if (o) o.textContent = r.answer || ''; });
      return;
    }
    if (t.closest('#exSave')) { examSave(t.closest('#exSave').dataset.exam, false); return; }
    if (t.closest('#exSubmit')) {
      var xs = t.closest('#exSubmit');
      if (!window.confirm('Hand in the exam now? You cannot change your answers after this.')) return;
      examSubmit(xs.dataset.exam, xs);
      return;
    }
    if (t.closest('#exAppealSend')) {
      var xa = t.closest('#exAppealSend');
      write(xa, API.request('student/lessons', { method: 'POST', body: { action: 'exam_appeal', exam_id: Number(xa.dataset.exam), text: ($('#exAppeal') || {}).value || '' } }),
        function () { renderExam(xa.dataset.exam); }, 'Appeal sent to your teacher.');
      return;
    }

    /* Put-in-order: a step moves one place, and focus stays on the button that
       moved it, so a keyboard user can keep pressing. */
    var mv = t.closest('.s-lsn-mv');
    if (mv) {
      var li = mv.closest('li'), up = mv.dataset.move === 'up';
      var sib = up ? li.previousElementSibling : li.nextElementSibling;
      if (sib) { if (up) li.parentNode.insertBefore(li, sib); else li.parentNode.insertBefore(sib, li); }
      var again = li.querySelector('[data-move="' + mv.dataset.move + '"]');
      if (again) again.focus();
      return;
    }

    if (t.closest('#lsnSend')) {
      var sb = t.closest('#lsnSend'), lid = Number(sb.dataset.lessonId);
      write(sb, API.request('student/lessons', { method: 'POST', body: { action: 'submit', lesson_id: lid, answers: lessonAnswers() } }),
        function (r) {
          toast(r.passed ? 'Passed — well done.' : (r.pending ? 'Sent. Your teacher will mark the written answers.' : 'Not this time — have a look at what came back and try again.'),
            r.passed ? 'good' : null);
          renderLesson(lid);
        });
      return;
    }

    /* Check in. The gate is the server's — this only asks. `mode` follows the
       session's own mode so an online student is not recorded as onsite. */
    var lv = t.closest('[data-live]');
    if (lv) { location.href = '/portal/live.html?session=' + encodeURIComponent(lv.dataset.live); return; }
    var ci = t.closest('[data-checkin]');
    if (ci) {
      var n = (S.attend && S.attend.next) || (S.home && S.home.next) || {};
      write(ci, API.request('student/checkin', {
        method: 'POST',
        body: { action: 'checkin', session_id: Number(ci.dataset.checkin), mode: n.mode || 'onsite' }
      }), function () { go('attend'); }, 'Checked in. Your instructor can see you are here.');
      return;
    }
    var co = t.closest('[data-clockout]');
    if (co) {
      write(co, API.request('student/checkin', {
        method: 'POST', body: { action: 'clock_out', session_id: Number(co.dataset.clockout) }
      }), function () { go('attend'); }, 'Clocked out.');
      return;
    }

    var prep = t.closest('[data-prepare]');
    if (prep) {
      write(prep, API.request('student/preclass', {
        method: 'POST',
        body: { action: 'prepare', lesson_id: Number(prep.dataset.prepare), done: prep.dataset.done === '1' }
      }), function () { renderTrain(); }, 'Noted.');
      return;
    }

    var m = t.closest('.s-mins button');
    if (m) {
      $$('.s-mins button').forEach(function (b) { b.setAttribute('aria-pressed', b === m ? 'true' : 'false'); });
      return;
    }
    if (t.closest('#pracSave')) {
      var chosen = $('.s-mins button[aria-pressed="true"]');
      if (!chosen) { toast('How long did you practise? Pick a length first.', 'bad'); return; }
      write(t.closest('#pracSave'), API.request('student/practice-log', {
        method: 'POST',
        body: { minutes: Number(chosen.dataset.min), focus: ($('#pracFocus') || {}).value || null }
      }), function () { renderTrain(); }, 'Logged. That is today kept.');
      return;
    }

    var chk = t.closest('.s-check');
    if (chk) { chk.setAttribute('aria-checked', chk.getAttribute('aria-checked') === 'true' ? 'false' : 'true'); return; }

    if (t.closest('#tbSend')) {
      var tbBtn = t.closest('#tbSend');
      var body = ($('#tbBody') || {}).value || '';
      if (!body.trim()) { toast('Write your explanation first — a sentence or two is plenty.', 'bad'); return; }
      /* ⚠️ The three self-checks are NOT appended to the explanation.
         They used to be, and it compounded: the textarea is prefilled with
         the last submission, so "Send it again" posted the previous body
         WITH its appended checklist and then appended the checklist a second
         time. MEASURED on screen after one resubmit. They are a prompt the
         student reads before writing — §2.1 is explicit that this is never
         marked and `teachbacks` has no field for them, so inventing one
         would make it the graded task the design refuses. What they do is
         shape the sentence the student writes, which is the whole point. */
      write(tbBtn, API.request('student/preclass', {
        method: 'POST',
        body: { action: 'teachback', lesson_id: Number(tbBtn.dataset.lesson), kind: 'written', body: body }
      }), function () { renderTrain(); }, 'Sent. It is never marked and never counts towards your band.');
      return;
    }

    /* Keep it warm is a device-local reminder, not a record. There is no
       endpoint because there is nothing the academy should be told: the moment
       it is stored and reported it stops being retrieval practice. */
    var warm = t.closest('[data-warm]');
    if (warm) {
      var days = Number(warm.dataset.days) || 1;
      try {
        var due = new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);
        localStorage.setItem('mam-warm-due', due);
      } catch (er) {}
      $$('[data-warm]').forEach(function (b) { b.setAttribute('aria-pressed', b === warm ? 'true' : 'false'); });
      toast('Good. Come back to it in ' + days + ' ' + plural(days, 'day', 'days') + '.', 'good');
      return;
    }

    /* Opening the request reveals the form BELOW the charge it belongs to,
       rather than a `window.prompt`. Three things were wrong with the prompt:
       it ignores the design system and the reader's text-size setting
       entirely, it has no persistent label, and it can only return one string
       — so every request was filed under `other` while the server offers
       seven reason codes and the payload ships them. A waiver queue where
       every row reads "Other approved reason" cannot be triaged. */
    var waive = t.closest('[data-waive]');
    if (waive) {
      var form = $('#waive-' + waive.dataset.waive);
      if (!form) return;
      form.hidden = false;
      waive.setAttribute('aria-expanded', 'true');
      var sel = form.querySelector('select');
      if (sel) sel.focus();
      return;
    }
    var waiveCancel = t.closest('[data-waive-cancel]');
    if (waiveCancel) {
      var wf = $('#waive-' + waiveCancel.dataset.waiveCancel);
      if (wf) wf.hidden = true;
      var opener = document.querySelector('[data-waive="' + waiveCancel.dataset.waiveCancel + '"]');
      if (opener) { opener.setAttribute('aria-expanded', 'false'); opener.focus(); }
      return;
    }
    var waiveSend = t.closest('[data-waive-send]');
    if (waiveSend) {
      var id = waiveSend.dataset.waiveSend;
      var reason = ($('#waiveWhy-' + id) || {}).value || '';
      var detail = ($('#waiveNote-' + id) || {}).value || '';
      if (!reason) { fieldError(id, 'Choose the reason that fits best.'); return; }
      fieldError(id, '');
      write(waiveSend, API.request('student/accountability', {
        method: 'POST',
        body: { action: 'request_exception', charge_id: Number(id), reason_code: reason, detail: detail || null }
      }), function () { renderMoney(); }, 'Asked. Somebody will look at it and tell you what they decide.');
      return;
    }

    var fp = t.closest('[data-feepay]');
    if (fp) {
      write(fp, API.request('student/fee-pay', { method: 'POST', body: { invoice_id: Number(fp.dataset.feepay) } }),
        function (r) { if (r && r.authorization_url) location.href = r.authorization_url; });
      return;
    }
    if (t.closest('#cardClaim')) {
      write(t.closest('#cardClaim'), API.request('student/id-card', { method: 'POST', body: {} }),
        function () { renderCard(); }, 'Your card is ready. A copy is on its way to your email.');
      return;
    }
    if (t.closest('#repAdd')) {
      var title = ($('#repTitle') || {}).value || '';
      if (!title.trim()) { toast('What is the piece called?', 'bad'); return; }
      write(t.closest('#repAdd'), API.request('student/repertoire', {
        method: 'POST',
        body: { action: 'add', title: title, composer: ($('#repComposer') || {}).value || null, status: 'learning' }
      }), function () { renderRepertoire(); }, 'Added.');
      return;
    }
    var rs = t.closest('[data-repstatus]');
    if (rs) {
      write(rs, API.request('student/repertoire', {
        method: 'POST', body: { action: 'update', id: Number(rs.dataset.rep), status: rs.dataset.repstatus }
      }), function () { renderRepertoire(); }, 'Updated.');
      return;
    }
    var rd = t.closest('[data-repdel]');
    if (rd) {
      write(rd, API.request('student/repertoire', {
        method: 'POST', body: { action: 'delete', id: Number(rd.dataset.repdel) }
      }), function () { renderRepertoire(); }, 'Removed.');
      return;
    }

    if (P.a11yHandle && P.a11yHandle(t)) { e.preventDefault(); renderA11y(); return; }

    if (t.closest('#signOut')) {
      API.request('auth/logout', { method: 'POST' }).catch(function () {})
        .then(function () { location.href = '/portal/login'; });
    }
  });

  window.addEventListener('hashchange', function () {
    var parts = location.hash.replace('#', '').split('/');
    if (parts[0] && parts[0] !== S.screen) go(parts[0], parts[1]);
  });

  window.addEventListener('offline', function () {
    toast('You are offline. What is on screen is the last thing we loaded, and nothing you '
      + 'change will be saved until you have data again.', 'bad');
  });

  /* ── Boot ───────────────────────────────────────────────────────────────── */

  function applyIdentity(d) {
    var me = d.me || {};
    P.identity(me.name || 'Student', 'Student');
    $('#railName').textContent = me.name || 'Student';
    $('#railInitials').textContent = initials(me.name);
    var en = (d.next && d.next.cohort_title) || '';
    $('#railCourse').textContent = en;
    var owed = Number((d.owed || {}).cents) || 0;
    var badge = $('#moneyBadge'), sheetOwed = $('#sheetOwed');
    if (owed > 0) {
      badge.hidden = false;
      badge.setAttribute('aria-label', money(owed) + ' outstanding');
      sheetOwed.hidden = false; sheetOwed.textContent = money(owed);
    } else {
      badge.hidden = true; sheetOwed.hidden = true;
    }
  }

  function bootError(fallback) {
    return function (err) {
      var code = (err && err.body && err.body.error) || '';
      if (code === 'unauthorized' || code === 'forbidden' || (err && err.status === 401)) {
        location.href = '/portal/login?next=' + encodeURIComponent('/portal/student/');
        return;
      }
      paint(empty('triangle-exclamation', errOf(err, fallback)
        + '<br><br>If this keeps happening, the academy office can help.'));
    };
  }

  screenReq('home')
    .then(function (d) {
      S.home = d;
      applyIdentity(d);
      var parts = location.hash.replace('#', '').split('/');
      go(SCREENS[parts[0]] ? parts[0] : 'home', parts[1]);
    })
    .catch(bootError('Could not load your portal.'));
})();
