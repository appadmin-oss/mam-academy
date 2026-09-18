# Built — instructor application v2, 2026-09-18

`README.md` beside this file is the handoff, unmodified. This records what was
built from it, what was decided where the handoff left a decision open, and the
six defects that only appeared once the page was driven in a browser.

## Where it lives

| File | What changed |
|---|---|
| `instructor-apply.php` | Seven steps → four. App bar + 2px progress replace the announce bar, the 80px nav and the seven-dot stepper. |
| `assets/js/instructor-apply.js` | `STEPS = 4`; course list + highest-level radios; day-stacked week; one submit listener drives every step; a seven-step draft is migrated, not discarded. |
| `assets/css/apply.css` | The `.fac-*` block rewritten. The shared `.step` / `.field` / `.btn-step` rules are untouched, so the student wizard is unaffected. |
| `assets/js/main.js` | One word: `'instructor-apply'` added to the floating-CTA hide-list. See defect 1. |
| `api/tests/test_instructor_apply.php` | 44 static guards, one per handoff invariant plus the contract points. |

## The handoff's four open questions, as built

1. **The five questions cut from step 3** (CV link, qualifications, teaching
   philosophy, links, earliest start) are behind **"Add more detail
   (optional)"** on that step, which is the option the handoff itself offers
   ("do NOT delete those questions from the product"). They keep their ids,
   their validation and their place in the POST body. Nothing was dropped
   pending the faculty team's answer, because a disclosure is reversible in
   either direction and a deletion is not.
2. **Highest-level-only** is built as a presentation change.
   `collectSubjects()` expands the chosen index to the full 0..N set, so the
   API contract and the `UNIQUE(course, level)` index on
   `instructor_assignments` are untouched. If the panel later needs to record
   "teaches Pre-Diploma but not Certificate", the page changes and the endpoint
   does not.
3. **The programmes chip group is kept**, inside that same disclosure — the
   handoff's instruction was "decide before building; do not leave it
   half-wired", and the desktop summary column is a summary, not a place to put
   an input.
4. **The consent wording is the currently-approved wording, NOT the handoff's
   trim** — this is the one place the build departs from the design. The
   handoff's own open question 4 asks the Designated Safeguarding Officer to
   re-approve it, so the trim is not yet a design decision that has been made.
   Two of the changes are substantive: the draft drops "I have read and" from
   the policy limb, and widens "any safeguarding concern" to "any concern". The
   **layout** is the handoff's throughout (no card, no uppercase heading, 24px
   box, 14px/1.6 text). Swapping the two strings once the DSO signs off changes
   nothing else; a test pins both clauses so the swap is deliberate.

## Found by driving it, not by reading it

Each of these passed lint, parsed cleanly and looked right in the source.

1. **A *student* Apply pill sat on top of the faculty form's Submit button.**
   `main.js` removes its floating CTA on transactional pages by matching the
   whole slug against `['apply', 'checkout', …]` — and `'apply'` does not match
   `'instructor-apply'`. This is the identical blind spot the page's own
   `data-no-lead-cta` exists for in `lead.js`, which path-tests for `/apply`.
   Fixed in the hide-list rather than with a CSS patch, so the element is
   removed rather than merely invisible; the CSS rule is kept as a second line
   against a stale cached `main.js`.
2. **The cookie banner covered the action bar.** The banner lives in
   `window.mamDock`, a fixed column-reverse stack at `z-index: 950` — above a
   form control **on purpose**, because a consent notice must not be covered.
   So the bar rides above the dock: `bottom: var(--fac-dock-h)`, measured by
   `trackDock()`. It cannot be a constant — the banner's height depends on how
   its sentence wraps, which depends on the accessibility toolbar's text size.
3. **"Loading the application…" never went away.** `gate.hidden = true` ran and
   the attribute was on the element. `html.js .js-only { display: revert }` is
   specificity (0,2,1) and `[hidden]` is a UA rule, so `revert` won. Fixed
   generally with `html.js .js-only[hidden] { display: none }` rather than one
   line per element.
4. **The app bar rendered 76px instead of 48** — `display: block`, for the same
   reason: `html.js .js-only` beat `.fac-appbar { display: flex }` on an
   equal-specificity tie decided by source order. It had to be restated *after*
   that rule, not merely more loudly.
5. **The back control measured 16×28.** Its only content is an icon, and with
   Font Awesome blocked an icon has no box. A control may not depend on an icon
   even for its *size*; `min-height`/`min-width` are stated as well as the
   padding.
6. **Both consents were ticked and both refusals were still on screen.** Errors
   were only recomputed on the next press of the button. `revalidate()` now
   re-checks a step that has already complained, quietly, as the candidate
   answers.

## Verified in a browser, not asserted in a test

Playwright/Chromium at **320 / 390 / 430 / 900 / 1000 / 1280**. No horizontal
overflow at any of them; no page errors at any of them.

