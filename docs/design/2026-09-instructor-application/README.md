# Handoff: Instructor Application — mobile-first rework

## Overview

`/instructor-apply` (`instructor-apply.php` + `assets/js/instructor-apply.js` + the
instructor block of `assets/css/apply.css`) is the faculty application: a seven-step
JS wizard with a course × level matrix, an availability grid and a safeguarding
section with two referees.

This bundle restructures it **for a phone first, then scales up** — no new visual
language, no new tokens, no new libraries. Same dark candlelit palette, same Inter /
Cormorant Garamond pairing, same Font Awesome 6.5 icon set, same catalogue data.

The work it does:

| Change | Why |
|---|---|
| **7 steps → 4** | Training + Experience + Availability collapse into "Track record"; Review folds into submit. Fewer abandonment cliffs on the academy's faculty funnel. |
| **Courses first, then levels** | Today all 12 courses render all 4 levels at once — 48 checkboxes before the applicant has said anything. Now: 12 rows; the level control appears only under a chosen course. |
| **Availability stacks by day** | The current grid is `84px + 7 × minmax(52px,1fr)` with `min-width:460px` inside a 358px card — it scrolls sideways on every phone. Now one row per day, three part toggles. |
| **Much less chrome** | The announce bar (≈37px) + 80px nav + 44px card radius + 3px gradient rule push the first question below the fold. Replaced by a 2px progress line and a single-row app bar. |
| **Less reading per step** | One heading per screen. Every subtitle that restated its title is cut; the safeguarding detail collapses behind a "What we check" disclosure. The commitment sentence itself stays visible — safer-recruitment guidance requires it where the candidate reads it. |
| **Referee 2 folded away** | Until referee 1 is complete, and down to three fields (name, relationship, email *or* phone) from five. |

## About the design files

The files in this bundle are **design references written in HTML** — prototypes of the
intended look and behaviour, **not production code to paste in**.

The target here is the existing MAM Academy codebase: server-rendered PHP with no build
step, vanilla ES5-style JS in `assets/js/`, hand-written CSS in `assets/css/`. So the
implementation route is:

1. Edit `instructor-apply.php`'s markup to the new four-step structure.
2. Extend the instructor block of `assets/css/apply.css` (the `.fac-*` rules) — this file
   is deliberately shared with the student wizard so the two forms cannot drift into two
   house styles. Keep that property: anything general goes in the shared `.step` / `.field`
   / `.btn-step` rules, anything instructor-specific in `.fac-*`.
3. Adapt `assets/js/instructor-apply.js` — `renderMatrix()`, `renderChips()`,
   `renderAvailability()`, `validateStep()`, `showStep()`, the draft `saveDraft`/`loadDraft`
   keys and the step count in `facProgress`.

The prototypes are single-file streaming components (`.dc.html`); open any of them directly
in a browser to inspect computed values. `support.js` must sit beside them.

**Do not treat the prototypes as a rewrite of the page.** Everything the 2026-08-17 forms
audit fixed must survive the restructure — see "Invariants" below.

## Fidelity

**High-fidelity.** Colours, type sizes, spacing, radii and target sizes are final and
measured, and every value traces to a file in the repo (`assets/css/main.css` `:root`,
`assets/css/apply.css`, the catalogue seed in `api/lib/db.php`). Recreate pixel-perfectly.

Two contractual floors, carried over from the guardian redesign and already stated twice in
`apply.css`, are honoured throughout and must not regress:

- **Interactive targets ≥ 44px on touch.** Desktop may use the design's tighter 38px
  control height, gated behind `@media (min-width:900px) and (pointer:fine)` — that is how
  the current file does it.
- **No running text below 12px.** At a 16px root, `0.74rem` computes to 11.84px, which is
  under the floor — hence the `.step-num` comment in `apply.css`. The prototypes use
  12px / 12.5px / 13px for meta text.

## Files

