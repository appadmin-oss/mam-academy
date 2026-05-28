/* ============================================================
   MAM Academy — Shared site JS
   Nav, hero carousel, scroll spy, FAQ, animations, cookie, etc.
   ============================================================ */
(function () {
  'use strict';

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

    on(window, 'scroll', function () {
      var y = window.scrollY;
      if (navbar) navbar.classList.toggle('scrolled', y > 16);
      if (floatingEnroll) {
        var hero = $('#hero, .page-hero');
        var threshold = hero ? hero.offsetHeight * 0.6 : 400;
        var visible = y > threshold;
        floatingEnroll.classList.toggle('visible', visible);
        floatingEnroll.setAttribute('aria-hidden', !visible);
      }
    }, { passive: true });

    var menuOpen = false;
    var menuOpenerEl = null;
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
    $$('#mobileMenu a').forEach(function (a) { on(a, 'click', function () { setMenu(false); }); });
    on(document, 'keydown', function (e) { if (e.key === 'Escape' && menuOpen) setMenu(false); });

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
    // appearing/changing text is announced.
    $$('.field-error, .form-error').forEach(function (el) {
      if (!el.getAttribute('role')) el.setAttribute('role', 'alert');
      if (!el.getAttribute('aria-live')) el.setAttribute('aria-live', 'polite');
    });

    $$('[data-mobile-toggle]').forEach(function (btn) {
      on(btn, 'click', function () {
        var key = btn.getAttribute('data-mobile-toggle');
        var sub = document.getElementById('m-' + key);
        var open = sub && sub.classList.contains('open');
        $$('[data-mobile-toggle]').forEach(function (b) { b.classList.remove('open'); });
        $$('.mobile-nav-sub').forEach(function (s) { s.classList.remove('open'); });
        if (!open && sub) { btn.classList.add('open'); sub.classList.add('open'); }
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

    // Highlight current page in nav
    var path = location.pathname.split('/').pop() || 'index.html';
    $$('.nav-link, .mobile-nav-link, .mobile-nav-sub-link').forEach(function (a) {
      var href = a.getAttribute('href');
      if (!href) return;
      var hrefPath = href.split('/').pop().split('#')[0];
      if (hrefPath && hrefPath === path) a.classList.add('active');
    });
  }

  // ============================================================
  // Hero carousel
  // ============================================================
  function initHero() {
    var slides = $$('.hero-slide');
    var dots = $$('.hero-dot');
    if (!slides.length) return;
    var current = 0, timer = null;
    function go(i) {
      slides[current].classList.remove('active');
      if (dots[current]) { dots[current].classList.remove('active'); dots[current].setAttribute('aria-selected', 'false'); }
      current = (i + slides.length) % slides.length;
      slides[current].classList.add('active');
      if (dots[current]) { dots[current].classList.add('active'); dots[current].setAttribute('aria-selected', 'true'); }
    }
    function next() { go(current + 1); }
    function prev() { go(current - 1); }
    function start() { stop(); timer = setInterval(next, 6500); }
    function stop()  { if (timer) { clearInterval(timer); timer = null; } }
    on($('#nextArrow'), 'click', function () { next(); start(); });
    on($('#prevArrow'), 'click', function () { prev(); start(); });
    dots.forEach(function (d) { on(d, 'click', function () { go(parseInt(d.getAttribute('data-dot'), 10)); start(); }); });
    var hero = $('#hero'); if (hero) {
      var tx0 = 0, ty0 = 0;
      on(hero, 'touchstart', function (e) { tx0 = e.changedTouches[0].clientX; ty0 = e.changedTouches[0].clientY; }, { passive: true });
      on(hero, 'touchend', function (e) {
        var dx = e.changedTouches[0].clientX - tx0;
        var dy = e.changedTouches[0].clientY - ty0;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) { dx < 0 ? next() : prev(); start(); }
      }, { passive: true });
      on(hero, 'mouseenter', stop);
      on(hero, 'mouseleave', start);
    }
    on(document, 'visibilitychange', function () { document.hidden ? stop() : start(); });
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
        var target = document.querySelector(href);
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
    var d = $('#cd-days'), h = $('#cd-hours'), m = $('#cd-mins'), s = $('#cd-secs');
    if (!d) return;
    var cfg = window.MAM_CONFIG && window.MAM_CONFIG.intake ? window.MAM_CONFIG.intake.startDate : '2026-05-05T00:00:00Z';
    var target = new Date(cfg).getTime();
    function pad(n) { return String(n).padStart(2, '0'); }
    function tick() {
      var diff = target - Date.now();
      if (diff <= 0) { d.textContent = h.textContent = m.textContent = s.textContent = '00'; return; }
      d.textContent = pad(Math.floor(diff / 86400000));
      h.textContent = pad(Math.floor((diff % 86400000) / 3600000));
      m.textContent = pad(Math.floor((diff % 3600000) / 60000));
      s.textContent = pad(Math.floor((diff % 60000) / 1000));
    }
    tick(); setInterval(tick, 1000);
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
  function initCookie() {
    var banner = $('#cookieBanner'); if (!banner) return;
    if (Storage.getItem('mam.cookie')) return;
    setTimeout(function () { banner.classList.add('shown'); }, 1200);
    on($('#cookieAccept'),  'click', function () { Storage.setItem('mam.cookie', 'accept'); banner.classList.remove('shown'); });
    on($('#cookieDecline'), 'click', function () { Storage.setItem('mam.cookie', 'decline'); banner.classList.remove('shown'); });
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
    var btns = $$('.currency-btn'); if (!btns.length) return;
    var rates = window.MAM_CONFIG && window.MAM_CONFIG.currency ? window.MAM_CONFIG.currency.rates : { USD: 1, NGN: 1500 };
    function setCur(cur) {
      btns.forEach(function (b) {
        var active = b.getAttribute('data-currency') === cur;
        b.classList.toggle('active', active); b.setAttribute('aria-selected', String(active));
      });
      var symbol = { USD: '$', NGN: '₦', GBP: '£', EUR: '€' }[cur] || '$';
      $$('[data-cur-sym]').forEach(function (s) { s.textContent = symbol; });
      $$('[data-price]').forEach(function (p) {
        var usd = parseInt(p.getAttribute('data-price'), 10);
        var converted = Math.round(usd * (rates[cur] || 1));
        if (cur === 'USD') p.textContent = usd;
        else if (cur === 'NGN' && converted >= 1000) p.textContent = Math.round(converted / 1000) + 'k';
        else p.textContent = converted;
      });
      Storage.setItem('mam.currency', cur);
    }
    btns.forEach(function (b) { on(b, 'click', function () { setCur(b.getAttribute('data-currency')); }); });
    var saved = Storage.getItem('mam.currency');
    if (saved && saved !== 'USD') setCur(saved);
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
      s.src = 'https://cdn.jsdelivr.net/npm/just-validate@4.5.0/dist/just-validate.production.min.js';
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
      var fallback = (window.MAM_CONFIG && window.MAM_CONFIG.forms && window.MAM_CONFIG.forms.fallbackEmail) || 'connect@mamcentre.org';
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
  function boot() {
    initPreloader();
    initNav();
    initHero();
    initSmoothScroll();
    initFaq();
    initAnimations();
    initCountdown();
    initTestimonials();
    initCookie();
    initToolbar();
    initCurrency();
    initNewsletter();
    initFilters();
    initImageFallback();
    initYear();
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

  // Wait for partials to load before binding nav/hero events
  document.addEventListener('DOMContentLoaded', function () {
    Promise.all([
      loadPartial('include-header', 'partials/header.html'),
      loadPartial('include-footer', 'partials/footer.html')
    ]).then(boot);
  });
})();
