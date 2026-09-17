# Hero & nav — accessibility, layout and UX review

Reviewed against the attached working tree: `index.php` (lines 71–205), `partials/header.html`, `assets/css/main.css`, `assets/js/main.js` (`initHero`, ~line 518).

**Scope:** no visual overhaul. Same palette, type, imagery and layout language. Everything below is structural, behavioural or accessibility work.

**What I could and couldn't check.** I read the markup, CSS and JS, so the findings on tab order, ARIA, timing, target sizes and image loading are verified from source. I have not run the page on a real device or with a screen reader, so perceived performance and actual AT output should be confirmed once these land.

---

## Summary

The hero carousel has three Level A accessibility failures — it auto-rotates with no pause mechanism, ignores `prefers-reduced-motion`, and keeps the three invisible slides in the tab order, so a keyboard user tabs through six links they cannot see. Those outrank everything else on the page. The nav is in better shape than expected (skip link, `:focus-visible`, `focus-within` dropdowns all present) and needs correction rather than rebuilding: the `menubar` roles are actively misleading, one trigger is a link to nowhere, and two top-level items point at the same page.

Nothing here requires changing how the page looks.

---

## Findings

### [Severity 4] Hero auto-rotates with no way to stop it
**Where:** `initHero()` — `start()` is called unconditionally on the last line; the only pause is `mouseenter`/`mouseleave` on `#hero`.
**Why it matters:** WCAG 2.2.2 Pause, Stop, Hide (Level A) requires a mechanism to pause any automatically-moving content that runs longer than 5 seconds. The interval is 6500ms. A hover pause is not a mechanism for keyboard-only or touch users — on a phone there is no hover at all, so the carousel is unstoppable. The testimonials rotator on the same page already does this correctly.
**Fix:** Add a real pause/play button inside `.hero-nav`, tracking a `userPaused` flag that suppresses `start()`, exactly as `initHero`'s sibling rotator does at `main.js:775`. Also add `focusin`/`focusout` to the existing `mouseenter`/`mouseleave` pair.

### [Severity 4] Hero ignores `prefers-reduced-motion`
**Where:** `initHero()` — final `start();`. Compare `main.js:797`, where the testimonials rotator guards with `if (!matchMedia('(prefers-reduced-motion: reduce)').matches) start();`
**Why it matters:** WCAG 2.3.3, and a genuine accessibility harm for vestibular disorders — this is a full-viewport crossfade with an 8-second background `scale(1.06) → scale(1)` Ken Burns push on every slide. The CSS honours the preference in fifteen other places; the hero is the one thing on the page big enough to cause symptoms and it is the one place the guard is missing.
**Fix:** Wrap the final `start()` in the same guard the testimonials rotator uses. One line.

### [Severity 4] The three inactive slides stay in the tab order
**Where:** `main.css:434` — `.hero-slide{opacity:0;pointer-events:none}`. The `display:none` rule exists only at `main.css:1205`, inside a narrow-viewport block.
**Why it matters:** `pointer-events:none` stops the mouse but does not remove anything from the tab sequence or the accessibility tree. On desktop, a keyboard user tabbing off the nav lands on six invisible links (`View Pathways`, `Apply`, `Explore Rhythm`, `Crash Course`, `The MetaMusic Order`, `Free · Power Play`) with no visible focus ring anywhere on screen. A screen reader reads all four headlines, so the page announces four competing hero propositions.
**Fix:** Add `visibility:hidden` to `.hero-slide` and `visibility:visible` to `.hero-slide.active`. That kills focusability and AT exposure while preserving the opacity crossfade exactly as it is now. Then set `aria-hidden="true"` on inactive slides in `go()` for good measure.

### [Severity 3] Choosing a slide re-arms the timer that moves you off it
**Where:** `initHero()` — every dot and arrow handler calls `start()` after navigating.
**Why it matters:** A user who deliberately taps dot 3 to read slide 3 is moved to slide 4 in 6.5 seconds. Deliberate navigation is the clearest possible signal that the user wants to stay, and the code treats it as a reason to restart the clock. This is the single most irritating thing about the current hero and it is a three-line fix.
**Fix:** On any user-initiated navigation, set `userPaused = true` and call `stop()` instead of `start()`. Autoplay is a nudge for passive visitors; once someone steers, hand over control permanently.

