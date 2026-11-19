/* MAM Academy — compress in the browser, then upload straight to storage.
 *
 * window.MAM_MEDIA.upload(file, { purpose, onProgress(fraction, stage), signal })
 *   → Promise<{ id, kind, url, bytes, duration, width, height }>
 *
 * Why compress HERE: a phone records 1080p or 4K at 15–40 Mbps, so a three-minute
 * performance is 400 MB–1 GB. Sent as it is, that is an upload most families'
 * data plan cannot pay for and a bucket bill nobody budgeted. Re-encoded to 720p
 * H.264 at about 2.3 Mbps with 160 kbps AAC it is ~50 MB and a teacher can still
 * hear intonation and see a bow arm — the two things the marking needs.
 *
 *   · Video: Mediabunny (WebCodecs), self-hosted, loaded only when a video is
 *     chosen. Audio is COPIED when it is already AAC — re-encoding music twice
 *     is where it starts to sound like a phone call — and where the browser
 *     cannot encode AAC (Safari before 26) the original audio is kept.
 *   · A video already small enough (≤ 720p and ≤ ~3.5 Mbps) is sent as it is.
 *   · A browser with no WebCodecs sends the original, if it fits; if it does
 *     not, the person is told plainly rather than shown a failed upload.
 *   · Pictures: resized to 1600px on the long edge and saved as WebP (JPEG
 *     where WebP is not available). Re-encoding also drops EXIF, which on a
 *     phone photo includes where the child lives.
 *   · Audio files are sent as they are.
 *
 * The upload itself: ask /api/media/sign for a one-file, one-size, fifteen-minute
 * address; PUT the bytes to it (a private bucket, or this site when none is
 * set) with real progress; then /api/media/done, where the server checks what
 * arrived before the file counts.
 */
