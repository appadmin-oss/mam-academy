/* ============================================================
   MAM Academy — Shared site JS
   Nav, hero carousel, scroll spy, FAQ, animations, cookie, etc.
   ============================================================ */
(function () {
  'use strict';

  // ---- JS-on marker (synchronous, before paint) ------------------------
  // Scroll-reveal elements are hidden ONLY under `html.js`. Setting this here,
  // before first paint, means: (a) with JS the reveal animation works, and
  // (b) without JS — or if this script never parses — content keeps its
  // natural opacity and can never be stuck invisible.
  try { document.documentElement.classList.add('js'); } catch (e) {}

  // ---- Gradient-text capability probe ----------------------------------
  // Gold/coloured headings paint a gradient onto the text via
  // background-clip:text + transparent fill. When an engine can't paint that
  // (older Android WebViews, Opera Mini, some in-app browsers — common on this
  // audience's devices) the text turns invisible. The CSS @supports fallback
  // rescues engines that understand @supports, but Opera Mini and friends
  // ignore @supports entirely, so the inline-styled spans still vanish. This
  // probe flags the real capability with a plain class the CSS can target
  // WITHOUT @supports, guaranteeing the text is never transparent. Runs first,
  // before paint, to avoid a flash.
  (function () {
    var ok = false;
    try {
      if (window.CSS && CSS.supports) {
        ok = CSS.supports('-webkit-background-clip', 'text') || CSS.supports('background-clip', 'text');
      }
      if (!ok) {
        var s = document.createElement('span').style;
        s.cssText = '-webkit-background-clip:text;background-clip:text';
        ok = s.backgroundClip === 'text' || s.webkitBackgroundClip === 'text';
      }
    } catch (e) { ok = false; }
    // Positive opt-in: gradient text renders as a solid, on-brand colour by
    // DEFAULT (so it can never be invisible — covers no-JS, forced-colors,
    // engines that accept background-clip:text but don't actually paint it),
    // and is upgraded to the clipped gradient only once support is confirmed.
    document.documentElement.className += ok ? ' textclip' : ' no-textclip';
  })();

  // ---- Scroll-reveal safety net ----------------------------------------
  // Section headers/cards use .fade-in-up/.fade-in (opacity:0 until JS adds
  // .visible). If the IntersectionObserver never armed (an init threw, boot
  // didn't run, observer unsupported) those would stay INVISIBLE forever. This
  // independent net — outside boot() — guarantees content is shown regardless:
  // reduced-motion shows it at once, and a hard timer reveals anything still
  // hidden so nothing can be stuck transparent.
  (function () {
    // Reveal targets: our own .fade-in(-up), AOS [data-aos] (opacity:0 until
    // animated), and the exact elements GSAP hides with inline opacity:0
    // (section/hero headings + content cards). Scoped deliberately so we NEVER
    // clear opacity on hero slides, decorative layers or carousels that use
    // opacity:0 for legitimate reasons.
    var GSAP_SEL = '.section-title,.page-hero-title,.unique-card,.school-card,.program-card,.pathway-card,.faculty-card,.team-card';
    var SEL = '.fade-in-up:not(.visible),.fade-in:not(.visible),[data-aos]:not(.aos-animate),' + GSAP_SEL;
    function vh() { return window.innerHeight || document.documentElement.clientHeight || 0; }
    function inView(el) { try { var r = el.getBoundingClientRect(); return r.bottom > -80 && r.top < vh() + 80; } catch (e) { return true; } }
    function reveal(el) {
      el.classList.add('visible');                                   // .fade-in(-up)
      if (el.hasAttribute('data-aos')) el.classList.add('aos-animate'); // AOS
      if (el.style && parseFloat(el.style.opacity) === 0) el.style.opacity = ''; // GSAP leftover (only if exactly 0 → never mid-animation)
    }
    function sweep(force) {
      try {
        var els = document.querySelectorAll(SEL);
        for (var i = 0; i < els.length; i++) if (force || inView(els[i])) reveal(els[i]);
      } catch (e) {
        try { document.documentElement.className += ' reveal-fallback'; } catch (_) {}
      }
    }
    try {
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { sweep(true); });
        else sweep(true);
      }
      // As the user scrolls, un-stick anything in view that a reveal never fired on.
      var tick = false;
      window.addEventListener('scroll', function () { if (tick) return; tick = true; setTimeout(function () { tick = false; sweep(false); }, 180); }, { passive: true });
      window.addEventListener('load', function () { setTimeout(function () { sweep(false); }, 1200); });
      setTimeout(function () { sweep(true); }, 700); // last-resort: force-reveal everything so nothing is ever stuck transparent (CSS failsafe also reveals ~0.6s)
    } catch (e) {
      try { document.documentElement.className += ' reveal-fallback'; } catch (_) {}
    }
  })();

  // ---- Scroll-reveal: arm EARLY, independent of the header/footer partials --
  // The reveal observer used to live in boot(), which only runs AFTER the
  // header+footer partials are fetched. On slow/flaky mobile connections that
  // delayed revealing above-the-fold content, so headings sat invisible until
  // the safety-net timers fired (up to seconds). Arm it here on DOMContentLoaded
  // so page content (which is in the static HTML, not the partials) reveals on
  // time regardless of partial latency. Idempotent with boot's initAnimations.
  (function () {
    function armReveal() {
      try {
        var targets = document.querySelectorAll('.fade-in-up, .fade-in');
        if (!('IntersectionObserver' in window)) {
          for (var j = 0; j < targets.length; j++) targets[j].classList.add('visible');
          return;
        }
        var io = new IntersectionObserver(function (entries) {
          entries.forEach(function (en) {
            if (en.isIntersecting) { en.target.classList.add('visible'); io.unobserve(en.target); }
          });
        }, { threshold: 0.1, rootMargin: '0px 0px -32px 0px' });
        for (var i = 0; i < targets.length; i++) io.observe(targets[i]);
        window.__mamRevealArmed = true;
      } catch (e) {}
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', armReveal);
    else armReveal();
  })();

  // ---- Gradient-clip repaint after web fonts load -----------------------
  // WebKit/Blink (Chrome, Safari) drop the `background-clip:text` clip when a
  // web font swaps in AFTER first paint — the (transparent) text-fill then
  // shows nothing, so gold/coloured headings "go transparent once load
  // completes". Forcing a one-frame reflow on each gradient element after
  // document.fonts.ready re-establishes the clip. Runs once; negligible cost.
  function repaintGradientText() {
    var sel = '.highlight-gold,.highlight-red,.highlight-purple,.nav-brand-name,'
            + '.footer-logo-name,.preloader-name,.stat-number,.countdown-num,'
            + '.pp-title .accent,[style*="-webkit-text-fill-color:transparent"]';
    var els = document.querySelectorAll(sel);
    for (var i = 0; i < els.length; i++) {
      var el = els[i], d = el.style.display;
      el.style.display = 'none';
      void el.offsetHeight;           // force reflow → re-rasterise the clip
      el.style.display = d;
    }
  }
  try {
    if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
      document.fonts.ready.then(function () { repaintGradientText(); });
    }
  } catch (e) {}
  // Belt-and-braces: also after full load, in case fonts.ready isn't supported.
  window.addEventListener('load', function () { setTimeout(repaintGradientText, 80); });

  // dotLottie web component was previously loaded here for animated emoji,
  // but its WASM loader uses eval() which our CSP (correctly) forbids.
  // Replace every <dotlottie-wc src="…notoemoji/<codepoint>/lottie.json"…>
  // we find in the DOM with the matching native emoji glyph in a span the
  // CSS bounces. Same vibe, zero external script, zero CSP grief.
  function replaceDotLottie(root) {
    var nodes = (root || document).querySelectorAll('dotlottie-wc[src*="notoemoji"]');
    nodes.forEach(function (el) {
      var m = (el.getAttribute('src') || '').match(/notoemoji\/(?:latest\/)?([0-9a-f_]+)/i);
      if (!m) { el.remove(); return; }
      var glyph = m[1].split('_').map(function (h) {
        var cp = parseInt(h, 16); if (!cp || cp === 0xFE0F) return '';
        return String.fromCodePoint(cp);
      }).join('');
      var span = document.createElement('span');
      span.className = 'emoji-pop ' + (el.className || '');
      span.setAttribute('role', 'img'); span.setAttribute('aria-hidden', 'true');
      span.textContent = glyph || '🎵';
      el.replaceWith(span);
    });
  }
  if (typeof document !== 'undefined') {
    if (document.readyState !== 'loading') replaceDotLottie();
    else document.addEventListener('DOMContentLoaded', function () { replaceDotLottie(); });
    // Re-scan after partials inject — header/footer may carry dotlottie tags.
    document.addEventListener('mam:ready', function () { replaceDotLottie(); });
  }
  // Iconify — Microsoft Fluent 3D emoji (static) + line-md animated icons.
  // Used for inline decorations where a Lottie player would be overkill.
  if (typeof document !== 'undefined' && !document.querySelector('script[data-mam-iconify]')) {
    var __ic = document.createElement('script');
    __ic.src = 'https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js';
    __ic.async = true;
    __ic.dataset.mamIconify = '1';
    (document.head || document.documentElement).appendChild(__ic);
  }

  // libs.js + lead.js progressive enhancement modules. libs.js pulls in
  // SweetAlert2/Tom Select/AOS/GSAP/etc; lead.js mounts the sticky
  // apply-now bar and the exit-intent capture modal. Both are optional
  // — the site degrades cleanly if either CDN/script is blocked.
  if (typeof document !== 'undefined') {
    var __base = (document.currentScript && document.currentScript.getAttribute('src')) || '';
    var __idx  = __base.indexOf('main.js');
    var __dir  = __idx >= 0 ? __base.slice(0, __idx) : 'assets/js/';
    [['libs.js', 'mamLibs'], ['lead.js', 'mamLead']].forEach(function (pair) {
      if (document.querySelector('script[data-' + pair[1] + ']')) return;
      var s = document.createElement('script');
      s.src = __dir + pair[0];
      s.async = true;
      s.dataset[pair[1]] = '1';
      (document.head || document.documentElement).appendChild(s);
    });
  }

  var $  = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var on = function (el, ev, fn, opts) { if (el) el.addEventListener(ev, fn, opts || false); };

  var Storage = (function () {
    try { var k = '__mam_t'; localStorage.setItem(k, '1'); localStorage.removeItem(k); return localStorage; }
    catch (e) { var m = {}; return { getItem: function (k) { return m[k] || null; }, setItem: function (k, v) { m[k] = String(v); }, removeItem: function (k) { delete m[k]; } }; }
  })();
  window.MAM_STORAGE = Storage;

  // ============================================================
  // MAM_FORMS — shared form-UX kit
  // A single, consistent implementation of the busy-button + error affordances
  // that every form on the site had been re-coding by hand (7+ copies of
  // disable+spinner+label; divergent spinner markup). Opt-in per form; safe to
  // call even when the design tokens aren't present.
  // ============================================================
  window.MAM_FORMS = (function () {
    // One tiny keyframe/spinner definition, injected once, usable anywhere.
    var cssInjected = false;
    function ensureCss() {
      if (cssInjected) return; cssInjected = true;
      var st = document.createElement('style'); st.id = 'mam-forms-css';
      st.textContent =
        '.mam-spin{display:inline-block;width:1em;height:1em;vertical-align:-0.15em;margin-right:.5em;'
        + 'border:2px solid currentColor;border-right-color:transparent;border-radius:50%;'
        + 'animation:mam-spin .6s linear infinite}'
        + '@keyframes mam-spin{to{transform:rotate(360deg)}}'
        + '@media (prefers-reduced-motion:reduce){.mam-spin{animation-duration:1.4s}}'
        + '.is-busy{cursor:progress!important;opacity:.85}';
      (document.head || document.documentElement).appendChild(st);
    }

    // Disable a button and show a spinner + label; remembers the prior label.
    function setBusy(btn, label) {
      if (!btn) return;
      ensureCss();
      if (btn.getAttribute('data-mam-orig') === null || btn.getAttribute('data-mam-orig') === undefined) {
        btn.setAttribute('data-mam-orig', btn.innerHTML);
      }
      btn.disabled = true;
      btn.classList.add('is-busy');
      btn.setAttribute('aria-busy', 'true');
      btn.innerHTML = '<span class="mam-spin" aria-hidden="true"></span>' + (label || 'Working…');
    }
    // Restore a button to its pre-busy label + enabled state.
    function resetBusy(btn) {
      if (!btn) return;
      var orig = btn.getAttribute('data-mam-orig');
      if (orig !== null) { btn.innerHTML = orig; btn.removeAttribute('data-mam-orig'); }
      btn.disabled = false;
      btn.classList.remove('is-busy');
      btn.removeAttribute('aria-busy');
    }
    // Scroll to + focus the first invalid/errored control in a form.
    function focusFirstError(form) {
      if (!form) return;
      var el = form.querySelector('.error, .has-error, [aria-invalid="true"], :invalid');
      if (!el) return;
      try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
      try { el.focus({ preventScroll: true }); } catch (e) { if (el.focus) el.focus(); }
    }
    // Announce a transient status message to screen readers via a shared,
    // visually-hidden polite live region (created on first use).
    var liveRegion = null;
    function announce(msg) {
      if (!liveRegion) {
        liveRegion = document.createElement('div');
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.setAttribute('role', 'status');
        liveRegion.style.cssText = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0';
        document.body.appendChild(liveRegion);
      }
      liveRegion.textContent = '';
      setTimeout(function () { liveRegion.textContent = String(msg || ''); }, 30);
    }
    return { setBusy: setBusy, resetBusy: resetBusy, focusFirstError: focusFirstError, announce: announce };
  })();

  // ============================================================
  // Partials — load shared header & footer
  // ============================================================
  function loadPartial(id, file) {
    var slot = document.getElementById(id);
    if (!slot) return Promise.resolve();
    return fetch(file).then(function (r) { return r.text(); }).then(function (html) {
      slot.outerHTML = html;
    }).catch(function () {
      slot.innerHTML = '<div style="padding:20px;color:#FB7185">Failed to load ' + file + '</div>';
    });
  }
  window.MAM_LOAD_PARTIAL = loadPartial;

  // ============================================================
  // Preloader
  // ============================================================
  function initPreloader() {
    var pl = $('#preloader'); if (!pl) return;
    function hide() { pl.classList.add('hidden'); setTimeout(function () { pl.style.display = 'none'; }, 500); }
    if (document.readyState === 'complete') setTimeout(hide, 500);
    else { window.addEventListener('load', function () { setTimeout(hide, 400); }); setTimeout(hide, 3500); }
  }

  // ============================================================
  // Navigation — sticky, mobile menu, dropdowns
  // ============================================================
  function initNav() {
    var navbar = $('#navbar');
    var hamburger = $('#hamburgerBtn');
    var mobileMenu = $('#mobileMenu');
    var floatingEnroll = $('#floatingEnroll');
    // Context-aware "Apply Now" FAB: it's redundant or distracting on the
    // application itself and other transactional/authenticated surfaces, so
    // remove it there entirely (it stays on marketing/info pages).
    (function () {
      if (!floatingEnroll) return;
      var slug = (location.pathname.split('/').pop() || '').replace(/\.(php|html)$/, '') || 'index';
      // 'instructor-apply' is NOT covered by 'apply' — the slug is matched
      // whole, and this is the same blind spot `lead.js` has (it path-tests for
      // `/apply`, which `/instructor-apply` does not match, which is why that
      // page carries `data-no-lead-cta`). MEASURED: the pill advertised the
      // STUDENT wizard to a prospective TEACHER and, once the faculty form grew
      // a bottom action bar, physically covered its Submit button.
      var hideOn = ['apply', 'instructor-apply', 'checkout', 'book', 'thank-you', 'status', 'contact'];
      var inApp = /\/(portal|admin)(\/|$)/.test(location.pathname);
      // On touch the pill goes entirely. MEASURED on first visit at 390x700:
      // the pill, lead.js's CTA bar and the cookie banner were 304px of a
      // 700px screen — over half the content area — and back-to-top was never
      // enrolled in the dock at all, so it overlapped the stack. The bar now
      // carries Apply at every width and the menu is one tap away, which is
      // what makes deleting this safe rather than merely tidier.
      var coarse = window.matchMedia
        && window.matchMedia('(max-width: 1024px), (pointer: coarse)').matches;
      if (hideOn.indexOf(slug) >= 0 || inApp || coarse) { floatingEnroll.remove(); floatingEnroll = null; }
    })();
    var footerEl = null;

    on(window, 'scroll', function () {
      var y = window.scrollY;
      if (navbar) navbar.classList.toggle('scrolled', y > 16);
      if (floatingEnroll) {
        var hero = $('#hero, .page-hero');
        var threshold = hero ? hero.offsetHeight * 0.6 : 400;
        var visible = y > threshold;
        /* Never two Apply calls-to-action at once. lead.js already puts a
           persistent "Next intake is open — APPLY NOW" bar in the same dock,
           and the design asking for this pill on mobile could not know that:
           MEASURED at 390, the two stacked above the cookie banner and shouted
           the same thing twice. The bar is dismissible for 30 days, so
           deleting the pill would leave mobile with no persistent Apply path
           afterwards — which is the thing the design wanted. So they are
           mutually exclusive: the bar wins while it is up, the pill takes over
           once it is gone. */
        if (visible && document.querySelector('.mam-cta-bar')) visible = false;
        floatingEnroll.classList.toggle('visible', visible);
        // `inert` rather than aria-hidden: aria-hidden on a container wrapping
        // a focusable link is a known trap — the link stays tabbable but loses
        // its name, so a screen-reader user lands on an unlabelled "link".
        // inert removes focusability and AT exposure together, so the two can
        // never drift out of step with the CSS.
        if (visible) floatingEnroll.removeAttribute('inert');
        else floatingEnroll.setAttribute('inert', '');

        // Dock above the footer on large screens so the button never covers
        // the footer links. Below 1024px it stays a normal fixed pill.
        if (!footerEl) footerEl = document.querySelector('footer[role="contentinfo"]') || document.querySelector('footer');
        if (footerEl && window.innerWidth > 1024) {
          var fTop = footerEl.getBoundingClientRect().top;
          if (fTop < window.innerHeight - 24) {
            floatingEnroll.classList.add('docked');
            floatingEnroll.style.top = Math.max(0, y + fTop - floatingEnroll.offsetHeight - 20) + 'px';
          } else {
            floatingEnroll.classList.remove('docked');
            floatingEnroll.style.top = '';
          }
        } else if (floatingEnroll.classList.contains('docked')) {
          floatingEnroll.classList.remove('docked');
          floatingEnroll.style.top = '';
        }
      }
    }, { passive: true });

    var menuOpen = false;
    var menuOpenerEl = null;
    // The menu covers the whole viewport and carries its OWN 56px head, so
    // there is nothing to align it to — the bar's hamburger and the head's
    // close button are two halves of one toggle. (It used to be offset to the
    // navbar's measured bottom, which moved with the announce bar.)
    function setMenu(open) {
      menuOpen = open;
      if (hamburger) {
        hamburger.classList.toggle('open', open);
        hamburger.setAttribute('aria-expanded', String(open));
      }
      if (mobileMenu) {
        mobileMenu.classList.toggle('open', open);
        mobileMenu.setAttribute('aria-hidden', String(!open));
        if (open) {
          mobileMenu.setAttribute('aria-modal', 'true');
          mobileMenu.setAttribute('role', 'dialog');
          menuOpenerEl = document.activeElement;
          // Move focus into the menu for keyboard users
          var firstLink = mobileMenu.querySelector('a, button');
          if (firstLink) firstLink.focus();
        } else {
          mobileMenu.removeAttribute('aria-modal');
          // Restore focus to whatever opened the menu
          if (menuOpenerEl && typeof menuOpenerEl.focus === 'function') {
            menuOpenerEl.focus();
            menuOpenerEl = null;
          }
        }
      }
      document.body.style.overflow = open ? 'hidden' : '';
    }
    // Initial aria-hidden state
    if (mobileMenu) mobileMenu.setAttribute('aria-hidden', 'true');
    on(hamburger, 'click', function () { setMenu(!menuOpen); });
    on($('#menuCloseBtn'), 'click', function () { setMenu(false); });
    // Search is the bar's other control. There is no separate search overlay:
    // the menu already holds the field, so the button opens the menu and puts
    // the caret in it rather than shipping a second surface that does one
    // thing. (`focus()` must wait for the panel's visibility transition, or
    // the field is still `visibility:hidden` and the focus is dropped.)
    on($('#navSearchBtn'), 'click', function () {
      setMenu(true);
      setTimeout(function () { var f = $('#menuSearch'); if (f) f.focus(); }, 320);
    });
    $$('#mobileMenu a').forEach(function (a) { on(a, 'click', function () { setMenu(false); }); });
    on(document, 'keydown', function (e) { if (e.key === 'Escape' && menuOpen) setMenu(false); });

    // On rotate/resize: never strand the menu open when we cross to the
    // desktop layout (where the hamburger is hidden), and keep it aligned.
    on(window, 'resize', function () {
      if (!menuOpen) return;
      if (window.innerWidth > 1024) setMenu(false);
    });

    // Focus trap inside open mobile menu — Tab cycles within
    on(document, 'keydown', function (e) {
      if (!menuOpen || e.key !== 'Tab' || !mobileMenu) return;
      var focusable = mobileMenu.querySelectorAll('a[href], button:not([disabled])');
      if (!focusable.length) return;
      var first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    // Promote every error message to a screen-reader live region so
    // appearing/changing text is announced. Covers all of the form-error
    // conventions in use across the site (apply.css `.field-error`/`.form-error`,
    // the inline `.su-field-error` on summer/checkout/tuition, scholarship's
    // feedback banner, instructor-apply's `#facErr`) so no form's errors go
    // silent to assistive tech.
    $$('.field-error, .form-error, .su-field-error, .sch-feedback, #facErr, [data-error-live]').forEach(function (el) {
      if (!el.getAttribute('role')) el.setAttribute('role', 'alert');
      if (!el.getAttribute('aria-live')) el.setAttribute('aria-live', 'polite');
    });

    // Universal "focus the first invalid field" behaviour: browsers fire a
    // (non-bubbling) `invalid` event per field when a form fails native HTML5
    // validation on submit. Caught in the capture phase, this scrolls to and
    // focuses the first offending control — a consistent recovery UX for every
    // form that uses `required`/`type=email`/etc., with no per-form wiring.
    var invalidRAF = 0, firstInvalid = null;
    document.addEventListener('invalid', function (e) {
      var el = e.target;
      if (!el || !el.focus) return;
      if (!firstInvalid) firstInvalid = el;
      if (invalidRAF) return;
      invalidRAF = requestAnimationFrame(function () {
        invalidRAF = 0;
        var target = firstInvalid; firstInvalid = null;
        if (!target) return;
        try { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e2) {}
        try { target.focus({ preventScroll: true }); } catch (e2) { target.focus(); }
      });
    }, true);

    $$('[data-mobile-toggle]').forEach(function (btn) {
      on(btn, 'click', function () {
        var key = btn.getAttribute('data-mobile-toggle');
        var sub = document.getElementById('m-' + key);
        var open = sub && sub.classList.contains('open');
        // Accordion: collapse every section, then open the clicked one if it was
        // closed. Keep aria-expanded in sync so screen readers announce state.
        $$('[data-mobile-toggle]').forEach(function (b) { b.classList.remove('open'); b.setAttribute('aria-expanded', 'false'); });
        $$('.mobile-nav-sub').forEach(function (s) { s.classList.remove('open'); });
        if (!open && sub) { btn.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); sub.classList.add('open'); }
      });
    });

    // Desktop dropdown: JS-managed hover with delay so cursor can cross
    // the gap to a fixed-position mega menu without :hover dropping out.
    var CLOSE_DELAY = 220;
    $$('.nav-item').forEach(function (item) {
      var trigger = item.querySelector('.nav-link');
      var dd = item.querySelector('.nav-dropdown, .mega-menu');
      if (!trigger || !dd) return;
      trigger.setAttribute('aria-haspopup', 'true');
      trigger.setAttribute('aria-expanded', 'false');
      var closeT = null;
      function open() {
        if (closeT) { clearTimeout(closeT); closeT = null; }
        // Close other open menus
        $$('.nav-item.js-hover').forEach(function (n) { if (n !== item) n.classList.remove('js-hover', 'open'); });
        item.classList.add('js-hover'); item.classList.add('open');
        trigger.setAttribute('aria-expanded', 'true');
      }
      function scheduleClose() {
        if (closeT) clearTimeout(closeT);
        closeT = setTimeout(function () {
          item.classList.remove('js-hover'); item.classList.remove('open');
          trigger.setAttribute('aria-expanded', 'false');
          closeT = null;
        }, CLOSE_DELAY);
      }
      on(item, 'mouseenter', open);
      on(item, 'mouseleave', scheduleClose);
      on(dd,   'mouseenter', open);
      on(dd,   'mouseleave', scheduleClose);
      on(trigger, 'focus', open);
      on(item, 'focusout', function (e) {
        if (!item.contains(e.relatedTarget) && !dd.contains(e.relatedTarget)) scheduleClose();
      });
      on(trigger, 'keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
          var first = dd.querySelector('a');
          if (first) { e.preventDefault(); open(); first.focus(); }
        }
        if (e.key === 'Escape') { item.classList.remove('js-hover', 'open'); trigger.blur(); }
      });
      on(dd, 'keydown', function (e) { if (e.key === 'Escape') { item.classList.remove('js-hover', 'open'); trigger.focus(); } });
    });

    // Highlight current page in nav. Normalise so it matches whether the
    // site is served with clean URLs (/apply) or legacy .html (/apply.html),
    // and treats /, /index and /index.html all as "home".
    var norm = function (p) {
      p = (p || '').split('#')[0].split('?')[0].split('/').pop();
      p = p.replace(/\.html$/, '');
      return p === '' || p === 'index' ? 'home' : p;
    };
    var path = norm(location.pathname);
    $$('.nav-link, .mobile-nav-sub-link, a.menu-row, .menu-minor a').forEach(function (a) {
      var href = a.getAttribute('href');
      if (!href) return;
      if (norm(href) === path) a.classList.add('active');
    });
  }

  // ============================================================
  // Hero carousel — rewritten 20 Aug 2026 from the canonical design frame
  // (Hero_Nav_Fixed.dc.html). What the old one lacked, and why each matters:
  //
  //   • It had no pause control, which makes an auto-advancing carousel a
  //     WCAG 2.2.2 failure however pretty it is.
  //   • It ignored prefers-reduced-motion, so a visitor who asked for less
  //     motion got a slide change every six and a half seconds anyway.
  //   • It announced nothing, so a screen-reader user was read a heading that
  //     silently became a different heading.
  //   • Steering it restarted the timer, so a visitor who navigated
  //     deliberately was moved on anyway. Deliberate navigation means they want
  //     to stay: it now hands over control and stops.
  //   • aria-selected on a button that is not in a listbox is meaningless;
  //     aria-current is what "this is the one you are on" is spelled.
  // ============================================================
  function initHero() {
    var slides = $$('.hero-slide');
    var dots   = $$('.hero-dot');
    var live   = $('#heroLive');
    var toggle = $('#heroPause');
    var stage  = $('#hero');
    if (!slides.length || !stage) return;

    var INTERVAL = 6500;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var i = 0, timer = null, paused = !!reduce;

    var fills = dots.map(function (d) { return d.querySelector('.hero-dot-fill'); });

    // The active dot doubles as a progress bar. scaleX rather than width: a
    // 0 → 100% width transition does not interpolate (a length to a
    // percentage), and a transform runs on the compositor rather than
    // relayouting the dot forty times a second.
    function setFill(mode) {
      fills.forEach(function (f, k) {
        if (!f) return;
        f.style.transition = 'none';
        f.style.transform = (k === i && mode === 'solid') ? 'scaleX(1)' : 'scaleX(0)';
      });
      var f = fills[i];
      if (!f || mode !== 'run') return;
      void f.offsetWidth;                       // commit the reset before animating
      f.style.transition = 'transform ' + INTERVAL + 'ms linear';
      f.style.transform = 'scaleX(1)';
    }

    // Freeze where it actually is, so a hover does not rewind the bar.
    function freezeFill() {
      var f = fills[i];
      if (!f) return;
      var t = getComputedStyle(f).transform;
      var x = 0;
      if (t && t !== 'none') {
        if (window.DOMMatrixReadOnly) { try { x = new DOMMatrixReadOnly(t).a || 0; } catch (e) { x = 0; } }
        else { var m = t.match(/matrix\(([^,]+)/); x = m ? parseFloat(m[1]) || 0 : 0; }
      }
      f.style.transition = 'none';
      f.style.transform = 'scaleX(' + x + ')';
    }

    // `visibility` takes a slide out of the tab order, but it transitions over
    // .9s and computes as `visible` for the whole fade — MEASURED: for those
    // 900ms the outgoing slide's two links were still focusable. `inert` closes
    // that window immediately, and is what actually expresses "this subtree is
    // not interactive" rather than leaving it to a paint property.
    function setInert(el, on) {
      if ('inert' in HTMLElement.prototype) el.inert = on;
      else if (on) el.setAttribute('inert', ''); else el.removeAttribute('inert');
    }
    slides.forEach(function (sl, k) { setInert(sl, k !== 0); });

    function go(n, announce) {
      var prevEl = slides[i];
      prevEl.classList.remove('active');
      prevEl.setAttribute('aria-hidden', 'true');
      setInert(prevEl, true);
      if (dots[i]) { dots[i].classList.remove('active'); dots[i].removeAttribute('aria-current'); }

      i = (n + slides.length) % slides.length;

      var cur = slides[i];
      cur.classList.add('active');
      cur.removeAttribute('aria-hidden');
      setInert(cur, false);
      if (dots[i]) { dots[i].classList.add('active'); dots[i].setAttribute('aria-current', 'true'); }

      setFill(paused ? 'solid' : 'run');
      if (announce && live) {
        live.textContent = 'Slide ' + (i + 1) + ' of ' + slides.length + ': ' +
          (cur.getAttribute('data-title') || '');
      }
    }

    function stop()  { if (timer) { clearInterval(timer); timer = null; } }
    function start() {
      if (paused) return;
      stop();
      timer = setInterval(function () { go(i + 1, false); }, INTERVAL);
    }

    function syncToggle() {
      if (!toggle) return;
      toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
      toggle.setAttribute('aria-label', paused ? 'Play the slideshow' : 'Pause the slideshow');
      toggle.innerHTML = '<i class="fa-solid fa-' + (paused ? 'play' : 'pause') + '" aria-hidden="true"></i>';
    }

    // Deliberate navigation hands over control: it stops the timer for good
    // rather than restarting it under the visitor.
    function steer(fn) {
      return function (e) {
        if (e && e.preventDefault) e.preventDefault();
        paused = true;
        stop();
        syncToggle();
        fn();
        setFill('solid');
      };
    }

    on($('#nextArrow'), 'click', steer(function () { go(i + 1, true); }));
    on($('#prevArrow'), 'click', steer(function () { go(i - 1, true); }));
    dots.forEach(function (d) {
      on(d, 'click', steer(function () { go(parseInt(d.getAttribute('data-dot'), 10) || 0, true); }));
    });

    if (toggle) on(toggle, 'click', function () {
      paused = !paused;
      syncToggle();
      if (paused) { stop(); setFill('solid'); } else { start(); setFill('run'); }
    });

    // Arrow keys work anywhere inside the hero once focus is in it, but never
    // steal them from a link or a field.
    on(stage, 'keydown', function (e) {
      if (e.target && e.target.closest && e.target.closest('a,input,textarea,select')) return;
      if (e.key === 'ArrowRight') steer(function () { go(i + 1, true); })(e);
      else if (e.key === 'ArrowLeft') steer(function () { go(i - 1, true); })(e);
    });

    // Drag with a mouse — the gesture that already worked with a finger.
    var px = null;
    on(stage, 'pointerdown', function (e) {
      if (e.pointerType !== 'mouse' || (e.target.closest && e.target.closest('a,button'))) return;
      px = e.clientX;
      stage.style.cursor = 'grabbing';
    });
    function endDrag(e) {
      if (px === null) return;
      var dx = e.clientX - px;
      px = null;
      stage.style.cursor = '';
      if (Math.abs(dx) > 60) steer(function () { go(i + (dx < 0 ? 1 : -1), true); })();
    }
    on(stage, 'pointerup', endDrag);
    on(stage, 'pointerleave', endDrag);

    var tx0 = 0, ty0 = 0;
    on(stage, 'touchstart', function (e) {
      tx0 = e.changedTouches[0].clientX; ty0 = e.changedTouches[0].clientY;
    }, { passive: true });
    on(stage, 'touchend', function (e) {
      var dx = e.changedTouches[0].clientX - tx0;
      var dy = e.changedTouches[0].clientY - ty0;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
        steer(function () { go(i + (dx < 0 ? 1 : -1), true); })();
      }
    }, { passive: true });

    // Hover and focus HOLD rather than pause: the visitor is reading, and the
    // bar resumes from where it stopped instead of restarting the interval.
    function hold()   { stop(); freezeFill(); }
    function resume() { if (!paused) { start(); setFill('run'); } }
    on(stage, 'mouseenter', hold);
    on(stage, 'mouseleave', resume);
    on(stage, 'focusin', hold);
    on(stage, 'focusout', resume);

    on(document, 'visibilitychange', function () { document.hidden ? hold() : resume(); });

    syncToggle();
    setFill(paused ? 'solid' : 'run');
    start();
  }

  // ============================================================
  // Smooth scroll
  // ============================================================
  function initSmoothScroll() {
    $$('a[href^="#"]').forEach(function (a) {
      on(a, 'click', function (e) {
        var href = a.getAttribute('href');
        if (!href || href === '#' || href.length < 2) return;
        // Resolve by id; querySelector on a fragment like "#2-modes" (id
        // starting with a digit) is an invalid selector and would throw.
        var target = null;
        try { target = document.getElementById(decodeURIComponent(href.slice(1))) || document.querySelector(href); } catch (e2) { return; }
        if (!target) return;
        e.preventDefault();
        var offset = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--nav-h'), 10) || 80;
        var top = target.getBoundingClientRect().top + window.scrollY - offset - 8;
        window.scrollTo({ top: top, behavior: 'smooth' });
        try { history.replaceState(null, '', href); } catch (e2) {}
      });
    });
  }

  // ============================================================
  // FAQ
  // ============================================================
  function initFaq() {
    $$('.faq-question').forEach(function (q) {
      on(q, 'click', function () {
        var item = q.closest('.faq-item');
        var openNow = item.classList.contains('open');
        $$('.faq-item').forEach(function (i) { i.classList.remove('open'); i.querySelector('.faq-question').setAttribute('aria-expanded', 'false'); });
        if (!openNow) { item.classList.add('open'); q.setAttribute('aria-expanded', 'true'); }
      });
    });
    // FAQ schema for SEO
    var faqs = $$('.faq-item').map(function (item) {
      var q = item.querySelector('.faq-question span');
      var a = item.querySelector('.faq-answer-inner');
      return { q: q ? q.textContent.trim() : '', a: a ? a.textContent.trim() : '' };
    }).filter(function (x) { return x.q && x.a; });
    if (!faqs.length) return;
    var schema = {
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      'mainEntity': faqs.map(function (f) {
        return { '@type': 'Question', 'name': f.q, 'acceptedAnswer': { '@type': 'Answer', 'text': f.a } };
      })
    };
    var s = document.createElement('script');
    s.type = 'application/ld+json';
    s.textContent = JSON.stringify(schema);
    document.head.appendChild(s);
  }

  // ============================================================
  // Animations + scroll spy + counters
  // ============================================================
  function initAnimations() {
    // The fade-reveal observer is normally armed early (before partials) by the
    // top-level IIFE; only arm here if that didn't run, so we never double-bind.
    if (!window.__mamRevealArmed) {
      var targets = $$('.fade-in-up, .fade-in');
      if ('IntersectionObserver' in window) {
        var obs = new IntersectionObserver(function (entries) {
          entries.forEach(function (en) {
            if (en.isIntersecting) { en.target.classList.add('visible'); obs.unobserve(en.target); }
          });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        targets.forEach(function (el) { obs.observe(el); });
      } else {
        targets.forEach(function (el) { el.classList.add('visible'); });
      }
    }

    var stats = $$('.stat-number[data-count]');
    if ('IntersectionObserver' in window && stats.length) {
      var sObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;
          var el = en.target;
          var to = parseInt(el.getAttribute('data-count'), 10);
          var suffix = el.textContent.replace(/[0-9]/g, '').trim() || '+';
          var n = 0, step = Math.max(1, to / 60);
          var t = setInterval(function () {
            n = Math.min(n + step, to);
            el.textContent = Math.floor(n) + suffix;
            if (n >= to) clearInterval(t);
          }, 16);
          sObs.unobserve(el);
        });
      }, { threshold: 0.5 });
      stats.forEach(function (el) { sObs.observe(el); });
    }

    var meters = $$('.meter-fill');
    if ('IntersectionObserver' in window && meters.length) {
      var mObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) {
            en.target.style.width = en.target.getAttribute('data-fill') + '%';
            mObs.unobserve(en.target);
          }
        });
      }, { threshold: 0.4 });
      meters.forEach(function (el) { mObs.observe(el); });
    }

    var navLinks = $$('.nav-link');
    var sections = $$('main section[id]');
    if ('IntersectionObserver' in window && sections.length) {
      var spy = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;
          var id = '#' + en.target.id;
          navLinks.forEach(function (l) {
            var href = l.getAttribute('href');
            l.classList.toggle('active', href === id || (href && href.indexOf(id) !== -1));
          });
        });
      }, { rootMargin: '-45% 0px -50% 0px' });
      sections.forEach(function (s) { spy.observe(s); });
    }
  }

  // ============================================================
  // Countdown (intake)
  // ============================================================
  function initCountdown() {
    // Stamp the configurable intake label wherever the markup references it.
    var intake = (window.MAM_CONFIG && window.MAM_CONFIG.intake) || {};
    if (intake.label) $$('[data-intake-label]').forEach(function (el) { el.textContent = intake.label; });
    var d = $('#cd-days'), h = $('#cd-hours'), m = $('#cd-mins'), s = $('#cd-secs');
    if (!d) return;
    var target = new Date(intake.startDate || '2026-09-07T00:00:00Z').getTime();
    function pad(n) { return String(n).padStart(2, '0'); }
    function tick() {
      var diff = target - Date.now();
      if (diff <= 0) {
        // The intake has begun — a frozen 00:00:00:00 reads as broken, so
        // swap the digits for a live message instead.
        var wrap = $('#countdown');
        if (wrap && !wrap.dataset.cdOpen) {
          wrap.dataset.cdOpen = '1';
          var intakeLbl = intake.label ? String(intake.label).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }) + ' intake is underway' : 'Enrolment is open';
          wrap.innerHTML = '<div class="countdown-open" style="padding:14px 22px;border:1px solid var(--border-gold,rgba(224,138,26,.45));border-radius:12px;color:var(--gold-bright,#FCC647);font-weight:800;letter-spacing:0.04em"><i class="fa-solid fa-door-open" aria-hidden="true"></i> ' + intakeLbl + ' — applications are reviewed weekly.</div>';
        }
        return true; // done — caller stops the interval
      }
      d.textContent = pad(Math.floor(diff / 86400000));
      h.textContent = pad(Math.floor((diff % 86400000) / 3600000));
      m.textContent = pad(Math.floor((diff % 3600000) / 60000));
      s.textContent = pad(Math.floor((diff % 60000) / 1000));
      return false;
    }
    if (tick()) return;
    var timer = setInterval(function () { if (tick()) clearInterval(timer); }, 1000);
  }

  // ============================================================
  // Testimonials
  // ============================================================
  function initTestimonials() {
    var track = $('#testimonialsTrack'); if (!track) return;
    // Source from config so the academy can edit/extend without touching JS.
    var data = (window.MAM_CONFIG && window.MAM_CONFIG.testimonials) || [];
    if (!data.length) { track.parentNode && (track.parentNode.style.display = 'none'); return; }

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function avatar(t) {
      var photo = t.photo && window.MAM_CDN ? window.MAM_CDN.image(t.photo, { width: 120 }) : t.photo;
      return photo
        ? '<div class="testimonial-avatar"><img src="' + esc(photo) + '" alt="' + esc(t.name) + '" loading="lazy"></div>'
        : '<div class="testimonial-avatar" aria-hidden="true">' + esc(t.initials || (t.name || '?').slice(0, 2).toUpperCase()) + '</div>';
    }
    function card(t) {
      var stars = Math.max(0, Math.min(5, t.stars || 5));
      return '<figure class="testimonial-card" role="group" aria-roledescription="testimonial">' +
        '<div class="testimonial-stars" aria-label="' + stars + ' out of 5 stars">' + '★'.repeat(stars) + '<span style="opacity:.25">' + '★'.repeat(5 - stars) + '</span></div>' +
        '<blockquote class="testimonial-quote">&ldquo;' + esc(t.quote) + '&rdquo;</blockquote>' +
        '<figcaption class="testimonial-author">' + avatar(t) +
        '<div><div class="testimonial-name">' + esc(t.name) + '</div><div class="testimonial-role">' + esc(t.role) + '</div></div>' +
        '</figcaption></figure>';
    }

    // Build a proper rotator: a viewport that scrolls one card at a time,
    // with dots, prev/next, autoplay (paused on hover/focus/visibility),
    // and native touch-swipe via scroll-snap.
    var wrap = track.parentNode; // .testimonials-track-wrap
    wrap.classList.add('is-rotator');
    track.classList.remove('testimonials-track');
    track.classList.add('testimonials-rail');
    track.innerHTML = data.map(card).join('');

    var controls = document.createElement('div');
    controls.className = 'testimonials-controls';
    controls.innerHTML =
      '<button type="button" class="t-arrow t-prev" aria-label="Previous testimonial"><i class="fa-solid fa-chevron-left"></i></button>' +
      '<div class="t-dots" role="tablist" aria-label="Choose testimonial"></div>' +
      '<button type="button" class="t-arrow t-next" aria-label="Next testimonial"><i class="fa-solid fa-chevron-right"></i></button>';
    wrap.appendChild(controls);

    var dotsWrap = controls.querySelector('.t-dots');
    data.forEach(function (_, i) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 't-dot'; b.setAttribute('role', 'tab');
      b.setAttribute('aria-label', 'Testimonial ' + (i + 1));
      b.addEventListener('click', function () { go(i, true); });
      dotsWrap.appendChild(b);
    });
    var dots = Array.prototype.slice.call(dotsWrap.children);
    var cards = Array.prototype.slice.call(track.children);
    var index = 0, timer = null, userPaused = false;

    function go(i, fromUser) {
      index = (i + cards.length) % cards.length;
      var el = cards[index];
      track.scrollTo({ left: el.offsetLeft - track.offsetLeft, behavior: 'smooth' });
      dots.forEach(function (d, di) { d.classList.toggle('active', di === index); d.setAttribute('aria-selected', di === index ? 'true' : 'false'); });
      if (fromUser) restart();
    }
    function next() { go(index + 1); }
    function prev() { go(index - 1); }
    function start() { if (!userPaused) { stop(); timer = setInterval(next, 6000); } }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function restart() { stop(); start(); }

    controls.querySelector('.t-next').addEventListener('click', function () { next(); restart(); });
    controls.querySelector('.t-prev').addEventListener('click', function () { prev(); restart(); });
    ['mouseenter', 'focusin', 'touchstart'].forEach(function (ev) { wrap.addEventListener(ev, function () { userPaused = true; stop(); }, { passive: true }); });
    ['mouseleave', 'focusout'].forEach(function (ev) { wrap.addEventListener(ev, function () { userPaused = false; start(); }); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
    // Keep dots in sync if the user free-scrolls/swipes the rail.
    var st;
    track.addEventListener('scroll', function () {
      clearTimeout(st);
      st = setTimeout(function () {
        var nearest = 0, best = Infinity;
        cards.forEach(function (c, ci) { var d = Math.abs(c.offsetLeft - track.offsetLeft - track.scrollLeft); if (d < best) { best = d; nearest = ci; } });
        index = nearest;
        dots.forEach(function (d, di) { d.classList.toggle('active', di === index); });
      }, 120);
    }, { passive: true });

    go(0);
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches) start();
  }

  // ============================================================
  // Cookie banner
  // ============================================================
  // Shared bottom dock: the single fixed container that stacks the transient
  // bottom sheets (cookie consent, lead CTA) so they never overlap or need
  // per-component offset hacks. lead.js reuses it via window.mamDock.
  function mamDock() {
    var d = document.getElementById('mamDock');
    if (!d) {
      d = document.createElement('div');
      d.id = 'mamDock';
      d.className = 'mam-dock';
      document.body.appendChild(d);
    }
    return d;
  }
  window.mamDock = mamDock;

  /**
   * The floating Apply pill shares the bottom-right corner with the cookie
   * banner. MEASURED at 390: with the pill no longer hidden on mobile they
   * overlap — the pill's own `docked` class is about the FOOTER and only runs
   * above 1024px, so nothing was stacking them.
   *
   * The dock is what exists for this: a fixed, column-reverse flex container
   * that the cookie banner already lives in, so stacking is the browser's job
   * rather than a per-component offset. Below the desktop breakpoint the pill
   * joins it; above, it stays a free-floating pill in the corner where there
   * is room for one.
   */
  function dockFloatingEnroll() {
    var fe = document.getElementById('floatingEnroll');
    if (!fe) return;
    var wantDock = window.innerWidth <= 1024;
    var inDock = fe.parentElement && fe.parentElement.id === 'mamDock';
    if (wantDock && !inDock) {
      fe.classList.add('in-dock');
      mamDock().appendChild(fe);
    } else if (!wantDock && inDock) {
      fe.classList.remove('in-dock');
      document.body.appendChild(fe);
    }
  }
  window.addEventListener('resize', dockFloatingEnroll);
  dockFloatingEnroll();

  function initCookie() {
    var banner = $('#cookieBanner'); if (!banner) return;
    if (Storage.getItem('mam.cookie')) { banner.remove(); return; }
    mamDock().appendChild(banner);      // dock it — stacking handled by flex
    setTimeout(function () { banner.classList.add('shown'); }, 1200);
    function dismiss(v) { return function () { Storage.setItem('mam.cookie', v); banner.classList.remove('shown'); setTimeout(function () { banner.remove(); }, 380); }; }
    on($('#cookieAccept'),  'click', dismiss('accept'));
    on($('#cookieDecline'), 'click', dismiss('decline'));
  }

  // ============================================================
  // Announcement strip
  // ============================================================
  // The old bar had no dismissal at all: it simply scrolled away, so the
  // intake message was gone for good after the first screenful and nothing
  // retained it. It is persistent now, which only works if it can be closed —
  // and the dismissal is keyed to the MESSAGE, not to the element, so a new
  // campaign reappears for somebody who closed the last one.
  function initAnnounce() {
    var strip = $('#announceStrip');
    if (!strip) return;
    var msgEl = $('#announceMsg');
    // Read the copy AFTER initCountdown has stamped [data-intake-label], or
    // every intake would hash as "September" and only the first would ever be
    // dismissible. boot() orders this after it for exactly that reason.
    function key() {
      var t = (msgEl ? msgEl.textContent : '').replace(/\s+/g, ' ').trim();
      // A short, stable digest rather than the sentence itself — localStorage
      // keys are not a place to put a marketing paragraph.
      var h = 5381, i = 0;
      for (; i < t.length; i++) h = ((h * 33) ^ t.charCodeAt(i)) >>> 0;
      return 'mam.announce.' + h.toString(36);
    }
    var k = key();
    on($('#announceClose'), 'click', function () {
      Storage.setItem(k, '1');
      strip.setAttribute('hidden', '');
    });
    function evaluate() {
      k = key();
      if (Storage.getItem(k)) strip.setAttribute('hidden', '');
      else strip.removeAttribute('hidden');
    }
    evaluate();
    // MAM_CONFIG.intake can arrive late, from /api/config/public. Re-reading
    // the copy then is the difference between "the new intake reappears for
    // everyone" and "it reappears for whoever had JS's default label".
    document.addEventListener('mam:config', function () {
      var intake = (window.MAM_CONFIG && window.MAM_CONFIG.intake) || {};
      if (intake.label) $$('[data-intake-label]').forEach(function (el) { el.textContent = intake.label; });
      evaluate();
    });
  }

  // ============================================================
  // Footer groups
  // ============================================================
  // Four <details> so the footer collapses to four 54px rows on a phone
  // instead of 35 links in one vertical run. They work with JS off at every
  // width; this only opens them where they are COLUMNS rather than
  // accordions, and closes them again on the way back down so a rotate does
  // not strand a 12-link column expanded on a phone.
  function initFooterGroups() {
    var groups = $$('.footer-group');
    if (!groups.length) return;
    var mq = window.matchMedia && window.matchMedia('(min-width: 1025px)');
    if (!mq) return;
    function sync() {
      groups.forEach(function (g) {
        if (mq.matches) g.setAttribute('open', '');
        else g.removeAttribute('open');
      });
    }
    sync();
    if (mq.addEventListener) mq.addEventListener('change', sync);
    else if (mq.addListener) mq.addListener(sync);
  }

  // ============================================================
  // Accessibility toolbar
  // ============================================================
  function initToolbar() {
    function setContrast(high) {
      document.documentElement.setAttribute('data-contrast', high ? 'high' : 'normal');
      Storage.setItem('mam.contrast', high ? 'high' : 'normal');
      var b = $('#contrastToggle'); if (b) b.classList.toggle('active', high);
    }
    on($('#contrastToggle'), 'click', function () {
      var c = document.documentElement.getAttribute('data-contrast') === 'high';
      setContrast(!c);
    });
    if (Storage.getItem('mam.contrast') === 'high') setContrast(true);

    var scale = parseFloat(Storage.getItem('mam.fontScale') || '1');
    function apply() { document.documentElement.style.fontSize = (16 * scale) + 'px'; Storage.setItem('mam.fontScale', String(scale)); }
    apply();
    on($('#fontUp'),   'click', function () { scale = Math.min(1.3, scale + 0.05); apply(); });
    on($('#fontDown'), 'click', function () { scale = Math.max(0.85, scale - 0.05); apply(); });
    on($('#printBtn'), 'click', function () { window.print(); });
  }

  // ============================================================
  // Currency toggle
  // ============================================================
  function initCurrency() {
    // Run wherever there's money on the page — the switcher is an optional
    // override; prices convert to the visitor's local currency regardless.
    var btns = $$('.currency-btn');
    if (!btns.length && !$$('[data-price]').length && !$$('[data-cur-sym]').length) return;
    var CCY = window.MAM_CCY;
    if (!CCY) return; // config.js provides the single currency source of truth
    var current = CCY.detect();
    // Currencies where a big converted number reads better abbreviated ("1.5k").
    var BIG = { NGN: 1, UGX: 1, TZS: 1, RWF: 1, MWK: 1 };

    function setCur(cur, persist) {
      if (!CCY.known(cur)) cur = 'USD';
      current = cur;
      btns.forEach(function (b) {
        var active = b.getAttribute('data-currency') === cur;
        b.classList.toggle('active', active); b.setAttribute('aria-pressed', String(active));
      });
      var sym = CCY.symbol(cur), rate = CCY.rate(cur);
      $$('[data-cur-sym]').forEach(function (s) { s.textContent = sym; });
      $$('[data-price]').forEach(function (p) {
        var usd = parseFloat(p.getAttribute('data-price'));
        // Non-numeric data-price (e.g. "meta-order") is a program KEY used by the
        // nav mega-menu chips — owned by initNavPricing, not a value to convert.
        if (!isFinite(usd)) return;
        var converted = usd * rate;
        if (cur === 'USD') p.textContent = Math.round(usd).toLocaleString();
        else if (BIG[cur] && converted >= 1000) p.textContent = Math.round(converted / 1000) + 'k';
        else p.textContent = Math.round(converted).toLocaleString();
      });
      // Inline prose amounts — <span data-amt-usd="X"> anywhere on the page.
      // Painted as a complete symbol+number string so the element can stand alone
      // in prose without a separate data-cur-sym sibling.
      $$('[data-amt-usd]').forEach(function (el) {
        el.textContent = CCY.format(parseFloat(el.getAttribute('data-amt-usd')), cur);
      });
      if (typeof initNavPricing === 'function') initNavPricing(); // keep the key-based nav chips in the same currency
      if (persist !== false) Storage.setItem('mam.currency', cur);
    }
    btns.forEach(function (b) { on(b, 'click', function () { setCur(b.getAttribute('data-currency')); }); });

    // Render immediately in the detected/saved currency (MAM_CCY reads rates live).
    setCur(current, false);

    // Re-render when config.js hydrates the server's authoritative rate map.
    document.addEventListener('mam:config', function () { setCur(current, false); });

    // Re-render when MAM_CCY's async geo-IP lookup refines the detected currency
    // (only fires when the visitor hasn't made an explicit choice). This keeps
    // every money surface on the same currency the moment location resolves.
    document.addEventListener('mam:currency', function () {
      if (!Storage.getItem('mam.currency')) setCur(CCY.detect(), false);
    });

    // 1) Live, auto-updating rates — free, key-less, cached 24h in localStorage
    //    ('mam.fx', which MAM_CCY reads). The server map still wins where present.
    (function loadRates() {
      try {
        var c = JSON.parse(Storage.getItem('mam.fx') || 'null');
        if (c && c.rates && (Date.now() - c.t) < 864e5) { return setCur(current, false); }
      } catch (e) {}
      fetch('https://open.er-api.com/v6/latest/USD', { mode: 'cors' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.rates && j.rates.USD === 1) {
            try { Storage.setItem('mam.fx', JSON.stringify({ t: Date.now(), rates: j.rates })); } catch (e) {}
            setCur(current, false);
          }
        }).catch(function () { /* keep fallback rates */ });
    })();

    // 2) Location refinement (free geo-IP lookup) is owned by MAM_CCY (config.js)
    //    so it runs once site-wide and feeds MAM_CCY.detect() — the single source
    //    of truth every money surface reads. CCY.detect() above already triggered
    //    it; we just repaint via the mam:currency listener when it resolves.
  }

  // ============================================================
  // Newsletter — uses backend API; mailto only if API unreachable
  // ============================================================
  // Lazy-loads JustValidate once and resolves with the constructor.
  // Returns a Promise<JustValidate|null> — null if the CDN is unreachable
  // so the caller can fall back to a permissive submit.
  var justValidatePromise = null;
  function loadJustValidate() {
    if (justValidatePromise) return justValidatePromise;
    justValidatePromise = new Promise(function (resolve) {
      if (window.JustValidate) return resolve(window.JustValidate);
      var s = document.createElement('script');
      // 4.5.0 was a 404 on jsDelivr — pinned to the highest published v4.
      s.src = 'https://cdn.jsdelivr.net/npm/just-validate@4.3.0/dist/just-validate.production.min.js';
      s.async = true;
      s.onload  = function () { resolve(window.JustValidate || null); };
      s.onerror = function () { resolve(null); };
      document.head.appendChild(s);
    });
    return justValidatePromise;
  }
  window.MAM_LOAD_JUSTVALIDATE = loadJustValidate;

  function initNewsletter() {
    var form = $('#newsletterForm'); if (!form) return;
    var input = $('#newsletterEmail');
    var msg = $('#newsletterMsg');

    var mailtoSubscribe = function (em) {
      var fallback = (window.MAM_CONFIG && window.MAM_CONFIG.forms && window.MAM_CONFIG.forms.fallbackEmail) || 'academy@mamcentre.org';
      window.location.href = 'mailto:' + fallback + '?subject=' + encodeURIComponent('Newsletter subscribe') + '&body=' + encodeURIComponent('Please subscribe ' + em);
      if (msg) { msg.textContent = 'Opening your email…'; msg.classList.add('shown'); }
    };
    var submitWithEmail = function (email) {
      if (input) input.style.borderColor = '';
      if (window.MAM_API && window.MAM_API.subscribeNewsletter) {
        window.MAM_API.subscribeNewsletter(email, 'footer-' + location.pathname)
          .then(function () {
            if (msg) { msg.textContent = "Thanks — you're subscribed."; msg.classList.add('shown'); }
            input.value = '';
          })
          .catch(function (err) {
            if (err && err.status === 429) {
              if (msg) { msg.textContent = 'You\'re sending too many requests — please try later.'; msg.classList.add('shown'); msg.style.color = 'var(--red-hot)'; }
              return;
            }
            mailtoSubscribe(email);
          });
      } else { mailtoSubscribe(email); }
    };

    // Prefer JustValidate when reachable — accessible errors, aria-live, clean
    // focus management. Fall back to a permissive submit if the CDN fails.
    loadJustValidate().then(function (JV) {
      if (!JV) {
        on(form, 'submit', function (e) {
          e.preventDefault();
          var em = (input.value || '').trim();
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) { input.style.borderColor = 'var(--red-hot)'; return; }
          submitWithEmail(em);
        });
        return;
      }
      var v = new JV(form, { errorFieldCssClass: 'jv-error', errorLabelCssClass: 'jv-error-label', focusInvalidField: true });
      v.addField('#newsletterEmail', [
        { rule: 'required', errorMessage: 'Email is required' },
        { rule: 'email',    errorMessage: 'Enter a valid email' }
      ]).onSuccess(function (e) { e.preventDefault(); submitWithEmail((input.value || '').trim()); });
    });
  }

  // ============================================================
  // Programs filter (only if present)
  // ============================================================
  function initFilters() {
    var tabs = $$('.filter-tab');
    var cards = $$('.program-card');
    if (!tabs.length || !cards.length) return;
    tabs.forEach(function (tab) {
      on(tab, 'click', function () {
        tabs.forEach(function (t) { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
        tab.classList.add('active'); tab.setAttribute('aria-selected', 'true');
        var f = tab.getAttribute('data-filter');
        cards.forEach(function (c) {
          c.style.display = (f === 'all' || c.getAttribute('data-category') === f) ? '' : 'none';
        });
      });
    });
  }

  // ============================================================
  // Image fallback
  // ============================================================
  function initImageFallback() {
    $$('img').forEach(function (img) {
      on(img, 'error', function () {
        if (img.dataset.fallback === 'done') return;
        img.dataset.fallback = 'done';
        img.style.background = 'linear-gradient(135deg, var(--bg-elevated), var(--bg-card))';
        img.style.minHeight = '180px';
        img.removeAttribute('src');
      });
    });
  }

  // ============================================================
  // Year stamp
  // ============================================================
  function initYear() {
    $$('#year, [data-year]').forEach(function (e) { e.textContent = new Date().getFullYear(); });
  }

  // ============================================================
  // Boot
  // ============================================================
  // ============================================================
  // Scholarship — config-driven CTAs injected site-wide
  // Reads MAM_CONFIG.scholarship and fills every [data-scholarship-banner]
  // placeholder + any [data-scholarship-link]. Change copy/URL in config.js
  // (one place) and it updates everywhere; set enabled:false to remove it all.
  // ============================================================
  function initScholarship() {
    var s = (window.MAM_CONFIG && window.MAM_CONFIG.scholarship) || {};
    var banners = $$('[data-scholarship-banner]');
    var links = $$('[data-scholarship-link]');
    if (!s.enabled) {
      banners.forEach(function (el) { el.remove(); });
      links.forEach(function (el) { var host = (el.closest && el.closest('[data-scholarship-host]')) || el; host.style.display = 'none'; });
      return;
    }
    // Scheme-guard the admin-configured URL before it enters href sinks below:
    // allow http(s) and root-relative (not protocol-relative //), else neutralise.
    var url = (/^(https?:\/\/|\/(?!\/))/i.test(String(s.url || '')) ? s.url : '#');
    var esc = function (t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); };
    injectScholarshipStyles();
    banners.forEach(function (el) {
      var v = el.getAttribute('data-scholarship-banner') || 'full';
      if (v === 'compact') {
        el.innerHTML = '<a class="scholar-compact" href="' + esc(url) + '">'
          + '<span class="scholar-compact-ic"><i class="fa-solid fa-hand-holding-heart"></i></span>'
          + '<span class="scholar-compact-tx"><strong>' + esc(s.cta) + '</strong><span>' + esc(s.short) + '</span></span>'
          + '<i class="fa-solid fa-arrow-right scholar-compact-arr"></i></a>';
      } else {
        el.innerHTML = '<div class="scholar-band">'
          + '<div class="scholar-band-glow" aria-hidden="true"></div>'
          + '<div class="scholar-band-inner">'
          + '<div class="scholar-band-ic"><i class="fa-solid fa-hand-holding-heart"></i></div>'
          + '<div class="scholar-band-body">'
          + (s.eyebrow ? '<div class="scholar-band-eyebrow">' + esc(s.eyebrow) + '</div>' : '')
          + '<h2 class="scholar-band-title">' + esc(s.title) + '</h2>'
          + '<p class="scholar-band-blurb">' + esc(s.blurb) + '</p></div>'
          + '<div class="scholar-band-act"><a class="btn btn-primary btn-large" href="' + esc(url) + '"><i class="fa-solid fa-graduation-cap"></i>' + esc(s.cta) + '</a></div>'
          + '</div></div>';
      }
    });
    links.forEach(function (el) {
      if (el.tagName === 'A') el.href = url;
      if (el.getAttribute('data-scholarship-link') === 'text') el.textContent = s.cta;
    });
  }
  function injectScholarshipStyles() {
    if (document.getElementById('scholar-css')) return;
    var css = '.scholar-band{position:relative;overflow:hidden;border-radius:22px;background:linear-gradient(135deg,rgba(224,138,26,0.16),rgba(123,58,237,0.12));border:1px solid var(--border-gold,rgba(224,138,26,0.4));padding:clamp(22px,4vw,40px)}'
      + '.scholar-band-glow{position:absolute;inset:-40% -10% auto auto;width:380px;height:380px;background:radial-gradient(circle,rgba(251,191,36,0.22),transparent 70%);pointer-events:none}'
      + '.scholar-band-inner{position:relative;display:grid;grid-template-columns:auto 1fr auto;gap:clamp(16px,3vw,28px);align-items:center}'
      + '.scholar-band-ic{width:64px;height:64px;border-radius:18px;display:grid;place-items:center;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#120D08;font-size:1.6rem;flex:0 0 auto;box-shadow:0 8px 24px rgba(224,138,26,0.35)}'
      + '.scholar-band-eyebrow{font-size:0.76rem;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:var(--gold-bright,#FBBF24);margin-bottom:8px}'
      + '.scholar-band-title{font-family:var(--font-display,inherit);font-size:clamp(1.4rem,3vw,2rem);line-height:1.15;margin:0 0 8px;color:var(--text,#fff)}'
      + '.scholar-band-blurb{margin:0;color:var(--text-soft,#cbd5e1);font-size:0.98rem;line-height:1.6;max-width:60ch}'
      + '.scholar-band-act{flex:0 0 auto}.scholar-band-act .btn{white-space:nowrap}'
      + '@media(max-width:860px){.scholar-band-inner{grid-template-columns:auto 1fr}.scholar-band-act{grid-column:1/-1}.scholar-band-act .btn{width:100%;justify-content:center}}'
      + '@media(max-width:520px){.scholar-band-inner{grid-template-columns:1fr;text-align:center}.scholar-band-ic{margin:0 auto}.scholar-band-blurb{margin-inline:auto}}'
      + '.scholar-compact{display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:14px;background:var(--bg-surface,#1a1410);border:1px solid var(--border-gold,rgba(224,138,26,0.4));text-decoration:none;transition:transform .2s,border-color .2s}'
      + '.scholar-compact:hover{transform:translateY(-2px);border-color:var(--gold-bright,#FBBF24)}'
      + '.scholar-compact-ic{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#120D08;font-size:1.1rem;flex:0 0 auto}'
      + '.scholar-compact-tx{display:flex;flex-direction:column;line-height:1.3;flex:1}'
      + '.scholar-compact-tx strong{color:var(--text,#fff);font-size:0.98rem}.scholar-compact-tx span{color:var(--text-muted,#94a3b8);font-size:0.85rem}'
      + '.scholar-compact-arr{color:var(--gold-bright,#FBBF24);flex:0 0 auto}';
    var st = document.createElement('style'); st.id = 'scholar-css'; st.textContent = css; document.head.appendChild(st);
  }

  /* The footer wordmark's scroll-linked parallax is GONE with the SVG it was
     written for. It keyed progress off the wordmark's OWN height — fine for a
     full-bleed 190px <text>, a snap for the 34px plain-text span that replaced
     it, and MEASURED at 390 it left the mark on `clip-path: inset(100%)` (i.e.
     entirely invisible) for every scroll position short of the very bottom.
     A reveal that spans 34px is a glitch, not a reveal; the mark is simply
     there now, which also drops a scroll listener and a `will-change:clip-path`
     layer from every page on the site. */

  // ============================================================
  // Nav pricing — hydrate the menu "from $X" chips from MAM_CONFIG.pricing so
  // they can never drift from the server-authoritative prices again. The static
  // markup carries a correct fallback; this refreshes it (and re-runs when the
  // live /api/config/public values arrive via the mam:config event).
  // ============================================================
  function initNavPricing() {
    var P = (window.MAM_CONFIG && window.MAM_CONFIG.pricing) || {};
    var CCY = window.MAM_CCY;
    $$('[data-price]').forEach(function (el) {
      var key = el.getAttribute('data-price');
      if (isFinite(parseFloat(key))) return; // numeric data-price is the currency engine's job
      var row = P[key];
      if (!row || typeof row.usd !== 'number') return;
      // Localise the chip to the visitor's currency (USD is the canonical unit).
      var money = CCY ? CCY.format(row.usd, CCY.detect()) : '$' + Math.round(row.usd);
      var txt = (el.hasAttribute('data-price-from') ? 'from ' : '') + money;
      // Replace only the leading text node so any <small> suffix is preserved.
      if (el.firstChild && el.firstChild.nodeType === 3) el.firstChild.nodeValue = txt;
      else el.insertBefore(document.createTextNode(txt), el.firstChild);
    });
  }
  document.addEventListener('mam:config', initNavPricing);

  // ============================================================
  // Visual graphics — inject music-themed decoration site-wide (no per-page
  // markup): an animated equalizer leading every section eyebrow, aurora depth
  // behind key sections, and a cursor-spotlight on cards.
  // ============================================================
  // Fine-pointer check — used by the card cursor-spotlight below.
  var mamWaveFine = !!(window.matchMedia && matchMedia('(pointer: fine)').matches);

  // Cursor "spotlight" on cards — a soft warm light glides under the pointer,
  // the premium feel top product sites use. Collision-free: a dedicated
  // pointer-events:none overlay child using mix-blend-mode:soft-light, so it
  // gently warms the card near the cursor WITHOUT obscuring any text. Fine
  // pointers only (coarse/touch + reduced-motion are handled in CSS).
  function initCardGlow() {
    if (!mamWaveFine) return;
    var sel = '.school-card,.mode-card,.faculty-card,.pathway-card,.unique-card,'
            + '.next-step-card,.choice-card,.struct-card,.extra-card,.testimonial-card,.method-step';
    $$(sel).forEach(function (card) {
      if (card.__mamGlow) return;
      card.__mamGlow = 1;
      card.classList.add('has-glow');
      var g = document.createElement('span');
      g.className = 'card-glow'; g.setAttribute('aria-hidden', 'true');
      card.appendChild(g);
      card.addEventListener('pointermove', function (e) {
        var r = card.getBoundingClientRect();
        if (!r.width || !r.height) return;
        card.style.setProperty('--gx', ((e.clientX - r.left) / r.width * 100).toFixed(1) + '%');
        card.style.setProperty('--gy', ((e.clientY - r.top) / r.height * 100).toFixed(1) + '%');
      });
    });
  }

  function initGraphics() {
    $$('.section-eyebrow').forEach(function (ey) {
      if (ey.querySelector('.eq')) return;
      var eq = document.createElement('span');
      eq.className = 'eq'; eq.setAttribute('aria-hidden', 'true');
      eq.innerHTML = '<i></i><i></i><i></i><i></i>';
      ey.insertBefore(eq, ey.firstChild);
    });
    ['how', 'modes', 'schools', 'showcase', 'pathways', 'faculty-preview'].forEach(function (id, i) {
      var s = document.getElementById(id); if (!s) return;
      s.classList.add('aurora');
      if (i % 2) s.classList.add('aurora-flip'); // mirror every other → flowing warmth
    });
    initCardGlow();
    // Perf: below-the-fold images load lazily (skip anything in the hero or
    // nav, which must paint immediately).
    var vh = window.innerHeight || 800;
    $$('img:not([loading])').forEach(function (img) {
      if (img.closest('#hero, #navbar, .page-hero, .mobile-menu')) return;
      if (img.getBoundingClientRect().top < vh * 1.5) return;
      img.setAttribute('loading', 'lazy');
      img.setAttribute('decoding', 'async');
    });
  }

  // ============================================================
  // Signed-in state in the nav
  // ============================================================
  // Reflects the real session site-wide: a cached paint (no flash) then an
  // async revalidation via MAM_AUTH. Signed-in swaps the "Sign in" ghost for an
  // account chip → the role's portal, plus a Sign-out button; the hamburger
  // node (already bound by initNav) is preserved, never re-created.
  function initAuthNav() {
    if (!window.MAM_AUTH) return;
    var cta = $('#navCta');
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
    function firstName(u) { return (((u && u.name) || '').trim().split(/\s+/)[0]) || 'Account'; }
    function avatarInner(u) {
      if (u && u.avatar_url) return '<img src="' + esc(u.avatar_url) + '" alt="">';
      var n = ((u && (u.name || u.email)) || '?').trim();
      return esc(n.charAt(0).toUpperCase() || '?');
    }
    injectAuthCss();
    var rendered = false;
    function renderSignedIn(st) {
      if (rendered || !cta || !st || !st.authenticated) return;
      rendered = true;
      var ham = cta.querySelector('#hamburgerBtn');           // keep the bound node
      cta.innerHTML = ''
        + '<a href="' + esc(st.portal) + '" class="btn-nav-account" title="Open your dashboard">'
        +   '<span class="nav-avatar">' + avatarInner(st.user) + '</span>'
        +   '<span class="nav-account-text"><span class="nav-account-name">' + esc(firstName(st.user)) + '</span>'
        +   '<span class="nav-account-role">' + esc(st.role || 'member') + '</span></span>'
        + '</a>'
        + '<button type="button" class="btn-nav-ghost btn-nav-logout" data-action="nav-logout" title="Sign out" aria-label="Sign out"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i></button>';
      if (ham) cta.appendChild(ham);
      var lo = cta.querySelector('[data-action="nav-logout"]');
      if (lo) on(lo, 'click', function () { lo.disabled = true; window.MAM_AUTH.logout().then(function () { location.href = '/'; }); });
      // Mobile quick tile: Sign in → Dashboard
      var tile = document.querySelector('[data-auth-tile]');
      if (tile) { tile.setAttribute('href', st.portal); tile.innerHTML = '<i class="fa-solid fa-gauge" aria-hidden="true"></i><span>Dashboard</span>'; }
    }
    var c = window.MAM_AUTH.cached();
    if (c && c.authenticated) renderSignedIn(c);
    window.MAM_AUTH.state().then(renderSignedIn).catch(function () {});
  }
  function injectAuthCss() {
    if (document.getElementById('mam-authnav-css')) return;
    var css = ''
      + '.btn-nav-account{display:inline-flex;align-items:center;gap:9px;padding:5px 14px 5px 6px;border-radius:100px;border:1px solid var(--border-gold,rgba(224,138,26,.45));background:linear-gradient(135deg,rgba(224,138,26,.14),rgba(251,191,36,.06));color:var(--text,#fff);text-decoration:none;font-weight:700;line-height:1;transition:transform .15s ease,box-shadow .15s ease}'
      + '.btn-nav-account:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(224,138,26,.28)}'
      + '.nav-avatar{width:30px;height:30px;border-radius:50%;flex:0 0 auto;display:grid;place-items:center;font-size:.82rem;font-weight:800;color:#1A120B;background:linear-gradient(135deg,#E8961F,#FBBF24);overflow:hidden}'
      + '.nav-avatar img{width:100%;height:100%;object-fit:cover}'
      + '.nav-account-text{display:flex;flex-direction:column;gap:1px}'
      + '.nav-account-name{font-size:.86rem}'
      + '.nav-account-role{font-size:.64rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.6}'
      + '.btn-nav-logout{padding:9px 12px}'
      + '@media (max-width:1024px){.btn-nav-account .nav-account-text{display:none}.btn-nav-account{padding:5px}}';
    var st = document.createElement('style'); st.id = 'mam-authnav-css'; st.textContent = css;
    document.head.appendChild(st);
  }

  // Floating "back to top" — these marketing pages get very long on mobile.
  // Injected here (not in the partials) so it's present site-wide and simply
  // doesn't exist when JS is off, where it'd be inert anyway.
  function initBackToTop() {
    if (document.querySelector('.to-top')) return;
    // Touch keeps one bottom-docked surface at a time (see initNav). This FAB
    // was never in the dock — fixed at z-index 901 against the dock's 950 — so
    // it overlapped whatever was there and was painted over. It returns on a
    // pointer-fine desktop, where there is room in the corner for one.
    if (window.matchMedia && window.matchMedia('(max-width: 1024px), (pointer: coarse)').matches) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'to-top';
    btn.setAttribute('aria-label', 'Back to top');
    btn.innerHTML = '<i class="fa-solid fa-arrow-up" aria-hidden="true"></i>';
    document.body.appendChild(btn);
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var shown = false, ticking = false;
    // The bottom-left corner is also home to the (transient) cookie-consent and
    // lead-CTA bars. Rather than fight them for space, yield: stay hidden while
    // one occupies the corner, and reappear once it's dismissed. The right
    // gutter is left to the floating "Apply Now" button.
    function cornerBlocked() {
      var vh = window.innerHeight || 800, blocked = false;
      ['.cookie-banner.shown', '.mam-cta-bar.is-in'].forEach(function (sel) {
        var el = document.querySelector(sel); if (!el) return;
        var r = el.getBoundingClientRect();
        if (r.height && r.bottom > vh - 40 && r.left < 110) blocked = true;
      });
      return blocked;
    }
    function update() {
      ticking = false;
      var y = window.pageYOffset || document.documentElement.scrollTop || 0;
      var want = y > (window.innerHeight || 800) * 1.2 && !cornerBlocked();
      if (want !== shown) { shown = want; btn.classList.toggle('show', want); btn.setAttribute('aria-hidden', want ? 'false' : 'true'); }
    }
    function ping() { if (ticking) return; ticking = true; setTimeout(update, 120); }
    window.addEventListener('scroll', ping, { passive: true });
    window.addEventListener('resize', ping, { passive: true });
    // Dismissing the consent / CTA bar frees the corner — re-evaluate after any click.
    document.addEventListener('click', function () { setTimeout(update, 80); }, true);
    setTimeout(update, 1400); // re-check once the consent/CTA bars have appeared (~1.2s)
    on(btn, 'click', function () {
      window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
      // Return focus to the top of the document so keyboard users aren't stranded.
      var top = document.querySelector('.skip-link') || document.querySelector('#navbar a, #navbar button');
      if (top) { try { top.focus({ preventScroll: true }); } catch (e) { top.focus(); } }
    });
    update();
  }

  // Mark the current page in the nav (aria-current + the pre-existing `.active`
  // style hook) so visitors always know where they are. Matches by pathname,
  // tolerant of clean URLs (/pathways) vs .php and of trailing slashes.
  function initActiveNav() {
    function norm(href) {
      if (!href) return null;
      var h = String(href).trim();
      if (!h || h === '#' || h.charAt(0) === '#') return null;   // fragment / non-navigational
      var a = document.createElement('a'); a.href = h;
      if (a.host && a.host !== location.host) return null;       // external link
      var p = a.pathname.replace(/\/+$/, '').replace(/\.php$/, '').toLowerCase();
      return p === '' ? '/' : p;
    }
    var here = norm(location.pathname) || '/';
    var matches = function (a) { var p = norm(a.getAttribute('href')); return !!p && p === here; };

    // aria-current="page" on every link that LITERALLY points here (valid for
    // repeated links and correct for screen readers).
    $$('#navbar a.nav-link, #navbar a.dropdown-item, .mobile-menu a[href]')
      .forEach(function (a) { if (matches(a)) a.setAttribute('aria-current', 'page'); });

    // The underline is purely visual → show it on exactly ONE top-level item:
    // a direct match if there is one, else the category that owns the matching
    // dropdown entry (e.g. Pathways while on /crash). This avoids two links
    // (e.g. Schools + Disciplines, both → /schools) underlining at once.
    var top = $$('#navbar a.nav-link').filter(matches)[0];
    if (!top) {
      var di = $$('#navbar a.dropdown-item').filter(matches)[0];
      var item = di && di.closest('.nav-item');
      top = item && item.querySelector('a.nav-link');
    }
    if (top) top.classList.add('active');
  }

  // Site-wide promo bar: a live PUBLIC promo (banner + live countdown), fetched
  // from the authoritative API. Self-contained, CSP-safe (no external deps),
  // dismissible per session. The server enforces the actual discount at checkout;
  // this only announces it.
  function initPromoBanner() {
    var base = (window.MAM_CONFIG && MAM_CONFIG.api && MAM_CONFIG.api.base) || '/api';
    fetch(base + '/promo/active', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok || !j.active || !j.promo) return;
        var p = j.promo;
        var key = 'mam.promo.dismissed', sig = (p.title || p.body || '1');
        try { if (sessionStorage.getItem(key) === sig) return; } catch (e) {}
        var endTs = p.ends_at ? new Date(p.ends_at).getTime() : 0;
        if (endTs && endTs <= Date.now()) return; // already expired

        // One-time structural CSS (inline styles carry the admin-set colour).
        if (!document.getElementById('mam-promo-css')) {
          var st = document.createElement('style'); st.id = 'mam-promo-css';
          st.textContent =
            '.mam-promo-bar{position:relative;z-index:60;display:flex;align-items:center;gap:12px;'
            + 'padding:9px 16px;color:#231a12;font-family:var(--font-body,Inter,sans-serif);'
            + 'font-size:.86rem;line-height:1.35;border-bottom:1px solid rgba(0,0,0,.14)}'
            + '.mam-promo-inner{flex:1;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;'
            + 'gap:4px 10px;text-align:center;max-width:1180px;margin:0 auto}'
            + '.mam-promo-msg{font-weight:600}'
            + '.mam-promo-count{font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap}'
            + '.mam-promo-cta{background:#231a12;color:#fff;text-decoration:none;padding:5px 14px;'
            + 'border-radius:100px;font-weight:700;font-size:.8rem;white-space:nowrap}'
            + '.mam-promo-cta:hover{opacity:.9}'
            + '.mam-promo-x{flex-shrink:0;background:transparent;border:0;color:#231a12;font-size:1.3rem;'
            + 'line-height:1;cursor:pointer;padding:0 4px;opacity:.65}.mam-promo-x:hover{opacity:1}';
          document.head.appendChild(st);
        }

        var bar = document.createElement('div');
        bar.className = 'mam-promo-bar';
        bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', 'Promotion');
        bar.style.background = /^#[0-9A-Fa-f]{6}$/.test(p.bg_color || '') ? p.bg_color : '#FEF3C7';

        var inner = document.createElement('div'); inner.className = 'mam-promo-inner';
        var msg = document.createElement('span'); msg.className = 'mam-promo-msg';
        msg.textContent = (p.discount_percent > 0 ? p.discount_percent + '% off — ' : '') + (p.body || '');
        var count = document.createElement('span'); count.className = 'mam-promo-count';
        inner.appendChild(msg); inner.appendChild(count);
        if (p.cta_label && p.cta_url) {
          var a = document.createElement('a'); a.className = 'mam-promo-cta';
          a.href = p.cta_url; a.textContent = p.cta_label;
          inner.appendChild(a);
        }
        var close = document.createElement('button');
        close.className = 'mam-promo-x'; close.type = 'button';
        close.setAttribute('aria-label', 'Dismiss offer'); close.textContent = '×';
        var iv;
        function dismiss() { bar.remove(); if (iv) clearInterval(iv); }
        close.addEventListener('click', function () {
          dismiss();
          try { sessionStorage.setItem(key, sig); } catch (e) {}
        });
        bar.appendChild(inner); bar.appendChild(close);
        document.body.insertBefore(bar, document.body.firstChild);

        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function tick() {
          if (!endTs) { count.textContent = ''; return; }
          var d = endTs - Date.now();
          if (d <= 0) { dismiss(); return; }
          var h = Math.floor(d / 3.6e6), m = Math.floor((d % 3.6e6) / 6e4), s = Math.floor((d % 6e4) / 1000);
          count.textContent = '· ends in ' + (h > 0 ? h + 'h ' : '') + pad(m) + 'm ' + pad(s) + 's';
        }
        tick();
        if (endTs) iv = setInterval(tick, 1000);
      })
      .catch(function () { /* promo bar is best-effort; never block the page */ });
  }

  function boot() {
    // Each init is isolated: a throw in one (e.g. the nav) must never stop the
    // others from running — otherwise a single failure could, for example,
    // leave the scroll-reveal section headers stuck at opacity:0.
    [initPreloader, initNav, initAuthNav, initActiveNav, initHero, initSmoothScroll, initFaq, initAnimations,
     initCountdown, initTestimonials, initAnnounce, initCookie, initToolbar, initCurrency, initPromoBanner,
     initNewsletter, initFilters, initImageFallback, initYear, initScholarship,
     initFooterGroups, initNavPricing, initGraphics, initBackToTop].forEach(function (fn) {
      try { fn(); } catch (e) { if (window.console) console.error('[MAM] init failed:', fn.name, e); }
    });

    // Failsafe: any scroll-reveal element that is already in the viewport must
    // be visible. If the IntersectionObserver never armed (an init threw) or
    // misfired, this un-sticks on-screen content (section headers etc.) without
    // touching below-the-fold elements, so their scroll animation is preserved.
    function revealInView() {
      var vh = window.innerHeight || document.documentElement.clientHeight;
      $$('.fade-in-up:not(.visible), .fade-in:not(.visible)').forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.top < vh && r.bottom > 0) el.classList.add('visible');
      });
    }
    setTimeout(revealInView, 1200);
    on(window, 'load', function () { setTimeout(revealInView, 200); });

    // Route all <img> and hero backgrounds through Cloudinary fetch once
    // partials are in place. Safe no-op until MAM_CONFIG.cdn.cloudName is set.
    if (window.MAM_CDN && typeof window.MAM_CDN.rewriteAll === 'function') {
      try { window.MAM_CDN.rewriteAll(document); } catch (e) {}
    }

    if (window.console && console.log) {
      console.log('%c🎵 MAM Academy', 'background:linear-gradient(135deg,#E08A1A,#FBBF24);color:#120D08;padding:12px 24px;font-size:18px;font-weight:900;border-radius:8px;letter-spacing:2px');
      console.log('%cA Creative Intelligence & Performance Academy', 'color:#FBBF24;font-size:13px;font-weight:600');
    }

    document.dispatchEvent(new CustomEvent('mam:ready'));

    // Lazy-load the motion choreography module after the rest of
    // the page is wired up. Failure here is non-fatal — site works
    // with CSS fades only.
    var existing = document.querySelector('script[data-mam-motion]');
    if (!existing) {
      var basePath = (function () {
        var src = (document.currentScript && document.currentScript.getAttribute('src')) || '';
        // Find the path prefix for main.js so motion.js loads from the same folder.
        var idx = src.indexOf('main.js');
        if (idx >= 0) return src.slice(0, idx);
        // Fallback: look for any script tag whose src ends with /main.js
        var ss = document.querySelectorAll('script[src]');
        for (var i = 0; i < ss.length; i++) {
          var s = ss[i].getAttribute('src') || '';
          var j = s.indexOf('main.js');
          if (j >= 0) return s.slice(0, j);
        }
        return 'assets/js/';
      })();
      var m = document.createElement('script');
      m.src = basePath + 'motion.js';
      m.async = true;
      m.dataset.mamMotion = '1';
      document.head.appendChild(m);

      // Progressive third-party libraries (jQuery, GSAP, Swiper, Splide,
      // SplitType, WebFont) — all optional & guarded inside libs.js.
      if (!document.querySelector('script[data-mam-libs]')) {
        var lib = document.createElement('script');
        lib.src = basePath + 'libs.js';
        lib.async = true;
        lib.dataset.mamLibs = '1';
        document.head.appendChild(lib);
      }

      // Make sure the motion CSS is loaded alongside the JS module.
      if (!document.querySelector('link[data-mam-motion-css]')) {
        var cssPath = basePath.replace(/js\/?$/, '') + 'css/motion.css';
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = cssPath;
        l.dataset.mamMotionCss = '1';
        document.head.appendChild(l);
      }

    }
  }

  // ---- Sitewide structured-data graph ---------------------------------
  // Every non-home page emits a linked JSON-LD @graph: a typed WebPage node
  // (AboutPage / ContactPage / CollectionPage / FAQPage / WebPage by slug),
  // a BreadcrumbList, and compact Organization + WebSite nodes carrying the
  // SAME @ids the homepage declares — so the whole site resolves to one
  // Organization/WebSite entity. The homepage keeps its own fuller markup, so
  // it's skipped here. One place updates structured data across all pages.
  function injectSiteSchema() {
    try {
      var canon = document.querySelector('link[rel="canonical"]');
      var here = (canon ? canon.href : location.href).split('#')[0].split('?')[0];
      var origin = (function () { try { return new URL(here).origin; } catch (e) { return location.origin; } })();
      var slug = location.pathname.replace(/^\/+|\/+$/g, '').replace(/\.html$/i, '').toLowerCase();
      if (!slug || slug === 'index') return; // home carries its own graph

      var cfg = window.MAM_CONFIG || {};
      var siteName = (cfg.site && cfg.site.name) || 'MAM Academy';
      var orgId = origin + '/#organization', siteId = origin + '/#website';
      var NAMES = {
        about: 'About', schools: 'Schools', pathways: 'Pathways', compare: 'Compare',
        faculty: 'Faculty', crash: 'Crash Programmes', 'metamusic-order': 'The MetaMusic Order',
        'power-play': 'Power Play', auditer: 'MAM Auditer', faq: 'FAQ', studio: 'Studio',
        tools: 'Tools', store: 'Store', contact: 'Contact', privacy: 'Privacy',
        terms: 'Terms', policies: 'Policies', apply: 'Apply', book: 'Book a Session', 'thank-you': 'Thank You'
      };
      var TYPE = {
        about: 'AboutPage', contact: 'ContactPage',
        schools: 'CollectionPage', pathways: 'CollectionPage', compare: 'CollectionPage',
        store: 'CollectionPage', faculty: 'CollectionPage'
        // faq → plain WebPage here; a full FAQPage is emitted by the FAQ rotator
      };
      var name = NAMES[slug] || slug.replace(/[-_]/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
      var title = (document.title || siteName).replace(/\s*[—|].*$/, '').trim() || (name + ' — ' + siteName);

      var webpage = {
        '@type': TYPE[slug] || 'WebPage', '@id': here + '#webpage', url: here, name: title,
        isPartOf: { '@id': siteId }, about: { '@id': orgId }, breadcrumb: { '@id': here + '#breadcrumb' }, inLanguage: 'en'
      };
      var desc = document.querySelector('meta[name="description"]');
      if (desc && desc.content) webpage.description = desc.content;

      var graph = [
        { '@type': 'Organization', '@id': orgId, name: siteName, url: origin + '/', logo: origin + '/mam-academy-logo.svg' },
        { '@type': 'WebSite', '@id': siteId, url: origin + '/', name: siteName, publisher: { '@id': orgId } },
        webpage,
        {
          '@type': 'BreadcrumbList', '@id': here + '#breadcrumb',
          itemListElement: [
            { '@type': 'ListItem', position: 1, name: 'Home', item: origin + '/' },
            { '@type': 'ListItem', position: 2, name: name, item: here }
          ]
        }
      ];
      var s = document.createElement('script');
      s.type = 'application/ld+json';
      s.textContent = JSON.stringify({ '@context': 'https://schema.org', '@graph': graph });
      document.head.appendChild(s);
    } catch (e) { /* structured data is best-effort; never block the page */ }
  }

  // Wait for partials to load before binding nav/hero events
  document.addEventListener('DOMContentLoaded', function () {
    injectSiteSchema();
    Promise.all([
      loadPartial('include-header', 'partials/header.html?v=20260617b'),
      loadPartial('include-footer', 'partials/footer.html?v=20260617b')
    ]).then(boot);
  });
})();