### [Severity 3] Carousel dots are below the minimum target size
**Where:** `main.css:507` — `.hero-dot{width:10px;height:10px}`; `main.css:1143` raises it to 14px on narrow viewports.
**Why it matters:** WCAG 2.5.8 Target Size (Minimum) requires 24×24 CSS px. 10px fails by a wide margin, and 14px still fails — on mobile, where `main.css:524` and `main.css:1045` both hide the arrows, these are the *only* visible control. Swipe works (`touchstart`/`touchend` in `initHero`) but is undiscoverable.
**Fix:** Keep the dots visually 10px, but wrap each in a `<button>` with `padding` to a 24×24 (ideally 44×44) hit area and `background:none`. Nothing changes visually. This is the standard fix and it costs no design.

### [Severity 3] Dots use the tab pattern without implementing it
**Where:** `index.php:189–193` — `role="tablist"` with four `role="tab"` buttons.
**Why it matters:** `role="tab"` promises a controlled `tabpanel`, `aria-controls`, roving `tabindex`, and arrow-key navigation between tabs. None of the four exist. A screen-reader user hears "tab, 1 of 4," presses the arrow keys that pattern requires, and nothing happens. Incorrect ARIA is worse than no ARIA, because it sets an expectation the code cannot meet.
**Fix:** Drop the roles entirely. Four plain `<button>` elements with `aria-label="Go to slide 2"` and `aria-current="true"` on the active one is honest and needs no JS beyond what's already there. Wrap the hero in `role="region" aria-roledescription="carousel"` if you want the semantics named.

### [Severity 3] Slide changes are silent to screen readers
**Where:** `initHero()`'s `go()` — toggles classes and `aria-selected` only.
**Why it matters:** A sighted user sees the content change; a screen-reader user gets nothing, so the four propositions are invisible to them even after the tab-order fix above.
**Fix:** Add `<div aria-live="polite" class="sr-only">` inside `#hero` and write `"Slide 3 of 4: Practice with Focus"` into it in `go()`. Use `polite`, not `assertive` — the announcement must not interrupt.

### [Severity 3] Four hero background images, none of them discoverable
**Where:** `index.php` — each `.slide-bg` sets `background-image` inline, four Unsplash URLs at `?w=1920&q=80`.
**Why it matters:** CSS background images are invisible to the browser's preload scanner, so the hero image starts downloading only after the stylesheet is parsed — the worst case for LCP on the one element that defines it. All four are fetched, so a Lagos visitor on metered data pays for three images they may never see. There is also no `alt`, though these are decorative so that part is defensible.
**Fix:** Replace slide 1's div with a real `<img alt="" fetchpriority="high" decoding="async">`, and give slides 2–4 `loading="lazy"`. Add a `<link rel="preload" as="image">` for slide 1. Consider self-hosting rather than depending on Unsplash for your LCP element.

### [Severity 3] Two top-level nav items point at the same page
**Where:** `header.html` — `Schools` → `schools`, `Disciplines` → `schools`.
**Why it matters:** Two different labels for one destination is a direct information-scent failure: a visitor looking for piano lessons has no way to know which of the two to open, and the ones who guess wrong conclude the site has no such page. Nine top-level items is already at the upper limit; two of them being synonyms is spend with no return.
**Fix:** Merge into one. `Disciplines` is the better label for what a parent is actually searching for — they want "piano," not "Instrumental Intelligence School." Keep the four-schools structure inside the mega-menu, drop the duplicate trigger.

### [Severity 3] The Experiences trigger is a link to nowhere
**Where:** `header.html` — `<a href="#" class="nav-link" role="menuitem">Experiences</a>`.
**Why it matters:** Activating it jumps to the top of the page and appends `#` to the URL. Every other top-level item navigates somewhere real, so this one silently breaks the pattern, and it fails on the assumption that a mouse hover is available to reveal the dropdown.
**Fix:** Make it a `<button type="button" aria-expanded="false">`. That is what it is, it gets keyboard activation for free, and it stops claiming to be a destination.

### [Severity 3] Nav uses `menubar` roles on what is a list of links
**Where:** `header.html` — `<ul class="nav-links" role="menubar">` with `role="none"` on the `<li>`s, `role="menuitem"` on the links, `role="menu"` on the dropdowns.
**Why it matters:** `menubar` is for application menus and changes the expected keyboard contract entirely: arrow keys move between items, Tab exits the whole menubar as a single stop, and Escape closes. None of that is implemented. So a screen-reader user is told they're in an application menu and given a plain list of links instead. The dropdowns are also `role="menu"` while containing headings and multi-line descriptive links, which a `menu` is not allowed to hold.
**Fix:** Remove `role="menubar"`, `role="none"`, `role="menuitem"` and `role="menu"`. A `<nav><ul><li><a>` with a disclosure button is the correct pattern and already behaves the way people expect. This is deleting attributes, not adding code.

