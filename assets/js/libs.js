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
    splittext:'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/SplitText.min.js',
    splittype:'https://cdn.jsdelivr.net/npm/split-type@0.3.4/umd/index.min.js',
    swiperJs: 'https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.js',
    swiperCss:'https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.css',
    splideJs: 'https://cdn.jsdelivr.net/npm/@splidejs/splide@4/dist/js/splide.min.js',
    splideCss:'https://cdn.jsdelivr.net/npm/@splidejs/splide@4/dist/css/splide.min.css',
    webfont:  'https://cdn.jsdelivr.net/npm/webfontloader@1.6.28/webfontloader.js'
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

    // Heading word-rise using SplitText (or SplitType fallback).
    document.querySelectorAll('.section-title, .page-hero-title').forEach(function (el) {
      if (el.dataset.gsapDone) return; el.dataset.gsapDone = '1';
      var targets = null;
      try {
        if (window.SplitText) targets = new window.SplitText(el, { type: 'words' }).words;
        else if (window.SplitType) targets = new window.SplitType(el, { types: 'words' }).words;
      } catch (e) { targets = null; }
      if (!targets || !targets.length) targets = [el];
      gsap.from(targets, {
        yPercent: 60, opacity: 0, duration: 0.7, ease: 'power3.out', stagger: 0.04,
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

  function boot() {
    loadJs(CDN.webfont).then(initWebFont);
    loadJs(CDN.jquery); // available globally for any jQuery-based snippet

    loadCss(CDN.swiperCss); loadCss(CDN.splideCss);

    // GSAP core then plugins, then run reveals.
    loadJs(CDN.gsap).then(function (ok) {
      if (!ok) return;
      Promise.all([loadJs(CDN.strigger), loadJs(CDN.splittext), loadJs(CDN.splittype)]).then(initGsap);
    });

    Promise.all([loadJs(CDN.swiperJs), loadJs(CDN.splideJs)]).then(initCarousels);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  // Re-scan for carousels after async partials/content settle.
  window.addEventListener('load', function () { setTimeout(initCarousels, 400); });
})();
