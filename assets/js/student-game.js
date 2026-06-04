/*! MAM Academy — Practice Arcade  ·  window.MAM_GAME
 * ---------------------------------------------------------------------------
 * "Note Rush" — a sight-reading game that actually teaches. Name the note on a
 * real staff (treble / bass / grand, with ledger lines).
 *
 * LEARNING-FIRST
 *  • Learn Mode: untimed. Every note is explained — where it sits (line/space)
 *    plus the mnemonic (Every Good Boy Deserves Fanta / FACE / All Cows Eat
 *    Grass …) — and the pitch plays. A "Reveal & learn" button shows the answer.
 *  • Every miss in the scored modes shows the same mnemonic, so mistakes teach.
 *  • Adaptive: the game tracks per-note accuracy and biases practice toward the
 *    notes you find hardest; a "Tricky notes" panel surfaces them.
 *
 * GAME SYSTEMS
 *  • Timed + Survival rounds, combo + speed scoring, XP → level, achievements,
 *    a deterministic Daily Challenge with a streak, per-mode bests, score
 *    history. All persisted (localStorage), backward-compatible with v1 saves.
 *
 * Dependency-free: Canvas + DOM + Web Audio (guarded).
 */
(function () {
  'use strict';

  var SAVE = 'mam.game.noterush.v1';
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

  // Interval Trainer — index = diatonic steps between the two notes.
  var INTERVAL_LABELS = ['Unison', '2nd', '3rd', '4th', '5th', '6th', '7th', 'Octave'];
  var INTERVAL_STEPS = [1, 2, 3, 4, 5, 6, 7];

  // Teaching: turn each note into a "where it sits + mnemonic" micro-lesson.
  function ordinal(n) { return n + ({ 1: 'st', 2: 'nd', 3: 'rd' }[n] || 'th'); }
  function learnHint(note, clef) {
    var steps = note - CLEFS[clef].bottomLine, L = letterOf(note), pos;
    if (steps < 0) pos = Math.ceil(-steps / 2) + ' ledger below the staff';
    else if (steps > 8) pos = Math.ceil((steps - 8) / 2) + ' ledger above the staff';
    else if (steps % 2 === 0) pos = 'on the ' + ordinal(steps / 2 + 1) + ' line';
    else pos = 'in the ' + ordinal((steps - 1) / 2 + 1) + ' space';
    var onStaff = steps >= 0 && steps <= 8, line = steps % 2 === 0, mnem;
    if (clef === 'treble') mnem = onStaff ? (line ? 'Treble lines: Every Good Boy Deserves Fanta (E G B D F)' : 'Treble spaces spell FACE (F A C E)') : 'Treble clef — count up/down from a line you know.';
    else mnem = onStaff ? (line ? 'Bass lines: Good Boys Deserve Fanta Always (G B D F A)' : 'Bass spaces: All Cows Eat Grass (A C E G)') : 'Bass clef — count up/down from a line you know.';
    return '<b>' + L + '</b> — ' + pos + '. ' + mnem;
  }

  /* ── seeded RNG (Daily Challenge) ── */
  function seedFromString(s) { var h = 2166136261; for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; }
  function mulberry32(a) { return function () { a |= 0; a = a + 0x6D2B79F5 | 0; var t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
  function dateKey() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
  function dayNumber(d) { return Math.floor((d || Date.now()) / 86400000); }

  /* ── settings ── */
  function loadSettings() {
    var s = { roundSec: 60, sound: true, survival: false, learn: false };
    try { var d = JSON.parse(localStorage.getItem(SET) || '{}');
      if ([30, 60, 90, 120].indexOf(d.roundSec) >= 0) s.roundSec = d.roundSec;
      ['sound', 'survival', 'learn'].forEach(function (k) { if (typeof d[k] === 'boolean') s[k] = d[k]; });
    } catch (e) {}
    return s;
  }
  function saveSettings(s) { try { localStorage.setItem(SET, JSON.stringify(s)); } catch (e) {} }

  /* ── persistent profile (extended, migrating from v1) ── */
  function blankProg() {
    return {
      xp: 0, games: 0, notes: 0, bestScore: 0, bestCombo: 0, playSec: 0,
      clef: { treble: { right: 0, total: 0 }, bass: { right: 0, total: 0 } },
      modeBest: { treble: 0, bass: 0, 'treble-ledger': 0, grand: 0, interval: 0 },
      history: [], daily: { streak: 0, last: '', lastDay: 0, best: 0 },
      noteStats: {}, ach: {}
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
      if (d.noteStats && typeof d.noteStats === 'object') p.noteStats = d.noteStats;
      if (d.ach && typeof d.ach === 'object') p.ach = d.ach;
    } catch (e) {}
    return p;
  }
  function saveProg(p) { try { localStorage.setItem(SAVE, JSON.stringify(p)); } catch (e) {} }
  function levelFromXp(xp) { return Math.max(1, Math.floor(Math.sqrt(xp / 50)) + 1); }
  function xpForLevel(L) { return 50 * (L - 1) * (L - 1); }
  function accuracy(p) { var r = p.clef.treble.right + p.clef.bass.right, t = p.clef.treble.total + p.clef.bass.total; return t ? Math.round(r / t * 100) : 0; }
  // Weakest attempted notes (lowest accuracy first), for the tricky-notes panel.
  function weakNotes(ns, minTotal) {
    var out = []; Object.keys(ns || {}).forEach(function (k) { var s = ns[k]; if (s.total >= (minTotal || 4)) out.push({ key: k, acc: s.right / s.total, total: s.total }); });
    out.sort(function (a, b) { return a.acc - b.acc; }); return out;
  }

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
  function applyAchievements(p) { var fresh = []; ACH.forEach(function (a) { if (a.test(p) && !p.ach[a.id]) { p.ach[a.id] = true; fresh.push(a); } }); return fresh; }

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
      var t = ac.currentTime;
      if (good === false) {
        // Gentle "not quite" — a soft, low two-note fall through a low-pass,
        // never a harsh buzz (it plays on every miss, so it must be easy on the ear).
        var lp = ac.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 900; lp.connect(ac.destination);
        [155.56, 138.59].forEach(function (f, i) {
          var o = ac.createOscillator(), g = ac.createGain(); o.type = 'triangle'; o.frequency.value = f;
          var s = t + i * 0.05;
          g.gain.setValueAtTime(0.0001, s);
          g.gain.exponentialRampToValueAtTime(0.11, s + 0.01);
          g.gain.exponentialRampToValueAtTime(0.0001, s + 0.18);
          o.connect(g).connect(lp); o.start(s); o.stop(s + 0.2);
        });
        return;
      }
      // Correct — a warm FM bell/tine (pleasant to hear repeatedly + trains the ear
      // to the real pitch), shaped by a soft low-pass and a natural decay.
      var f = freqOf(d);
      var lp2 = ac.createBiquadFilter(); lp2.type = 'lowpass'; lp2.frequency.value = Math.min(6500, f * 8); lp2.Q.value = 0.5; lp2.connect(ac.destination);
      var car = ac.createOscillator(), g2 = ac.createGain(); car.type = 'triangle'; car.frequency.value = f;
      var mod = ac.createOscillator(), mg = ac.createGain(); mod.type = 'sine'; mod.frequency.value = f * 3;
      mg.gain.setValueAtTime(f * 1.2, t); mg.gain.exponentialRampToValueAtTime(f * 0.05, t + 0.2);
      mod.connect(mg).connect(car.frequency);
      g2.gain.setValueAtTime(0.0001, t);
      g2.gain.exponentialRampToValueAtTime(0.22, t + 0.008);
      g2.gain.exponentialRampToValueAtTime(0.0001, t + 0.55);
      car.connect(g2).connect(lp2);
      car.start(t); mod.start(t); car.stop(t + 0.6); mod.stop(t + 0.6);
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

    // Robustness net — any unexpected error in a screen transition shows a calm
    // recovery card instead of leaving a broken panel inside the portal.
    function safe(fn) {
      try { fn(); }
      catch (e) {
        if (window.console && console.warn) console.warn('[MAM_GAME]', e);
        try {
          stop();
          host.innerHTML = '<div class="ng-wrap"><div class="ng-summary"><div class="ng-sum-eyebrow"><i class="fa-solid fa-gamepad"></i> Practice Arcade</div>' +
            '<p class="ng-hero-sub" style="margin:14px 0 18px">That didn\'t load cleanly. Let\'s try again.</p>' +
            '<div class="ng-sum-actions"><button class="ng-btn ng-btn-gold" id="ngReboot" type="button"><i class="fa-solid fa-rotate-right"></i> Reload arcade</button></div></div></div>';
          var b = document.getElementById('ngReboot'); if (b) b.addEventListener('click', function () { safe(renderMenu); });
        } catch (e2) {}
      }
    }
    function weakLetterInRange(clef, lo, hi) {
      var seen = {}, best = null;
      for (var dd = lo; dd <= hi; dd++) seen[letterOf(dd)] = 1;
      Object.keys(seen).forEach(function (L) { var s = prog.noteStats[clef + ':' + L]; if (s && s.total >= 4) { var a = s.right / s.total; if (a < 0.85 && (!best || a < best.a)) best = { L: L, a: a }; } });
      return best ? best.L : null;
    }

    /* menu pieces */
    function sparkline(hist) {
      if (!hist.length) return '';
      var max = Math.max.apply(null, hist) || 1;
      return '<div class="ng-spark" aria-hidden="true">' + hist.map(function (v) { return '<i style="height:' + Math.max(8, Math.round(v / max * 100)) + '%"></i>'; }).join('') + '</div>';
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
    function trickyPanel() {
      var w = weakNotes(prog.noteStats, 4).slice(0, 5);
      if (!w.length) return '';
      return '<div class="ng-section-title">Your tricky notes <span>practice targets these</span></div>' +
        '<div class="ng-tricky">' + w.map(function (x) {
          var p = x.key.split(':'), a = Math.round(x.acc * 100);
          return '<div class="ng-tricky-chip"><b>' + esc(p[1]) + '</b><span>' + esc(p[0]) + ' · ' + a + '%</span></div>';
        }).join('') + '</div>';
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
    function learnCard() {
      return '<button class="ng-daily ng-learn" type="button" data-learn="1">' +
        '<div class="ng-daily-head"><span class="ng-daily-eyebrow"><i class="fa-solid fa-graduation-cap"></i> Learn Mode</span><span class="ng-daily-streak">no clock</span></div>' +
        '<div class="ng-daily-body"><div><strong>Learn the notes, step by step</strong><small>Every answer explained — where it sits + the mnemonic. Reveal any note.</small></div>' +
          '<span class="ng-daily-cta">Start <i class="fa-solid fa-arrow-right"></i></span></div>' +
      '</button>';
    }
    function intervalCard() {
      var best = prog.modeBest.interval || 0;
      return '<button class="ng-daily ng-interval" type="button" data-interval="1">' +
        '<div class="ng-daily-head"><span class="ng-daily-eyebrow"><i class="fa-solid fa-arrows-up-down-left-right"></i> Interval Trainer</span><span class="ng-daily-streak">' + (best ? 'Best ' + best : 'new') + '</span></div>' +
        '<div class="ng-daily-body"><div><strong>Name the distance between two notes</strong><small>2nds to octaves — trains the relationships, not just letters.</small></div>' +
          '<span class="ng-daily-cta">Play <i class="fa-solid fa-arrow-right"></i></span></div>' +
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
        sw('learn', 'Learn mode by default (untimed · every note explained)') +
        sw('sound', 'Sound') +
      '</div>';
    }

    function renderMenu() {
      stop();
      host.innerHTML =
        '<div class="ng-wrap">' +
          '<div class="ng-hero"><div class="ng-hero-eyebrow"><i class="fa-solid fa-gamepad"></i> Practice Arcade</div>' +
            '<h2 class="ng-hero-title">Note Rush</h2>' +
            '<p class="ng-hero-sub">Learn to read music, then race the clock. New to the staff? Start with <strong>Learn Mode</strong> — every note is explained. Then build combos and climb the levels.</p></div>' +
          progressPanel() +
          learnCard() +
          dailyCard() +
          intervalCard() +
          '<div class="ng-section-title">Choose a level</div>' +
          '<div class="ng-modes">' + MODES.map(function (m) {
            return '<button class="ng-mode" type="button" data-mode="' + m.id + '"><span class="ng-mode-name">' + esc(m.name) + '</span><span class="ng-mode-sub">' + esc(m.sub) + '</span>' +
              (prog.modeBest[m.id] ? '<span class="ng-mode-best">Best ' + prog.modeBest[m.id] + '</span>' : '') +
              '<span class="ng-mode-go"><i class="fa-solid fa-play"></i> Play</span></button>';
          }).join('') + '</div>' +
          trickyPanel() +
          settingsPanel() +
          achPanel() +
        '</div>';

      Array.prototype.forEach.call(host.querySelectorAll('.ng-mode'), function (b) { b.addEventListener('click', function () { actxGet(); safe(function () { startGame({ modeId: b.getAttribute('data-mode') }); }); }); });
      var dc = host.querySelector('[data-daily]'); if (dc) dc.addEventListener('click', function () { actxGet(); safe(function () { startGame({ modeId: 'grand', daily: true }); }); });
      var lc = host.querySelector('[data-learn]'); if (lc) lc.addEventListener('click', function () { actxGet(); safe(function () { startGame({ modeId: 'treble-ledger', learn: true }); }); });
      var ic = host.querySelector('[data-interval]'); if (ic) ic.addEventListener('click', function () { actxGet(); safe(function () { startInterval({ clef: 'treble' }); }); });
      Array.prototype.forEach.call(host.querySelectorAll('[data-set]'), function (cb) { cb.addEventListener('change', function () { settings[cb.getAttribute('data-set')] = cb.checked; if (cb.getAttribute('data-set') === 'sound') soundOn = cb.checked; saveSettings(settings); }); });
      Array.prototype.forEach.call(host.querySelectorAll('[data-len]'), function (b) { b.addEventListener('click', function () { settings.roundSec = parseInt(b.getAttribute('data-len'), 10); saveSettings(settings); Array.prototype.forEach.call(host.querySelectorAll('[data-len]'), function (x) { x.classList.toggle('active', x === b); }); }); });
    }

    /* gameplay */
    function startGame(opts) {
      opts = opts || {};
      var mode = modeById(opts.modeId);
      var daily = !!opts.daily;
      var learn = !daily && (!!opts.learn || settings.learn);
      var survival = (daily || learn) ? false : settings.survival;
      var roundSec = daily ? 60 : settings.roundSec;
      var rng = daily ? mulberry32(seedFromString('mam-noterush-' + dateKey())) : Math.random;
      var startedAt = Date.now();
      var state = {
        score: 0, combo: 0, bestCombo: 0, right: 0, total: 0, reviewed: 0, lives: 3,
        daily: daily, survival: survival, learn: learn, awaiting: false,
        byClef: { treble: { right: 0, total: 0 }, bass: { right: 0, total: 0 } }, noteStats: {},
        endAt: Date.now() + roundSec * 1000, note: null, clef: null, askAt: 0, flash: null, over: false
      };

      var hudRight = learn
        ? '<div class="ng-hud-item"><span>Reviewed</span><b id="ngReviewed">0</b></div>'
        : survival
          ? '<div class="ng-hud-item ng-hud-lives"><span>Lives</span><b id="ngLives">♥♥♥</b></div>'
          : '<div class="ng-hud-item ng-hud-time"><span>Time</span><b id="ngTime">' + roundSec + '</b></div>';
      host.innerHTML =
        '<div class="ng-wrap ng-playing">' +
          (daily ? '<div class="ng-daily-banner"><i class="fa-solid fa-calendar-day"></i> Daily Challenge · ' + esc(dateKey()) + '</div>' : '') +
          (learn ? '<div class="ng-daily-banner"><i class="fa-solid fa-graduation-cap"></i> Learn mode · no clock · every note explained</div>' : '') +
          '<div class="ng-hud">' +
            (learn
              ? '<div class="ng-hud-item"><span>Correct</span><b id="ngScore">0</b></div>'
              : '<div class="ng-hud-item"><span>Score</span><b id="ngScore">0</b></div><div class="ng-hud-item"><span>Combo</span><b id="ngCombo">0×</b></div>') +
            hudRight +
            '<button class="ng-quit" id="ngQuit" type="button" title="' + (learn ? 'Finish' : 'End round') + '"><i class="fa-solid fa-xmark"></i></button>' +
          '</div>' +
          ((survival || learn) ? '' : '<div class="ng-timebar"><i id="ngTimeBar"></i></div>') +
          '<div class="ng-stage"><canvas id="ngCanvas" aria-label="A note on the staff — name it below"></canvas><div class="ng-feedback" id="ngFeedback"></div></div>' +
          '<div class="ng-answers" id="ngAnswers" role="group" aria-label="Name the note">' +
            LETTERS.map(function (l) { return '<button class="ng-key" type="button" data-note="' + l + '">' + l + '</button>'; }).join('') +
          '</div>' +
          '<div class="ng-teach" id="ngTeach" hidden></div>' +
          (learn ? '<button class="ng-reveal" id="ngReveal" type="button"><i class="fa-solid fa-eye"></i> Reveal &amp; learn</button>' : '') +
          '<p class="ng-tip">Tap a letter or press its key (C–B). ' + (learn ? 'Take your time — each answer is explained.' : survival ? '3 lives.' : roundSec + 's on the clock.') + '</p>' +
        '</div>';

      var cvs = host.querySelector('#ngCanvas'), ctx = cvs.getContext('2d');
      var elBar = host.querySelector('#ngTimeBar'), elFb = host.querySelector('#ngFeedback'), elTeach = host.querySelector('#ngTeach');

      function updateHud() {
        var e;
        if ((e = host.querySelector('#ngScore'))) e.textContent = learn ? state.right : state.score;
        if ((e = host.querySelector('#ngCombo'))) { e.textContent = state.combo + '×'; e.classList.toggle('hot', state.combo >= 5); }
        if ((e = host.querySelector('#ngReviewed'))) e.textContent = state.reviewed;
        if ((e = host.querySelector('#ngLives'))) e.textContent = state.lives > 0 ? Array(state.lives + 1).join('♥') : '—';
      }
      function disableKeys(on) { Array.prototype.forEach.call(host.querySelectorAll('.ng-key'), function (k) { k.disabled = !!on; }); }
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
        // Adaptive: outside the seeded daily, ~35% of notes target a weak letter.
        var weakL = (!daily && rng() < 0.35) ? weakLetterInRange(clef, lo, hi) : null;
        var tries = 0;
        do {
          d = lo + Math.floor(rng() * (hi - lo + 1)); tries++;
        } while (tries < 16 && ((weakL && letterOf(d) !== weakL) || (state.note != null && d === state.note)));
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
      function teach(kind) {                       // learn mode: explain, then wait for Next
        state.awaiting = true; disableKeys(true);
        var lead = kind === 'good' ? '<i class="fa-solid fa-circle-check"></i> Correct! ' : kind === 'reveal' ? '<i class="fa-solid fa-eye"></i> This note is ' : '<i class="fa-solid fa-circle-info"></i> Not quite — ';
        elTeach.innerHTML = '<div class="ng-teach-row ' + (kind === 'good' ? 'good' : kind === 'reveal' ? 'rev' : 'bad') + '">' + lead + learnHint(state.note, state.clef) + '</div>' +
          '<button class="ng-btn ng-btn-gold" id="ngNext" type="button">Next note <i class="fa-solid fa-arrow-right"></i></button>';
        elTeach.hidden = false;
        var nx = elTeach.querySelector('#ngNext'); if (nx) nx.addEventListener('click', function () { elTeach.hidden = true; state.awaiting = false; disableKeys(false); nextNote(); });
      }
      function miss() { elTeach.innerHTML = '<div class="ng-teach-row bad"><i class="fa-solid fa-circle-info"></i> ' + learnHint(state.note, state.clef) + '</div>'; elTeach.hidden = false; }

      function answer(letter) {
        if (state.over || state.note == null || state.awaiting) return;
        var correct = letterOf(state.note), clefKey = state.clef;
        state.total++; state.byClef[clefKey].total++;
        var nk = clefKey + ':' + correct, ns = state.noteStats[nk] || (state.noteStats[nk] = { right: 0, total: 0 }); ns.total++;
        var win = letter === correct;
        if (win) {
          var dt = (Date.now() - state.askAt) / 1000, speed = Math.max(0, Math.round((2.5 - Math.min(2.5, dt)) * 6));
          state.score += 10 + state.combo * 2 + speed; state.combo++; state.right++; state.byClef[clefKey].right++; ns.right++;
          if (state.combo > state.bestCombo) state.bestCombo = state.combo;
          playNote(state.note, true); setFlash('good');
        } else { state.combo = 0; playNote(state.note, false); setFlash('bad', correct); }
        if (state.learn) { if (win) state.reviewed++; updateHud(); teach(win ? 'good' : 'bad'); return; }
        updateHud();
        if (win) { nextNote(); return; }
        if (survival) { state.lives--; updateHud(); if (state.lives <= 0) { miss(); setTimeout(endGame, 1100); return; } }
        miss(); disableKeys(true);
        setTimeout(function () { elTeach.hidden = true; disableKeys(false); nextNote(); }, 1200);
      }

      host.querySelector('#ngAnswers').addEventListener('click', function (e) { var b = e.target.closest('.ng-key'); if (b && !b.disabled) answer(b.getAttribute('data-note')); });
      host.querySelector('#ngQuit').addEventListener('click', endGame);
      var revBtn = host.querySelector('#ngReveal');
      if (revBtn) revBtn.addEventListener('click', function () { if (state.awaiting || state.note == null) return; state.flash = 'good'; draw(); playNote(state.note, true); state.reviewed++; updateHud(); teach('reveal'); });
      function onKey(e) {
        if (!document.contains(cvs)) { document.removeEventListener('keydown', onKey); return; }
        var L = (e.key || '').toUpperCase();
        if (LETTERS.indexOf(L) >= 0) { e.preventDefault(); var b = host.querySelector('.ng-key[data-note="' + L + '"]'); if (b && !b.disabled) answer(L); }
      }
      document.addEventListener('keydown', onKey);

      loopAlive = true;
      ticker = setInterval(function () {
        if (!loopAlive || !document.contains(cvs)) { stop(); return; }
        if (!survival && !learn) {
          var left = Math.max(0, Math.ceil((state.endAt - Date.now()) / 1000)), te = host.querySelector('#ngTime');
          if (te) te.textContent = left;
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
        // Merge per-note stats (every mode) so adaptive targeting keeps learning.
        Object.keys(state.noteStats).forEach(function (k) { var s = prog.noteStats[k] || (prog.noteStats[k] = { right: 0, total: 0 }); s.right += state.noteStats[k].right; s.total += state.noteStats[k].total; });
        prog.playSec += Math.max(0, Math.round((Date.now() - startedAt) / 1000));

        if (learn) {   // practice: save learning data, no XP/score gaming
          saveProg(prog);
          host.innerHTML = '<div class="ng-wrap"><div class="ng-summary">' +
            '<div class="ng-sum-eyebrow"><i class="fa-solid fa-graduation-cap"></i> Learn session</div>' +
            '<div class="ng-sum-score">' + state.reviewed + '<span>notes reviewed</span></div>' +
            '<p class="ng-hero-sub" style="margin:0 0 18px">Great practice. The game will now lean a little more on the notes you found tricky — try a timed round when you\'re ready.</p>' +
            '<div class="ng-sum-actions"><button class="ng-btn ng-btn-gold" id="ngAgain" type="button"><i class="fa-solid fa-rotate-right"></i> Keep learning</button><button class="ng-btn" id="ngMenu" type="button">Menu</button></div>' +
          '</div></div>';
          host.querySelector('#ngAgain').addEventListener('click', function () { safe(function () { startGame({ modeId: mode.id, learn: true }); }); });
          host.querySelector('#ngMenu').addEventListener('click', function () { safe(renderMenu); });
          return;
        }

        var xpGain = Math.round(state.score / 5) + state.right;
        var beforeLvl = levelFromXp(prog.xp);
        prog.xp += xpGain; prog.games++; prog.notes += state.right;
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
        host.querySelector('#ngAgain').addEventListener('click', function () { safe(function () { startGame({ modeId: mode.id, daily: daily }); }); });
        host.querySelector('#ngMenu').addEventListener('click', function () { safe(renderMenu); });
      }
    }

    /* Interval Trainer — a second discipline: name the distance between two
       notes. Self-contained (its own two-note staff) so the proven note-naming
       flow above is untouched. */
    function startInterval(opts) {
      opts = opts || {};
      var clefMode = opts.clef || 'treble';
      var roundSec = settings.roundSec || 60;
      var startedAt = Date.now();
      var state = { score: 0, combo: 0, bestCombo: 0, right: 0, total: 0, endAt: Date.now() + roundSec * 1000, lo: null, clef: null, steps: 0, askAt: 0, flash: null, over: false };

      host.innerHTML =
        '<div class="ng-wrap ng-playing">' +
          '<div class="ng-daily-banner ng-interval-banner"><i class="fa-solid fa-arrows-up-down-left-right"></i> Interval Trainer · name the distance between the two notes</div>' +
          '<div class="ng-hud">' +
            '<div class="ng-hud-item"><span>Score</span><b id="ngScore">0</b></div>' +
            '<div class="ng-hud-item"><span>Combo</span><b id="ngCombo">0×</b></div>' +
            '<div class="ng-hud-item ng-hud-time"><span>Time</span><b id="ngTime">' + roundSec + '</b></div>' +
            '<button class="ng-quit" id="ngQuit" type="button" title="End round"><i class="fa-solid fa-xmark"></i></button>' +
          '</div>' +
          '<div class="ng-timebar"><i id="ngTimeBar"></i></div>' +
          '<div class="ng-stage"><canvas id="ngCanvas" aria-label="Two notes on a staff — name the interval"></canvas><div class="ng-feedback" id="ngFeedback"></div></div>' +
          '<div class="ng-answers ng-answers-wide" id="ngAnswers" role="group" aria-label="Name the interval">' +
            INTERVAL_STEPS.map(function (s) { return '<button class="ng-key" type="button" data-step="' + s + '">' + INTERVAL_LABELS[s] + '</button>'; }).join('') +
          '</div>' +
          '<p class="ng-tip">Tap the interval, or press 2–8. ' + roundSec + 's on the clock.</p>' +
        '</div>';

      var cvs = host.querySelector('#ngCanvas'); if (!cvs) { safe(renderMenu); return; }
      var ctx = cvs.getContext && cvs.getContext('2d'); if (!ctx) { safe(renderMenu); return; }
      var elScore = host.querySelector('#ngScore'), elCombo = host.querySelector('#ngCombo'),
          elTime = host.querySelector('#ngTime'), elBar = host.querySelector('#ngTimeBar'), elFb = host.querySelector('#ngFeedback');

      function sizeCanvas() {
        var w = cvs.parentNode.clientWidth || 600, h = Math.max(220, Math.min(300, Math.round(w * 0.42)));
        var dpr = window.devicePixelRatio || 1;
        cvs.style.width = w + 'px'; cvs.style.height = h + 'px';
        cvs.width = Math.round(w * dpr); cvs.height = Math.round(h * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        return { w: w, h: h };
      }
      function nextPair() {
        var clef = clefMode === 'both' ? (Math.random() < 0.5 ? 'treble' : 'bass') : clefMode;
        var steps = INTERVAL_STEPS[Math.floor(Math.random() * INTERVAL_STEPS.length)];
        var room = 8 - steps;                                   // keep both notes on/near the staff
        var lo = CLEFS[clef].bottomLine + Math.floor(Math.random() * (room + 1));
        if (state.lo === lo && state.steps === steps) lo = CLEFS[clef].bottomLine + ((lo - CLEFS[clef].bottomLine + 1) % (room + 1));
        state.clef = clef; state.lo = lo; state.steps = steps; state.askAt = Date.now(); state.flash = null;
        draw();
      }
      function drawNote(geom, d, color) {
        var gap = geom.gap, bottomLineY = geom.bottomLineY, cx = geom.cx, clef = CLEFS[state.clef];
        var steps = d - clef.bottomLine, ny = bottomLineY - steps * (gap / 2);
        ctx.strokeStyle = 'rgba(245,238,227,0.7)'; ctx.lineWidth = 1.4;
        if (steps < 0) for (var l = -2; l >= steps; l -= 2) { var ly = bottomLineY - l * (gap / 2); ctx.beginPath(); ctx.moveTo(cx - 16, ly + 0.5); ctx.lineTo(cx + 16, ly + 0.5); ctx.stroke(); }
        if (steps > 8) for (var u = 10; u <= steps; u += 2) { var uy = bottomLineY - u * (gap / 2); ctx.beginPath(); ctx.moveTo(cx - 16, uy + 0.5); ctx.lineTo(cx + 16, uy + 0.5); ctx.stroke(); }
        ctx.save(); ctx.translate(cx, ny); ctx.rotate(-0.32);
        ctx.fillStyle = color; ctx.beginPath(); ctx.ellipse(0, 0, gap * 0.72, gap * 0.52, 0, 0, Math.PI * 2); ctx.fill(); ctx.restore();
      }
      function draw() {
        var dim = sizeCanvas(), w = dim.w, h = dim.h;
        ctx.clearRect(0, 0, w, h);
        var clef = CLEFS[state.clef], gap = 16, midY = h / 2, bottomLineY = midY + 2 * gap, cx = Math.round(w * 0.6);
        ctx.strokeStyle = 'rgba(245,238,227,0.55)'; ctx.lineWidth = 1.4;
        for (var i = 0; i < 5; i++) { var y = bottomLineY - i * gap; ctx.beginPath(); ctx.moveTo(40, y + 0.5); ctx.lineTo(w - 24, y + 0.5); ctx.stroke(); }
        ctx.fillStyle = '#FBBF24'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        ctx.font = (gap * 5.4) + 'px Georgia, "Times New Roman", serif';
        ctx.fillText(clef.glyph, 48, state.clef === 'treble' ? midY + 4 : midY - gap * 0.6);
        var col = state.flash === 'good' ? '#34D399' : state.flash === 'bad' ? '#FB7185' : '#F5EEE3';
        var geom = { gap: gap, bottomLineY: bottomLineY, cx: cx };
        drawNote(geom, state.lo, col);
        drawNote(geom, state.lo + state.steps, col);
      }
      function setFlash(kind, label) {
        state.flash = kind; draw();
        if (kind === 'bad' && label) { elFb.textContent = label; elFb.className = 'ng-feedback show bad'; }
        else if (kind === 'good') { elFb.textContent = '✓'; elFb.className = 'ng-feedback show good'; }
        setTimeout(function () { elFb.className = 'ng-feedback'; }, 520);
      }
      function answer(step) {
        if (state.over || state.lo == null) return;
        state.total++;
        if (step === state.steps) {
          var dt = (Date.now() - state.askAt) / 1000, speed = Math.max(0, Math.round((2.5 - Math.min(2.5, dt)) * 6));
          state.score += 10 + state.combo * 2 + speed; state.combo++; state.right++;
          if (state.combo > state.bestCombo) state.bestCombo = state.combo;
          setFlash('good');
          elScore.textContent = state.score; elCombo.textContent = state.combo + '×'; elCombo.classList.toggle('hot', state.combo >= 5);
          nextPair();
        } else {
          state.combo = 0; elCombo.textContent = '0×'; elCombo.classList.remove('hot');
          setFlash('bad', 'It was a ' + INTERVAL_LABELS[state.steps]);
          var keys = host.querySelectorAll('.ng-key'); Array.prototype.forEach.call(keys, function (k) { k.disabled = true; });
          setTimeout(function () { Array.prototype.forEach.call(keys, function (k) { k.disabled = false; }); nextPair(); }, 720);
        }
      }
      host.querySelector('#ngAnswers').addEventListener('click', function (e) { var b = e.target.closest('.ng-key'); if (b && !b.disabled) answer(parseInt(b.getAttribute('data-step'), 10)); });
      host.querySelector('#ngQuit').addEventListener('click', endInterval);
      function onKey(e) {
        if (!document.contains(cvs)) { document.removeEventListener('keydown', onKey); return; }
        var n = parseInt(e.key, 10);
        if (n >= 2 && n <= 8) { e.preventDefault(); var step = n - 1; var b = host.querySelector('.ng-key[data-step="' + step + '"]'); if (b && !b.disabled) answer(step); }
      }
      document.addEventListener('keydown', onKey);

      loopAlive = true;
      ticker = setInterval(function () {
        if (!loopAlive || !document.contains(cvs)) { stop(); return; }
        var left = Math.max(0, Math.ceil((state.endAt - Date.now()) / 1000));
        if (elTime) elTime.textContent = left;
        if (elBar) elBar.style.width = Math.max(0, (state.endAt - Date.now()) / (roundSec * 1000) * 100) + '%';
        if (left <= 5) { var th = host.querySelector('.ng-hud-time'); if (th) th.classList.add('low'); }
        if (Date.now() >= state.endAt) endInterval();
      }, 200);
      window.addEventListener('resize', draw);
      nextPair();

      function endInterval() {
        if (state.over) return; state.over = true; stop();
        document.removeEventListener('keydown', onKey);
        var xpGain = Math.round(state.score / 5) + state.right;
        var beforeLvl = levelFromXp(prog.xp);
        prog.xp += xpGain; prog.games++;
        prog.playSec += Math.max(0, Math.round((Date.now() - startedAt) / 1000));
        if (state.score > prog.bestScore) prog.bestScore = state.score;
        if (state.bestCombo > prog.bestCombo) prog.bestCombo = state.bestCombo;
        if (state.score > (prog.modeBest.interval || 0)) prog.modeBest.interval = state.score;
        prog.history.push(state.score); if (prog.history.length > 14) prog.history = prog.history.slice(-14);
        var fresh = applyAchievements(prog);
        saveProg(prog);
        var afterLvl = levelFromXp(prog.xp), leveled = afterLvl > beforeLvl;
        var acc = state.total ? Math.round(state.right / state.total * 100) : 0;
        host.innerHTML =
          '<div class="ng-wrap"><div class="ng-summary">' +
            (leveled ? '<div class="ng-levelup"><i class="fa-solid fa-arrow-up"></i> Level up! You\'re now level ' + afterLvl + '</div>' : '') +
            '<div class="ng-sum-eyebrow">Interval Trainer · round complete</div>' +
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
        host.querySelector('#ngAgain').addEventListener('click', function () { safe(function () { startInterval({ clef: clefMode }); }); });
        host.querySelector('#ngMenu').addEventListener('click', function () { safe(renderMenu); });
      }
    }

    safe(renderMenu);
  }

  window.MAM_GAME = { mount: mount, progress: loadProg };
})();
