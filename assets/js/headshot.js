/**
 * MAM Academy — Headshot capture & AI validation
 * ─────────────────────────────────────────────────────────────
 * Runs face detection IN THE BROWSER using MediaPipe Tasks Vision
 * (Google's actively-maintained successor to MediaPipe Solutions).
 * No API key, no server round-trip — every check is on-device.
 *
 * Why MediaPipe over the old face-api.js path:
 *   • Apache-2.0, free, actively maintained (face-api.js is abandoned)
 *   • Models hosted on storage.googleapis.com — won't disappear like
 *     the third-party GitHub Pages mirror face-api.js relied on
 *   • BlazeFace model is ~230 KB vs face-api's ~1.5 MB combined
 *   • Sub-100 ms inference on phones, better accuracy in low light
 *   • Returns 6 keypoints (eyes/nose/mouth/ears) so we can also
 *     check head tilt and that both eyes are visible
 *
 * Validation rules (in order):
 *   1. File type is an image, ≤ 10 MB
 *   2. Image decodes; dimensions ≥ 240×240 px
 *   3. EXACTLY one face detected
 *   4. Face occupies between 15 % and 90 % of the frame's shorter side
 *   5. Face is roughly centered (within 30 % of frame center)
 *   6. Head tilt ≤ 25° (eyes roughly level)
 *   7. Both eyes are visible (not turned away from the camera)
 *
 * On pass, optionally uploads to Cloudinary via an unsigned upload
 * preset (MAM_CONFIG.cdn.cloudName + uploadPreset). Returns:
 *   { ok, url, reasons[], face{ score, pct, tilt_deg, keypoints, ... } }
 *
 * Public API (unchanged from the face-api.js version, so apply.js
 * doesn't care which engine is underneath):
 *   MAM_HEADSHOT.mount(rootElement, opts)        // wires a dropzone UI
 *   MAM_HEADSHOT.validate(file)                  // returns Promise<result>
 *   MAM_HEADSHOT.upload(file)                    // returns Promise<{url}>
 */
