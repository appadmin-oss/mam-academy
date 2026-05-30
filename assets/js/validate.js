/**
 * MAM Academy — Smart input validation
 * ─────────────────────────────────────────────────────────────
 * Heuristic + library-backed input quality checks that catch what
 * regex misses: disposable email providers, gibberish names ("asdf",
 * "qwertyuiop"), nonsense goals, and malformed international phone
 * numbers. Wraps libphonenumber-js (lite metadata, ~75 KB) loaded
 * on demand from jsDelivr.
 *
 * Public API
 *   MAM_VALIDATE.email(value)    -> { ok, reason?, severity? }
 *   MAM_VALIDATE.phone(value, country?) -> Promise<{ ok, e164?, reason? }>
 *   MAM_VALIDATE.name(value)     -> { ok, reason? }
 *   MAM_VALIDATE.text(value, opts) -> { ok, reason? }   (for goals, etc.)
 *
 * Severity values are 'error' (block) or 'warn' (soft, allow submit
 * with a notice) — disposable email is a warn, not a hard block,
 * so legit unusual providers don't get rejected by mistake.
 */
(function () {
  'use strict';

  // ── Disposable / temporary email domains. ──────────────────────────
  // 80/20: the top providers cover virtually every test-account abuse
  // we'd see. Extend as needed; the check is a Set.has() so cost is O(1).
  var DISPOSABLE = new Set([
    '0-mail.com','10minutemail.com','20minutemail.com','33mail.com','anonbox.net',
    'binkmail.com','bobmail.info','bofthew.com','brefmail.com','bsnow.net',
    'deadaddress.com','despam.it','discard.email','discardmail.com','dispostable.com',
    'drdrb.net','dropmail.me','e4ward.com','easytrashmail.com','emailondeck.com',
    'emailtemporanea.net','fakeinbox.com','fakemail.fr','fakemailgenerator.com',
    'getairmail.com','getnada.com','grr.la','guerrillamail.com','guerrillamail.info',
    'guerrillamailblock.com','harakirimail.com','hidemail.de','inboxalias.com',
    'inboxbear.com','jetable.org','laste.ml','lroid.com','mailcatch.com',
    'maildrop.cc','mailexpire.com','mailforspam.com','mailinator.com','mailmoat.com',
    'mailnesia.com','mailnull.com','mintemail.com','mt2014.com','mvrht.com',
    'mytemp.email','myinbox.icu','nada.email','no-spam.ws','nospam4.us',
    'nowmymail.com','objectmail.com','pokemail.net','sharklasers.com','spam4.me',
    'spamavert.com','spambox.us','spamgourmet.com','tempail.com','tempmail.com',
    'tempmail.de','tempmail.email','tempmail.net','tempmailaddress.com','tempmailo.com',
    'tempr.email','throwawaymail.com','trash-mail.com','trashmail.com','trashmail.net',
    'wegwerfmail.de','wegwerfmail.net','wegwerfmail.org','wegwerpmailadres.nl',
    'yopmail.com','yopmail.fr','yopmail.net','zetmail.com'
  ]);

  // ── Common gibberish / placeholder patterns for name fields. ──────
  // A name field set to one of these is almost certainly bot input.
  var NAME_BLOCKLIST = new Set([
    'asdf','qwer','qwerty','qwertyuiop','test','tester','testing',
    'abc','abcd','xyz','xxx','none','nobody','noone','foo','bar',
    'foobar','baz','john doe','jane doe','firstname','lastname',
    'username','admin','administrator','root','user','sample',
    'aaaa','aaaaa','bbbb','cccc','example'
  ]);

  function email(value) {
    var v = (value || '').trim().toLowerCase();
    if (!v) return { ok: false, severity: 'error', reason: 'Email is required.' };

    // RFC-permissive shape — same shape the form already accepts so we
    // don't reject anything the server validator would accept.
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
      return { ok: false, severity: 'error', reason: 'That email address doesn\'t look right.' };
    }

    var domain = v.slice(v.lastIndexOf('@') + 1);
    if (DISPOSABLE.has(domain)) {
      return {
        ok: false,
        severity: 'warn',
        reason: 'That looks like a temporary email service — we send your confirmation, schedule and policy to this address, so please use an email you can reach next week.'
      };
    }
    // Common typo nets (gmail.con, .co, ymail.con) — soft warn
    if (/\.con$/.test(domain) || /\.cm$/.test(domain) || /\.co$/.test(domain) && !/(\.co\.[a-z]{2,})$/.test(domain)) {
      return { ok: false, severity: 'warn', reason: 'Double-check that domain — it ends in "' + domain.split('.').pop() + '" rather than .com / .co.<region>.' };
    }
    return { ok: true };
  }

  function name(value) {
    var raw = (value || '').trim();
    if (raw.length < 2) return { ok: false, reason: 'Please enter a real name (at least 2 letters).' };
    if (raw.length > 60) return { ok: false, reason: 'Please keep names under 60 characters.' };

    var lc = raw.toLowerCase();
    if (NAME_BLOCKLIST.has(lc)) return { ok: false, reason: '"' + raw + '" doesn\'t look like a real name. Please enter your full name.' };

    // No letters at all? definitely not a name.
    if (!/[A-Za-zÀ-ɏ一-鿿֐-׿؀-ۿ]/.test(raw)) {
      return { ok: false, reason: 'A name should contain letters.' };
    }
    // Five or more of the same character in a row ("aaaaa")
    if (/(.)\1{4,}/.test(raw)) return { ok: false, reason: 'That doesn\'t look like a real name.' };
    // Looks like a URL or email
    if (/(https?:|www\.|@)/i.test(raw)) return { ok: false, reason: 'Names shouldn\'t contain URLs or email addresses.' };
    return { ok: true };
  }

  /**
   * Goals / free-text quality. Optional input — empty passes. If filled
   * in, we want at least a meaningful sentence (≥ 8 chars, ≥ 3 unique
   * letters, not just "aaaaaaaaa", not just punctuation).
   */
  function text(value, opts) {
    opts = opts || {};
    var v = (value || '').trim();
    var minLen = opts.minLen || 0;
    if (!v) return minLen > 0
      ? { ok: false, reason: 'Please add a little detail (at least ' + minLen + ' characters).' }
      : { ok: true };
    if (v.length < minLen) return { ok: false, reason: 'A bit more detail would help — at least ' + minLen + ' characters.' };
    var letters = v.replace(/[^A-Za-z]/g, '').toLowerCase();
    if (letters.length >= 8) {
      var unique = new Set(letters.split('')).size;
      if (unique < 3) return { ok: false, reason: 'That doesn\'t read like a real answer — try a sentence or two.' };
    }
    return { ok: true };
  }

  // ── Phone validation via libphonenumber-js (loaded once, on demand) ──
  var phoneLibPromise = null;
  function loadPhoneLib() {
    if (phoneLibPromise) return phoneLibPromise;
    phoneLibPromise = new Promise(function (resolve, reject) {
      if (window.libphonenumber) return resolve(window.libphonenumber);
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/libphonenumber-js@1.11.4/bundle/libphonenumber-min.js';
      s.async = true;
      s.onload = function () { resolve(window.libphonenumber); };
      s.onerror = function () { reject(new Error('libphonenumber_load_failed')); };
      document.head.appendChild(s);
    });
    return phoneLibPromise;
  }

  function phone(value, country) {
    var v = (value || '').trim();
    if (!v) return Promise.resolve({ ok: false, reason: 'Mobile number is required.' });

    return loadPhoneLib().then(function (lib) {
      try {
        var parsed = lib.parsePhoneNumberFromString(v, (country || 'NG').toUpperCase());
        if (!parsed) return { ok: false, reason: 'Couldn\'t parse that phone number. Include the country code (e.g. +234 …).' };
        if (!parsed.isValid()) {
          return { ok: false, reason: 'That phone number isn\'t valid for ' + (parsed.country || 'the chosen country') + '. Check the digit count.' };
        }
        return { ok: true, e164: parsed.number, country: parsed.country, formatted: parsed.formatInternational() };
      } catch (e) {
        return { ok: false, reason: 'Couldn\'t parse that phone number.' };
      }
    }).catch(function () {
      // Library unreachable — fall back to permissive regex so we don't block submission.
      var ok = /^[+0-9\s\-()]{7,}$/.test(v);
      return ok ? { ok: true, formatted: v } : { ok: false, reason: 'Enter a valid phone number.' };
    });
  }

  window.MAM_VALIDATE = { email: email, phone: phone, name: name, text: text };
})();
