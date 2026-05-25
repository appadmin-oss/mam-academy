/* ============================================================
   MAM Academy — Motion (smooth build)
   ─────────────────────────────────────────────────────────────
   The previous build wrote inline transforms directly on each
   mousemove and let a 100ms CSS transition catch up. That can
   look stepped when the cursor moves fast.

   This build does it the way premium sites do: every interactive
   transform has a target value AND a current value. A rAF loop
   lerps current toward target at ~10% per frame (capped by a
   "settled" threshold) so motion is continuous, even when the
   cursor moves quickly or leaves the element. The loop self-
   suspends once the value is close enough to its target so we
   never burn CPU on a stationary card.

   Lerping applies to:
     • 3D card tilt   (rotateX / rotateY + translateZ lift)
     • Magnetic CTAs  (translate x / y)
     • Hero bg parallax  (image translate)

   Loaded lazily by main.js so we don't have to touch every page.
   ============================================================ */
(function () {
  'use strict';

  /* ─────────────  Config  ───────────── */
  var RM    = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var TOUCH = matchMedia('(hover: none)').matches;
  var SMALL = matchMedia('(max-width: 768px)').matches;

  /* Lerp coefficients — lower = smoother but laggier.
     0.12 gives ~30 frames to converge (~500ms at 60fps) which
     reads as "responsive but luxurious." */
  var LERP = 0.12;
  var LERP_SETTLE = 0.015;  // below this, snap to target & stop

  var EASE = {
    outExpo:  [0.16, 1.00, 0.30, 1.00],
    outQuart: [0.25, 1.00, 0.50, 1.00],
  };

  var MOTION_URL = 'https://cdn.jsdelivr.net/npm/motion@10.18.0/dist/motion.min.js';
  var THREE_URL  = 'https://cdn.jsdelivr.net/npm/three@0.158.0/build/three.min.js';

  function $(s, c)  { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function loadScript(url) {
    return new Promise(function (r) {
      if (document.querySelector('script[src="' + url + '"]')) return setTimeout(r, 30);
      var s = document.createElement('script');
      s.src = url; s.async = true;
      s.onload  = function () { r(); };
      s.onerror = function () { r(); };
      document.head.appendChild(s);
    });
  }

  /* ─────────────  Boot  ───────────── */
  function boot() {
    if (RM) { document.documentElement.classList.add('motion-reduced'); return; }
    document.documentElement.classList.add('motion-on');

    initScrollProgress();
    initFloatingNotes();
    if (!TOUCH) {
      initCardTilt();
      initMagneticButtons();
      initHeroBackgroundParallax();
    }
    loadScript(MOTION_URL).then(function () {
      var M = window.Motion;
      if (!M) return;
      initPathwayProgression(M);
      initMegaMenuPhrasing(M);
    });
    if ($('#hero') && !TOUCH && !SMALL && canRunThree()) {
      loadScript(THREE_URL).then(function () {
        if (window.THREE) initHeroParticleField(window.THREE);
      });
    }
  }

  /* ============================================================
     Lerp helper — returns an animator with target / settle API.
     Each interactive element gets one of these. The animator
     owns its own rAF loop and shuts off when there's nothing to
     do, so 12 idle cards cost zero frames.
     ============================================================ */
  function makeLerper(apply, opts) {
    opts = opts || {};
    var rate = opts.rate || LERP;
    var settle = opts.settle || LERP_SETTLE;

    var state = {};      // current values
    var target = {};     // target values
    var raf = null;

    function step() {
      var moving = false;
      for (var k in target) {
        var diff = target[k] - state[k];
        if (Math.abs(diff) > settle) {
          state[k] += diff * rate;
          moving = true;
        } else if (state[k] !== target[k]) {
          state[k] = target[k];
          moving = true;
        }
      }
      apply(state);
      raf = moving ? requestAnimationFrame(step) : null;
    }

    return {
      setTarget: function (delta) {
        for (var k in delta) {
          target[k] = delta[k];
          if (state[k] === undefined) state[k] = delta[k];  // first-run seed
        }
        if (!raf) raf = requestAnimationFrame(step);
      },
      seed: function (vals) {
        for (var k in vals) { state[k] = vals[k]; target[k] = vals[k]; }
        apply(state);
      },
      stop: function () {
        if (raf) { cancelAnimationFrame(raf); raf = null; }
      }
    };
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
     2. Floating glyphs (CSS-only)
     ============================================================ */
  function initFloatingNotes() {
    var hero = $('#hero'); if (!hero || hero.querySelector('.floating-notes')) return;
    var glyphs = ['♪', '♫', '♬', '♭', '♮', '♯', '♩'];
    var c = document.createElement('div');
    c.className = 'floating-notes';
    c.setAttribute('aria-hidden', 'true');
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
      n.style.setProperty('--spin', ((Math.random() - 0.5) * 240) + 'deg');
      c.appendChild(n);
    }
    hero.insertBefore(c, hero.firstChild);
  }

  /* ============================================================
     3. Card tilt — lerped (smooth even on fast cursor moves)
     ============================================================ */
  function initCardTilt() {
    var SEL = '.school-card,.pathway-card,.faculty-card,.mode-card,.program-card,.next-step-card,.unique-card,.method-step,.hero-card,.kpi';
    $$(SEL).forEach(attachTilt);
  }
  function attachTilt(card) {
    if (card.dataset.tilt) return; card.dataset.tilt = '1';
    var MAX = card.classList.contains('hero-card') ? 3.5 : 6;
    var LIFT = card.classList.contains('hero-card') ? 0 : 4;
    var TZ = 14;

    var lerper = makeLerper(function (s) {
      // Compose the transform from the lerped state values.
      if (s.rx === 0 && s.ry === 0 && s.lift === 0) {
        card.style.transform = '';
        card.style.willChange = '';
        return;
      }
      card.style.transform =
        'perspective(1200px) rotateX(' + s.rx.toFixed(3) + 'deg) rotateY(' + s.ry.toFixed(3) + 'deg) ' +
        'translate3d(0,' + (-s.lift).toFixed(2) + 'px,' + (s.lift * TZ / Math.max(LIFT, 0.01)).toFixed(2) + 'px)';
    });
    lerper.seed({ rx: 0, ry: 0, lift: 0 });

    var rect = null;
    card.addEventListener('mouseenter', function () {
      rect = card.getBoundingClientRect();
      card.style.willChange = 'transform';
      card.style.transition = 'none';
      lerper.setTarget({ lift: LIFT });
    });
    card.addEventListener('mousemove', function (e) {
      if (!rect) return;
      var x = (e.clientX - rect.left) / rect.width;
      var y = (e.clientY - rect.top)  / rect.height;
      lerper.setTarget({
        rx: -(y - 0.5) * MAX * 2,
        ry:  (x - 0.5) * MAX * 2
      });
    });
    card.addEventListener('mouseleave', function () {
      rect = null;
      lerper.setTarget({ rx: 0, ry: 0, lift: 0 });
    });
  }

  /* ============================================================
     4. Magnetic CTAs — lerped (smooth on fast cursor moves)
     ============================================================ */
  function initMagneticButtons() {
    var SEL = '.btn-primary, .btn-cta-large, .btn-nav-cta, .btn-pay, .btn-hero, .btn-signup, .compare-cta-primary';
    $$(SEL).forEach(attachMagnetic);
  }
  function attachMagnetic(btn) {
    if (btn.dataset.magnetic) return; btn.dataset.magnetic = '1';

    var lerper = makeLerper(function (s) {
      if (Math.abs(s.x) < 0.05 && Math.abs(s.y) < 0.05) {
        btn.style.transform = ''; btn.style.willChange = ''; return;
      }
      btn.style.transform = 'translate(' + s.x.toFixed(2) + 'px,' + s.y.toFixed(2) + 'px)';
    }, { rate: 0.14 });
    lerper.seed({ x: 0, y: 0 });

    var rect = null;
    btn.addEventListener('mouseenter', function () {
      rect = btn.getBoundingClientRect();
      btn.style.willChange = 'transform';
      btn.style.transition = 'none';
    });
    btn.addEventListener('mousemove', function (e) {
      if (!rect) return;
      var x = (e.clientX - (rect.left + rect.width  / 2)) * 0.18;
      var y = (e.clientY - (rect.top  + rect.height / 2)) * 0.22;
      lerper.setTarget({
        x: Math.max(-14, Math.min(14, x)),
        y: Math.max(-12, Math.min(12, y))
      });
    });
    btn.addEventListener('mouseleave', function () {
      rect = null;
      lerper.setTarget({ x: 0, y: 0 });
    });
  }

  /* ============================================================
     5. Hero background parallax — lerped
     ============================================================ */
  function initHeroBackgroundParallax() {
    var hero = $('#hero'); if (!hero) return;

    var lerper = makeLerper(function (s) {
      $$('.hero-slide.active .slide-bg').forEach(function (bg) {
        if (Math.abs(s.x) < 0.05 && Math.abs(s.y) < 0.05) {
          bg.style.transform = '';
        } else {
          bg.style.transform = 'scale(1.06) translate(' + s.x.toFixed(2) + 'px,' + s.y.toFixed(2) + 'px)';
        }
      });
    }, { rate: 0.08 });  // slower for backgrounds (premium feel)
    lerper.seed({ x: 0, y: 0 });

    hero.addEventListener('mousemove', function (e) {
      var r = hero.getBoundingClientRect();
      var dx = (e.clientX - r.left) / r.width  - 0.5;
      var dy = (e.clientY - r.top)  / r.height - 0.5;
      lerper.setTarget({ x: dx * -14, y: dy * -10 });
    });
    hero.addEventListener('mouseleave', function () {
      lerper.setTarget({ x: 0, y: 0 });
    });
  }

  /* ============================================================
     6. Pathway progression (Motion-library timeline)
     ============================================================ */
  function initPathwayProgression(M) {
    var pp = $('.pathway-progression'); if (!pp) return;
    var nodes = $$('.pp-node', pp);
    var track = pp.querySelector('.pathway-progression-track');
    M.inView(pp, function () {
      if (track) {
        M.animate(function (v) {
          track.style.setProperty('--track-progress', (v * 100).toFixed(1) + '%');
        }, { duration: 1.1, easing: EASE.outExpo });
      }
      nodes.forEach(function (n, i) {
        var feat = n.classList.contains('featured');
        M.animate(n,
          { opacity: 1,
            transform: ['translateY(20px) scale(0.85)',
                        'translateY(0) scale(' + (feat ? 1.04 : 1) + ')',
                        'translateY(0) scale(1)'] },
          { duration: 0.85, delay: 0.25 + i * 0.18, easing: EASE.outExpo, offset: [0, 0.7, 1] }
        );
      });
    }, { amount: 0.3 });
  }

  /* ============================================================
     7. Mega menu phrased reveal
     ============================================================ */
  function initMegaMenuPhrasing(M) {
    $$('.nav-item').forEach(function (item) {
      var dd = item.querySelector('.mega-menu'); if (!dd) return;
      var open = false;
      var obs = new MutationObserver(function () {
        var now = item.classList.contains('js-hover') || item.classList.contains('open');
        if (now && !open) {
          open = true;
          dd.querySelectorAll('.mega-banner, .mega-col, .mega-spotlight').forEach(function (el, i) {
            M.animate(el,
              { opacity: [0.2, 1], transform: ['translateY(10px)', 'translateY(0)'] },
              { duration: 0.32, delay: i * 0.04, easing: EASE.outQuart }
            );
          });
        } else if (!now) { open = false; }
      });
      obs.observe(item, { attributes: true, attributeFilter: ['class'] });
    });
  }

  /* ============================================================
     8. Three.js golden particle field — hero only
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
    var slides = hero.querySelector('.hero-slides');
    if (slides && slides.nextSibling) hero.insertBefore(canvas, slides.nextSibling);
    else hero.appendChild(canvas);

    function W() { return hero.clientWidth  || window.innerWidth; }
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
      vertexShader:
        'attribute float aSize; attribute float aPhase;' +
        'uniform float uTime; uniform float uPx;' +
        'varying float vAlpha;' +
        'void main(){' +
        '  vec4 mv = modelViewMatrix * vec4(position,1.0);' +
        '  float t = 0.5 + 0.5 * sin(uTime * 1.1 + aPhase);' +
        '  vAlpha = 0.30 + 0.45 * t;' +
        '  gl_PointSize = aSize * uPx * (220.0 / -mv.z) * (0.6 + 0.4 * t);' +
        '  gl_Position = projectionMatrix * mv;' +
        '}',
      fragmentShader:
        'varying float vAlpha;' +
        'void main(){' +
        '  vec2 c = gl_PointCoord - 0.5;' +
        '  float d = length(c);' +
        '  if (d > 0.5) discard;' +
        '  float a = smoothstep(0.5, 0.0, d) * vAlpha;' +
        '  gl_FragColor = vec4(0.98, 0.72, 0.32, a);' +
        '}',
      transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    });

    var points = new THREE.Points(geometry, material);
    scene.add(points);

    var mx = 0, my = 0;
    hero.addEventListener('mousemove', function (e) {
      var r = hero.getBoundingClientRect();
      mx = (e.clientX - r.left) / r.width  - 0.5;
      my = (e.clientY - r.top)  / r.height - 0.5;
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