| File | What it is |
|---|---|
| `Instructor Application - Quiet.dc.html` | **The design to build.** Four mobile screens at 390px + the 1280px scale-up. |
| `Instructor Application - Mobile First.dc.html` | Earlier iteration of the same four steps, kept for reference — tiles instead of a list, filled inputs instead of baselines, a gold levels panel. Superseded. |
| `Instructor Application - Current.dc.html` | Faithful 390px recreation of the page **as shipped today**, all seven steps. Use it to diff against. |
| `mam-academy-logo.svg` | Copied from the repo root, unmodified. |
| `support.js` | Runtime the `.dc.html` prototypes need in order to open. Not part of the design. |
| `screenshots/` | Rendered captures of the five final screens — `01-courses`, `02-you`, `03-track-record`, `04-references` (each 390px wide, captured at 2×) and `05-desktop-1280` (1×). The logo appears blank in the desktop capture: the rasteriser does not inline external SVGs. It loads correctly in the browser. |

---

## Screens

### 1 · Courses  (`data-screen-label="Quiet 01 Courses"`)

**Purpose** — Declare which of the 12 catalogue courses you can teach, and how far you can
take a student in each.

**Layout** — Single column, 390px frame.

- App bar: `padding:16px 20px 14px`, `display:flex`, `justify-content:space-between`.
  Left `1 of 4`, right `Save & exit`. Both 13px / 700 / `rgba(252,248,243,0.74)`, tap
  padding `6px 2px`.
- Progress: 2px tall, track `rgba(232,201,160,0.16)`, fill `#FCC647` at 25 / 50 / 75 / 100%.
- Body: `padding:26px 20px 100px` (the bottom value clears the sticky bar), `display:flex`,
  `flex-direction:column`, `gap:22px`.
- Heading: Cormorant Garamond 700, 29px, `line-height:1.12`, no eyebrow, no subtitle.
- Course list: `display:flex; flex-direction:column`. Each row is a wrapper with
  `border-bottom:1px solid rgba(232,201,160,0.16)`.

**Course row**

- Head: `display:flex; align-items:center; gap:14px; min-height:58px`.
- Mark: 24 × 24, `border-radius:50%`. Unselected `border:1.5px solid rgba(232,201,160,0.36)`.
  Selected `background:#FCC647`, containing `<i class="fa-solid fa-check">` at 11px in `#1A120B`.
- Name: `flex:1; min-width:0`, 15.5px, `line-height:1.3`. Unselected 500 /
  `rgba(252,248,243,0.90)`; selected 700 / `#FCF8F3`.
- Core marker (the four `kind='core'` courses only): 12px / 700 / `.08em` / uppercase /
  `rgba(252,248,243,0.58)`, `flex-shrink:0`, right-aligned. **This replaces the two section
  headings** ("Core courses" / "Instruments & specialisms") and their helper lines.

**Level control** — rendered only when the row is selected, `padding:0 0 16px 38px` (the
38px indent aligns it past the mark):

- Label `up to`, 12.5px, `rgba(252,248,243,0.58)`, `flex-shrink:0`.
- Four grade chips `1-2` `3-4` `5-6` `7-8`, each `flex:1; min-width:0; min-height:44px`,
  `border-radius:8px`, 12.5px / 650, centred.
  Off: `border:1.5px solid rgba(232,201,160,0.24)`, `rgba(252,248,243,0.58)`.
  On: `border:1.5px solid #FCC647`, `background:rgba(252,198,71,0.16)`, `#FCF8F3`.
- Semantics: **highest level only** — everything below it is assumed. This is a change from
  the current form, where each of the four levels is an independent checkbox. If the API
  must keep receiving the full set, expand client-side in `collectSubjects()`: chips 0..N
  where N is the chosen index.

**Sticky bar** — `position:absolute; left:0; right:0; bottom:0`, `padding:14px 20px 20px`
(use `max(20px, env(safe-area-inset-bottom))` in production), `background:#1A120B`,
`border-top:1px solid rgba(232,201,160,0.16)`, `justify-content:space-between`.
Left: `2 chosen`, 13px / 650. Right: primary button — `min-height:50px`, `padding:0 26px`,
`border-radius:12px`, **flat `#FCC647`** (not the gradient), `#1A120B` text, 15px / 800,
label `Next` + `fa-arrow-right` at 12px.

**Copy** — `What do you teach?` is the whole of the heading. The current page's
"Choose every course you are qualified to teach…" paragraph is deleted; its one load-bearing
idea ("claim what you can genuinely teach") survives on desktop, where there is room.

---

### 2 · You  (`Quiet 02 You`)

**Purpose** — Contact details, teaching mode, optional headshot.

