# Patches — hero & nav

Copy-paste ready against the attached tree. Each block gives the exact current source and its replacement. Nothing here changes a colour, a font size or a spacing value.

Order matches the review. Steps 1–3 are the three Level A failures and are worth landing together.

---

## 1 · `assets/css/main.css` — take inactive slides out of the tab order

**Line 434–435, currently:**

```css
.hero-slide{position:absolute;inset:0;opacity:0;transition:opacity 0.9s var(--ease);pointer-events:none}
.hero-slide.active{opacity:1;pointer-events:auto}
```

**Replace with:**

```css
.hero-slide{position:absolute;inset:0;opacity:0;visibility:hidden;transition:opacity 0.9s var(--ease),visibility 0.9s var(--ease);pointer-events:none}
.hero-slide.active{opacity:1;visibility:visible;pointer-events:auto}
```

`visibility:hidden` removes the subtree from the tab order and the accessibility tree; `pointer-events:none` never did. Because `visibility` is a transitionable discrete property, listing it alongside `opacity` keeps the outgoing slide visible for the full 0.9s fade — the crossfade is unchanged.

---

## 2 · `assets/css/main.css` — dots to a 24px hit area, visuals identical

**Line 507–509, currently:**

```css
.hero-dot{width:10px;height:10px;border-radius:50%;background:rgba(255,255,255,0.55);transition:all 0.3s var(--ease);border:none;cursor:pointer}
.hero-dot.active{width:30px;border-radius:5px;background:var(--gold-bright)}
.hero-dot:hover{background:rgba(255,255,255,0.85)}
```

**Replace with:**

```css
/* The button is the target; ::after is the dot. Visual size unchanged,
   hit area raised to the 24px WCAG 2.5.8 minimum. */
.hero-dot{width:24px;height:24px;padding:0;border:none;background:none;cursor:pointer;display:grid;place-items:center;transition:width 0.3s var(--ease)}
.hero-dot::after{content:'';display:block;width:10px;height:10px;border-radius:50%;background:rgba(255,255,255,0.55);transition:all 0.3s var(--ease)}
.hero-dot.active{width:44px}
.hero-dot.active::after{width:30px;border-radius:5px;background:var(--gold-bright)}
.hero-dot:hover::after{background:rgba(255,255,255,0.85)}
```

**Line 1143–1144, currently:**

```css
  .hero-dot{width:14px;height:14px}
  .hero-dot.active{width:32px;height:14px;border-radius:7px}
```

**Replace with:**

```css
  .hero-dot{width:44px;height:44px}
  .hero-dot.active{width:52px}
  .hero-dot::after{width:14px;height:14px}
  .hero-dot.active::after{width:32px;height:14px;border-radius:7px}
```

`.hero-nav`'s `gap:8px` still holds, so the row reads the same — the targets just overlap the gap now instead of sitting inside it.

---

## 3 · `assets/css/main.css` — style the new pause control

**Add immediately after the `.hero-nav` rule at line 506:**

```css
/* Pause/play for the hero rotation — WCAG 2.2.2 needs a real, visible control,
   not a hover pause. Matches .hero-arrow's treatment at a smaller size. */
.hero-pause{width:32px;height:32px;margin-left:4px;border-radius:50%;background:rgba(0,0,0,0.5);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.25);color:#fff;font-size:0.66rem;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background 0.2s var(--ease),border-color 0.2s var(--ease)}
.hero-pause:hover{background:rgba(224,138,26,0.75);border-color:var(--gold)}
@media (max-width:640px){.hero-pause{width:44px;height:44px;font-size:0.8rem}}
```

---

## 4 · `index.php` — dots markup, pause button, live region

**Lines 189–193, currently:**

```html
  <div class="hero-nav" role="tablist" aria-label="Slide navigation">
    <button class="hero-dot active" role="tab" aria-selected="true" aria-label="Slide 1" data-dot="0"></button>
    <button class="hero-dot" role="tab" aria-selected="false" aria-label="Slide 2" data-dot="1"></button>
    <button class="hero-dot" role="tab" aria-selected="false" aria-label="Slide 3" data-dot="2"></button>
    <button class="hero-dot" role="tab" aria-selected="false" aria-label="Slide 4" data-dot="3"></button>
  </div>
```

**Replace with:**