- Targets, measured: course row 58 · week row 52 · primary button 50 · input,
  mode button and headshot row 48 · grade chip 44 · back and "Save & exit" 44 ·
  part toggle 40. App bar 51px (the design's 48 plus the 3px the 44px floor
  costs on a 13px line).
- Layout: 1180 grid with the 320 summary column at 1280; single 640 centred
  measure below 1024; logo, lede, step list and the "· draft saved" suffix all
  appear only where the handoff puts them.
- The full journey submits (`FAC-1`), and on success the app bar, the progress
  line and the draft all go.
- A seven-step draft migrates: `subjects{'piano|precert','piano|prediploma'}`
  becomes `piano → prediploma`, the split referee contacts fold into one field,
  and `step: 6` clamps to 4 of 4.
- **The headshot chain, both ends** (handoff invariant 9):
  `MAM_HEADSHOT.upload()` *and* the mounted widget each store a photo, and the
  returned `/api/media/headshot?k=…` re-fetches `200 image/jpeg`. The row reads
  "Added" only once a url exists.
- Reduced motion collapses every duration; nothing focusable sits inside a
  folded disclosure or an inactive step; zero controls without an accessible
  name; Enter in a text field advances the step and focus lands on the heading.

⚠️ The suite's guards are **static**. They do not measure a box, and on this
page a stylesheet rule that read correctly lost a specificity tie — twice.
Drive it.

## A second pass, by looking at every state (2026-09-18)

The first pass drove the four form steps. These came from rendering the states a
happy-path walkthrough never reaches — a group with a legend, an open
disclosure, an error, the gate, the success screen — and they are all things the
source read correctly.

1. **Every grouped control sat in a grooved box.** A `<fieldset>` carries
   `border: 2px groove` AND `min-width: min-content` from the UA stylesheet, and
   setting `display` clears neither. Teaching mode, the week and the programmes
   all rendered inside a border with the legend notched into its top edge — the
   one piece of chrome this redesign removes everywhere else. `min-width` was the
   invisible half: it is why five programme chips could not shrink to a phone.
2. **A `<legend>` nested in a wrapper div is not a caption.** The week's legend
   sat inside a flex row so the timezone could align right; browsers render that,
   but the group loses its accessible name and the markup is invalid. The legend
   is now the fieldset's first child with the timezone inside it.
   ⚠️ And the reset must NOT say `width: 100%` — as a flex item the legend
   already stretches, and stating it squeezed "Lagos time" onto two lines.
3. **The programmes group is not the teaching-mode group.** Two or three short
   words divide a row between them; five names up to "Grand Certified Program"
   at `flex: 1` came out ~60px wide with the text broken mid-word. They size to
   their content and wrap.
4. **"What we check" left its sentence.** `<details>` is FLOW content, not
   phrasing content, so the parser CLOSES an open `<p>` when it reaches one — the
   inline disclosure became a block 144px down whatever `display: inline` said.
   The paragraph is a `<div>` now. A test pins it, reading the markup with the
   PHP comments stripped, because the comment explaining the fix quotes both tags.
5. **Both consent refusals stacked below both checkboxes.** Each now sits under
   the control that satisfies it.
6. **The gate and the success screen had no branding and no way home.** The site
   nav is hidden on this page, so a candidate refused at the invite gate was left
   on a bare wall of text. The app bar is now present in every state: the logo and
   title are a link home, shown at every width OUTSIDE the form, and the step
   position and "Save & exit" appear only with the form. One `data-fac-state` on
   `<body>` drives it. ⚠️ The bar also has to come FIRST in the markup — it was
   after `#facGate`, so it rendered underneath the refusal it was meant to head.
7. **100px of dead ground above the footer** on those two screens: the page
   reserved room for a fixed action bar that only exists while the form is open.
8. **Three shared components were still on the student wizard's palette** — the
   emerald success ring, the emerald resume banner, and the uploader's 2px dashed
   orange dropzone with an orange wash, in a design whose own token table says
   "Elevation: none — hairlines and the single accent do the separating". All
   three are re-skinned **scoped to `body[data-fac-app]`**, so the student wizard
   is untouched. The uploader override is purely visual and touches no class,
   markup or logic of `headshot.js` (invariant 9); it wins on specificity because
   that file injects its stylesheet into `<head>` at mount time.

Re-verified at 320/390/430/900/1000/1280: no horizontal overflow, no fieldset
borders, "Lagos time" on one line, programme chips unclipped and at or above the
44px touch floor, the disclosure inline, no page errors, and the full journey
still submits. ⚠️ Repeated real submissions from one address exhaust
`mw_rate_limit('faculty_apply', 6, 600)` — a local run that suddenly "fails to
submit" is usually that, and the bucket lives in `rate_limits`.
