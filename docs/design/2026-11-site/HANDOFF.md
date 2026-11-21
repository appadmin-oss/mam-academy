# MAM Academy — Website Design Handoff

Version 1.0 · 20 November 2026 · Living copy: https://claude.ai/artifact/A6ZhRAvmXMnT5kPJCevZPq · Design canvas: https://claude.ai/artifact/L699EFC2bV8Fc25D2PBH1u

This package specifies the redesigned MAM Academy website: six pages on one shared design system, ready to build. The design canvas is the visual source of truth. This document, the files in `specs/` and the page sources in `source/pages/` explain it.

---

## 1. Overview

| Page | Source file | Purpose | Model |
| --- | --- | --- | --- |
| Home | `source/pages/Main.dc.html` | Explain MAM in one scroll and move people to apply | Stanford (structure), claude.com (nav, motion) |
| Programmes & fees | `Programmes.dc.html` | Compare programmes, see levels, estimate a fee | Stripe pricing (table, estimator) |
| Apply | `Apply.dc.html` | Complete an application in about 5 minutes | GOV.UK question pages, Stripe Checkout |
| About | `About.dc.html` | Who MAM is, how it teaches, who teaches | Stanford "About" page |
| MAM Auditer | `Auditer.dc.html` | Explain the entry assessment | Editorial + one interactive demo |
| Life at MAM | `Life.dc.html` | Community: Power Play, showcases, intensives | Editorial |

**Principles**

- One action per screen. Apply is always one click away; the secondary action is "Book an assessment".
- Serif for voice, sans for work. Cormorant Garamond for headings; Inter for everything read or tapped.
- Quiet, purposeful motion. Content rises as it enters; nothing loops for decoration.
- Standard first. Semantic HTML, WCAG 2.2 AA, works without scroll-animation support.
- No location-specific wording; no invented statistics or testimonials.

**Status:** approved for Home, Programmes, Apply, About and the effects layer. Photos and the intake date are placeholders (section 9).

---

## 2. Design tokens

Machine-readable versions: `specs/design-tokens.json` and `specs/tokens.css`.

### Colour

| Token | Hex | Use | Contrast |
| --- | --- | --- | --- |
| ivory | #FAF8F4 | Page background | — |
| sand | #F3EEE6 | Alternate section bands, estimator wells | — |
| line | #ECE5DA / #E3DACD | Card borders, dividers, hairlines | Decorative |
| line-strong | #CFC3B3 / #D6CCBE | Outlined buttons, timeline rails | Decorative |
| white | #FFFFFF | Cards, inputs, tiles | — |
| ink | #211A13 | Headings, body text, dark buttons | 16.2:1 on ivory |
| ink-soft | #3E342B | Long-form paragraphs (About) | 11.4:1 on ivory |
| muted | #574B40 | Secondary text, captions | 8.0:1 on ivory |
| espresso | #2A1E14 | Dark sections (Admissions) | — |
| night | #140D08 → #24160B → #5A3815 | Radial gradient for dark panels and closing CTAs | — |
| gold | #F2A93B | Primary button fill, accents on dark, focus ring | 8.6:1 with ink text |
| gold-text | #8A5208 | Eyebrows, active nav, links on light | 6.0:1 on ivory |
| gold-deep | #C27A12 | Numerals, rules, chart strokes | Decorative / large text |
| on-dark | #FCF8F3 / #D8CEC1 / #E9E1D6 | Text on dark (primary / muted / body) | ≥ 10:1 on night |
| error | #B0361C | Error text and 2px error borders | 6.2:1 on white |

Rule: gold #F2A93B is never used for text on ivory. Use gold-text #8A5208.

### Typography

| Role | Family | Size (min → max) | Weight / leading |
| --- | --- | --- | --- |
| Display H1 (Home hero) | Cormorant Garamond | clamp(47px, 5.83vw, 84px) | 500 / 1.02, tracking −0.01em |
| Page H1 (inner) | Cormorant Garamond | clamp(46px, 5.6vw, 84px); About clamp(52px, 6.4vw, 96px) | 500 / 1.0–1.02 |
| H2 section | Cormorant Garamond | clamp(34px, 4.17vw, 60px) | 500 / 1.05 |
| H3 card / block | Cormorant Garamond | 24–44px | 500–600 / 1.05–1.1 |
| Statement | Cormorant Garamond | clamp(30px, 3.4vw, 50px) | 500 / 1.18 |
| Eyebrow | Inter | 13px uppercase, 0.16em tracking | 600, gold-text |
| Lead | Inter | 18–19px | 400 / 1.6 |
| Body | Inter | 16–17px (About 18px) | 400 / 1.6–1.7 |
| Small / caption | Inter | 13–15px | 400–600 |
| Accent word | Cormorant Garamond italic | inherits | gold on dark, gold-text on light |