```html
  <div class="hero-nav" aria-label="Slide navigation">
    <button type="button" class="hero-dot active" aria-current="true" aria-label="Go to slide 1" data-dot="0"></button>
    <button type="button" class="hero-dot" aria-label="Go to slide 2" data-dot="1"></button>
    <button type="button" class="hero-dot" aria-label="Go to slide 3" data-dot="2"></button>
    <button type="button" class="hero-dot" aria-label="Go to slide 4" data-dot="3"></button>
    <button type="button" class="hero-pause" id="heroPause" aria-pressed="false" aria-label="Pause the slideshow"><i class="fa-solid fa-pause" aria-hidden="true"></i></button>
  </div>

  <p class="sr-only" id="heroLive" aria-live="polite" aria-atomic="true"></p>
```

The `tablist`/`tab` roles come out: they promise a controlled `tabpanel`, `aria-controls` and roving `tabindex`, none of which exist. `aria-current` is the honest signal for "this is the one you're on."

**Line 72, currently:**

```html
<section id="hero" aria-label="Hero carousel">
```

**Replace with:**

```html
<section id="hero" role="region" aria-roledescription="carousel" aria-label="MAM Academy highlights">
```

**On each of the four `<article class="hero-slide">` elements**, add a title the script can announce, and hide the three inactive ones from assistive tech at parse time:

```html
<article class="hero-slide active" data-slide="0" data-slide-title="Become a Meta-Musician">
<article class="hero-slide" data-slide="1" data-slide-title="Music Changes" aria-hidden="true">
<article class="hero-slide" data-slide="2" data-slide-title="Practice with Focus" aria-hidden="true">
<article class="hero-slide" data-slide="3" data-slide-title="Music Shapes You" aria-hidden="true">
```

Then **drop `role="region"` and the `aria-label` from the four `.hero-card` divs** — four nested regions each announcing "Slide N:" is what currently makes a screen reader read the hero four times over. The `data-slide-title` above replaces them.

**Slide 1's headline, lines 87–91, currently:**

```html
          <h1 class="card-headline xl">
            <span class="text-red">BECOME</span><br>
            <span class="text-dark">A META-</span><br>
            <span class="text-red">MUSICIAN</span>
          </h1>
```

**Replace with:**

```html
          <h1 class="card-headline xl" aria-label="Become a Meta-Musician">
            <span class="text-red">BECOME</span><br>
            <span class="text-dark">A META-</span><br>
            <span class="text-red">MUSICIAN</span>
          </h1>
```

Three visual lines unchanged; the hyphen and mid-word pause stop being read aloud.

---

## 5 · `assets/js/main.js` — replace `initHero()` entirely

**Replace the whole function (lines 518–551) with:**

```js
  function initHero() {
    var slides = $$('.hero-slide');
    var dots   = $$('.hero-dot');
    if (!slides.length) return;

    // Reduced motion means the rotation starts paused, not that the control
    // disappears — the visitor can still opt in.
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var current = 0, timer = null, userPaused = !!reduce;
    var live   = $('#heroLive');
    var toggle = $('#heroPause');

    function go(i, announce) {
      slides[current].classList.remove('active');
      slides[current].setAttribute('aria-hidden', 'true');
      if (dots[current]) { dots[current].classList.remove('active'); dots[current].removeAttribute('aria-current'); }

      current = (i + slides.length) % slides.length;

      slides[current].classList.add('active');
      slides[current].removeAttribute('aria-hidden');
      if (dots[current]) { dots[current].classList.add('active'); dots[current].setAttribute('aria-current', 'true'); }

      if (announce && live) {
        live.textContent = 'Slide ' + (current + 1) + ' of ' + slides.length + ': ' +
          (slides[current].getAttribute('data-slide-title') || '');
      }
    }

    function next() { go(current + 1, true); }
    function prev() { go(current - 1, true); }
    function stop()  { if (timer) { clearInterval(timer); timer = null; } }
    function start() { if (userPaused) return; stop(); timer = setInterval(function () { go(current + 1, false); }, 6500); }

    function syncToggle() {
      if (!toggle) return;
      toggle.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
      toggle.setAttribute('aria-label', userPaused ? 'Play the slideshow' : 'Pause the slideshow');
      toggle.innerHTML = '<i class="fa-solid fa-' + (userPaused ? 'play' : 'pause') + '" aria-hidden="true"></i>';
    }

    // Deliberate navigation is the clearest signal that the visitor wants to
    // stay. Hand over control for the rest of the session.
    function steer(fn) {
      return function () { userPaused = true; stop(); syncToggle(); fn(); };
    }

    on($('#nextArrow'), 'click', steer(next));
    on($('#prevArrow'), 'click', steer(prev));
    dots.forEach(function (d) {
      on(d, 'click', steer(function () { go(parseInt(d.getAttribute('data-dot'), 10), true); }));
    });

    on(toggle, 'click', function () {
      userPaused = !userPaused;
      syncToggle();
      if (userPaused) { stop(); } else { start(); }
    });

    on($('.hero-nav'), 'keydown', function (e) {
      if (e.key === 'ArrowRight')     { e.preventDefault(); steer(next)(); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); steer(prev)(); }
    });

    var hero = $('#hero');
    if (hero) {
      var tx0 = 0, ty0 = 0;
      on(hero, 'touchstart', function (e) { tx0 = e.changedTouches[0].clientX; ty0 = e.changedTouches[0].clientY; }, { passive: true });
      on(hero, 'touchend', function (e) {
        var dx = e.changedTouches[0].clientX - tx0;
        var dy = e.changedTouches[0].clientY - ty0;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) { steer(dx < 0 ? next : prev)(); }
      }, { passive: true });
      on(hero, 'mouseenter', stop);
      on(hero, 'mouseleave', start);
      on(hero, 'focusin',  stop);
      on(hero, 'focusout', start);
    }
    on(document, 'visibilitychange', function () { if (document.hidden) { stop(); } else { start(); } });

    syncToggle();
    start();
  }
```

