<?php
/**
 * /scan — the instructor's register. Open the class, then scan each child's card.
 *
 * This is the academy's actual check-in model, and it is the reverse of the one
 * the student pages assume. Children here are young: a nine-year-old does not
 * arrive with a phone, so the instructor logs the session and signs each child in
 * from the QR on their ID card. Summer camp arrives as a group and is marked in
 * one action instead.
 *
 * So the flow is: pick the session → the camera opens → scan cards → each result
 * lands in a running list with the child's name. Nothing is typed, because a
 * register taken one-handed while twenty children come through a door has to be
 * faster than paper or it will not be used.
 *
 * A thin client over three endpoints that already existed:
 *   GET  /api/instructor/sessions             — the cohorts and their sessions
 *   POST /api/instructor/card-scan            — one card → one mark
 *   POST /api/instructor/session-checkin-all  — the whole roster in one action
 *
 * jsQR is self-hosted (assets/js/vendor/jsQR.min.js): the CSP blocks CDNs, and a
 * camera page that silently fails to load its decoder is worse than no page.
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title>Take the register — MAM Academy</title>
<meta name="robots" content="noindex, nofollow">
<!-- The camera needs this on the page itself, not just in a header. -->
<meta http-equiv="Permissions-Policy" content="camera=(self)">
<link rel="icon" type="image/svg+xml" href="mam-academy-logo.svg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="assets/css/main.css?v=20260920">
<style>
  .sc-wrap{max-width:560px;margin:0 auto;padding:20px 16px 80px}
  .sc-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:14px}
  .sc-head h1{font-family:var(--font-serif,Georgia,serif);font-size:1.4rem;margin:0;color:var(--text,#fff)}
  .sc-head a{font-size:.85rem;color:var(--gold-bright,#FCC647)}
  .sc-panel{background:var(--bg-surface,#1A120B);border:1px solid var(--border-gold,rgba(224,138,26,.32));
    border-radius:16px;padding:16px;margin-bottom:14px}
  .sc-panel h2{font-family:'Bebas Neue',Impact,sans-serif;letter-spacing:.1em;text-transform:uppercase;
    font-size:.86rem;color:var(--gold-bright,#FCC647);margin:0 0 10px}
  select.sc-sel{width:100%;min-height:48px;font:500 1rem Inter,sans-serif;padding:10px 12px;border-radius:12px;
    background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.16);color:var(--text,#fff)}
  /* The viewfinder is square: a card QR is square, and a letterboxed preview
     makes people hunt for the frame. */
  .sc-cam{position:relative;aspect-ratio:1;border-radius:12px;overflow:hidden;background:#000;
    border:1px solid rgba(255,255,255,.14)}
  .sc-cam video{width:100%;height:100%;object-fit:cover;display:block}
  .sc-reticle{position:absolute;inset:18%;border:2px solid rgba(252,198,71,.85);border-radius:12px;
    pointer-events:none;box-shadow:0 0 0 100vmax rgba(0,0,0,.35)}
  .sc-camoff{display:flex;align-items:center;justify-content:center;height:100%;padding:24px;
    text-align:center;color:var(--text-soft,#cbb89e);font-size:.93rem;line-height:1.55}
  button.sc-btn{width:100%;min-height:56px;margin-top:12px;font:700 1.02rem Inter,sans-serif;border-radius:12px;
    display:flex;align-items:center;justify-content:center;gap:9px}
  button.sc-btn.sc-wide{min-height:62px;font-size:1.08rem}
  .sc-row{display:flex;gap:10px;align-items:center;padding:11px 12px;border-radius:12px;margin-top:8px;
    background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.09)}
  .sc-row .sc-nm{flex:1;min-width:0;font-weight:600;color:var(--text,#fff);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .sc-row .sc-tm{font-size:.78rem;color:var(--text-muted,#9a8264);font-variant-numeric:tabular-nums}
  .sc-ic{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
  /* Colour is never the only signal — every row carries an icon and words. */
  .sc-ok  .sc-ic{background:rgba(52,211,153,.18);color:#6EE7B7}
  .sc-dup .sc-ic{background:rgba(224,138,26,.18);color:#FCC647}
  .sc-bad .sc-ic{background:rgba(248,113,113,.18);color:#FCA5A5}
  .sc-note{font-size:.8rem;color:var(--text-muted,#9a8264);margin:6px 0 0;line-height:1.5}
  .sc-count{display:flex;gap:8px;margin-top:12px}
  .sc-count div{flex:1;text-align:center;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);
    border-radius:12px;padding:9px 6px}
  .sc-count b{display:block;font-family:'Bebas Neue',Impact,sans-serif;font-size:1.4rem;color:var(--text,#fff);
    font-variant-numeric:tabular-nums;line-height:1.15}
  .sc-count span{font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted,#9a8264)}
  .sc-hide{display:none!important}
</style>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<main id="main">
 <div class="sc-wrap">
  <div class="sc-head">
    <h1>Take the register</h1>
    <a href="/portal/instructor/#attendance">Instructor portal</a>
  </div>

  <div class="sc-panel">
    <h2>Which session</h2>
    <label class="sc-hide" for="scSession">Session</label>
    <select class="sc-sel" id="scSession" aria-label="Session"><option value="">Loading…</option></select>
    <p class="sc-note" id="scRoster"></p>
  </div>

  <div class="sc-panel" id="scScanPanel">
    <h2>Scan each card</h2>
    <div class="sc-cam" id="scCam">
      <div class="sc-camoff" id="scCamOff">The camera is off. Start it, then hold each student's ID card so the
        code sits inside the frame.</div>
      <video id="scVideo" playsinline muted class="sc-hide"></video>
      <div class="sc-reticle sc-hide" id="scReticle"></div>
    </div>
    <button class="btn btn-primary sc-btn sc-wide" id="scStart"><i class="fa-solid fa-camera"></i> Start camera</button>
    <p class="sc-note" id="scHint">Each scan is marked immediately — there is nothing to submit at the end.</p>
  </div>

  <div class="sc-panel">
    <h2>Whole group at once</h2>
    <p class="sc-note" style="margin:0 0 10px">For summer camp and any class that arrives together. Marks everyone
      on the roster who has no mark yet, and leaves the ones you have already recorded alone.</p>
    <button class="btn btn-secondary sc-btn" id="scAll"><i class="fa-solid fa-users"></i> Mark the whole roster present</button>
  </div>

  <div class="sc-panel">
    <h2>This session</h2>
    <div class="sc-count">
      <div><b id="scNPresent">0</b><span>Present</span></div>
      <div><b id="scNRoster">—</b><span>Roster</span></div>
      <div><b id="scNScans">0</b><span>Scans</span></div>
    </div>
    <div id="scLog" role="status" aria-live="polite"></div>
  </div>
 </div>
</main>
<script src="assets/js/config.js?v=20260807a"></script>
<script>
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var API = window.MAM_API;
  var sessions = {}, current = null, stream = null, raf = null, decoding = false;
  var scans = 0, seen = {};

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function now() { try { return new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; } }

  // Every row states its outcome in words as well as colour: this is read at a
  // glance in a doorway, sometimes by somebody who cannot tell the greens from
  // the ambers.
  // Keyed to the EXACT strings /api/instructor/card-scan returns — `marked`, not
  // `present`. Getting this wrong is silent and total: an unknown key falls to
  // `invalid`, so every successful scan read "Not a MAM card" while the child was
  // in fact being marked. Caught by driving a real card through it.
  var LOOK = {
    marked:       ['sc-ok',  'circle-check',        'Marked present'],
    already:      ['sc-dup', 'circle-half-stroke',  'Already marked'],
    expired:      ['sc-bad', 'clock-rotate-left',   'Card expired — needs replacing'],
    revoked:      ['sc-bad', 'ban',                 'Card revoked'],
    not_enrolled: ['sc-bad', 'user-xmark',          'Not on this roster'],
    invalid:      ['sc-bad', 'triangle-exclamation','Not a MAM card'],
    // Not from the scan endpoint — the group button reuses this renderer.
    present:      ['sc-ok',  'users',               'Group marked present']
  };

  function logRow(result, name, extra) {
    var look = LOOK[result] || LOOK.invalid;
    var row = document.createElement('div');
    row.className = 'sc-row ' + look[0];
    row.innerHTML = '<span class="sc-ic"><i class="fa-solid fa-' + look[1] + '"></i></span>'
      + '<span class="sc-nm"></span><span class="sc-tm">' + esc(now()) + '</span>';
    row.querySelector('.sc-nm').textContent = (name || 'Unknown card') + ' — ' + look[2] + (extra ? ' (' + extra + ')' : '');
    $('scLog').insertBefore(row, $('scLog').firstChild);
    scans++;
    $('scNScans').textContent = String(scans);
    beep(result === 'present');
  }

  // A short tone, because the instructor is looking at the child and the card,
  // not at the screen. Silent if the browser will not allow audio.
  function beep(good) {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = new Ctx(), o = ctx.createOscillator(), g = ctx.createGain();
      o.frequency.value = good ? 880 : 300;
      g.gain.value = 0.05;
      o.connect(g); g.connect(ctx.destination); o.start();
      setTimeout(function () { o.stop(); ctx.close(); }, good ? 90 : 220);
    } catch (e) { /* audio is a courtesy, never a requirement */ }
  }

  function refreshCounts() {
    if (!current) return;
    API.request('instructor/session-attendance?session_id=' + encodeURIComponent(current), { method: 'GET' })
      .then(function (r) {
        var marks = (r && r.marks) || [];
        var present = marks.filter(function (m) { return m.status === 'present' || m.status === 'late'; }).length;
        $('scNPresent').textContent = String(present);
        $('scNRoster').textContent = String(marks.length);
        $('scRoster').textContent = marks.length
          ? present + ' of ' + marks.length + ' marked so far.'
          : 'This cohort has nobody on its roster yet.';
      }).catch(function () { /* the counters are a courtesy; scanning still works */ });
  }

  function loadSessions() {
    API.request('instructor/sessions', { method: 'GET' }).then(function (r) {
      var cohorts = (r && r.cohorts) || [];
      if (!cohorts.length) {
        $('scSession').innerHTML = '<option value="">No cohorts assigned to you</option>';
        $('scRoster').textContent = 'You are not teaching any cohort yet, so there is no register to take.';
        return;
      }
      // Sessions per cohort, fetched in parallel and grouped by cohort in the
      // list — an instructor teaching four cohorts should not have to guess
      // which "Week 3" is which.
      Promise.all(cohorts.map(function (c) {
        return API.request('instructor/sessions?cohort_id=' + encodeURIComponent(c.id), { method: 'GET' })
          .then(function (d) { return { cohort: c, sessions: (d && d.sessions) || [] }; })
          .catch(function () { return { cohort: c, sessions: [] }; });
      })).then(function (groups) {
        var html = '', any = false;
        groups.forEach(function (g) {
          var upcoming = g.sessions.filter(function (s) { return s.status !== 'cancelled'; });
          if (!upcoming.length) return;
          html += '<optgroup label="' + esc(g.cohort.title || ('Cohort ' + g.cohort.id)) + '">';
          upcoming.forEach(function (s) {
            var when = String(s.scheduled_at || '').slice(0, 16).replace('T', ' ');
            html += '<option value="' + esc(s.id) + '">' + esc(when + ' · ' + (s.title || 'Session ' + s.id)) + '</option>';
            sessions[String(s.id)] = s;
            any = true;
          });
          html += '</optgroup>';
        });
        $('scSession').innerHTML = any ? html : '<option value="">No sessions scheduled</option>';
        if (any) { current = $('scSession').value; refreshCounts(); }
        else $('scRoster').textContent = 'No sessions scheduled yet — create one in the instructor portal first.';
      });
    }).catch(function (e) {
      var code = (e && e.body && e.body.error) || '';
      if (e && (e.status === 401 || e.status === 403 || code === 'forbidden' || code === 'unauthenticated')) {
        $('scSession').innerHTML = '<option value="">Sign in as an instructor</option>';
        $('scRoster').innerHTML = '<a href="/portal/login.html?next=%2Fscan">Sign in to take the register</a>';
        return;
      }
      $('scRoster').textContent = 'Could not load your sessions. Reload the page and try again.';
    });
  }

  function send(payload) {
    if (!current) { logRow('invalid', 'No session selected'); return; }
    API.request('instructor/card-scan', { method: 'POST', body: { payload: payload, session_id: parseInt(current, 10) } })
      .then(function (r) {
        var st = (r && r.student) || null;
        logRow((r && r.result) || 'invalid', st ? st.name : null);
        refreshCounts();
      })
      .catch(function (e) {
        var code = (e && e.body && e.body.error) || '';
        logRow('invalid', 'Card not accepted', code || 'try again');
      });
  }

  function tick() {
    var v = $('scVideo');
    if (!stream || !v || v.readyState < 2 || decoding) { raf = requestAnimationFrame(tick); return; }
    try {
      var c = tick.canvas || (tick.canvas = document.createElement('canvas'));
      var side = Math.min(v.videoWidth, v.videoHeight) || 0;
      if (!side) { raf = requestAnimationFrame(tick); return; }
      c.width = c.height = Math.min(side, 640);
      var ctx = c.getContext('2d', { willReadFrequently: true });
      // Centre crop, matching the reticle: decoding the whole frame picks up a
      // second card in the queue behind the one being presented.
      var sx = (v.videoWidth - side) / 2, sy = (v.videoHeight - side) / 2;
      ctx.drawImage(v, sx, sy, side, side, 0, 0, c.width, c.height);
      var img = ctx.getImageData(0, 0, c.width, c.height);
      var res = window.jsQR ? window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' }) : null;
      if (res && res.data) {
        // One card, one mark: the same code stays in frame for many frames, and
        // without this every scan would fire twenty times.
        var key = res.data;
        if (!seen[key] || Date.now() - seen[key] > 4000) {
          seen[key] = Date.now();
          decoding = true;
          send(key);
          setTimeout(function () { decoding = false; }, 900);
        }
      }
    } catch (e) { /* a bad frame is not worth stopping the loop for */ }
    raf = requestAnimationFrame(tick);
  }

  function startCamera() {
    var btn = $('scStart');
    if (stream) {
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
      if (raf) cancelAnimationFrame(raf);
      $('scVideo').classList.add('sc-hide'); $('scReticle').classList.add('sc-hide');
      $('scCamOff').classList.remove('sc-hide');
      btn.innerHTML = '<i class="fa-solid fa-camera"></i> Start camera';
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      $('scCamOff').textContent = 'This browser cannot open a camera. Mark the register from the instructor portal instead.';
      return;
    }
    btn.disabled = true;
    // The decoder is fetched only when a camera is actually wanted — it is 130KB
    // and most visits to this page are to press the group button.
    var need = window.jsQR ? Promise.resolve() : new Promise(function (ok, no) {
      var s = document.createElement('script');
      s.src = '/assets/js/vendor/jsQR.min.js'; s.onload = ok; s.onerror = no;
      document.head.appendChild(s);
    });
    need.then(function () {
      // `environment` is the rear camera on a phone, which is the one pointed at
      // a card. Falling back rather than failing: a laptop has only one.
      return navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
    }).then(function (s) {
      stream = s;
      var v = $('scVideo');
      v.srcObject = s; v.classList.remove('sc-hide');
      $('scReticle').classList.remove('sc-hide'); $('scCamOff').classList.add('sc-hide');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop camera';
      return v.play();
    }).then(function () { tick(); }).catch(function (err) {
      btn.disabled = false;
      var name = (err && err.name) || '';
      $('scCamOff').textContent = name === 'NotAllowedError'
        ? 'Camera access was refused. Allow it in your browser settings, or use the group button below.'
        : (window.jsQR ? 'Could not open the camera. Use the group button below instead.'
                       : 'The code reader could not load. Reload the page, or use the group button below.');
    });
  }

  $('scSession').addEventListener('change', function () {
    current = this.value; seen = {};
    refreshCounts();
  });
  $('scStart').addEventListener('click', startCamera);
  $('scAll').addEventListener('click', function () {
    if (!current) return;
    var btn = this, was = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = 'Marking…';
    API.request('instructor/session-checkin-all', { method: 'POST', body: { session_id: parseInt(current, 10), status: 'present' } })
      .then(function (r) {
        btn.disabled = false; btn.innerHTML = was;
        logRow(r.marked > 0 ? 'present' : 'already',
          'Whole roster', r.marked + ' marked, ' + r.skipped + ' already had a mark');
        refreshCounts();
      })
      .catch(function (e) {
        btn.disabled = false; btn.innerHTML = was;
        logRow('invalid', 'Group check-in failed', (e && e.body && e.body.error) || '');
      });
  });
  // Leaving the page with the camera live keeps the indicator on and drains the
  // battery, which is how somebody learns not to use it.
  window.addEventListener('pagehide', function () { if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); });

  if (!API) { $('scRoster').textContent = 'Could not start. Reload the page.'; return; }
  // Exposed so the mapping from an API result to a row can be driven in a real
  // browser without a printed card in front of a webcam. It is the only way to
  // test the thing that broke: an unknown result key falls back to `invalid`, so
  // a wrong map is silent and total.
  window.__render = function (r) { logRow((r && r.result) || 'invalid', r && r.student ? r.student.name : null); };
  loadSessions();
})();
</script>
</body>
</html>
