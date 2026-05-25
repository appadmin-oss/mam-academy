/* ============================================================
   MAM Academy — Motion as Music
   ─────────────────────────────────────────────────────────────
   Animation discipline borrowed from a musician's vocabulary:
     • Anacrusis  — a pickup note before the downbeat (1st card
                    in a stagger enters 80ms early; the rest land
                    on the beat).
     • Sustain    — card tilt releases over 700ms out-expo, the
                    sound of a damper pedal lifting.
     • Crescendo  — number tickers overshoot then settle (sforzando).
     • Phrasing   — section eyebrow line draws first; the title
                    arrives on the downbeat (conductor's baton).
     • Dust       — three.js particle field over the hero is the
                    visual equivalent of stage light catching dust.
     • Silence    — sections that don't need motion stay still.

   Powered by Motion One (vanilla-JS sibling of Framer Motion) and
   three.js for the ambient WebGL backdrop. Loaded lazily from a
   CDN; the site degrades to clean CSS fade-ins without them.
   ============================================================ */
(function () {
  'use strict';

  /* ─────────────  Config  ───────────── */
  var RM     = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var TOUCH  = matchMedia('(hover: none)').matches;
  var SMALL  = matchMedia('(max-width: 768px)').matches;

  // Curves named for the gesture they describe, in [x1,y1,x2,y2] cubic-bezier.
  var EASE = {
    outExpo:   [0.16, 1.00, 0.30, 1.00],  // long sustain release
    outQuart:  [0.25, 1.00, 0.50, 1.00],  // gentle landing
    sforzando: [0.34, 1.56, 0.64, 1.00],  // overshoot accent
    legato:    [0.65, 0.00, 0.35, 1.00],  // smooth in-out
  };

  var MOTION_URL = 'https://cdn.jsdelivr.net/npm/motion@10.18.0/dist/motion.min.js';
  var THREE_URL  = 'https://cdn.jsdelivr.net/npm/three@0.158.0/build/three.min.js';

  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function loadScript(url) {
    return new Promise(function (resolve) {
      if (document.querySelector('script[src="' + url + '"]')) {
        // Already injected — wait briefly for globals to attach.
        return setTimeout(resolve, 50);
      }
      var s = document.createElement('script');
      s.src = url; s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { resolve(); };
      document.head.appendChild(s);
    });
  }

  /* ─────────────  Boot  ───────────── */
  function boot() {
    if (RM) {
      document.documentElement.classList.add('motion-reduced');
      // Even in reduced-motion: ensure all the reveal-targeted elements
      // are visible (no opacity:0 stuck).
      $$('.fade-in-up, .fade-in').forEach(function (el) { el.classList.add('visible'); });
      return;
    }
    document.documentElement.classList.add('motion-on');

    // CSS-only effects (no library)
    initScrollProgress();
    initFloatingNotes();
    if (!TOUCH) {
      initCardTilt();
      initMagneticButtons();
      initHeroBackgroundParallax();
    }

    // Motion One — staggered reveals, baton sweeps, ticker
    loadScript(MOTION_URL).then(function () {
      var M = window.Motion;
      if (!M) return scriptFailFallback();
      initBatonSweeps(M);
      initHeroPhraseReveal(M);
      initSectionStaggers(M);
      initStatTicker(M);
      initPathwayProgressionMotion(M);
      initMegaMenuPhrasing(M);
    });

    // Three.js — only on the home hero, only when GPU & memory allow
    if ($('#hero') && !TOUCH && !SMALL && canRunThree()) {
      loadScript(THREE_URL).then(function () {
        if (window.THREE) initHeroParticleField(window.THREE);
      });
    }
  }

  /* If Motion library fails (offline / blocked), ensure reveals still happen via CSS. */
  function scriptFailFallback() {
    $$('.fade-in-up, .fade-in').forEach(function (el) { el.classList.add('visible'); });
  }

  /* ============================================================
     Scroll progress — a thin gold reed at the top of the page
     ============================================================ */
  function initScrollProgress() {
    if ($('.scroll-progress')) return;
    var bar = document.createElement('div');
    bar.className = 'scroll-progress';
    document.body.appendChild(bar);
    var raf = null;
    function update() {
      raf = null;
      var max = document.documentElement.scrollHeight - window.innerHeight;
      var p = max > 0 ? window.scrollY / max : 0;
      bar.style.transform = 'scaleX(' + p.toFixed(4) + ')';
    }
    window.addEventListener('scroll', function () {
      if (raf) return; raf = requestAnimationFrame(update);
    }, { passive: true });
    update();
  }

  /* ============================================================
     Floating notation — musical glyphs drift upward through the
     hero. CSS-driven so there's no JS cost beyond DOM creation.
     ============================================================ */
  function initFloatingNotes() {
    var hero = $('#hero');
    if (!hero || hero.querySelector('.floating-notes')) return;
    var glyphs = ['♩', '♪', '♫', '♬', '𝄞', '♭', '♮', '♯', '𝄢', '𝄐'];
    var container = document.createElement('div');
    container.className = 'floating-notes';
    container.setAttribute('aria-hidden', 'true');
    var COUNT = SMALL ? 5 : 9;
    for (var i = 0; i < COUNT; i++) {
      var n = document.createElement('span');
      n.className = 'floating-note';
      n.textContent = glyphs[i % glyphs.length];
      n.style.left = (4 + i * (92 / COUNT) + (Math.random() * 4 - 2)) + '%';
      n.style.fontSize = (1.1 + Math.random() * 2.2) + 'rem';
      n.style.animationDelay = (i * 1.4 + Math.random()) + 's';
      n.style.animationDuration = (16 + Math.random() * 12) + 's';
      n.style.setProperty('--drift', ((Math.random() - 0.5) * 80) + 'px');
      n.style.setProperty('--spin', ((Math.random() - 0.5) * 720) + 'deg');
      container.appendChild(n);
    }
    hero.insertBefore(container, hero.firstChild);
  }

  /* ============================================================
     Card tilt — perspective follow with long sustain on release.
     Picture lifting the damper pedal: the note holds, then fades.
     ============================================================ */
  function initCardTilt() {
    var SELECTOR = [
      '.school-card',  '.pathway-card', '.faculty-card', '.mode-card',
      '.program-card', '.next-step-card', '.unique-card', '.method-step',
      '.hero-card',    '.kpi'
    ].join(',');
    $$(SELECTOR).forEach(attachTilt);
  }
  function attachTilt(card) {
    if (card.dataset.tilt) return; card.dataset.tilt = '1';
    var max  = card.classList.contains('hero-card') ? 3.5 : 6;
    var rect = null, raf = null, lx = 0, ly = 0;

    card.addEventListener('mouseenter', function () {
      rect = card.getBoundingClientRect();
      card.style.willChange = 'transform';
      // Tighten the transition during follow so motion feels responsive.
      card.style.transition = 'transform 0.10s linear';
    });
    card.addEventListener('mousemove', function (e) {
      if (!rect) return;
      lx = e.clientX; ly = e.clientY;
      if (raf) return;
      raf = requestAnimationFrame(function () {
        raf = null; if (!rect) return;
        var x = (lx - rect.left) / rect.width;
        var y = (ly - rect.top)  / rect.height;
        var rotY = (x - 0.5) * (max * 2);
        var rotX = -(y - 0.5) * (max * 2);
        // Lift the card slightly toward the viewer (3D translateZ illusion).
        card.style.transform =
          'perspective(1100px) ' +
          'rotateX(' + rotX.toFixed(2) + 'deg) ' +
          'rotateY(' + rotY.toFixed(2) + 'deg) ' +
          'translate3d(0,-4px,12px)';
      });
    });
    card.addEventListener('mouseleave', function () {
      rect = null;
      // Long out-expo release — the "let-go" should feel sustained.
      card.style.transition = 'transform 0.7s cubic-bezier(0.16,1,0.3,1)';
      card.style.transform = '';
      setTimeout(function () { card.style.willChange = ''; card.style.transition = ''; }, 720);
    });
  }

  /* ============================================================
     Magnetic buttons — cursor attracts the CTA toward itself.
     Restrained: only primary CTAs, max 14px pull.
     ============================================================ */
  function initMagneticButtons() {
    var SELECTOR = '.btn-primary, .btn-cta-large, .btn-nav-cta, .btn-pay, .btn-hero, .btn-signup, .compare-cta-primary';
    $$(SELECTOR).forEach(function (btn) {
      if (btn.dataset.magnetic) return; btn.dataset.magnetic = '1';
      var rect = null, raf = null, lx = 0, ly = 0;
      btn.addEventListener('mouseenter', function () {
        rect = btn.getBoundingClientRect();
        btn.style.willChange = 'transform';
        btn.style.transition = 'transform 0.16s cubic-bezier(0.16,1,0.3,1)';
      });
      btn.addEventListener('mousemove', function (e) {
        if (!rect) return;
        lx = e.clientX; ly = e.clientY;
        if (raf) return;
        raf = requestAnimationFrame(function () {
          raf = null; if (!rect) return;
          var x = (lx - (rect.left + rect.width  / 2)) * 0.18;
          var y = (ly - (rect.top  + rect.height / 2)) * 0.22;
          // Clamp the pull so the button never visually escapes.
          x = Math.max(-14, Math.min(14, x));
          y = Math.max(-12, Math.min(12, y));
          btn.style.transform = 'translate(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px)';
        });
      });
      btn.addEventListener('mouseleave', function () {
        rect = null;
        btn.style.transition = 'transform 0.45s cubic-bezier(0.34,1.56,0.64,1)';
        btn.style.transform = '';
        setTimeout(function () { btn.style.transition = ''; btn.style.willChange = ''; }, 460);
      });
    });
  }

  /* ============================================================
     Hero background parallax — slide bg drifts opposite the cursor.
     Subtle (~14px max). Don't fight the carousel transitions.
     ============================================================ */
  function initHeroBackgroundParallax() {
    var hero = $('#hero'); if (!hero) return;
    var raf = null, x = 0, y = 0;
    hero.addEventListener('mousemove', function (e) {
      var r = hero.getBoundingClientRect();
      x = (e.clientX - r.left) / r.width - 0.5;
      y = (e.clientY - r.top)  / r.height - 0.5;
      if (raf) return;
      raf = requestAnimationFrame(function () {
        raf = null;
        $$('.hero-slide.active .slide-bg').forEach(function (bg) {
          bg.style.transform = 'scale(1.06) translate(' + (x * -14).toFixed(1) + 'px,' + (y * -10).toFixed(1) + 'px)';
        });
      });
    });
    hero.addEventListener('mouseleave', function () {
      $$('.hero-slide.active .slide-bg').forEach(function (bg) { bg.style.transform = ''; });
    });
  }

  /* ============================================================
     Baton sweep — the eyebrow line draws first; the title lands.
     ============================================================ */
  function initBatonSweeps(M) {
    $$('.section-eyebrow').forEach(function (eyebrow) {
      var line = eyebrow.querySelector('.line');
      var title = eyebrow.parentElement && eyebrow.parentElement.querySelector('.section-title');
      var desc  = eyebrow.parentElement && eyebrow.parentElement.querySelector('.section-desc');

      // Initial state
      if (line) line.style.transform = 'scaleX(0)';
      eyebrow.style.opacity = '0';
      if (title) { title.style.opacity = '0'; title.style.transform = 'translateY(18px)'; }
      if (desc)  { desc.style.opacity  = '0'; desc.style.transform  = 'translateY(14px)'; }

      M.inView(eyebrow, function () {
        // 1. Eyebrow + line sweep
        M.animate(eyebrow, { opacity: 1 }, { duration: 0.4, easing: 'ease-out' });
        if (line) {
          M.animate(line, { transform: ['scaleX(0)', 'scaleX(1)'] }, {
            duration: 0.6, easing: EASE.outExpo
          });
        }
        // 2. Title — downbeat
        if (title) {
          M.animate(title, { opacity: 1, transform: 'translateY(0px)' }, {
            duration: 0.7, delay: 0.18, easing: EASE.outExpo
          });
        }
        // 3. Description — release
        if (desc) {
          M.animate(desc, { opacity: 1, transform: 'translateY(0px)' }, {
            duration: 0.6, delay: 0.32, easing: EASE.outQuart
          });
        }
      }, { amount: 0.35 });
    });
  }

  /* ============================================================
     Hero phrase reveal — each <span> in the active slide's
     headline enters as a word in a musical line.
     ============================================================ */
  function initHeroPhraseReveal(M) {
    var slides = $$('.hero-slide');
    if (!slides.length) return;

    function arrange(slide) {
      $$('.card-headline span', slide).forEach(function (s) {
        s.style.display = 'inline-block';
        s.style.opacity = '0';
        s.style.transform = 'translateY(22px) scale(0.94)';
      });
      var subtitle = slide.querySelector('.card-subtitle');
      var desc     = slide.querySelector('.card-desc');
      [subtitle, desc].forEach(function (el) {
        if (!el) return;
        el.style.opacity = '0';
        el.style.transform = 'translateY(12px)';
      });
      var actions = $$('.btn-signup', slide);
      actions.forEach(function (b) {
        b.style.opacity = '0';
        b.style.transform = 'translateY(10px)';
      });
    }

    function phrase(slide) {
      var words = $$('.card-headline span', slide);
      // Anacrusis: word[0] arrives 60ms before the downbeat, the rest in tempo.
      words.forEach(function (w, i) {
        var delay = (i === 0 ? 0.30 : 0.36 + (i - 1) * 0.12);
        M.animate(w, { opacity: 1, transform: 'translateY(0px) scale(1)' }, {
          duration: 0.65, delay: delay, easing: EASE.outExpo
        });
      });
      var sub = slide.querySelector('.card-subtitle');
      var desc = slide.querySelector('.card-desc');
      if (sub)  M.animate(sub,  { opacity: 1, transform: 'translateY(0)' }, { duration: 0.55, delay: 0.30 + words.length * 0.12, easing: EASE.outQuart });
      if (desc) M.animate(desc, { opacity: 1, transform: 'translateY(0)' }, { duration: 0.55, delay: 0.42 + words.length * 0.12, easing: EASE.outQuart });
      $$('.btn-signup', slide).forEach(function (b, i) {
        M.animate(b, { opacity: 1, transform: 'translateY(0)' }, {
          duration: 0.5, delay: 0.55 + words.length * 0.12 + i * 0.08, easing: EASE.outQuart
        });
      });
    }

    slides.forEach(arrange);
    var active = $('.hero-slide.active') || slides[0];
    phrase(active);

    // Re-phrase whenever the active slide changes.
    var prev = active;
    var obs = new MutationObserver(function () {
      var now = $('.hero-slide.active');
      if (now && now !== prev) {
        arrange(now);
        phrase(now);
        prev = now;
      }
    });
    slides.forEach(function (s) { obs.observe(s, { attributes: true, attributeFilter: ['class'] }); });
  }

  /* ============================================================
     Section staggers — anacrusis stagger with musical phrasing.
     ============================================================ */
  function initSectionStaggers(M) {
    var groups = [
      { selector: '.how-grid > .how-step',           anacrusis: true,  y: 24, scale: 0.96 },
      { selector: '.schools-grid > .school-card',    anacrusis: true,  y: 30, scale: 0.96 },
      { selector: '.pathways-grid > .pathway-card',  anacrusis: true,  y: 30, scale: 0.96 },
      { selector: '.modes-grid > .mode-card',        anacrusis: false, y: 30, scale: 0.97 },
      { selector: '.method-grid > .method-step',     anacrusis: false, y: 22, scale: 0.95 },
      { selector: '.faculty-grid > .faculty-card',   anacrusis: true,  y: 28, scale: 0.96 },
      { selector: '.extras-row > .extra-card',       anacrusis: false, y: 18, scale: 0.98 },
      { selector: '.unique-grid > .unique-card',     anacrusis: true,  y: 24, scale: 0.96 },
      { selector: '.testimonials-track > .testimonial-card', anacrusis: false, y: 14, scale: 0.98 },
    ];
    groups.forEach(function (g) {
      var items = $$(g.selector);
      if (!items.length) return;
      items.forEach(function (el) {
        el.style.opacity = '0';
        el.style.transform = 'translateY(' + g.y + 'px) scale(' + g.scale + ')';
      });
      var anchor = items[0].parentElement;
      M.inView(anchor, function () {
        items.forEach(function (el, i) {
          // Anacrusis: first card lands 60ms early; the rest land on consecutive beats.
          var delay;
          if (g.anacrusis) {
            delay = i === 0 ? 0 : 0.06 + (i - 1) * 0.09;
          } else {
            delay = i * 0.085;
          }
          M.animate(el, { opacity: 1, transform: 'translateY(0px) scale(1)' }, {
            duration: 0.65, delay: delay, easing: EASE.outExpo
          });
        });
      }, { amount: 0.18 });
    });
  }

  /* ============================================================
     Stat counters — sforzando: a sharp tick up with overshoot.
     ============================================================ */
  function initStatTicker(M) {
    $$('.stat-number[data-count]').forEach(function (el) {
      if (el.dataset.tickerRun) return; el.dataset.tickerRun = '1';
      var target = parseInt(el.getAttribute('data-count'), 10) || 0;
      var suffix = (el.textContent.replace(/[0-9.,]/g, '').trim()) || '+';
      el.textContent = '0' + suffix;
      M.inView(el, function () {
        M.animate(function (v) {
          // Slight overshoot near the end, settling on target — sforzando.
          var settled = v < 0.85 ? v / 0.85 : 1 + Math.sin((v - 0.85) / 0.15 * Math.PI) * 0.04 * (1 - v);
          el.textContent = Math.round(target * Math.min(1, settled)).toLocaleString('en-US') + suffix;
        }, { duration: 1.8, easing: EASE.outExpo });
      }, { amount: 0.5 });
    });
  }

  /* ============================================================
     Pathway progression — the track unspools, then the nodes
     answer one by one (a phrase of four).
     ============================================================ */
  function initPathwayProgressionMotion(M) {
    var pp = $('.pathway-progression'); if (!pp) return;
    var nodes = $$('.pp-node', pp);
    var track = pp.querySelector('.pathway-progression-track');
    nodes.forEach(function (n) { n.style.opacity = '0'; n.style.transform = 'translateY(20px) scale(0.85)'; });
    if (track) track.style.setProperty('--track-progress', '0%');
    M.inView(pp, function () {
      // 1. Unspool the track
      if (track) {
        M.animate(function (v) {
          track.style.setProperty('--track-progress', (v * 100).toFixed(1) + '%');
        }, { duration: 1.1, easing: EASE.outExpo });
      }
      // 2. Each node lands on its beat, with the featured one accented.
      nodes.forEach(function (n, i) {
        var isFeatured = n.classList.contains('featured');
        M.animate(n,
          { opacity: 1, transform: ['translateY(20px) scale(0.85)', 'translateY(0px) scale(' + (isFeatured ? 1.04 : 1) + ')', 'translateY(0px) scale(1)'] },
          { duration: 0.85, delay: 0.25 + i * 0.18, easing: EASE.outExpo, offset: [0, 0.7, 1] }
        );
      });
    }, { amount: 0.3 });
  }

  /* ============================================================
     Mega menu — each column resolves one after another instead
     of all-at-once.
     ============================================================ */
  function initMegaMenuPhrasing(M) {
    $$('.nav-item').forEach(function (item) {
      var dd = item.querySelector('.mega-menu'); if (!dd) return;
      var open = false;
      var obs = new MutationObserver(function () {
        var nowOpen = item.classList.contains('js-hover') || item.classList.contains('open');
        if (nowOpen && !open) {
          open = true;
          var pieces = dd.querySelectorAll('.mega-banner, .mega-col, .mega-spotlight');
          pieces.forEach(function (el, i) {
            M.animate(el,
              { opacity: [0.2, 1], transform: ['translateY(10px)', 'translateY(0)'] },
              { duration: 0.32, delay: i * 0.04, easing: EASE.outQuart }
            );
          });
        } else if (!nowOpen) {
          open = false;
        }
      });
      obs.observe(item, { attributes: true, attributeFilter: ['class'] });
    });
  }

  /* ============================================================
     Three.js — golden particle field as ambient hero backdrop.
     Loaded lazily, paused when out of viewport, capped DPR.
     ============================================================ */
  function canRunThree() {
    try {
      var c = document.createElement('canvas');
      var gl = c.getContext('webgl2') || c.getContext('webgl');
      if (!gl) return false;
      // Skip on memory-constrained devices
      if (navigator.deviceMemory && navigator.deviceMemory < 2) return false;
      return true;
    } catch (e) { return false; }
  }

  function initHeroParticleField(THREE) {
    var hero = $('#hero'); if (!hero) return;
    if (hero.querySelector('.hero-three-canvas')) return;

    var canvas = document.createElement('canvas');
    canvas.className = 'hero-three-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    hero.insertBefore(canvas, hero.firstChild);

    function W() { return hero.clientWidth || window.innerWidth; }
    function H() { return hero.clientHeight || window.innerHeight; }

    var renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: false, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.8));
    renderer.setSize(W(), H(), false);

    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(36, W() / H(), 0.1, 600);
    camera.position.z = 70;

    // Particle field — count tuned per viewport
    var COUNT = SMALL ? 90 : 220;
    var positions  = new Float32Array(COUNT * 3);
    var velocities = new Float32Array(COUNT * 3);
    var sizes      = new Float32Array(COUNT);
    var phases     = new Float32Array(COUNT);
    var hues       = new Float32Array(COUNT); // small per-particle warm-tone variance

    for (var i = 0; i < COUNT; i++) {
      positions[i*3]   = (Math.random() - 0.5) * 140;
      positions[i*3+1] = (Math.random() - 0.5) * 100;
      positions[i*3+2] = (Math.random() - 0.5) * 80;
      velocities[i*3]   = (Math.random() - 0.5) * 0.035;
      velocities[i*3+1] = Math.random() * 0.06 + 0.012;
      velocities[i*3+2] = (Math.random() - 0.5) * 0.020;
      sizes[i]  = Math.random() * 2.8 + 0.8;
      phases[i] = Math.random() * Math.PI * 2;
      hues[i]   = 0.07 + Math.random() * 0.04;  // hue around amber (0.07–0.11)
    }

    var geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('aSize',    new THREE.BufferAttribute(sizes, 1));
    geometry.setAttribute('aPhase',   new THREE.BufferAttribute(phases, 1));
    geometry.setAttribute('aHue',     new THREE.BufferAttribute(hues, 1));

    var material = new THREE.ShaderMaterial({
      uniforms: {
        uTime: { value: 0 },
        uPx:   { value: renderer.getPixelRatio() }
      },
      vertexShader: [
        'attribute float aSize;',
        'attribute float aPhase;',
        'attribute float aHue;',
        'uniform float uTime;',
        'uniform float uPx;',
        'varying float vAlpha;',
        'varying float vHue;',
        'void main() {',
        '  vec4 mv = modelViewMatrix * vec4(position, 1.0);',
        '  float twinkle = 0.5 + 0.5 * sin(uTime * 1.1 + aPhase);',
        '  vAlpha = 0.30 + 0.45 * twinkle;',
        '  vHue   = aHue;',
        '  gl_PointSize = aSize * uPx * (220.0 / -mv.z) * (0.6 + 0.4 * twinkle);',
        '  gl_Position = projectionMatrix * mv;',
        '}'
      ].join('\n'),
      fragmentShader: [
        'varying float vAlpha;',
        'varying float vHue;',
        'vec3 hsl(float h, float s, float l) {',
        '  vec3 rgb = clamp(abs(mod(h*6.0 + vec3(0.0,4.0,2.0), 6.0) - 3.0) - 1.0, 0.0, 1.0);',
        '  return l + s*(rgb-0.5)*(1.0 - abs(2.0*l-1.0));',
        '}',
        'void main() {',
        '  vec2 c = gl_PointCoord - 0.5;',
        '  float d = length(c);',
        '  if (d > 0.5) discard;',
        '  float a = smoothstep(0.5, 0.0, d) * vAlpha;',
        '  vec3 col = hsl(vHue, 0.85, 0.58);',
        '  gl_FragColor = vec4(col, a);',
        '}'
      ].join('\n'),
      transparent: true,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    });

    var points = new THREE.Points(geometry, material);
    scene.add(points);

    var mx = 0, my = 0;
    hero.addEventListener('mousemove', function (e) {
      var r = hero.getBoundingClientRect();
      mx = (e.clientX - r.left) / r.width - 0.5;
      my = (e.clientY - r.top) / r.height - 0.5;
    }, { passive: true });

    var visible = true;
    var io = new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting;
      if (visible && !rafId) { lastT = 0; rafId = requestAnimationFrame(loop); }
    });
    io.observe(hero);

    var rafId = null, lastT = 0;
    function loop(t) {
      if (!visible) { rafId = null; return; }
      rafId = requestAnimationFrame(loop);
      var dt = lastT ? Math.min(0.05, (t - lastT) / 1000) : 0.016;
      lastT = t;

      var pos = geometry.attributes.position.array;
      for (var i = 0; i < COUNT; i++) {
        pos[i*3]   += velocities[i*3]   * dt * 60;
        pos[i*3+1] += velocities[i*3+1] * dt * 60;
        pos[i*3+2] += velocities[i*3+2] * dt * 60;
        // Wrap on Y (continuous drift up), gentle clamp on X/Z.
        if (pos[i*3+1] > 55)  pos[i*3+1] = -55;
        if (pos[i*3]   > 80)  pos[i*3]   = -80;
        if (pos[i*3]   < -80) pos[i*3]   = 80;
      }
      geometry.attributes.position.needsUpdate = true;

      // Slow camera parallax follows the mouse (subtle).
      camera.position.x += (mx * 8 - camera.position.x) * 0.04;
      camera.position.y += (-my * 6 - camera.position.y) * 0.04;
      camera.lookAt(0, 0, 0);

      material.uniforms.uTime.value = (t || 0) / 1000;
      renderer.render(scene, camera);
    }
    rafId = requestAnimationFrame(loop);

    // Resize
    var resizeRaf = null;
    window.addEventListener('resize', function () {
      if (resizeRaf) cancelAnimationFrame(resizeRaf);
      resizeRaf = requestAnimationFrame(function () {
        renderer.setSize(W(), H(), false);
        camera.aspect = W() / H();
        camera.updateProjectionMatrix();
      });
    });

    // Pause when tab hidden
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        visible = false;
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
      } else if (io && hero.getBoundingClientRect().top < window.innerHeight) {
        visible = true; if (!rafId) { lastT = 0; rafId = requestAnimationFrame(loop); }
      }
    });
  }

  /* ─────────────  Entry  ───────────── */
  if (document.querySelector('#navbar')) {
    boot();
  } else {
    document.addEventListener('mam:ready', boot, { once: true });
    // If partials never resolve, fall through with bare effects after 3s.
    setTimeout(function () { if (!document.documentElement.classList.contains('motion-on') && !document.documentElement.classList.contains('motion-reduced')) boot(); }, 3000);
  }
})();
