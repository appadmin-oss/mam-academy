/* ============================================================
   MAM Academy — Studio access gate + auto practice-log  ·  window.MAM_STUDIO_GATE
   ------------------------------------------------------------
   Student-only Studio tools (Rhythm Tap, Pitch Game, Speed & Pitch …) are
   FREE to discover but LOCKED until sign-in — the funnel that makes applying
   worthwhile. Each locked panel (marked `data-locked` in tools.php) shows a
   gate card: a short pitch, Sign-in + Apply CTAs, and a real, capped DEMO so a
   visitor feels the tool before being asked to join.

   It also provides the Studio's `window.MAM_STUDIO` (the portal ships its own
   richer one; the Studio page doesn't load that). logScore() persists personal
   bests per signed-in student AND — the "automatic practice log" — records a
   practice session to the portal tracker when a signed-in student completes a
   tool, throttled so it never spams the log.

   Reads the shared session via window.MAM_AUTH (config.js). Degrades safe:
   if auth is unknown it stays LOCKED (never leaks a premium tool), and a
   missing API never throws.
   ============================================================ */
(function () {
  'use strict';

  var GAMES = {
    rhythm: { label: 'Rhythm Tap',       minutes: 2 },
    pitch:  { label: 'Pitch Game',       minutes: 2 },
    speed:  { label: 'Speed & Pitch',    minutes: 3 }
  };
  var DEMO_CAP_MS = 45000;  // a demo run auto-closes back to the gate after this

  function authState() {
    if (window.MAM_AUTH && window.MAM_AUTH.state) return window.MAM_AUTH.state();
    return Promise.resolve({ authenticated: false, user: null, role: null, portal: '/portal/login' });
  }
  function cachedAuth() {
    return (window.MAM_AUTH && window.MAM_AUTH.cached && window.MAM_AUTH.cached()) || null;
  }

  /* ───────────────── MAM_STUDIO (Studio context) ───────────────── */
  // The portal defines a richer MAM_STUDIO; only define ours if absent so the
  // two never collide. Bests are namespaced per signed-in student.
  function ns() { var a = cachedAuth(); return (a && a.user && a.user.email ? a.user.email : 'guest').toLowerCase().replace(/[^a-z0-9@._-]/g, '') || 'guest'; }
  function lkey(name) { return 'mam.studio.' + name + '.' + ns(); }
  function lread(name, fb) { try { var v = JSON.parse(localStorage.getItem(lkey(name))); return v == null ? fb : v; } catch (e) { return fb; } }
  function lwrite(name, v) { try { localStorage.setItem(lkey(name), JSON.stringify(v)); } catch (e) {} }

  // Trinity-aligned band for a 0-100 score (mirrors the server's mam_studio_band).
  function band(score) { score = score | 0; return score >= 85 ? 'Distinction' : score >= 70 ? 'Merit' : score >= 60 ? 'Pass' : 'Keep going'; }

  // A device-local practice streak: consecutive days with at least one completed
  // run. Honest and dependency-free (no server call) — surfaced as encouragement,
  // never a claim about the student's official record. Bumped from logScore().
  function dayKey(d) { return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
  function bumpStreak() {
    try {
      var now = new Date(), today = dayKey(now);
      var s = lread('practiceStreak', { date: '', count: 0 }) || { date: '', count: 0 };
      if (s.date === today) return s.count || 1;                 // already counted today
      var yKey = dayKey(new Date(now.getTime() - 86400000));
      s.count = (s.date === yKey) ? (s.count || 0) + 1 : 1;      // continue or reset
      s.date = today; lwrite('practiceStreak', s);
      return s.count;
    } catch (e) { return 0; }
  }
  function currentStreak() { var s = lread('practiceStreak', { count: 0 }); return (s && s.count) || 0; }

  function logScore(game, score, meta) {
    game = String(game || '').slice(0, 24);
    score = Math.max(0, Math.min(100, Math.round(score) || 0));
    var all = lread('gameScores', {}); if (!all || typeof all !== 'object') all = {};
    var g = all[game] || { best: 0, last: 0, plays: 0, levels: {} };
    g.last = score; g.plays = (g.plays || 0) + 1; g.at = Date.now();
    var newBest = score > (g.best || 0); if (newBest) g.best = score;
    if (meta && meta.level) { g.levels = g.levels || {}; var lv = String(meta.level).slice(0, 40); if (score > (g.levels[lv] || 0)) g.levels[lv] = score; }
    all[game] = g; lwrite('gameScores', all);
    // Tell the gate (demo flow) + anyone listening that a run finished.
    try { document.dispatchEvent(new CustomEvent('mam:game-complete', { detail: { game: game, score: score, meta: meta || {} } })); } catch (e) {}
    // Auto practice-log (signed-in students only, throttled).
    autoPracticeLog(game, meta);
    // Encouragement: keep a device-local daily practice streak.
    bumpStreak();
    return { best: g.best, last: g.last, plays: g.plays, newBest: newBest };
  }
  function gameStats(game) { var all = lread('gameScores', {}); return (all && all[game]) || null; }

  // S1 telemetry + S3 AI practice feedback — fires on every completed run, on
  // any studio page (registered unconditionally). Telemetry always; AI feedback
  // only for the scored tools, signed-in, throttled, and entirely best-effort.
  var _aiFbLast = {};
  var _lastScore = {};   // tool → last run score (for the opt-in leaderboard post)
  document.addEventListener('mam:game-complete', function (e) {
    var d = (e && e.detail) || {};
    var game = String(d.game || '');
    var score = Math.round(d.score || 0);
    _lastScore[game] = score;
    if (window.MAM_TRACK) window.MAM_TRACK('score_logged', game, { score: score });
    if (game !== 'rhythm' && game !== 'pitchgame' && game !== 'pitch') return;
    var a = cachedAuth();
    if (!a || !a.authenticated || !window.MAM_API) return;
    var now = Date.now();
    if (now - (_aiFbLast[game] || 0) < 30000) return;   // throttle rapid replays
    _aiFbLast[game] = now;
    window.MAM_API.request('studio/feedback', { method: 'POST', body: { tool: game, score: score, metrics: (d.meta && d.meta.metrics) || {}, weak: (d.meta && d.meta.weak) || [] } })
      .then(function (r) {
        if (!r || !r.enabled || !r.feedback || !r.feedback.text) return;
        try { document.dispatchEvent(new CustomEvent('mam:studio-feedback', { detail: { game: game, text: r.feedback.text, provider: r.feedback.provider } })); } catch (e2) {}
        var box = document.querySelector('[data-studio-feedback="' + game + '"]') || document.querySelector('[data-studio-feedback]');
        if (box) { box.textContent = r.feedback.text; box.hidden = false; }
      })
      .catch(function () { /* AI is best-effort; never disrupt play */ });
  });

  // Record a practice session to the portal tracker when a signed-in student
  // finishes a tool. Throttled to once per game per 10 min so retries don't
  // flood the log; fires only for the 'student' role (others would 403).
  function autoPracticeLog(game, meta) {
    var a = cachedAuth();
    if (!a || !a.authenticated || a.role !== 'student' || !window.MAM_API) return;
    var info = GAMES[game]; if (!info) return;
    var tkey = 'mam.studio.lastlog.' + game + '.' + ns();
    var last = 0; try { last = parseInt(localStorage.getItem(tkey), 10) || 0; } catch (e) {}
    if (Date.now() - last < 10 * 60 * 1000) return;            // throttle
    try { localStorage.setItem(tkey, String(Date.now())); } catch (e) {}
    var minutes = Math.max(1, (meta && meta.minutes) || info.minutes || 1);
    window.MAM_API.request('student/practice-log', {
      method: 'POST',
      body: { minutes: minutes, focus: info.label + ' · Practice Studio' }
    }).catch(function () { /* best-effort; a failed auto-log must never disrupt play */ });
  }

  if (!window.MAM_STUDIO) {
    window.MAM_STUDIO = {
      setUser: function () {}, logScore: logScore, gameStats: gameStats,
      practiceStats: function () { return { streak: currentStreak(), weekMin: 0 }; }
    };
  } else if (!window.MAM_STUDIO.logScore) {
    window.MAM_STUDIO.logScore = logScore; window.MAM_STUDIO.gameStats = gameStats;
  }

  /* ───────────────── The gate ───────────────── */
  function injectCss() {
    if (document.getElementById('studio-gate-css')) return;
    var css = ''
      + '.tool-panel[data-locked]{position:relative}'
      + '.studio-gate{position:absolute;inset:0;z-index:5;display:flex;align-items:center;justify-content:center;padding:18px;'
      + 'background:linear-gradient(160deg,rgba(20,14,8,.82),rgba(20,14,8,.92));backdrop-filter:blur(7px);border-radius:inherit}'
      + '.studio-gate[hidden]{display:none}'
      + '.studio-gate-card{max-width:440px;width:100%;text-align:center;background:var(--bg-surface,#1A120B);border:1px solid var(--border-gold,rgba(224,138,26,.4));'
      + 'border-radius:18px;padding:30px 26px;box-shadow:0 30px 70px rgba(0,0,0,.5)}'
      + '.studio-gate-badge{display:inline-flex;align-items:center;gap:7px;font-size:.7rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;'
      + 'color:#1A120B;background:linear-gradient(135deg,var(--gold,#E08A1A),var(--gold-light,#FBBF24));padding:5px 12px;border-radius:100px;margin-bottom:14px}'
      + '.studio-gate-card h3{font-family:var(--font-serif,Georgia,serif);font-size:1.5rem;color:var(--text,#fff);margin:0 0 8px}'
      + '.studio-gate-card p{color:var(--text-soft,#cbb89e);font-size:.95rem;line-height:1.6;margin:0 0 18px}'
      + '.studio-gate-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}'
      + '.studio-gate-actions a,.studio-gate-actions button{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:11px;font-weight:800;'
      + 'font-size:.92rem;cursor:pointer;text-decoration:none;border:1px solid var(--border-strong,#7a6a52);background:var(--bg-raised,#2a1d10);color:var(--text,#fff);min-height:46px}'
      + '.studio-gate-actions .sg-primary{background:linear-gradient(135deg,var(--gold,#E08A1A),var(--gold-light,#FBBF24));color:#1A120B;border-color:transparent}'
      + '.studio-gate-actions .sg-demo{background:transparent;border-style:dashed}'
      + '.studio-gate-note{font-size:.8rem;color:var(--text-muted,#9a8264);margin:16px 0 0}'
      + '.studio-gate-demohint{position:absolute;left:50%;bottom:14px;transform:translateX(-50%);z-index:6;background:rgba(20,14,8,.9);color:var(--gold-bright,#FCC647);'
      + 'border:1px solid var(--border-gold,rgba(224,138,26,.5));border-radius:100px;padding:8px 16px;font-size:.82rem;font-weight:700;display:flex;align-items:center;gap:12px}'
      + '.studio-gate-demohint[hidden]{display:none}'
      + '.studio-gate-demohint button{background:linear-gradient(135deg,var(--gold,#E08A1A),var(--gold-light,#FBBF24));color:#1A120B;border:0;border-radius:100px;padding:6px 14px;font-weight:800;cursor:pointer}'
      + '.tools-tabs button[data-locked]{position:relative}'
      + '.tab-lock{font-size:.66rem;margin-left:5px;opacity:.85}'
      // Personal best + streak line on the scored-tool cards.
      + '.sg-personal{margin:10px 0 0;font-size:.86rem;color:var(--text-soft,#cbb89e);display:flex;flex-wrap:wrap;align-items:center;gap:8px}'
      + '.sg-personal strong{color:var(--gold-bright,#FCC647)}'
      + '.sg-band{font-size:.68rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--gold-bright,#FCC647);background:rgba(224,138,26,.14);border:1px solid var(--border-gold,rgba(224,138,26,.4));border-radius:100px;padding:2px 8px}'
      + '.sg-streak{color:var(--gold-bright,#FCC647);font-weight:700}.sg-streak i{margin-right:3px}.sg-dot{opacity:.5}'
      // Free-tool "unlock the full Studio" bridge.
      + '.sg-bridge{margin-top:18px;display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;justify-content:space-between;'
      + 'padding:13px 16px;border-radius:12px;border:1px solid var(--border-gold,rgba(224,138,26,.4));background:linear-gradient(135deg,rgba(224,138,26,.1),rgba(224,138,26,.03))}'
      + '.sg-bridge[hidden]{display:none}'
      + '.sg-bridge-txt{font-size:.9rem;color:var(--text-soft,#cbb89e)}.sg-bridge-txt i{color:var(--gold-bright,#FCC647);margin-right:6px}.sg-bridge-txt strong{color:var(--text,#fff)}'
      + '.sg-bridge-act{display:flex;align-items:center;gap:8px;flex-wrap:wrap}'
      + '.sg-bridge-go{padding:9px 16px;border-radius:9px;font-weight:800;font-size:.84rem;text-decoration:none;background:linear-gradient(135deg,var(--gold,#E08A1A),var(--gold-light,#FBBF24));color:#1A120B}'
      + '.sg-bridge-signin{padding:9px 12px;border-radius:9px;font-weight:700;font-size:.84rem;text-decoration:none;color:var(--text,#fff);border:1px solid var(--border-strong,#7a6a52)}'
      + '.sg-bridge-x{background:none;border:0;color:var(--text-muted,#9a8264);font-size:1.3rem;line-height:1;cursor:pointer;padding:0 4px}'
      + '.sg-bridge-x:hover{color:var(--text,#fff)}';
    var st = document.createElement('style'); st.id = 'studio-gate-css'; st.textContent = css;
    document.head.appendChild(st);
  }

  var PITCH = {
    rhythm: 'Read the rhythm, then tap or <strong>play it back</strong> — clap, drum, sing or any instrument. Trinity-graded scoring, endless auto-levelling and rewards.',
    pitch:  'Sing or play each note as it reaches the line. Real-time intonation, timing and consistency scoring — your pocket vocal coach.',
    speed:  'Slow any track down without changing its pitch, or transpose it to your key. The practice superpower behind every fast passage.'
  };

  var demoActive = {};   // tool → bool
  var demoTimers = {};

  function build(panel, tool) {
    var gate = document.createElement('div');
    gate.className = 'studio-gate';
    gate.innerHTML =
      '<div class="studio-gate-card">'
      + '<span class="studio-gate-badge"><i class="fa-solid fa-lock"></i> Students only</span>'
      + '<h3>Unlock ' + (GAMES[tool] ? GAMES[tool].label : 'this tool') + '</h3>'
      + '<p>' + (PITCH[tool] || 'A premium MAM Academy practice tool, free for enrolled students.') + '</p>'
      + '<div class="studio-gate-actions">'
      +   '<a class="sg-primary" href="/apply"><i class="fa-solid fa-music"></i> Apply for access</a>'
      +   '<a href="/portal/login?next=' + encodeURIComponent('/studio#' + tool) + '"><i class="fa-solid fa-right-to-bracket"></i> Sign in</a>'
      +   '<button type="button" data-demo><i class="fa-solid fa-play"></i> Try the demo</button>'
      + '</div>'
      + '<p class="studio-gate-note">Already applied? Your portal access is emailed once you\'re enrolled.</p>'
      + '</div>';
    panel.appendChild(gate);

    var hint = document.createElement('div');
    hint.className = 'studio-gate-demohint'; hint.hidden = true;
    hint.innerHTML = '<span><i class="fa-solid fa-wand-magic-sparkles"></i> Demo mode — a taste of ' + (GAMES[tool] ? GAMES[tool].label : 'the tool') + '</span><button type="button" data-demo-join>Unlock it all</button>';
    panel.appendChild(hint);

    gate.querySelector('[data-demo]').addEventListener('click', function () { startDemo(tool, gate, hint); });
    hint.querySelector('[data-demo-join]').addEventListener('click', function () { endDemo(tool, gate, hint); });
    var applyLink = gate.querySelector('.sg-primary');
    if (applyLink) applyLink.addEventListener('click', function () { if (window.MAM_TRACK) window.MAM_TRACK('gate_apply_click', tool); });
    return { gate: gate, hint: hint };
  }

  function startDemo(tool, gate, hint) {
    demoActive[tool] = true;
    gate.hidden = true;
    hint.hidden = false;
    clearTimeout(demoTimers[tool]);
    if (window.MAM_TRACK) window.MAM_TRACK('demo_start', tool); // S1 telemetry
    // Auto-close the demo after one completed run or the time cap.
    demoTimers[tool] = setTimeout(function () { endDemo(tool, gate, hint); }, DEMO_CAP_MS);
  }
  function endDemo(tool, gate, hint) {
    if (demoActive[tool] && window.MAM_TRACK) window.MAM_TRACK('demo_complete', tool); // S1 telemetry
    demoActive[tool] = false;
    clearTimeout(demoTimers[tool]);
    hint.hidden = true;
    // Re-lock unless they signed in during the demo.
    var a = cachedAuth();
    if (!(a && a.authenticated)) {
      gate.querySelector('h3').textContent = 'Enjoyed it? Unlock the full tool';
      gate.hidden = false;
    }
  }

  /* ── "Unlock the full Studio" bridge on the FREE tools (value-first, S2) ── */
  // A tasteful, dismissible prompt on the always-free tools that connects a
  // visitor's engagement to applying — shown only to signed-out visitors, and
  // only until dismissed for the session. Never blocks a tool.
  var FREE_TOOLS = ['metronome', 'tuner', 'pomodoro', 'ear', 'seq'];
  function bridgeDismissed() { try { return sessionStorage.getItem('mam.studio.bridge.dismiss') === '1'; } catch (e) { return false; } }
  function buildBridges() {
    return FREE_TOOLS.map(function (tool) {
      var panel = document.querySelector('.tool-panel[data-tool="' + tool + '"]');
      if (!panel || panel.querySelector('[data-apply-bridge]')) return null;
      var b = document.createElement('div');
      b.setAttribute('data-apply-bridge', ''); b.className = 'sg-bridge'; b.hidden = true;
      b.innerHTML = '<span class="sg-bridge-txt"><i class="fa-solid fa-unlock-keyhole" aria-hidden="true"></i> Enjoying the free tools? Students also unlock <strong>Rhythm Tap</strong>, <strong>Pitch Game</strong> &amp; <strong>Speed &amp; Pitch</strong>.</span>'
        + '<span class="sg-bridge-act">'
        + '<a class="sg-bridge-go" href="/apply">Apply for access</a>'
        + '<a class="sg-bridge-signin" href="/portal/login?next=' + encodeURIComponent('/studio') + '">Sign in</a>'
        + '<button type="button" class="sg-bridge-x" aria-label="Dismiss">&times;</button>'
        + '</span>';
      panel.appendChild(b);
      b.querySelector('.sg-bridge-x').addEventListener('click', function () {
        try { sessionStorage.setItem('mam.studio.bridge.dismiss', '1'); } catch (e) {}
        b.hidden = true;
      });
      b.querySelector('.sg-bridge-go').addEventListener('click', function () { if (window.MAM_TRACK) window.MAM_TRACK('gate_apply_click', tool); });
      return b;
    }).filter(Boolean);
  }

  function init() {
    var panels = Array.prototype.slice.call(document.querySelectorAll('.tool-panel[data-locked]'));
    if (!panels.length) return;
    injectCss();
    var gates = panels.map(function (p) { return build(p, p.getAttribute('data-tool')); });
    var bridges = buildBridges();

    var tabsHint = document.getElementById('toolsTabsHint');
    function applyAuth(st) {
      var signedIn = !!(st && st.authenticated);
      gates.forEach(function (g, i) {
        var tool = panels[i].getAttribute('data-tool');
        if (signedIn) { g.gate.hidden = true; g.hint.hidden = true; demoActive[tool] = false; }
        else if (!demoActive[tool]) { g.gate.hidden = false; }
      });
      // The free-tool apply bridge: signed-out visitors only, until dismissed.
      bridges.forEach(function (b) { b.hidden = signedIn || bridgeDismissed(); });
      if (tabsHint) {
        if (signedIn) { tabsHint.hidden = true; }
        else {
          var span = tabsHint.querySelector('span');
          if (span) span.innerHTML = '3 student tools (Rhythm Tap, Pitch Game, Speed &amp; Pitch) unlock when you '
            + '<a href="/portal/login?next=' + encodeURIComponent('/studio') + '">sign in</a> — '
            + '<a href="/apply">apply for access</a>.';
          tabsHint.hidden = false;
        }
      }
    }
    // Lock immediately (safe default), then reconcile with the real session.
    applyAuth(cachedAuth());
    authState().then(applyAuth).catch(function () {});

    // End a demo as soon as the demoed tool reports a completed run.
    document.addEventListener('mam:game-complete', function (e) {
      var tool = e.detail && e.detail.game;
      gates.forEach(function (g, i) {
        if (panels[i].getAttribute('data-tool') === tool && demoActive[tool]) endDemo(tool, g.gate, g.hint);
      });
    });

    setupLeaderboards();
  }

  /* ───────────────── Opt-in cohort leaderboard (S2) ───────────────── */
  function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function setupLeaderboards() {
    ['rhythm', 'pitchgame'].forEach(function (tool) {
      var panel = document.querySelector('.tool-panel[data-tool="' + tool + '"]');
      if (!panel || panel.querySelector('[data-leaderboard]')) return;
      var card = document.createElement('div');
      card.setAttribute('data-leaderboard', tool);
      card.style.cssText = 'margin-top:18px;padding:14px 16px;border:1px solid var(--border,rgba(255,255,255,.16));border-radius:12px';
      card.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">'
        + '<strong><i class="fa-solid fa-ranking-star" aria-hidden="true"></i> Leaderboard</strong>'
        + '<span style="display:flex;gap:8px">'
        + '<button type="button" data-board-share style="padding:7px 12px;border-radius:9px;border:1px solid var(--border,rgba(255,255,255,.2));background:transparent;color:inherit;cursor:pointer;font-weight:700;font-size:.8rem"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i> Share</button>'
        + '<button type="button" data-board-post style="padding:7px 12px;border-radius:9px;border:1px solid var(--border-gold,rgba(224,138,26,.5));background:transparent;color:inherit;cursor:pointer;font-weight:700;font-size:.8rem">Post my best</button>'
        + '</span></div>'
        + '<p data-board-personal class="sg-personal" hidden></p>'
        + '<ol data-board-list style="margin:10px 0 0;padding-left:22px;line-height:1.8"></ol>'
        + '<p data-board-me class="studio-gate-note" style="margin:8px 0 0" hidden></p>';
      panel.appendChild(card);
      renderPersonal(tool, card);
      // Keep the personal best + streak fresh as the student plays.
      document.addEventListener('mam:game-complete', function (e) {
        if (e && e.detail && e.detail.game === tool) renderPersonal(tool, card);
      });
      card.querySelector('[data-board-share]').addEventListener('click', function () {
        if (_lastScore[tool] == null) { setBoardMsg(card, 'Play a round first, then share your result.'); return; }
        var a = cachedAuth();
        var first = (a && a.user && a.user.name) ? String(a.user.name).split(' ')[0] : '';
        var url = '/api/studio/card?tool=' + encodeURIComponent(tool) + '&score=' + (_lastScore[tool] | 0) + (first ? '&name=' + encodeURIComponent(first) : '');
        window.open(url, '_blank', 'noopener');
      });
      card.querySelector('[data-board-post]').addEventListener('click', function () {
        var a = cachedAuth();
        if (!a || !a.authenticated || a.role !== 'student') { setBoardMsg(card, 'Sign in as a student to post your score.'); return; }
        if (_lastScore[tool] == null) { setBoardMsg(card, 'Play a round first, then post your best.'); return; }
        if (!window.MAM_API) return;
        window.MAM_API.request('studio/score', { method: 'POST', body: { tool: tool, score: _lastScore[tool] } })
          .then(function () { loadBoard(tool, card); })
          .catch(function () { setBoardMsg(card, 'Could not post right now.'); });
      });
      loadBoard(tool, card);
    });
  }
  // Inline personal best + Trinity band + play count + device-local streak, from
  // the student's own gameStats — surfaced immediately, no server round-trip.
  function renderPersonal(tool, card) {
    var el = card.querySelector('[data-board-personal]'); if (!el) return;
    var st = gameStats(tool);
    if (!st || !(st.plays > 0)) { el.hidden = true; el.innerHTML = ''; return; }
    var parts = ['Your best <strong>' + (st.best | 0) + '</strong> <span class="sg-band">' + band(st.best) + '</span>',
                 (st.plays | 0) + ' play' + (st.plays === 1 ? '' : 's')];
    var streak = currentStreak();
    if (streak > 0) parts.push('<span class="sg-streak"><i class="fa-solid fa-fire" aria-hidden="true"></i> ' + streak + '-day streak</span>');
    el.innerHTML = parts.join(' <span class="sg-dot">·</span> ');
    el.hidden = false;
  }
  function setBoardMsg(card, msg) { var p = card.querySelector('[data-board-me]'); if (p) { p.textContent = msg; p.hidden = false; } }
  function loadBoard(tool, card) {
    if (!window.MAM_API) return;
    window.MAM_API.request('studio/leaderboard?tool=' + encodeURIComponent(tool), { method: 'GET' })
      .then(function (r) {
        var list = card.querySelector('[data-board-list]'); if (!list) return;
        var items = (r && r.leaderboard) || [];
        list.innerHTML = items.length
          ? items.map(function (x) { return '<li>' + escHtml(x.name) + ' — <strong>' + (x.best_score | 0) + '</strong></li>'; }).join('')
          : '<li style="opacity:.6">No scores yet — be the first!</li>';
        if (r && r.me) setBoardMsg(card, 'Your best: ' + (r.me.best_score | 0) + ' · rank #' + (r.me.rank | 0));
      })
      .catch(function () { /* guests get a 401 — leave the default text */ });
  }

  window.MAM_STUDIO_GATE = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
