/**
 * MAM Academy — Studio Tools (window.MAM_STUDIO)
 * ---------------------------------------------------------------
 * Premium, zero-dependency practice tools for the student portal:
 *   • Practice tracker  — log sessions, streaks, weekly goal, 7-day chart
 *   • Metronome         — Web Audio, accents, tap-tempo, visual beat
 *   • Reference tuner   — equal-tempered reference pitches (adjustable A4)
 *   • Practice timer     — stopwatch that hands minutes to the log
 *   • Repertoire         — pieces you're learning, with progress status
 *
 * Why client-side: these are personal tools that don't need a server round
 * trip, so they work instantly and offline. State persists in localStorage,
 * namespaced per signed-in user so a shared device never bleeds data between
 * accounts. Charts use Chart.js if present, and degrade to a CSS bar fallback
 * if the CDN is blocked — nothing here ever hard-fails the dashboard.
 *
 * No external audio library: the metronome and tuner are built directly on the
 * Web Audio API (a scheduler with look-ahead for rock-solid timing), which is
 * lighter and more reliable than pulling in Tone.js.
 */
(function () {
  'use strict';

  // ---------- tiny helpers ----------
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function todayKey(){ return new Date().toISOString().slice(0,10); }
  function dayKey(d){ return d.toISOString().slice(0,10); }
  function clamp(n,lo,hi){ return Math.max(lo, Math.min(hi, n)); }

  // Per-user namespacing so a shared browser doesn't mix students' data.
  var NS = 'guest';
  function key(name){ return 'mam.studio.' + name + '.' + NS; }
  function read(name, fallback){
    try { var v = JSON.parse(localStorage.getItem(key(name))); return v == null ? fallback : v; }
    catch (e) { return fallback; }
  }
  function write(name, val){ try { localStorage.setItem(key(name), JSON.stringify(val)); } catch (e) {} }

  // ============================================================
  //  PRACTICE LOG
  // ============================================================
  var practice = {
    all: function(){ return read('practice', []); },
    goal: function(){ var g = read('practiceGoal', 150); return (typeof g === 'number' && g > 0) ? g : 150; },
    setGoal: function(min){ write('practiceGoal', clamp(parseInt(min,10)||150, 10, 5000)); },
    add: function(entry){
      var all = practice.all();
      all.unshift({
        id: Date.now() + '' + Math.floor(Math.random()*1000),
        date: entry.date || todayKey(),
        minutes: clamp(parseInt(entry.minutes,10)||0, 1, 1440),
        focus: (entry.focus || '').slice(0,80),
        notes: (entry.notes || '').slice(0,400),
        ts: Date.now()
      });
      write('practice', all.slice(0, 500));
    },
    remove: function(id){ write('practice', practice.all().filter(function(e){ return e.id !== id; })); },
    stats: function(){
      var all = practice.all();
      var byDate = {};
      all.forEach(function(e){ byDate[e.date] = (byDate[e.date]||0) + e.minutes; });
      // 7-day series ending today
      var series = [], weekMin = 0;
      for (var i = 6; i >= 0; i--) {
        var d = new Date(); d.setDate(d.getDate() - i);
        var k = dayKey(d), m = byDate[k] || 0; weekMin += m;
        series.push({ label: d.toLocaleDateString(undefined, { weekday: 'short' }), minutes: m, date: k });
      }
      // streak: consecutive days (ending today or yesterday) with practice
      var streak = 0, cur = new Date();
      if (!byDate[dayKey(cur)]) cur.setDate(cur.getDate() - 1); // allow "yesterday" so a fresh day doesn't reset
      while (byDate[dayKey(cur)]) { streak++; cur.setDate(cur.getDate() - 1); }
      var total = all.reduce(function(s,e){ return s + e.minutes; }, 0);
      var goal = practice.goal();
      return { total: total, weekMin: weekMin, todayMin: byDate[todayKey()]||0, streak: streak, series: series, goal: goal, goalPct: Math.min(100, Math.round(weekMin/goal*100)), sessions: all.length };
    }
  };

  // ============================================================
  //  REPERTOIRE
  // ============================================================
  var STAGES = ['Learning', 'Polishing', 'Performance-ready'];
  var repertoire = {
    all: function(){ return read('repertoire', []); },
    add: function(piece){
      var all = repertoire.all();
      all.unshift({ id: Date.now()+''+Math.floor(Math.random()*1000), title:(piece.title||'').slice(0,120), composer:(piece.composer||'').slice(0,80), stage: STAGES.indexOf(piece.stage)>=0 ? piece.stage : 'Learning', ts: Date.now() });
      write('repertoire', all.slice(0,200));
    },
    setStage: function(id, stage){ var all = repertoire.all(); all.forEach(function(p){ if (p.id===id && STAGES.indexOf(stage)>=0) p.stage = stage; }); write('repertoire', all); },
    remove: function(id){ write('repertoire', repertoire.all().filter(function(p){ return p.id!==id; })); }
  };

  // ============================================================
  //  AUDIO ENGINE (shared AudioContext)
  // ============================================================
  var actx = null;
  function audio(){
    if (!actx) { var AC = window.AudioContext || window.webkitAudioContext; if (AC) actx = new AC(); }
    if (actx && actx.state === 'suspended') actx.resume();
    return actx;
  }

  // ---- Metronome: look-ahead scheduler for sample-accurate timing ----
  function Metronome(opts){
    this.bpm = 90; this.beatsPerBar = 4; this.isRunning = false;
    this.onBeat = (opts && opts.onBeat) || function(){};
    this._next = 0; this._beat = 0; this._timer = null;
    this._lookahead = 25;      // ms between scheduler ticks
    this._schedAhead = 0.12;   // seconds to schedule in advance
  }
  Metronome.prototype._click = function(time, accent){
    var ac = audio(); if (!ac) return;
    var osc = ac.createOscillator(), gain = ac.createGain();
    osc.frequency.value = accent ? 1500 : 900;
    gain.gain.setValueAtTime(accent ? 0.5 : 0.32, time);
    gain.gain.exponentialRampToValueAtTime(0.0001, time + 0.05);
    osc.connect(gain).connect(ac.destination);
    osc.start(time); osc.stop(time + 0.06);
  };
  Metronome.prototype._tick = function(){
    var ac = audio(); if (!ac) return;
    while (this._next < ac.currentTime + this._schedAhead) {
      var accent = (this._beat % this.beatsPerBar) === 0;
      this._click(this._next, accent);
      var beatThis = this._beat, timeThis = this._next;
      var self = this;
      // fire the visual callback close to when the click sounds
      setTimeout(function(){ self.onBeat(beatThis % self.beatsPerBar, accent); }, Math.max(0, (timeThis - ac.currentTime) * 1000));
      this._next += 60.0 / this.bpm;
      this._beat++;
    }
  };
  Metronome.prototype.start = function(){
    var ac = audio(); if (!ac || this.isRunning) return;
    this.isRunning = true; this._beat = 0; this._next = ac.currentTime + 0.06;
    var self = this; this._tick(); this._timer = setInterval(function(){ self._tick(); }, this._lookahead);
  };
  Metronome.prototype.stop = function(){ this.isRunning = false; clearInterval(this._timer); this._timer = null; };
  Metronome.prototype.setBpm = function(b){ this.bpm = clamp(parseInt(b,10)||90, 30, 280); };

  // ---- Reference tuner: sustained equal-tempered pitches ----
  function TonePlayer(){ this.a4 = 440; this._osc = null; this._gain = null; this.current = null; }
  TonePlayer.prototype.freq = function(semitonesFromA4){ return this.a4 * Math.pow(2, semitonesFromA4/12); };
  TonePlayer.prototype.play = function(semitonesFromA4, label){
    var ac = audio(); if (!ac) return;
    this.stop();
    this._osc = ac.createOscillator(); this._gain = ac.createGain();
    this._osc.type = 'sine'; this._osc.frequency.value = this.freq(semitonesFromA4);
    this._gain.gain.setValueAtTime(0.0001, ac.currentTime);
    this._gain.gain.exponentialRampToValueAtTime(0.18, ac.currentTime + 0.03);
    this._osc.connect(this._gain).connect(ac.destination);
    this._osc.start(); this.current = label;
  };
  TonePlayer.prototype.stop = function(){
    var ac = audio();
    if (this._osc && ac) {
      try { this._gain.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.04); this._osc.stop(ac.currentTime + 0.06); } catch (e) {}
    }
    this._osc = null; this._gain = null; this.current = null;
  };

  // ============================================================
  //  RENDERERS
  // ============================================================
  function fmtMin(m){ if (m < 60) return m + ' min'; var h = Math.floor(m/60), r = m%60; return h + 'h' + (r ? ' ' + r + 'm' : ''); }

  // ---- Practice tab: tracker + goal + chart + history ----
  function renderPractice(root){
    var s = practice.stats();
    root.innerHTML =
      '<section class="kpi-row">'
      + kpi('Practice streak', s.streak + (s.streak === 1 ? ' day' : ' days'), s.streak ? 'Keep it alive — practise today' : 'Log a session to start a streak', 'gold')
      + kpi('This week', fmtMin(s.weekMin), 'Goal ' + fmtMin(s.goal) + ' · ' + s.goalPct + '%')
      + kpi('Today', fmtMin(s.todayMin), s.todayMin ? 'Nice work' : 'Not yet today')
      + kpi('All-time', fmtMin(s.total), s.sessions + ' session' + (s.sessions===1?'':'s'))
      + '</section>'
      + '<section class="panel"><div class="panel-head"><h2>Weekly goal</h2><span style="font-size:0.78rem;color:var(--text-muted)">' + s.goalPct + '% of ' + fmtMin(s.goal) + '</span></div>'
      +   '<div style="padding:18px 22px">'
      +     '<div class="studio-progress"><div class="studio-progress-fill" style="width:' + s.goalPct + '%"></div></div>'
      +     '<form id="goalForm" style="display:flex;gap:10px;align-items:center;margin-top:14px;flex-wrap:wrap">'
      +       '<label style="font-size:0.82rem;color:var(--text-muted)">Weekly minutes goal</label>'
      +       '<input id="goalInput" type="number" min="10" max="5000" step="10" value="' + s.goal + '" style="width:110px;padding:8px 10px;background:var(--bg-raised);border:1px solid var(--border-strong);border-radius:8px;color:var(--text)">'
      +       '<button class="btn" type="submit">Update goal</button>'
      +     '</form>'
      +   '</div></section>'
      + '<section class="panel"><div class="panel-head"><h2>Last 7 days</h2></div>'
      +   '<div style="padding:18px 22px"><canvas id="practiceChart" height="120" aria-label="Practice minutes for the last seven days"></canvas><div id="practiceChartFallback"></div></div>'
      + '</section>'
      + '<section class="panel"><div class="panel-head"><h2>Log a session</h2></div>'
      +   '<form id="logForm" style="padding:18px 22px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px">'
      +     '<div class="field"><label for="lg-min">Minutes</label><input id="lg-min" type="number" min="1" max="600" required placeholder="30"></div>'
      +     '<div class="field"><label for="lg-date">Date</label><input id="lg-date" type="date" value="' + todayKey() + '" required></div>'
      +     '<div class="field"><label for="lg-focus">Focus</label><input id="lg-focus" placeholder="Scales, sight-reading…" maxlength="80"></div>'
      +     '<div class="field" style="grid-column:1/-1"><label for="lg-notes">Notes</label><input id="lg-notes" placeholder="What went well? What to revisit?" maxlength="400"></div>'
      +     '<div class="actions" style="grid-column:1/-1"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus"></i> Add session</button></div>'
      +   '</form></section>'
      + '<section class="panel"><div class="panel-head"><h2>Practice history</h2></div><div id="practiceHistory"></div></section>';

    // goal form
    root.querySelector('#goalForm').addEventListener('submit', function(e){ e.preventDefault(); practice.setGoal(root.querySelector('#goalInput').value); renderPractice(root); });
    // log form
    root.querySelector('#logForm').addEventListener('submit', function(e){
      e.preventDefault();
      var m = root.querySelector('#lg-min').value;
      if (!m || parseInt(m,10) <= 0) { toast('Enter how many minutes you practised', 'error'); return; }
      practice.add({ minutes: m, date: root.querySelector('#lg-date').value, focus: root.querySelector('#lg-focus').value, notes: root.querySelector('#lg-notes').value });
      toast('Session logged — nice work!', 'success');
      renderPractice(root);
    });
    paintPracticeHistory(root.querySelector('#practiceHistory'));
    drawChart(root, s);
  }

  function paintPracticeHistory(box){
    var all = practice.all();
    if (!all.length) { box.innerHTML = empty('clock-rotate-left', 'No sessions logged yet. Add your first above — every minute counts.'); return; }
    var rows = all.slice(0, 60).map(function(e){
      return '<tr><td data-label="Date">' + esc(new Date(e.date+'T00:00:00').toLocaleDateString(undefined,{month:'short',day:'numeric'})) + '</td>'
        + '<td data-label="Minutes"><strong>' + fmtMin(e.minutes) + '</strong></td>'
        + '<td data-label="Focus">' + (e.focus ? esc(e.focus) : '<span style="color:var(--text-muted)">—</span>') + '</td>'
        + '<td data-label="Notes" style="color:var(--text-muted);font-size:0.82rem">' + esc(e.notes||'') + '</td>'
        + '<td data-label=""><button class="studio-del" data-id="' + esc(e.id) + '" title="Delete" aria-label="Delete session"><i class="fa-solid fa-trash-can"></i></button></td></tr>';
    }).join('');
    box.innerHTML = '<div class="ptable-wrap"><table class="ptable"><thead><tr><th>Date</th><th>Minutes</th><th>Focus</th><th>Notes</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    box.querySelectorAll('.studio-del').forEach(function(b){ b.addEventListener('click', function(){ practice.remove(b.getAttribute('data-id')); var rootEl = box.closest('#content') || document.getElementById('content'); renderPractice(rootEl); }); });
  }

  var _chart = null;
  function drawChart(root, s){
    var canvas = root.querySelector('#practiceChart');
    var fallback = root.querySelector('#practiceChartFallback');
    if (!canvas) return;
    if (typeof window.Chart === 'undefined') {
      // CSS bar fallback — chart lib unavailable (offline/blocked CDN)
      canvas.style.display = 'none';
      var max = Math.max(1, Math.max.apply(null, s.series.map(function(d){ return d.minutes; })));
      fallback.innerHTML = '<div class="studio-bars">' + s.series.map(function(d){
        return '<div class="studio-bar"><div class="studio-bar-fill" style="height:' + Math.round(d.minutes/max*100) + '%" title="' + d.minutes + ' min"></div><span>' + esc(d.label) + '</span></div>';
      }).join('') + '</div>';
      return;
    }
    try {
      if (_chart) { _chart.destroy(); _chart = null; }
      _chart = new window.Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: { labels: s.series.map(function(d){ return d.label; }), datasets: [{ label: 'Minutes', data: s.series.map(function(d){ return d.minutes; }), backgroundColor: 'rgba(252,198,71,0.65)', borderRadius: 6, maxBarThickness: 40 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.06)' }, ticks: { color: '#9A8264' } }, x: { grid: { display: false }, ticks: { color: '#9A8264' } } } }
      });
    } catch (e) { canvas.style.display = 'none'; }
  }

  // ---- Tools tab: metronome + tuner + practice timer ----
  var _metro = null, _tone = null, _timer = { sec: 0, running: false, h: null };
  function renderTools(root){
    root.innerHTML =
      '<section class="panel"><div class="panel-head"><h2><i class="fa-solid fa-drum" style="color:var(--gold-bright);margin-right:8px"></i>Metronome</h2></div>'
      +   '<div style="padding:22px;text-align:center">'
      +     '<div id="beatDots" class="studio-beats"></div>'
      +     '<div class="studio-bpm"><button type="button" class="studio-step" id="bpmDown">−</button>'
      +       '<div><div id="bpmVal" class="studio-bpm-val">90</div><div style="font-size:0.72rem;color:var(--text-muted);letter-spacing:0.12em">BPM</div></div>'
      +       '<button type="button" class="studio-step" id="bpmUp">+</button></div>'
      +     '<input id="bpmRange" type="range" min="30" max="280" value="90" style="width:100%;max-width:420px;margin:14px 0;accent-color:var(--gold)">'
      +     '<div style="display:flex;gap:10px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:6px">'
      +       '<label style="font-size:0.82rem;color:var(--text-muted)">Beats/bar</label>'
      +       '<select id="beatsSel" style="padding:8px 10px;background:var(--bg-raised);border:1px solid var(--border-strong);border-radius:8px;color:var(--text)"><option>2</option><option>3</option><option selected>4</option><option>5</option><option>6</option><option>7</option></select>'
      +       '<button type="button" class="btn" id="tapBtn"><i class="fa-solid fa-hand-pointer"></i> Tap</button>'
      +       '<button type="button" class="btn btn-primary" id="metroToggle"><i class="fa-solid fa-play"></i> Start</button>'
      +     '</div>'
      +   '</div></section>'
      + '<section class="panel"><div class="panel-head"><h2><i class="fa-solid fa-music" style="color:var(--gold-bright);margin-right:8px"></i>Reference tuner</h2>'
      +     '<span style="font-size:0.78rem;color:var(--text-muted)">A4 = <span id="a4val">440</span> Hz</span></div>'
      +   '<div style="padding:22px">'
      +     '<div id="noteRow" class="studio-notes"></div>'
      +     '<div style="display:flex;gap:10px;justify-content:center;align-items:center;margin-top:16px;flex-wrap:wrap">'
      +       '<label style="font-size:0.82rem;color:var(--text-muted)">Calibrate A4</label>'
      +       '<input id="a4range" type="range" min="415" max="466" value="440" style="width:200px;accent-color:var(--gold)">'
      +       '<button type="button" class="btn" id="toneStop"><i class="fa-solid fa-stop"></i> Stop tone</button>'
      +     '</div>'
      +     '<p style="text-align:center;color:var(--text-muted);font-size:0.8rem;margin:14px 0 0">Tap a note to sustain a pure reference pitch. Tap again or "Stop" to silence.</p>'
      +   '</div></section>'
      + '<section class="panel"><div class="panel-head"><h2><i class="fa-solid fa-stopwatch" style="color:var(--gold-bright);margin-right:8px"></i>Practice timer</h2></div>'
      +   '<div style="padding:22px;text-align:center">'
      +     '<div id="timerDisplay" class="studio-timer">00:00</div>'
      +     '<div style="display:flex;gap:10px;justify-content:center;margin-top:8px;flex-wrap:wrap">'
      +       '<button type="button" class="btn btn-primary" id="timerToggle"><i class="fa-solid fa-play"></i> Start</button>'
      +       '<button type="button" class="btn" id="timerLog"><i class="fa-solid fa-floppy-disk"></i> Log &amp; reset</button>'
      +       '<button type="button" class="btn" id="timerReset"><i class="fa-solid fa-rotate-left"></i> Reset</button>'
      +     '</div>'
      +     '<p style="color:var(--text-muted);font-size:0.8rem;margin:14px 0 0">"Log &amp; reset" saves the elapsed time straight to your practice history.</p>'
      +   '</div></section>';

    wireMetronome(root);
    wireTuner(root);
    wireTimer(root);
  }

  function wireMetronome(root){
    if (!_metro) _metro = new Metronome({});
    var dots = root.querySelector('#beatDots');
    function paintDots(active){
      var n = _metro.beatsPerBar, html = '';
      for (var i=0;i<n;i++) html += '<span class="studio-beat-dot' + (i===active?' on':'') + (i===0?' accent':'') + '"></span>';
      dots.innerHTML = html;
    }
    _metro.onBeat = function(beat){ paintDots(beat); };
    paintDots(-1);
    var bpmVal = root.querySelector('#bpmVal'), range = root.querySelector('#bpmRange');
    function setBpm(b){ _metro.setBpm(b); bpmVal.textContent = _metro.bpm; range.value = _metro.bpm; }
    setBpm(_metro.bpm);
    root.querySelector('#bpmUp').addEventListener('click', function(){ setBpm(_metro.bpm + 1); });
    root.querySelector('#bpmDown').addEventListener('click', function(){ setBpm(_metro.bpm - 1); });
    range.addEventListener('input', function(){ setBpm(range.value); });
    root.querySelector('#beatsSel').addEventListener('change', function(e){ _metro.beatsPerBar = parseInt(e.target.value,10)||4; paintDots(-1); });
    var toggle = root.querySelector('#metroToggle');
    toggle.addEventListener('click', function(){
      if (_metro.isRunning) { _metro.stop(); toggle.innerHTML = '<i class="fa-solid fa-play"></i> Start'; toggle.classList.remove('is-running'); paintDots(-1); }
      else { _metro.start(); toggle.innerHTML = '<i class="fa-solid fa-stop"></i> Stop'; toggle.classList.add('is-running'); }
    });
    // tap tempo
    var taps = [];
    root.querySelector('#tapBtn').addEventListener('click', function(){
      var now = performance.now(); taps.push(now); taps = taps.filter(function(t){ return now - t < 3000; });
      if (taps.length >= 2) {
        var diffs = []; for (var i=1;i<taps.length;i++) diffs.push(taps[i]-taps[i-1]);
        var avg = diffs.reduce(function(a,b){return a+b;},0)/diffs.length;
        setBpm(Math.round(60000/avg));
      }
    });
  }

  function wireTuner(root){
    if (!_tone) _tone = new TonePlayer();
    // One octave from A3(-12) up to G#4 etc. We index relative to A4 (0).
    var notes = [
      {n:'C', s:-9},{n:'C♯',s:-8},{n:'D',s:-7},{n:'D♯',s:-6},{n:'E',s:-5},{n:'F',s:-4},
      {n:'F♯',s:-3},{n:'G',s:-2},{n:'G♯',s:-1},{n:'A',s:0},{n:'A♯',s:1},{n:'B',s:2}
    ];
    var row = root.querySelector('#noteRow');
    row.innerHTML = notes.map(function(x){ return '<button type="button" class="studio-note" data-s="' + x.s + '" data-n="' + esc(x.n) + '">' + esc(x.n) + '</button>'; }).join('');
    function clearActive(){ row.querySelectorAll('.studio-note').forEach(function(b){ b.classList.remove('on'); }); }
    row.querySelectorAll('.studio-note').forEach(function(b){
      b.addEventListener('click', function(){
        var label = b.getAttribute('data-n');
        if (_tone.current === label) { _tone.stop(); clearActive(); return; }
        _tone.play(parseInt(b.getAttribute('data-s'),10), label); clearActive(); b.classList.add('on');
      });
    });
    var a4 = root.querySelector('#a4range'), a4val = root.querySelector('#a4val');
    a4.addEventListener('input', function(){ _tone.a4 = parseInt(a4.value,10)||440; a4val.textContent = _tone.a4; if (_tone._osc) { var act = row.querySelector('.studio-note.on'); if (act) _tone.play(parseInt(act.getAttribute('data-s'),10), act.getAttribute('data-n')); } });
    root.querySelector('#toneStop').addEventListener('click', function(){ _tone.stop(); clearActive(); });
  }

  function wireTimer(root){
    var disp = root.querySelector('#timerDisplay'), toggle = root.querySelector('#timerToggle');
    function fmt(){ var m = Math.floor(_timer.sec/60), s = _timer.sec%60; disp.textContent = (m<10?'0':'')+m+':'+(s<10?'0':'')+s; }
    fmt();
    toggle.addEventListener('click', function(){
      if (_timer.running) { _timer.running = false; clearInterval(_timer.h); toggle.innerHTML = '<i class="fa-solid fa-play"></i> Resume'; }
      else { _timer.running = true; toggle.innerHTML = '<i class="fa-solid fa-pause"></i> Pause'; _timer.h = setInterval(function(){ _timer.sec++; fmt(); }, 1000); }
    });
    root.querySelector('#timerReset').addEventListener('click', function(){ _timer.running=false; clearInterval(_timer.h); _timer.sec=0; fmt(); toggle.innerHTML = '<i class="fa-solid fa-play"></i> Start'; });
    root.querySelector('#timerLog').addEventListener('click', function(){
      var mins = Math.round(_timer.sec/60);
      if (mins < 1) { toast('Timer needs at least a minute to log', 'error'); return; }
      practice.add({ minutes: mins, focus: 'Timed practice' });
      _timer.running=false; clearInterval(_timer.h); _timer.sec=0; fmt(); toggle.innerHTML = '<i class="fa-solid fa-play"></i> Start';
      toast(fmtMin(mins) + ' logged to your practice history', 'success');
    });
  }

  // ---- Repertoire tab ----
  function renderRepertoire(root){
    var all = repertoire.all();
    var list = all.length ? all.map(function(p){
      var opts = STAGES.map(function(st){ return '<option' + (st===p.stage?' selected':'') + '>' + st + '</option>'; }).join('');
      return '<div class="studio-piece">'
        + '<div style="flex:1;min-width:0"><div class="studio-piece-title">' + esc(p.title) + '</div>'
        + (p.composer ? '<div class="studio-piece-sub">' + esc(p.composer) + '</div>' : '') + '</div>'
        + '<select class="studio-stage stage-' + esc(p.stage.toLowerCase().replace(/[^a-z]/g,'')) + '" data-id="' + esc(p.id) + '">' + opts + '</select>'
        + '<button class="studio-del" data-id="' + esc(p.id) + '" aria-label="Remove piece"><i class="fa-solid fa-trash-can"></i></button>'
        + '</div>';
    }).join('') : empty('book-open', 'No pieces yet. Add what you\'re working on to track it from learning to performance-ready.');
    root.innerHTML =
      '<section class="panel"><div class="panel-head"><h2>Add a piece</h2></div>'
      + '<form id="repForm" style="padding:18px 22px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px">'
      +   '<div class="field"><label for="rp-title">Title</label><input id="rp-title" required placeholder="Clair de Lune" maxlength="120"></div>'
      +   '<div class="field"><label for="rp-composer">Composer / artist</label><input id="rp-composer" placeholder="Debussy" maxlength="80"></div>'
      +   '<div class="field"><label for="rp-stage">Stage</label><select id="rp-stage">' + STAGES.map(function(s){return '<option>'+s+'</option>';}).join('') + '</select></div>'
      +   '<div class="actions" style="grid-column:1/-1"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus"></i> Add piece</button></div>'
      + '</form></section>'
      + '<section class="panel"><div class="panel-head"><h2>Your repertoire</h2><span style="font-size:0.78rem;color:var(--text-muted)">' + all.length + ' piece' + (all.length===1?'':'s') + '</span></div>'
      +   '<div style="padding:14px 22px">' + list + '</div></section>';

    root.querySelector('#repForm').addEventListener('submit', function(e){
      e.preventDefault();
      var t = root.querySelector('#rp-title').value.trim();
      if (!t) { toast('Give the piece a title', 'error'); return; }
      repertoire.add({ title: t, composer: root.querySelector('#rp-composer').value, stage: root.querySelector('#rp-stage').value });
      renderRepertoire(root);
    });
    root.querySelectorAll('.studio-stage').forEach(function(sel){ sel.addEventListener('change', function(){ repertoire.setStage(sel.getAttribute('data-id'), sel.value); renderRepertoire(root); }); });
    root.querySelectorAll('.studio-del').forEach(function(b){ b.addEventListener('click', function(){ repertoire.remove(b.getAttribute('data-id')); renderRepertoire(root); }); });
  }

  // ---- shared UI bits (mirror portal.js look) ----
  function kpi(label, value, sub, cls){ return '<div class="kpi"><div class="kpi-label">' + esc(label) + '</div><div class="kpi-value ' + (cls||'') + '">' + esc(value) + '</div><div class="kpi-sub">' + esc(sub||'') + '</div></div>'; }
  function empty(icon, text){ return '<div class="empty-state"><i class="fa-solid fa-' + icon + '"></i>' + text + '</div>'; }
  function toast(msg, type){ if (window.MAM_DIALOG && window.MAM_DIALOG.toast) window.MAM_DIALOG.toast(msg, type); }

  // Stop audio when leaving the tools tab / page so nothing keeps sounding.
  function stopAudio(){ if (_metro && _metro.isRunning) _metro.stop(); if (_tone) _tone.stop(); }
  window.addEventListener('pagehide', stopAudio);

  // ---- public API ----
  window.MAM_STUDIO = {
    setUser: function(email){ NS = (email || 'guest').toLowerCase().replace(/[^a-z0-9@._-]/g,''); },
    practiceStats: function(){ return practice.stats(); },
    repertoireCount: function(){ return repertoire.all().length; },
    renderPractice: renderPractice,
    renderTools: renderTools,
    renderRepertoire: renderRepertoire,
    stopAudio: stopAudio
  };
})();
