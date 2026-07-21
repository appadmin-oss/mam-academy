/**
 * MAM Academy — Image CDN (Cloudinary fetch)
 * ─────────────────────────────────────────────────────────────
 * Defines window.MAM_CDN, which routes public image URLs through
 * Cloudinary's /image/fetch/ transform pipeline so every browser
 * receives the smallest, most appropriate format automatically
 * (f_auto picks WebP/AVIF; q_auto chooses quality; w_N resizes).
 *
 * Configuration comes from MAM_CONFIG.cdn (set in config.js, then
 * live-patched by the /api/config/public hydration response):
 *   cloudName    — Cloudinary cloud name (e.g. 'mam-academy').
 *                  Empty = entire module is a safe no-op.
 *   defaultWidth — cap for hero / full-bleed images (default 1920)
 *   contentWidth — cap for in-content images (default 1200)
 *
 * Headshot uploads (apply form, student portal) use the Cloudinary
 * unsigned-upload widget via headshot.js, which reads
 * MAM_CONFIG.cdn.uploadPreset separately.
 *
 * Public API:
 *   MAM_CDN.image(url, opts)     — transform one URL
 *   MAM_CDN.rewriteAll(doc)      — bulk-rewrite img[src] + [data-bg]
 *
 * Opt any element out with the `data-no-cdn` attribute.
 * Safe to call before MAM_CONFIG.cdn.cloudName is set — returns the
 * original URL and leaves the DOM untouched.
 */
(function () {
  'use strict';

  function cdn() {
    return (window.MAM_CONFIG && window.MAM_CONFIG.cdn) || {};
  }

  /**
   * Return a Cloudinary fetch URL for `rawUrl` with the given
   * transforms applied. Returns `rawUrl` unchanged when:
   *   - cloudName is not set
   *   - the URL is not absolute http(s)
   *   - the URL is already a Cloudinary URL (no double-wrapping)
   *
   * opts:
   *   width  (int)   — resize to this pixel width
   *   height (int)   — resize to this pixel height
   *   crop   (str)   — c_fill / c_fit / c_thumb (default: none)
   */
  function image(rawUrl, opts) {
    var c = cdn();
    if (!c.cloudName || !rawUrl) return rawUrl || '';
    if (/res\.cloudinary\.com/i.test(rawUrl)) return rawUrl;      // already CDN
    if (!/^https?:\/\//i.test(rawUrl)) return rawUrl;             // relative / data URI

    opts = opts || {};
    var t = 'f_auto,q_auto';
    if (opts.width  && (opts.width  | 0) > 0) t += ',w_' + (opts.width  | 0);
    if (opts.height && (opts.height | 0) > 0) t += ',h_' + (opts.height | 0);
    if (opts.crop) t += ',c_' + String(opts.crop).replace(/[^a-z_]/g, '');

    return 'https://res.cloudinary.com/' + c.cloudName
         + '/image/fetch/' + t + '/' + encodeURIComponent(rawUrl);
  }

  /**
   * Walk `doc` (defaults to the current document) and rewrite all
   * qualifying img[src] and [data-bg] elements through the CDN.
   * Called once by main.js after page partials are in place.
   * Safe no-op when cloudName is empty.
   */
  function rewriteAll(doc) {
    var c = cdn();
    if (!c.cloudName) return;
    doc = doc || document;

    var dw = (c.defaultWidth | 0) || 1920;
    var cw = (c.contentWidth | 0) || 1200;

    var imgs = doc.querySelectorAll('img[src]:not([data-no-cdn])');
    for (var i = 0; i < imgs.length; i++) {
      var img = imgs[i];
      var src = img.getAttribute('src') || '';
      var w   = (parseInt(img.getAttribute('width') || '0', 10) || 0) || cw;
      var cdn_src = image(src, { width: w });
      if (cdn_src !== src) img.setAttribute('src', cdn_src);
    }

    var bgs = doc.querySelectorAll('[data-bg]:not([data-no-cdn])');
    for (var j = 0; j < bgs.length; j++) {
      var el  = bgs[j];
      var bg  = el.getAttribute('data-bg') || '';
      var cdn_bg = image(bg, { width: dw });
      if (cdn_bg !== bg) el.style.backgroundImage = 'url(' + cdn_bg + ')';
    }
  }

  window.MAM_CDN = { image: image, rewriteAll: rewriteAll };
})();
