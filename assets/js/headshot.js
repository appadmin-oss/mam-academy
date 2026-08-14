/**
 * MAM Academy — Headshot upload + validation widget
 *
 * Provides window.MAM_HEADSHOT.mount(element, opts).
 *
 * Validation: uses the browser's FaceDetector API where available
 * (Chromium 70+), falls back to basic brightness heuristic.
 * Upload: posts unsigned to Cloudinary when MAM_CONFIG.cdn is configured.
 *
 * State object passed to opts.onChange:
 * {
 *   file:       File|null,
 *   preview:    string|null,   // data: URI for the <img> preview
 *   validation: { ok:bool, reason:string|null, quality:string|null },
 *   uploaded:   { url:string, publicId:string }|null,
 *   uploading:  bool,
 *   error:      string|null,
 * }
 */
(function () {
  'use strict';

  function cdnCfg() {
    return (window.MAM_CONFIG && window.MAM_CONFIG.cdn) || {};
  }

  /* Cloudinary's cloud name and preset are admin settings delivered by an ASYNC
     fetch of /api/config/public (config.js patches MAM_CONFIG.cdn, then fires
     `mam:config`). Nothing here used to wait for it, so an applicant who picked a
     photo before that landed was judged "Cloudinary not configured" even when it
     WAS — and the old else-branch then said "✓ Photo accepted" and uploaded
     nothing. Resolve as soon as the config arrives, or give up after a beat and
     let the caller fall back to first-party storage. */
  var _cfgReady = null;
  function configReady() {
    if (_cfgReady) return _cfgReady;
    _cfgReady = new Promise(function (resolve) {
      var c = cdnCfg();
      if (c.cloudName && c.uploadPreset) return resolve(cdnCfg());
      var done = false;
      var fin = function () { if (!done) { done = true; resolve(cdnCfg()); } };
      document.addEventListener('mam:config', fin, { once: true });
      setTimeout(fin, 4000);
    });
    return _cfgReady;
  }

  /* Detect a face in an image element. Resolves to { ok, reason, quality } */
  function detectFace(img) {
    if (typeof window.FaceDetector === 'function') {
      var fd = new window.FaceDetector({ fastMode: true, maxDetectedFaces: 5 });
      return fd.detect(img).then(function (faces) {
        if (faces.length === 0) {
          return { ok: false, reason: 'No face detected — use a clear, front-facing photo.', quality: 'no-face' };
        }
        if (faces.length > 1) {
          return { ok: false, reason: 'Multiple faces detected — the photo must show only you.', quality: 'multi-face' };
        }
        return { ok: true, reason: null, quality: 'good' };
      }).catch(function () {
        return basicQualityCheck(img);
      });
    }
    return Promise.resolve(basicQualityCheck(img));
  }

  /* Fallback: canvas brightness heuristic */
  function basicQualityCheck(img) {
    try {
      var W = 64; var H = 64;
      var cv = document.createElement('canvas');
      cv.width = W; cv.height = H;
      var ctx = cv.getContext('2d');
      ctx.drawImage(img, 0, 0, W, H);
      var d = ctx.getImageData(0, 0, W, H).data;
      var total = 0;
      for (var i = 0; i < d.length; i += 4) {
        total += (d[i] * 299 + d[i + 1] * 587 + d[i + 2] * 114) / 1000;
      }
      var avg = total / (W * H);
      if (avg < 18)  return { ok: false, reason: 'Photo is too dark — try in better lighting.', quality: 'dark' };
      if (avg > 248) return { ok: false, reason: 'Photo is overexposed — try in softer lighting.', quality: 'overexposed' };
    } catch (e) { /* canvas tainted / unavailable — accept anyway */ }
    return { ok: true, reason: null, quality: 'good' };
  }

  /* Store the photo on OUR OWN server. This is the floor that guarantees a
     registration photo is never lost: previously, with Cloudinary unconfigured the
     widget said "✓ Photo accepted", uploaded nothing, and the form submitted an
     empty headshotUrl — the academy got a registration with no photograph and
     printed a faceless ID card. Resolves to { url } (a same-origin
     /api/media/headshot?k=… URL the form submits exactly like a Cloudinary one). */
  /* Resolve an API path. MAM_API.url() is the one place that knows the configured
     base and normalises it, and it matters here: a page under /portal/ or /admin/
     resolving a relative base would POST to /portal/api/… and 404. */
  function apiUrl(path) {
    if (window.MAM_API && typeof window.MAM_API.url === 'function') return window.MAM_API.url(path);
    var c = window.MAM_CONFIG || {};
    var base = c.apiBase || (c.api && c.api.base) || '/api';
    return String(base).replace(/\/$/, '') + path;
  }

  /* A CSRF token for the upload POST.
     /api/upload/headshot is CSRF-gated, so a request without a valid token is a
     403 and the photo is lost. There was no token: this asked MAM_API for a
     `csrfToken()` that has never existed, then fell back to a <meta name=csrf-token>
     that the registration pages do not carry — so the header was always absent and
     EVERY first-party upload failed. Tokens are minted by /api/csrf, so fetch one. */
  function csrfFor(force) {
    if (window.MAM_API && typeof window.MAM_API.csrf === 'function') {
      return window.MAM_API.csrf(force).catch(function () { return ''; });
    }
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return Promise.resolve(meta.content);
    return fetch(apiUrl('/csrf'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { return (j && j.token) || ''; })
      .catch(function () { return ''; });
  }

  function postHeadshot(dataUrl, token) {
    var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (token) headers['X-CSRF-Token'] = token;
    return fetch(apiUrl('/upload/headshot'), {
      method: 'POST', credentials: 'same-origin', headers: headers,
      body: JSON.stringify({ image: dataUrl })
    }).then(function (r) {
      return r.text().then(function (t) {
        var j = {};
        try { j = JSON.parse(t); } catch (e) { throw new Error('Upload response unreadable'); }
        if (!r.ok || j.ok === false || !j.url) {
          var err = new Error((j && (j.detail || j.message)) || 'Could not save the photo.');
          err.code = j && j.error;
          throw err;
        }
        return { url: j.url, publicId: null };
      });
    });
  }

  function uploadToOwnServer(dataUrl) {
    return csrfFor(false)
      .then(function (tok) { return postHeadshot(dataUrl, tok); })
      // A token can expire while an applicant fills in a long form. Refetch once
      // and retry, exactly as MAM_API.request() does for its own POSTs.
      .catch(function (e) {
        if (e && e.code === 'csrf_invalid') {
          return csrfFor(true).then(function (tok) { return postHeadshot(dataUrl, tok); });
        }
        throw e;
      });
  }

  /* Upload to Cloudinary unsigned. Resolves to { url, publicId }. */
  function uploadToCloudinary(file, cloudName, preset, onProgress) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      fd.append('file', file);
      fd.append('upload_preset', preset);
      fd.append('folder', 'mam-headshots');
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'https://api.cloudinary.com/v1_1/' + encodeURIComponent(cloudName) + '/image/upload');
      xhr.timeout = 60000;
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable && typeof onProgress === 'function') {
          onProgress(Math.round(e.loaded / e.total * 100));
        }
      };
      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          try { var r = JSON.parse(xhr.responseText); resolve({ url: r.secure_url, publicId: r.public_id }); }
          catch (e) { reject(new Error('Upload response unreadable')); }
        } else {
          reject(new Error('Upload failed (' + xhr.status + ')'));
        }
      };
      xhr.onerror   = function () { reject(new Error('Upload failed — check your connection.')); };
      xhr.ontimeout = function () { reject(new Error('Upload timed out.')); };
      xhr.send(fd);
    });
  }

  /* ── Styles (injected once) ──────────────────────────────────── */
  var STYLE_ID = 'mam-hs-css';
  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var el = document.createElement('style');
    el.id = STYLE_ID;
    el.textContent =
      '.mam-hs{position:relative;border:2px dashed var(--border-gold,#7A5C1E);border-radius:12px;' +
      'padding:20px 16px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;' +
      'background:rgba(232,150,31,.05);min-height:130px;display:flex;flex-direction:column;' +
      'align-items:center;justify-content:center;gap:8px;}' +
      '.mam-hs:hover,.mam-hs.drag-over{border-color:var(--gold,#E8961F);background:rgba(232,150,31,.1);}' +
      '.mam-hs input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;font-size:0;}' +
      '.mam-hs-icon{font-size:32px;line-height:1;pointer-events:none;}' +
      '.mam-hs-label{font-size:13px;color:var(--muted,#a09080);pointer-events:none;}' +
      '.mam-hs-label b{color:var(--gold,#E8961F);}' +
      '.mam-hs-accept{font-size:11px;color:var(--muted,#999);pointer-events:none;}' +
      '.mam-hs-pw{display:flex;align-items:center;gap:14px;text-align:left;width:100%;pointer-events:none;}' +
      '.mam-hs-thumb{width:70px;height:70px;object-fit:cover;border-radius:50%;' +
      'border:2px solid var(--gold,#E8961F);flex-shrink:0;}' +
      '.mam-hs-meta{flex:1;min-width:0;}' +
      '.mam-hs-fname{font-size:11px;color:var(--muted,#999);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px;}' +
      '.mam-hs-sl{font-size:13px;font-weight:600;display:flex;align-items:center;gap:5px;}' +
      '.mam-hs-sl.ok{color:#4caf50;}.mam-hs-sl.err{color:#e53935;}.mam-hs-sl.busy{color:var(--gold,#E8961F);}' +
      '.mam-hs-chg{font-size:11px;color:var(--gold,#E8961F);cursor:pointer;text-decoration:underline;' +
      'background:none;border:none;padding:0;margin-top:5px;pointer-events:auto;}' +
      '.mam-hs-bw{height:3px;background:rgba(232,150,31,.18);border-radius:2px;width:100%;margin-top:5px;overflow:hidden;}' +
      '.mam-hs-bar{height:100%;background:var(--gold,#E8961F);width:0;transition:width .2s;}' +
      '@keyframes mam-hs-spin{to{transform:rotate(360deg)}}' +
      '.mam-hs-spin{display:inline-block;width:13px;height:13px;border:2px solid currentColor;' +
      'border-top-color:transparent;border-radius:50%;animation:mam-hs-spin .7s linear infinite;}' +
      '.headshot-required-pulse .mam-hs{animation:mam-hs-pulse 1s 3;}' +
      '@keyframes mam-hs-pulse{0%,100%{border-color:var(--border-gold,#7A5C1E);}50%{border-color:#e53935;}}';
    document.head.appendChild(el);
  }

  /* ── Mount ───────────────────────────────────────────────────── */
  function mount(container, opts) {
    injectStyles();
    opts = opts || {};
    var onChange = typeof opts.onChange === 'function' ? opts.onChange : function () {};

    var state = {
      file: null, preview: null,
      validation: { ok: false, reason: null, quality: null },
      uploaded: null, uploading: false, error: null,
    };

    container.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.className = 'mam-hs';

    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.setAttribute('aria-label', 'Upload your headshot photo');

    var idle = document.createElement('div');
    idle.innerHTML =
      '<div class="mam-hs-icon">&#128248;</div>' +
      '<div class="mam-hs-label"><b>Click to select</b> or drag your photo here</div>' +
      '<div class="mam-hs-accept">JPG &middot; PNG &middot; Max 10&thinsp;MB &middot; Clear front-facing headshot</div>';

    wrap.appendChild(input);
    wrap.appendChild(idle);
    container.appendChild(wrap);

    function emit() { onChange(state); }

    /* Set a status line to icon + text using only safe DOM methods */
    function setStatus(sl, cssClass, iconHtml, msg) {
      sl.className = 'mam-hs-sl ' + cssClass;
      sl.innerHTML = '';
      if (iconHtml) {
        var ic = document.createElement('span');
        ic.innerHTML = iconHtml; // only hardcoded markup reaches here
        sl.appendChild(ic);
      }
      var txt = document.createTextNode(' ' + msg);
      sl.appendChild(txt);
    }

    /* Show the preview panel; returns handles to live-update status */
    function showPreview(file, src) {
      var pw    = document.createElement('div'); pw.className = 'mam-hs-pw';
      var thumb = document.createElement('img'); thumb.className = 'mam-hs-thumb'; thumb.src = src; thumb.alt = 'Preview';
      var meta  = document.createElement('div'); meta.className = 'mam-hs-meta';
      var nm    = document.createElement('div'); nm.className = 'mam-hs-fname'; nm.textContent = file.name;
      var sl    = document.createElement('div');
      setStatus(sl, 'busy', '<span class="mam-hs-spin"></span>', 'Checking photo…');
      var bw    = document.createElement('div'); bw.className = 'mam-hs-bw';
      var bar   = document.createElement('div'); bar.className = 'mam-hs-bar'; bw.appendChild(bar);
      var chg   = document.createElement('button'); chg.type = 'button'; chg.className = 'mam-hs-chg';
      chg.textContent = 'Choose a different photo';
      meta.appendChild(nm); meta.appendChild(sl); meta.appendChild(bw); meta.appendChild(chg);
      pw.appendChild(thumb); pw.appendChild(meta);
      wrap.innerHTML = ''; wrap.appendChild(input); wrap.appendChild(pw);
      chg.addEventListener('click', function (e) { e.stopPropagation(); reset(); });
      return { sl: sl, bar: bar };
    }

    function reset() {
      state = { file: null, preview: null, validation: { ok: false, reason: null, quality: null }, uploaded: null, uploading: false, error: null };
      wrap.innerHTML = ''; wrap.appendChild(input); wrap.appendChild(idle);
      input.value = '';
      emit();
    }

    function handleFile(file) {
      if (!file || !file.type.match(/^image\//)) {
        state.validation = { ok: false, reason: 'Please select an image file (JPG or PNG).', quality: 'wrong-type' };
        emit(); return;
      }
      if (file.size > 10 * 1024 * 1024) {
        state.validation = { ok: false, reason: 'Photo is too large — maximum 10 MB.', quality: 'too-large' };
        emit(); return;
      }
      var reader = new FileReader();
      reader.onload = function (ev) {
        var src = ev.target.result;
        state = { file: file, preview: src, validation: { ok: false, reason: null, quality: 'checking' }, uploaded: null, uploading: false, error: null };
        emit();

        var ui = showPreview(file, src);

        var imgEl = new Image();
        imgEl.onload = function () {
          detectFace(imgEl).then(function (res) {
            state.validation = res;
            if (!res.ok) {
              setStatus(ui.sl, 'err', null, res.reason || 'Photo check failed');
              emit(); return;
            }
            configReady().then(function (c) {
            if (c.cloudName && c.uploadPreset) {
              state.uploading = true;
              setStatus(ui.sl, 'busy', '<span class="mam-hs-spin"></span>', 'Uploading…');
              emit();
              uploadToCloudinary(file, c.cloudName, c.uploadPreset, function (pct) {
                ui.bar.style.width = pct + '%';
              }).then(function (r) {
                state.uploading = false; state.uploaded = r; state.error = null;
                ui.bar.style.width = '100%';
                setStatus(ui.sl, 'ok', null, '✓ Uploaded');
                emit();
              }).catch(function (e) {
                state.uploading = false; state.error = e.message || 'Upload failed';
                setStatus(ui.sl, 'err', null, state.error);
                emit();
              });
            } else {
              /* No usable Cloudinary config — store it on our own server instead of
                 pretending. The old branch here showed a green "✓ Photo accepted"
                 and uploaded NOTHING, which is why registrations arrived with no
                 photograph and cards printed faceless. */
              state.uploading = true;
              setStatus(ui.sl, 'busy', '<span class="mam-hs-spin"></span>', 'Saving photo…');
              emit();
              /* Shrink to a compact JPEG before sending, same as the standalone
                 upload() path below. Without this, a full-resolution phone photo
                 (allowed by the 10 MB client check) becomes an even larger base64
                 JSON body and can exceed the server's POST ceiling — a raw 413
                 with no field-level feedback, instead of a clean save. */
              downscaleDataUrl(src, 1200, 0.85).then(uploadToOwnServer).then(function (r) {
                state.uploading = false; state.uploaded = r; state.error = null;
                ui.bar.style.width = '100%';
                setStatus(ui.sl, 'ok', null, '✓ Saved');
                emit();
              }).catch(function (e) {
                state.uploading = false;
                state.error = e.message || 'Could not save the photo.';
                setStatus(ui.sl, 'err', null, state.error);
                emit();
              });
            }
            });
          });
        };
        imgEl.onerror = function () {
          state.validation = { ok: false, reason: 'Could not read image — try a different file.', quality: 'read-error' };
          emit();
        };
        imgEl.src = src;
      };
      reader.readAsDataURL(file);
    }

    input.addEventListener('change', function () {
      if (input.files && input.files[0]) handleFile(input.files[0]);
    });
    wrap.addEventListener('dragover',  function (e) { e.preventDefault(); wrap.classList.add('drag-over'); });
    wrap.addEventListener('dragleave', function ()  { wrap.classList.remove('drag-over'); });
    wrap.addEventListener('drop',      function (e) {
      e.preventDefault(); wrap.classList.remove('drag-over');
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) handleFile(f);
    });
  }

  /* ── Standalone upload, no widget ─────────────────────────────────
     For callers that already have their own file input and just want the photo
     stored: the student portal's profile page and the admin card-photo editor.
     Both are places where the only alternative on offer was pasting a link to an
     image hosted somewhere else.

     Resolves to { url } — Cloudinary's when it is configured, otherwise our own
     same-origin URL. Rejects with a message fit to show the user. */
  /* Downscale a data-URL image so its longest side ≤ maxPx and re-encode as a
     compact JPEG. Our /api/upload/headshot receives this as a base64 JSON body
     (~1.4× its size); a full-resolution phone photo can exceed the server's POST
     limit and come back as an HTML error page. Falls back to the original bytes
     on any failure (tainted canvas, decode error) so a photo is never lost. */
  function downscaleDataUrl(dataUrl, maxPx, quality) {
    return new Promise(function (resolve) {
      try {
        var img = new Image();
        img.onerror = function () { resolve(dataUrl); };
        img.onload = function () {
          try {
            var w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
            if (!w || !h) { resolve(dataUrl); return; }
            var scale = Math.min(1, maxPx / Math.max(w, h));
            var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
            var cv = document.createElement('canvas'); cv.width = cw; cv.height = ch;
            var ctx = cv.getContext('2d');
            if (!ctx) { resolve(dataUrl); return; }
            ctx.drawImage(img, 0, 0, cw, ch);
            var out = cv.toDataURL('image/jpeg', quality || 0.85);
            resolve(out && out.indexOf('data:image/jpeg') === 0 ? out : dataUrl);
          } catch (e) { resolve(dataUrl); }
        };
        img.src = dataUrl;
      } catch (e) { resolve(dataUrl); }
    });
  }

  function upload(file) {
    if (!file) return Promise.reject(new Error('No file selected.'));
    if (!/^image\//.test(file.type || '')) return Promise.reject(new Error('Please choose an image file (JPG or PNG).'));
    if (file.size > 25 * 1024 * 1024) return Promise.reject(new Error('That photo is too large — maximum 25 MB.'));

    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onerror = function () { reject(new Error('Could not read that file.')); };
      reader.onload = function (ev) { resolve(String(ev.target.result)); };
      reader.readAsDataURL(file);
    }).then(function (rawDataUrl) {
      // Compact JPEG for our own-server store (the base64 JSON path). Cloudinary
      // takes the raw File and needs no shrinking.
      return downscaleDataUrl(rawDataUrl, 1200, 0.85);
    }).then(function (dataUrl) {
      return configReady().then(function (c) {
        if (c.cloudName && c.uploadPreset) {
          // Cloudinary first when configured, but never at the cost of losing the
          // photo: fall through to our own store if it fails.
          return uploadToCloudinary(file, c.cloudName, c.uploadPreset, null)
            .catch(function () { return uploadToOwnServer(dataUrl); });
        }
        return uploadToOwnServer(dataUrl);
      });
    });
  }

  window.MAM_HEADSHOT = { mount: mount, upload: upload };
})();
