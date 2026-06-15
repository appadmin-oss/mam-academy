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
 * Two layers of checking:
 *
 *   A. Hard gate — objective FILE problems only (these block):
 *      1. Must be an image (JPG/PNG/WebP/HEIC), ≤ 10 MB
 *      2. Must decode; dimensions ≥ 240×240 px
 *
 *   B. Advisory quality report — NEVER blocks; produces a professional,
 *      per-criterion checklist + overall grade, the way a commercial
 *      passport/ID-photo validator does (ICAO 9303 / ISO-IEC 19794-5 in
 *      spirit). A human confirms the photo in the dashboard, so we guide
 *      rather than gatekeep:
 *        • A single, front-facing face (count, pose/yaw, eyes visible)
 *        • Head size / framing, centering, head level (roll)
 *        • In focus (variance-of-Laplacian blur metric)
 *        • Exposure (mean luma + shadow/highlight clipping)
 *        • Contrast (luma spread)
 *        • Even lighting (left/right face balance)
 *        • Plain background (border-ring luma variance)
 *      The quality scan reads the pixels via a canvas, so it still runs and
 *      gives useful tips even when the face model can't load.
 *
 * On a successful file, optionally uploads to Cloudinary via an unsigned
 * upload preset (MAM_CONFIG.cdn.cloudName + uploadPreset). validate() returns:
 *   { ok, reasons[], warnings[], checks[{id,label,status,detail}],
 *     quality:{score,grade}, metrics{…}, face{score,pct,tilt_deg,yaw,…}|null,
 *     skipped?, soft? }
 * `ok` is true for every advisory outcome — only a FILE problem makes it false.
 *
 * NOTE: the pixel-quality thresholds are tuned conservatively but were written
 * without a browser to calibrate against (no camera/canvas in CI). They want a
 * real-device QA pass; because every quality finding is advisory, a mis-tuned
 * threshold only changes a tip, never whether an applicant can proceed.
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
  var MAX_YAW      = 0.60;   // nose offset / eye span — beyond this the head is turned away
  var MIN_SCORE    = 0.40;   // lenient: BlazeFace under-scores some phone selfies
  var DETECT_TIMEOUT_MS = 6000; // never let model load/inference block the form

  // ── Image-quality thresholds (luminance 0..255) ─────────────────────────────
  // Informed by ICAO 9303 / ISO-IEC 19794-5 portrait guidance and common
  // commercial ID-photo validators. These gate ADVISORY tips only — a photo is
  // never hard-rejected on quality; a human confirms it in the dashboard. They
  // are tuned conservatively to avoid false "bad photo" nags and WILL benefit
  // from real-device calibration (see browser-QA note in the commit).
  var ANALYSIS_MAX   = 384;  // normalise analysis resolution so thresholds are stable across phone megapixels
  var Q_MIN_BRIGHT   = 64;   // mean luma below → underexposed / too dark
  var Q_MAX_BRIGHT   = 204;  // mean luma above → washed out / overexposed
  var Q_MIN_CONTRAST = 26;   // luma stddev below → flat / low-contrast
  var Q_MIN_SHARP    = 70;   // Laplacian variance below → likely soft / out of focus
  var Q_MAX_CLIP     = 0.07; // >7% pure-black or pure-white pixels → clipped exposure
  var Q_MAX_LIGHT    = 0.34; // left/right face brightness difference ratio → uneven lighting
  var Q_MAX_BG_STD   = 62;   // background luma stddev above → busy / distracting backdrop

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

  function round2(n) { return Math.round(n * 100) / 100; }

  /** Rec.601 luma — the perceptual brightness of a pixel. */
  function luma(r, g, b) { return 0.299 * r + 0.587 * g + 0.114 * b; }

  /** Luma stddev of a frame's border ring (top + sides, skipping the bottom
   *  where clothing sits). A plain backdrop reads low; a busy room reads high. */
  function borderStd(gray, cw, ch) {
    var m = Math.max(2, Math.round(Math.min(cw, ch) * 0.08));
    var sum = 0, count = 0;
    for (var y = 0; y < ch; y++) {
      var top = y < m, row = y * cw;
      for (var x = 0; x < cw; x++) {
        if (top || x < m || x >= cw - m) { sum += gray[row + x]; count++; }
      }
    }
    if (!count) return null;
    var mean = sum / count, v = 0;
    for (var yy = 0; yy < ch; yy++) {
      var t2 = yy < m, r2 = yy * cw;
      for (var xx = 0; xx < cw; xx++) {
        if (t2 || xx < m || xx >= cw - m) { var d = gray[r2 + xx] - mean; v += d * d; }
      }
    }
    return Math.round(Math.sqrt(v / count));
  }

  /**
   * Measure objective image quality from the pixels themselves — the part of a
   * professional ID-photo check that face detection can't tell you: focus,
   * exposure, contrast, lighting balance and background. Runs on a downscaled
   * copy (ANALYSIS_MAX) so it's fast and resolution-independent. `faceBoxPx` is
   * the detected box in FULL-RES pixels (or null when no face was found — global
   * metrics are still useful). Returns null only if a 2D canvas is unavailable.
   */
  function computeImageMetrics(img, faceBoxPx) {
    try {
      var w = img.naturalWidth, h = img.naturalHeight;
      var scale = Math.min(1, ANALYSIS_MAX / Math.max(w, h));
      var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
      var canvas = document.createElement('canvas');
      canvas.width = cw; canvas.height = ch;
      var ctx = canvas.getContext('2d', { willReadFrequently: true });
      if (!ctx) return null;
      ctx.drawImage(img, 0, 0, cw, ch);
      var data = ctx.getImageData(0, 0, cw, ch).data;
      var n = cw * ch, gray = new Float32Array(n);
      var sum = 0, black = 0, white = 0;
      for (var i = 0, p = 0; i < n; i++, p += 4) {
        var L = luma(data[p], data[p + 1], data[p + 2]);
        gray[i] = L; sum += L;
        if (L <= 8) black++; else if (L >= 247) white++;
      }
      var mean = sum / n, vs = 0;
      for (var k = 0; k < n; k++) { var dv = gray[k] - mean; vs += dv * dv; }
      var std = Math.sqrt(vs / n);

      // Variance of the Laplacian — the standard no-reference focus/blur metric.
      var lapSum = 0, lapSq = 0, lapN = 0;
      for (var y = 1; y < ch - 1; y++) {
        for (var x = 1; x < cw - 1; x++) {
          var idx = y * cw + x;
          var lap = 4 * gray[idx] - gray[idx - 1] - gray[idx + 1] - gray[idx - cw] - gray[idx + cw];
          lapSum += lap; lapSq += lap * lap; lapN++;
        }
      }
      var lapVar = lapN ? (lapSq / lapN) - Math.pow(lapSum / lapN, 2) : 0;

      var metrics = {
        brightness: Math.round(mean),
        contrast:   Math.round(std),
        sharpness:  Math.round(lapVar),
        clip:       round2((black + white) / n),
        lightingDiff: null,
        bgStd:        borderStd(gray, cw, ch)
      };

      if (faceBoxPx) {
        var fx = Math.max(0, Math.round(faceBoxPx.x * scale));
        var fy = Math.max(0, Math.round(faceBoxPx.y * scale));
        var fw = Math.min(cw - fx, Math.round(faceBoxPx.w * scale));
        var fh = Math.min(ch - fy, Math.round(faceBoxPx.h * scale));
        if (fw > 4 && fh > 4) {
          var midX = fx + (fw >> 1), lS = 0, lC = 0, rS = 0, rC = 0;
          for (var yy = fy; yy < fy + fh; yy++) {
            var off = yy * cw;
            for (var xx = fx; xx < fx + fw; xx++) {
              if (xx < midX) { lS += gray[off + xx]; lC++; } else { rS += gray[off + xx]; rC++; }
            }
          }
          var lM = lC ? lS / lC : 0, rM = rC ? rS / rC : 0, avg = (lM + rM) / 2;
          metrics.lightingDiff = avg > 0 ? round2(Math.abs(lM - rM) / avg) : 0;
        }
      }
      return metrics;
    } catch (e) { return null; }
  }

  /**
   * Combine the geometry (from face detection) and the pixel metrics into a
   * structured, weighted quality report — the kind of per-criterion checklist a
   * professional passport/ID-photo validator produces. Pure & deterministic.
   *   geom    : { faceCount, score, pct, cx, cy, tiltDeg, eyesVisible, yawRatio } | null
   *   metrics : output of computeImageMetrics | null
   * Returns { checks:[{id,label,status,detail}], score, grade, hasFail }.
   */
  function assessQuality(geom, metrics) {
    var checks = [];
    function add(id, label, status, detail) { checks.push({ id: id, label: label, status: status, detail: detail || '' }); }

    if (!geom || geom.faceCount === 0) add('face', 'Face detected', 'fail', "We couldn't clearly detect a face. Use a well-lit, front-facing photo.");
    else if (geom.faceCount > 1)       add('face', 'A single face', 'warn', 'More than one face is visible — your headshot should show only you.');
    else                               add('face', 'A single face', 'pass', 'One clear face.');

    if (geom && geom.faceCount === 1) {
      if (geom.pct < MIN_FACE_PCT)      add('framing', 'Head size', 'warn', 'You look far away — crop closer (face about a third to half the frame).');
      else if (geom.pct > MAX_FACE_PCT) add('framing', 'Head size', 'warn', 'Your face fills the frame — pull back to show head and shoulders.');
      else                              add('framing', 'Head size', 'pass', Math.round(geom.pct * 100) + '% of the frame.');

      var off = Math.max(Math.abs(geom.cx - 0.5), Math.abs(geom.cy - 0.5));
      add('center', 'Centered', off > CENTER_TOL ? 'warn' : 'pass', off > CENTER_TOL ? 'Centre your face in the frame.' : 'Nicely centred.');

      add('level', 'Head level', geom.tiltDeg > MAX_TILT_DEG ? 'warn' : 'pass',
          geom.tiltDeg > MAX_TILT_DEG ? 'Straighten up so your eyes are level (' + geom.tiltDeg + '° tilt).' : geom.tiltDeg + '° tilt.');

      var turned = (geom.yawRatio != null && geom.yawRatio > MAX_YAW) || !geom.eyesVisible;
      add('pose', 'Facing camera', turned ? 'warn' : 'pass', turned ? 'Look straight at the camera so both eyes show evenly.' : 'Front-facing.');
    }

    if (metrics) {
      add('sharp', 'In focus', metrics.sharpness >= Q_MIN_SHARP ? 'pass' : 'warn',
          metrics.sharpness >= Q_MIN_SHARP ? 'Crisp.' : 'Looks soft — hold steady, wipe the lens, and tap to focus.');

      if (metrics.brightness < Q_MIN_BRIGHT)      add('exposure', 'Exposure', 'warn', 'Too dark — face an even, bright light source.');
      else if (metrics.brightness > Q_MAX_BRIGHT) add('exposure', 'Exposure', 'warn', 'Over-exposed — avoid strong backlight or glare.');
      else if (metrics.clip > Q_MAX_CLIP)         add('exposure', 'Exposure', 'warn', 'Detail is lost in the shadows or highlights.');
      else                                        add('exposure', 'Exposure', 'pass', 'Well exposed.');

      add('contrast', 'Contrast', metrics.contrast >= Q_MIN_CONTRAST ? 'pass' : 'warn',
          metrics.contrast >= Q_MIN_CONTRAST ? 'Good tonal range.' : 'A little flat — separate yourself from the backdrop.');

      if (metrics.lightingDiff != null)
        add('lighting', 'Even lighting', metrics.lightingDiff > Q_MAX_LIGHT ? 'warn' : 'pass',
            metrics.lightingDiff > Q_MAX_LIGHT ? 'One side of your face is brighter — light yourself evenly.' : 'Balanced light.');

      if (metrics.bgStd != null)
        add('background', 'Plain background', metrics.bgStd > Q_MAX_BG_STD ? 'warn' : 'pass',
            metrics.bgStd > Q_MAX_BG_STD ? 'Busy background — stand against a plain, light wall.' : 'Clean backdrop.');
    } else {
      add('quality', 'Image quality scan', 'skip', 'Not available in this browser — a person will confirm your photo.');
    }

    // Weighted score (skips excluded). The criteria that most affect a usable ID
    // photo carry more weight; cosmetic ones less.
    var weights = { face: 3, framing: 1, center: 1, level: 1, pose: 1.5, sharp: 2, exposure: 2, contrast: 1, lighting: 1, background: 1 };
    var got = 0, max = 0;
    checks.forEach(function (c) {
      if (c.status === 'skip') return;
      var wt = (weights[c.id] != null) ? weights[c.id] : 1;
      max += wt;
      got += wt * (c.status === 'pass' ? 1 : (c.status === 'warn' ? 0.5 : 0));
    });
    var score = max ? Math.round(got / max * 100) : 0;
    var grade = score >= 90 ? 'Excellent' : score >= 75 ? 'Good' : score >= 55 ? 'Fair' : 'Needs work';
    return { checks: checks, score: score, grade: grade, hasFail: checks.some(function (c) { return c.status === 'fail'; }) };
  }

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function statusIcon(s) {
    return s === 'pass' ? 'fa-circle-check'
         : s === 'warn' ? 'fa-triangle-exclamation'
         : s === 'fail' ? 'fa-circle-xmark'
         : 'fa-circle-minus';
  }

  /**
   * Render the advisory result as a professional, per-criterion report card —
   * an overall grade + score meter and a tick/cross checklist, the way a
   * commercial ID-photo validator presents it. `extra` is optional HTML
   * appended inside the card (e.g. the upload status), so the report stays put
   * while the photo uploads. Pure string building — no DOM reads.
   */
  function renderReport(v, extra) {
    var checks = (v && v.checks) || [];
    var q = (v && v.quality) || { score: 0, grade: '—' };
    var anyIssue = checks.some(function (c) { return c.status === 'warn' || c.status === 'fail'; });
    var tone = anyIssue ? 'warn' : 'ok';
    var gradeClass = q.score >= 90 ? 'excellent' : q.score >= 75 ? 'good' : q.score >= 55 ? 'fair' : 'low';
    var rows = checks.map(function (c) {
      return '<li class="hs-check hs-c-' + c.status + '">'
           +   '<i class="fa-solid ' + statusIcon(c.status) + '" aria-hidden="true"></i>'
           +   '<span class="hs-c-label">' + escHtml(c.label) + '</span>'
           +   (c.detail ? '<span class="hs-c-detail">' + escHtml(c.detail) + '</span>' : '')
           + '</li>';
    }).join('');
    var foot = v && v.skipped
      ? "Instant scan unavailable here — your photo is saved and a person will confirm it."
      : anyIssue
        ? "You can continue now — a person will confirm your photo. A quick re-take following the tips will sharpen your ID."
        : "Great — this makes a clean, professional ID photo.";
    return '<div class="hs-report hs-report-' + tone + '">'
         +   '<div class="hs-report-head">'
         +     '<span class="hs-grade hs-grade-' + gradeClass + '">' + escHtml(q.grade) + '</span>'
         +     '<span class="hs-score-label">Photo quality</span>'
         +     '<span class="hs-score-num">' + q.score + '%</span>'
         +   '</div>'
         +   '<div class="hs-meter" role="progressbar" aria-valuenow="' + q.score + '" aria-valuemin="0" aria-valuemax="100"><span style="width:' + Math.max(4, q.score) + '%"></span></div>'
         +   '<ul class="hs-checks">' + rows + '</ul>'
         +   '<p class="hs-report-foot">' + foot + '</p>'
         +   (extra || '')
         + '</div>';
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

      // Build the final advisory result from geometry (may be null when no face
      // was detected or the model couldn't load) plus the pixel-quality scan.
      // The check is ADVISORY: ok is always true here — a photo is still
      // required (picked + uploaded) and a human confirms it in the dashboard,
      // but we never hard-block on geometry or quality.
      function finish(geom, extra) {
        var metrics = computeImageMetrics(img, (geom && geom.box) || null);
        URL.revokeObjectURL(loaded.url);
        var q = assessQuality(geom, metrics);
        // Flat list of actionable tips (warn + fail), for any consumer that just
        // wants strings; the structured `checks` drive the rich UI.
        var warnings = q.checks
          .filter(function (c) { return c.status === 'warn' || c.status === 'fail'; })
          .map(function (c) { return c.detail; })
          .filter(Boolean);
        return Object.assign({
          ok: true,
          reasons: [],
          warnings: warnings,
          checks: q.checks,
          quality: { score: q.score, grade: q.grade },
          metrics: metrics,
          face: geom ? {
            score:    geom.score,
            pct:      round2(geom.pct),
            tilt_deg: Math.round(geom.tiltDeg),
            yaw:      (geom.yawRatio != null) ? round2(geom.yawRatio) : null,
            box:      geom.box,
            size:     { w: w, h: h },
            keypoints: geom.keypoints
          } : null
        }, extra || {});
      }

      return detectorWithTimeout(DETECT_TIMEOUT_MS).then(function (detector) {
        var result;
        try {
          result = detector.detect(img);
        } catch (e) {
          // Detector loaded but failed at inference — still give a quality scan.
          return finish(null, { skipped: true, error: String(e && e.message || e) });
        }

        var faces = (result && result.detections) || [];
        if (!faces.length) return finish({ faceCount: 0 }, { soft: true });

        var d = faces[0];
        var bb = d.boundingBox;       // { originX, originY, width, height } in pixels
        var score = (d.categories && d.categories[0] && d.categories[0].score) || 0;
        var pct = Math.min(bb.width, bb.height) / Math.min(w, h);
        var cx = (bb.originX + bb.width / 2) / w;
        var cy = (bb.originY + bb.height / 2) / h;

        var rightEye = kp(d, KP.RIGHT_EYE, w, h);
        var leftEye  = kp(d, KP.LEFT_EYE,  w, h);
        var nose     = kp(d, KP.NOSE,      w, h);
        var tiltDeg = 0, yawRatio = null;
        if (rightEye && leftEye) {
          tiltDeg = Math.abs(Math.atan2(leftEye.y - rightEye.y, leftEye.x - rightEye.x) * 180 / Math.PI);
          if (tiltDeg > 90) tiltDeg = 180 - tiltDeg;
          // Yaw proxy: how far the nose sits from the eye-midpoint, relative to
          // the eye span. ~0 looking straight on; grows as the head turns.
          var span = Math.abs(leftEye.x - rightEye.x);
          if (nose && span > 1) yawRatio = Math.abs(nose.x - (rightEye.x + leftEye.x) / 2) / span;
        }

        return finish({
          faceCount:   faces.length,
          score:       Math.round(score * 100) / 100,
          pct:         pct,
          cx:          cx,
          cy:          cy,
          tiltDeg:     tiltDeg,
          yawRatio:    yawRatio,
          eyesVisible: !!(rightEye && leftEye),
          box:         { x: bb.originX, y: bb.originY, w: bb.width, h: bb.height },
          keypoints:   d.keypoints
        });
      }).catch(function (err) {
        // Detector failed to load (offline, CDN blocked, browser too old). Don't
        // block the applicant — a pixel-only quality scan still runs, and the
        // backend knows a human should eyeball this photo.
        return finish(null, { skipped: true, error: String(err && err.message || err) });
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
        // Professional per-criterion report card. Advisory throughout: any
        // warn/fail is a tip, never a block — the applicant can always continue.
        var anyIssue = (v.checks || []).some(function (c) { return c.status === 'warn' || c.status === 'fail'; });
        var tone = anyIssue ? 'warn' : 'ok';
        setStatus(tone, renderReport(v));
        if (opts.onChange) opts.onChange(state);

        // Best-effort Cloudinary upload — only attempts if configured. The
        // report stays visible; the upload state shows inside the same card.
        var cdn = (window.MAM_CONFIG && window.MAM_CONFIG.cdn) || {};
        if (cdn.cloudName && cdn.uploadPreset) {
          setStatus(tone, renderReport(v, '<div class="hs-upload hs-upload-working"><i class="fa-solid fa-cloud-arrow-up fa-fade"></i> Uploading your headshot…</div>'));
          upload(file).then(function (u) {
            state.uploaded = u;
            setStatus(tone, renderReport(v, '<div class="hs-upload hs-upload-ok"><i class="fa-solid fa-circle-check"></i> Headshot uploaded.</div>'));
            if (opts.onChange) opts.onChange(state);
          }).catch(function () {
            setStatus(tone, renderReport(v, "<div class=\"hs-upload hs-upload-warn\"><i class=\"fa-solid fa-triangle-exclamation\"></i> We couldn't reach the image CDN, but your photo is saved on this device.</div>"));
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

    // Warm the face model the moment the dropzone mounts, so the heavy CDN/WASM
    // download overlaps with the applicant filling the form. By the time they
    // pick a photo the detector is usually ready and the check is near-instant —
    // this is what fixes the "verification takes forever" first-load wait.
    try { ensureDetector().catch(function () {}); } catch (e) {}

    return { state: function () { return state; }, clear: clear };
  }

  window.MAM_HEADSHOT = { mount: mount, validate: validate, upload: upload };
})();
