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

    // Each effect is independent — one failing must never block the rest.
    [initScrollProgress, initFloatingNotes, initCardFX, initMagnetic, loadEmojiRuntimes]
      .forEach(function (fn) { try { fn(); } catch (e) { /* non-fatal */ } });

    loadScript(MOTION_URL).then(function () {
      var M = window.Motion;
      // If the choreography library is blocked/offline, never leave gated
      // content hidden — reveal the pathway timeline in its final state.
      if (!M) { revealPathway(); return; }
      initPathwayProgression(M);
      initMegaMenuPhrasing(M);
    });

    // Safety net: if the timeline is still hidden shortly after boot
    // (library slow or inView never fires above the fold), reveal it.
    setTimeout(revealPathway, 2600);

    // One sculptural ambient piece — only where it belongs, only when capable
    if ($('#hero') && canRunThree()) {
      loadScript(THREE_URL).then(function () {
        if (window.THREE) initHeroWireframe(window.THREE);
      });
    }
  }

  /* Reveal the pathway-progression nodes/track if motion can't (no FOUC,
     no hidden content). Idempotent. */
  function revealPathway() {
    var pp = $('.pathway-progression'); if (!pp) return;
    $$('.pp-node', pp).forEach(function (n) {
      if (+getComputedStyle(n).opacity < 0.99) {
        n.style.opacity = '1';
        n.style.transform = 'none';
      }
    });
    var track = pp.querySelector('.pathway-progression-track');
    if (track) track.style.setProperty('--track-progress', '100%');
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

  /* ============================================================
     Floating musical glyphs — the signature ambient drift in the
     hero. Pure CSS animation; JS only seeds randomised instances.
     ============================================================ */
  function initFloatingNotes() {
    var host = $('#hero') || $('.page-hero') || $('.order-hero') || $('.pp-hero');
    if (!host) return;
    if (host.querySelector('.floating-notes')) return;
    var layer = document.createElement('div');
    layer.className = 'floating-notes';
    layer.setAttribute('aria-hidden', 'true');
    var glyphs = ['♪', '♫', '♬', '♩', '𝄞'];
    var count = SMALL ? 3 : 6;
    for (var i = 0; i < count; i++) {
      var n = document.createElement('span');
      n.className = 'floating-note';
      n.textContent = glyphs[(Math.random() * glyphs.length) | 0];
      var size = 0.9 + Math.random() * 2.4;            // rem
      var dur  = 14 + Math.random() * 13;              // s
      n.style.left = (Math.random() * 100).toFixed(2) + '%';
      n.style.fontSize = size.toFixed(2) + 'rem';
      n.style.animationDuration = dur.toFixed(1) + 's';
      n.style.animationDelay = (-Math.random() * dur).toFixed(1) + 's';
      n.style.setProperty('--drift', ((Math.random() * 160 - 80) | 0) + 'px');
      n.style.setProperty('--spin', ((Math.random() * 220 - 110) | 0) + 'deg');
      layer.appendChild(n);
    }
    // The layer is position:absolute/inset:0 (pointer-events:none, screen
    // blend) so paint order is irrelevant — append safely.
    host.appendChild(layer);
  }

  /* ============================================================
     Card field — cursor-tracking spotlight + restrained 3D tilt.
     Fine-pointer only (never on touch). Reduced motion → skipped
     entirely because boot() returns before this runs.
     ============================================================ */
  var CARD_SEL = '.pathway-card,.school-card,.program-card,.unique-card,.mode-card,.faculty-card,.how-step,.next-step-card,.method-step';
  function initCardFX() {
    if (!matchMedia('(pointer:fine)').matches) return;
    $$(CARD_SEL).forEach(function (card) {
      if (card.__fx) return; card.__fx = 1;
      var cs = getComputedStyle(card);
      if (cs.position === 'static') card.style.position = 'relative';
      var spot = document.createElement('span');
      spot.className = 'fx-spot';
      card.appendChild(spot);
      var raf = null, rect = null, mx = 0, my = 0;
      function apply() {
        raf = null;
        var px = mx / rect.width, py = my / rect.height;          // 0..1
        spot.style.setProperty('--spot-x', (px * 100).toFixed(1) + '%');
        spot.style.setProperty('--spot-y', (py * 100).toFixed(1) + '%');
        var rx = (0.5 - py) * 6, ry = (px - 0.5) * 6;             // deg, ±6
        card.style.transform = 'perspective(900px) rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + ry.toFixed(2) + 'deg) translateY(-6px)';
      }
      card.addEventListener('pointermove', function (e) {
        if (e.pointerType && e.pointerType !== 'mouse') return;
        rect = card.getBoundingClientRect();
        mx = e.clientX - rect.left; my = e.clientY - rect.top;
        if (!raf) raf = requestAnimationFrame(apply);
      });
      card.addEventListener('pointerleave', function () {
        if (raf) { cancelAnimationFrame(raf); raf = null; }
        card.style.transform = '';
      });
    });
  }

  /* ============================================================
     Magnetic CTAs — primary buttons ease toward the cursor within
     a small radius. Premium, subtle, fine-pointer only.
     ============================================================ */
  function initMagnetic() {
    if (!matchMedia('(pointer:fine)').matches) return;
    $$('.btn-primary,.btn-nav-cta,.btn-large,.mobile-nav-cta,.floating-enroll-btn').forEach(function (btn) {
      if (btn.__mag) return; btn.__mag = 1;
      var raf = null, tx = 0, ty = 0;
      btn.style.willChange = 'transform';
      btn.addEventListener('pointermove', function (e) {
        var r = btn.getBoundingClientRect();
        tx = (e.clientX - (r.left + r.width / 2)) * 0.22;
        ty = (e.clientY - (r.top + r.height / 2)) * 0.30;
        if (!raf) raf = requestAnimationFrame(function () {
          raf = null; btn.style.transform = 'translate(' + tx.toFixed(1) + 'px,' + ty.toFixed(1) + 'px)';
        });
      });
      btn.addEventListener('pointerleave', function () {
        if (raf) { cancelAnimationFrame(raf); raf = null; }
        btn.style.transform = '';
      });
    });
  }

  /* ============================================================
     Animated-emoji / line-icon runtimes. Loaded lazily and with
     graceful failure — the page is fully functional without them
     (custom elements simply stay blank; CSS reserves their box).
     ============================================================ */
  function loadEmojiRuntimes() {
    if (document.querySelector('iconify-icon') && !window.customElements.get('iconify-icon')) {
      loadScript('https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js');
    }
    // dotlottie-wc deliberately omitted — its WASM loader uses eval() which
    // our CSP forbids. Lingering <dotlottie-wc> tags degrade to invisible
    // (no display rule), so callers should swap them for native emoji.
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
