/* ============================================================
   MAM Academy — Motion (minimalist build)
   ─────────────────────────────────────────────────────────────
   Philosophy: do less, do it well. The most premium gesture in
   motion design is what you choose NOT to do.

   Scope:
     1. Scroll-progress reed (1px, monochrome)
     2. Pathway progression timeline (a meaningful UI moment)
     3. Mega-menu sequenced reveal (considered, brief)
     4. A single, slow-rotating wireframe icosahedron in the hero
        — one sculptural element, low opacity, low poly

   Out of scope (intentionally):
     • Card tilt / 3D rotation
     • Magnetic buttons
     • Floating musical notes / particle fields
     • Parallax of any kind
     • Click ripples or press effects

   Loaded lazily via main.js. Site looks correct without this.
   ============================================================ */
(function () {
  'use strict';

  var RM    = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var SMALL = matchMedia('(max-width: 768px)').matches;

  var MOTION_URL = 'https://cdn.jsdelivr.net/npm/motion@10.18.0/dist/motion.min.js';
  var THREE_URL  = 'https://cdn.jsdelivr.net/npm/three@0.158.0/build/three.min.js';

  function $(s, c)  { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }

  function loadScript(url) {
    return new Promise(function (r) {
      if (document.querySelector('script[src="' + url + '"]')) return setTimeout(r, 30);
      var s = document.createElement('script');
      s.src = url; s.async = true;
      s.onload = function () { r(); };
      s.onerror = function () { r(); };
      document.head.appendChild(s);
    });
  }

  function boot() {
    if (RM) { document.documentElement.classList.add('motion-reduced'); return; }
    document.documentElement.classList.add('motion-on');

    initScrollProgress();

    loadScript(MOTION_URL).then(function () {
      var M = window.Motion;
      if (!M) return;
      initPathwayProgression(M);
      initMegaMenuPhrasing(M);
    });

    // One sculptural ambient piece — only where it belongs, only when capable
    if ($('#hero') && canRunThree()) {
      loadScript(THREE_URL).then(function () {
        if (window.THREE) initHeroWireframe(window.THREE);
      });
    }
  }

  /* ============================================================
     Scroll progress — 1px monochrome reed at the top of the page
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
     Pathway progression — track unspools, four nodes resolve.
     A meaningful UI moment; one of the few places where motion
     serves a real informational purpose (timeline metaphor).
     ============================================================ */
  function initPathwayProgression(M) {
    var pp = $('.pathway-progression'); if (!pp) return;
    var nodes = $$('.pp-node', pp);
    var track = pp.querySelector('.pathway-progression-track');
    M.inView(pp, function () {
      if (track) {
        M.animate(function (v) {
          track.style.setProperty('--track-progress', (v * 100).toFixed(1) + '%');
        }, { duration: 1.0, easing: [0.16, 1, 0.30, 1] });
      }
      nodes.forEach(function (n, i) {
        M.animate(n,
          { opacity: 1, transform: ['translateY(12px)', 'translateY(0)'] },
          { duration: 0.6, delay: 0.20 + i * 0.12, easing: [0.16, 1, 0.30, 1] }
        );
      });
    }, { amount: 0.3 });
  }

  /* ============================================================
     Mega menu — banner, columns, spotlight resolve in sequence.
     Brief (under 400ms total) so it never feels slow.
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
              { opacity: [0.3, 1], transform: ['translateY(6px)', 'translateY(0)'] },
              { duration: 0.28, delay: i * 0.04, easing: [0.16, 1, 0.30, 1] }
            );
          });
        } else if (!now) { open = false; }
      });
      obs.observe(item, { attributes: true, attributeFilter: ['class'] });
    });
  }

  /* ============================================================
     Three.js — one sculptural object. A low-poly wireframe
     icosahedron rotates slowly in the hero. No interaction,
     no parallax, no particles. The visual equivalent of a single
     held tone.
     ============================================================ */
  function canRunThree() {
    if (SMALL) return false; // mobile gets a clean static hero
    try {
      var c = document.createElement('canvas');
      var gl = c.getContext('webgl2') || c.getContext('webgl');
      if (!gl) return false;
      if (navigator.deviceMemory && navigator.deviceMemory < 2) return false;
      return true;
    } catch (e) { return false; }
  }

  function initHeroWireframe(THREE) {
    var hero = $('#hero'); if (!hero) return;
    if (hero.querySelector('.hero-three-canvas')) return;

    var canvas = document.createElement('canvas');
    canvas.className = 'hero-three-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    // Insert between slide BG and slide overlay so the warm gradient
    // overlay tints the wireframe the way it tints the image — one scene.
    var slides = hero.querySelector('.hero-slides');
    if (slides && slides.nextSibling) hero.insertBefore(canvas, slides.nextSibling);
    else hero.appendChild(canvas);

    var SIZE = 520; // logical canvas size; positioned via CSS

    var renderer = new THREE.WebGLRenderer({
      canvas: canvas,
      alpha: true,
      antialias: true,
      powerPreference: 'low-power'
    });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
    renderer.setSize(SIZE, SIZE, false);

    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(40, 1, 0.1, 100);
    camera.position.z = 5;

    // Low-poly icosahedron — sculptural. detail=1 is ~80 faces.
    var geo = new THREE.IcosahedronGeometry(1.55, 1);
    var wireGeo = new THREE.WireframeGeometry(geo);
    var mat = new THREE.LineBasicMaterial({
      color: 0xFBBF24,        // gold-bright
      transparent: true,
      opacity: 0.32,
    });
    var wireframe = new THREE.LineSegments(wireGeo, mat);
    scene.add(wireframe);

    // A second, slightly smaller copy at a different rotation axis adds
    // a sense of depth without becoming busy.
    var innerGeo = new THREE.IcosahedronGeometry(0.85, 0);
    var innerWire = new THREE.WireframeGeometry(innerGeo);
    var innerMat = new THREE.LineBasicMaterial({
      color: 0xE08A1A,
      transparent: true,
      opacity: 0.22,
    });
    var innerMesh = new THREE.LineSegments(innerWire, innerMat);
    scene.add(innerMesh);

    var visible = true;
    var io = new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting;
      if (visible && !rafId) rafId = requestAnimationFrame(loop);
    });
    io.observe(hero);

    var rafId = null;
    function loop(t) {
      if (!visible) { rafId = null; return; }
      rafId = requestAnimationFrame(loop);
      var s = (t || 0) * 0.0001;
      wireframe.rotation.x = s * 0.7;
      wireframe.rotation.y = s * 1.0;
      innerMesh.rotation.x = -s * 1.4;
      innerMesh.rotation.y = -s * 0.6;
      renderer.render(scene, camera);
    }
    rafId = requestAnimationFrame(loop);

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        visible = false;
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
      } else if (hero.getBoundingClientRect().top < window.innerHeight) {
        visible = true; if (!rafId) rafId = requestAnimationFrame(loop);
      }
    });

    // Fade in via CSS class once the first render lands, so we never
    // show a popping wireframe.
    requestAnimationFrame(function () { canvas.classList.add('fx-ready'); });
  }

  /* ─────────────  Entry  ───────────── */
  if (document.querySelector('#navbar')) boot();
  else {
    document.addEventListener('mam:ready', boot, { once: true });
    setTimeout(function () {
      if (!document.documentElement.classList.contains('motion-on') &&
          !document.documentElement.classList.contains('motion-reduced')) boot();
    }, 3000);
  }
})();