Fonts: Google Fonts, Cormorant Garamond 400/500/600/700 + italic 400/500; Inter 400/500/600; `display=swap`. Preconnect to fonts.googleapis.com.

### Space, shape, elevation

| Token | Value |
| --- | --- |
| Content width | max 1360px (About 1200px; long text 640–820px) |
| Page gutter | clamp(20px, 4vw, 40px) |
| Section padding | clamp(64–72px, 8.9–10vw, 128–144px) top and bottom |
| Inset panels | clamp(8px, 1vw, 14px) from viewport edge; radius clamp(14px, 1.6vw, 22px) |
| Radii | buttons 10px or pill; inputs 12px; tiles 16px; cards 20–24px |
| Card shadow (hover) | 0 30px 60px −36px rgba(33,26,19,.4) |
| Header | 72px, ivory at 92% with 18px backdrop blur |
| Touch targets | ≥ 44×44px; primary buttons 48–56px tall |
| Breakpoints | 1080, 960 (Apply), 900, 720, 480px — desktop-first `max-width` |

---

## 3. Motion system

All scroll motion uses CSS scroll-driven animations (`animation-timeline: view()`), wrapped in `@supports`, so it needs no JavaScript and degrades to static content. Full CSS: `specs/motion-and-base.css`.

| Token | Value | Use |
| --- | --- | --- |
| --out | cubic-bezier(.19, 1, .22, 1) | Panels, cards, page transitions, reveals |
| --soft | cubic-bezier(.25, 1, .5, 1) | Text, word rises, fade-ins |
| Hover | 0.2–0.75s | Buttons 0.2s colour; card lift 0.75s; underline 0.6s |
| Load-in | 1.0–1.1s, stagger 0.07–0.085s per word | Hero headline, page titles |

| Class | Behaviour | Range |
| --- | --- | --- |
| .fade-in | Rises 24px and fades in on load | 1s + delay |
| .mask > .w | Word rises from its own mask on load | 1.1s, staggered |
| .mask > .sw | Same word rise, scroll-driven | entry 0–40% → entry 60–100% |
| .reveal | Block fades up as it enters | entry 0% → 90% |
| .read | Paragraph brightens from muted | entry 0% → 100% |
| .stagger | Children enter one after another | +10% per child |
| .bar | Bars grow from the left | entry 20% → 100% |
| .count | Number counts up (registered `--n`) | entry 20% → 100% |
| .hero-copy | Hero text drifts up and fades on exit | exit 0% → 70% |
| .lift | Card rises 4px with soft shadow on hover | 0.75s --out |
| .ul | Underline sweeps in on hover | 0.6s --out |
| .arrow | Arrow nudges 4px right on hover | 0.5s --out |
| .zoom | Image scales to 1.04 inside its frame | 1.2s --out |

**Shared effects layer** (`specs/effects.css`, `specs/effects.js`, call `mamFx()` once after mount):

- Page transitions: cross-document View Transitions (`@view-transition { navigation: auto }`); old page fades out 0.3s, new page rises 14px over 0.6s.
- Image reveal: `[data-fx-img]` photos open from a 9% / 7% rounded inset and slight desaturation as they enter.
- Card spotlight: a 380px warm radial light follows the pointer over `.lift`, `.zoom`, bento tiles; stronger on dark cards.
- Smart header (inner pages, `[data-smart]`): hides after 520px when scrolling down; returns on any scroll up; the "On this page" bar moves to top 0.
- Scroll-spy: the "On this page" link for the section in view gets `.on` (gold-text, underlined) and `aria-current="location"`.

**Reduced motion:** `prefers-reduced-motion: reduce` disables every scroll animation, word rise, view transition, image reveal and the smart header. Count-ups show their final number; all content is visible from the start.

---

## 4. Global components