### [Severity 2] Mega-menus open on focus but nothing says so, and Escape doesn't close
**Where:** `main.css:198` — `.nav-item:focus-within > .nav-dropdown` works, so keyboard access does exist. But no trigger carries `aria-expanded`, and there's no Escape handler.
**Why it matters:** Credit where due — `focus-within` means the mega-menus are reachable, which is more than most sites manage. What's missing is the state announcement, so a screen-reader user has no idea a panel opened beneath them, and no way to dismiss it without tabbing through every item inside.
**Fix:** Add a separate disclosure `<button aria-expanded>` beside each parent link (the chevron is already there — make the chevron the button). Wire Escape to close and return focus to the trigger.

### [Severity 2] The floating Apply button is hidden from screen readers, and absent on mobile
**Where:** `index.php`/`header.html` — `<div class="floating-enroll" aria-hidden="true">` containing a real `<a href="apply">`; `main.css:1055` sets `display:none!important` on narrow viewports.
**Why it matters:** `aria-hidden="true"` on a container holding a focusable link is a known trap — the link stays tabbable but is unnamed, so a screen-reader user lands on "link" with no label. Separately, hiding it on mobile removes the persistent path to Apply on the device where the hero CTA scrolls out of view fastest.
**Fix:** Drop the `aria-hidden` and toggle `inert` or `visibility` instead. Keep a compact version on mobile — a persistent Apply affordance is the primary conversion path and the mobile menu should not be the only way to reach it.

### [Severity 2] No `aria-current` on the active nav item
**Where:** `main.js:509–513` — adds an `.active` class only.
**Why it matters:** The current page is signalled visually but not programmatically, so screen-reader users lose their place in the site.
**Fix:** Set `aria-current="page"` alongside the class. One line, in code that already runs.

### [Severity 1] Hero headline breaks mid-word
**Where:** `index.php:87–91` — `BECOME` / `A META-` / `MUSICIAN` split across three `<span>`s with `<br>`.
**Why it matters:** "META-" and "MUSICIAN" are separate text nodes, so screen readers and text-to-speech read a hyphen and a pause mid-word. The hard breaks also mean the headline can't reflow, which is what produces the awkward wraps at intermediate widths.
**Fix:** Keep the three visual lines, but mark the word as one unit: `<span class="line">A META&shy;</span><span class="line">MUSICIAN</span>` reads correctly, or wrap in `aria-label="Become a meta-musician"` on the `<h1>`.

---

## What's working

Worth stating so none of it gets "fixed":

- **The skip link and focus system are already right.** `main.css:82` gives a 3px gold focus ring with a 6px dark halo — that's visible on both light cards and photographic backgrounds, which is harder than it looks. `main.css:148` has a proper off-screen skip link.
- **`prefers-reduced-motion` is honoured in fifteen places**, including the footer wordmark clip, the card glow, the equaliser and the frame hovers. The hero is the exception, not the rule.
- **The mega-menus are keyboard-reachable** via `focus-within`, and the mobile menu uses real disclosure buttons with `aria-expanded` and `aria-controls` — correct, and better than the desktop nav it mirrors.
- **Touch swipe on the hero already works**, and `visibilitychange` stops the timer on a backgrounded tab.
- **The mobile quick-tile grid** puts Apply first at a genuinely thumb-sized target. That's the right instinct.

---

## Two layout questions worth deciding, not just fixing

**Four slides, four different primary CTAs.** Slide 1 says Apply, slide 2 says View Pathways, slide 3 says Explore Rhythm, slide 4 says The MetaMusic Order. Carousel engagement drops steeply after the first slide, so in practice slides 2–4 are seen by a small fraction of visitors while diluting the one action you want. The structural fix that keeps all four slides: let the rotation carry the *story*, and keep Apply persistent below it, outside the rotation. Then no proposition depends on a slide someone never sees.

**The announcement bar has no dismiss.** It sits above a sticky nav and permanently consumes vertical space — on a 390×844 phone, the bar plus nav takes a real bite out of the first screen before the headline starts. A dismiss button with the state in `localStorage` returns that space to returning visitors without losing the message for new ones.

---

## Suggested order

1. The three Severity 4 hero items — roughly a dozen lines total, and they clear three Level A failures.
2. Timer-restart behaviour and dot target size — small, and they're the two things people actually feel.
3. Delete the `menubar` roles, fix the `href="#"` trigger, merge Schools and Disciplines.
4. Image loading and the live region.
5. The two layout questions above, once you've decided them.

Nothing in 1–4 changes a colour, a font or a spacing value.
