/**
 * MAM Academy — Headshot capture & AI validation
 * ─────────────────────────────────────────────────────────────
 * Runs face detection IN THE BROWSER using face-api.js (TensorFlow.js
 * under the hood) before letting an upload proceed. No API key, no
 * server round-trip for validation — every check is on-device. Free,
 * private, fast enough for a single still image.
 *
 * Validation rules (in order):
 *   1. File type is an image, ≤ 10 MB
 *   2. Image decodes; dimensions ≥ 240×240 px
 *   3. EXACTLY one face detected
 *   4. Face occupies between 15 % and 90 % of the frame's shorter side
 *   5. Face is roughly centered (within 30 % of frame center)
 *
 * On pass, optionally uploads the original file to Cloudinary via an
 * unsigned upload preset (configure MAM_CONFIG.cdn.cloudName +
 * MAM_CONFIG.cdn.uploadPreset). Returns { ok, url, reasons[], face{...} }.
 *
 * Public API:
 *   MAM_HEADSHOT.mount(rootElement, opts)        // wires a dropzone UI
 *   MAM_HEADSHOT.validate(file)                  // returns Promise<result>
 *   MAM_HEADSHOT.upload(file)                    // returns Promise<{url}>
 */
(function () {
  'use strict';

  var FACEAPI_SRC  = 'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js';
  var MODELS_URL   = 'https://justadudewhohacks.github.io/face-api.js/models';
  var MAX_BYTES    = 10 * 1024 * 1024;
  var MIN_PX       = 240;
  var MIN_FACE_PCT = 0.15;
  var MAX_FACE_PCT = 0.90;
  var CENTER_TOL   = 0.30;
  var MIN_SCORE    = 0.55;

  var modelsReady = null;

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      if (document.querySelector('script[data-src="' + src + '"]')) return resolve();
      var s = document.createElement('script');
      s.src = src; s.async = true; s.setAttribute('data-src', src);
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('script_load_failed: ' + src)); };
      document.head.appendChild(s);
    });
  }

  function ensureModels() {
    if (modelsReady) return modelsReady;
    modelsReady = loadScript(FACEAPI_SRC).then(function () {
      if (!window.faceapi) throw new Error('faceapi_unavailable');
      // TinyFaceDetector is ~190 KB and runs in <300 ms on phones. Plenty
      // accurate for a "is this a clear single-face headshot" gate.
      return Promise.all([
        window.faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_URL),
        window.faceapi.nets.faceLandmark68TinyNet.loadFromUri(MODELS_URL)
      ]);
    });
    return modelsReady;
  }

  function readImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { resolve({ img: img, url: url }); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode_failed')); };
      img.src = url;
    });
  }

  function validate(file) {
    var reasons = [];

    if (!file) return Promise.resolve({ ok: false, reasons: ['No file selected.'] });
    if (!/^image\/(jpe?g|png|webp|heic|heif)$/i.test(file.type)) {
      reasons.push('That looks like a ' + (file.type || 'non-image') + ' file. Please upload a JPG, PNG or WebP image.');
    }
    if (file.size > MAX_BYTES) {
      reasons.push('That file is ' + (file.size / 1048576).toFixed(1) + ' MB — please keep headshots under 10 MB.');
    }
    if (reasons.length) return Promise.resolve({ ok: false, reasons: reasons });

    return readImage(file).then(function (loaded) {
      var img = loaded.img;
      var w = img.naturalWidth, h = img.naturalHeight;
      if (w < MIN_PX || h < MIN_PX) {
        URL.revokeObjectURL(loaded.url);
        return { ok: false, reasons: ['That image is too small (' + w + '×' + h + '). Please upload at least 240×240.'] };
      }

      return ensureModels().then(function () {
        var opts = new window.faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: MIN_SCORE });
        return window.faceapi.detectAllFaces(img, opts).withFaceLandmarks(true).then(function (faces) {
          URL.revokeObjectURL(loaded.url);

          if (!faces || !faces.length) {
            return { ok: false, reasons: ['No face detected. Please use a clear, front-facing photo with good lighting.'], face: null };
          }
          if (faces.length > 1) {
            return { ok: false, reasons: ['We see ' + faces.length + ' faces in this photo. Headshots must show only the applicant.'], face: null };
          }

          var d = faces[0];
          var box = d.detection.box;
          var shortSide = Math.min(w, h);
          var faceShort = Math.min(box.width, box.height);
          var pct = faceShort / shortSide;

          if (pct < MIN_FACE_PCT) {
            return { ok: false, reasons: ['You\'re too far from the camera — the face takes up only ' + Math.round(pct * 100) + '% of the frame. Crop closer (target 30–60%).'], face: { score: d.detection.score, pct: pct } };
          }
          if (pct > MAX_FACE_PCT) {
            return { ok: false, reasons: ['Face fills almost the entire frame. Pull back so we can see your head and shoulders.'], face: { score: d.detection.score, pct: pct } };
          }

          // Center check — both face center coords should sit within CENTER_TOL of the frame center
          var cx = (box.x + box.width  / 2) / w;
          var cy = (box.y + box.height / 2) / h;
          if (Math.abs(cx - 0.5) > CENTER_TOL || Math.abs(cy - 0.5) > CENTER_TOL) {
            return { ok: false, reasons: ['Please center your face in the frame.'], face: { score: d.detection.score, pct: pct } };
          }

          return {
            ok: true,
            reasons: [],
            face: {
              score: Math.round(d.detection.score * 100) / 100,
              pct:   Math.round(pct * 100) / 100,
              box:   { x: box.x, y: box.y, w: box.width, h: box.height },
              size:  { w: w, h: h }
            }
          };
        });
      }).catch(function (err) {
        URL.revokeObjectURL(loaded.url);
        // If face-api fails to load (offline, blocked) we still let the user
        // submit — the file is on file; just no AI gate.
        return { ok: true, reasons: [], face: null, skipped: true, error: String(err && err.message || err) };
      });
    });
  }

  function upload(file) {
    var cdn = (window.MAM_CONFIG && window.MAM_CONFIG.cdn) || {};
    if (!cdn.cloudName || !cdn.uploadPreset) {
      return Promise.reject(new Error('cloudinary_not_configured'));
    }
    var fd = new FormData();
    fd.append('file', file);
    fd.append('upload_preset', cdn.uploadPreset);
    fd.append('folder', 'mam-academy/headshots');
    return fetch('https://api.cloudinary.com/v1_1/' + cdn.cloudName + '/image/upload', {
      method: 'POST', body: fd
    }).then(function (r) {
      if (!r.ok) throw new Error('cloudinary_upload_failed_' + r.status);
      return r.json();
    }).then(function (data) {
      return { url: data.secure_url, publicId: data.public_id, width: data.width, height: data.height };
    });
  }

  /**
   * Wire a dropzone UI into `root` and call opts.onChange(state) whenever
   * the validated state of the picked file changes. The state shape:
   *   { file, validation, uploaded: {url} | null }
   */
  function mount(root, opts) {
    opts = opts || {};
    var state = { file: null, validation: null, uploaded: null };

    root.classList.add('headshot-mount');
    root.innerHTML =
      '<div class="hs-dropzone" tabindex="0" role="button" aria-label="Upload headshot">'
      +   '<div class="hs-empty">'
      +     '<i class="fa-solid fa-camera"></i>'
      +     '<strong>Click to upload your headshot</strong>'
      +     '<span>JPG, PNG or WebP · up to 10 MB · clear front-facing photo</span>'
      +   '</div>'
      +   '<div class="hs-preview" hidden>'
      +     '<img alt="Headshot preview">'
      +     '<button type="button" class="hs-replace">Replace</button>'
      +   '</div>'
      +   '<input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" hidden>'
      + '</div>'
      + '<div class="hs-status" hidden></div>';

    var dz       = root.querySelector('.hs-dropzone');
    var emptyEl  = root.querySelector('.hs-empty');
    var prevEl   = root.querySelector('.hs-preview');
    var imgEl    = root.querySelector('.hs-preview img');
    var input    = root.querySelector('input[type=file]');
    var statusEl = root.querySelector('.hs-status');
    var replaceBtn = root.querySelector('.hs-replace');

    function setStatus(kind, html) {
      if (!html) { statusEl.hidden = true; statusEl.innerHTML = ''; statusEl.className = 'hs-status'; return; }
      statusEl.hidden = false;
      statusEl.className = 'hs-status hs-status-' + kind;
      statusEl.innerHTML = html;
    }

    function showPreview(file) {
      var url = URL.createObjectURL(file);
      imgEl.src = url;
      imgEl.onload = function () { URL.revokeObjectURL(url); };
      prevEl.hidden = false;
      emptyEl.hidden = true;
    }

    function clear() {
      state = { file: null, validation: null, uploaded: null };
      input.value = '';
      imgEl.src = '';
      prevEl.hidden = true;
      emptyEl.hidden = false;
      setStatus(null);
      if (opts.onChange) opts.onChange(state);
    }

    function pickFile(file) {
      if (!file) return;
      state.file = file;
      state.uploaded = null;
      showPreview(file);
      setStatus('working', '<i class="fa-solid fa-circle-notch fa-spin"></i> Checking your headshot…');
      if (opts.onChange) opts.onChange(state);

      validate(file).then(function (v) {
        state.validation = v;
        if (!v.ok) {
          setStatus('error', '<i class="fa-solid fa-circle-exclamation"></i> ' + v.reasons.join(' '));
          if (opts.onChange) opts.onChange(state);
          return;
        }
        if (v.skipped) {
          setStatus('warn', '<i class="fa-solid fa-circle-info"></i> AI validation unavailable — saved your photo anyway. We\'ll review it manually.');
        } else {
          setStatus('ok', '<i class="fa-solid fa-circle-check"></i> Looks great — clear single-face headshot.');
        }
        if (opts.onChange) opts.onChange(state);

        // Best-effort Cloudinary upload — only attempts if configured.
        var cdn = (window.MAM_CONFIG && window.MAM_CONFIG.cdn) || {};
        if (cdn.cloudName && cdn.uploadPreset) {
          setStatus('working', '<i class="fa-solid fa-cloud-arrow-up fa-fade"></i> Uploading your headshot…');
          upload(file).then(function (u) {
            state.uploaded = u;
            setStatus('ok', '<i class="fa-solid fa-circle-check"></i> Headshot uploaded.');
            if (opts.onChange) opts.onChange(state);
          }).catch(function () {
            // Validation passed; just couldn't reach the CDN. Don't block the
            // applicant — the file stays in state and the backend can request
            // it later.
            setStatus('warn', '<i class="fa-solid fa-circle-info"></i> We couldn\'t reach the image CDN, but your photo is saved on this device.');
            if (opts.onChange) opts.onChange(state);
          });
        }
      });
    }

    dz.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.hs-replace')) return; // handled below
      input.click();
    });
    dz.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    dz.addEventListener('dragover', function (e) { e.preventDefault(); dz.classList.add('is-dragover'); });
    dz.addEventListener('dragleave', function () { dz.classList.remove('is-dragover'); });
    dz.addEventListener('drop', function (e) {
      e.preventDefault();
      dz.classList.remove('is-dragover');
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) pickFile(f);
    });
    input.addEventListener('change', function () { pickFile(input.files && input.files[0]); });
    replaceBtn.addEventListener('click', function (e) { e.stopPropagation(); clear(); input.click(); });

    return { state: function () { return state; }, clear: clear };
  }

  window.MAM_HEADSHOT = { mount: mount, validate: validate, upload: upload };
})();