**Announcement strip (Home only).** Dark strip, 13–14px: "[INTAKE] intake is open — places are limited and applications are reviewed weekly. Apply now ›". Dismiss (×, 44px target) hides it; the page below has curved top corners that animate to 0 on dismiss.

**Header — Home (claude.com model).** At the top only a minimal layer: wordmark left; "Menu" pill and "Apply" right (Apply hidden ≤ 720px). After 560px of scroll the full ivory bar slides down (0.75s --out): Programmes ▾, Admissions, Life at MAM, About, Sign in | Book an assessment (outlined) | Apply → (ink). Links sit 36px from the buttons.

**Header — inner pages.** Sticky 72px bar, always present, smart-hides. Current page link gold-text with `aria-current="page"`. ≤ 1080px links collapse to a 44px menu button.

**Programmes panel (mega menu).** Opens under the header, max-height viewport, scrolls inside; Escape and toggle close it; `aria-expanded` on both triggers. Groups: Levels (four levels with grade ranges → Programmes#levels), Instruments (eight → Programmes#instruments), Short courses (Crash Programme · 6 weeks, The MetaMusic Order · 12 weeks, Power Play · free, monthly), feature card "Not sure where to start? Book a MAM Auditer assessment →". Mobile adds Admissions, Life at MAM, About, Sign in to your portal.

**Buttons**

| Variant | Style | Use |
| --- | --- | --- |
| Primary on light | Ink fill, ivory text, radius 10px or pill, 48–56px | Apply, Continue |
| Primary on dark | Gold fill, ink text | Apply now, Register |
| Secondary | 1px line-strong outline, ink text | Book an assessment |
| Ghost on dark | 1px rgba(252,248,243,.4–.6) outline, ivory text | Visit a class |
| Text link | gold-text or ink, `.ul` + `.arrow` | Learn more → |

Press: scale 0.98. Focus: 3px gold outline, 2px offset.

**Cards and lists.** Card: white, 1px line border, radius 20px, padding 28px, `.lift` + spotlight. Editorial rows (preferred on inner pages): hairline top border, large serif title, muted description, optional right meta. Timeline rail: 1px line-strong, 13px gold dots with a 5px page-colour ring; vertical ≤ 720px. Photo placeholder: sand gradient, caption bottom-left.

**Footer.** Newsletter ("Notes from the music room", email + Subscribe); brand column (wordmark, mission, Apply for [INTAKE] →); four intent groups (Study, Families, Community, Get in touch); official social SVG icons (Instagram, X, Facebook, YouTube); legal links; © line; outlined MAMCENTRE wordmark at 16vw, letter-spacing −0.03em.

---

## 5. Page specs

### Home

1. **Hero.** Inset rounded photo frame, min-height clamp(560px, 92svh, 760px), bottom-up dark gradient. H1 "Where young musicians become *complete* artists" (word rise; gold italic "complete"; no forced break). Sub-line "Graded music education from age 4 to diploma — online or in person at our studios." Buttons: Apply now (gold → Apply) and Visit a class (ghost → MAM Auditer). Photo 2.6s Ken Burns settle; copy drifts up on exit.
2. **About.** Centred 900px column: "More than music lessons", paragraph, pills About MAM / The MAM Method.
3. **Four schools (bento).** "Four schools inside every programme". Dark tiles: Instrumental, Performance, Rhythm Intelligence, MetaMusic & Consciousness (photo tile). 2 columns ≤ 900px, 1 ≤ 480px.
4. **Learning journey.** "Four levels, one continuous path" — four level cards (level, grades chip, name, description, tuition from). Dark placement banner "Placed by audition, not by guesswork" + Book an assessment.
5. **Proof.** "We measure what matters": dark MAM Auditer sample profile (6 bars, count-up %) + four commitments.
6. **Life at MAM.** "On stage, all year": Student Showcase feature + Power Play (Free), The MetaMusic Order ($60), Crash Programme ($70).
7. **Admissions (dark).** Sticky heading "From first enquiry to first lesson in about a week", four steps, tuition card ($120 / $240 / $313, online +20%), gold scholarship card.
8. **Questions.** Five accordions (grid-rows animation).
9. **Curtain call.** "Your seat is *waiting*." + Start your application / Talk to admissions.
10. **The shape of sound.** Inset dark waveform panel; pointer plays pentatonic notes; mic and sound toggles (32px) top-right; mic mode shows live pitch.

![Home, desktop](screenshots/desktop/home-1440.png)

### Programmes & fees

1. Hero "Find your *path,* and know your fee" + Estimate my fee; sticky "On this page": Programmes, Levels, Fees, Instruments, Intensives.
2. Statement "You don't buy a level — *you grow through them.*"
3. Comparison table: Standard $120 / Certified Musician $240 (Most chosen, white column) / Grand Musician $313; seven feature rows; per-column "Estimate" scrolls to the estimator with that programme selected. Min-width 760px, scrolls sideways on phones.
4. Levels rail (four stops: level, grades, name, description, base fee).
5. Fee estimator (section 6).
6. Instruments: sticky intro; eight instruments as a large-serif index with sub-types; hover nudges 10px in gold; expressions African / Contemporary / Classical.
7. Intensives: Crash Programme $70 flat, The MetaMusic Order $60 flat; scholarship as one quiet line.
8. Closing "Ready when you are."

![Programmes, desktop](screenshots/desktop/programmes-1440.png)

### Apply

Full screen, no site header or footer. Left: sticky dark panel clamp(340px, 32vw, 440px) with section list and fee. Right: one question per screen, max-width 640px; top bar with Back, "Question n of 7", Save and exit; 3px gold progress line. Flow in section 6.

![Apply, first question](screenshots/desktop/apply-1440.png)
![Apply, programme step](screenshots/desktop/apply-1440-programme-step.png)

### About (Stanford "About" model)

1. Breadcrumb, H1 "About MAM *Academy*" (word rise), full-width photo, height clamp(260px, 42vw, 620px).
2. Intro "A creative intelligence and performance academy", two paragraphs, "Our history →".
3. Six themed blocks, alternating photo side: H3, paragraph, optional gold-ruled quote, "Learn more" link + sub-text — four schools; heard first; a method of our own; working musicians; support; community.
4. "Learn more" band: founding note + Leadership & faculty and Our history link cards.

![About, desktop](screenshots/desktop/about-1440.png)

### MAM Auditer

1. Hero "Placed by listening, *not guessing.*" + Apply and book / Try a question; "On this page".
2. Statement "Most schools guess your level from your age. *We listen first.*"
3. Ten dimensions: SVG radar of a sample profile + tab chips; selection highlights the axis and shows name, score, meaning.
4. "Which note is higher?" ear test (dark), two answer buttons, feedback, score.
5. From session to plan: four-stop rail. Closing "Get heard. Get placed."

![MAM Auditer, desktop](screenshots/desktop/auditer-1440.png)

### Life at MAM

1. Hero "Music that *builds* people." + Join Power Play; "On this page".
2. Power Play (dark): description, schedule, Register free, live countdown to the next first Wednesday.
3. A term at MAM: Assess & plan, Lessons, Check-in, Showcase.
4. Stages, intensives and gatherings: three alternating photo/text rows.
5. Between lessons: Practice Studio, Student & parent portals, Store. Closing "Join the community."

![Life at MAM, desktop](screenshots/desktop/life-1440.png)

Phone screenshots (390px) for every page are in `screenshots/mobile/`.

---

## 6. Interactions and logic

### Fee model (USD per term) — `specs/fee-model.js`, tests in `specs/fee-model.test.js`

| Level | Grades | Base B |
| --- | --- | --- |
| Pre-Certificate | 1–2 | $120 |
| Certificate | 3–4 | $130 |
| Pre-Diploma | 5–6 | $160 |
| Diploma | 7–8 | $180 |

- Standard = B + 0.5B · x  (x = additional courses, 0–4)
- Certified Musician = B + 120  (four compulsory courses)
- Grand Musician = (3B + 0.5B · x) × 0.87
- Online: +20% on Standard, Certified, Grand. Flat: Crash $70 (6 weeks), MetaMusic $60 (12 weeks), no surcharge.
- Registration: $5 one-off — the only payment at application.
- Currencies (indicative): USD 1, NGN 1500, GBP 0.79.
- Check values: Certified Pre-Cert $240 · Grand $313 (saving $47) · Standard + 2 online $288 · Certified Diploma ₦450,000. `node specs/fee-model.test.js` verifies them.

### Fee estimator (Programmes)

- Segmented radio groups (`role="radiogroup"`, `role="radio"`, `aria-checked`): Programme (Standard, Certified, Grand, Crash), Level (hidden for Crash), Mode (In person / Online +20%).
- Additional-courses stepper (−/+ 44px, live `<output>`), Standard and Grand only.
- Dark receipt: currency switch, total 56–76px serif with `aria-live="polite"`, line items, registration note, "Apply with this choice →".

### Application flow (Apply)

| # | Screen | Input | Validation |
| --- | --- | --- | --- |
| 1 | Who is this application for? | Tiles: Myself / My child | Required |
| 2 | Name ("What is your child's name?" for a child) | First, last | Both required |
| 3 | Age group | Tiles: 4–7, 8–12, 13–17, 18+ | Required |
| 4 | Instrument | 9 tiles incl. Help me choose; 4–7 see "Great first instrument" on Voice, Piano, Drums | Required |
| 5 | Programme | 5 tiles with price; "Recommended for [age]" (Standard for 4–7, else Certified) | Required |
| 6 | Mode | In person / Online (+20%, "Same price" for flat courses) | Required |
| 7 | Contact | Guardian name (child only), email, phone | Email pattern; ≥ 7 digits |
| 8 | Check your answers | Summary; "Change" returns here ("Save and return") | — |
| 9 | Pay the $5 registration fee | Card (Paystack) / Bank transfer + terms checkbox | Terms required |
| 10 | Application received | Reference MAM-YY-NNNN, next steps, Book my assessment | — |

Behaviour:

- The H1 is the question and receives focus on every step change; Enter continues (except on buttons/links).
- Errors: summary at top (`role="alert"`, 2px red border) + inline messages; fields get `aria-invalid` and a 2px red border; messages say what to do.
- Email typo check: domains within 2 edits (Levenshtein) of gmail.com, yahoo.com, hotmail.com, outlook.com, icloud.com, live.com, ymail.com show "Did you mean …?" with a one-tap fix.
- Autosave: `localStorage["mam-apply-v1"] = { f, step, at }` on every change (try/catch); resume with "Welcome back — we kept your answers" and Start over; cleared on submit.
- Left panel: About the learner · The course · Contact · Review and pay, with ticks, summaries and Edit; live estimated tuition. ≤ 960px: replaced by a one-line summary bar.

Application payload (POST to the application endpoint, server validates again):

```json
{
  "applyingFor": "self | child",
  "learner": { "firstName": "Ada", "lastName": "Obi", "ageGroup": "8–12" },
  "course": { "instrument": "Voice & Choir", "programme": "standard | certified | grand | crash | meta", "mode": "onsite | online" },
  "contact": { "guardianName": "Only when applyingFor = child", "email": "name@example.com", "phone": "+234 …" },
  "payment": { "method": "card | transfer", "registrationUSD": 5 },
  "agreedToTerms": true,
  "estimatedTuitionUSD": 240,
  "intake": "[INTAKE]"
}
```

### Other interactions

- Home waveform: pointer x maps to a pentatonic note (triangle + sine pluck → compressor); mic mode detects pitch by autocorrelation (RMS gate 0.012, 60–1400 Hz, parabolic interpolation). Sound is opt-in; canvas pauses off-screen (IntersectionObserver).
- Auditer ear test: two tones 0.7s apart, 2–7 semitones; written feedback in a status line; running score.
- Life countdown: days to the next first Wednesday, computed on the visitor's clock.

---

## 7. Accessibility (WCAG 2.2 AA)

- Landmarks: one `header`, `main#main`, labelled `nav` (Main, Breadcrumb, On this page, Footer), `footer`; skip link first.
- One H1 per page; animated headings keep the full text in `aria-label` and hide word spans with `aria-hidden`.
- Contrast per section 2; visible 3px gold focus everywhere; focus management on Apply steps.
- Targets ≥ 44×44px (exceeds 2.5.8's 24px).
- Real radio and tab semantics; live regions for totals and the Apply counter; `role="alert"` for errors.
- Real photos need meaningful `alt`; decorative canvases/SVG are `aria-hidden` and never block clicks.
- No sound without a click; mic only on explicit toggle, with a clear error if refused.
- Reduced motion fully supported.

## 8. Responsive behaviour

| Breakpoint | Changes |
| --- | --- |
| ≤ 1080px | Header links → menu button; bento tightens; sticky side headings become static |
| ≤ 960px | Apply: left panel hidden; one-line summary bar |
| ≤ 900px | Two-column layouts stack; level cards 2-up; footer groups 2-up |
| ≤ 720px | Apply leaves the Home top layer; rails turn vertical; comparison table scrolls; Enter hint hidden |
| ≤ 480px | Single column; type at minimum clamp sizes |

Test at 390, 768, 1024 and 1440px. No horizontal overflow except the comparison table and the "On this page" bar, which scroll on purpose.

---

## 9. Content, assets and open items

### Photography needed (real MAM photos, no stock)

| Where | Subject | Ratio / min size |
| --- | --- | --- |
| Home hero | Students performing on stage | 16:9, 2400px |
| Home bento | MetaMusic seminar | 4:3, 1600px |
| Home Life | End-of-term showcase | 4:3, 1600px |
| Programmes hero | Students in a piano lesson | 16:9, 2400px |
| About hero | Students and faculty on stage | 21:9, 2400px |
| About blocks (6) | Group lesson, assessor listening, piano practice, faculty performing, student after a lesson, showcase | 3:2, 1600px |
| MAM Auditer hero | Assessor with a young violinist | 16:9, 2400px |
| Life hero + rows (3) | Showcase stage, MetaMusic cohort, crash-course class | 16:9 / 16:10, 1600–2400px |

WebP or AVIF, under 300 KB each, with alt text.

### Facts to confirm

- [ ] Intake name and date for every [INTAKE] placeholder
- [ ] MAM Auditer session length (designed as "about 30 minutes")
- [ ] Vision line "We interpret the timeless using instruments and replay it in time"
- [ ] Faculty names and photos beyond Just-Joe Adeola
- [ ] Term structure on Life
- [ ] Auditer dimension descriptions and sample values

### Links still to wire

- [ ] Power Play registration form
- [ ] Portal sign-in, Practice Studio, Store
- [ ] Leadership & faculty, Our history pages
- [ ] Terms, class policies, privacy, accessibility, cookies
- [ ] Paystack checkout for the $5 registration
- [ ] Confirm the Apply endpoint (designed to post to the existing `apply.php`)

---

## 10. Technical notes

**Page source format.** Each `source/pages/*.dc.html` is a self-contained Design Component: markup inside `<x-dc>`, styles inside `<helmet>`, logic in one `<script type="text/x-dc">` class whose `renderVals()` returns the values for `{{ holes }}`. `<sc-for list as>` repeats markup; `<sc-if value>` toggles it. Port each page to the production stack (PHP templates on the current site) by translating those three constructs; all styles are inline or in the helmet `<style>` and can move to a stylesheet unchanged.

**Generators.** `source/generators/` holds the Python that produced the inner pages: `shared.py` (header, panel, footer, page shell), `kit.py` (hero, statement, split, closing), one file per page, and `fx.py` (effects layer). Run order: page generators, then `python3 fx.py`. They expect the files at `/home/claude/...` paths; adjust the constants at the top.

**Recommended production structure**

```
/assets/css/tokens.css          ← specs/tokens.css
/assets/css/base.css            ← specs/motion-and-base.css
/assets/css/effects.css         ← specs/effects.css
/assets/js/effects.js           ← specs/effects.js (call mamFx() on DOMContentLoaded)
/assets/js/fee-model.js         ← specs/fee-model.js (shared by Home, Programmes, Apply)
/partials/header.php, panel.php, footer.php
/index.php, programmes.php, apply.php, about.php, auditer.php, life.php
```

**Browser support.** Latest two versions of Safari, Chrome, Firefox, Edge; iOS Safari 16+. Scroll-driven animations and View Transitions are progressive enhancements: unsupported browsers show the final, static state.

**Performance budget.** LCP < 2.5s on 4G; hero image ≤ 300 KB, `fetchpriority="high"`; other images `loading="lazy"`; total JS < 60 KB gzipped per page; fonts preconnected with `display=swap`; canvases pause off-screen.

**QA checklist**

- [ ] Fee check values pass (`node specs/fee-model.test.js`) and match the UI on all three pages
- [ ] Keyboard-only pass on every page (focus order, visible focus, Escape closes the panel)
- [ ] VoiceOver / NVDA pass on Apply (questions, errors, live regions)
- [ ] Reduced-motion pass
- [ ] 390 / 768 / 1024 / 1440px with no unintended horizontal scroll
- [ ] Autosave resume and Start over on Apply
