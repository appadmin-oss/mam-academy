/* ============================================================
   MAM Practice Player (Advanced Modernization §1.2 / §1.6)
   ------------------------------------------------------------
   A music-native audio player built for practice, not playback:
     • Waveform view (§1.6) drawn from decoded audio, click to seek.
     • Tempo 50–100% without pitch change (preservesPitch).
     • A–B loop for drilling a hard passage.
     • Metronome count-in before playback.
     • Timestamped markers (§1.1) rendered on the waveform; taps jump.
       Instructor mode exposes "add marker at playhead" via onAddMarker.

   Framework-free. window.MAM_PLAYER.mount(el, opts) → controller.
   opts: { src, title?, markers?:[{at,label}], canAddMarker?, onAddMarker?(at) }
   Waveform decode needs the audio fetchable (same-origin/CORS); on
   failure it degrades to a plain seek bar — playback always works.
   ============================================================ */
(function () {
  'use strict';
  if (window.MAM_PLAYER) { return; }

  var _actx = null;
  function actx() { try { if (!_actx) _actx = new (window.AudioContext || window.webkitAudioContext)(); return _actx; } catch (e) { return null; } }
  function cssVar(name, fallback) {
    try { var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim(); return v || fallback; } catch (e) { return fallback; }
  }
  function fmtTime(s) {
    if (!isFinite(s) || s < 0) s = 0;
    var m = Math.floor(s / 60), r = Math.floor(s % 60);
    return m + ':' + (r < 10 ? '0' : '') + r;
  }
  function esc(x) { return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function playCountIn(beats, bpm, done) {
    var ctx = actx();
    if (!ctx) { done(); return; }
    if (ctx.state === 'suspended') { try { ctx.resume(); } catch (e) {} }
    var spb = 60 / (bpm || 90), t0 = ctx.currentTime + 0.05;
    for (var i = 0; i < beats; i++) {
      var osc = ctx.createOscillator(), g = ctx.createGain(), t = t0 + i * spb;
      osc.frequency.value = (i === 0) ? 1320 : 880;
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(0.4, t + 0.005);
      g.gain.exponentialRampToValueAtTime(0.0001, t + 0.09);
      osc.connect(g); g.connect(ctx.destination);
      osc.start(t); osc.stop(t + 0.1);
    }
    setTimeout(done, beats * spb * 1000);
  }

  function mount(el, opts) {
    opts = opts || {};
    var markers = (opts.markers || []).slice();
    var loopA = null, loopB = null, peaks = null;

    el.classList.add('mp');
    el.innerHTML =
      '<div class="mp-top">'
      + '<button type="button" class="mp-play" aria-label="Play"><i class="fa-solid fa-play"></i></button>'
      + '<div class="mp-wave"><canvas></canvas><div class="mp-markers"></div></div>'
      + '<div class="mp-time"><span class="mp-cur">0:00</span> / <span class="mp-dur">0:00</span></div>'
      + '</div>'
      + '<div class="mp-ctl">'
      + '<label class="mp-tempo">Tempo <output class="mp-tempo-val">100%</output>'
      + '<input type="range" min="50" max="100" step="5" value="100"></label>'
      + '<div class="mp-loop"><button type="button" data-loop="a" title="Set loop start">A</button>'
      + '<button type="button" data-loop="b" title="Set loop end">B</button>'
      + '<button type="button" data-loop="clear" title="Clear loop">✕</button></div>'
      + '<label class="mp-count"><input type="checkbox"> Count-in</label>'
      + (opts.canAddMarker ? '<button type="button" class="mp-addmark" title="Comment at playhead"><i class="fa-solid fa-location-dot"></i> Add note</button>' : '')
      + '</div>'
      + '<audio preload="metadata" crossorigin="anonymous" src="' + esc(opts.src) + '"></audio>';

    var audio = el.querySelector('audio');
    var playBtn = el.querySelector('.mp-play');
    var canvas = el.querySelector('canvas');
    var waveWrap = el.querySelector('.mp-wave');
    var markHost = el.querySelector('.mp-markers');
    var curEl = el.querySelector('.mp-cur'), durEl = el.querySelector('.mp-dur');
    var tempo = el.querySelector('.mp-tempo input'), tempoVal = el.querySelector('.mp-tempo-val');
    var countBox = el.querySelector('.mp-count input');
    try { audio.preservesPitch = true; audio.mozPreservesPitch = true; audio.webkitPreservesPitch = true; } catch (e) {}

    function drawWave() {
      var w = canvas.clientWidth || waveWrap.clientWidth || 480, h = canvas.clientHeight || 56;
      var dpr = window.devicePixelRatio || 1;
      canvas.width = w * dpr; canvas.height = h * dpr;
      var c = canvas.getContext('2d'); c.scale(dpr, dpr);
      c.clearRect(0, 0, w, h);
      var mid = h / 2;
      var played = (audio.duration ? (audio.currentTime / audio.duration) : 0) * w;
      var accent = cssVar('--gold', '#C79A3A'), base = cssVar('--border', '#3a4150');
      if (peaks && peaks.length) {
        var bars = peaks.length, bw = w / bars;
        for (var i = 0; i < bars; i++) {
          var ph = Math.max(1, peaks[i] * (h * 0.9));
          c.fillStyle = (i * bw <= played) ? accent : base;
          c.fillRect(i * bw, mid - ph / 2, Math.max(1, bw - 1), ph);
        }
      } else {
        // Fallback: a simple track + progress (no decoded waveform).
        c.fillStyle = base; c.fillRect(0, mid - 2, w, 4);
        c.fillStyle = accent; c.fillRect(0, mid - 2, played, 4);
      }
    }

    function renderMarkers() {
      if (!markers.length || !audio.duration) { markHost.innerHTML = ''; return; }
      markHost.innerHTML = markers.map(function (m, i) {
        var left = Math.max(0, Math.min(100, (m.at / audio.duration) * 100));
        return '<button type="button" class="mp-mark" data-i="' + i + '" style="left:' + left + '%" title="' + esc(m.label || '') + ' — ' + fmtTime(m.at) + '"></button>';
      }).join('');
    }

    // Decode for the waveform (best-effort; playback never depends on it).
    function loadPeaks() {
      if (!window.fetch || !window.AudioContext && !window.webkitAudioContext) { return; }
      fetch(opts.src, { mode: 'cors' }).then(function (r) { return r.arrayBuffer(); }).then(function (buf) {
        var ctx = actx(); if (!ctx) return;
        return ctx.decodeAudioData(buf);
      }).then(function (audioBuf) {
        if (!audioBuf) return;
        var ch = audioBuf.getChannelData(0), bars = 160, block = Math.floor(ch.length / bars), out = [];
        var max = 0;
        for (var i = 0; i < bars; i++) {
          var sum = 0;
          for (var j = 0; j < block; j++) { var v = ch[i * block + j] || 0; sum += v * v; }
          var rms = Math.sqrt(sum / block); out.push(rms); if (rms > max) max = rms;
        }
        peaks = out.map(function (v) { return max ? v / max : 0; });
        drawWave();
      }).catch(function () { /* CORS or decode fail → keep the fallback bar */ });
    }

    function toggle() {
      if (audio.paused) {
        var go = function () { audio.play().catch(function () {}); };
        if (countBox.checked) { playBtn.disabled = true; playCountIn(4, 90, function () { playBtn.disabled = false; go(); }); }
        else { go(); }
      } else { audio.pause(); }
    }
    function seekFromEvent(e) {
      var rect = waveWrap.getBoundingClientRect();
      var x = ((e.touches ? e.touches[0].clientX : e.clientX) - rect.left) / rect.width;
      if (audio.duration) audio.currentTime = Math.max(0, Math.min(1, x)) * audio.duration;
    }

    playBtn.addEventListener('click', toggle);
    waveWrap.addEventListener('click', function (e) {
      if (e.target.closest('.mp-mark')) { var i = +e.target.closest('.mp-mark').getAttribute('data-i'); if (markers[i]) audio.currentTime = markers[i].at; return; }
      seekFromEvent(e);
    });
    audio.addEventListener('loadedmetadata', function () { durEl.textContent = fmtTime(audio.duration); drawWave(); renderMarkers(); });
    audio.addEventListener('timeupdate', function () {
      curEl.textContent = fmtTime(audio.currentTime);
      if (loopA != null && loopB != null && audio.currentTime >= loopB) { audio.currentTime = loopA; }
      drawWave();
    });
    audio.addEventListener('play', function () { playBtn.innerHTML = '<i class="fa-solid fa-pause"></i>'; playBtn.setAttribute('aria-label', 'Pause'); });
    audio.addEventListener('pause', function () { playBtn.innerHTML = '<i class="fa-solid fa-play"></i>'; playBtn.setAttribute('aria-label', 'Play'); });
    audio.addEventListener('ended', function () { playBtn.innerHTML = '<i class="fa-solid fa-play"></i>'; });
    tempo.addEventListener('input', function () { audio.playbackRate = tempo.value / 100; tempoVal.textContent = tempo.value + '%'; });
    el.querySelectorAll('[data-loop]').forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-loop');
        if (k === 'a') { loopA = audio.currentTime; if (loopB != null && loopB <= loopA) loopB = null; }
        else if (k === 'b') { loopB = audio.currentTime; if (loopA != null && loopB <= loopA) loopA = null; }
        else { loopA = loopB = null; }
        el.querySelector('[data-loop="a"]').classList.toggle('on', loopA != null);
        el.querySelector('[data-loop="b"]').classList.toggle('on', loopB != null);
      });
    });
    var addBtn = el.querySelector('.mp-addmark');
    if (addBtn && typeof opts.onAddMarker === 'function') {
      addBtn.addEventListener('click', function () { opts.onAddMarker(audio.currentTime, ctrl); });
    }
    window.addEventListener('resize', drawWave);
    loadPeaks();

    var ctrl = {
      el: el, audio: audio,
      seek: function (t) { audio.currentTime = t; },
      time: function () { return audio.currentTime; },
      setMarkers: function (list) { markers = (list || []).slice(); renderMarkers(); },
      addMarker: function (m) { markers.push(m); renderMarkers(); },
    };
    return ctrl;
  }

  window.MAM_PLAYER = { mount: mount };
})();
