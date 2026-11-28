<?php
/**
 * /checkin — the student's check-in page.
 *
 * Why a page of its own, when the student portal already has a Check-in tab.
 * The tab is the right place to READ about a session — preparation, teach-back,
 * streak, the week. This is the thing a student opens at a door, on a phone,
 * thirty seconds before a lesson, and it has exactly one job: get them marked
 * present. So it does not load the portal SPA (226KB and a tab to find), it
 * carries no navigation to get lost in, and the check-in button is the largest
 * thing on the screen.
 *
 * It is a thin client over `GET/POST /api/student/checkin`. No new endpoint, no
 * new library, no migration: every gate — the window, enrolment, the late grace,
 * the geofence — stays server-authoritative where it already was. A second
 * implementation of "may this student check in" is how two surfaces come to
 * disagree, and the one in a student's hand would be the wrong one.
 *
 * Distinct from /attend, which handles a SCANNED token. Both end in the same
 * mark; the difference is who initiates. A student who cannot see the
 * instructor's QR — sat at the back, or online — needs this one.
 */
declare(strict_types=1);
$MAM_CI_VER = '20260819a';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Check in — MAM Academy</title>
<meta name="description" content="Check in to today's MAM Academy session.">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260920">
<style>
  /* Phone-first and deliberately narrow: this is held in one hand, in a
     corridor. Nothing here is wider than a thumb's reach. */
  .ci-wrap{max-width:520px;margin:0 auto;padding:24px 18px 72px}
  .ci-card{background:var(--bg-surface,#1A120B);border:1px solid var(--border-gold,rgba(224,138,26,.35));
    border-radius:20px;padding:24px 20px;box-shadow:0 24px 60px rgba(0,0,0,.45)}
  .ci-eyebrow{font-family:'Bebas Neue',Impact,sans-serif;letter-spacing:.14em;text-transform:uppercase;
    font-size:.82rem;color:var(--gold-bright,#FCC647);margin:0 0 6px}
  .ci-card h1{font-family:var(--font-serif,Georgia,serif);font-size:1.55rem;line-height:1.2;
    color:var(--text,#fff);margin:0 0 10px;text-wrap:balance}
  .ci-card p{color:var(--text-soft,#cbb89e);font-size:.97rem;line-height:1.6;margin:0 0 10px}
  .ci-when{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin:14px 0 4px}
  .ci-time{font-family:'Bebas Neue',Impact,sans-serif;font-size:2.4rem;line-height:1;
    color:var(--text,#fff);font-variant-numeric:tabular-nums}
  .ci-cohort{font-size:.9rem;color:var(--text-muted,#9a8264)}
  /* The countdown is the page's one moving part, and it is the answer to the
     only question the student has: am I in time? */
  .ci-count{margin:16px 0;padding:14px 16px;border-radius:12px;font-weight:600;
    display:flex;gap:10px;align-items:flex-start;line-height:1.45;font-size:.95rem}
  .ci-count.ok{background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.35);color:#6EE7B7}
  .ci-count.soon{background:rgba(224,138,26,.13);border:1px solid rgba(224,138,26,.4);color:#FCC647}
  .ci-count.shut{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.35);color:#FCA5A5}
  .ci-count.info{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:var(--text-soft,#cbb89e)}
  .ci-modes{display:flex;gap:8px;margin:18px 0 6px;flex-wrap:wrap}
  /* 48px so it is a real target on a phone, not a 24px minimum scraped past. */
  button.ci-mode{flex:1 1 auto;min-width:96px;min-height:48px;border-radius:12px;cursor:pointer;
    background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.14);color:var(--text-soft,#cbb89e);
    font:600 .95rem Inter,sans-serif;display:flex;align-items:center;justify-content:center;gap:7px}
  button.ci-mode[aria-pressed="true"]{background:rgba(224,138,26,.16);border-color:var(--gold-bright,#FCC647);
    color:var(--gold-bright,#FCC647)}
  button.ci-mode:focus-visible{outline:2px solid var(--gold-bright,#FCC647);outline-offset:2px}
  /* `button.ci-go`/`a.ci-go`, not `.ci-go`: main.css sets
     `.btn,button.btn,a.btn,…{min-height:44px}`, and a bare class loses to
     `button.btn` on specificity — the source said 62px and the button rendered
     51px. Measured, not assumed. This is the primary action on a page somebody
     is using one-handed in a corridor, so it gets the height it asks for. */
  button.ci-go, a.ci-go{width:100%;min-height:62px;margin-top:14px;font:800 1.12rem Inter,sans-serif;
    border-radius:16px;display:flex;align-items:center;justify-content:center;gap:10px}
  button.ci-go[disabled]{opacity:.45;cursor:not-allowed}
  .ci-sub{margin-top:14px;font-size:.85rem;color:var(--text-muted,#9a8264);line-height:1.55}
  .ci-strip{display:flex;gap:8px;margin-top:16px}
  .ci-stat{flex:1;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);
    border-radius:12px;padding:10px 8px;text-align:center}
  .ci-stat b{display:block;font-family:'Bebas Neue',Impact,sans-serif;font-size:1.5rem;
    color:var(--text,#fff);line-height:1.1;font-variant-numeric:tabular-nums}
  .ci-stat span{font-size:.7rem;letter-spacing:.09em;text-transform:uppercase;color:var(--text-muted,#9a8264)}
  .ci-links{margin-top:20px;display:flex;gap:14px;flex-wrap:wrap;font-size:.9rem}
  .ci-links a{color:var(--gold-bright,#FCC647)}
  .ci-spin{display:inline-block;width:18px;height:18px;border:3px solid rgba(224,138,26,.3);
    border-top-color:var(--gold-bright,#FCC647);border-radius:50%;animation:cispin .8s linear infinite}
  @keyframes cispin{to{transform:rotate(360deg)}}
  @media (prefers-reduced-motion:reduce){.ci-spin{animation:none}}
  .ci-hide{display:none!important}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<main id="main">
  <div class="ci-wrap">
    <div class="ci-card" id="ciCard">
      <p class="ci-eyebrow" id="ciEyebrow">Check in</p>
      <h1 id="ciTitle"><span class="ci-spin" aria-hidden="true"></span></h1>
      <!-- One live region for the whole card: a student using a screen reader
           should hear the outcome once, not each fragment as it is written. -->
      <div id="ciLive" role="status" aria-live="polite"></div>
      <div id="ciBody"></div>
    </div>
    <div class="ci-links">
      <a href="/portal/student/#checkin"><i class="fa-solid fa-arrow-left"></i> Full portal</a>
      <a href="/portal/student/#attendance">My attendance record</a>
    </div>
  </div>
</main>
<script src="assets/js/config.js?v=20261128v"></script>
<script>
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var API = window.MAM_API;
  var mode = 'onsite', state = null, ticker = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  // 'YYYY-MM-DD HH:MM:SS' is a local wall clock, not an instant. Safari refuses
  // to parse it with a space, so it is normalised rather than trusted.
  function when(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }
  function clock(d) {
    try { return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
    catch (e) { return ''; }
  }
  function human(sec) {
    sec = Math.abs(Math.round(sec));
    if (sec < 60) return sec + ' second' + (sec === 1 ? '' : 's');
    var m = Math.round(sec / 60);
    if (m < 60) return m + ' minute' + (m === 1 ? '' : 's');
    var h = Math.floor(m / 60);
    return h + ' hour' + (h === 1 ? '' : 's') + (m % 60 ? ' ' + (m % 60) + ' min' : '');
  }

  function paint(title, bodyHtml, eyebrow) {
    $('ciEyebrow').textContent = eyebrow || 'Check in';
    $('ciTitle').textContent = title;
    $('ciBody').innerHTML = bodyHtml;
  }
  function say(msg) { $('ciLive').textContent = msg; }

  function signedOut() {
    var back = encodeURIComponent('/checkin');
    paint('Sign in to check in',
      '<p>Your attendance is recorded against your own account, so we need to know it is you.</p>'
      + '<a class="btn btn-primary ci-go" href="/portal/login.html?next=' + back + '">'
      + '<i class="fa-solid fa-right-to-bracket"></i> Sign in</a>', 'Check in');
    say('Sign in to check in.');
  }

  function statStrip(d) {
    // An unassessed figure is null, not 0 — a new student shown 0% reads as
    // having missed everything. Em dash means "no answer yet".
    var att = (d.attendance_rate === null || d.attendance_rate === undefined)
      ? '—' : Math.round(d.attendance_rate) + '%';
    return '<div class="ci-strip">'
      + '<div class="ci-stat"><b>' + esc(d.week || '—') + (d.total_weeks ? '/' + esc(d.total_weeks) : '') + '</b><span>Week</span></div>'
      + '<div class="ci-stat"><b>' + esc(d.streak == null ? '—' : d.streak) + '</b><span>Streak</span></div>'
      + '<div class="ci-stat"><b>' + att + '</b><span>Attendance</span></div>'
      + '</div>';
  }

  function modeButtons(modes) {
    var labels = { onsite: ['fa-building-columns', 'Onsite'], online: ['fa-video', 'Online'], hybrid: ['fa-tower-broadcast', 'Hybrid'] };
    return '<div class="ci-modes" role="group" aria-label="How are you attending?">'
      + (modes || ['onsite', 'online', 'hybrid']).map(function (m) {
          var l = labels[m] || ['fa-circle', m];
          return '<button type="button" class="ci-mode" data-mode="' + esc(m) + '" aria-pressed="'
            + (m === mode ? 'true' : 'false') + '"><i class="fa-solid ' + l[0] + '"></i>' + esc(l[1]) + '</button>';
        }).join('')
      + '</div>';
  }

  function render() {
    if (!state) return;
    var d = state.dashboard || {}, nc = state.next_class, cfg = state.config || {};
    var today = d.today;

    if (!today && !nc) {
      paint('Nothing to check in to',
        '<p>You have no session scheduled. When your instructor schedules one it will appear here, '
        + 'and the check-in button opens ' + esc(cfg.open_before_min || 30) + ' minutes before it starts.</p>'
        + statStrip(d), 'All clear');
      say('No session scheduled.');
      return;
    }

    var sess = today || {};
    var w = (nc && nc.window) || {};
    var start = when(sess.scheduled_at || (nc && nc.scheduled_at));
    var head = '<div class="ci-when"><span class="ci-time">' + esc(start ? clock(start) : '—') + '</span>'
      + '<span class="ci-cohort">' + esc((nc && nc.cohort_title) || (d.cohort && d.cohort.title) || '') + '</span></div>'
      + '<p>' + esc(sess.title || (nc && nc.title) || 'Your session') + '</p>';

    // Already marked: the page's job is done, so say so plainly and offer the
    // only thing left — clocking out, which is the other half of the clock.
    if (sess.checked_in) {
      var att = (nc && nc.attendance) || {};
      var out = att.clocked_out_at
        ? '<p class="ci-sub">You clocked out at ' + esc(clock(when(att.clocked_out_at)))
          + (att.duration_min ? ' after ' + esc(att.duration_min) + ' minutes.' : '.') + '</p>'
        : '<button class="btn btn-secondary ci-go" id="ciOut"><i class="fa-solid fa-door-open"></i> Clock out</button>'
          + '<p class="ci-sub">Clock out when the session ends so your time present is recorded.</p>';
      paint('You are checked in', head
        + '<div class="ci-count ok"><i class="fa-solid fa-circle-check"></i><span>Marked '
        + esc(sess.status || 'present') + (att.minutes_late ? ', ' + esc(att.minutes_late) + ' minutes late' : '')
        + '. Have a good session.</span></div>' + out + statStrip(d), 'Done');
      say('You are checked in and marked ' + (sess.status || 'present') + '.');
      return;
    }

    if (cfg.self_enabled === false) {
      paint('Your instructor marks the register', head
        + '<div class="ci-count info"><i class="fa-solid fa-circle-info"></i><span>Self check-in is turned off '
        + 'for this academy. Your instructor will mark you present.</span></div>' + statStrip(d), 'Check in');
      say('Self check-in is turned off.');
      return;
    }

    var secs = typeof w.seconds_to_start === 'number' ? w.seconds_to_start : null;
    var opens = when(w.opens_at), ready = when(w.ready_by);
    var canNow = !!sess.can_check_in;

    if (!canNow && opens && Date.now() < opens.getTime()) {
      paint('Not open yet', head
        + '<div class="ci-count soon" id="ciCount"><i class="fa-regular fa-clock"></i><span>Check-in opens at '
        + esc(clock(opens)) + '</span></div>'
        + '<p class="ci-sub">' + esc(w.message || '') + '</p>' + statStrip(d), 'Check in');
      say('Check-in opens at ' + clock(opens) + '.');
      startTicker();
      return;
    }
    if (!canNow) {
      paint('Check-in has closed', head
        + '<div class="ci-count shut"><i class="fa-solid fa-circle-exclamation"></i><span>The window for this '
        + 'session has closed. Speak to your instructor — they can still mark you present.</span></div>'
        + statStrip(d), 'Check in');
      say('Check-in has closed for this session.');
      return;
    }

    var urgency = (ready && Date.now() > ready.getTime()) ? 'soon' : 'ok';
    var line = secs === null ? esc(w.message || 'You can check in now.')
      : (secs > 0 ? 'Starts in ' + human(secs) + (ready ? ' — be ready by ' + clock(ready) : '')
                  : 'Started ' + human(secs) + ' ago');
    paint('Check in', head
      + '<div class="ci-count ' + urgency + '" id="ciCount"><i class="fa-solid fa-hand-sparkles"></i><span id="ciCountText">'
      + esc(line) + '</span></div>'
      + modeButtons(cfg.modes)
      + '<button class="btn btn-primary ci-go" id="ciGo"><i class="fa-solid fa-hand-sparkles"></i> Check in now</button>'
      + '<p class="ci-sub">' + esc(w.message || '') + '</p>' + statStrip(d), 'Check in');
    say(line);
    wire();
    startTicker();
  }

  function startTicker() {
    if (ticker) clearInterval(ticker);
    // Once a minute: the numbers are in minutes, and a per-second repaint on a
    // phone in a corridor is battery spent for nothing.
    ticker = setInterval(function () {
      var el = $('ciCountText');
      if (!el || !state || !state.next_class || !state.next_class.window) return load(true);
      var start = when(state.next_class.window.starts_at);
      if (!start) return;
      var secs = Math.round((start.getTime() - Date.now()) / 1000);
      el.textContent = secs > 0 ? 'Starts in ' + human(secs) : 'Started ' + human(secs) + ' ago';
      // The window may have opened or closed since the payload was fetched, and
      // the server is the only authority on which.
      if (Math.abs(secs) % 300 < 60) load(true);
    }, 60000);
  }

  function wire() {
    Array.prototype.forEach.call(document.querySelectorAll('.ci-mode'), function (b) {
      b.addEventListener('click', function () {
        mode = b.getAttribute('data-mode') || 'onsite';
        Array.prototype.forEach.call(document.querySelectorAll('.ci-mode'), function (o) {
          o.setAttribute('aria-pressed', o === b ? 'true' : 'false');
        });
      });
    });
    var go = $('ciGo');
    if (go) go.addEventListener('click', function () { submit('checkin', go); });
    var out = $('ciOut');
    if (out) out.addEventListener('click', function () { submit('clock_out', out); });
  }

  function submit(action, btn) {
    btn.disabled = true;
    var was = btn.innerHTML;
    btn.innerHTML = '<span class="ci-spin" aria-hidden="true"></span> Working…';
    var body = { action: action };
    var sid = state && state.dashboard && state.dashboard.today && state.dashboard.today.session_id;
    if (sid) body.session_id = sid;
    if (action === 'checkin') body.mode = mode;
    API.request('student/checkin', { method: 'POST', body: body }).then(function () {
      return load();
    }).catch(function (e) {
      btn.disabled = false; btn.innerHTML = was;
      var code = (e && e.body && e.body.error) || '';
      var MAP = {
        checkin_not_open:  'Check-in is not open yet for this session.',
        checkin_closed:    'The check-in window has closed. Your instructor can still mark you present.',
        not_enrolled:      'You are not on the roster for that session.',
        session_not_found: 'That session no longer exists. Reload the page.',
        checkin_disabled:  'Self check-in is turned off for this academy.',
        location_required: 'This session needs your location to check in. Allow location access and try again.',
        out_of_range:      'You are too far from the venue to check in here.',
        already_checked_in:'You are already checked in.',
        not_checked_in:    'You are not checked in, so there is nothing to clock out of.',
        rate_limited:      'Too many attempts just now — wait a moment and try again.'
      };
      var msg = MAP[code] || 'Could not check you in. Please try again, or ask your instructor to mark you.';
      var c = $('ciCount');
      if (c) { c.className = 'ci-count shut'; c.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i><span></span>'; c.querySelector('span').textContent = msg; }
      say(msg);
      // A stale window is the commonest cause, and the server knows the truth.
      if (code === 'checkin_not_open' || code === 'checkin_closed' || code === 'already_checked_in') load(true);
    });
  }

  function load(quiet) {
    if (!quiet) { $('ciTitle').innerHTML = '<span class="ci-spin" aria-hidden="true"></span>'; $('ciBody').innerHTML = ''; }
    return API.request('student/checkin', { method: 'GET' }).then(function (r) {
      state = r; render();
    }).catch(function (e) {
      var code = (e && e.body && e.body.error) || '';
      if (e && (e.status === 401 || code === 'unauthenticated' || code === 'forbidden')) return signedOut();
      paint('We could not load your session',
        '<p>' + esc(code === 'api_unavailable' || (e && e.apiDown)
          ? 'We could not reach the server. Check your connection and try again.'
          : 'Something went wrong on our side. Please try again in a moment.') + '</p>'
        + '<button class="btn btn-primary ci-go" onclick="location.reload()">'
        + '<i class="fa-solid fa-rotate-right"></i> Try again</button>', 'Check in');
      say('Could not load your session.');
    });
  }

  if (!API) { signedOut(); return; }
  load();
  // Coming back to a backgrounded tab at the door: the window has almost
  // certainly moved on, so re-ask rather than showing a stale countdown.
  document.addEventListener('visibilitychange', function () { if (!document.hidden) load(true); });
})();
</script>
</body>
</html>