- App bar left becomes `<i class="fa-solid fa-arrow-left"> 2 of 4`.
- Heading `How do we reach you?`, same type as screen 1.
- Four fields, `gap:18px`, each a `<label style="display:flex;flex-direction:column;gap:8px">`:
  - Label 13px / 650 / `rgba(252,248,243,0.90)` — **sentence case, not the current
    uppercase `.field-label`**.
  - Input: **baseline, not a filled box.** `border:0; border-bottom:1.5px solid
    rgba(232,201,160,0.36)`, `padding:0 2px`, `min-height:48px`, `font-size:16px`
    (16px is mandatory — iOS zooms the viewport on focus below it).
  - Help text 12.5px / `rgba(252,248,243,0.58)`, only on Email
    ("Becomes your portal sign-in if you join"). The other three helpers are cut.
  - Fields: Full name (`autocomplete="name"`), Email (`email`), Phone (`tel`),
    City (`address-level2`).
- "You can teach": two options, `flex:1`, `min-height:48px`, `border-radius:10px`.
  Selected `border:1.5px solid #FCC647`, `background:rgba(252,198,71,0.12)`.
  Unselected `border:1.5px solid rgba(232,201,160,0.30)`, `rgba(252,248,243,0.74)`.
  (Moved here from the current step 1 — it is a logistics question, not a teaching one.)
- Headshot: one row above a `border-top:1px solid rgba(232,201,160,0.16)`, `gap:14px`.
  48px circle, `border:1.5px solid rgba(232,201,160,0.36)`, `fa-camera`; label
  `Add a headshot` 14px / 650; `Optional` pushed right with `margin-left:auto`.
  **Replaces the 220px dashed dropzone**, which on a 390px screen is a third of the viewport
  for an optional field. Tapping it expands the existing `MAM_HEADSHOT.mount()` widget in
  place — keep that widget and its `#hsStatus` `role="status"` region exactly as they are.
- Sticky bar: button only, `justify-content:flex-end`.

---

### 3 · Track record  (`Quiet 03 Track record`)

**Purpose** — Absorbs the current steps 3 (Training), 4 (Experience) and 5 (Availability).

- Heading `Tell us about your teaching`.
- Biography: same baseline treatment, `min-height:120px`, `line-height:1.55`,
  `padding:14px 0 0`, `display:block`. Helper `Becomes your faculty profile.` 12.5px.
  Keep the 40-character minimum from `validateStep()`.
- Years teaching / Hours a week: side by side, `gap:20px`, `flex:1` each,
  `inputmode="numeric"`.
- "When could you teach?" — label 13px / 650, `Lagos time` 12.5px / `0.58` on the right.
  **One row per day**, `display:grid; grid-template-columns:46px 1fr 1fr 1fr; gap:8px`,
  `min-height:52px`, `border-bottom:1px solid rgba(232,201,160,0.16)`.
  Day abbreviation 13px / 650 / `rgba(252,248,243,0.74)`.
  Three part toggles per row, `min-height:40px`, `border-radius:8px`, 12.5px / 650,
  labels `Morning` / `Afternoon` / `Evening`.
  Off `border:1.5px solid rgba(232,201,160,0.20)`, `rgba(252,248,243,0.58)`.
  On `background:rgba(252,198,71,0.22)`, `border:1.5px solid #FCC647`, `#FCF8F3`.
  The state key stays `state.avail['mon:morning']` — unchanged, so drafts and the API
  payload are compatible.
- Cut from here: the CV link field, the qualifications textarea, the "How do you teach?"
  textarea, the "Links" field and the "Earliest you could start" date. **Do not delete
  those questions from the product** — move them to the post-submit interview booking, or
  restore them behind a "Add more detail (optional)" disclosure on this step. Confirm with
  the faculty team before dropping any.

---

### 4 · References  (`Quiet 04 References`)

**Purpose** — Two referees and the two safeguarding consents.

- Heading `Two people we can call`, then one paragraph, 14.5px / `line-height:1.55` /
  `rgba(252,248,243,0.74)`:
  *"Both references are taken up before any offer. Contacts only — never certificates or
  document numbers."* followed by `What we check` as an inline disclosure trigger in
  `#FCC647` / 650 / `white-space:nowrap`.
  **The 164-word gold `.fac-safenote` panel becomes this one sentence plus the disclosure.**
  The disclosure body holds the rest verbatim. The commitment sentence must stay on the
  visible surface — it is the part safer-recruitment guidance requires in the advert itself.
