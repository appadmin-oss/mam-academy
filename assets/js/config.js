/**
 * MAM Academy — Site Configuration
 * ---------------------------------------------------------------
 * Edit this file to wire your payment providers and submission
 * endpoints. All keys here are PUBLIC (publishable) — never store
 * secret keys in the browser. Server-side verification is handled
 * by /api/verify-payment.php (Paystack) or your own webhook.
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

  /* -------- Form submission --------
     Set `endpoint` to a Formspree / your own POST URL.
     If null, submissions fall back to mailto. */
  forms: {
    applicationEndpoint: null,
    newsletterEndpoint: null,
    contactEndpoint: null,
    fallbackEmail: 'connect@mamcentre.org'
  },

  /* -------- Payments --------
     Paystack: publishable key starts with pk_test_ / pk_live_
     Flutterwave: public key starts with FLWPUBK_TEST- / FLWPUBK-
     Stripe (optional): pk_test_ / pk_live_
     Leave keys empty/null to disable a provider; the UI will hide
     methods that aren't configured. Switch to live keys to go live. */
  payments: {
    paystack: {
      publicKey: '',        // e.g. 'pk_live_xxxxxxxxxxxxxxxx'
      currency: 'NGN',
      verifyEndpoint: '/api/verify-payment.php'
    },
    flutterwave: {
      publicKey: '',        // e.g. 'FLWPUBK-xxxxxxxxxxxxxxxx-X'
      currency: 'NGN'
    },
    stripe: {
      publicKey: '',        // optional — for international cards
      currency: 'USD'
    },
    bankTransfer: {
      enabled: true,
      bankName: 'MAM Centre — request from office',
      accountName: 'MAM Centre',
      accountNumber: 'Provided after enrollment',
      reference: 'MAM-{id}'
    }
  },

  /* -------- Currency conversion -------- */
  currency: {
    base: 'USD',
    rates: { USD: 1, NGN: 1500, GBP: 0.79, EUR: 0.92 }
  },

  /* -------- Pricing catalogue -------- */
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

  /* -------- Next intake -------- */
  intake: {
    startDate: '2026-05-05T00:00:00Z',
    label: 'May 2026',
    spotsTotal: 25,
    spotsRemaining: 7
  }
};
</content>
</invoke>