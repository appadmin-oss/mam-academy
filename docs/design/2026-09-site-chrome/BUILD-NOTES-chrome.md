# Build notes — site chrome (Part 1 of the 2026-09 handoff)

What shipped from `README.md` Part 1, what the browser changed, and the four
places the handoff contradicts itself or the repo. Part 2 (the student
application) is a separate piece of work and is **not** in this commit.

Files: `partials/header.html`, `partials/footer.html`, the nav/footer/cookie
blocks of `assets/css/main.css`, and `initNav` / `initAnnounce` /
`initFooterGroups` / `initBackToTop` in `assets/js/main.js`.
Guarded by `api/tests/test_site_chrome.php`.

## Measured, at nine widths

Driven in Playwright (320 · 360 · 390 · 430 · 768 · 900 · 1024 · 1280 · 1440),
touch emulation below 1025. Static tests do not measure boxes; every number
below came out of a real layout.

| | before | after |
|---|---|---|
| Chrome height, phone | 145px (and it reflowed) | **101px**, identical at every width |
| Announce message | wrapped to 2 lines at 390 | **1 line**, ellipsised, at all nine |
| Horizontal overflow | — | **0px** at all nine |
| Bottom-docked surfaces, first visit | 4 (pill · CTA bar · cookie · back-to-top, the last not even in the dock) | **2** |
| Bar height, desktop | 80px floating pill | **64px** flush |
| Menu | 4 tiles + 5 accordions + 40 links | **7 rows + 5 minor links** |
| Footer | 35 links in one run | **4 collapsed groups**; columns from 1025px |
| Footer wordmark, phone | 66px stretched 1.18× | **34px**, natural |

Also verified in the browser, not by grep: the search button opens the menu
and lands the caret in the field; Escape closes it and returns focus to the
opener; the accordion chevron rotates and its `aria-expanded` follows; body
scroll locks while the menu is open; the announce dismissal survives a reload
**and reappears when `MAM_CONFIG.intake.label` later changes**; all four
`<details>` are closed on a phone and open at 1025px; and the three mega menus
render 2/3/3 columns with no wrap and no overflow at 1280.

## Four places the handoff had to be resolved

1. **"Exactly two icon buttons" vs "the bar already carries Apply."** The
   header spec puts only search and menu in the mobile bar; the rationale for
   deleting the floating pill and the back-to-top FAB says the bar carries
   Apply. Its own frame does not. Both removals are implemented, and
   `.btn-nav-apply` is kept in the bar so the justification is true —
   otherwise touch loses every persistent Apply path the moment the announce
   strip and `lead.js`'s bar are dismissed (the bar remembers for 30 days).
   It gives way below 360px, where the strip and the menu carry Apply instead.

2. **The announce close button: 32px in the prose, 44px in the frame.** 44 on
   touch, 32 from `(min-width:1025px)` — which is also exactly what the
   desktop frame draws. The 32px figure would have broken the handoff's own
   "interactive targets ≥ 44px on touch" floor.

3. **"Decline stays available inside Details."** Not done. Whether a consent
   notice offers a decline control is a legal decision, not a layout one, so
   `#cookieDecline` keeps its box and loses its padding. The banner is one
   row as asked.

4. **The seven `apply?discipline=` deep links.** Removed from the menu, as
   asked. **Kept in the desktop mega menu**: Part 2 of the same handoff says
   to map the old optgroup values onto the new searchable list "so
   `?discipline=` prefills and stored drafts still resolve", so the link is a
   working shortcut, not the mismatch the removal is about. What it *was* on
   a phone is 13 of the menu's 40 links.

## Three defects the browser found, that no static test could

- **The footer wordmark was invisible.** `initFooterWordmark` keyed its
  clip-path reveal off the wordmark's own height — right for a full-bleed
  190px SVG `<text>`, a snap for the 34px plain-text span that replaced it,
  and MEASURED it sat on `clip-path: inset(100%)` for every scroll position
  short of the page's exact bottom. The parallax is deleted rather than
  retuned: a reveal spanning 34px is a glitch, and removing it drops a scroll
  listener and a `will-change: clip-path` layer from every page on the site.

- **The mega menus collapsed to two columns below 1280px**, wrapping the third
  under its neighbours (Disciplines measured 886px tall inside a 900px
  viewport). That override existed only because three columns *plus* the
  1.15fr photographic spotlight needed 1080px. With the spotlight gone, three
  columns fit in 880px, so the collapse is deleted and the grid states its
  column count per variant (`mega-2col` / `mega-3col`) instead of relying on a
  3-track default with a fourth child wrapping underneath it.

- **`.footer-logo-name` wrapped to `MAM / ACADEMY`** at 21px in the 196px
  brand column whenever Bebas Neue had not loaded and the Impact fallback was
  used. `white-space: nowrap`, like the header's.

## Repo-wide notes

- **`--nav-h` is the lever**: 56px, 64px from 1025px. `#hero`,
  `scroll-padding-top` and `[id]{scroll-margin-top}` all derive from it, so
  nothing else needed touching. The `@media (max-width:768px){--nav-h:64px}`
  override is gone.
- **`--hairline: rgba(232,201,160,0.16)`** is a new token, deliberately
  lighter than `--border` (0.20): the header, menu and footer groups are
  hairlines between rows, not card edges. It is lifted under
  `[data-contrast="high"]` with the rest.
- **`.announce[hidden]{display:none}`** — the fifth appearance of this trap in
  this codebase. A class-level `display:flex` outranks the UA's
  `[hidden]{display:none}`, so anything toggled with `hidden` needs its own
  guard. (`apply.css` documents the other four.)
- **The menu is `inset:0`, not offset to the navbar's bottom.** It carries its
  own 56px head, so stacking the bar above it would show two heads — and the
  offset moved with the announce strip. `positionMenu()` is deleted.
- **`<details>` for the footer groups.** They collapse and expand with no JS
  at all; `initFooterGroups` only forces them open where they are *columns*,
  and closes them again on the way back down so a rotate cannot strand a
  12-link column expanded on a phone. `display:contents` puts the four
  straight into the desktop grid without a wrapper track.
- **The announce dismissal is a djb2 digest of the rendered message**, not of
  the element. `MAM_CONFIG.intake` can arrive late from
  `/api/config/public`, so the handler re-stamps `[data-intake-label]` on
  `mam:config` and re-evaluates — the difference between "the new intake
  reappears for everyone" and "it reappears for whoever had JS's default
  label".

## Unrelated fix carried in this commit

`api/tests/test_instructor_portal.php` scheduled its fixture at a flat
`time() + 480`. `mam_iportal_home()`'s window ends at **today's** 23:59:59 UTC
— "Today" is the screen's whole promise — so for the last eight minutes of
every UTC day the fixture fell out of the window and took five assertions with
it. The fixture is clamped inside the day and the expected countdown derived
from the time actually stored. The "already running" fixture deliberately has
**no** clamp: the window opens two hours *behind* now, so ten minutes ago is
inside it even on yesterday's date, whereas clamping it into today would put
it in the future for the first ten minutes of a day and invert the assertion.
