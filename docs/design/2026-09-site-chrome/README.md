# Handoff: Site chrome + student application

## Overview

Two pieces of the MAM Academy site, reworked for a phone first and then scaled up:

1. **The site chrome** — `partials/header.html`, `partials/footer.html` and the nav/footer
   rules in `assets/css/main.css`. Every page on the site wears this.
2. **The student application** — `apply.php` + `assets/js/apply.js` + the shared wizard
   rules in `assets/css/apply.css`. The five-step enrolment wizard.

This is the second bundle. The first, `design_handoff_instructor_application/`, covers
`/instructor-apply` and establishes the visual vocabulary both of these follow: hairline
dividers instead of bordered cards, baseline inputs instead of filled slabs, one accent
colour, one heading per screen. Read that README first if you have it — this one does not
repeat the shared rationale.

No new tokens, no new libraries, no new fonts. Same dark candlelit palette, same
Inter / Cormorant Garamond / Bebas Neue trio, same Font Awesome 6.5 set.

### What the chrome rework does

| Change | Why |
|---|---|
| **Header 145px → 102px** | The announce bar wraps to two lines at 390px and the navbar is a floating pill (`top:10px` + `margin-top:10px` + 24px radius + 28px inset + 40px shadow). Flush, opaque, single-row instead. |
| **Announce bar becomes persistent and dismissible** | Today it scrolls away permanently and nothing retains the intake message. Now it is one ellipsised line under the bar with a real close button and a stored dismissal. |
| **Four brand-shrinking breakpoints deleted** | `main.css` shrinks the wordmark 1.45rem → 1.18 → 1.05 → 1rem and degrades "Sign in" to `font-size:0` purely to stop overflow. A 56px bar with two icon buttons does not need them. |
| **One floating object, not four** | Back-to-top (bottom-left), Apply Now pill (bottom-right), cookie banner (base) and `lead.js`'s CTA bar can all be on screen at once; `main.js` ships a docking routine whose only job is stopping chrome colliding with chrome. |
| **Menu: 40 links → 7 rows + 5 minor** | Disciplines alone is 13 links, seven of which are `apply?discipline=` deep links that duplicate the wizard's own first step. |
| **Footer: 35 links in one run → 4 collapsed groups** | Plus the wordmark, which is stretched to `textLength:1184` on a 390px screen, returns to its natural size. |

### What the student-application rework does

| Change | Why |
|---|---|
| **"Step 1 of 5" becomes six real screens** | Step 1 currently asks seven questions and shows 38 choice cards on one ~4,600px scroll. The stepper's promise and the work do not match; the forms audit puts abandonment on this step at 46%. |
| **Level and expression stop reflowing the page** | They are `hidden` substeps that appear and disappear with the programme, so the scroll length changes under the applicant mid-answer. As screens, they are simply skipped when unused. |
| **Focus area + primary course merge** | An 11-option `<select>` and 14 radio cards asking two versions of "what will you study". One searchable list of 14 rows instead. |
| **Profile splits who / where / when** | 15 fields, six of them address, in `.field-row` pairs that stay two-up at 390px. Address gets its own screen so the phone can autofill it in one tap. |
| **Review becomes editable in place** | Each row carries Edit; the $5 is stated before the consents, not after them. |

## About the design files

The files in this bundle are **design references written in HTML** — prototypes of the
intended look and behaviour, **not production code to paste in**.

The target is the existing MAM Academy codebase: server-rendered PHP with no build step,
vanilla ES5-style JS in `assets/js/`, hand-written CSS in `assets/css/`. So:

- Chrome → edit `partials/header.html` and `partials/footer.html`; rewrite the nav and
  footer blocks of `assets/css/main.css`; adjust the menu/dock logic in `assets/js/main.js`.
- Student wizard → edit `apply.php`'s markup; extend the shared wizard rules in
  `assets/css/apply.css` (the same file the instructor form uses — keep them shared so the
  two forms cannot drift into two house styles); adapt `showStep()`, `validateStep()`,
  `renderMinors()`, `renderReview()` and the draft keys in `assets/js/apply.js`.

The prototypes are single-file streaming components (`.dc.html`); open either directly in a
browser to inspect computed values. `support.js` must sit beside them.

## Fidelity

