/*
 * MAM Auditer — the ten-dimension radar's tabs, the "which note is higher?" ear
 * test, and the applicant checklist. The radar and ear test follow the handoff's
 * own logic (Auditer.dc.html); the checklist keeps the previous page's storage key
 * (mam.auditer.checklist.v1) so ticks made before the redesign are still there.
 */
(function () {
  'use strict';
  var d = document;
  var $$ = function (s, el) { return Array.prototype.slice.call((el || d).querySelectorAll(s)); };

  /* ---------- the ten dimensions (sample values, handoff §5 — to be confirmed) ---------- */
  var D = [['Musical level', 70, 'Where your playing sits today on the four-level path.'], ['Technical strength', 68, 'Control, tone and accuracy on your instrument.'], ['Rhythm intelligence', 82, 'How quickly you read, feel and hold time.'], ['Ear sensitivity', 74, 'Hearing differences in pitch, tone and texture.'], ['Tonal recognition', 77, 'Recognising keys, chords and intervals.'], ['Stage confidence', 61, 'Ease and presence when someone is listening.'], ['Artistic potential', 79, 'Musical ideas, phrasing and expression.'], ['Performance readiness', 64, 'How close you are to performing a full piece.'], ['Discipline', 88, 'Focus, preparation and follow-through.'], ['Learning capacity', 85, 'How fast new ideas take hold.']];
  var tabs = $$('[data-act="dim"]');
  var svg = d.querySelector('svg[aria-label^="Sample MAM Auditer profile"]');
  var panel = d.getElementById('dim-panel');
  if (tabs.length && svg && panel) {
    var axes = $$('line[x1="200"]', svg), dots = $$('circle', svg), labels = $$('text', svg);
    var TAB = 'min-height: 40px; padding: 0 14px; border-radius: 999px; font: inherit; font-size: 14px; cursor: pointer; border: 1px solid ';
    var sel = tabs.findIndex(function (t) { return t.getAttribute('aria-selected') === 'true'; });
    var select = function (i, focus) {
      sel = i;
      tabs.forEach(function (t, k) {
        var on = k === i;
        t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
        t.setAttribute('style', TAB + (on ? '#211A13; background: #211A13; color: #FAF8F4' : '#CFC3B3; background: transparent; color: #211A13'));
        if (axes[k]) axes[k].setAttribute('stroke', on ? '#211A13' : '#D9CCBA');
        if (dots[k]) { dots[k].setAttribute('r', on ? '7' : '4'); dots[k].setAttribute('fill', on ? '#211A13' : '#C27A12'); }
        if (labels[k]) { labels[k].style.fill = on ? '#211A13' : '#574B40'; labels[k].style.fontWeight = on ? '600' : '400'; }
      });
      var box = panel.querySelector('.pnl');
      if (box) {
        box.style.animationName = i % 2 ? 'qIn' : 'qIn2';
        box.querySelector('h3').textContent = D[i][0];
        box.querySelector('h3 + span').textContent = D[i][1] + '%';
        box.querySelector('p').textContent = D[i][2];
      }
      if (focus) tabs[i].focus();
    };
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { if (sel !== i) select(i); });
      t.addEventListener('mouseenter', function () { if (sel !== i) select(i); });
      t.addEventListener('keydown', function (e) {
        var k = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0; if (!k) return;
        e.preventDefault(); select((sel + k + tabs.length) % tabs.length, true);
      });
    });
    select(sel < 0 ? 2 : sel);
  }

  /* ---------- the ear test: two tones 0.7s apart, 2–7 semitones ---------- */
  var playBtn = d.querySelector('[data-act="ear-play"]');
  if (playBtn) {
    var yes = d.querySelector('[data-act="ear-yes"]'), no = d.querySelector('[data-act="ear-no"]');
    var group = yes.parentNode, status = group.nextElementSibling, scoreEl = status.nextElementSibling.querySelector('strong');
    var ac = null, q = null, score = 0;
    var ctx = function () {
      if (!ac) { var A = window.AudioContext || window.webkitAudioContext; if (!A) return null; ac = new A(); }
      if (ac.state === 'suspended') ac.resume(); return ac;
    };
    var tone = function (f, t) {
      var o = ac.createOscillator(), o2 = ac.createOscillator(), g = ac.createGain();
      o.type = 'triangle'; o2.type = 'sine'; o.frequency.value = f; o2.frequency.value = f * 2;
      g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(0.22, t + 0.02); g.gain.exponentialRampToValueAtTime(0.001, t + 0.55);
      o.connect(g); o2.connect(g); g.connect(ac.destination); o.start(t); o2.start(t); o.stop(t + 0.6); o2.stop(t + 0.6);
    };
    var ANS = 'min-height: 56px; border-radius: 14px; font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; border: 1px solid rgba(252,248,243,';
    var paint = function () {
      var waiting = !q || q.done;
      [yes, no].forEach(function (b) {
        b.setAttribute('aria-disabled', waiting ? 'true' : 'false');
        b.setAttribute('style', ANS + (waiting ? '.2); background: rgba(252,248,243,.03); color: rgba(252,248,243,.5)' : '.55); background: rgba(252,248,243,.1); color: #FCF8F3'));
      });
      playBtn.lastChild.textContent = waiting ? (q ? 'Play a new pair' : 'Play the two notes') : 'Play again';
      scoreEl.textContent = String(score);
    };
    playBtn.addEventListener('click', function () {
      var a = ctx();
      if (!q || q.done) {
        var base = 220 * Math.pow(2, Math.floor(Math.random() * 12) / 12);
        var semis = (2 + Math.floor(Math.random() * 6)) * (Math.random() < 0.5 ? -1 : 1);
        q = { a: base, b: base * Math.pow(2, semis / 12), done: false };
      }
      if (a) { var t = a.currentTime + 0.05; tone(q.a, t); tone(q.b, t + 0.7); }
      status.textContent = a ? '' : 'Sound is not available in this browser.';
      paint();
    });
    var answer = function (first) {
      if (!q || q.done) { status.textContent = 'Play the pair first.'; return; }
      var right = first === (q.a > q.b);
      q.done = true; if (right) score++;
      status.textContent = right ? 'Correct — the ' + (first ? 'first' : 'second') + ' note was higher. Play another?'
        : 'Not quite — the ' + (q.a > q.b ? 'first' : 'second') + ' note was higher. Try another.';
      paint();
    };
    yes.addEventListener('click', function () { answer(true); });
    no.addEventListener('click', function () { answer(false); });
  }

  /* ---------- the applicant checklist ---------- */
  var KEY = 'mam.auditer.checklist.v1';
  var boxes = $$('[data-chk]'), count = d.querySelector('[data-chk-count]');
  if (boxes.length) {
    var saved = {};
    try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { saved = {}; }
    var tally = function () { if (count) count.querySelector('strong').textContent = String(boxes.filter(function (b) { return b.checked; }).length); };
    boxes.forEach(function (b) {
      b.checked = !!saved[b.getAttribute('data-chk')];
      b.addEventListener('change', function () {
        saved[b.getAttribute('data-chk')] = b.checked;
        try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) { /* private mode: ticks last for this visit */ }
        tally();
      });
    });
    tally();
  }
})();
