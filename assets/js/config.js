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

  /* -------- Next intake (for the homepage countdown) -------- */
  intake: {
    startDate: '2026-05-05T00:00:00Z',
    label: 'May 2026',
    spotsTotal: 25,
    spotsRemaining: 7
  }
};

/* -------- API client helper -------- */
window.MAM_API = (function () {
  var cfg = window.MAM_CONFIG.api;
  var csrfToken = null, csrfFetchedAt = 0;

  function url(path) { return cfg.base.replace(/\/$/, '') + '/' + path.replace(/^\//, ''); }

  function getCsrf(force) {
    if (!force && csrfToken && (Date.now() - csrfFetchedAt) < 6000 * 1000) return Promise.resolve(csrfToken);
    return fetch(url(cfg.csrfPath), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('csrf_unavailable'); return r.json(); })
      .then(function (j) { csrfToken = j.token; csrfFetchedAt = Date.now(); return csrfToken; });
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
        return r.json().catch(function () { return {}; }).then(function (data) {
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
