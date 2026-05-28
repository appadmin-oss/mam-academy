/**
 * MAM Academy — Frontend Configuration
 * ---------------------------------------------------------------
 * Only public values live here. All secrets stay server-side in
 * /api/.env. The backend is the source of truth for pricing,
 * application records and payment verification.
 * ---------------------------------------------------------------
 */
window.MAM_CONFIG = {
  /* -------- Site identity -------- */
  site: {
    name: 'MAM Academy',
    url: 'https://academy.mamcentre.org',
    contactEmail: 'connect@mamcentre.org',
    supportPhone: '+234 800 000 0000',
    address: 'MAM Centre, Lagos, Nigeria'
  },

  /* -------- API -------- */
  api: {
    base: 'api',                  // relative; auto-resolves to /api on the same origin
    healthPath: 'health',
    csrfPath: 'csrf',
    applicationPath: 'application',
    paymentInitPath: 'payment/initialize',
    paymentVerifyPath: 'payment/verify',
    newsletterPath: 'newsletter',
    contactPath: 'contact'
  },

  /* -------- Mailto fallback (used only if API unreachable) -------- */
  forms: {
    fallbackEmail: 'connect@mamcentre.org'
  },

  /* -------- Image CDN (Cloudinary fetch) --------
     Set `cloudName` to your Cloudinary cloud name to route every <img>
     and inline page-hero background through Cloudinary, with q_auto and
     f_auto applied so each browser gets the smallest right-sized variant.
     Leaving cloudName empty is a safe no-op — the site keeps using the
     original URLs. Opt an asset out with the `data-no-cdn` attribute. */
  cdn: {
    provider: 'cloudinary',
    cloudName: '',          // e.g. 'mam-academy' — set this once and every image is served via the CDN
    defaultWidth: 1920,     // hero/full-bleed cap
    contentWidth: 1200,     // in-content image cap
    // Unsigned upload preset for applicant-supplied images (headshots).
    // Create one in Cloudinary → Settings → Upload → "Upload presets" with
    // signing mode = Unsigned, and folder restriction = mam-academy/.
    // Leaving this empty disables in-browser uploads — applicants can still
    // submit and we'll request the headshot manually.
    uploadPreset: ''
  },

  /* -------- Optional payment methods --------
     Server returns the public keys + payloads in payment/initialize.
     This section is only used to decide which method buttons show in
     the UI before the server has had a chance to respond. */
  paymentMethods: {
    paystack:    { label: 'Paystack',    enabled: true },
    flutterwave: { label: 'Flutterwave', enabled: true },
    stripe:      { label: 'Stripe',      enabled: false }, // enable in admin when server-side ready
    bank:        { label: 'Bank Transfer', enabled: true }
  },

  /* -------- Currency conversion (display only) -------- */
  currency: {
    base: 'USD',
    rates: { USD: 1, NGN: 1500, GBP: 0.79, EUR: 0.92 }
  },

  /* -------- Pricing (display only — server enforces real total) -------- */
  pricing: {
    'pre-cert':   { name: 'Pre-Certification (Grade 1–2)', usd: 120 },
    'cert':       { name: 'Certification (Grade 3–4)',      usd: 150 },
    'pre-dip':    { name: 'Pre-Diploma (Grade 5–6)',         usd: 160 },
    'diploma':    { name: 'Diploma (Grade 7–8)',             usd: 180 },
    'crash':      { name: 'Crash Programme (6 weeks)',       usd: 40  },
    'meta-order': { name: 'The MetaMusic Order (12 weeks)',  usd: 240 }
  },
  registrationFeeUsd: 5,
  certificationFeeUsd: 22,

  /* -------- Testimonials (edit here; the homepage rotator reads this) --------
     `photo` is optional — when set it shows a real headshot (routed through
     Cloudinary if configured); otherwise we render the initials avatar. Keep
     these to genuine, attributable student/parent stories. */
  testimonials: [
    { stars: 5, quote: "MAM Academy didn't just teach my son piano — they taught him discipline, consistency and excellence. He's a completely different child.", name: 'Mrs. Adaeze Mba', role: 'Parent · Lagos', initials: 'AM', photo: '' },
    { stars: 5, quote: 'I started with zero knowledge. Within one year I was playing in our worship team. The instructors are incredibly patient and gifted.', name: 'Tunde Nwosu', role: 'Piano · 2024', initials: 'TN', photo: '' },
    { stars: 5, quote: 'MAM cares about who you become, not just what you can play. Music is the vehicle — character is the destination.', name: 'Chisom Ike', role: 'Violin · 2023', initials: 'CI', photo: '' },
    { stars: 5, quote: 'My daughter was shy and introverted. MAM has transformed her — she now performs with complete confidence.', name: 'Mr. Patrick Obi', role: 'Parent · Abuja', initials: 'PO', photo: '' },
    { stars: 5, quote: 'The MAM Auditer placed me exactly where I needed to be. No guessing. The growth has been measurable from week one.', name: 'Kemi Aluko', role: 'Voice · 2025', initials: 'KA', photo: '' },
    { stars: 5, quote: 'MetaMusic changed how I think about sound — not just how I play. This place is special.', name: 'Emmanuel Effiong', role: 'Drums · 2024', initials: 'EE', photo: '' },
    { stars: 5, quote: 'Power Play with Van. BOJA reset my entire vision as a musician. I now think like a builder, not just a performer.', name: 'Nneka Abosi', role: 'Power Play · 2025', initials: 'NA', photo: '' }
  ],

  /* -------- Power Play series (free monthly broadcast) --------
     Held on the first Wednesday of every month. Admin-tunable:
       - time/timezone format the displayed start
       - meetUrl is the persistent Google Meet room link
       - rrule overrides the default "first-wednesday" if you ever
         need to move the cadence (e.g. 'last-friday'). Supported:
         first-monday … first-sunday, last-monday … last-sunday.    */
  powerPlay: {
    rrule:    'first-wednesday',
    time:     '19:00',           // 24-hour clock in the local `timezone`
    timezone: 'Africa/Lagos',
    durationMinutes: 90,
    meetUrl:  '',                // paste your persistent meet link here
    host:     'Van. BOJA'
  },

  /* -------- Next intake (for the homepage countdown) -------- */
  intake: {
    startDate: '2026-05-05T00:00:00Z',
    label: 'May 2026',
    spotsTotal: 25,
    spotsRemaining: 7
  },

  /* -------- Programme tracks (age-based routing) --------
     KTAMS = Kids @ MAM (under 16) · MAM = main academy (16+). The track
     is derived from the applicant's age and shown in the form; the
     server re-derives it so it can never be spoofed. */
  tracks: {
    KTAMS: { name: 'KTAMS — Kids @ MAM', note: 'Our nurturing junior track for young musicians under 16. A parent or guardian co-enrols.' },
    MAM:   { name: 'MAM Academy', note: 'The full academy experience for ages 16 and above.' }
  },

  /* -------- Class policies (short summaries shown before consent) --------
     Online/home vs onsite classes have different policies. The full
     signed policy is emailed on submit; this is the at-a-glance summary
     the applicant agrees to inside the form. */
  policies: {
    online: {
      title: 'MAM Academy Policy — Online / Home Classes',
      intro: 'A quick summary of what keeps online classes effective. The full policy is emailed to you on submission.',
      points: [
        'A quiet, distraction-free space; phones on silent — no calls during class.',
        'Be ready 15 minutes early. Max 2 late arrivals; lateness without 2-hour notice incurs a ₦500 / $0.50 fee.',
        'Submit assignments on time (late submission: ₦1,000 / $1).',
        'Max 2 absences — notify 24 hours ahead, or a ₦1,000 / $1 fee applies.',
        'Rescheduling needs 24-hour notice plus a ₦2,000 / $2 inconvenience fee.',
        'Two internet-connected devices: one on your instrument, one on your face.',
        'Daily online practice review at the agreed time; certificate after revision + exam.',
        'All payments are final and strictly non-refundable.'
      ]
    },
    onsite: {
      title: 'MAM Academy Policy — Onsite Classes (Lagos)',
      intro: 'A quick summary of what keeps onsite classes effective. The full policy is emailed to you on submission.',
      points: [
        'Arrive 30 minutes early. Lateness without 2-hour notice incurs a ₦1,000 fee.',
        'Notify absences at least 24 hours ahead, or a ₦2,000 fee applies.',
        'Submit assignments on time (late: ₦1,000) plus a daily 1-minute practice video.',
        'No phone use in class — switched off or on silent.',
        'Maintain decorum and participate actively in all activities.',
        'Daily practice of concepts taught; certificate after revision + exam.',
        'All payments are strictly non-refundable.'
      ]
    }
  }
};

