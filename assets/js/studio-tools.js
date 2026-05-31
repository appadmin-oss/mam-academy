/**
 * MAM Academy — Studio Tools (window.MAM_STUDIO)
 * ---------------------------------------------------------------
 * Practice-management tools for the student portal:
 *   • Practice tracker  — log sessions, streaks, weekly goal, 7-day chart, history
 *   • Practice timer    — times a session and logs it straight to the tracker
 *   • Repertoire        — pieces you're learning, with a progress stage
 *
 * The audio tools (metronome, chromatic tuner, Pomodoro, ear-training) live in
 * the canonical Practice Studio (/studio → tools.html). The portal links to it
 * rather than shipping a second, divergent implementation — one enterprise-grade
 * suite, not two.
 *
 * State persists in localStorage, namespaced per signed-in user so a shared
 * device never bleeds data between accounts. Dates are keyed in LOCAL time so a
 * session always lands on the calendar day the student actually practised.
 * Charts use Chart.js when it's available and otherwise fall back to CSS bars,
 * so the dashboard never shows an empty box and never hard-fails.
 */
(function () {
  'use strict';

  // ---------- tiny helpers ----------
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  // LOCAL calendar-day key (NOT UTC) — keeps practice on the right day in every timezone.
  function dayKey(d){ var y=d.getFullYear(), m=d.getMonth()+1, day=d.getDate(); return y+'-'+(m<10?'0':'')+m+'-'+(day<10?'0':'')+day; }
  function todayKey(){ return dayKey(new Date()); }
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
    all: function(){ var a = read('practice', []); return Array.isArray(a) ? a : []; },
    goal: function(){ var g = read('practiceGoal', 150); return (typeof g === 'number' && g > 0) ? g : 150; },
    setGoal: function(min){ write('practiceGoal', clamp(parseInt(min,10)||150, 10, 5000)); },
    add: function(entry){
      var all = practice.all();
      all.unshift({
        id: Date.now() + '' + Math.floor(Math.random()*1000),
        date: /^\d{4}-\d{2}-\d{2}$/.test(entry.date || '') ? entry.date : todayKey(),
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
      all.forEach(function(e){ byDate[e.date] = (byDate[e.date]||0) + (parseInt(e.minutes,10)||0); });
      // 7-day series ending today (local)
      var series = [], weekMin = 0;
      for (var i = 6; i >= 0; i--) {
        var d = new Date(); d.setDate(d.getDate() - i);
        var k = dayKey(d), m = byDate[k] || 0; weekMin += m;
        series.push({ label: d.toLocaleDateString(undefined, { weekday: 'short' }), minutes: m, date: k });
      }
      // streak: consecutive days (ending today, or yesterday so a fresh day doesn't reset it)
      var streak = 0, cur = new Date();
      if (!byDate[dayKey(cur)]) cur.setDate(cur.getDate() - 1);
      while (byDate[dayKey(cur)]) { streak++; cur.setDate(cur.getDate() - 1); }
      var total = all.reduce(function(s,e){ return s + (parseInt(e.minutes,10)||0); }, 0);
      var goal = practice.goal();
      return { total: total, weekMin: weekMin, todayMin: byDate[todayKey()]||0, streak: streak, series: series, goal: goal, goalPct: Math.min(100, Math.round(weekMin/goal*100)), sessions: all.length };
    }
  };

  // ============================================================
  //  REPERTOIRE
  // ============================================================
  var STAGES = ['Learning', 'Polishing', 'Performance-ready'];
  var repertoire = {
    all: function(){ var a = read('repertoire', []); return Array.isArray(a) ? a : []; },
    add: function(piece){
      var all = repertoire.all();
      all.unshift({ id: Date.now()+''+Math.floor(Math.random()*1000), title:(piece.title||'').slice(0,120), composer:(piece.composer||'').slice(0,80), stage: STAGES.indexOf(piece.stage)>=0 ? piece.stage : 'Learning', ts: Date.now() });
      write('repertoire', all.slice(0,200));
    },
    setStage: function(id, stage){ var all = repertoire.all(); all.forEach(function(p){ if (p.id===id && STAGES.indexOf(stage)>=0) p.stage = stage; }); write('repertoire', all); },
    remove: function(id){ write('repertoire', repertoire.all().filter(function(p){ return p.id!==id; })); }
  };

  // ============================================================
  //  RENDERERS
  // ============================================================
  function fmtMin(m){ m = parseInt(m,10)||0; if (m < 60) return m + ' min'; var h = Math.floor(m/60), r = m%60; return h + 'h' + (r ? ' ' + r + 'm' : ''); }
  function rootEl(){ return document.getElementById('content'); }

  // Practice timer state (no audio — purely a stopwatch that feeds the log).
  var _timer = { sec: 0, running: false, h: null };

  // ---- Practice tab: tracker + goal + chart + timer + history ----
  function renderPractice(root){
    root = root || rootEl(); if (!root) return;
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
      +       '<label for="goalInput" style="font-size:0.82rem;color:var(--text-muted)">Weekly minutes goal</label>'
      +       '<input id="goalInput" type="number" min="10" max="5000" step="10" value="' + s.goal + '" style="width:110px;padding:8px 10px;background:var(--bg-raised);border:1px solid var(--border-strong);border-radius:8px;color:var(--text)">'
      +       '<button class="btn" type="submit">Update goal</button>'
      +     '</form>'
      +   '</div></section>'
      + '<section class="panel"><div class="panel-head"><h2>Last 7 days</h2></div>'
      +   '<div style="padding:18px 22px"><div style="position:relative;height:160px"><canvas id="practiceChart" aria-label="Practice minutes for the last seven days"></canvas></div><div id="practiceChartFallback"></div></div>'
      + '</section>'
      + '<section class="panel"><div class="panel-head"><h2>Log a session</h2></div>'
      +   '<div style="padding:18px 22px 0"><div class="studio-timer-bar">'
      +     '<div><span class="studio-timer-mini" id="timerDisplay">00:00</span> <span style="color:var(--text-muted);font-size:0.82rem">live timer</span></div>'
      +     '<div style="display:flex;gap:8px;flex-wrap:wrap">'
      +       '<button type="button" class="btn" id="timerToggle"><i class="fa-solid fa-play"></i> Start</button>'
      +       '<button type="button" class="btn btn-primary" id="timerLog"><i class="fa-solid fa-floppy-disk"></i> Log &amp; reset</button>'
      +       '<button type="button" class="btn" id="timerReset"><i class="fa-solid fa-rotate-left"></i> Reset</button>'
      +     '</div>'
      +   '</div></div>'
      +   '<form id="logForm" style="padding:16px 22px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px">'
      +     '<div class="field"><label for="lg-min">Minutes</label><input id="lg-min" type="number" min="1" max="600" required placeholder="30"></div>'
      +     '<div class="field"><label for="lg-date">Date</label><input id="lg-date" type="date" value="' + todayKey() + '" max="' + todayKey() + '" required></div>'
      +     '<div class="field"><label for="lg-focus">Focus</label><input id="lg-focus" placeholder="Scales, sight-reading…" maxlength="80"></div>'
      +     '<div class="field" style="grid-column:1/-1"><label for="lg-notes">Notes</label><input id="lg-notes" placeholder="What went well? What to revisit?" maxlength="400"></div>'
      +     '<div class="actions" style="grid-column:1/-1"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus"></i> Add session</button></div>'
      +   '</form></section>'
      + '<section class="panel"><div class="panel-head"><h2>Practice history</h2></div><div id="practiceHistory"></div></section>'
      + '<div class="studio-cta"><div><strong>Need a metronome, tuner or ear-training?</strong><span>Open the full Practice Studio — pro metronome, microphone tuner, Pomodoro &amp; more.</span></div>'
      +   '<a class="btn btn-primary" href="/studio" target="_blank" rel="noopener"><i class="fa-solid fa-wand-magic-sparkles"></i> Open Practice Studio</a></div>';

    root.querySelector('#goalForm').addEventListener('submit', function(e){ e.preventDefault(); practice.setGoal(root.querySelector('#goalInput').value); renderPractice(root); });
    root.querySelector('#logForm').addEventListener('submit', function(e){
      e.preventDefault();
      var m = parseInt(root.querySelector('#lg-min').value, 10);
      if (!m || m <= 0) { toast('Enter how many minutes you practised', 'error'); return; }
      practice.add({ minutes: m, date: root.querySelector('#lg-date').value, focus: root.querySelector('#lg-focus').value, notes: root.querySelector('#lg-notes').value });
      toast('Session logged — nice work!', 'success');
      renderPractice(root);
    });
    wireTimer(root);
    paintPracticeHistory(root.querySelector('#practiceHistory'));
    drawChart(root, s);
  }

  function wireTimer(root){
    var disp = root.querySelector('#timerDisplay'), toggle = root.querySelector('#timerToggle');
    function fmt(){ var m = Math.floor(_timer.sec/60), x = _timer.sec%60; disp.textContent = (m<10?'0':'')+m+':'+(x<10?'0':'')+x; }
    function setBtn(){ toggle.innerHTML = _timer.running ? '<i class="fa-solid fa-pause"></i> Pause' : (_timer.sec ? '<i class="fa-solid fa-play"></i> Resume' : '<i class="fa-solid fa-play"></i> Start'); }
    fmt(); setBtn();
    if (_timer.running) { clearInterval(_timer.h); _timer.h = setInterval(function(){ _timer.sec++; fmt(); }, 1000); } // survive re-render
    toggle.addEventListener('click', function(){
      if (_timer.running) { _timer.running = false; clearInterval(_timer.h); }
      else { _timer.running = true; _timer.h = setInterval(function(){ _timer.sec++; fmt(); }, 1000); }
      setBtn();
    });
    root.querySelector('#timerReset').addEventListener('click', function(){ _timer.running=false; clearInterval(_timer.h); _timer.sec=0; fmt(); setBtn(); });
    root.querySelector('#timerLog').addEventListener('click', function(){
      var mins = Math.round(_timer.sec/60);
      if (mins < 1) { toast('Timer needs at least a minute to log', 'error'); return; }
      practice.add({ minutes: mins, focus: 'Timed practice' });
      _timer.running=false; clearInterval(_timer.h); _timer.sec=0;
      toast(fmtMin(mins) + ' logged to your practice history', 'success');
      renderPractice(root);
    });
  }

  function paintPracticeHistory(box){
    if (!box) return;
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
    box.querySelectorAll('.studio-del').forEach(function(b){ b.addEventListener('click', function(){ practice.remove(b.getAttribute('data-id')); renderPractice(rootEl()); }); });
  }

  // Progressive enhancement: paint CSS bars immediately, upgrade to Chart.js
  // when the deferred CDN library finishes loading (give up after ~3s).
  function whenChart(cb, tries){
    if (typeof window.Chart !== 'undefined') return cb(true);
    if ((tries||0) >= 25) return cb(false);
    setTimeout(function(){ whenChart(cb, (tries||0)+1); }, 120);
  }
  var _chart = null;
  function drawChart(root, s){
    var canvas = root.querySelector('#practiceChart');
    var fallback = root.querySelector('#practiceChartFallback');
    if (!canvas || !fallback) return;
    function cssBars(){
      canvas.style.display = 'none';
      var max = Math.max(1, Math.max.apply(null, s.series.map(function(d){ return d.minutes; })));
      fallback.innerHTML = '<div class="studio-bars">' + s.series.map(function(d){
        return '<div class="studio-bar"><div class="studio-bar-fill" style="height:' + Math.round(d.minutes/max*100) + '%" title="' + d.minutes + ' min"></div><span>' + esc(d.label) + '</span></div>';
      }).join('') + '</div>';
    }
    cssBars(); // never leave an empty box
    whenChart(function(ok){
      if (!ok || !document.body.contains(canvas)) return; // CDN blocked, or user navigated away
      try {
        if (_chart) { _chart.destroy(); _chart = null; }
        fallback.innerHTML = ''; canvas.style.display = '';
        _chart = new window.Chart(canvas.getContext('2d'), {
          type: 'bar',
          data: { labels: s.series.map(function(d){ return d.label; }), datasets: [{ label: 'Minutes', data: s.series.map(function(d){ return d.minutes; }), backgroundColor: 'rgba(252,198,71,0.65)', borderRadius: 6, maxBarThickness: 40 }] },
          options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.06)' }, ticks: { color: '#9A8264', precision: 0 } }, x: { grid: { display: false }, ticks: { color: '#9A8264' } } } }
        });
      } catch (e) { cssBars(); }
    });
  }

  // ---- Repertoire tab ----
  function renderRepertoire(root){
    root = root || rootEl(); if (!root) return;
    var all = repertoire.all();
    var list = all.length ? all.map(function(p){
      var opts = STAGES.map(function(st){ return '<option' + (st===p.stage?' selected':'') + '>' + st + '</option>'; }).join('');
      return '<div class="studio-piece">'
        + '<div style="flex:1;min-width:0"><div class="studio-piece-title">' + esc(p.title) + '</div>'
        + (p.composer ? '<div class="studio-piece-sub">' + esc(p.composer) + '</div>' : '') + '</div>'
        + '<select class="studio-stage stage-' + esc(p.stage.toLowerCase().replace(/[^a-z]/g,'')) + '" data-id="' + esc(p.id) + '" aria-label="Progress stage">' + opts + '</select>'
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
  function empty(icon, text){ return '<div class="empty-state"><i class="fa-solid fa-' + icon + '"></i>' + esc(text) + '</div>'; }
  function toast(msg, type){ if (window.MAM_DIALOG && window.MAM_DIALOG.toast) window.MAM_DIALOG.toast(msg, type); }

  // ---- public API ----
  window.MAM_STUDIO = {
    setUser: function(email){ NS = (email || 'guest').toLowerCase().replace(/[^a-z0-9@._-]/g,'') || 'guest'; },
    practiceStats: function(){ return practice.stats(); },
    repertoireCount: function(){ return repertoire.all().length; },
    renderPractice: renderPractice,
    renderRepertoire: renderRepertoire
  };
})();
