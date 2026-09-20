# Build notes — the student application (Part 2 of the 2026-09 handoff)

What shipped from `README.md` Part 2, the one instruction that was refused and
why, and the five defects that only appeared in a browser. Part 1 (the site
chrome) shipped separately; see `BUILD-NOTES-chrome.md`.

Files: `apply.php`, `assets/js/apply.js`, the `.a-*` block of
`assets/css/apply.css` (shared with the instructor form on purpose — anything
general belongs in `.step`/`.field`, not in either block).
Guarded by `api/tests/test_apply_wizard.php`; `api/tests/test_apply_form.php`
keeps the invariants about the `<form>` element itself.

## What changed

Step 1 asked seven questions and showed 38 choice cards on one ~4,600px
scroll, and level and expression appeared and disappeared with the programme so
the scroll length changed under the applicant mid-answer. Each question is its
own screen now; on a desktop they fold back onto one page beside a 320px
summary column.

| | before | after |
|---|---|---|
| Step 1 | 7 questions, 38 cards, ~4,600px | **7 screens**, one question each |
| Level / expression | `hidden` blocks that reflowed the page | **skipped screens**, in both directions |
| Step 2 | 15 fields, six of them address, two-up at 390px | **who / address / guardian**, one column |
| Review | a read-only dump, one Edit per section, price after the consents | **9 hairline rows, an Edit each**, price **before** the consents |
| Running total | a collapsed `<details>` at every screen size | a **column** from 1024px |
| Progress | five dots that sat still for the whole of step 1 | a 2px line that moves on **every** screen: 5 · 10 · 15 · 21 · 26 · 31 · 36 · 49 … |

Driven in Playwright at 320 · 390 · 430 · 768 · 1024 · 1280 · 1440, touch
emulation below 1024: zero horizontal overflow at every width, no console
errors, and the action bar clear of `window.mamDock` at all seven (the dock
measured 88–153px depending on how the cookie sentence wraps).

Behaviour verified by driving it, not by grepping: every validation gate holds
one screen at a time; skipping works forwards **and** backwards (back from the
major screen with `crash` chosen lands on programme); the guardian screen
appears for a 12-year-old and not for a 28-year-old; browser Back unwinds the
same screens the back control does and then leaves the wizard; a real photo
upload through the mounted widget still gates step 2; the review's Edit buttons
open the right screen; `?pathway=&mode=&discipline=&level=` still prefills; a
draft resumes on the screen it was left on; and with JavaScript disabled the
wizard chrome is hidden and the `<noscript>` explains why.

## The one instruction refused

**"Focus area + primary course merge … one searchable list of 14 rows instead."**
Not done. The two questions are kept as two screens, both in the new searchable
row treatment.

The handoff's reading is that they ask "two versions of what will you study".
They do not. `discipline` is an **instrument** — piano, clarinet, drums — and
CLAUDE.md records `mam_enrol_resolve()` refusing exactly those because they are
instruments and not courses. `program` is a **career focus** — film scoring,
music business, orchestration. There is no mapping from the eleven onto the
fourteen, and `applications.program` is a stored column that
`mam_validate_application()` checks against its own allowlist and **rejects the
submission without**. Merging them would have meant either dropping a question
the academy asks or writing one answer into two columns that mean different
things.

The handoff's own **open question 5** — "does the focus-area taxonomy need to
survive the merge, e.g. as group headers?" — is the same doubt. It survives as
its own screen, with Creation & Production / Leadership as group heads. Step 1
is therefore **seven** screens, not six; the progress figures the handoff gives
(6 · 12 · 18 · 24 · 30 · 36%) are reproduced as a band rather than as literals,
so the last question of the step still lands on 36% whatever the count.

If the academy would rather drop the focus area, that is a one-line change here
and a one-line change in `validation.php` — but it is their call, not a
styling decision.

## Five defects the browser found, that no static test could

- **96px of dead space above every question.** Each screen is a `<section>`,
  and `main.css` gives *every* `<section>` on the site
  `padding: clamp(56px,8vw,96px) clamp(16px,4vw,24px)`. MEASURED at 1280: each
  heading sat 96px down and 24px in from its own column. A block that reuses a
  sectioning element inherits the site's section rule; `.a-sub { padding: 0 }`
  says no to it explicitly.

- **The action bar sat 600px below the fold.** `position: sticky; bottom: 0`
  holds an element up only while there is content *after* it inside its
  containing block — and the bar is the form's last child, so sticky gave it
  nothing. MEASURED at 390: the bar's top was at y=1444 in an 844px viewport.
  It is `fixed` now, above `--a-dock-h`, the same arrangement the instructor
  form uses for the same reason.

- **The 2px progress line stayed on at 1280.** `html.js .js-only { display:
  revert }` is (0,2,1) and beats a bare `.a-prog { display: none }` (0,1,0)
  *even inside a media query*. The app bar was fine only because its rule
  happened to carry the `html.js` prefix already. **The sixth appearance of
  this trap in this one file.**

- **…and the same trap from the other side:** `.a-appbar { display: flex }` as
  a base rule is (0,1,0), exactly the same as `.js-only { display: none }`, so
  source order decided it — and this block is appended *after* `.js-only`.
  MEASURED with `javaScriptEnabled: false`: the app bar, the progress line and
  the action bar were all on screen over a form that cannot advance without
  them. The display now lives only on the `html.js` rule.

- **The progress bar ran backwards.** 9% → 18% → **15%** the moment "Certified
  Musician" added three screens, because the denominator was the live screen
  count and the live count depends on an answer not yet given. Until a
  programme is chosen the bar assumes the longest path and the counter says
  "Question 2" rather than "Question 2 of 4" — a total it might have to take
  back is worse than no total.

Also found and fixed in passing: the review rendered the stored headshot at its
natural width (a 350px slab among 54px rows — now a 48px thumbnail); the
review named the major `Drums` where the row the applicant clicked said
`Drums & Percussion`; `.apply-main` inherited a card shadow and a 1px top
highlight from `main.css`'s UI-polish rule, against the design's "elevation —
none"; and the back-to-top FAB painted over both this bar and the faculty
form's, so `cornerBlocked()` now yields to `.a-bar` and `.fac-bar` as it
already did to the cookie banner.

## Contract points that deliberately did NOT move

- **The draft key and shape.** `mam.apply.draft` is unchanged; `subStep` is
  added, and a draft written before the split has none, which resolves to the
  first screen of its step. `saveDraft()`/`loadDraft()` and the server mirror
  are untouched.
- **The `?pathway=&mode=&discipline=&level=&expression=` allowlist**, including
  its reflected-XSS guard (only values matching a rendered input enter `data`).
- **The headshot chain.** Four independent failure points, all four of which
  have failed in production; the uploader, `#hsStatus` and
  `MAM_HEADSHOT.mount()` are as they were. The only change is that the rule
  now *opens* the disclosure the widget lives behind — an error written into a
  collapsed `<details>` is an error nobody reads.
- **`novalidate`**, which matters more after the change rather than less: the
  split increases the number of `required` controls sitting on hidden screens.
- **Step 4's submit still sends the confirmation email and the invoice before
  payment**, so the applicant has both in hand whether or not they finish step
  5.

## Deviations worth a second opinion

- The review's action button reads **"Continue to payment"**, not the design's
  **"Pay $5"**. It submits the application and opens the payment step; it does
  not take a payment. The price is stated immediately above it.
- **`#cookieDecline` survives** (chrome part, restated here because the same
  reasoning applies): whether a consent notice offers a decline control is a
  legal decision, not a layout one.