**High-fidelity.** Colours, type sizes, spacing, radii and target sizes are final and
measured, and every value traces to a file in the repo. Recreate pixel-perfectly.

Each `.dc.html` shows the **current** build beside the **proposed** one. Only the frames
labelled "Proposed" / "New" are the design. The "Today" / "Current" frames are faithful
recreations of what ships now, kept so the comparison can be checked rather than taken on
trust — do not build from them. They are the only place sub-12px type appears (11.52px and
11.84px, reproducing the source's `0.72rem`/`0.74rem` at a 16px root); the proposed designs
hold a 12px floor throughout.

Two contractual floors, already stated twice in `apply.css`, are honoured throughout:

- **Interactive targets ≥ 44px on touch.** Desktop may use the tighter 38-40px control
  height, gated behind `@media (min-width:900px) and (pointer:fine)`.
- **No running text below 12px** in anything new.

## Files

| File | What it is |
|---|---|
| `Site Chrome.dc.html` | Header, menu and footer — current vs proposed at 390px, then the 1280px scale-up. |
| `Student Application.dc.html` | The programme step, profile, review — current vs proposed at 390px, then the 1280px scale-up. |
| `mam-academy-logo.svg` | Copied from the repo root, unmodified. |
| `support.js` | Runtime the `.dc.html` prototypes need in order to open. Not part of the design. |
| `screenshots/` | Rendered captures, 390px frames at 2x and 1280px frames at 1x. Chrome: `chrome-01-header-current`, `-02-header-new`, `-03-menu-current`, `-04-menu-new`, `-05-footer-current`, `-06-footer-new`, `-07-desktop-header`, `-08-desktop-footer`. Application: `apply-01-step1-current`, `-02-1a-mode`, `-03-1b-programme`, `-04-1c-course`, `-05-step2-current`, `-06-step2-new`, `-07-review`, `-08-desktop`. The logo rasterises blank in these captures (the rasteriser does not inline external SVGs); it loads correctly in the browser. |

---

# Part 1 — Site chrome

## Screen: Header  (`data-screen-label="Chrome new header"`)

**Purpose** — Identify the site, reach search and the menu, and carry the current
admissions message without eating the viewport.

**Layout** — Two stacked full-bleed rows, flush to the top edge. No radius, no horizontal
inset, no `top` offset, no shadow.

**Row 1 — the bar**

- `height:56px`, `display:flex`, `align-items:center`, `justify-content:space-between`,
  `gap:12px`, `padding:0 16px`.
- `background:rgba(26,18,11,0.92)` — **opaque enough to read against any hero**. The
  current `rgba(18,13,8,0.72)` + `backdrop-filter:blur(20px)` is a real cost on mid-range
  Android and the blur is invisible behind the page heroes anyway.
- `border-bottom:1px solid rgba(232,201,160,0.16)`.
- Brand, left: 30px logo (`border-radius:8px`) + wordmark in Bebas Neue 19px, `.07em`,
  **flat `#FCC647`**. The gradient-clipped wordmark
  (`-webkit-background-clip:text` + `transparent` fill) is dropped at this size: it costs a
  paint layer and fails to a transparent word if the clip is unsupported.
- Actions, right: exactly two icon buttons, `gap:6px`, each `min-width:44px;min-height:44px`.
  Search (`fa-magnifying-glass`, 15px) and menu. The menu glyph is **two** 20 × 2px bars
  with `gap:5px`, not three — with a "Menu" label absent, two bars read as a control and
  three read as decoration at this weight. Either is acceptable; pick one and keep it.
- **"Sign in" leaves the bar** and moves into the menu, where it has room for a label.

**Row 2 — the announce strip**

- `display:flex`, `align-items:center`, `gap:10px`, `padding:9px 16px`.
- `background:rgba(252,198,71,0.10)`, `border-bottom:1px solid rgba(232,201,160,0.16)`.
  **Not** the `linear-gradient(90deg,#E8961F,#F7A823,#E8961F)` bar — a full-saturation gold
  band above the fold competes with the page's own primary action.
- 6px `#FCC647` dot, `flex-shrink:0`.
- Message: `flex:1; min-width:0; font-size:12.5px; line-height:1.4;` with
  `overflow:hidden; text-overflow:ellipsis; white-space:nowrap` — **one line, always**.
  Copy: `September intake open · ` + `Apply` as an inline link in `#FCC647`/700.
- Close: `min-width:32px; min-height:32px`, `fa-xmark` 13px in `rgba(252,248,243,0.58)`.
  Dismissal persists (localStorage, keyed to the message so a new campaign reappears).
- The strip sits **below** the bar so dismissing it never moves the bar.

**Result** — chrome ends at 102px, first word of page content at 129px, versus 145px and
171px today. It never reflows, at any width.

**Removed from the mobile viewport**

- Back-to-top FAB (48px circle, bottom-left).
- Floating "Apply Now" pill (bottom-right, `linear-gradient` + `0 8px 32px` glow).
- Both return at `min-width:1024px and (pointer:fine)` if wanted. On touch the bar already
  carries Apply and the menu is one tap away.

**Cookie banner** — one row, not a stacked block: text `flex:1` at 12.5px with a `Details`
link, and a single `min-height:44px` OK button (`#FCC647`, `padding:0 20px`,
`border-radius:12px`). `padding:12px 16px 18px` — use
`max(18px, env(safe-area-inset-bottom))` in production. Decline stays available inside
Details; essential-cookies-only does not need a two-button consent gate.

## Screen: Menu  (`Chrome new menu`)

**Purpose** — Reach any part of the site in one or two taps.

- Head: `height:56px`, `padding:0 16px`, `border-bottom:1px solid rgba(232,201,160,0.16)`.
  "Menu" 13px/700 left, `fa-xmark` 17px in a 44px target right.
- Body: `padding:18px 20px 28px`, `gap:22px`.
- **Search first** — `min-height:48px`, `padding:0 16px`,
  `border:1.5px solid rgba(232,201,160,0.36)`, `border-radius:12px`, 16px placeholder
  "Search courses, pages, tools". A 40-link site needs search more than it needs a
  four-tile quick grid.
- **Two actions**, `gap:10px`, each `flex:1; min-height:50px; border-radius:12px`:
  Apply (`#FCC647` on `#1A120B`, 800) and Sign in (outlined, 700).
  This replaces the 4-tile grid, in which Studio and Store were given the same visual
  weight as Apply.
- **Seven rows**, `min-height:56px`, `border-bottom:1px solid rgba(232,201,160,0.16)`,
  `gap:14px`: a 20px-wide centred icon in `rgba(252,248,243,0.58)`, a two-line label
  (name 15.5px/650 `#FCF8F3`; description 12.5px `rgba(252,248,243,0.58)`), and a trailing
  chevron (`fa-chevron-right` → opens a sub-panel) or arrow (`fa-arrow-right` → navigates).
  The distinction is load-bearing; keep it.

  | Row | Description | Trailing |
  |---|---|---|
  | Apply to a pathway | Certified · Grand · Crash — compare and start | chevron |
  | What you can study | Seven disciplines across four schools | chevron |
  | The MAM Method | How the training works, and why | chevron |
  | Practice Studio | Free tools — tuner, metronome, notation | arrow |
  | Store | Books, scores and merchandise | arrow |
  | Experiences | MetaMusic Order · Power Play · Auditer | chevron |
  | Teach at MAM | Faculty application | arrow |

- **Minor links** as a wrapping row, `gap:0 20px`, each `min-height:40px`, 13.5px in
  `rgba(252,248,243,0.74)`: FAQ · Contact · Blog ↗ · Privacy · Scholarships.
- The seven `apply?discipline=` deep links are **gone** — they pre-answered a question the
  wizard asks on its own third screen, and a mismatch between the two was a live bug class.

## Screen: Footer  (`Chrome new footer`)

- `padding:32px 20px 24px`, `gap:26px`, `background:#1A120B`.
- Brand: 30px logo + Bebas 20px flat `#FCC647`; tagline "Crafting the Timeless in Time" in
  Cormorant Garamond italic 15.5px `rgba(252,248,243,0.74)`. The 58-word "about" paragraph
  is cut — it is the third time the same sentence appears on most pages.
- Actions: full-width `Apply to the Academy` (`min-height:48px`, `#FCC647`), then Email us /
  Call us side by side (`flex:1`, `min-height:44px`, outlined, gold icon + label). Contact
  details are the most-used thing in a footer and were previously plain text among 35 links.
- **Four collapsed groups** — `min-height:54px` rows with a hairline bottom, title 15px/650,
  and a muted count on the right before `fa-chevron-down`: Schools (5), Pathways (6),
  Academy (12), Portals (3). `<details>`/`<summary>`, so they work without JS.
- Newsletter: baseline email input (`border-bottom:1.5px solid rgba(232,201,160,0.36)`,
  `min-height:48px`, 16px) + an outlined `Sign up` button, `min-height:44px`.
  Replaces the 46px gradient circle with a bare paper-plane glyph — an unlabelled icon
  button as the only way to submit.
- Socials: four 40px targets, `gap:16px`, glyphs at 15px in `rgba(252,248,243,0.74)`, no
  circles or backgrounds. `© 2026` on the right of the same row.
- Legal: wrapping row, `gap:0 18px`, each `min-height:40px`, 12.5px.
- Wordmark: Bebas 34px, `letter-spacing:0.14em`, `color:rgba(232,201,160,0.24)`, centred.
  **No `transform:scaleX()`, no gradient clip, no SVG `textLength`.** Today it renders at
  66px stretched 1.18× on a 390px screen.

## Screen: Desktop chrome  (`Chrome new desktop header`, `Chrome new desktop footer`)

**Header** — announce strip `padding:9px 40px` with the message left, `Reserve your seat`
pushed right by `margin-left:auto`, close after it. Then a 64px bar, `padding:0 40px`,
`gap:32px`: brand (32px logo + Bebas 21px), six nav items, then search + Sign in + Apply
pushed right.

- Nav item: `min-height:40px`, `padding:0 14px`, `border-radius:10px`, 13.5px/500,
  `white-space:nowrap`. Active/open gets `color:#FCC647` and
  `background:rgba(255,255,255,0.07)`. Items with children carry `fa-chevron-down` at 9px,
  `opacity:0.7`, `margin-left:6px`.
- Sign in: `min-height:40px`, `padding:0 16px`, `border:1px solid rgba(232,201,160,0.36)`,
  `border-radius:10px`. Apply: same metrics, `padding:0 20px`, `#FCC647` on `#1A120B`, 800.
- **Mega menus keep their columns but lose the photographic spotlight panel** — it was the
  widest element in the chrome and the slowest thing on the page to paint, for a decorative
  image behind a menu.

**Footer** — `padding:48px 40px 28px`, `gap:36px`.
`display:grid; grid-template-columns:1.4fr repeat(3,1fr) 1.1fr; gap:48px; align-items:start`.
Brand + contact column, three link columns (column heads 12px/800/`.1em`/uppercase in
`rgba(252,248,243,0.58)`; links 13.5px `rgba(252,248,243,0.90)`, `gap:9px`), newsletter
column. Then a bottom bar above a hairline: socials left, legal links pushed right with
`margin-left:auto`, copyright last. Wordmark: Bebas 76px, `letter-spacing:0.3em`,
`rgba(232,201,160,0.20)`.

---

# Part 2 — Student application

The wizard keeps its **five named steps** — Programme, Profile, Background, Review,
Payment. What changes is that step 1 stops being seven questions on one scroll.

## Screens 1a–1f · Programme  (`Apply new 1a mode`, `1b programme`, `1c course`)

**Purpose** — Six single-question screens, all inside "Step 1 of 5".

**Shared shell**

- App bar: `padding:16px 20px 14px`. Left `1 of 5 · Programme` (with a leading
  `fa-arrow-left` from 1b onward), right `Save & exit`. Both 13px/700
  `rgba(252,248,243,0.74)`.
- Progress: 2px, track `rgba(232,201,160,0.16)`, fill `#FCC647`. **The fill advances within
  the step** — 6 / 12 / 18 / 24 / 30 / 36% across the six questions, then 40% at step 2,
  60, 80, 100. Movement on every screen is the point: a bar that sits still for six screens
  teaches people the form is not progressing.
- Body: `padding:26px 20px`, `gap:22px`. Heading Cormorant Garamond 700, 29px/1.12.
  No eyebrow, no subtitle.
- Sticky bar: `margin-top:auto`, `padding:14px 20px 20px`, `background:#1A120B`,
  `border-top:1px solid rgba(232,201,160,0.16)`, `justify-content:space-between`.
  Left `Question N of 6` 13px/650. Right the primary button: `min-height:50px`,
  `padding:0 26px`, `border-radius:12px`, flat `#FCC647`, `#1A120B`, 15px/800,
  `Next` + `fa-arrow-right` 12px.

**Selection marks** (used on every screen here and matching the instructor form)

- Mark: 24 × 24 circle. Off `border:1.5px solid rgba(232,201,160,0.36)`.
  On `background:#FCC647` with `fa-check` 11px in `#1A120B`.
- Row label: `flex:1; min-width:0`, 15.5px/1.3. Off 500 `rgba(252,248,243,0.90)`,
  on 700 `#FCF8F3`.

**1a · How will you learn?** — two cards, `gap:10px`, `padding:16px`, `border-radius:14px`,
`gap:14px` internally. Off `border:1.5px solid rgba(232,201,160,0.30)`; on
`border:1.5px solid #FCC647` + `background:rgba(252,198,71,0.12)`. Title 16px/700, desc 13px
`rgba(252,248,243,0.58)`. Copy: "Online / Live video classes from anywhere",
"Onsite, Lagos / Lessons and ensembles at MAM Centre". Closing line:
"Everything here can change before you pay."

**1b · Which programme?** — five hairline rows, `min-height:72px`. Two-line label plus a
right-aligned tag in 12.5px/700 `rgba(252,248,243,0.74)`: Crash (6 weeks), Standard
(1 course), Certified Musician (up to 5), Grand Musician (all 3), MetaMusic Order
(12 weeks). Closing line names the $5 and defers tuition to the Auditer.

**1c · Level** — four hairline rows (Pre-Certificate Grade 1–2 … Diploma Grade 7–8).
Skipped for `crash` and `meta-order`, exactly as `substepLevel[hidden]` does today.

**1d · Expression** — three rows (Classical, Contemporary, African). Skipped for
`grand-musician` and `meta-order`.

**1e · What will you major in?** — search field (`min-height:48px`, identical to the menu's)
above 14 hairline rows, `min-height:58px`, each a two-line label. **This replaces both the
`#program` focus-area `<select>` and the 14 `discipline` cards.** Map the old optgroup
values onto the same list so `?discipline=` prefills and stored drafts still resolve.

**1f · Add more courses** — shown only for `certified-musician` and `grand-musician`, same
row treatment, with the compulsory-courses note as one line above it rather than a
bordered `.compulsory-note` panel.

## Screen 2 · Profile  (`Apply new 2 profile`)

- Heading "Who is studying?".
- Six baseline fields, `gap:18px`: First name (`autocomplete="given-name"`), Last name
  (`family-name`), Email (`email`), Mobile number (`tel`), Date of birth (`bday`), Sex.
  Label 13px/650 sentence case; input `border:0; border-bottom:1.5px solid
  rgba(232,201,160,0.36)`, `padding:0 2px`, `min-height:48px`, **`font-size:16px`**
  (mandatory — iOS zooms the viewport on focus below it).
- Help lines only where they earn it: Email → "Your application copy and invoice go here.";
  DOB → "Sets your age band — under 16 adds a guardian contact."
- Headshot: one 48px-circle row above a hairline with a trailing chevron, expanding the
  existing `MAM_HEADSHOT.mount()` widget in place. Keep that widget and its `#hsStatus`
  `role="status"` region exactly as they are.
- Closing line: "Address comes next, on its own screen — your phone can autofill it in one
  tap."
- **Address moves to its own screen** (street, LGA, city, state, country as five baseline
  fields, `autocomplete` on each). The current `.field-row` pairs stay two-up at 390px,
  which is what makes "Local Government (County)" and "State (Province)" unreadable.
- Guardian block stays conditional on age < 16, on the address screen's successor.

## Screen 4 · Review  (`Apply new 4 review`)

- Heading "Check it over".
- Six hairline rows, `min-height:54px`: a 96px `flex-shrink:0` key in 12.5px
  `rgba(252,248,243,0.58)`, the value 14px/650 `#FCF8F3`, and `Edit` 13px/700 `#FCC647`
  right. Rows: Programme, Major, Level, Expression, Student, Intake.
- **Price before consent**: a `Due today` / `$5` pair — label 14px/650, amount Cormorant
  Garamond 30px/700 `#FCC647` — then one 13px line naming what it covers (registration,
  student ID, student file, 30-minute Auditer assessment) and stating tuition is not
  charged here.
- Two consents, `gap:16px`, no cards: 24 × 24 checkbox
  (`border:1.5px solid rgba(232,201,160,0.36)`, `border-radius:6px`), text 13.8px/1.6.
- Sticky bar: `fa-lock` + "Secure" left, `Pay $5` right.
- The `.policy-block` (title + intro + points list + note) collapses into a disclosure
  behind the policy consent. The full policy is already emailed on submit; a scrollable
  policy excerpt inside a phone form is read by nobody.
- **Keep the typed-signature field** (`#policySignName`) and its signed-on date — that is a
  deliberate legal artefact, not chrome.

## Screen · Desktop  (`Apply new desktop`)

`padding:44px 48px 52px`, `display:grid; grid-template-columns:minmax(0,1fr) 320px;
gap:0 72px; align-items:start`.

- **The six questions fold back onto one page** — mode (2-up grid), programme (hairline
  rows, `min-height:64px`), major (two-column hairline grid, `gap:0 48px`,
  `min-height:54px`, single-line labels). Section heads are 12px/800/`.1em`/uppercase in
  `rgba(252,248,243,0.58)`, `gap:34px` between sections.
- `align-items:start` is load-bearing — with the default `stretch` a row's `border-bottom`
  paints at the bottom of the stretched track rather than under its content.
- Right column: `align-self:stretch` (it must opt back out of `align-items:start`, or its
  rule stops partway down), `padding-left:40px`,
  `border-left:1px solid rgba(232,201,160,0.16)`, `gap:26px`. Five step rows (7px dot,
  `min-height:40px`, current 700 `#FCF8F3` / rest 500 `rgba(252,248,243,0.58)`), then
  `Your selection` with key/value pairs and a `Due today` / `$5` total.
  Make it `position:sticky; top:24px` in production.
- **This replaces the collapsed `<details class="summary-panel">`**, which hid the running
  total behind a tap on every screen size.
- Cap the grid at `max-width:1180px; margin:0 auto`.

**Breakpoints** — the repo's own: `max-width:560px`, `max-width:960px`,
`min-width:900px and (pointer:fine)`. The summary column appears at `min-width:1024px`;
between 960 and 1024 the form is one centred column at `max-width:640px`.

## Interactions & behaviour

- **Sub-step navigation** — extend `showStep(n)` with a sub-index, or model the six as
  steps 0.0–0.5. Back from question 1 leaves the wizard; back from 2–6 goes to the previous
  question. Browser Back must map to the same thing.
- **Skipping** — level and expression are skipped by the same predicates that drive
  `substepLevel[hidden]` / `substepExpression[hidden]` today. Skipping must work in reverse:
  going back from the major screen with `crash` selected lands on programme, not level.
- **Keep `#applyAnnounce`-style `role="status" aria-live="polite"` announcements** on every
  screen change. Moving focus alone never says which question of how many.
- **Autosave** — unchanged `saveDraft()`/`loadDraft()` and the resume banner. Note
  `.resume-banner[hidden]{display:none}` in `apply.css`: author `display:flex` outranks the
  UA's `[hidden]` rule, and a first-time visitor was once told "We saved your draft" over an
  empty form. Any new `display:flex` element that also carries `hidden` needs that guard.
- **Validation** — `validateStep()` rules are unchanged, just distributed one question per
  screen; a screen with an unanswered required question cannot advance.
- **Errors** — keep `NOTICE_TONES` / `setNotice(html, tone, takeFocus)`. Server field errors
  focus the first rejected field; a dropped connection is amber, not red; a successful retry
  calls `clearNotice()`.
- **Menu open/close** — keep the focus trap, `aria-expanded`, Escape-to-close and body
  scroll lock already in `main.js`. Remove only the chrome-collision docking routine.
- **Transitions** — existing `stepFade` 0.4s `cubic-bezier(0.4,0,0.2,1)`. Announce-strip
  dismissal: height/opacity 180ms. Every animation needs a
  `prefers-reduced-motion: reduce` branch that keeps the cue and drops the movement.

## State

No new application state. The wizard's existing shape carries over; only navigation gains a
sub-index:

```
state = {
  step,            // 0-4, unchanged
  subStep,         // NEW: 0-5 within step 0
  mode, pathway, level, expression, program, discipline,
  minors {}, profile {…}, background {…}, consents {…}
}
```

Chrome state is two booleans in localStorage: `announceDismissed` (keyed to the message
text or a campaign id, so a new campaign reappears) and the existing cookie-consent flag.

## Design tokens

All from `assets/css/main.css` `:root` — use the variables, not the literals. The
prototypes inline computed values only because the DC runtime has no stylesheet.

**Colour**

| Token | Value | Used for |
|---|---|---|
| `--bg` | `#211A13` | Page ground |
| `--bg-surface` | `#2A2119` | Selected-card ground only |
| `--gold-bright` | `#FCC647` | The single accent: marks, fill, progress, primary button, brand |
| `--gold` / `--gold-light` | `#E8961F` / `#F7A823` | Gradients elsewhere on the site; **not** used in the new chrome or wizard |
| `--text` | `#FCF8F3` | Headings, selected labels |
| `--text-soft` | `rgba(252,248,243,0.90)` | Field labels, unselected row labels, consent text |
| `--text-muted` | `rgba(252,248,243,0.74)` | App bar, body copy, social glyphs |
| `--text-faint` | `rgba(252,248,243,0.58)` | Placeholders, meta, help, descriptions |
| `--border` | `rgba(232,201,160,0.20)` | Frame edge |
| `--border-strong` | `rgba(232,201,160,0.36)` | Baselines, unchecked marks, search field |
| — | `rgba(232,201,160,0.16)` | **Hairlines** — dividers, progress track, section rules |
| — | `rgba(232,201,160,0.24)` | Footer wordmark |
| — | `rgba(252,198,71,0.10)` / `(0.12)` | Announce strip / selected card ground |
| — | `rgba(26,18,11,0.92)` / `#1A120B` | Header ground / sticky-bar ground and ink on gold |
| `--red` | `#F0436A` | Errors only |

Ink on `#FCC647` measures 12.6:1. The faintest text used, `rgba(252,248,243,0.58)` on
`#211A13`, measures 5.5:1 — above the 4.5:1 body threshold.

**Type**

| Role | Mobile | Desktop | Weight |
|---|---|---|---|
| Screen heading | 29px / 1.12 | 40px / 1.08 | 700 serif |
| Wordmark | 19–20px | 21px | Bebas, `.07em` |
| Row label | 15.5px / 1.3 | 15.5px / 1.3 | 500 → 700 selected |
| Field label | 13px | 13px | 650 |
| Input text | 16px | 15–16px | 400 |
| Body copy | 13–14.5px / 1.55 | 13.5–15px / 1.6 | 400 |
| Button | 15px | 13.5–15px | 800 |
| App bar / nav | 13px | 13.5px | 500–700 |
| Meta / help / desc | 12.5px | 12.5–13px | 400–700 |
| Section head | — | 12px, `.1em`, uppercase | 800 |

**Spacing** — `2 6 8 10 12 14 16 18 20 22 26 30 32 34 36 40 44 48 52 72`. Gutter 16–20px
mobile / 40–48px desktop.

**Radius** — 6px checkbox · 8px logo · 10px nav item, desktop button · 12px input, primary
button, icon button · 14px selection card · 20px dropzone (recreation only) · 26px device
frame (prototype only). **`--radius-md` (20px), `--radius-lg` (30px), `--radius-xl` (44px)
and the navbar's 24px are not used.**

**Elevation** — none. No shadows in the new chrome or wizard; hairlines and the single
accent do the separating. This deletes `0 12px 40px rgba(0,0,0,0.34)` from the navbar,
`0 8px 32px rgba(224,138,26,0.5)` from the Apply pill and `--glow-gold` from the logo.

**Target sizes** — 72px programme row · 58px course row · 56px header bar, menu row ·
54px footer group, review row · 50px primary button · 48px input, search, footer CTA ·
44px icon button, secondary button, social, legal link · 40px desktop nav item, step row ·
32px strip close · 24px checkbox and mark *inside* their rows (the row is the target).

## Assets

- `mam-academy-logo.svg` — copied from the repo root, unmodified. 2000 × 2000, transparent.
  Rendered at 30px (mobile bar, footer) and 32px (desktop bar, footer).
- **Icons: Font Awesome 6.5.0 Free Solid + Brands**, the same CDN stylesheet both pages
  already link. Solid: `magnifying-glass`, `xmark`, `arrow-left`, `arrow-right`, `check`,
  `chevron-down`, `chevron-right`, `plus`, `camera`, `envelope`, `phone`, `location-dot`,
  `lock`, `receipt`, `graduation-cap`, `music`, `compass`, `wand-magic-sparkles`,
  `bag-shopping`, `fire`, `chalkboard-user`, `paper-plane`, `circle-exclamation`,
  `arrow-up`. Brands: `instagram`, `x-twitter`, `facebook-f`, `youtube`.
  ⚠️ CLAUDE.md records a CDN-loaded script being load-bearing behind a fallback that was not
  one, **twice**. Icons degrading to blank squares offline is cosmetic, but **no control may
  depend on an icon to be identifiable** — every one above sits beside real text or carries
  an `aria-label`. The two bare icon buttons in the header (search, menu) need
  `aria-label="Search"` / `aria-label="Menu"` and `aria-expanded` on the latter.
- The `iconify-icon` web component used for the MetaMusic Order card's fire emoji is
  replaced by `fa-fire`; one icon library is enough.
- No photography or illustration. The desktop mega-menu spotlight image is removed. The
  headshot slot is user-supplied via the existing first-party uploader
  (`api/lib/uploads.php`, `POST /api/upload/headshot`).

## Invariants — do not regress

From the 2026-08-17 forms audit and CLAUDE.md. All are fixes that already shipped.

1. **The wizard stays a real `<form>`** with a `submit` listener per step, not click
   handlers. It was 51 inputs and 4 selects outside any form at all, which cost Enter-to-
   submit, autofill grouping, password-manager save and native validation.
2. **`novalidate` stays.** All five steps live in the DOM while hidden, so a `required`
   control on a hidden step makes the form unsubmittable with "An invalid form control is
   not focusable". Splitting step 1 into six screens **increases** the number of hidden
   required controls — this matters more after the change, not less.
3. **`required` marks exactly what `validateStep()` enforces unconditionally.** `city` is
   absent because the wizard does not validate it; `parentName`/`parentPhone` are absent
   because they apply only to minors.
4. **`#hsStatus role="status"` stays in the DOM.** The "a photo is required" branch used to
   write into an element that was never on the page; the only feedback was a border pulse.
5. **A `<label>` must wrap its control or carry `for`.** A sibling `<label>` with neither is
   a caption — it looks right and associates with nothing. That idiom accounted for 98
   unnamed controls across the admin and the three portals.
6. **The honeypot keeps its class**, not an inline style: `.hp-field` exists because
   `style="position:absolute;left:-9999px"` is the one thing a strict CSP cannot allow.
7. **The `<noscript>` explanation stays**, and `.js-only` keeps hiding wizard chrome until
   `main.js` puts `js` on `<html>`.
8. **`.skip-link` stays first in the body** and must still reach `#main` with the new
   header.
9. **The programme chooser's predicate stays shared.** `mam_summer_accepting($today)` must
   gate the chooser exactly when `POST /api/summer/apply` would accept — the chooser and the
   endpoint cannot disagree.
10. **The headshot chain has four independent failure points and all four have failed in
    production.** Do not touch the uploader. A URL that 404s is not a saved photo, and no
    branch may report success without a stored URL.
11. **Step 4 submit sends the confirmation email and invoice before payment.** The applicant
    must end up with those in hand whether or not they finish step 5 — the pay-later link in
    that email lands on `/book?ref=…`.

## Open questions

1. Menu glyph — two bars or three? Both are in the prototype's rationale; pick one.
2. Does the announce strip's dismissal persist across sessions, or per session? Keyed to
   campaign id either way.
3. Back-to-top on desktop: restore at `min-width:1024px`, or drop entirely?
4. The four footer group counts (5 / 6 / 12 / 3) are from today's link list — confirm the
   Portals group should be public-facing at all.
5. Does the focus-area taxonomy (Creation & Production / Leadership) need to survive the
   merge into one searchable course list, e.g. as group headers in the results?
