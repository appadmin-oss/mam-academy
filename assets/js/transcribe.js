/*! MAM Academy — Practice Studio · Transcribe (Klangio audio → notation)
 * ---------------------------------------------------------------------------
 * Record or upload a short clip, send it to our server-side Klangio proxy
 * (/api/transcribe), poll for the result, then show a note-roll preview plus
 * MIDI / MusicXML downloads.
 *
 * Robust by design — every external touch-point (mic, MediaRecorder, FileReader,
 * network, JSON) is guarded, so a missing mic, denied permission, signed-out
 * user, unconfigured server, or provider hiccup yields a friendly message, never
 * an uncaught error. Auth + CSRF + rate limiting are enforced server-side.
 */
(function () {
  'use strict';

  function init() {
    var panel = document.querySelector('.tool-panel[data-tool="transcribe"]');
    if (!panel) return;
    var $ = function (id) { return document.getElementById(id); };
    var elModel = $('trModel'), elRec = $('trRec'), elRecLabel = $('trRecLabel'),
        elUploadBtn = $('trUploadBtn'), elFile = $('trFile'), elClip = $('trClip'),
        elAudio = $('trAudio'), elClipName = $('trClipName'), elClipX = $('trClipX'),
        elGo = $('trGo'), elHint = $('trHint'), elResult = $('trResult');
    if (!elGo || !elResult) return;

    var API = window.MAM_API;
    var API_BASE = (window.MAM_CONFIG && window.MAM_CONFIG.api && window.MAM_CONFIG.api.base) || '/api';
    var MAX_BYTES = 8 * 1024 * 1024, MAX_SEC = 30;

    var clipBlob = null, clipUrl = null;        // current audio
    var recorder = null, recStream = null, recChunks = [], recTimer = null, recStart = 0;
    var polling = false, pollStop = false;

    if (!API || typeof API.request !== 'function') {
      elGo.disabled = true;
      message('info', 'fa-circle-info', 'Transcription is unavailable on this page.');
      return;
    }

    /* ── input: recording ── */
    function canRecord() {
      return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia &&
                window.MediaRecorder && typeof MediaRecorder.isTypeSupported === 'function');
    }
    function pickMime() {
      var c = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/ogg', 'audio/mp4'];
      for (var i = 0; i < c.length; i++) { try { if (MediaRecorder.isTypeSupported(c[i])) return c[i]; } catch (e) {} }
      return '';
    }
    if (!canRecord()) {
      if (elRec) elRec.style.display = 'none';
      var orEl = panel.querySelector('.tr-or'); if (orEl) orEl.style.display = 'none';
    }

    function stopStream() {
      if (recStream) { try { recStream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} recStream = null; }
    }
    function tickRec() {
      var s = Math.floor((Date.now() - recStart) / 1000);
      if (elRecLabel) elRecLabel.textContent = 'Stop · ' + s + 's';
      if (s >= MAX_SEC) stopRecording();
    }
    function startRecording() {
      if (recorder) return;
      message('info', 'fa-microphone', 'Allow microphone access, then play…');
      navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false } })
        .then(function (stream) {
          recStream = stream; recChunks = [];
          var mime = pickMime();
          try { recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream); }
          catch (e) { recorder = new MediaRecorder(stream); }
          recorder.ondataavailable = function (e) { if (e.data && e.data.size) recChunks.push(e.data); };
          recorder.onstop = function () {
            var type = (recorder && recorder.mimeType) || pickMime() || 'audio/webm';
            var blob = new Blob(recChunks, { type: type.split(';')[0] || 'audio/webm' });
            recorder = null; stopStream();
            if (elRec) { elRec.classList.remove('recording'); }
            if (elRecLabel) elRecLabel.textContent = 'Record';
            if (blob.size > 1024) setClip(blob, 'recording'); else clearResult();
          };
          recorder.start();
          recStart = Date.now();
          if (elRec) elRec.classList.add('recording');
          if (recTimer) clearInterval(recTimer);
          recTimer = setInterval(tickRec, 250); tickRec();
          clearResult();
        })
        .catch(function (err) {
          stopStream(); recorder = null;
          var name = (err && err.name) || '';
          var msg = name === 'NotAllowedError' || name === 'SecurityError'
            ? 'Microphone access was blocked. Allow it in your browser, or upload a file instead.'
            : name === 'NotFoundError'
              ? 'No microphone was found. You can upload an audio file instead.'
              : 'Couldn\'t start recording. You can upload an audio file instead.';
          message('err', 'fa-microphone-slash', msg);
        });
    }
    function stopRecording() {
      if (recTimer) { clearInterval(recTimer); recTimer = null; }
      if (recorder && recorder.state !== 'inactive') { try { recorder.stop(); } catch (e) { recorder = null; stopStream(); } }
    }
    if (elRec) elRec.addEventListener('click', function () {
      if (recorder) stopRecording(); else startRecording();
    });

    /* ── input: file upload ── */
    if (elUploadBtn && elFile) {
      elUploadBtn.addEventListener('click', function () { elFile.value = ''; elFile.click(); });
      elFile.addEventListener('change', function () {
        var f = elFile.files && elFile.files[0]; if (!f) return;
        if (!/^audio\//.test(f.type || '') && !/\.(mp3|wav|m4a|aac|ogg|flac|webm|aiff?)$/i.test(f.name || '')) {
          message('err', 'fa-file-circle-xmark', 'That doesn\'t look like an audio file. Try MP3, WAV, M4A, OGG or FLAC.'); return;
        }
        if (f.size > MAX_BYTES) { message('err', 'fa-file-circle-xmark', 'That file is too large. Please keep it under 8 MB (~30 seconds).'); return; }
        setClip(f, f.name || 'audio');
      });
    }

    /* ── clip handling ── */
    function setClip(blob, name) {
      clipBlob = blob;
      if (clipUrl) { try { URL.revokeObjectURL(clipUrl); } catch (e) {} }
      clipUrl = null;
      try { clipUrl = URL.createObjectURL(blob); if (elAudio) elAudio.src = clipUrl; } catch (e) {}
      if (elClipName) elClipName.textContent = (name || 'clip') + ' · ' + Math.max(1, Math.round(blob.size / 1024)) + ' KB';
      if (elClip) elClip.hidden = false;
      elGo.disabled = false;
      clearResult();
    }
    function discardClip() {
      clipBlob = null;
      if (clipUrl) { try { URL.revokeObjectURL(clipUrl); } catch (e) {} clipUrl = null; }
      if (elAudio) { try { elAudio.pause(); } catch (e) {} elAudio.removeAttribute('src'); }
      if (elClip) elClip.hidden = true;
      elGo.disabled = true;
      clearResult();
    }
    if (elClipX) elClipX.addEventListener('click', discardClip);

    /* ── result panel states ── */
    function clearResult() {
      pollStop = true; polling = false;
      elResult.innerHTML = '<div class="tr-empty" id="trEmpty"><i class="fa-solid fa-music"></i><p>Your transcription will appear here.</p></div>';
    }
    function status(text) {
      elResult.innerHTML = '<div class="tr-status"><span class="tr-spin"></span><span></span></div>';
      var s = elResult.querySelector('.tr-status span:last-child'); if (s) s.textContent = text;
    }
    function message(kind, icon, text, retry) {
      // `text` is always developer-controlled copy (no user input), so it may
      // carry a trusted inline link.
      var html = '<div class="tr-msg ' + (kind === 'err' ? 'err' : '') + '"><i class="fa-solid ' + icon + '"></i><div>' + text + '</div>';
      if (retry) html += '<button class="tr-retry" type="button">Try again</button>';
      html += '</div>';
      elResult.innerHTML = html;
      if (retry) { var b = elResult.querySelector('.tr-retry'); if (b) b.addEventListener('click', retry); }
    }

    /* ── submit + poll ── */
    function blobToDataUrl(blob) {
      return new Promise(function (res, rej) {
        try { var fr = new FileReader(); fr.onload = function () { res(String(fr.result || '')); }; fr.onerror = function () { rej(new Error('read_failed')); }; fr.readAsDataURL(blob); }
        catch (e) { rej(e); }
      });
    }
    function setBusy(on) { elGo.disabled = on || !clipBlob; if (elRec) elRec.disabled = on; if (elUploadBtn) elUploadBtn.disabled = on; if (elModel) elModel.disabled = on; }

    elGo.addEventListener('click', function () {
      if (!clipBlob) return;
      setBusy(true);
      status('Uploading your clip…');
      blobToDataUrl(clipBlob).then(function (dataUrl) {
        var mime = (clipBlob.type || 'audio/webm').split(';')[0] || 'audio/webm';
        return API.request('transcribe', { method: 'POST', body: { audio_base64: dataUrl, mime: mime, model: elModel ? elModel.value : 'piano' } });
      }).then(function (r) {
        var job = r && r.job_id;
        if (!job) { setBusy(false); return message('err', 'fa-triangle-exclamation', 'The transcription service returned an unexpected response. Please try again.', retry); }
        status('Transcribing… this usually takes 10–40 seconds.');
        pollStop = false; pollJob(job, 0);
      }).catch(function (err) {
        setBusy(false);
        handleApiError(err);
      });
    });

    function retry() { setBusy(false); if (clipBlob) elGo.click(); }

    function pollJob(job, attempt) {
      if (pollStop) return;
      if (attempt > 45) { setBusy(false); return message('err', 'fa-clock', 'This is taking longer than expected. The service may be busy — please try again.', retry); }
      API.request('transcribe?job=' + encodeURIComponent(job)).then(function (r) {
        if (pollStop) return;
        if (r && r.done) { setBusy(false); return renderDone(r, job); }
        if (r && r.failed) { setBusy(false); return message('err', 'fa-circle-xmark', (r.message || 'Transcription failed. Try a cleaner, single-instrument recording.'), retry); }
        setTimeout(function () { pollJob(job, attempt + 1); }, 2500);
      }).catch(function (err) {
        if (pollStop) return;
        // Transient poll errors: retry a few times before giving up.
        if (attempt < 6 && (!err || !err.status || err.status >= 500)) { setTimeout(function () { pollJob(job, attempt + 1); }, 3000); return; }
        setBusy(false); handleApiError(err);
      });
    }

    function handleApiError(err) {
      var st = err && err.status, code = err && err.body && err.body.error, msg = err && err.body && err.body.message;
      if (st === 401) return message('err', 'fa-right-to-bracket', 'Please sign in with your MAM student account to use transcription. <a href="/portal/login.html" style="color:var(--gold-bright)">Sign in →</a>');
      if (st === 503 || code === 'transcribe_unconfigured') return message('info', 'fa-screwdriver-wrench', 'Transcription isn\'t enabled on this server yet. Ask an admin to add a Klangio API key.');
      if (st === 413 || code === 'audio_too_large') return message('err', 'fa-weight-hanging', 'That clip is too large — keep it under ~30 seconds.', retry);
      if (st === 429) return message('err', 'fa-hourglass-half', 'You\'ve made a lot of requests. Please wait a minute and try again.', retry);
      return message('err', 'fa-triangle-exclamation', (msg || 'Something went wrong reaching the transcription service. Please try again.'), retry);
    }

    /* ── render result ── */
    function renderDone(r, job) {
      var notes = (r && r.notes) || [];
      var dl = API_BASE + '/transcribe?job=' + encodeURIComponent(job) + '&download=';
      var html = '<div class="tr-done-head"><h3>Transcription ready</h3>' +
        (notes.length ? '<span>' + notes.length + ' notes detected</span>' : '') + '</div>';
      html += '<canvas class="tr-roll" id="trRoll"></canvas>';
      if (notes.length) html += '<p class="tr-roll-x">Note-roll preview · time → , pitch ↑</p>';
      else html += '<p class="tr-roll-x">Preview unavailable — download the files below.</p>';
      html += '<div class="tr-dl">' +
        '<a href="' + dl + 'midi" download><i class="fa-solid fa-file-audio"></i> MIDI</a>' +
        '<a href="' + dl + 'musicxml" download><i class="fa-solid fa-file-lines"></i> MusicXML</a>' +
        '<a href="' + dl + 'csv" download><i class="fa-solid fa-table"></i> Notes (CSV)</a>' +
        '</div>';
      elResult.innerHTML = html;
      var cv = document.getElementById('trRoll');
      if (cv) { cv._notes = notes; requestAnimationFrame(function () { drawRoll(cv, notes); }); }
    }

    function nameToPitch(name) {
      if (!name) return null;
      var m = /^([A-Ga-g])([#b♯♭]?)(-?\d+)?$/.exec(String(name).trim());
      if (!m) return null;
      var base = { C: 0, D: 2, E: 4, F: 5, G: 7, A: 9, B: 11 }[m[1].toUpperCase()];
      if (base == null) return null;
      var acc = (m[2] === '#' || m[2] === '♯') ? 1 : (m[2] === 'b' || m[2] === '♭') ? -1 : 0;
      var oct = m[3] != null ? parseInt(m[3], 10) : 4;
      return (oct + 1) * 12 + base + acc;
    }
    function roundRect(ctx, x, y, w, h, r) {
      r = Math.max(0, Math.min(r, h / 2, w / 2));
      ctx.beginPath(); ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
    }
    function drawRoll(cv, notes) {
      try {
        var dpr = window.devicePixelRatio || 1, w = cv.clientWidth || 520, h = 170;
        cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr);
        var ctx = cv.getContext('2d'); if (!ctx) return;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.clearRect(0, 0, w, h);
        if (!notes || !notes.length) return;
        var pts = [], tMax = 0;
        notes.forEach(function (n, i) {
          var p = nameToPitch(n.note); if (p == null) return;
          var t = (n.t != null && isFinite(n.t)) ? n.t : i * 0.4;
          var d = (n.d != null && isFinite(n.d) && n.d > 0) ? n.d : 0.4;
          pts.push({ p: p, t: t, d: d }); tMax = Math.max(tMax, t + d);
        });
        if (!pts.length) return;
        if (tMax <= 0) tMax = pts.length * 0.4;
        var pMin = Infinity, pMax = -Infinity;
        pts.forEach(function (q) { pMin = Math.min(pMin, q.p); pMax = Math.max(pMax, q.p); });
        pMin -= 1; pMax += 1;
        var padX = 8, padY = 8, plotW = w - 2 * padX, plotH = h - 2 * padY, rows = Math.max(1, pMax - pMin);
        ctx.strokeStyle = 'rgba(245,238,227,0.06)'; ctx.lineWidth = 1;
        for (var p = pMin; p <= pMax; p++) { var y = padY + plotH - ((p - pMin) / rows) * plotH; ctx.beginPath(); ctx.moveTo(padX, y + 0.5); ctx.lineTo(w - padX, y + 0.5); ctx.stroke(); }
        var bh = Math.max(4, plotH / rows * 0.82);
        pts.forEach(function (q) {
          var x = padX + (q.t / tMax) * plotW, ww = Math.max(3, (q.d / tMax) * plotW - 1);
          var y = padY + plotH - ((q.p - pMin) / rows) * plotH;
          ctx.fillStyle = 'rgba(252,198,71,0.92)';
          roundRect(ctx, x, y - bh / 2, Math.min(ww, plotW - (x - padX)), bh, 2); ctx.fill();
        });
      } catch (e) { /* preview is non-critical */ }
    }

    // Redraw the roll on resize if one is showing.
    window.addEventListener('resize', function () {
      var cv = document.getElementById('trRoll');
      if (cv && cv._notes) drawRoll(cv, cv._notes);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