/* -------- API client helper -------- */
window.MAM_API = (function () {
  var cfg = window.MAM_CONFIG.api;
  var csrfToken = null, csrfFetchedAt = 0;

  function url(path) { return cfg.base.replace(/\/$/, '') + '/' + path.replace(/^\//, ''); }

  function getCsrf(force) {
    if (!force && csrfToken && (Date.now() - csrfFetchedAt) < 6000 * 1000) return Promise.resolve(csrfToken);
    return fetch(url(cfg.csrfPath), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        // Never let an HTML error page surface as "Unexpected token '<'".
        return r.text().then(function (t) {
          var j = {};
          try { j = JSON.parse(t); } catch (e) { throw apiUnavailable(r.status); }
          if (!r.ok || !j.token) throw apiUnavailable(r.status);
          return j;
        });
      })
      .then(function (j) { csrfToken = j.token; csrfFetchedAt = Date.now(); return csrfToken; });
  }

  function apiUnavailable(status) {
    var e = new Error('api_unavailable');
    e.status = status || 0; e.apiDown = true;
    return e;
  }

  function request(path, opts) {
    opts = opts || {};
    var method = (opts.method || 'GET').toUpperCase();
    var headers = Object.assign({ 'Content-Type': 'application/json', 'Accept': 'application/json' }, opts.headers || {});
    var fire = function (token) {
      if (token) headers['X-CSRF-Token'] = token;
      return fetch(url(path), {
        method: method,
        headers: headers,
        credentials: 'same-origin',
        body: opts.body != null && method !== 'GET' ? JSON.stringify(opts.body) : undefined
      }).then(function (r) {
        return r.text().then(function (t) {
          var data = {}, parsed = true;
          if (t) { try { data = JSON.parse(t); } catch (e) { parsed = false; } }
          if (!parsed) {
            // Server returned HTML/text (misconfig, 404 page, maintenance) —
            // surface a clean, actionable error instead of a parser crash.
            throw apiUnavailable(r.status);
          }
          if (!r.ok) {
            var err = new Error(data.error || ('http_' + r.status));
            err.status = r.status; err.body = data;
            throw err;
          }
          return data;
        });
      });
    };
    if (method === 'GET') return fire(null);
    return getCsrf().then(fire).catch(function (e) {
      if (e && e.status === 403 && (e.body || {}).error === 'csrf_invalid') {
        // Token may have expired — fetch a fresh one and retry once
        return getCsrf(true).then(fire);
      }
      throw e;
    });
  }

  return {
    health:           function ()      { return request(cfg.healthPath); },
    submitApplication: function (data) { return request(cfg.applicationPath, { method: 'POST', body: data }); },
    initializePayment: function (data) { return request(cfg.paymentInitPath, { method: 'POST', body: data }); },
    verifyPayment:     function (data) { return request(cfg.paymentVerifyPath, { method: 'POST', body: data }); },
    subscribeNewsletter: function (email, source) { return request(cfg.newsletterPath, { method: 'POST', body: { email: email, source: source || 'site' } }); },
    sendContact:       function (data) { return request(cfg.contactPath, { method: 'POST', body: data }); },
    url: url
  };
})();

/* -------- Cloudinary fetch helper --------
   Wraps any absolute image URL in a Cloudinary fetch URL so we get q_auto,
   f_auto, the right width and DPR variant — without uploading anything.
   Returns the original URL when no cloud name is configured. */
window.MAM_CDN = (function () {
  var cdn = (window.MAM_CONFIG && window.MAM_CONFIG.cdn) || {};

  function isHttp(u) { return /^https?:\/\//i.test(u || ''); }

  function image(src, opts) {
    if (!cdn.cloudName || !isHttp(src)) return src;
    opts = opts || {};
    var dpr = Math.min(3, Math.round(window.devicePixelRatio || 1));
    var w   = opts.width || cdn.contentWidth || 1200;
    var transforms = ['q_auto', 'f_auto', 'w_' + w, 'dpr_' + dpr];
    if (opts.crop)    transforms.push('c_' + opts.crop);
    if (opts.gravity) transforms.push('g_' + opts.gravity);
    return 'https://res.cloudinary.com/' + cdn.cloudName + '/image/fetch/' + transforms.join(',') + '/' + encodeURIComponent(src);
  }

  /**
   * Rewrite every <img> and inline page-hero background on the page to go
   * through Cloudinary. Run once partials/header/footer have loaded.
   * Opt-out per element with `data-no-cdn`.
   */
  function rewriteAll(root) {
    if (!cdn.cloudName) return; // no-op until cloud name is configured
    root = root || document;

    // Heroes use `--page-hero-img:url(...)` inline; route those through CDN at hero width
    root.querySelectorAll('[style*="--page-hero-img"]').forEach(function (el) {
      if (el.hasAttribute('data-no-cdn') || el.hasAttribute('data-cdn-done')) return;
      var s = el.getAttribute('style') || '';
      var m = s.match(/--page-hero-img\s*:\s*url\(['"]?([^'")]+)['"]?\)/);
      if (!m || !isHttp(m[1])) return;
      var cdnUrl = image(m[1], { width: cdn.defaultWidth || 1920 });
      el.setAttribute('style', s.replace(m[0], "--page-hero-img:url('" + cdnUrl + "')"));
      el.setAttribute('data-cdn-done', '1');
    });

    // <img src="https://..."> — skip same-origin, data:, blob:, the logo, and explicit opt-outs
    root.querySelectorAll('img[src]').forEach(function (img) {
      if (img.hasAttribute('data-no-cdn') || img.hasAttribute('data-cdn-done')) return;
      var src = img.getAttribute('src') || '';
      if (!isHttp(src)) return;
      var width = parseInt(img.getAttribute('width') || '0', 10) || undefined;
      img.setAttribute('src', image(src, { width: width }));
      img.setAttribute('data-cdn-done', '1');
    });
  }

  return { image: image, rewriteAll: rewriteAll };
})();