Five behavioural changes, all of them the point of the exercise:

- `start()` returns early when paused, so pausing actually holds through `mouseleave`, `focusout` and tab refocus.
- `reduce` seeds `userPaused`, so reduced-motion visitors get a still hero and a play button rather than nothing.
- `steer()` stops autoplay permanently on any dot, arrow, swipe or arrow-key input.
- `focusin`/`focusout` join the hover pair, so keyboard users get the same courtesy the mouse already got.
- Autoplay advances with `announce = false`, so the live region only speaks for navigation the visitor asked for. An `aria-live` firing every 6.5s unprompted is worse than silence.

---

## 6 · `index.php` — hero images the preload scanner can see

**Slide 1, line 77, currently:**

```html
      <div class="slide-bg" style="background-image:url('https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1920&q=80')"></div>
```

**Replace with:**

```html
      <img class="slide-bg" src="https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1920&q=80" alt="" fetchpriority="high" decoding="async">
```

**Slides 2–4** take the same shape with `loading="lazy"` and `fetchpriority="low"` instead of `fetchpriority="high"`.

**Then in `main.css`, line 436, currently:**

```css
.hero-slide .slide-bg{position:absolute;inset:0;background-size:cover;background-position:center top;background-color:var(--bg-surface);transform:scale(1.06);transition:transform 8s ease-out}
```

**Replace with:**

```css
.hero-slide .slide-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center top;background-color:var(--bg-surface);transform:scale(1.06);transition:transform 8s ease-out}
```

**And add to `<head>`:**

```html
<link rel="preload" as="image" href="https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=1920&q=80" fetchpriority="high">
```

Worth saying plainly: your LCP element currently depends on a third party you don't control. Self-hosting these four is the larger win, and it's the one change here I'd push for beyond accessibility.

---

## 7 · `partials/header.html` — delete the menubar roles

Four deletions, no additions:

- `<ul class="nav-links" role="menubar">` → `<ul class="nav-links">`
- every `<li class="nav-item" role="none">` → `<li class="nav-item">`
- every `class="nav-link" role="menuitem"` → `class="nav-link"`
- every `<div class="nav-dropdown mega-menu" role="menu">` → `<div class="nav-dropdown mega-menu">`

`menubar` sets an application-menu keyboard contract — arrow keys move between items, Tab exits the whole bar as one stop — that the code doesn't implement. And `role="menu"` may only contain menu items, while these panels hold `<h5>` headings and two-line descriptive links. Removing the roles leaves a `nav > ul > li > a`, which is what it is and behaves how people expect.

---

## 8 · `partials/header.html` — the Experiences trigger

**Currently:**

```html
        <a href="#" class="nav-link" role="menuitem">Experiences <i class="fa-solid fa-chevron-down chev" aria-hidden="true"></i></a>
```

**Replace with:**

```html
        <button type="button" class="nav-link nav-link-disclosure" aria-expanded="false">Experiences <i class="fa-solid fa-chevron-down chev" aria-hidden="true"></i></button>
```

