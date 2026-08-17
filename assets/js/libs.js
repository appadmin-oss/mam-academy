/* ============================================================
   MAM Academy — Third-party library integration (progressive)
   Loads jQuery, GSAP (+ScrollTrigger +SplitText), SplitType,
   Swiper 8, Splide and WebFont. Everything is OPTIONAL: each
   enhancement is guarded, so a blocked CDN or CSP never breaks
   the site — it simply falls back to the base experience.
   ============================================================ */
(function () {
  'use strict';
  var RM = matchMedia('(prefers-reduced-motion: reduce)').matches;

  var CDN = {
    jquery:   'https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js',
    gsap:     'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js',
    strigger: 'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js',
    // SplitText is a Club GreenSock paid plugin — it 404s on the npm CDN.
    // We use the free SplitType library below to achieve the same effect,
    // and the loader (Promise.all) skips the splittext slot below.
    splittype:'https://cdn.jsdelivr.net/npm/split-type@0.3.4/umd/index.min.js',
    swiperJs: 'https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.js',
    swiperCss:'https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.css',
    splideJs: 'https://cdn.jsdelivr.net/npm/@splidejs/splide@4/dist/js/splide.min.js',
    splideCss:'https://cdn.jsdelivr.net/npm/@splidejs/splide@4/dist/css/splide.min.css',
    webfont:  'https://cdn.jsdelivr.net/npm/webfontloader@1.6.28/webfontloader.js',
    swal2Js:  'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js',
    swal2Css: 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css',
    tomJs:    'https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js',
    tomCss:   'https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css',
    aosJs:    'https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js',
    aosCss:   'https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css',
    // just-validate@4.5.0 was 404 on jsDelivr — the highest published v4 is
    // 4.3.0. Pin to that. If you upgrade, verify the published version
    // exists with `npm view just-validate versions` first.
    justValidate: 'https://cdn.jsdelivr.net/npm/just-validate@4.3.0/dist/just-validate.production.min.js'
  };

  function loadJs(src) {
    return new Promise(function (res) {
      if (document.querySelector('script[src="' + src + '"]')) return res(true);
      var s = document.createElement('script');
      s.src = src; s.async = true;
      s.onload = function () { res(true); };
      s.onerror = function () { res(false); };
      document.head.appendChild(s);
    });
  }
  function loadCss(href) {
    if (document.querySelector('link[href="' + href + '"]')) return;
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l);
  }

  /* ---- WebFont: keep FOUT-free font loading; <link> remains the fallback ---- */
  function initWebFont() {
    if (!window.WebFont) return;
    try {
      window.WebFont.load({
        google: { families: ['Bebas Neue', 'Cormorant Garamond:400,600,700', 'Inter:300,400,500,600,700,800'] },
        classes: true, events: false,
        active: function () { document.documentElement.classList.add('fonts-ready'); }
      });
    } catch (e) {}
  }

  /* ---- GSAP: gentle, musical scroll reveals on headings/cards ---- */
  function initGsap() {
    if (RM || !window.gsap) return;
    var gsap = window.gsap;
    if (window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);

    // Heading word-rise using SplitType. CRITICAL: skip headings that
    // contain a gradient span (.highlight-gold). Splitting them into word
    // spans makes the words inherit -webkit-text-fill-color:transparent
    // from the parent but NOT the background-image — result is fully
    // transparent text with no gradient to clip to. The whole heading
    // still fades in as one unit, so the visual still reads as animated.
    document.querySelectorAll('.section-title, .page-hero-title').forEach(function (el) {
      if (el.dataset.gsapDone) return; el.dataset.gsapDone = '1';
      var hasGradient = el.querySelector('.highlight-gold');
      var targets = null;
      if (!hasGradient) {
        try {
          if (window.SplitType) targets = new window.SplitType(el, { types: 'words' }).words;
        } catch (e) { targets = null; }
      }
      if (!targets || !targets.length) targets = [el];
      gsap.from(targets, {
        yPercent: hasGradient ? 0 : 60,
        y: hasGradient ? 24 : 0,
        opacity: 0, duration: 0.7, ease: 'power3.out', stagger: 0.04,
        scrollTrigger: window.ScrollTrigger ? { trigger: el, start: 'top 85%', once: true } : undefined
      });
    });

    // Soft rise for content cards (only where main.js fade-in isn't already used).
    if (window.ScrollTrigger) {
      document.querySelectorAll('.unique-card, .school-card, .program-card, .pathway-card, .faculty-card, .team-card')
        .forEach(function (el) {
          gsap.from(el, { y: 28, opacity: 0, duration: 0.6, ease: 'power2.out',
            scrollTrigger: { trigger: el, start: 'top 90%', once: true } });
        });
    }
  }

  /* ---- Swiper / Splide: upgrade any opted-in carousels (no-op if none) ---- */
  function initCarousels() {
    if (window.Swiper) {
      document.querySelectorAll('.swiper:not(.swiper-initialized)').forEach(function (el) {
        try { new window.Swiper(el, { slidesPerView: 'auto', spaceBetween: 20, grabCursor: true, freeMode: true }); } catch (e) {}
      });
    }
    if (window.Splide) {
      document.querySelectorAll('.splide:not(.is-initialized)').forEach(function (el) {
        try { new window.Splide(el, { perPage: 1, gap: '1rem', arrows: true, pagination: true }).mount(); } catch (e) {}
      });
    }
  }

  /* ---- AOS: scroll-triggered fades / rises ----
     Auto-tags a curated set of homepage/section elements so we get a
     consistent reveal cadence sitewide without editing every HTML file.
     Anything already carrying data-aos is left untouched. Cards within a
     grid get a stagger via data-aos-delay. */
  function autoTagAos() {
    // Section intros rise first.
    document.querySelectorAll('.section-eyebrow, .section-title, .section-desc, .testimonials-head, .page-hero-inner').forEach(function (el) {
      if (!el.hasAttribute('data-aos')) { el.setAttribute('data-aos', 'fade-up'); el.setAttribute('data-aos-duration', '650'); }
    });
    // Card grids: stagger children for a gentle cascade.
    var groups = ['#how', '#modes', '#schools', '#faculty-preview', '#stats', '#about'];
    groups.forEach(function (sel) {
      var scope = document.querySelector(sel); if (!scope) return;
      var cards = scope.querySelectorAll('.method-step, .mode-card, .school-card, .team-card, .stat-card, .stat, .extra-card, .next-step-card, .about-card');
      Array.prototype.forEach.call(cards, function (el, i) {
        if (el.hasAttribute('data-aos')) return;
        el.setAttribute('data-aos', 'fade-up');
        el.setAttribute('data-aos-delay', String((i % 4) * 90));
        el.setAttribute('data-aos-duration', '650');
      });
    });
  }
  function initAos() {
    if (RM || !window.AOS) return;
    try {
      autoTagAos();
      window.AOS.init({ duration: 700, easing: 'ease-out-quart', once: true, offset: 60, disableMutationObserver: false });
      // Partials/content injected after first paint — refresh so late nodes animate.
      document.addEventListener('mam:ready', function () { try { autoTagAos(); window.AOS.refreshHard(); } catch (e) {} });
    } catch (e) {}
  }

  /* ---- Tom Select: enhance every <select data-enhance="select"> ---- */
  function initTomSelect() {
    if (!window.TomSelect) return;
    document.querySelectorAll('select[data-enhance="select"]').forEach(function (sel) {
      if (sel.tomselect) return;
      try {
        new window.TomSelect(sel, {
          allowEmptyOption: true,
          maxOptions: 100,
          placeholder: sel.querySelector('option[value=""]') ? sel.querySelector('option[value=""]').textContent : 'Select…'
        });
      } catch (e) {}
    });
  }

  /* ---- Self-hosted toast, for when SweetAlert2 does not arrive ----------
     MEASURED: with the CDN blocked, MAM_DIALOG.toast() wrote to console.log
     and nothing else. `alert` and `confirm` degrade to native dialogs, which
     are at least visible; `toast` degraded to somewhere no user ever looks.
     That mattered because toast IS the instructor portal's entire error
     vocabulary — "Could not record: …", "Could not save attendance.",
     "Could not update the mark." — so on any network that blocks the CDN
     (ad-blocker, corporate proxy, a tightened CSP, a bad minute) an instructor
     recording a mark saw the form reset and reasonably concluded it saved.
     It had not.

     This is deliberately dependency-free: styles injected once, no external
     font or icon, so it cannot fail the same way the thing it replaces did. */
  var TOAST_CSS_ID = 'mam-toast-css';
  function toastStyles() {
    if (document.getElementById(TOAST_CSS_ID)) return;
    var el = document.createElement('style');
    el.id = TOAST_CSS_ID;
    el.textContent =
      '#mam-toasts{position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;' +
      'gap:8px;max-width:min(380px,calc(100vw - 32px));pointer-events:none}' +
      '.mam-toast{pointer-events:auto;display:flex;align-items:flex-start;gap:10px;padding:12px 14px;' +
      'border-radius:12px;font:500 0.88rem/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;' +
      'background:#1A120B;color:#FBF7F3;border:1px solid #856520;box-shadow:0 10px 30px rgba(0,0,0,.45);' +
      'animation:mam-toast-in .18s ease-out}' +
      // Borders are the tone signal, so they clear WCAG 1.4.11's 3:1 against
      // the panel they sit on — the same rule the apply banner follows.
      '.mam-toast.err{border-color:#F43F5E;background:#2A1218;color:#FECDD3}' +
      '.mam-toast.warn{border-color:#E8961F;background:#241A0E;color:#FDE7C7}' +
      '.mam-toast.ok{border-color:#22C55E;background:#0F2116;color:#BBF7D0}' +
      '.mam-toast-x{margin-left:auto;flex-shrink:0;background:none;border:0;color:inherit;opacity:.65;' +
      'cursor:pointer;font-size:1.05rem;line-height:1;padding:2px 4px;min-width:24px;min-height:24px}' +
      '.mam-toast-x:hover,.mam-toast-x:focus-visible{opacity:1}' +
      '@keyframes mam-toast-in{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}' +
      '@media (prefers-reduced-motion:reduce){.mam-toast{animation:none}}';
    document.head.appendChild(el);
  }
  function fallbackToast(message, icon) {
    try {
      toastStyles();
      var host = document.getElementById('mam-toasts');
      if (!host) {
        host = document.createElement('div');
        host.id = 'mam-toasts';
        document.body.appendChild(host);
      }
      var bad  = (icon === 'error' || icon === 'warning');
      var t = document.createElement('div');
      t.className = 'mam-toast ' + (icon === 'error' ? 'err' : icon === 'warning' ? 'warn' : 'ok');
      // A failure INTERRUPTS; a success is announced politely.
      t.setAttribute('role', bad ? 'alert' : 'status');
      var txt = document.createElement('span');
      txt.textContent = String(message == null ? '' : message);  // callers pass server text
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'mam-toast-x'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
      var close = function () { if (t.parentNode) t.parentNode.removeChild(t); };
      x.addEventListener('click', close);
      t.appendChild(txt); t.appendChild(x);
      host.appendChild(t);
      // An error the reader misses is the whole problem, so it lingers longer
      // than a confirmation and can always be dismissed by hand.
      setTimeout(close, bad ? 9000 : 4000);
    } catch (e) {
      try { window.alert(message); } catch (e2) {}
    }
  }

  /* ---- MAM_DIALOG: branded confirm/alert/toast backed by SweetAlert2 ----
     Falls back to native confirm()/alert() if the library can't load, so
     the user is never stuck. Theme matches the site's gold-on-dark. */
  function makeDialog() {
    var swal = window.Swal;
    var theme = {
      background: '#1A120B',
      color: '#FBF7F3',
      confirmButtonColor: '#E08A1A',
      cancelButtonColor:  '#3A2A1A',
      iconColor: '#FBBF24',
      customClass: {
        popup: 'mam-swal',
        confirmButton: 'mam-swal-confirm',
        cancelButton:  'mam-swal-cancel',
        title: 'mam-swal-title'
      }
    };
    function merge(a, b) { for (var k in b) a[k] = b[k]; return a; }
    return {
      alert: function (title, text, icon) {
        if (!swal) return Promise.resolve(window.alert(title + (text ? '\n' + text : '')));
        return swal.fire(merge({ title: title, text: text || '', icon: icon || 'info' }, theme));
      },
      confirm: function (title, text, confirmLabel) {
        if (!swal) return Promise.resolve(window.confirm(title + (text ? '\n' + text : '')));
        return swal.fire(merge({
          title: title, text: text || '', icon: 'question',
          showCancelButton: true, confirmButtonText: confirmLabel || 'Yes',
          cancelButtonText: 'Cancel', reverseButtons: true
        }, theme)).then(function (r) { return !!r.isConfirmed; });
      },
      toast: function (message, icon) {
        if (!swal) { fallbackToast(message, icon); return; }
        swal.fire(merge({
          toast: true, position: 'top-end', timer: 3500, showConfirmButton: false,
          title: message, icon: icon || 'success'
        }, theme));
      },
      prompt: function (title, placeholder, inputType) {
        if (!swal) return Promise.resolve(window.prompt(title));
        return swal.fire(merge({
          title: title, input: inputType || 'text', inputPlaceholder: placeholder || '',
          showCancelButton: true, confirmButtonText: 'Submit', cancelButtonText: 'Cancel'
        }, theme)).then(function (r) { return r.isConfirmed ? r.value : null; });
      }
    };
  }

  function boot() {
    loadJs(CDN.webfont).then(initWebFont);
    loadJs(CDN.jquery); // available globally for any jQuery-based snippet

    loadCss(CDN.swiperCss); loadCss(CDN.splideCss);
    loadCss(CDN.swal2Css);  loadCss(CDN.tomCss);  loadCss(CDN.aosCss);

    // GSAP core then plugins, then run reveals.
    loadJs(CDN.gsap).then(function (ok) {
      if (!ok) return;
      Promise.all([loadJs(CDN.strigger), loadJs(CDN.splittype)]).then(initGsap);
    });

    Promise.all([loadJs(CDN.swiperJs), loadJs(CDN.splideJs)]).then(initCarousels);

    // SweetAlert2 → MAM_DIALOG. Available before/after load: degrades to
    // native alert/confirm if the script never arrives.
    window.MAM_DIALOG = makeDialog();
    loadJs(CDN.swal2Js).then(function (ok) { if (ok) window.MAM_DIALOG = makeDialog(); });

    // Tom Select runs after partials settle so dropdowns added later get covered.
    loadJs(CDN.tomJs).then(function (ok) { if (ok) initTomSelect(); });
    document.addEventListener('mam:ready', function () { initTomSelect(); });

    loadJs(CDN.aosJs).then(initAos);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  // Re-scan for carousels after async partials/content settle.
  window.addEventListener('load', function () { setTimeout(initCarousels, 400); });
})();
