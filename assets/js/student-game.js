/*! MAM Academy — Practice Arcade  ·  window.MAM_GAME
 * ---------------------------------------------------------------------------
 * "Note Rush" — a sight-reading sprint for the student dashboard. A note is
 * drawn on a real musical staff (treble / bass / grand, with ledger lines);
 * the student names it before the 60-second clock runs out. Correct answers
 * build a combo and a speed bonus; the pitch is played back so the eye and the
 * ear train together.
 *
 * PROGRESS TRACKER — every game feeds a persistent profile (localStorage):
 * XP → player level, lifetime games / notes / best score / best combo, and a
 * per-clef accuracy breakdown. Nothing is uploaded; it works fully offline.
 *
 * Dependency-free: pure Canvas + DOM + Web Audio (audio is optional and
 * guarded, so a blocked/again-suspended context never breaks the game).
 */
(function () {
  'use strict';

  var SAVE = 'mam.game.noterush.v1';
  var ROUND_SECONDS = 60;
  var LETTERS = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];
  var LETTER_STEP = { C: 0, D: 1, E: 2, F: 3, G: 4, A: 5, B: 6 };

  // Diatonic index — a single integer ladder across octaves, so vertical
  // position on a staff is linear in it. C4 = 4*7 + 0 = 28.
  function dia(letter, octave) { return octave * 7 + LETTER_STEP[letter]; }
  function letterOf(d) { return LETTERS[((d % 7) + 7) % 7]; }
  function octaveOf(d) { return Math.floor(d / 7); }
  // MIDI (for pitch playback). Natural notes only. C4 = 60.
  var SEMI = { C: 0, D: 2, E: 4, F: 5, G: 7, A: 9, B: 11 };
  function midiOf(d) { return (octaveOf(d) + 1) * 12 + SEMI[letterOf(d)]; }
  function freqOf(d) { return 440 * Math.pow(2, (midiOf(d) - 69) / 12); }

  // Clefs. `bottomLine` = diatonic index sitting on the lowest staff line.
  var CLEFS = {
    treble: { glyph: '𝄞', bottomLine: dia('E', 4) },  // lines E4 G4 B4 D5 F5
    bass:   { glyph: '𝄢', bottomLine: dia('G', 2) }   // lines G2 B2 D3 F3 A3
  };

  // Difficulty modes. range = inclusive [minDia, maxDia] the note is drawn from.
  var MODES = [
    { id: 'treble', name: 'Treble Clef', sub: 'On-staff notes · the place to start', clef: 'treble',
      range: [dia('E', 4), dia('F', 5)] },
    { id: 'bass', name: 'Bass Clef', sub: 'On-staff notes · left-hand reading', clef: 'bass',
      range: [dia('G', 2), dia('A', 3)] },
    { id: 'treble-ledger', name: 'Treble + Ledger', sub: 'Middle C up to high A', clef: 'treble',
      range: [dia('C', 4), dia('A', 5)] },
    { id: 'grand', name: 'Grand Staff', sub: 'Both clefs, with ledger lines · pro', clef: 'both',
      range: [dia('C', 2), dia('C', 6)] }
  ];

  /* ── Persistent progress ────────────────────────────────────────────── */
  function blankProg() {
    return { xp: 0, games: 0, notes: 0, bestScore: 0, bestCombo: 0,
      clef: { treble: { right: 0, total: 0 }, bass: { right: 0, total: 0 } } };
  }
  function loadProg() {
    try {
      var raw = localStorage.getItem(SAVE);
      if (!raw) return blankProg();
      var d = JSON.parse(raw), p = blankProg();
      ['xp', 'games', 'notes', 'bestScore', 'bestCombo'].forEach(function (k) { if (typeof d[k] === 'number' && d[k] >= 0) p[k] = d[k] | 0; });
      if (d.clef) ['treble', 'bass'].forEach(function (c) {
        if (d.clef[c]) { p.clef[c].right = d.clef[c].right | 0; p.clef[c].total = d.clef[c].total | 0; }
      });
      return p;
    } catch (e) { return blankProg(); }
  }
  function saveProg(p) { try { localStorage.setItem(SAVE, JSON.stringify(p)); } catch (e) {} }
  // Smooth level curve: cumulative XP for level L is 50·L². Level 1 starts at 0.
  function levelFromXp(xp) { return Math.max(1, Math.floor(Math.sqrt(xp / 50)) + 1); }
  function xpForLevel(L) { return 50 * (L - 1) * (L - 1); }
  function accuracy(p) {
    var r = p.clef.treble.right + p.clef.bass.right, t = p.clef.treble.total + p.clef.bass.total;
    return t ? Math.round(r / t * 100) : 0;
  }

  /* ── Optional pitch playback (guarded) ──────────────────────────────── */
  var actx = null;
  function actxGet() {
    try {
      if (window.MAM && typeof window.MAM.audio === 'function') return (actx = window.MAM.audio());
      if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)();
      if (actx.state === 'suspended') actx.resume();
      return actx;
    } catch (e) { return null; }
  }
  function playNote(d, good) {
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

  /* ── The game ───────────────────────────────────────────────────────── */
  function mount(host) {
    if (!host) return;
    var prog = loadProg();
    var raf = null, ticker = null, loopAlive = false;

    function stop() { loopAlive = false; if (raf) cancelAnimationFrame(raf); if (ticker) clearInterval(ticker); raf = ticker = null; }

    // ---- screens -------------------------------------------------------
    function progressPanel() {
      var lvl = levelFromXp(prog.xp), base = xpForLevel(lvl), next = xpForLevel(lvl + 1);
      var pct = Math.max(0, Math.min(100, Math.round((prog.xp - base) / Math.max(1, next - base) * 100)));
      var tr = prog.clef.treble, bs = prog.clef.bass;
      function accRow(label, c) { var a = c.total ? Math.round(c.right / c.total * 100) : 0; return '<div class="ng-acc-row"><span>' + label + '</span><div class="ng-acc-bar"><i style="width:' + a + '%"></i></div><b>' + a + '%</b></div>'; }
      return '<div class="ng-progress">' +
        '<div class="ng-level"><div class="ng-level-badge">' + lvl + '</div><div><div class="ng-level-label">Level ' + lvl + '</div>' +
          '<div class="ng-xpbar"><i style="width:' + pct + '%"></i></div>' +
          '<div class="ng-xp-sub">' + prog.xp + ' XP · ' + (next - prog.xp) + ' to level ' + (lvl + 1) + '</div></div></div>' +
        '<div class="ng-stats">' +
          '<div class="ng-stat"><b>' + prog.games + '</b><span>Games</span></div>' +
          '<div class="ng-stat"><b>' + prog.notes + '</b><span>Notes read</span></div>' +
          '<div class="ng-stat"><b>' + prog.bestScore + '</b><span>Best score</span></div>' +
          '<div class="ng-stat"><b>' + prog.bestCombo + '×</b><span>Best combo</span></div>' +
          '<div class="ng-stat"><b>' + accuracy(prog) + '%</b><span>Accuracy</span></div>' +
        '</div>' +
        '<div class="ng-accs">' + accRow('Treble', tr) + accRow('Bass', bs) + '</div>' +
      '</div>';
    }

    function renderMenu() {
      stop();
      host.innerHTML =
        '<div class="ng-wrap">' +
          '<div class="ng-hero"><div class="ng-hero-eyebrow"><i class="fa-solid fa-gamepad"></i> Practice Arcade</div>' +
            '<h2 class="ng-hero-title">Note Rush</h2>' +
            '<p class="ng-hero-sub">Name the note before the clock runs out. Build a combo, climb the levels, sharpen your sight-reading — the pitch plays back so your ear learns too.</p></div>' +
          progressPanel() +
          '<div class="ng-modes">' + MODES.map(function (m) {
            return '<button class="ng-mode" type="button" data-mode="' + m.id + '"><span class="ng-mode-name">' + esc(m.name) + '</span><span class="ng-mode-sub">' + esc(m.sub) + '</span><span class="ng-mode-go"><i class="fa-solid fa-play"></i> Play</span></button>';
          }).join('') + '</div>' +
        '</div>';
      Array.prototype.forEach.call(host.querySelectorAll('.ng-mode'), function (b) {
        b.addEventListener('click', function () { actxGet(); startGame(b.getAttribute('data-mode')); });
      });
    }

    // ---- gameplay ------------------------------------------------------
    function startGame(modeId) {
      var mode = MODES.filter(function (m) { return m.id === modeId; })[0] || MODES[0];
      var state = { score: 0, combo: 0, bestCombo: 0, right: 0, total: 0, byClef: { treble: { right: 0, total: 0 }, bass: { right: 0, total: 0 } },
        endAt: Date.now() + ROUND_SECONDS * 1000, note: null, clef: null, askAt: 0, flash: null, over: false };

      host.innerHTML =
        '<div class="ng-wrap ng-playing">' +
          '<div class="ng-hud">' +
            '<div class="ng-hud-item"><span>Score</span><b id="ngScore">0</b></div>' +
            '<div class="ng-hud-item"><span>Combo</span><b id="ngCombo">0×</b></div>' +
            '<div class="ng-hud-item ng-hud-time"><span>Time</span><b id="ngTime">' + ROUND_SECONDS + '</b></div>' +
            '<button class="ng-quit" id="ngQuit" type="button" title="End game"><i class="fa-solid fa-xmark"></i></button>' +
          '</div>' +
          '<div class="ng-timebar"><i id="ngTimeBar"></i></div>' +
          '<div class="ng-stage"><canvas id="ngCanvas" aria-label="A note on the staff — name it below"></canvas><div class="ng-feedback" id="ngFeedback"></div></div>' +
          '<div class="ng-answers" id="ngAnswers" role="group" aria-label="Name the note">' +
            LETTERS.map(function (l) { return '<button class="ng-key" type="button" data-note="' + l + '">' + l + '</button>'; }).join('') +
          '</div>' +
          '<p class="ng-tip">Tap a letter or press its key on your keyboard.</p>' +
        '</div>';

      var cvs = host.querySelector('#ngCanvas'), ctx = cvs.getContext('2d');
      var elScore = host.querySelector('#ngScore'), elCombo = host.querySelector('#ngCombo'),
          elTime = host.querySelector('#ngTime'), elBar = host.querySelector('#ngTimeBar'),
          elFb = host.querySelector('#ngFeedback');

      function sizeCanvas() {
        var w = cvs.parentNode.clientWidth || 600, h = Math.max(220, Math.min(300, Math.round(w * 0.42)));
        var dpr = window.devicePixelRatio || 1;
        cvs.style.width = w + 'px'; cvs.style.height = h + 'px';
        cvs.width = Math.round(w * dpr); cvs.height = Math.round(h * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        return { w: w, h: h };
      }

      function nextNote() {
        var clef = mode.clef === 'both' ? (Math.random() < 0.5 ? 'treble' : 'bass') : mode.clef;
        var lo = mode.range[0], hi = mode.range[1], d;
        // For the grand staff, bias each clef to its readable range.
        if (mode.clef === 'both') { if (clef === 'treble') { lo = dia('C', 4); hi = dia('C', 6); } else { lo = dia('C', 2); hi = dia('C', 4); } }
        var tries = 0;
        do { d = lo + Math.floor(Math.random() * (hi - lo + 1)); tries++; } while (state.note != null && d === state.note && tries < 8);
        state.note = d; state.clef = clef; state.askAt = Date.now(); state.flash = null;
        draw();
      }

      function draw() {
        var dim = sizeCanvas(), w = dim.w, h = dim.h;
        ctx.clearRect(0, 0, w, h);
        var clef = CLEFS[state.clef], gap = 16, midY = h / 2, bottomLineY = midY + 2 * gap;
        var cx = Math.round(w * 0.62);
        // staff lines
        ctx.strokeStyle = 'rgba(245,238,227,0.55)'; ctx.lineWidth = 1.4;
        for (var i = 0; i < 5; i++) { var y = bottomLineY - i * gap; ctx.beginPath(); ctx.moveTo(40, y + 0.5); ctx.lineTo(w - 24, y + 0.5); ctx.stroke(); }
        // clef glyph
        ctx.fillStyle = '#FBBF24'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
        ctx.font = (gap * 5.4) + 'px Georgia, "Times New Roman", serif';
        ctx.fillText(clef.glyph, 48, state.clef === 'treble' ? midY + 4 : midY - gap * 0.6);
        // note position
        var steps = state.note - clef.bottomLine;       // diatonic steps above bottom line
        var ny = bottomLineY - steps * (gap / 2);
        // ledger lines (every line position outside 0..8 half-steps)
        ctx.strokeStyle = 'rgba(245,238,227,0.7)'; ctx.lineWidth = 1.4;
        var halfSteps = steps; // 0=bottom line, 8=top line; even = line
        if (halfSteps < 0) for (var l = -2; l >= halfSteps; l -= 2) { var ly = bottomLineY - l * (gap / 2); ctx.beginPath(); ctx.moveTo(cx - 16, ly + 0.5); ctx.lineTo(cx + 16, ly + 0.5); ctx.stroke(); }
        if (halfSteps > 8) for (var u = 10; u <= halfSteps; u += 2) { var uy = bottomLineY - u * (gap / 2); ctx.beginPath(); ctx.moveTo(cx - 16, uy + 0.5); ctx.lineTo(cx + 16, uy + 0.5); ctx.stroke(); }
        // note head
        var col = state.flash === 'good' ? '#34D399' : state.flash === 'bad' ? '#FB7185' : '#F5EEE3';
        ctx.save(); ctx.translate(cx, ny); ctx.rotate(-0.32);
        ctx.fillStyle = col; ctx.beginPath(); ctx.ellipse(0, 0, gap * 0.72, gap * 0.52, 0, 0, Math.PI * 2); ctx.fill(); ctx.restore();
        // stem
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
        var correct = letterOf(state.note);
        var clefKey = state.clef;
        state.total++; state.byClef[clefKey].total++;
        if (letter === correct) {
          var dt = (Date.now() - state.askAt) / 1000;
          var speed = Math.max(0, Math.round((2.5 - Math.min(2.5, dt)) * 6));   // up to +15 for speed
          var gain = 10 + state.combo * 2 + speed;
          state.score += gain; state.combo++; state.right++; state.byClef[clefKey].right++;
          if (state.combo > state.bestCombo) state.bestCombo = state.combo;
          playNote(state.note, true);
          setFlash('good');
          elScore.textContent = state.score; elCombo.textContent = state.combo + '×';
          elCombo.classList.toggle('hot', state.combo >= 5);
          nextNote();
        } else {
          state.combo = 0; elCombo.textContent = '0×'; elCombo.classList.remove('hot');
          playNote(state.note, false);
          setFlash('bad', correct);
          // brief pause so the learner registers the correct answer, then move on
          var keys = host.querySelectorAll('.ng-key'); Array.prototype.forEach.call(keys, function (k) { k.disabled = true; });
          setTimeout(function () { Array.prototype.forEach.call(keys, function (k) { k.disabled = false; }); nextNote(); }, 540);
        }
      }

      host.querySelector('#ngAnswers').addEventListener('click', function (e) {
        var b = e.target.closest('.ng-key'); if (b && !b.disabled) answer(b.getAttribute('data-note'));
      });
      host.querySelector('#ngQuit').addEventListener('click', endGame);
      function onKey(e) {
        if (!document.contains(cvs)) { document.removeEventListener('keydown', onKey); return; }
        var L = (e.key || '').toUpperCase();
        if (LETTERS.indexOf(L) >= 0) { e.preventDefault(); var b = host.querySelector('.ng-key[data-note="' + L + '"]'); if (b && !b.disabled) answer(L); }
      }
      document.addEventListener('keydown', onKey);

      // clock + render loop
      loopAlive = true;
      ticker = setInterval(function () {
        if (!loopAlive || !document.contains(cvs)) { stop(); return; }
        var left = Math.max(0, Math.ceil((state.endAt - Date.now()) / 1000));
        elTime.textContent = left;
        elBar.style.width = Math.max(0, (state.endAt - Date.now()) / (ROUND_SECONDS * 1000) * 100) + '%';
        if (left <= 5) host.querySelector('.ng-hud-time').classList.add('low');
        if (Date.now() >= state.endAt) endGame();
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
        if (state.score > prog.bestScore) prog.bestScore = state.score;
        if (state.bestCombo > prog.bestCombo) prog.bestCombo = state.bestCombo;
        prog.clef.treble.right += state.byClef.treble.right; prog.clef.treble.total += state.byClef.treble.total;
        prog.clef.bass.right += state.byClef.bass.right; prog.clef.bass.total += state.byClef.bass.total;
        saveProg(prog);
        var afterLvl = levelFromXp(prog.xp), leveled = afterLvl > beforeLvl;
        var acc = state.total ? Math.round(state.right / state.total * 100) : 0;
        host.innerHTML =
          '<div class="ng-wrap"><div class="ng-summary">' +
            (leveled ? '<div class="ng-levelup"><i class="fa-solid fa-arrow-up"></i> Level up! You\'re now level ' + afterLvl + '</div>' : '') +
            '<div class="ng-sum-eyebrow">' + esc(mode.name) + ' · round complete</div>' +
            '<div class="ng-sum-score">' + state.score + '<span>points</span></div>' +
            '<div class="ng-sum-grid">' +
              '<div><b>' + state.right + '/' + state.total + '</b><span>Correct</span></div>' +
              '<div><b>' + acc + '%</b><span>Accuracy</span></div>' +
              '<div><b>' + state.bestCombo + '×</b><span>Best combo</span></div>' +
              '<div><b>+' + xpGain + '</b><span>XP earned</span></div>' +
            '</div>' +
            '<div class="ng-sum-actions">' +
              '<button class="ng-btn ng-btn-gold" id="ngAgain" type="button"><i class="fa-solid fa-rotate-right"></i> Play again</button>' +
              '<button class="ng-btn" id="ngMenu" type="button">Change level</button>' +
            '</div>' +
          '</div></div>';
        host.querySelector('#ngAgain').addEventListener('click', function () { startGame(mode.id); });
        host.querySelector('#ngMenu').addEventListener('click', renderMenu);
      }
    }

    renderMenu();
  }

  window.MAM_GAME = { mount: mount, progress: loadProg };
})();