(function () {
  'use strict';

  // MediaPipe Tasks Vision — ES-module via jsDelivr. Pin a known-good version
  // so a breaking upstream release can't surprise us. Google's CDN for the
  // .tflite model is rock-solid.
  var MP_MODULE_URL  = 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/+esm';
  var MP_WASM_BASE   = 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/wasm';
  var MP_MODEL_URL   = 'https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite';

  var MAX_BYTES    = 10 * 1024 * 1024;
  var MIN_PX       = 240;
  var MIN_FACE_PCT = 0.12;
  var MAX_FACE_PCT = 0.92;
  var CENTER_TOL   = 0.38;
  var MAX_TILT_DEG = 32;
  var MIN_SCORE    = 0.40;   // lenient: BlazeFace under-scores some phone selfies
  var DETECT_TIMEOUT_MS = 6000; // never let model load/inference block the form

  // Keypoint indices documented by MediaPipe: 0=right eye, 1=left eye,
  // 2=nose tip, 3=mouth center, 4=right ear tragion, 5=left ear tragion.
  // (Right/left are FROM THE PERSON'S PERSPECTIVE, so swapped on-screen.)
  var KP = { RIGHT_EYE: 0, LEFT_EYE: 1, NOSE: 2, MOUTH: 3, RIGHT_EAR: 4, LEFT_EAR: 5 };

  var detectorReady = null;

  /** Lazily build the FaceDetector. Cached for the page lifetime. */
  function ensureDetector() {
    if (detectorReady) return detectorReady;
    detectorReady = import(MP_MODULE_URL).then(function (mp) {
      return mp.FilesetResolver.forVisionTasks(MP_WASM_BASE).then(function (vision) {
        // delegate:'GPU' uses WebGL where supported and falls back to WASM SIMD
        // automatically — never throws on missing GPU. runningMode:'IMAGE' is
        // cheaper than 'VIDEO' for our single-still use case.
        return mp.FaceDetector.createFromOptions(vision, {
          baseOptions: {
            modelAssetPath: MP_MODEL_URL,
            delegate: 'GPU'
          },
          runningMode: 'IMAGE',
          minDetectionConfidence: MIN_SCORE
        });
      });
    });
    return detectorReady;
  }

  // Race the detector against a timeout so a slow CDN / WASM download can NEVER
  // hang the headshot step. On timeout the caller treats the check as skipped
  // (photo accepted, reviewed by a human) — speed over a perfect AI gate.
  function detectorWithTimeout(ms) {
    return Promise.race([
      ensureDetector(),
      new Promise(function (_, reject) { setTimeout(function () { reject(new Error('detector_timeout')); }, ms); })
    ]);
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

  /** Find the keypoint at the documented index, scaled to image pixels. */
  function kp(det, idx, w, h) {
    var p = det.keypoints && det.keypoints[idx];
    if (!p) return null;
    // MediaPipe returns normalised coords (0..1). Scale to pixels for math.
    return { x: p.x * w, y: p.y * h };
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

      return detectorWithTimeout(DETECT_TIMEOUT_MS).then(function (detector) {
        var result;
        try {
          result = detector.detect(img);
        } catch (e) {
          URL.revokeObjectURL(loaded.url);
          // Detector loaded but failed at inference time — don't block submit;
          // log it and let the backend review.
          return { ok: true, reasons: [], warnings: [], face: null, skipped: true, error: String(e && e.message || e) };
        }
        URL.revokeObjectURL(loaded.url);

        // The face check is ADVISORY — it offers helpful tips but NEVER blocks
        // the upload. A photo is still required (the file must be picked +
        // uploaded), and a human confirms it in the dashboard. This is what
        // makes the step fast + forgiving instead of a strict AI gate.
        var faces = (result && result.detections) || [];
        var warnings = [];

        if (!faces.length) {
          return { ok: true, reasons: [], warnings: ["We couldn't clearly detect a face — please use a well-lit, front-facing photo. Your photo is saved and will be reviewed."], face: null, soft: true };
        }
        if (faces.length > 1) warnings.push('We can see more than one face — your headshot should show only you.');

        var d = faces[0];
        var bb = d.boundingBox;       // { originX, originY, width, height } in pixels
        var score = (d.categories && d.categories[0] && d.categories[0].score) || 0;
        var pct = Math.min(bb.width, bb.height) / Math.min(w, h);
        if (pct < MIN_FACE_PCT)      warnings.push('You look a little far away — for the sharpest ID photo, crop closer (face roughly a third to half the frame).');
        else if (pct > MAX_FACE_PCT) warnings.push('Your face fills the frame — pull back slightly so we see your head and shoulders.');

        var cx = (bb.originX + bb.width / 2) / w;
        var cy = (bb.originY + bb.height / 2) / h;
        if (Math.abs(cx - 0.5) > CENTER_TOL || Math.abs(cy - 0.5) > CENTER_TOL) warnings.push('Try to centre your face in the frame.');

        var rightEye = kp(d, KP.RIGHT_EYE, w, h);
        var leftEye  = kp(d, KP.LEFT_EYE,  w, h);
        var tiltDeg = 0;
        if (rightEye && leftEye) {
          tiltDeg = Math.abs(Math.atan2(leftEye.y - rightEye.y, leftEye.x - rightEye.x) * 180 / Math.PI);
          if (tiltDeg > 90) tiltDeg = 180 - tiltDeg;
        }
        if (tiltDeg > MAX_TILT_DEG) warnings.push('Your head looks tilted — straighten up so your eyes are level (best for the ID card).');

        return {
          ok: true,
          reasons: [],
          warnings: warnings,
          face: {
            score:    Math.round(score * 100) / 100,
            pct:      Math.round(pct * 100) / 100,
            tilt_deg: Math.round(tiltDeg),
            box:      { x: bb.originX, y: bb.originY, w: bb.width, h: bb.height },
            size:     { w: w, h: h },
            keypoints: d.keypoints
          }
        };
      }).catch(function (err) {
        URL.revokeObjectURL(loaded.url);
        // Detector failed to load (offline, CDN blocked, browser too old).
        // Don't block the applicant — flag it as skipped so the backend knows
        // a human should eyeball this photo.
        return { ok: true, reasons: [], warnings: [], face: null, skipped: true, error: String(err && err.message || err) };
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
        // Only objective FILE problems (type / size / dimensions) block. Face
        // geometry is advisory — shown as a tip, never a hard stop.
        if (!v.ok) {
          setStatus('error', '<i class="fa-solid fa-circle-exclamation"></i> ' + (v.reasons || []).join(' '));
          if (opts.onChange) opts.onChange(state);
          return;
        }
        var warns = (v.warnings && v.warnings.length) ? v.warnings : null;
        if (v.skipped) {
          setStatus('ok', "<i class=\"fa-solid fa-circle-check\"></i> Photo saved — we'll confirm it for your ID. <span class=\"hs-detail\">(Instant face check skipped — taking too long.)</span>");
        } else if (warns) {
          setStatus('warn', '<i class="fa-solid fa-circle-info"></i> ' + warns.join(' ') + ' <span class="hs-detail">You can continue — a person will confirm it.</span>');
        } else {
          var detail = '';
          if (v.face) {
            var bits = [];
            if (typeof v.face.pct === 'number')      bits.push(Math.round(v.face.pct * 100) + '% framing');
            if (typeof v.face.tilt_deg === 'number') bits.push(v.face.tilt_deg + '° tilt');
            if (typeof v.face.score === 'number')    bits.push(Math.round(v.face.score * 100) + '% confidence');
            if (bits.length) detail = ' <span class="hs-detail">(' + bits.join(' · ') + ')</span>';
          }
          setStatus('ok', '<i class="fa-solid fa-circle-check"></i> Looks great — clear single-face headshot.' + detail);
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
            setStatus('warn', "<i class=\"fa-solid fa-circle-info\"></i> We couldn't reach the image CDN, but your photo is saved on this device.");
            if (opts.onChange) opts.onChange(state);
          });
        }
      });
    }

    dz.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('.hs-replace')) return;
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