- Referee 1: three baseline fields (Name / How do they know you? / Email or phone),
  `gap:18px`. No card, no border, no uppercase `REFEREE 1` heading.
- Referee 2: a single row between two hairlines, `min-height:56px`, `gap:14px` —
  `fa-plus` at 13px in `#FCC647` (20px wide, centred) + `Add second referee` 14.5px / 650
  in `#FCC647`. Expands to the same three fields. Still **required** before submit —
  `validateStep()`'s `err-referees` rule is unchanged; the fold is progressive disclosure,
  not a relaxation.
- Consents: two rows, `gap:16px`, no card. 24 × 24 checkbox,
  `border:1.5px solid rgba(232,201,160,0.36)`, `border-radius:6px`, `margin-top:2px`;
  text 14px / `line-height:1.6` / `rgba(252,248,243,0.90)`. Both `required`.
  Wording trimmed but legally identical in substance — have the DSO re-approve it.
- Sticky button: `Submit` + `fa-paper-plane`.

---

### Desktop · 1280px  (`Quiet desktop`)

**The form stays one column.** The extra width buys a running summary, not a second column
of questions — a two-column list of variable-height rows produced misaligned hairlines and
read as two unrelated lists.

- App bar `padding:20px 48px 18px`: 30px logo (`border-radius:8px`,
  `border:1px solid rgba(224,138,26,0.4)`) + `Faculty application · 1 of 4` 13.5px / 700;
  `Save & exit` right. Same 2px progress line.
- Body: `display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:0 72px;
  align-items:start; padding:44px 48px 52px`. The tracks fill the content box — a fixed
  left measure orphans the frame's right third.
  `align-items:start` is load-bearing — with the default `stretch`, a course row's
  `border-bottom` paints at the bottom of the stretched track instead of under its content.
- Left column: heading at 40px / `line-height:1.08`, the identical course list, then the
  actions row above a `border-top` — button + `2 chosen · draft saved` 13.5px.
- Right column: `padding-left:40px; border-left:1px solid rgba(232,201,160,0.16)`,
  `gap:28px`.
  - Step list: four rows, `min-height:40px; gap:12px`. 7px dot — current `#FCC647`,
    others `rgba(232,201,160,0.36)`. Label 14px; current 700 / `#FCF8F3`, others 500 /
    `rgba(252,248,243,0.58)`.
  - `So far` 12.5px / 700 / `.1em` / uppercase / `0.58`, above a `border-top`, then one
    entry per chosen course: name 14.5px / 650 / `#FCF8F3`, detail 13px / `0.58`
    (`up to Grade 3-4 · 6 years`). Closing line: *"Highest level is enough — everything
    below it is assumed."*
  - Make it `position:sticky; top:24px` in production.

**Breakpoints** — the repo's own: `max-width:560px` (phone), `max-width:960px` (below
desktop), `min-width:900px and (pointer:fine)` (the 38px control height). The summary column
appears at `min-width:1024px`; between 960 and 1024 the form is the single column, centred
at `max-width:640px`. Cap the whole grid at `max-width:1180px; margin:0 auto` so it does not
stretch past a comfortable measure on very wide displays.

---

## Interactions & behaviour

- **Course row tap** toggles selection; the level control expands beneath it. Reuse
  `syncMatrixCard()` — it already toggles `.is-chosen` and reveals the per-course
  years field on the same trigger.
- **Grade chip tap** sets the highest level for that course (radio semantics within the row).
- **Step navigation** — `showStep(n)` unchanged. Keep the `#facAnnounce`
  `role="status" aria-live="polite"` announcement: moving focus alone never says *which*
  step of how many.
- **Progress fill** — width `(step + 1) / 4 * 100%`.
- **Autosave** — unchanged `saveDraft()` / `loadDraft()` and the resume banner. Note
  `.resume-banner[hidden]{display:none}` and `.apply-stepper[hidden]{display:none}` in
  `apply.css`: author `display:flex` outranks the UA's `[hidden]` rule, and a first-time
  visitor was once told "We saved your draft on this device" over an empty form. Any new
  `display:flex` element that also carries `hidden` needs the same guard.
