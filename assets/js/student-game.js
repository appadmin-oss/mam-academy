/*! MAM Academy — Practice Arcade  ·  window.MAM_GAME
 * ---------------------------------------------------------------------------
 * "Note Rush" — an enterprise-grade sight-reading game for the student
 * dashboard. A note is drawn on a real musical staff (treble / bass / grand,
 * with ledger lines); name it before the clock (Timed) or before you run out of
 * lives (Survival). Correct answers build a combo + speed bonus; the pitch
 * plays back so eye and ear train together.
 *
 * SYSTEMS
 *  • Progress profile (localStorage): XP → player level, lifetime games/notes/
 *    best-score/best-combo, per-clef accuracy, per-mode best, score history,
 *    total play time. Backward-compatible with the v1 save shape.
 *  • Daily Challenge: a deterministic, seeded round (same for everyone each day)
 *    with its own best score + a consecutive-day streak.
 *  • Achievements: unlockable badges; newly-earned ones surface after a round.
 *  • Settings: round length, sound on/off, Survival mode — all persisted.
 *
 * Dependency-free: Canvas + DOM + Web Audio (audio guarded — a blocked/suspended
 * context never breaks the game).
 */
(function () {
  'use strict';

  var SAVE = 'mam.game.noterush.v1';        // kept stable; shape is extended + migrated
  var SET = 'mam.game.settings.v1';
  var LETTERS = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];
  var LETTER_STEP = { C: 0, D: 1, E: 2, F: 3, G: 4, A: 5, B: 6 };

  function dia(letter, octave) { return octave * 7 + LETTER_STEP[letter]; }
  function letterOf(d) { return LETTERS[((d % 7) + 7) % 7]; }
  function octaveOf(d) { return Math.floor(d / 7); }
  var SEMI = { C: 0, D: 2, E: 4, F: 5, G: 7, A: 9, B: 11 };
  function midiOf(d) { return (octaveOf(d) + 1) * 12 + SEMI[letterOf(d)]; }
  function freqOf(d) { return 440 * Math.pow(2, (midiOf(d) - 69) / 12); }

  var CLEFS = {
    treble: { glyph: '𝄞', bottomLine: dia('E', 4) },
    bass:   { glyph: '𝄢', bottomLine: dia('G', 2) }
  };
  var MODES = [
    { id: 'treble', name: 'Treble Clef', sub: 'On-staff notes · start here', clef: 'treble', range: [dia('E', 4), dia('F', 5)] },
    { id: 'bass', name: 'Bass Clef', sub: 'On-staff notes · left hand', clef: 'bass', range: [dia('G', 2), dia('A', 3)] },
    { id: 'treble-ledger', name: 'Treble + Ledger', sub: 'Middle C up to high A', clef: 'treble', range: [dia('C', 4), dia('A', 5)] },
    { id: 'grand', name: 'Grand Staff', sub: 'Both clefs + ledgers · pro', clef: 'both', range: [dia('C', 2), dia('C', 6)] }
  ];
  function modeById(id) { return MODES.filter(function (m) { return m.id === id; })[0] || MODES[0]; }

  /* ── seeded RNG (for the Daily Challenge) ── */
  function seedFromString(s) { var h = 2166136261; for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; }
  function mulberry32(a) { return function () { a |= 0; a = a + 0x6D2B79F5 | 0; var t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
  function dateKey() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
  function dayNumber(d) { return Math.floor((d || Date.now()) / 86400000); }

  /* ── settings ── */
  function loadSettings() {
    var s = { roundSec: 60, sound: true, survival: false };
    try { var d = JSON.parse(localStorage.getItem(SET) || '{}');
      if ([30, 60, 90, 120].indexOf(d.roundSec) >= 0) s.roundSec = d.roundSec;
      if (typeof d.sound === 'boolean') s.sound = d.sound;
      if (typeof d.survival === 'boolean') s.survival = d.survival;
    } catch (e) {}
    return s;
  }
  function saveSettings(s) { try { localStorage.setItem(SET, JSON.stringify(s)); } catch (e) {} }

  /* ── persistent profile (extended, migrating from v1) ── */
  function blankProg() {
    return {
      xp: 0, games: 0, notes: 0, bestScore: 0, bestCombo: 0, playSec: 0,
      clef: { treble: { right: 0, total: 0 }, bass: { right: 0, total: 0 } },
      modeBest: { treble: 0, bass: 0, 'treble-ledger': 0, grand: 0 },
      history: [],                                   // recent round scores (newest last, max 14)
      daily: { streak: 0, last: '', lastDay: 0, best: 0 },
      ach: {}
    };
  }
  function loadProg() {
    var p = blankProg();
    try {
      var d = JSON.parse(localStorage.getItem(SAVE) || '{}');
      ['xp', 'games', 'notes', 'bestScore', 'bestCombo', 'playSec'].forEach(function (k) { if (typeof d[k] === 'number' && d[k] >= 0) p[k] = d[k] | 0; });
      if (d.clef) ['treble', 'bass'].forEach(function (c) { if (d.clef[c]) { p.clef[c].right = d.clef[c].right | 0; p.clef[c].total = d.clef[c].total | 0; } });
      if (d.modeBest) Object.keys(p.modeBest).forEach(function (k) { if (typeof d.modeBest[k] === 'number') p.modeBest[k] = d.modeBest[k] | 0; });
      if (Array.isArray(d.history)) p.history = d.history.filter(function (n) { return typeof n === 'number'; }).slice(-14);
      if (d.daily) { p.daily.streak = d.daily.streak | 0; p.daily.last = String(d.daily.last || ''); p.daily.lastDay = d.daily.lastDay | 0; p.daily.best = d.daily.best | 0; }
      if (d.ach && typeof d.ach === 'object') p.ach = d.ach;
    } catch (e) {}
    return p;
  }
  function saveProg(p) { try { localStorage.setItem(SAVE, JSON.stringify(p)); } catch (e) {} }
  function levelFromXp(xp) { return Math.max(1, Math.floor(Math.sqrt(xp / 50)) + 1); }
  function xpForLevel(L) { return 50 * (L - 1) * (L - 1); }
  function accuracy(p) { var r = p.clef.treble.right + p.clef.bass.right, t = p.clef.treble.total + p.clef.bass.total; return t ? Math.round(r / t * 100) : 0; }

  /* ── achievements ── */
  var ACH = [
    { id: 'first', name: 'First Steps', desc: 'Play your first round', icon: 'flag-checkered', test: function (p) { return p.games >= 1; } },
    { id: 'games50', name: 'Regular', desc: 'Play 50 rounds', icon: 'calendar-check', test: function (p) { return p.games >= 50; } },
    { id: 'notes1k', name: 'Sight Reader', desc: 'Read 1,000 notes', icon: 'glasses', test: function (p) { return p.notes >= 1000; } },
    { id: 'combo20', name: 'On Fire', desc: 'Reach a 20× combo', icon: 'fire', test: function (p) { return p.bestCombo >= 20; } },
    { id: 'score500', name: 'High Scorer', desc: 'Score 500 in a round', icon: 'trophy', test: function (p) { return p.bestScore >= 500; } },
    { id: 'acc90', name: 'Sharpshooter', desc: '90%+ accuracy (200+ notes)', icon: 'bullseye', test: function (p) { var t = p.clef.treble.total + p.clef.bass.total, r = p.clef.treble.right + p.clef.bass.right; return t >= 200 && r / t >= 0.9; } },
    { id: 'treble', name: 'Treble Master', desc: '95%+ treble (100+ notes)', icon: 'music', test: function (p) { return p.clef.treble.total >= 100 && p.clef.treble.right / p.clef.treble.total >= 0.95; } },
    { id: 'bass', name: 'Bass Master', desc: '95%+ bass (100+ notes)', icon: 'guitar', test: function (p) { return p.clef.bass.total >= 100 && p.clef.bass.right / p.clef.bass.total >= 0.95; } },
    { id: 'daily7', name: 'Daily Devotee', desc: '7-day daily streak', icon: 'calendar-days', test: function (p) { return p.daily.streak >= 7; } },
    { id: 'level10', name: 'Virtuoso', desc: 'Reach level 10', icon: 'crown', test: function (p) { return levelFromXp(p.xp) >= 10; } }
  ];
  // Returns the ids that became true now but weren't recorded as earned yet.
  function applyAchievements(p) {
    var fresh = [];
    ACH.forEach(function (a) { if (a.test(p) && !p.ach[a.id]) { p.ach[a.id] = true; fresh.push(a); } });
    return fresh;
  }

  /* ── audio (guarded; respects the sound setting) ── */
  var actx = null, soundOn = true;
  function actxGet() {
    try {
      if (window.MAM && typeof window.MAM.audio === 'function') return (actx = window.MAM.audio());
      if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)();
      if (actx.state === 'suspended') actx.resume();
      return actx;
    } catch (e) { return null; }
  }
  function playNote(d, good) {
    if (!soundOn) return;
    var ac = actxGet(); if (!ac) return;
    try {
      var t = ac.currentTime, o = ac.createOscillator(), g = ac.createGain();
      o.type = good === false ? 'sawtooth' : 'triangle';
      o.frequency.value = good === false ? 130 : freqOf(d);
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(good === false ? 0.12 : 0.18, t + 0.012);
      g.gain.exponentialRampToValueAtTime(0.0001, t + (good === false ? 0.18 : 0.5));
      o.connect(g).connect(ac.destination); o.start(t); o.stop(t + 0.55);
    } catch (e) {}
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmtTime(sec) { sec = sec | 0; var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60); return h ? (h + 'h ' + m + 'm') : (m + 'm'); }

  /* ── the game ── */
  function mount(host) {
    if (!host) return;
    var prog = loadProg(), settings = loadSettings();
    var ticker = null, loopAlive = false;
    soundOn = settings.sound;

    function stop() { loopAlive = false; if (ticker) clearInterval(ticker); ticker = null; }

    /* menu pieces */
    function sparkline(hist) {
      if (!hist.length) return '';
      var max = Math.max.apply(null, hist) || 1;
      return '<div class="ng-spark" aria-hidden="true">' + hist.map(function (v) {
        return '<i style="height:' + Math.max(8, Math.round(v / max * 100)) + '%"></i>';
      }).join('') + '</div>';
    }
    function progressPanel() {
      var lvl = levelFromXp(prog.xp), base = xpForLevel(lvl), next = xpForLevel(lvl + 1);
      var pct = Math.max(0, Math.min(100, Math.round((prog.xp - base) / Math.max(1, next - base) * 100)));
      function accRow(label, c) { var a = c.total ? Math.round(c.right / c.total * 100) : 0; return '<div class="ng-acc-row"><span>' + label + '</span><div class="ng-acc-bar"><i style="width:' + a + '%"></i></div><b>' + a + '%</b></div>'; }
      return '<div class="ng-progress">' +
        '<div class="ng-level"><div class="ng-level-badge">' + lvl + '</div><div style="flex:1"><div class="ng-level-label">Level ' + lvl + '</div>' +
          '<div class="ng-xpbar"><i style="width:' + pct + '%"></i></div>' +
          '<div class="ng-xp-sub">' + prog.xp + ' XP · ' + (next - prog.xp) + ' to level ' + (lvl + 1) + '</div></div></div>' +
        '<div class="ng-stats">' +
          '<div class="ng-stat"><b>' + prog.games + '</b><span>Rounds</span></div>' +
          '<div class="ng-stat"><b>' + prog.notes + '</b><span>Notes</span></div>' +
          '<div class="ng-stat"><b>' + prog.bestScore + '</b><span>Best</span></div>' +
          '<div class="ng-stat"><b>' + prog.bestCombo + '×</b><span>Combo</span></div>' +
          '<div class="ng-stat"><b>' + accuracy(prog) + '%</b><span>Accuracy</span></div>' +
          '<div class="ng-stat"><b>' + fmtTime(prog.playSec) + '</b><span>Played</span></div>' +
        '</div>' +
        '<div class="ng-accs">' + accRow('Treble', prog.clef.treble) + accRow('Bass', prog.clef.bass) + '</div>' +
        (prog.history.length ? '<div class="ng-spark-wrap"><span>Recent scores</span>' + sparkline(prog.history) + '</div>' : '') +
      '</div>';
    }
    function dailyCard() {
      var playedToday = prog.daily.last === dateKey();
      return '<button class="ng-daily" type="button" data-daily="1">' +
        '<div class="ng-daily-head"><span class="ng-daily-eyebrow"><i class="fa-solid fa-calendar-day"></i> Daily Challenge</span>' +
          '<span class="ng-daily-streak"><i class="fa-solid fa-fire"></i> ' + prog.daily.streak + '-day streak</span></div>' +
        '<div class="ng-daily-body"><div><strong>Today\'s set</strong><small>Same notes for everyone · 60s · grand staff</small></div>' +
          '<span class="ng-daily-cta">' + (playedToday ? 'Best ' + prog.daily.best + ' · replay' : 'Play') + ' <i class="fa-solid fa-arrow-right"></i></span></div>' +
      '</button>';
    }
    function achPanel() {
      return '<div class="ng-section-title">Achievements <span>' + Object.keys(prog.ach).length + '/' + ACH.length + '</span></div>' +
        '<div class="ng-ach-grid">' + ACH.map(function (a) {
          var got = !!prog.ach[a.id];
          return '<div class="ng-ach' + (got ? '' : ' locked') + '" title="' + esc(a.desc) + '"><div class="ng-ach-ico"><i class="fa-solid fa-' + a.icon + '"></i></div>' +
            '<div class="ng-ach-name">' + esc(a.name) + '</div><div class="ng-ach-desc">' + esc(a.desc) + '</div></div>';
        }).join('') + '</div>';
    }
    function settingsPanel() {
      function sw(key, label) { return '<label class="ng-set-row"><span>' + label + '</span><input type="checkbox" data-set="' + key + '"' + (settings[key] ? ' checked' : '') + '></label>'; }
      var lens = [30, 60, 90, 120].map(function (n) { return '<button type="button" class="ng-len' + (settings.roundSec === n ? ' active' : '') + '" data-len="' + n + '">' + n + 's</button>'; }).join('');
      return '<div class="ng-section-title">Settings</div><div class="ng-settings">' +
        '<div class="ng-set-row"><span>Round length</span><div class="ng-lens">' + lens + '</div></div>' +
        sw('survival', 'Survival mode (3 lives, no clock)') +
        sw('sound', 'Sound') +
      '</div>';
    }

    function renderMenu() {
      stop();
      host.innerHTML =
        '<div class="ng-wrap">' +
          '<div class="ng-hero"><div class="ng-hero-eyebrow"><i class="fa-solid fa-gamepad"></i> Practice Arcade</div>' +
            '<h2 class="ng-hero-title">Note Rush</h2>' +
            '<p class="ng-hero-sub">Name the note before time (or lives) run out. Build combos, climb the levels, unlock achievements — the pitch plays back so your ear learns too.</p></div>' +
          progressPanel() +
          dailyCard() +
          '<div class="ng-section-title">Choose a level</div>' +
          '<div class="ng-modes">' + MODES.map(function (m) {
            return '<button class="ng-mode" type="button" data-mode="' + m.id + '"><span class="ng-mode-name">' + esc(m.name) + '</span><span class="ng-mode-sub">' + esc(m.sub) + '</span>' +
              (prog.modeBest[m.id] ? '<span class="ng-mode-best">Best ' + prog.modeBest[m.id] + '</span>' : '') +
              '<span class="ng-mode-go"><i class="fa-solid fa-play"></i> Play</span></button>';
          }).join('') + '</div>' +
          settingsPanel() +
          achPanel() +
        '</div>';

      Array.prototype.forEach.call(host.querySelectorAll('.ng-mode'), function (b) {
        b.addEventListener('click', function () { actxGet(); startGame({ modeId: b.getAttribute('data-mode') }); });
      });
      var dc = host.querySelector('[data-daily]'); if (dc) dc.addEventListener('click', function () { actxGet(); startGame({ modeId: 'grand', daily: true }); });
      Array.prototype.forEach.call(host.querySelectorAll('[data-set]'), function (cb) {
        cb.addEventListener('change', function () { settings[cb.getAttribute('data-set')] = cb.checked; if (cb.getAttribute('data-set') === 'sound') soundOn = cb.checked; saveSettings(settings); });
      });
      Array.prototype.forEach.call(host.querySelectorAll('[data-len]'), function (b) {
        b.addEventListener('click', function () { settings.roundSec = parseInt(b.getAttribute('data-len'), 10); saveSettings(settings); Array.prototype.forEach.call(host.querySelectorAll('[data-len]'), function (x) { x.classList.toggle('active', x === b); }); });
      });
    }

    /* gameplay */
    function startGame(opts) {
      opts = opts || {};
      var mode = modeById(opts.modeId);
      var daily = !!opts.daily;
      var survival = daily ? false : settings.survival;
      var roundSec = daily ? 60 : settings.roundSec;
      var rng = daily ? mulberry32(seedFromString('mam-noterush-' + dateKey())) : Math.random;
      var startedAt = Date.now();
      var state = {
        score: 0, combo: 0, bestCombo: 0, right: 0, total: 0, lives: 3, daily: daily, survival: survival,
        byClef: { treble: { right: 0, total: 0 }, bass: { right: 0, total: 0 } },
        endAt: Date.now() + roundSec * 1000, note: null, clef: null, askAt: 0, flash: null, over: false
      };

      var hudRight = survival
        ? '<div class="ng-hud-item ng-hud-lives"><span>Lives</span><b id="ngLives">♥♥♥</b></div>'
        : '<div class="ng-hud-item ng-hud-time"><span>Time</span><b id="ngTime">' + roundSec + '</b></div>';
      host.innerHTML =
        '<div class="ng-wrap ng-playing">' +
          (daily ? '<div class="ng-daily-banner"><i class="fa-solid fa-calendar-day"></i> Daily Challenge · ' + esc(dateKey()) + '</div>' : '') +
          '<div class="ng-hud">' +
            '<div class="ng-hud-item"><span>Score</span><b id="ngScore">0</b></div>' +
            '<div class="ng-hud-item"><span>Combo</span><b id="ngCombo">0×</b></div>' +
            hudRight +
            '<button class="ng-quit" id="ngQuit" type="button" title="End round"><i class="fa-solid fa-xmark"></i></button>' +
          '</div>' +
          (survival ? '' : '<div class="ng-timebar"><i id="ngTimeBar"></i></div>') +
          '<div class="ng-stage"><canvas id="ngCanvas" aria-label="A note on the staff — name it below"></canvas><div class="ng-feedback" id="ngFeedback"></div></div>' +
          '<div class="ng-answers" id="ngAnswers" role="group" aria-label="Name the note">' +
            LETTERS.map(function (l) { return '<button class="ng-key" type="button" data-note="' + l + '">' + l + '</button>'; }).join('') +
          '</div>' +
          '<p class="ng-tip">Tap a letter or press its key (C–B). ' + (survival ? '3 lives.' : roundSec + 's on the clock.') + '</p>' +
        '</div>';

      var cvs = host.querySelector('#ngCanvas'), ctx = cvs.getContext('2d');
      var elScore = host.querySelector('#ngScore'), elCombo = host.querySelector('#ngCombo'),
          elTime = host.querySelector('#ngTime'), elBar = host.querySelector('#ngTimeBar'),
          elLives = host.querySelector('#ngLives'), elFb = host.querySelector('#ngFeedback');

      function sizeCanvas() {
        var w = cvs.parentNode.clientWidth || 600, h = Math.max(220, Math.min(300, Math.round(w * 0.42)));
        var dpr = window.devicePixelRatio || 1;
        cvs.style.width = w + 'px'; cvs.style.height = h + 'px';
        cvs.width = Math.round(w * dpr); cvs.height = Math.round(h * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        return { w: w, h: h };
      }
      function nextNote() {
        var clef = mode.clef === 'both' ? (rng() < 0.5 ? 'treble' : 'bass') : mode.clef;
        var lo = mode.range[0], hi = mode.range[1], d;
        if (mode.clef === 'both') { if (clef === 'treble') { lo = dia('C', 4); hi = dia('C', 6); } else { lo = dia('C', 2); hi = dia('C', 4); } }
        var tries = 0;
        do { d = lo + Math.floor(rng() * (hi - lo + 1)); tries++; } while (state.note != null && d === state.note && tries < 8);
        state.note = d; state.clef = clef; state.askAt = Date.now(); state.flash = null;
        draw();
      }
      function draw() {
        var dim = sizeCanvas(), w = dim.w, h = dim.h;
        ctx.clearRect(0, 0, w, h);
        var clef = CLEFS[state.clef], gap = 16, midY = h / 2, bottomLineY = midY + 2 * gap;
        var cx = Math.round(w * 0.62);
        ctx.strokeStyle = 'rgba(245,238,227,0.55)'; ctx.lineWidth = 1.4;
        for (var i = 0; i < 5; i++) { var y = bottomLineY - i * gap; ctx.beginPath(); ctx.moveTo(40, y + 0.5); ctx.lineTo(w - 24, y + 0.5); ctx.stroke(); }
        ctx.fillStyle = '#FBBF24'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        ctx.font = (gap * 5.4) + 'px Georgia, "Times New Roman", serif';
        ctx.fillText(clef.glyph, 48, state.clef === 'treble' ? midY + 4 : midY - gap * 0.6);
        var steps = state.note - clef.bottomLine;
        var ny = bottomLineY - steps * (gap / 2);
        ctx.strokeStyle = 'rgba(245,238,227,0.7)'; ctx.lineWidth = 1.4;
        if (steps < 0) for (var l = -2; l >= steps; l -= 2) { var ly = bottomLineY - l * (gap / 2); ctx.beginPath(); ctx.moveTo(cx - 16, ly + 0.5); ctx.lineTo(cx + 16, ly + 0.5); ctx.stroke(); }
        if (steps > 8) for (var u = 10; u <= steps; u += 2) { var uy = bottomLineY - u * (gap / 2); ctx.beginPath(); ctx.moveTo(cx - 16, uy + 0.5); ctx.lineTo(cx + 16, uy + 0.5); ctx.stroke(); }
        var col = state.flash === 'good' ? '#34D399' : state.flash === 'bad' ? '#FB7185' : '#F5EEE3';
        ctx.save(); ctx.translate(cx, ny); ctx.rotate(-0.32);
        ctx.fillStyle = col; ctx.beginPath(); ctx.ellipse(0, 0, gap * 0.72, gap * 0.52, 0, 0, Math.PI * 2); ctx.fill(); ctx.restore();
        ctx.strokeStyle = col; ctx.lineWidth = 2;
        var stemUp = steps < 4; ctx.beginPath();
        if (stemUp) { ctx.moveTo(cx + gap * 0.66, ny); ctx.lineTo(cx + gap * 0.66, ny - gap * 3.1); }
        else { ctx.moveTo(cx - gap * 0.66, ny); ctx.lineTo(cx - gap * 0.66, ny + gap * 3.1); }
        ctx.stroke();
      }
      function setFlash(kind, correctLetter) {
        state.flash = kind; draw();
        if (kind === 'bad' && correctLetter) { elFb.textContent = 'That was ' + correctLetter; elFb.className = 'ng-feedback show bad'; }
        else if (kind === 'good') { elFb.textContent = '✓'; elFb.className = 'ng-feedback show good'; }
        setTimeout(function () { elFb.className = 'ng-feedback'; }, 520);
      }
      function answer(letter) {
        if (state.over || state.note == null) return;
        var correct = letterOf(state.note), clefKey = state.clef;
        state.total++; state.byClef[clefKey].total++;
        if (letter === correct) {
          var dt = (Date.now() - state.askAt) / 1000;
          var speed = Math.max(0, Math.round((2.5 - Math.min(2.5, dt)) * 6));
          state.score += 10 + state.combo * 2 + speed; state.combo++; state.right++; state.byClef[clefKey].right++;
          if (state.combo > state.bestCombo) state.bestCombo = state.combo;
          playNote(state.note, true); setFlash('good');
          elScore.textContent = state.score; elCombo.textContent = state.combo + '×'; elCombo.classList.toggle('hot', state.combo >= 5);
          nextNote();
        } else {
          state.combo = 0; elCombo.textContent = '0×'; elCombo.classList.remove('hot');
          playNote(state.note, false); setFlash('bad', correct);
          if (survival) {
            state.lives--; if (elLives) elLives.textContent = state.lives > 0 ? Array(state.lives + 1).join('♥') : '—';
            if (state.lives <= 0) { setTimeout(endGame, 560); return; }
          }
          var keys = host.querySelectorAll('.ng-key'); Array.prototype.forEach.call(keys, function (k) { k.disabled = true; });
          setTimeout(function () { Array.prototype.forEach.call(keys, function (k) { k.disabled = false; }); nextNote(); }, 540);
        }
      }

      host.querySelector('#ngAnswers').addEventListener('click', function (e) { var b = e.target.closest('.ng-key'); if (b && !b.disabled) answer(b.getAttribute('data-note')); });
      host.querySelector('#ngQuit').addEventListener('click', endGame);
      function onKey(e) {
        if (!document.contains(cvs)) { document.removeEventListener('keydown', onKey); return; }
        var L = (e.key || '').toUpperCase();
        if (LETTERS.indexOf(L) >= 0) { e.preventDefault(); var b = host.querySelector('.ng-key[data-note="' + L + '"]'); if (b && !b.disabled) answer(L); }
      }
      document.addEventListener('keydown', onKey);

      loopAlive = true;
      ticker = setInterval(function () {
        if (!loopAlive || !document.contains(cvs)) { stop(); return; }
        if (!survival) {
          var left = Math.max(0, Math.ceil((state.endAt - Date.now()) / 1000));
          if (elTime) elTime.textContent = left;
          if (elBar) elBar.style.width = Math.max(0, (state.endAt - Date.now()) / (roundSec * 1000) * 100) + '%';
          if (left <= 5) { var th = host.querySelector('.ng-hud-time'); if (th) th.classList.add('low'); }
          if (Date.now() >= state.endAt) endGame();
        }
      }, 200);
      window.addEventListener('resize', draw);
      nextNote();

      function endGame() {
        if (state.over) return; state.over = true; stop();
        document.removeEventListener('keydown', onKey);
        // commit progress
        var xpGain = Math.round(state.score / 5) + state.right;
        var beforeLvl = levelFromXp(prog.xp);
        prog.xp += xpGain; prog.games++; prog.notes += state.right;
        prog.playSec += Math.max(0, Math.round((Date.now() - startedAt) / 1000));
        if (state.score > prog.bestScore) prog.bestScore = state.score;
        if (state.bestCombo > prog.bestCombo) prog.bestCombo = state.bestCombo;
        if (state.score > (prog.modeBest[mode.id] || 0)) prog.modeBest[mode.id] = state.score;
        prog.clef.treble.right += state.byClef.treble.right; prog.clef.treble.total += state.byClef.treble.total;
        prog.clef.bass.right += state.byClef.bass.right; prog.clef.bass.total += state.byClef.bass.total;
        prog.history.push(state.score); if (prog.history.length > 14) prog.history = prog.history.slice(-14);
        var newDailyBest = false;
        if (daily) {
          var today = dateKey(), tDay = dayNumber();
          if (prog.daily.last !== today) { prog.daily.streak = (prog.daily.lastDay === tDay - 1) ? prog.daily.streak + 1 : 1; prog.daily.last = today; prog.daily.lastDay = tDay; }
          if (state.score > prog.daily.best || prog.daily.last !== today) { /* best handled below */ }
          if (state.score > prog.daily.best) { prog.daily.best = state.score; newDailyBest = true; }
        }
        var fresh = applyAchievements(prog);
        saveProg(prog);
        var afterLvl = levelFromXp(prog.xp), leveled = afterLvl > beforeLvl;
        var acc = state.total ? Math.round(state.right / state.total * 100) : 0;
        host.innerHTML =
          '<div class="ng-wrap"><div class="ng-summary">' +
            (leveled ? '<div class="ng-levelup"><i class="fa-solid fa-arrow-up"></i> Level up! You\'re now level ' + afterLvl + '</div>' : '') +
            (newDailyBest ? '<div class="ng-levelup ng-daily-best"><i class="fa-solid fa-calendar-day"></i> New daily best!</div>' : '') +
            '<div class="ng-sum-eyebrow">' + esc(mode.name) + (daily ? ' · daily' : (survival ? ' · survival' : '')) + ' · round complete</div>' +
            '<div class="ng-sum-score">' + state.score + '<span>points</span></div>' +
            '<div class="ng-sum-grid">' +
              '<div><b>' + state.right + '/' + state.total + '</b><span>Correct</span></div>' +
              '<div><b>' + acc + '%</b><span>Accuracy</span></div>' +
              '<div><b>' + state.bestCombo + '×</b><span>Best combo</span></div>' +
              '<div><b>+' + xpGain + '</b><span>XP earned</span></div>' +
            '</div>' +
            (fresh.length ? '<div class="ng-new-ach"><div class="ng-new-ach-title"><i class="fa-solid fa-medal"></i> Achievement' + (fresh.length > 1 ? 's' : '') + ' unlocked</div>' + fresh.map(function (a) { return '<span class="ng-new-ach-chip"><i class="fa-solid fa-' + a.icon + '"></i> ' + esc(a.name) + '</span>'; }).join('') + '</div>' : '') +
            '<div class="ng-sum-actions">' +
              '<button class="ng-btn ng-btn-gold" id="ngAgain" type="button"><i class="fa-solid fa-rotate-right"></i> Play again</button>' +
              '<button class="ng-btn" id="ngMenu" type="button">Menu</button>' +
            '</div>' +
          '</div></div>';
        host.querySelector('#ngAgain').addEventListener('click', function () { startGame({ modeId: mode.id, daily: daily }); });
        host.querySelector('#ngMenu').addEventListener('click', renderMenu);
      }
    }

    renderMenu();
  }

  window.MAM_GAME = { mount: mount, progress: loadProg };
})();
