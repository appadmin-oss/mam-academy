/* ============================================================
   MAM Academy — Rhythm Tap  ·  window.MAM_RHYTHM
   ------------------------------------------------------------
   A rhythm-reading tap game in the style of the reference clip: a cream board,
   a grid of note-value cards that light up in time at a fixed BPM, levels of
   increasing difficulty, and a metronome. Two modes:

     • Listen — the board plays the rhythm for you (cards light + click on each
       note onset) so you learn it.
     • Play   — a 4-beat count-in, then you TAP each card's rhythm in time; cards
       fill with colour by how tightly you hit the onsets; weighted timing score.

   Original implementation: rhythm patterns + simplified SVG notation are
   generic music theory (no songs/lyrics/art). Reuses window.MAM.audio() for a
   single shared, low-latency click; no samples to host.
   ============================================================ */
(function () {
  'use strict';

  // Onsets are positions within ONE beat (0 = downbeat … 1 = next beat).
  var PAT = {
    q:    { onsets: [0],                 glyph: 'quarter'    }, // ♩
    ee:   { onsets: [0, 0.5],            glyph: 'eighths'    }, // ♫
    ssss: { onsets: [0, .25, .5, .75],   glyph: 'sixteenths' }, // ♬♬
    des:  { onsets: [0, 0.75],           glyph: 'dotted'     }, // ♩.♪ feel
    res:  { onsets: [0.5],               glyph: 'rest_eighth'}, // 𝄾♪
    qr:   { onsets: [],                  glyph: 'rest'       }  // 𝄽 (don't tap)
  };
  var LEVELS = [
    { name: 'Level 1', bpm: 92,  cards: ['q','ee','q','ee','q','q','ee','q','ee','q','q','ee'] },
    { name: 'Level 2', bpm: 100, cards: ['q','ee','ssss','ee','res','ee','res','q','ssss','q','qr','ee'] },
    { name: 'Level 3', bpm: 104, cards: ['ee','des','ssss','ee','res','q','des','ssss','qr','ee','des','q'] },
    { name: 'Level 4', bpm: 112, cards: ['ssss','des','ee','ssss','res','ee','des','ssss','des','ee','ssss','des','ee','qr'] }
  ];
  var COLORS = ['#F4577E', '#5B9BE8', '#F2B632', '#C44DD6', '#42C58B', '#FF8A4C'];
  var HIT_TOL = 0.18; // fraction of a beat that still counts as on-time

  var root, dom = {}, level = LEVELS[0], state = 'idle';
  var raf = null, t0 = 0, beat = 0.6, cardTaps = [], cardDone = [], curCard = -1, mode = 'play';

  function ac() { return (window.MAM && window.MAM.audio) ? window.MAM.audio() : (window.__rgAC || (window.__rgAC = new (window.AudioContext || window.webkitAudioContext)())); }

  /* ── Click / tone synthesis (shared context) ── */
  function click(when, accent) {
    var a = ac(), o = a.createOscillator(), g = a.createGain();
    o.type = 'square'; o.frequency.value = accent ? 2000 : 1400;
    g.gain.setValueAtTime(accent ? 0.22 : 0.12, when);
    g.gain.exponentialRampToValueAtTime(0.0001, when + 0.05);
    o.connect(g).connect(a.destination); o.start(when); o.stop(when + 0.06);
  }
  function blip(when, freq) {
    var a = ac(), o = a.createOscillator(), g = a.createGain();
    o.type = 'triangle'; o.frequency.value = freq || 660;
    g.gain.setValueAtTime(0.0001, when); g.gain.exponentialRampToValueAtTime(0.2, when + 0.008);
    g.gain.exponentialRampToValueAtTime(0.0001, when + 0.14);
    o.connect(g).connect(a.destination); o.start(when); o.stop(when + 0.16);
  }

  /* ════════════════════ Notation glyphs (simplified SVG) ════════════════════ */
  function glyph(type) {
    var ink = '#2B2B33', s = '<svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
    function head(x, y) { return '<ellipse cx="' + x + '" cy="' + y + '" rx="9" ry="6.6" transform="rotate(-20 ' + x + ' ' + y + ')" fill="' + ink + '"/>'; }
    function stem(x) { return '<rect x="' + (x + 7.4) + '" y="22" width="2.6" height="34" fill="' + ink + '"/>'; }
    function flagBeam(x1, x2, y, h) { return '<rect x="' + (x1 + 7.4) + '" y="' + y + '" width="' + (x2 - x1) + '" height="' + (h || 4.5) + '" fill="' + ink + '"/>'; }
    function rest() { // stylised quarter rest
      return '<path d="M40 24 q9 6 2 13 q-8 6 1 13 q-9 -4 -3 -12 q7 -6 -1 -12 z" fill="' + ink + '"/>';
    }
    function eighthRest() { return '<g><circle cx="36" cy="34" r="4.2" fill="' + ink + '"/><rect x="38.5" y="33" width="2.4" height="20" transform="rotate(18 39 43)" fill="' + ink + '"/></g>'; }
    var g = '';
    if (type === 'quarter') { g = head(32, 50) + stem(32); }
    else if (type === 'eighths') { g = head(20, 52) + stem(20) + head(46, 52) + stem(46) + flagBeam(20, 46, 22, 5); }
    else if (type === 'sixteenths') { g = head(13, 54) + stem(13) + head(31, 54) + stem(31) + head(49, 54) + stem(49) + head(60, 54) + stem(60) + flagBeam(13, 60, 22, 4) + flagBeam(13, 60, 28, 4); }
    else if (type === 'dotted') { g = head(22, 52) + stem(22) + '<circle cx="36" cy="50" r="3" fill="' + ink + '"/>' + head(50, 52) + stem(50) + flagBeam(22, 50, 22, 5) + flagBeam(40, 50, 28, 4); }
    else if (type === 'rest_eighth') { g = eighthRest() + head(54, 52) + stem(54); }
    else if (type === 'rest') { g = rest(); }
    return s + g + '</svg>';
  }

  /* ════════════════════ Board ════════════════════ */
  function buildBoard() {
    var cards = level.cards.map(function (id, i) {
      return '<button class="rg-card" data-i="' + i + '" type="button" aria-label="Card ' + (i + 1) + '">' + glyph(PAT[id].glyph) + '</button>';
    }).join('');
    dom.title.textContent = level.name;
    dom.bpm.textContent = '♩ = ' + level.bpm + ' BPM';
    dom.grid.innerHTML = cards;
    cardDone = level.cards.map(function () { return null; });
    cardTaps = level.cards.map(function () { return []; });
    curCard = -1;
    paint();
  }
  function paint() {
    var els = dom.grid.querySelectorAll('.rg-card');
    els.forEach(function (el, i) {
      el.classList.toggle('rg-cur', i === curCard && state !== 'idle');
      el.classList.remove('rg-c0', 'rg-c1', 'rg-c2', 'rg-c3', 'rg-c4', 'rg-c5', 'rg-miss', 'rg-ok');
      var d = cardDone[i];
      if (d != null) {
        if (d.acc >= 0.85) el.style.setProperty('--rg-fill', d.color), el.classList.add('rg-ok');
        else if (d.acc >= 0.5) el.classList.add('rg-ok'), el.style.setProperty('--rg-fill', '#F2B632');
        else el.classList.add('rg-miss'), el.style.removeProperty('--rg-fill');
      } else { el.style.removeProperty('--rg-fill'); }
    });
  }

  /* ════════════════════ Timeline + loop ════════════════════ */
  function startRun(playMode) {
    if (state !== 'idle') stopRun(true);
    mode = playMode ? 'play' : 'listen';
    buildBoard();
    state = mode;
    beat = 60 / level.bpm;
    var a = ac(), base = a.currentTime + 0.18;
    // 4-beat count-in
    for (var i = 0; i < 4; i++) click(base + i * beat, i === 0);
    t0 = base + 4 * beat; // first card downbeat
    // Listen mode: pre-schedule the clicks for every onset so it plays itself.
    if (mode === 'listen') {
      level.cards.forEach(function (id, k) {
        PAT[id].onsets.forEach(function (on, j) { blip(t0 + k * beat + on * beat, 560 + j * 90); });
        click(t0 + k * beat, k % 4 === 0);
      });
    } else {
      // Play mode still ticks the pulse so the student feels the beat.
      for (var b = 0; b < level.cards.length; b++) click(t0 + b * beat, b % 4 === 0);
    }
    setStatus(mode === 'listen' ? 'Listen — watch the cards light up in time.' : 'Tap each card’s rhythm as it lights up. Count-in first…');
    dom.start.classList.toggle('rg-on', mode === 'play');
    cancelAnimationFrame(raf); raf = requestAnimationFrame(loop);
  }
  function loop() {
    if (state === 'idle') return;
    raf = requestAnimationFrame(loop);
    var elapsed = ac().currentTime - t0;
    var idx = Math.floor(elapsed / beat);
    if (idx !== curCard) {
      // a card just ended → finalize it (play mode)
      if (mode === 'play' && curCard >= 0 && curCard < level.cards.length && cardDone[curCard] == null) finalizeCard(curCard);
      if (mode === 'listen' && curCard >= 0 && curCard < level.cards.length) cardDone[curCard] = { acc: 1, color: COLORS[curCard % COLORS.length] };
      curCard = idx; paint();
    }
    if (elapsed >= level.cards.length * beat + 0.05) { endRun(); return; }
  }
  function finalizeCard(k) {
    var pat = PAT[level.cards[k]], E = pat.onsets, T = cardTaps[k] || [];
    var acc;
    if (E.length === 0) { acc = T.length === 0 ? 1 : Math.max(0, 1 - T.length * 0.5); } // rest: don't tap
    else {
      var used = [], matched = 0;
      E.forEach(function (e) {
        var bi = -1, bd = 1e9;
        T.forEach(function (t, ti) { if (used[ti]) return; var dd = Math.abs(t - e); if (dd < bd) { bd = dd; bi = ti; } });
        if (bi >= 0 && bd <= HIT_TOL) { used[bi] = 1; matched += (1 - bd / HIT_TOL) * 0.4 + 0.6; }
      });
      var extra = T.length - used.filter(Boolean).length;
      acc = Math.max(0, (matched - extra * 0.4) / E.length);
    }
    cardDone[k] = { acc: acc, color: COLORS[k % COLORS.length] };
  }
  function endRun() {
    if (mode === 'play' && curCard >= 0 && cardDone[curCard] == null && curCard < level.cards.length) finalizeCard(curCard);
    state = 'idle'; cancelAnimationFrame(raf); raf = null; curCard = -1;
    dom.start.classList.remove('rg-on');
    paint();
    if (mode === 'play') showResult();
    else setStatus('That’s the rhythm. Hit Play to tap it yourself.');
  }
  function stopRun(silent) {
    state = 'idle'; cancelAnimationFrame(raf); raf = null; curCard = -1;
    dom.start && dom.start.classList.remove('rg-on'); paint();
    if (!silent) setStatus('Stopped.');
  }

  /* ── tap capture ── */
  function tap() {
    if (state !== 'play') return;
    var elapsed = ac().currentTime - t0;
    if (elapsed < -0.05) return;
    var k = Math.floor(elapsed / beat);
    if (k < 0 || k >= level.cards.length) return;
    var frac = elapsed / beat - k; // 0..1 within the card
    (cardTaps[k] = cardTaps[k] || []).push(frac);
    var el = dom.grid.querySelector('.rg-card[data-i="' + k + '"]');
    if (el) { el.classList.remove('rg-pulse'); void el.offsetWidth; el.classList.add('rg-pulse'); }
    blip(ac().currentTime, 720);
  }

  function showResult() {
    var sum = 0; cardDone.forEach(function (d) { sum += d ? d.acc : 0; });
    var pct = Math.round(sum / level.cards.length * 100);
    var stars = pct >= 90 ? 3 : pct >= 70 ? 2 : pct >= 45 ? 1 : 0, st = '';
    for (var i = 0; i < 3; i++) st += '<span class="rg-star ' + (i < stars ? 'on' : '') + '">★</span>';
    dom.result.innerHTML = '<div class="rg-result-card"><div class="rg-stars">' + st + '</div>'
      + '<div class="rg-final">' + pct + '<small>%</small></div><div class="rg-final-l">' + level.name + ' rhythm</div>'
      + '<button class="rg-btn rg-primary rg-again" type="button">Try again</button></div>';
    dom.result.classList.add('shown');
    var a = dom.result.querySelector('.rg-again'); if (a) a.addEventListener('click', function () { dom.result.classList.remove('shown'); startRun(true); });
    if (window.MAM_STUDIO && window.MAM_STUDIO.logScore) { try { window.MAM_STUDIO.logScore('rhythm', pct); } catch (e) {} }
  }
  function setStatus(m) { if (dom.status) dom.status.textContent = m; }

  /* ════════════════════ UI ════════════════════ */
  function build() {
    root.innerHTML =
      '<div class="rg-board">'
      +  '<div class="rg-blob rg-blob-1"></div><div class="rg-blob rg-blob-2"></div>'
      +  '<div class="rg-head"><h3 class="rg-title" id="rgTitle">Level 1</h3><div class="rg-sub">MAM Academy · Rhythm Tap</div></div>'
      +  '<div class="rg-grid" id="rgGrid"></div>'
      +  '<div class="rg-bpm" id="rgBpm">♩ = 100 BPM</div>'
      +  '<div class="rg-result" id="rgResult"></div>'
      + '</div>'
      + '<div class="rg-controls">'
      +  '<label class="rg-field">Level<select id="rgLevel"></select></label>'
      +  '<button class="rg-btn" id="rgListen" type="button"><i class="fa-solid fa-volume-high"></i> Listen</button>'
      +  '<button class="rg-btn rg-primary" id="rgStart" type="button"><i class="fa-solid fa-hand-pointer"></i> Play</button>'
      +  '<button class="rg-btn" id="rgStop" type="button"><i class="fa-solid fa-stop"></i> Stop</button>'
      +  '<span class="rg-status" id="rgStatus">Pick a level, hit Listen to learn the rhythm, then Play to tap it in time.</span>'
      + '</div>'
      + '<div class="rg-tap-hint">Tap the board, click a card, or press <kbd>Space</kbd> on the beat.</div>';
    dom = {
      title: root.querySelector('#rgTitle'), sub: root.querySelector('.rg-sub'), grid: root.querySelector('#rgGrid'),
      bpm: root.querySelector('#rgBpm'), result: root.querySelector('#rgResult'), status: root.querySelector('#rgStatus'),
      start: root.querySelector('#rgStart'), board: root.querySelector('.rg-board')
    };
    var sel = root.querySelector('#rgLevel');
    sel.innerHTML = LEVELS.map(function (l, i) { return '<option value="' + i + '">' + l.name + '</option>'; }).join('');
    sel.addEventListener('change', function () { stopRun(true); level = LEVELS[parseInt(sel.value, 10)] || LEVELS[0]; buildBoard(); });
    root.querySelector('#rgListen').addEventListener('click', function () { startRun(false); });
    dom.start.addEventListener('click', function () { dom.result.classList.remove('shown'); startRun(true); });
    root.querySelector('#rgStop').addEventListener('click', function () { stopRun(); dom.result.classList.remove('shown'); });
    // Tap surfaces
    dom.board.addEventListener('pointerdown', function (e) { if (!e.target.closest('.rg-result')) tap(); });
    document.addEventListener('keydown', function (e) {
      if (e.code !== 'Space') return;
      var panel = root.closest('.tool-panel'); if (panel && !panel.classList.contains('active')) return;
      if (document.activeElement && /INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName)) return;
      e.preventDefault(); tap();
    });
    buildBoard();
  }

  function init() {
    root = document.getElementById('rhythmGame');
    if (!root || root.__rg) return; root.__rg = 1;
    try { build(); } catch (e) { if (window.console) console.error('[MAM rhythm]', e); }
    document.querySelectorAll('.tools-tabs button').forEach(function (b) {
      b.addEventListener('click', function () { if (b.getAttribute('data-tool') !== 'rhythm' && state !== 'idle') stopRun(true); });
    });
  }
  window.MAM_RHYTHM = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