- **Validation** — `validateStep()` rules are unchanged, remapped to four steps:
  step 1 `err-subjects`; step 2 name + email; step 3 bio ≥ 40 chars; step 4
  `err-referees` + both consents.
- **Errors** — keep `NOTICE_TONES` / `setNotice(html, tone, takeFocus)`. Server field
  errors focus the first rejected field, a dropped connection is amber not red, and a
  successful retry calls `clearNotice()`.
- **Transitions** — the existing `stepFade` 0.4s `cubic-bezier(0.4,0,0.2,1)`. Level
  expansion: height/opacity over 180ms, same easing. Everything animated needs a
  `prefers-reduced-motion: reduce` branch that keeps the cue and drops the movement.

## State

No new state. The existing shape in `instructor-apply.js` carries over:

```
state = {
  step, subjects {'course|level': true}, years {course: n},
  programmes {}, modes {}, avail {'mon:morning': true},
  headshotUrl, catalogue {courses, levels, programmes, modes}
}
```

Two notes: `step` now ranges 0–3; and if the highest-level semantics ship, expand the
chosen index to the full `subjects` set at collect time so the API contract is untouched.

The `programmes` chip group is **not** in the four-step design — it is optional on the
current form ("Leave it blank if you are happy to be considered for any"). Either drop it or
put it in the desktop summary column. Decide before building; do not leave it half-wired.

## Design tokens

All from `assets/css/main.css` `:root` — use the variables, not the literals below. The
prototypes inline the computed values only because the DC runtime has no stylesheet.

**Colour**

| Token | Value | Used for |
|---|---|---|
| `--bg` | `#211A13` | Frame / page ground |
| `--bg-surface` | `#2A2119` | — (v2 drops the second surface) |
| `--bg-raised` | `#352A1F` | — |
| `--bg-elevated` | `#4A3B2B` | — (v2 drops filled inputs) |
| `--gold` | `#E8961F` | Gradients on the rest of the site |
| `--gold-light` | `#F7A823` | " |
| `--gold-bright` | `#FCC647` | The single accent: marks, fill, progress, primary button |
| `--text` | `#FCF8F3` | Headings, selected labels |
| `--text-soft` | `rgba(252,248,243,0.90)` | Field labels, consent text |
| `--text-muted` | `rgba(252,248,243,0.74)` | App bar, day labels, body copy |
| `--text-faint` | `rgba(252,248,243,0.58)` | Placeholders, meta, help |
| `--border` | `rgba(232,201,160,0.20)` | Frame edge |
| `--border-strong` | `rgba(232,201,160,0.36)` | Baselines, unchecked marks |
| — | `rgba(232,201,160,0.16)` | **Hairlines** — list dividers, progress track, section rules |
| — | `#1A120B` | Ink on gold; sticky-bar ground |
| `--red` | `#F0436A` | Errors only |

Button ink on `#FCC647` measures 12.6:1. The faintest text used, `rgba(252,248,243,0.58)`
on `#211A13`, measures 5.5:1 — above the 4.5:1 body-text threshold.

**Type** — `--font-body` Inter; `--font-serif` Cormorant Garamond (headings only);
`--font-display` Bebas Neue (not used in v2 — the wordmark is gone from the form chrome).

| Role | Mobile | Desktop | Weight |
|---|---|---|---|
| Screen heading | 29px / 1.12 | 40px / 1.08 | 700 serif |
| Field label | 13px | 13px | 650 |
| Input text | 16px | 16px | 400 |
| Course name | 15.5px / 1.3 | 15.5px / 1.3 | 500 → 700 selected |
| Body copy | 14.5px / 1.55 | 14.5px / 1.55 | 400 |
| Button | 15px | 15px | 800 |
| App bar | 13px | 13.5px | 700 |
| Meta / help / chips | 12.5px | 13px | 500–700 |

**Spacing** — multiples of 2 on an 8-ish rhythm: `2 6 8 10 12 14 16 18 20 22 26 28 30 40
48 56 72`. Screen gutter 20px mobile / 48px desktop. Block gap 22px mobile / 30px desktop.

**Radius** — 6px checkbox · 8px grade & part chips · 10px mode buttons · 12px primary
button (`--radius-sm`) · 26px device frame (prototype only). **`--radius-md` (20px),
`--radius-lg` (30px) and `--radius-xl` (44px) are not used** — the 44px card is one of the
things v2 removes.

