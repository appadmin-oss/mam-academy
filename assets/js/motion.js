/* ============================================================
   MAM Academy — Motion (clean build)
   ─────────────────────────────────────────────────────────────
   Scope, intentionally narrow:
     1. Scroll progress reed
     2. Floating musical glyphs (CSS-driven, hero only)
     3. 3D card tilt on hover
     4. Magnetic primary CTAs
     5. Hero background parallax (cursor)
     6. Pathway progression timeline (gated on .motion-on; no FOUC)
     7. Mega menu — sequenced reveal on hover only
     8. Three.js golden particle field (lazy, hero only, optional)

   NOT in scope (all caused FOUC and have been removed):
     • Section staggers     — owned by main.js `.fade-in-up`
     • Baton-sweep eyebrows  — same
     • Hero phrase reveal    — fights the existing CSS card entrance
     • Stat ticker overrides — main.js already does this; doubling
                                up garbled the numbers

   Powered by Motion One — vanilla-JS sibling of Framer Motion.
   ============================================================ */
(function () {
  'use strict';

  /* ─────────────  Config  ───────────── */
  var RM    = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var TOUCH = matchMedia('(hover: none)').matches;
  var SMALL = matchMedia('(max-width: 768px)').matches;

  var EASE = {
    outExpo:   [0.16, 1.00, 0.30, 1.00],
    outQuart:  [0.25, 1.00, 0.50, 1.00],
    sforzando: [0.34, 1.56, 0.64, 1.00],
  };

  var MOTION_URL = 'https://cdn.jsdelivr.net/npm/motion@10.18.0/dist/motion.min.js';
  var THREE_URL  = 'https://cdn.jsdelivr.net/npm/three@0.158.0/build/three.min.js';

  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

  function loadScript(url) {
    return new Promise(function (resolve) {
      if (document.querySelector('script[src="' + url + '"]')) return setTimeout(resolve, 30);
      var s = document.createElement('script');
      s.src = url; s.async = true;
      s.onload  = function () { resolve(); };
      s.onerror = function () { resolve(); };
      document.head.appendChild(s);
    });
  }

  /* ─────────────  Boot  ───────────── */
  function boot() {
    if (RM) {
      document.documentElement.classList.add('motion-reduced');
      return;
    }
    document.documentElement.classList.add('motion-on');

    // 1. CSS-only effects (no library dependencies)
    initScrollProgress();
    initFloatingNotes();
    if (!TOUCH) {
      initCardTilt();
      initMagneticButtons();
      initHeroBackgroundParallax();
    }

    // 2. Motion One — only what doesn't fight existing CSS reveals
    loadScript(MOTION_URL).then(function () {
      var M = window.Motion;
      if (!M) return;
      initPathwayProgression(M);
      initMegaMenuPhrasing(M);
    });

    // 3. Three.js — home hero only, WebGL-capable, non-touch, non-mobile
    if ($('#hero') && !TOUCH && !SMALL && canRunThree()) {
      loadScript(THREE_URL).then(function () {
        if (window.THREE) initHeroParticleField(window.THREE);
      });
    }
  }

  /* ============================================================
     1. Scroll progress reed
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
      bar.style.transform = 'scaleX(' + (max > 0 ? (window.scrollY / max).toFixed(4) : 0) + ')';
    }
    window.addEventListener('scroll', function () {
      if (raf) return; raf = requestAnimationFrame(update);
    }, { passive: true });
    update();
  }

  /* ============================================================
     2. Floating musical glyphs (BMP-only — safe on every device)
     ============================================================ */
  function initFloatingNotes() {
    var hero = $('#hero');
    if (!hero || hero.querySelector('.floating-notes')) return;
    // Restricted to BMP block — these render reliably everywhere.
    var glyphs = ['♪', '♫', '♬', '♭', '♮', '♯', '♩'];
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
      n.style.setProperty('--spin', ((Math.random() - 0.5) * 540) + 'deg');
      container.appendChild(n);
    }
    hero.insertBefore(container, hero.firstChild);
  }

  /* ============================================================
     3. 3D card tilt — long sustain release (~700ms)
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
        card.style.transform =
          'perspective(1100px) rotateX(' + rotX.toFixed(2) + 'deg) rotateY(' + rotY.toFixed(2) + 'deg) translate3d(0,-4px,12px)';
      });
    });
    card.addEventListener('mouseleave', function () {
      rect = null;
      card.style.transition = 'transform 0.7s cubic-bezier(0.16,1,0.3,1)';
      card.style.transform = '';
      setTimeout(function () { card.style.willChange = ''; card.style.transition = ''; }, 720);
    });
  }

  /* ============================================================
     4. Magnetic CTAs — clamped to ±14px
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
     5. Hero bg parallax (cursor-driven; subtle)
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
     6. Pathway progression — initial state in CSS (no FOUC),
        Motion One animates to final state on view.
     ============================================================ */
  function initPathwayProgression(M) {
    var pp = $('.pathway-progression'); if (!pp) return;
    var nodes = $$('.pp-node', pp);
    var track = pp.querySelector('.pathway-progression-track');
    M.inView(pp, function () {
      // Unspool the connecting track
      if (track) {
        M.animate(function (v) {
          track.style.setProperty('--track-progress', (v * 100).toFixed(1) + '%');
        }, { duration: 1.1, easing: EASE.outExpo });
      }
      // Phrase the four nodes — featured node gets the accent
      nodes.forEach(function (n, i) {
        var isFeatured = n.classList.contains('featured');
        M.animate(n,
          { opacity: 1, transform: ['translateY(20px) scale(0.85)',
                                    'translateY(0px) scale(' + (isFeatured ? 1.04 : 1) + ')',
                                    'translateY(0px) scale(1)'] },
          { duration: 0.85, delay: 0.25 + i * 0.18, easing: EASE.outExpo, offset: [0, 0.7, 1] }
        );
      });
    }, { amount: 0.3 });
  }

  /* ============================================================
     7. Mega menu — sequenced reveal on open
     ============================================================ */
  function initMegaMenuPhrasing(M) {
    $$('.nav-item').forEach(function (item) {
      var dd = item.querySelector('.mega-menu'); if (!dd) return;
      var open = false;
      var obs = new MutationObserver(function () {
        var nowOpen = item.classList.contains('js-hover') || item.classList.contains('open');
        if (nowOpen && !open) {
          open = true;
          dd.querySelectorAll('.mega-banner, .mega-col, .mega-spotlight').forEach(function (el, i) {
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
     8. Three.js — golden particle field over the hero.
     ============================================================ */
  function canRunThree() {
    try {
      var c = document.createElement('canvas');
      var gl = c.getContext('webgl2') || c.getContext('webgl');
      if (!gl) return false;
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
    // Insert AFTER .hero-slides so the canvas sits above the slide bg but
    // below the slide-overlay (which integrates the particle warmth with
    // the rest of the scene's gradient treatment).
    var slides = hero.querySelector('.hero-slides');
    if (slides && slides.nextSibling) hero.insertBefore(canvas, slides.nextSibling);
    else hero.appendChild(canvas);

    function W() { return hero.clientWidth || window.innerWidth; }
    function H() { return hero.clientHeight || window.innerHeight; }

    var renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: false, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.8));
    renderer.setSize(W(), H(), false);

    var scene  = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(36, W() / H(), 0.1, 600);
    camera.position.z = 70;

    var COUNT = SMALL ? 90 : 220;
    var positions  = new Float32Array(COUNT * 3);
    var velocities = new Float32Array(COUNT * 3);
    var sizes      = new Float32Array(COUNT);
    var phases     = new Float32Array(COUNT);

    for (var i = 0; i < COUNT; i++) {
      positions[i*3]   = (Math.random() - 0.5) * 140;
      positions[i*3+1] = (Math.random() - 0.5) * 100;
      positions[i*3+2] = (Math.random() - 0.5) * 80;
      velocities[i*3]   = (Math.random() - 0.5) * 0.035;
      velocities[i*3+1] = Math.random() * 0.06 + 0.012;
      velocities[i*3+2] = (Math.random() - 0.5) * 0.020;
      sizes[i]  = Math.random() * 2.8 + 0.8;
      phases[i] = Math.random() * Math.PI * 2;
    }

    var geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geometry.setAttribute('aSize',    new THREE.BufferAttribute(sizes, 1));
    geometry.setAttribute('aPhase',   new THREE.BufferAttribute(phases, 1));

    var material = new THREE.ShaderMaterial({
      uniforms: { uTime: { value: 0 }, uPx: { value: renderer.getPixelRatio() } },
      vertexShader: [
        'attribute float aSize;',
        'attribute float aPhase;',
        'uniform float uTime;',
        'uniform float uPx;',
        'varying float vAlpha;',
        'void main() {',
        '  vec4 mv = modelViewMatrix * vec4(position, 1.0);',
        '  float twinkle = 0.5 + 0.5 * sin(uTime * 1.1 + aPhase);',
        '  vAlpha = 0.30 + 0.45 * twinkle;',
        '  gl_PointSize = aSize * uPx * (220.0 / -mv.z) * (0.6 + 0.4 * twinkle);',
        '  gl_Position = projectionMatrix * mv;',
        '}'
      ].join('\n'),
      fragmentShader: [
        'varying float vAlpha;',
        'void main() {',
        '  vec2 c = gl_PointCoord - 0.5;',
        '  float d = length(c);',
        '  if (d > 0.5) discard;',
        '  float a = smoothstep(0.5, 0.0, d) * vAlpha;',
        '  vec3 col = vec3(0.98, 0.72, 0.32);',
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
        if (pos[i*3+1] > 55)  pos[i*3+1] = -55;
        if (pos[i*3]   > 80)  pos[i*3]   = -80;
        if (pos[i*3]   < -80) pos[i*3]   = 80;
      }
      geometry.attributes.position.needsUpdate = true;

      camera.position.x += (mx * 8 - camera.position.x) * 0.04;
      camera.position.y += (-my * 6 - camera.position.y) * 0.04;
      camera.lookAt(0, 0, 0);

      material.uniforms.uTime.value = (t || 0) / 1000;
      renderer.render(scene, camera);
    }
    rafId = requestAnimationFrame(loop);

    var resizeRaf = null;
    window.addEventListener('resize', function () {
      if (resizeRaf) cancelAnimationFrame(resizeRaf);
      resizeRaf = requestAnimationFrame(function () {
        renderer.setSize(W(), H(), false);
        camera.aspect = W() / H();
        camera.updateProjectionMatrix();
      });
    });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        visible = false;
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
      } else if (hero.getBoundingClientRect().top < window.innerHeight) {
        visible = true; if (!rafId) { lastT = 0; rafId = requestAnimationFrame(loop); }
      }
    });
  }

  /* ─────────────  Entry  ───────────── */
  if (document.querySelector('#navbar')) {
    boot();
  } else {
    document.addEventListener('mam:ready', boot, { once: true });
    setTimeout(function () {
      if (!document.documentElement.classList.contains('motion-on') &&
          !document.documentElement.classList.contains('motion-reduced')) boot();
    }, 3000);
  }
})();