(function () {
  'use strict';

  var TARGET_W = 1280, TARGET_H = 720, VIDEO_BPS = 2300000, AUDIO_BPS = 160000;
  var IMAGE_EDGE = 1600;
  var mb = null;

  function api() { return window.MAM_API; }
  function csrf(force) { return api() && api().csrf ? api().csrf(force) : Promise.resolve(null); }

  function post(path, body) {
    var go = function (token) {
      return fetch(path, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token || '' },
        body: JSON.stringify(body)
      }).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (!r.ok) { var e = new Error((j && j.message) || (j && j.error) || ('http_' + r.status)); e.code = j && j.error; e.status = r.status; throw e; }
          return j;
        });
      });
    };
    return csrf(false).then(go).catch(function (e) {
      if (e && e.status === 403 && e.code === 'csrf_invalid') return csrf(true).then(go);
      throw e;
    });
  }

  function loadMediabunny() {
    if (mb) return Promise.resolve(mb);
    return import('/assets/js/vendor/mediabunny.min.mjs?v=1.66.2').then(function (m) { mb = m; return m; });
  }

  function canTranscode() {
    return typeof window.VideoEncoder === 'function' && typeof window.VideoDecoder === 'function';
  }

  function kindOf(file) {
    var t = (file.type || '').toLowerCase(), n = (file.name || '').toLowerCase();
    if (t.indexOf('video/') === 0 || /\.(mp4|mov|m4v|webm)$/.test(n)) return 'video';
    if (t.indexOf('audio/') === 0 || /\.(mp3|m4a|aac|wav|ogg|oga|opus|weba)$/.test(n)) return 'audio';
    if (t.indexOf('image/') === 0 || /\.(jpe?g|png|webp|heic)$/.test(n)) return 'image';
    if (t === 'application/pdf' || /\.pdf$/.test(n)) return 'pdf';
    return 'other';
  }

  /* ── Pictures ─────────────────────────────────────────────────────── */

  function compressImage(file) {
    if (typeof createImageBitmap !== 'function') return Promise.resolve({ blob: file, name: file.name });
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
      var scale = Math.min(1, IMAGE_EDGE / Math.max(bmp.width, bmp.height));
      var w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
      var c = document.createElement('canvas'); c.width = w; c.height = h;
      c.getContext('2d').drawImage(bmp, 0, 0, w, h);
      if (bmp.close) bmp.close();
      var save = function (type, q) { return new Promise(function (res) { c.toBlob(res, type, q); }); };
      return save('image/webp', 0.8).then(function (b) {
        if (b && b.type === 'image/webp') return { blob: b, name: 'photo.webp', width: w, height: h };
        return save('image/jpeg', 0.82).then(function (j) { return { blob: j, name: 'photo.jpg', width: w, height: h }; });
      });
    }).then(function (r) {
      // Never send a "compressed" picture that came out bigger than the original.
      return r.blob && r.blob.size < file.size ? r : { blob: file, name: file.name, width: r.width, height: r.height };
    }).catch(function () { return { blob: file, name: file.name }; });
  }

  /* ── Video ────────────────────────────────────────────────────────── */

  function compressVideo(file, onProgress, signal) {
    if (!canTranscode()) return Promise.resolve({ blob: file, name: file.name, compressed: false, reason: 'no-webcodecs' });
    return loadMediabunny().then(function (M) {
      var input = new M.Input({ source: new M.BlobSource(file), formats: M.ALL_FORMATS });
      return Promise.all([input.getPrimaryVideoTrack(), input.getPrimaryAudioTrack(), input.computeDuration()]).then(function (got) {
        var vt = got[0], at = got[1], duration = got[2] || 0;
        if (!vt) return { blob: file, name: file.name, compressed: false, reason: 'no-video' };
        var w = vt.displayWidth, h = vt.displayHeight;
        var bps = duration > 0 ? (file.size * 8) / duration : Infinity;
        var small = Math.max(w, h) <= TARGET_W && Math.min(w, h) <= TARGET_H && bps <= 3500000 && /mp4|quicktime/.test(file.type || 'video/mp4');
        if (small) return { blob: file, name: file.name, compressed: false, duration: duration, width: w, height: h, reason: 'already-small' };
        var landscape = w >= h;
        var opts = {
          input: input,
          output: new M.Output({ format: new M.Mp4OutputFormat({ fastStart: 'in-memory' }), target: new M.BufferTarget() }),
          video: { codec: 'avc', bitrate: VIDEO_BPS, frameRate: 30, keyFrameInterval: 2 }
        };
        // Scale the LONG edge to 1280 and let the short edge follow, so a portrait phone video stays portrait.
        if (Math.max(w, h) > TARGET_W) { if (landscape) opts.video.width = TARGET_W; else opts.video.height = TARGET_W; }
        var audioCopy = at && at.codec === 'aac';
        var audioPlan = Promise.resolve(audioCopy || !at ? {} : null);
        if (at && !audioCopy) {
          audioPlan = (M.canEncodeAudio ? M.canEncodeAudio('aac', { bitrate: AUDIO_BPS }) : Promise.resolve(false)).then(function (ok) {
            return ok ? { codec: 'aac', bitrate: AUDIO_BPS, sampleRate: 48000 } : {};
          });
        }
        return audioPlan.then(function (audio) {
          if (audio) opts.audio = audio;
          return M.Conversion.init(opts);
        }).then(function (conv) {
          if (!conv.isValid) return { blob: file, name: file.name, compressed: false, reason: 'not-convertible' };
          if (signal) signal.addEventListener('abort', function () { conv.cancel(); }, { once: true });
          conv.onProgress = function (p) { if (onProgress) onProgress(Math.max(0, Math.min(1, p))); };
          return conv.execute().then(function () {
            var buf = opts.output.target.buffer;
            var out = new Blob([buf], { type: 'video/mp4' });
            // Never upload a "compressed" file bigger than the one the person chose.
            if (out.size >= file.size) return { blob: file, name: file.name, compressed: false, duration: duration, width: w, height: h, reason: 'no-saving' };
            return { blob: out, name: 'video.mp4', compressed: true, duration: duration,
                     width: opts.video.width || Math.round(w * (opts.video.height || h) / h),
                     height: opts.video.height || Math.round(h * (opts.video.width || w) / w), from: file.size };
          });
        });
      });
    }).catch(function (e) {
      if (signal && signal.aborted) throw e;
      return { blob: file, name: file.name, compressed: false, reason: 'failed', error: String(e && e.message || e) };
    });
  }

  /* ── Sending ──────────────────────────────────────────────────────── */

  function put(spec, blob, onProgress, signal) {
    return new Promise(function (resolve, reject) {
      var x = new XMLHttpRequest();
      var u = spec.url;
      x.open(spec.method || 'PUT', u, true);
      var same = u.charAt(0) === '/';
      if (same) x.withCredentials = true;
      Object.keys(spec.headers || {}).forEach(function (k) { x.setRequestHeader(k, spec.headers[k]); });
      x.upload.onprogress = function (ev) { if (ev.lengthComputable && onProgress) onProgress(ev.loaded / ev.total); };
      x.onload = function () {
        if (x.status >= 200 && x.status < 300) return resolve();
        var msg = 'The upload did not finish.';
        try { var j = JSON.parse(x.responseText || '{}'); if (j.message) msg = j.message; } catch (e) {}
        var err = new Error(msg); err.status = x.status; reject(err);
      };
      x.onerror = function () { reject(new Error(same ? 'The upload stopped. Check your connection and try again.'
        : 'The upload was refused by storage. Try again; if it keeps happening, tell the office (the bucket may need its CORS rule).')); };
      if (signal) signal.addEventListener('abort', function () { x.abort(); reject(new Error('Upload cancelled.')); }, { once: true });
      x.send(blob);
    });
  }

  /**
   * Compress (where it helps), sign, send, confirm. Stages reported through
   * onProgress(fraction, stage): 'prepare' | 'compress' | 'upload' | 'check'.
   */
  function upload(file, opts) {
    opts = opts || {};
    var purpose = opts.purpose || 'community';
    var progress = function (f, stage) { if (opts.onProgress) opts.onProgress(f, stage); };
    var kind = kindOf(file);
    progress(0, 'prepare');
    var prepared;
    if (kind === 'video') prepared = compressVideo(file, function (f) { progress(f, 'compress'); }, opts.signal);
    else if (kind === 'image') prepared = compressImage(file);
    else prepared = Promise.resolve({ blob: file, name: file.name });
    var meta = {};
    return prepared.then(function (p) {
      meta = { duration: p.duration || null, width: p.width || null, height: p.height || null,
               compressed: !!p.compressed, from_bytes: p.from || null };
      var type = p.blob.type || file.type || '';
      return post('/api/media/sign', { purpose: purpose, name: p.name || file.name, type: type, size: p.blob.size, meta: meta })
        .then(function (signed) {
          progress(0, 'upload');
          return put(signed.upload, p.blob, function (f) { progress(f, 'upload'); }, opts.signal).then(function () { return signed; });
        });
    }).then(function (signed) {
      progress(1, 'check');
      return post('/api/media/done', { id: signed.id, meta: meta });
    }).then(function (done) { return done.file; });
  }

  window.MAM_MEDIA = {
    upload: upload,
    compressVideo: compressVideo,
    compressImage: compressImage,
    kindOf: kindOf,
    canCompressVideo: canTranscode,
    /* For a <video>/<audio> element: grant time to a phone that was asked for a raw recording. */
    recordingConstraints: { audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false } }
  };
})();