**And add to `main.css` beside the `.nav-link` rule at line 190:**

```css
/* The chevron glyph is 9px, so padding alone cannot reach the 24px target
   floor — give the button an explicit box. The glyph size is independent of
   it, so nothing changes visually. */
.nav-link-disclosure{font:inherit;color:inherit;background:none;border:none;cursor:pointer;min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;padding:0}
```

`href="#"` jumps to the top of the page and appends a bare hash. Every sibling goes somewhere real; this one only ever existed to be hovered.

**Applies to every disclosure chevron, not just this one.** Where a parent item keeps its link and gains a separate chevron button (Disciplines, About), that chevron is the *only* way to open the mega-menu — the adjacent `<a>` navigates away. So it has to clear 2.5.8 on its own, and 4px of horizontal padding around a 9px glyph measures 17px. Use the rule above on all of them.

---

## 9 · `partials/header.html` — merge Schools and Disciplines

Both currently resolve to `schools`. Two labels for one destination means a parent hunting for piano has a coin-flip and no feedback if they call it wrong.

Keep the **Disciplines** trigger and its mega-menu — "Piano & Keyboard" is what people search for, not "Instrumental Intelligence." Delete the separate **Schools** trigger and fold its four school links into the Disciplines panel as a third column headed `The four schools`, or into the existing About panel where `Four Signature Schools` already lives.

That takes the top level from nine items to eight, and every remaining one is a distinct destination.

---

## 10 · `assets/js/main.js` — `aria-current` on the active nav item

**Line 509–513, currently:**

```js
    $$('.nav-link, .mobile-nav-link, .mobile-nav-sub-link').forEach(function (a) {
      var href = a.getAttribute('href');
      if (!href) return;
      if (norm(href) === path) a.classList.add('active');
    });
```

**Replace the last line with:**

```js
      if (norm(href) === path) { a.classList.add('active'); a.setAttribute('aria-current', 'page'); }
```

---

## 11 · `header.html` + `main.css` — the floating Apply button

**Currently:**

```html
<div class="floating-enroll" id="floatingEnroll" aria-hidden="true">
```

**Replace with:**

```html
<div class="floating-enroll" id="floatingEnroll" inert>
```

`aria-hidden="true"` on a container wrapping a focusable link is a known trap: the link stays tabbable but loses its name, so a screen-reader user lands on an unlabelled "link." `inert` removes both focusability and AT exposure together, and whichever JS toggles `aria-hidden` should toggle the `inert` attribute instead.

**Then at `main.css:1055`, currently:**

```css
  .floating-enroll{display:none!important}
```

**Replace with:**

```css
  /* Keep the persistent Apply path on the device where the hero CTA leaves
     the viewport soonest — just make it compact. */
  .floating-enroll{bottom:16px;right:16px}
  .floating-enroll-btn{padding:12px 18px;font-size:0.86rem;min-height:44px}
```

If it's being hidden because it collides with the cookie dock, the fix is to dock it rather than delete it — `main.js` already has a `window.mamDock` for exactly this stacking problem.

---

## 12 · `assets/css/main.css` — landscape phones lose every control

**Line 1201–1209** hides `.hero-nav` under `(max-height:560px) and (orientation:landscape)`, and `.hero-arrow` is already hidden at line 1045. In that orientation the carousel keeps auto-advancing with no dot, no arrow and no pause button — only an undiscoverable swipe.

**Change line 1208 from:**

```css
  .scroll-cue,.hero-nav{display:none}
```

**to:**

```css
  .scroll-cue{display:none}
  .hero-nav{bottom:10px}
```

Once step 4 puts the pause button inside `.hero-nav`, keeping that row visible is what makes the 2.2.2 fix hold in every orientation. Hiding the scroll cue is fine; hiding the controls is the thing that breaks it.

---

## What I'd verify after landing these

- **Tab once from the skip link on desktop.** Focus should reach nav, then slide 1's two CTAs, then the four dots and the pause button — and nothing invisible. That single pass confirms steps 1 and 4 together.
- **Set the OS reduced-motion preference.** The hero should be still on load with a play button showing.
- **Tap a dot on a phone and wait fifteen seconds.** It should not move.
- **Screen reader on the hero.** One headline, not four.
- **Lighthouse before and after step 6**, to see whether the LCP change is worth self-hosting the images.
