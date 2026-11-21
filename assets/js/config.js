/**
 * MAM Academy — Frontend Configuration
 * ---------------------------------------------------------------
 * Only public values live here. All secrets stay server-side in
 * /api/.env. The backend is the source of truth for pricing,
 * application records and payment verification.
 * ---------------------------------------------------------------
 */
window.MAM_CONFIG = {
  /* -------- Versions -------- */
  // uiVersion identifies the shipped front-end build (shown in the admin
  // footer, sent on support requests); apiVersion is the JSON-API major the
  // clients in this bundle are written against (see X-MAM-API-Version).
  uiVersion: '2026.07.06',
  apiVersion: 'v1',

  /* -------- Site identity -------- */
  site: {
    name: 'MAM Academy',
    url: 'https://academy.mamcentre.org',
    contactEmail: 'academy@mamcentre.org',
    supportPhone: '+234 704 444 6517',
    address: '2 Abolude Oremeji Street, Off Akowonjo Road, Bakery Bus Stop, Egbeda, Alimosho, Lagos, Nigeria'
  },

  /* Receiving bank account — static fallback; the live values come from
     /api/config/public (admin-editable in Pricing & Settings) and override
     these on the mam:config event. */
  bank: {
    bank: 'GTBank',
    account_name: 'The Just Folks Limited',
    account_number: '0117579643'
  },

  /* ============================================================
     SCHOLARSHIP — single source of truth (EDIT HERE, applies everywhere)
     ------------------------------------------------------------
     main.js reads this block and injects the banner/CTAs into every
     [data-scholarship-banner] placeholder and the global footer link.
     • To change the copy or destination, edit the fields below.
     • To turn the scholarship off site-wide, set enabled:false — every
       banner and link disappears automatically (no page edits needed).
     ============================================================ */
  scholarship: {
    enabled: true,
    url: 'https://mamcentre.org/scholarships/ngv',
    name: 'NGV Scholarship',
    eyebrow: 'Cost should never be the barrier',
    title: 'Can’t afford the fees? Apply for a scholarship.',
    blurb: 'The NGV Scholarship exists for talented students who genuinely cannot afford tuition. If money is the only thing standing between you and your training at MAM Academy, we want to hear from you — apply today.',
    cta: 'Apply for a Scholarship',
    short: 'Can’t afford it? A scholarship can cover your tuition.',
    note: 'Need financial help? The NGV Scholarship can cover your fees.'
  },

  /* -------- Frequently-changed marketing content (edit freely) --------
     Centralised so copy tweaks never mean hunting through page markup.
     Consumed by main.js where the matching [data-content="…"] hooks exist. */
  content: {
    registrationFeeNote: 'Registration is a one-time $5. Additional 30-minute courses are 50% of a major-course fee.',
    placementNote: 'You don’t buy a level — you choose a programme and progress through all four tiers. Your starting placement is set by the MAM Auditer assessment.'
  },

  /* -------- API -------- */
  api: {
    base: '/api',                 // root-absolute so portal/ & admin/ pages resolve correctly (never /portal/api → 404)
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
    fallbackEmail: 'academy@mamcentre.org'
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

  /* -------- Pricing (display only — server enforces real total) --------
     Two flagship programmes (available across all four certification levels)
     plus two standalone intensives. Online learners pay +onlineSurchargePct%
     on the programme fee; onsite is the standard fee. Keep in sync with
     mam_prices() in api/lib/validation.php. */
  pricing: {
    'standard':           { name: 'Standard',           usd: 120 },
    'certified-musician': { name: 'Certified Musician', usd: 240 },
    'grand-musician':     { name: 'Grand Musician',     usd: 313 },
    'crash':              { name: 'Crash Programme (6 weeks)',      usd: 70 },
    'meta-order':         { name: 'The MetaMusic Order (12 weeks)', usd: 60 }
  },
  registrationFeeUsd: 5,
  onlineSurchargePct: 20,

  /* -------- Level-based pricing model --------
     B = chosen level's base price; C = compulsory fee ($120, Certified/Grand).
     Standard = B · Certified/Grand = B + C + 0.5B·extra · Crash = flat (no level).
     Online adds +onlineSurchargePct%. Hydrated live from /api/config/public;
     server stays authoritative. Keep in sync with mam_compute_application_price. */
  levels: { precert: 'Pre-Certificate', cert: 'Certificate', prediploma: 'Pre-Diploma', diploma: 'Diploma' },
  levelPrices: { precert: 120, cert: 130, prediploma: 160, diploma: 180 },
  compulsoryFeeUsd: 120,
  grandDiscountPct: 13,

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
    host:     'Just-Joe Adeola'
  },

  /* -------- Book a Session (post-application appointment) --------
     /book embeds your Google Appointment Schedule as an iframe so visitors
     never leave the site. Paste the share URL from Google Calendar →
     "Appointment schedules" → Share. Falls back to a "open in new tab"
     button if the iframe is blocked by your share settings. The same URL
     is read server-side from BOOKING_EMBED_URL in api/.env (so the API can
     also surface it). The booking page is gated behind a one-time $5 fee
     that covers registration, ID card, student file and a 30-minute MAM
     Auditer assessment. */
  booking: {
    feeUsd:    5,
    embedUrl:  '',               // paste your Google Appointment share link
    covers:    [
      'Registration & admission processing',
      'Your MAM Academy student ID card',
      'Your student file (kept on record at the Centre)',
      'A 30-minute MAM Auditer assessment with our judges'
    ]
  },

  /* -------- Next intake (for the homepage countdown) --------
     main.js stamps `label` into every [data-intake-label] and counts down to
     `startDate`; once the date passes, the countdown swaps itself for an
     "enrolment open" message instead of freezing at zero. */
  intake: {
    startDate: '2026-09-07T00:00:00Z',
    label: 'September 2026',
    spotsTotal: 25,
    spotsRemaining: 19
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
/* ── May we measure this visitor? ONE answer, for every page ──────────────
 * The funnel beacon (apply.js) and the studio's usage beacon (studio-core.js)
 * each carried their own check, and both asked only Do-Not-Track — a signal
 * Chrome and Safari have removed. Neither read the cookie banner, so a visitor
 * who pressed Decline under "Essential cookies only" went on being counted.
 * The rule is one sentence: if anything said no, the answer is no — Do-Not-
 * Track, Global Privacy Control, or Decline on the banner (main.js stores it
 * as mam.cookie = 'decline'). Not having answered the banner is not a no; the
 * banner itself says only essential cookies are set, and this beacon sets
 * none (a per-tab sessionStorage id, never a cookie).
 */
window.MAM_PRIVACY = (function () {
  function said(no) { return no === '1' || no === 'yes' || no === true; }
  function mayMeasure() {
    try {
      if (said(navigator.doNotTrack) || said(window.doNotTrack) || said(navigator.msDoNotTrack)) return false;
      if (navigator.globalPrivacyControl === true) return false;
      if (window.localStorage && localStorage.getItem('mam.cookie') === 'decline') return false;
    } catch (e) { return false; }   // storage refused: assume the cautious answer
    return true;
  }
  /* The per-tab funnel session id both beacons send — one key, one format. */
  function funnelSid() {
    try {
      var k = 'mam.funnel.sid', v = sessionStorage.getItem(k);
      if (!v) { v = 'fs-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10); sessionStorage.setItem(k, v); }
      return v;
    } catch (e) { return null; }
  }
  return { mayMeasure: mayMeasure, funnelSid: funnelSid };
})();

window.MAM_API = (function () {
  var cfg = window.MAM_CONFIG.api;
  var csrfToken = null, csrfFetchedAt = 0;

  function url(path) {
    // Always resolve from the site root, never the current directory — a
    // relative base would make /portal/* and /admin/* pages hit
    // /portal/api/… and 404. Force a leading slash for non-absolute bases.
    var base = cfg.base || '/api';
    if (!/^https?:\/\//.test(base) && base.charAt(0) !== '/') base = '/' + base;
    base = base.replace(/\/$/, '');
    var p = String(path || '');
    if (/^https?:\/\//.test(p)) return p;            // caller passed a full URL
    p = '/' + p.replace(/^\/+/, '');                 // exactly one leading slash
    // Idempotent: if the caller already included the base prefix (e.g.
    // '/api/auth/login'), don't prepend it again — otherwise '/api' + that
    // yields '/api/api/auth/login' → 404. Accepts both 'auth/login' and
    // '/api/auth/login' and resolves to a single, correct path.
    if (base && (p === base || p.indexOf(base + '/') === 0)) return p;
    return base + p;
  }

  function getCsrf(force) {
    if (!force && csrfToken && (Date.now() - csrfFetchedAt) < 6000 * 1000) return Promise.resolve(csrfToken);
    return fetch(url(cfg.csrfPath), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        // Never let an HTML error page surface as "Unexpected token '<'".
        return r.text().then(function (t) {
          var j = {};
          try { j = JSON.parse(t); } catch (e) { throw apiUnavailable(r.status); }
          if (!r.ok || !j.token) {
            // The server answered with valid JSON but couldn't mint a token
            // (e.g. csrf_not_configured when APP_KEY is unset). Surface the real
            // reason to the console so an operator sees a config problem instead
            // of a vague "server unreachable", while users still get friendly copy.
            if (j && j.error) {
              try { console.warn('[MAM_API] CSRF unavailable (HTTP ' + r.status + '): ' + j.error); } catch (e) {}
            }
            throw apiUnavailable(r.status);
          }
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
    var attempt = function () {
      if (method === 'GET') return fire(null);
      return getCsrf().then(fire).catch(function (e) {
        if (e && e.status === 403 && (e.body || {}).error === 'csrf_invalid') {
          // Token may have expired — fetch a fresh one and retry once
          return getCsrf(true).then(fire);
        }
        throw e;
      });
    };
    if (!opts.retryOnNetwork) return attempt();
    // One quiet retry on a NETWORK-level failure (intermittent ERR_CONNECTION_RESET,
    // a dropped connection, a flaky proxy). Only callers that send an idempotency
    // key opt in, so the server dedupes a request that landed but whose response
    // was lost — the retry can never create a duplicate. A persistent outage still
    // falls through to the caller's graceful fallback.
    return attempt().catch(function (e) {
      var networkish = e && (e.apiDown || e.name === 'TypeError' || e.message === 'Failed to fetch' || e.message === 'api_unavailable');
      if (!networkish) throw e;
      return new Promise(function (res) { setTimeout(res, 1200); }).then(attempt);
    });
  }

  return {
    health:           function ()      { return request(cfg.healthPath); },
    submitApplication: function (data, idempotencyKey) {
      var opts = { method: 'POST', body: data };
      // Only enable the network-retry when we can send an idempotency key — the
      // key is what makes a retry safe (server dedupes), so the two travel together.
      if (idempotencyKey) { opts.headers = { 'X-Idempotency-Key': idempotencyKey }; opts.retryOnNetwork = true; }
      return request(cfg.applicationPath, opts);
    },
    initializePayment: function (data) { return request(cfg.paymentInitPath, { method: 'POST', body: data }); },
    verifyPayment:     function (data) { return request(cfg.paymentVerifyPath, { method: 'POST', body: data }); },
    subscribeNewsletter: function (email, source) { return request(cfg.newsletterPath, { method: 'POST', body: { email: email, source: source || 'site' } }); },
    sendContact:       function (data) { return request(cfg.contactPath, { method: 'POST', body: data }); },
    // Store: list/detail are public GETs (use fetch directly); checkout is a
    // CSRF-protected POST so it routes through the existing request() helper.
    storeCheckout: function (data) { return request('/api/store/checkout', { method: 'POST', body: data }); },
    url:     url,
    /* A valid CSRF token, as a promise. Widgets that POST with their own fetch()
       (the headshot uploader sends a large JSON body and wants progress) need the
       same token request() uses; without it every such POST is a 403 csrf_invalid.
       Pass true to force a refetch after a rejected token. */
    csrf:    function (force) { return getCsrf(!!force); },
    // Generic API caller used by the portal pages: request(path, opts).
    // Accepts either '/api/auth/login' (absolute) or 'auth/login' (relative)
    // and routes through the same CSRF + JSON pipeline as the named helpers.
    request: function (path, opts) {
      var p = String(path || '');
      if (p.indexOf('/api/') !== 0) p = '/api/' + p.replace(/^\//, '');
      return request(p, opts || {});
    }
  };
})();

/* -------- Signed-in state (site-wide) --------
   One cached source of truth for "is someone signed in, and who". The marketing
   nav, the Studio gate, and the auto practice-logger all read this instead of
   each calling /api/auth/me. The result is cached in memory + sessionStorage
   (5-min TTL) so navigating between pages doesn't refetch, while a single async
   revalidation keeps it fresh. Never throws — a down API resolves to signed-out. */
window.MAM_AUTH = (function () {
  /* The FALLBACK only. `/api/auth/me` now carries the real list from
     `mam_portal_targets()`, and `shape()` prefers it — this map is what the
     synchronous `cached()` best-guess paints with before the fetch lands, and
     what a down API falls back to. It went stale once already (no 'parent',
     so every guardian was handed a student-portal link), which is why the
     server's answer wins wherever there is one. */
  var PORTAL = { admin: '/admin/', instructor: '/portal/instructor/', student: '/portal/student/', parent: '/portal/parent/' };
  var KEY = 'mam.auth.v1', TTL = 5 * 60 * 1000;
  var mem = null, inflight = null;

  function portalFor(role) { return PORTAL[role] || '/portal/student/'; }
  function shape(r) {
    if (!(r && r.authenticated && r.user)) {
      return { authenticated: false, user: null, role: null, portal: '/portal/login', portals: [] };
    }
    var list = (r.portals && r.portals.length) ? r.portals : null;
    return {
      authenticated: true, user: r.user, role: r.user.role,
      // The server's first target when it sent one; the local map otherwise.
      portal: list ? list[0].url : portalFor(r.user.role),
      portals: list || []
    };
  }
  /** Synchronous best-guess from sessionStorage — lets the nav paint the right
      state on first frame (no flash), then state() revalidates in the background. */
  function cached() {
    if (mem) return mem;
    try { var r = JSON.parse(sessionStorage.getItem(KEY)); if (r && (Date.now() - r.t) < TTL) return (mem = r.v); } catch (e) {}
    return null;
  }
  /** Authoritative async check. force=true bypasses the cache. */
  function state(force) {
    if (!force) { var c = cached(); if (c) return Promise.resolve(c); }
    if (inflight) return inflight;
    inflight = window.MAM_API.request('auth/me', { method: 'GET' })
      .then(function (r) { return done(shape(r)); })
      .catch(function () { return done(shape(null)); });
    return inflight;
  }
  function done(v) {
    mem = v; inflight = null;
    try { sessionStorage.setItem(KEY, JSON.stringify({ t: Date.now(), v: v })); } catch (e) {}
    return v;
  }
  function clear() { mem = null; try { sessionStorage.removeItem(KEY); } catch (e) {} }
  function logout() {
    clear();
    return window.MAM_API.request('auth/logout', { method: 'POST' })
      .catch(function () {})
      .then(function () { return true; });
  }
  return { state: state, cached: cached, portalFor: portalFor, clear: clear, logout: logout };
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

/* -------- Live pricing hydration --------
   The values above are safe defaults so the page renders instantly (and works
   if the API is unreachable). On load we fetch the admin-editable prices from
   /api/config/public and patch MAM_CONFIG IN PLACE — the same object reference
   the form/store already read — then fire `mam:config` so any view that has
   already painted (the apply summary, the store) can refresh. The server is
   always authoritative at charge time; this just keeps the displayed numbers in
   sync with the admin without a redeploy. */
(function hydratePricing() {
  try {
    var base = (window.MAM_CONFIG && window.MAM_CONFIG.api && window.MAM_CONFIG.api.base) || '/api';
    fetch(base + '/config/public', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok) return;
        var C = window.MAM_CONFIG;
        if (j.pricing) {
          Object.keys(j.pricing).forEach(function (k) {
            var p = j.pricing[k];
            if (!p) return;
            if (!C.pricing[k]) C.pricing[k] = {};
            C.pricing[k].name = p.name || C.pricing[k].name;
            C.pricing[k].usd  = (typeof p.usd === 'number') ? p.usd : C.pricing[k].usd;
          });
        }
        if (typeof j.registration_fee_usd === 'number') C.registrationFeeUsd = j.registration_fee_usd;
        if (typeof j.online_surcharge_pct === 'number') C.onlineSurchargePct = j.online_surcharge_pct;
        // Level-based model — keep the form's price preview in sync with admin.
        if (j.levels && typeof j.levels === 'object') C.levels = j.levels;
        if (j.level_prices && typeof j.level_prices === 'object') C.levelPrices = j.level_prices;
        if (typeof j.compulsory_fee_usd === 'number') C.compulsoryFeeUsd = j.compulsory_fee_usd;
        if (typeof j.grand_discount_pct === 'number') C.grandDiscountPct = j.grand_discount_pct;
        if (typeof j.store_shipping_flat_usd === 'number') C.storeShippingUsd = j.store_shipping_flat_usd;
        if (typeof j.usd_to_ngn === 'number' && C.currency && C.currency.rates) C.currency.rates.NGN = j.usd_to_ngn;
        // Full display-rate map (server-authoritative). Merge so any currency the
        // server omits keeps its built-in fallback; the live NGN above is included.
        if (j.currency_rates && C.currency) C.currency.rates = Object.assign({}, C.currency.rates, j.currency_rates);
        // Real receiving bank account for the apply/store transfer panels.
        if (j.bank) C.bank = j.bank;
        // CDN (Cloudinary) config — admin-editable so the cloud name and
        // upload preset can be set without a code deploy. Patched here so
        // headshot.js upload() and MAM_CDN both see the live values.
        if (j.cdn && typeof j.cdn === 'object') {
          if (!C.cdn) C.cdn = {};
          if (j.cdn.cloudName)    C.cdn.cloudName    = j.cdn.cloudName;
          if (j.cdn.uploadPreset) C.cdn.uploadPreset = j.cdn.uploadPreset;
          if (typeof j.cdn.folder === 'string') C.cdn.folder = j.cdn.folder;
        }
        try { document.dispatchEvent(new CustomEvent('mam:config', { detail: j })); } catch (e) {}
      })
      .catch(function () { /* defaults already in place */ });
  } catch (e) {}
})();

/* =====================================================================
 * MAM_CCY — one currency source of truth for the whole site.
 * Shows money in the visitor's LOCAL currency (by country/locale), unless
 * they've modified it via the switcher ('mam.currency'). Broad coverage of
 * major world + English-speaking African currencies; anything we can't price
 * falls back to USD. Rates come from the server ('mam.fx' live cache +
 * MAM_CONFIG.currency.rates), with a small static fallback for first paint.
 * ===================================================================== */
window.MAM_CCY = (function () {
  // Country (ISO-3166-alpha-2) → currency. Covers majors + English-speaking
  // Africa; unmapped countries fall through to USD.
  var COUNTRY = {
    US:'USD', UM:'USD', PR:'USD', EC:'USD', SV:'USD', PA:'USD',
    GB:'GBP', UK:'GBP', IM:'GBP', JE:'GBP', GG:'GBP',
    IE:'EUR', DE:'EUR', FR:'EUR', IT:'EUR', ES:'EUR', NL:'EUR', BE:'EUR', AT:'EUR', PT:'EUR',
    FI:'EUR', GR:'EUR', LU:'EUR', SK:'EUR', SI:'EUR', LT:'EUR', LV:'EUR', EE:'EUR', CY:'EUR', MT:'EUR', HR:'EUR',
    // English-speaking / major African markets
    NG:'NGN', GH:'GHS', KE:'KES', ZA:'ZAR', UG:'UGX', TZ:'TZS', RW:'RWF', ZM:'ZMW',
    MW:'MWK', BW:'BWP', NA:'NAD', GM:'GMD', SL:'SLE', SZ:'SZL', LS:'LSL', MU:'MUR', EG:'EGP',
    ZW:'USD', LR:'USD',
    // Other majors / large diaspora
    CA:'CAD', AU:'AUD', NZ:'NZD', IN:'INR', PK:'PKR', BD:'BDT',
    AE:'AED', SA:'SAR', QA:'QAR', JP:'JPY', CN:'CNY', CH:'CHF',
    SE:'SEK', NO:'NOK', DK:'DKK', BR:'BRL', MX:'MXN', PH:'PHP'
  };
  var SYM = {
    USD:'$', NGN:'₦', GBP:'£', EUR:'€', GHS:'GH₵', KES:'KSh', ZAR:'R',
    UGX:'USh', TZS:'TSh', RWF:'FRw', ZMW:'ZK', MWK:'MK', BWP:'P', NAD:'N$', GMD:'D',
    SLE:'Le', LRD:'L$', SZL:'E', LSL:'L', MUR:'₨', EGP:'E£',
    CAD:'C$', AUD:'A$', NZD:'NZ$', INR:'₹', PKR:'₨', BDT:'৳', AED:'AED', SAR:'SAR',
    QAR:'QAR', JPY:'¥', CNY:'¥', CHF:'CHF', SEK:'kr', NOK:'kr', DKK:'kr',
    BRL:'R$', MXN:'MX$', PHP:'₱'
  };
  // First-paint fallback rates (USD-relative). Live server rates override these.
  var FALLBACK = {
    USD:1, NGN:1500, GBP:0.79, EUR:0.92, GHS:15, KES:129, ZAR:18, UGX:3800, TZS:2600,
    RWF:1300, ZMW:27, MWK:1700, BWP:13.5, NAD:18, GMD:70, SLE:22, LRD:190, SZL:18, LSL:18,
    MUR:46, EGP:49, CAD:1.37, AUD:1.52, NZD:1.66, INR:84, PKR:278, BDT:120, AED:3.67,
    SAR:3.75, QAR:3.64, JPY:155, CNY:7.2, CHF:0.88, SEK:10.7, NOK:10.9, DKK:6.9, BRL:5.6, MXN:18.5, PHP:58
  };
  // Zero-decimal currencies (don't show cents).
  var ZERO_DEC = { NGN:1, JPY:1, UGX:1, TZS:1, RWF:1, KES:1, GHS:1, MWK:1, XAF:1, XOF:1 };

  function ls(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function ss(k)    { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }

  // Session-scoped geo-IP result. Distinct from the user's explicit 'mam.currency'
  // choice: a saved choice always wins, but the geo guess is authoritative over the
  // (often wrong) browser-locale guess. Cached per session so we look up once.
  var GEO_KEY = 'mam.ccy.geo';
  var _geo = null, _geoTried = false;
  function geoCached() {
    if (_geo) return _geo;
    var v = ss(GEO_KEY);
    if (v && known(v)) return (_geo = v);
    return null;
  }
  // Record a geo-detected currency as the authoritative guess and tell every
  // painter to refresh. No-op if the visitor has made an explicit choice.
  function setDetected(cur) {
    if (!known(cur) || ls('mam.currency')) return;
    _geo = cur; ssSet(GEO_KEY, cur);
    try { document.dispatchEvent(new CustomEvent('mam:currency', { detail: { currency: cur } })); } catch (e) {}
  }
  function setCountry(code) { var c = fromCountry(code); if (c) setDetected(c); }
  // One-time geo-IP lookup (free, key-less). ipapi returns the currency code
  // directly; we accept any we can price. Fires only when there's no explicit
  // choice and no cached geo result yet.
  function refineByGeo() {
    if (_geoTried) return; _geoTried = true;
    if (ls('mam.currency') || geoCached()) return;
    try {
      fetch('https://ipapi.co/currency/', { mode: 'cors' })
        .then(function (r) { return r.text(); })
        .then(function (c) { setDetected((c || '').trim().toUpperCase()); })
        .catch(function () {});
    } catch (e) {}
  }

  function rates() {
    var out = {}; for (var k in FALLBACK) out[k] = FALLBACK[k];
    try { var c = window.MAM_CONFIG && MAM_CONFIG.currency && MAM_CONFIG.currency.rates; if (c) for (var a in c) out[a] = c[a]; } catch (e) {}
    try { var f = JSON.parse(ls('mam.fx') || 'null'); if (f && f.rates) for (var b in f.rates) out[b] = f.rates[b]; } catch (e) {}
    return out;
  }
  function known(cur) { return !!cur && (rates()[cur] != null); }
  function rate(cur) { var r = rates(); return r[cur] != null ? r[cur] : 1; }
  function symbol(cur) { return SYM[cur] || (cur ? cur + ' ' : '$'); }

  // Detect the visitor's currency: their saved choice ("modified") wins; else map
  // their locale region → currency; else USD. (An async geo-IP refinement in
  // main.js can upgrade this via setCountry().)
  function detect() {
    var saved = ls('mam.currency');
    if (saved && known(saved)) return saved;      // explicit choice always wins
    var g = geoCached();
    if (g) return g;                              // geo-IP result (accurate, session-cached)
    // No explicit choice and no geo result yet — kick off a one-time IP lookup
    // whose result (via mam:currency) will refine every painter on this and
    // later pages. Meanwhile fall back to the browser-locale / timezone guess.
    refineByGeo();
    var region = ((navigator.language || navigator.userLanguage || '').split('-')[1] || '').toUpperCase();
    var byLocale = COUNTRY[region];
    if (byLocale && known(byLocale)) return byLocale;
    var tz = ''; try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
    if (/Lagos|Accra|Nairobi|Johannesburg|Kampala|Dar_es_Salaam|Kigali|Lusaka/.test(tz)) {
      var byTz = { Lagos:'NGN', Accra:'GHS', Nairobi:'KES', Johannesburg:'ZAR', Kampala:'UGX', Kigali:'RWF', Lusaka:'ZMW' }[tz.split('/').pop()];
      if (byTz && known(byTz)) return byTz;
    }
    return 'USD';
  }
  function fromCountry(code) { var c = COUNTRY[(code || '').toUpperCase()]; return (c && known(c)) ? c : null; }

  // Format a raw Naira amount (already in NGN — NOT a USD figure to convert).
  // Card & bank payments settle in NGN, so the gateway charge is disclosed here.
  function ngn(n) { return '₦' + Math.round(parseFloat(n) || 0).toLocaleString('en-US'); }

  // Build the "you'll actually be charged" disclosure from a payment/initialize
  // response. Card & bank always settle in Naira; we surface the exact NGN charge
  // and, when the visitor's display currency differs, an ≈ estimate in it. The
  // server is authoritative for the amount — this is disclosure only.
  function settlementNote(init) {
    if (!init || !init.amount_ngn) return '';
    var out = 'Card &amp; bank payments settle in Nigerian Naira — you will be charged <strong>' + ngn(init.amount_ngn) + '</strong>';
    var cur = detect();
    if (cur !== 'NGN' && init.amount_usd) out += ' (≈ ' + format(init.amount_usd, cur) + ')';
    return out + '.';
  }
  // Plain-text twin of settlementNote for status regions that render via textContent.
  function settlementText(init) {
    if (!init || !init.amount_ngn) return '';
    var out = 'Card & bank payments settle in Nigerian Naira — you will be charged ' + ngn(init.amount_ngn);
    var cur = detect();
    if (cur !== 'NGN' && init.amount_usd) out += ' (≈ ' + format(init.amount_usd, cur) + ')';
    return out + '.';
  }

  // Format a USD amount in `cur` (defaults to detected). Zero-decimal aware.
  function format(usd, cur) {
    cur = cur || detect();
    var v = (parseFloat(usd) || 0) * rate(cur);
    var n = ZERO_DEC[cur] ? Math.round(v) : Math.round(v * 100) / 100;
    return symbol(cur) + n.toLocaleString('en-US', ZERO_DEC[cur] ? {} : { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  return { COUNTRY: COUNTRY, detect: detect, fromCountry: fromCountry, setCountry: setCountry, setDetected: setDetected, rate: rate, symbol: symbol, format: format, known: known, rates: rates, ngn: ngn, settlementNote: settlementNote, settlementText: settlementText };
})();

/* -------- Lazy-load cdn.js ------------------------------------------------
   MAM_CDN (image URL rewriting via Cloudinary fetch) is used by main.js and
   portal pages. We inject it here so every page that already loads config.js
   gets it without touching individual HTML files. Loads async so it never
   blocks first paint; cdn.js sets window.MAM_CDN once executed, and main.js
   guards with typeof MAM_CDN.rewriteAll before calling it. */
(function () {
  try {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    var s = document.createElement('script');
    // Resolve from the same path prefix as config.js itself.
    var self = (document.currentScript && document.currentScript.src) || '';
    var base = self ? self.replace(/config\.js[^/]*$/, '') : '/assets/js/';
    s.src = base + 'cdn.js?v=20260718a';
    s.async = true;
    document.head.appendChild(s);
  } catch (e) {}
})();