**Elevation** — none. No shadows anywhere in the form; hairlines and the single accent do
the separating. The one shadow retained is the logo's `--glow-gold` in the desktop app bar.

**Target sizes** — 58px course row · 52px week row · 50px primary button · 48px input,
mode button, headshot circle · 44px grade chip · 40px part toggle, desktop step row ·
24px checkbox and mark *inside* a 58px row (the row is the target, not the mark).

## Assets

- `mam-academy-logo.svg` — copied from the repo root, unmodified. 2000 × 2000, transparent.
  Rendered at 34px (mobile nav, per `main.css` line 1228) and 30px (desktop app bar).
- **Icons: Font Awesome 6.5.0 Free Solid**, the same CDN stylesheet `instructor-apply.php`
  already links. Used: `fa-check`, `fa-arrow-right`, `fa-arrow-left`, `fa-plus`,
  `fa-camera`, `fa-paper-plane`, `fa-minus` (annotations only).
  ⚠️ CLAUDE.md records a CDN-loaded script being load-bearing behind a fallback that was
  not one, **twice** (the apply wizard's phone field, the portal's toast). Icons degrading
  to blank squares offline is cosmetic, but no control may depend on an icon to be
  identifiable — every one above sits beside real text or has an `aria-label`.
- No photography or illustration. The headshot slot is user-supplied via the existing
  first-party uploader (`api/lib/uploads.php`, `POST /api/upload/headshot`).

## Invariants — do not regress

From the 2026-08-17 forms audit and CLAUDE.md. All of these are fixes that already shipped.

1. **Each step's fields stay inside a `<form>`**, with a `submit` listener, not click
   handlers. The wizard having no `<form>` was the audit's headline finding.
2. **`novalidate` stays.** Every step lives in the DOM while hidden, so a `required`
   control on a hidden step makes the form unsubmittable with "An invalid form control is
   not focusable" — an error the candidate can neither see nor fix.
3. **`required` marks exactly what `validateStep()` enforces unconditionally**, and no more.
4. **Every availability cell keeps its own `aria-label`** (`"Mon Morning"`). "Mon" and
   "Morning" are adjacent only visually.
5. **A `<label>` must wrap its control or carry `for`.** A sibling `<label>` with neither is
   a *caption* — it looks right and associates with nothing. That idiom accounted for 98
   unnamed controls across the admin and the three portals.
6. **The honeypot keeps its class**, not an inline style: `.hp-field` exists because
   `style="position:absolute;left:-9999px"` is the one thing a strict CSP cannot allow.
7. **The `<noscript>` explanation stays**, and `.js-only` keeps hiding the wizard chrome
   until `main.js` puts `js` on `<html>`. The matrix is built from the live catalogue, so
   with scripting off the honest answer is to say so and offer the email route.
8. **`data-no-lead-cta data-no-lead-exit` stay on `<body>`.** `lead.js` path-tests for
   `/apply`, which `/instructor-apply` does not match — without these a prospective
   *teacher* is shown a sticky bar advertising the *student* wizard, over this form's own
   controls.
9. **The headshot chain has four independent failure points and all four have failed in
   production.** Do not touch the uploader. If you must, verify in a real browser: drive
   `MAM_HEADSHOT.upload()` *and* the mounted widget, then re-fetch the returned URL. A URL
   that 404s is not a saved photo, and no branch may report success without a stored URL.
10. **No certificate numbers, document scans or offence data anywhere on this form.**
    `vetting_records` deliberately stores no check content — a verifier records the
    *outcome*, and the evidence is filed offline. Adding an upload field here would create
    exactly the NDPA liability that design avoids.

## Open questions for the faculty team

1. The five fields cut from step 3 (CV link, qualifications, teaching philosophy, links,
   earliest start) — drop, move to the interview, or restore behind an optional disclosure?
2. Highest-level-only vs. independent level checkboxes: does the panel ever need to know
   that somebody teaches Pre-Diploma but *not* Certificate?
3. Keep the optional "Programmes you could teach on" chip group, or retire it?
4. DSO sign-off on the trimmed consent wording and on the safeguarding statement moving to
   one sentence + disclosure.
