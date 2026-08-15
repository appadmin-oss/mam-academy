/**
 * MAM Academy — record a take over a backing track (Advanced Modernization §1.4).
 * ─────────────────────────────────────────────────────────────
 * MAM_TAKE.mount(el, {assignmentId, backingUrl?, bpm?, countIn?}) gives a
 * student a record button, an optional backing track that starts with the
 * recording, a local playback of the result, and a Submit that stores the take
 * against the assignment.
 *
 * NOTHING IS SENT UNTIL THE STUDENT PRESSES SUBMIT. The recording lives in a
 * blob in the page until then, so a bad take costs nothing and a nervous
 * student can re-record as many times as they like. That also means a student
 * who closes the tab has uploaded nothing — which is the correct default for a
 * recording of a child.
 *
 * MediaRecorder is a plain browser API — no service, no key, no vendored
 * library. What the browser produces varies (webm/opus on Chromium and
 * Firefox, mp4/aac on Safari), so the container is NEGOTIATED here and
 * re-sniffed server-side; nothing trusts the MIME string we send.
 */
(function () {
  'use strict';

  // In preference order. The first supported one wins; an empty string lets
  // the browser pick, which Safari needs since it supports none of these names.
  var CANDIDATES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/mp4',
    ''
  ];

  function pickMime() {
    if (typeof window.MediaRecorder === 'undefined') { return null; }
    for (var i = 0; i < CANDIDATES.length; i++) {
      var m = CANDIDATES[i];
      if (m === '') { return ''; }
      try { if (window.MediaRecorder.isTypeSupported(m)) { return m; } } catch (e) {}
    }
    return '';
  }

  function supported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && typeof window.MediaRecorder !== 'undefined');
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function fmt(sec) {
    sec = Math.max(0, Math.floor(sec));
    return Math.floor(sec / 60) + ':' + (sec % 60 < 10 ? '0' : '') + (sec % 60);
  }

  function blobToDataUrl(blob) {
    return new Promise(function (resolve, reject) {
      var r = new FileReader();
      r.onload = function () { resolve(String(r.result)); };
      r.onerror = function () { reject(new Error('read_failed')); };
      r.readAsDataURL(blob);
    });
  }

  function mount(el, opts) {
    if (!el) { return null; }
    opts = opts || {};
    var assignmentId = parseInt(opts.assignmentId, 10) || 0;

    if (!supported()) {
      // An honest, actionable message — never a button that does nothing.
      el.innerHTML = '<p class="tk-unsupported">Recording is not available in this browser. '
        + 'You can still submit a take by uploading a file or pasting a link.</p>';
      return null;
    }

    el.innerHTML = ''
      + '<div class="tk">'
      +   '<div class="tk-row">'
      +     '<button type="button" class="btn btn-primary tk-rec"><i class="fa-solid fa-microphone"></i> Record</button>'
      +     '<button type="button" class="btn tk-stop" disabled><i class="fa-solid fa-stop"></i> Stop</button>'
      +     '<span class="tk-timer" role="timer" aria-live="off">0:00</span>'
      +     '<span class="tk-state" role="status" aria-live="polite"></span>'
      +   '</div>'
      +   (opts.backingUrl ? '<audio class="tk-backing" src="' + esc(opts.backingUrl) + '" preload="auto"></audio>' : '')
      +   '<div class="tk-result" hidden>'
      +     '<audio class="tk-play" controls></audio>'
      +     '<div class="tk-row">'
      +       '<button type="button" class="btn btn-primary tk-submit"><i class="fa-solid fa-paper-plane"></i> Submit take</button>'
      +       '<button type="button" class="btn btn-ghost tk-again">Record again</button>'
      +     '</div>'
      +   '</div>'
      + '</div>';

    var recBtn  = el.querySelector('.tk-rec');
    var stopBtn = el.querySelector('.tk-stop');
    var timerEl = el.querySelector('.tk-timer');
    var stateEl = el.querySelector('.tk-state');
    var result  = el.querySelector('.tk-result');
    var player  = el.querySelector('.tk-play');
    var backing = el.querySelector('.tk-backing');
    var subBtn  = el.querySelector('.tk-submit');
    var again   = el.querySelector('.tk-again');

    var rec = null, chunks = [], stream = null, blob = null, t0 = 0, tick = null, objUrl = null;

    function say(msg) { stateEl.textContent = msg || ''; }

    function cleanupStream() {
      // Release the microphone the moment we stop needing it. Leaving the track
      // live keeps the browser's recording indicator on, which is alarming and
      // reasonably reads as the site still listening.
      if (stream) { stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); stream = null; }
    }

    function stopTimer() { if (tick) { window.clearInterval(tick); tick = null; } }

    function reset() {
      stopTimer();
      cleanupStream();
      if (objUrl) { try { URL.revokeObjectURL(objUrl); } catch (e) {} objUrl = null; }
      blob = null; chunks = [];
      result.hidden = true;
      timerEl.textContent = '0:00';
      recBtn.disabled = false; stopBtn.disabled = true;
      say('');
    }

    function start() {
      say('Asking for your microphone…');
      recBtn.disabled = true;
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
        stream = s;
        var mime = pickMime();
        var options = mime ? { mimeType: mime } : undefined;
        try { rec = new window.MediaRecorder(stream, options); }
        catch (e) { rec = new window.MediaRecorder(stream); }  // options refused → let the browser decide

        chunks = [];
        rec.ondataavailable = function (e) { if (e.data && e.data.size) { chunks.push(e.data); } };
        rec.onstop = function () {
          stopTimer();
          cleanupStream();
          if (backing) { try { backing.pause(); backing.currentTime = 0; } catch (e) {} }
          blob = new Blob(chunks, { type: (rec && rec.mimeType) || 'audio/webm' });
          if (!blob.size) { say('That recording came out empty — please try again.'); reset(); return; }
          if (objUrl) { try { URL.revokeObjectURL(objUrl); } catch (e) {} }
          objUrl = URL.createObjectURL(blob);
          player.src = objUrl;
          result.hidden = false;
          recBtn.disabled = false; stopBtn.disabled = true;
          say('Take recorded — listen back, then submit or record again.');
        };

        rec.start();
        t0 = Date.now();
        stopBtn.disabled = false;
        say('Recording…');
        if (backing) { try { backing.currentTime = 0; backing.play(); } catch (e) {} }
        tick = window.setInterval(function () { timerEl.textContent = fmt((Date.now() - t0) / 1000); }, 250);
      }).catch(function (err) {
        recBtn.disabled = false;
        var name = err && err.name;
        say(name === 'NotAllowedError'
          ? 'Microphone permission was declined — allow it in your browser to record.'
          : (name === 'NotFoundError' ? 'No microphone was found.' : 'Could not start recording.'));
      });
    }

    function stop() {
      if (rec && rec.state !== 'inactive') { try { rec.stop(); } catch (e) {} }
      stopBtn.disabled = true;
    }

    function submit() {
      if (!blob) { return; }
      if (!assignmentId) { say('This take is not attached to an assignment.'); return; }
      subBtn.disabled = true;
      say('Uploading your take…');
      blobToDataUrl(blob).then(function (dataUrl) {
        var API = window.MAM_API;
        if (!API || !API.request) { throw new Error('no_api'); }
        return API.request('student/take', {
          method: 'POST',
          body: { assignment_id: assignmentId, audio: dataUrl, note: opts.note || null }
        });
      }).then(function (r) {
        say('Take submitted.');
        subBtn.disabled = false;
        if (typeof opts.onSubmitted === 'function') { opts.onSubmitted(r); }
        if (window.MAM_PORTAL && window.MAM_PORTAL.celebrate && window.MAM_PORTAL.seenOnce
            && window.MAM_PORTAL.seenOnce('take:first')) {
          window.MAM_PORTAL.celebrate('milestone', 'First take submitted');
        }
      }).catch(function (e) {
        subBtn.disabled = false;
        var d = e && e.data;
        say((d && d.detail) || 'Could not submit that take — please try again.');
      });
    }

    recBtn.addEventListener('click', start);
    stopBtn.addEventListener('click', stop);
    subBtn.addEventListener('click', submit);
    again.addEventListener('click', reset);

    return { start: start, stop: stop, reset: reset, _blob: function () { return blob; } };
  }

  var api = { mount: mount, supported: supported, _internals: { pickMime: pickMime, fmt: fmt, CANDIDATES: CANDIDATES } };
  if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
  if (typeof window !== 'undefined') { window.MAM_TAKE = api; }
})();
