# Built — the instructor portal v2, 2026-09-19

Source: `portals-mobile.dc.html` `data-phone="instructorv2"` (seven screens at
390) and `portals-desktop.dc.html` `data-portal="instructor"` (244px rail at
1440). `README-portals.md` beside them is the handoff; its seven refusals apply.

## Where it lives

| File | What it is |
|---|---|
| `portal/instructor/index.html` | Class-only markup. `data-portal="v2"` on `<html>`, one stylesheet, no `style=` attribute anywhere. |
| `assets/js/portal-instructor.js` | The app: seven screens, one request each, every write through one helper. |
| `assets/css/portal-v2.css` | The shared design system, extended with the `.i-*` block. Everything both portals share is reused unchanged. |
| `api/modules/portals/lib/instructor-portal.php` | The read layer (shipped earlier), plus the Termly report provider this build needed. |
| `api/routes/instructor/report.php` | New. The instructor's half of the termly report. |
| `portal/instructor/classic.html` | **The legacy 13-tab dashboard, not retired.** See below. |
| `api/tests/test_instructor_portal_ui.php` | 38 static guards on the surface. |

## The legacy dashboard is still there, and that is deliberate

The redesign covers **seven** screens. The dashboard it replaces served
**thirteen** tabs — classes, sessions, assignments, gradebook, analytics,
announce, handbook, account among them. Swapping one for the other would have
deleted six destinations silently, so `portal/instructor/classic.html` stays
reachable and is linked from the v2 More sheet ("Everything else"), and it
links back.

This is the arrangement CLAUDE.md already records for `admin/index.php` beside
`admin/console.php`: *"The legacy admin/index.php is NOT retired… Both are
reachable; each links to the other."* The three existing test files that assert
properties of that dashboard now read `classic.html` and still pass.

## Two things the read layer was missing

1. **`report` validated and did not dispatch.** It was in
   `MAM_IPORTAL_SCREENS`, so it passed the unknown-screen check — and then fell
   through to `default`, returning the **home** payload. A screen name that
   validates and does not dispatch is worse than one that 404s, because the
   client renders something. `report` and `more` now have cases.
2. **There was no write path for a termly report at all.**
   `mam_report_build()` and `mam_report_set_comment()` existed in the lib; no
   route reached them. `POST /api/instructor/report` does, and it reuses an
   open draft rather than building a second one — the one-report-per-slot rule
   is PHP's on both engines precisely because no UNIQUE index can state it, so
   a blind build throws on the second press of a save button.

⚠️ The report PROVIDER does not build. `mam_report_build()` INSERTs, and a GET
that creates an academic record every time a screen is opened fills
`termly_reports` with drafts nobody asked for. The panel is assembled from the
same sources the builder reads; the row is created by the POST, when a person
has written something. A test asserts the provider contains no call to it.

## Found by driving it, not by reading it

1. **The grade screen said "Audio and video" directly above the notice
   explaining that the video was hidden.** The heading was computed from "does
   *any* criterion allow video"; it has to follow the criterion the server put
   first, which is the one being marked. A bias control that appears beside
   video the marker can see reads as a malfunction.
2. **The first band button gave 27.** `mam_marking_bands()` returns
   DESCENDING — Distinction first — because `mam_marking_band_of()` walks it to
   find the first band a score reaches, and that order is load-bearing there.
   The UI reads Pass · Merit · Distinction, so the copy is sorted client-side
   rather than the lib being reordered under its own lookup.
3. **The register's primary button was not primary.** `.i-next-acts button`
   fills white (it belongs to the dark session card), so reusing that class for
   a light-surface button row silently overrode `.p-primary`. `.p-actions` is
   the generic row.
4. **`#gradeSave` and `#tbSend` rendered with no handler.** Dead buttons, which
   CLAUDE.md names explicitly. Both are wired now — to
   `POST /api/instructor/feedback` and `POST /api/instructor/preclass`
   (`action:'review'`) — and the teach-back refuses to send without a verdict
   rather than posting an empty decision.
5. **"Display & access" in the More sheet had no renderer** and fell back to
   Home. It is the same screen the guardian portal has, against the **same**
   storage key: it is one person's preference about their own eyes, not a
   per-portal setting.
6. **"8:30 PM" wrapped to two lines** in the 58px time column. The design
   prints "5:30" there — no meridiem; it stays on the session card's meta line
   where it is read once.

## Where the design and the data disagree

- **Per-band descriptors.** The design prints "Merit descriptor: largely
  accurate notes and intonation…". `marking.php` carries one `help` line per
  CRITERION and no per-band text. The screen shows the band, its range and that
  help. Inventing descriptors would be putting words in an examiner's mouth,
  and a band boundary is a pass or a fail for a real child. **Open question for
  the academy.**
- **The Students caption.** The design shows "Level 2 · 92% attended"; the
  provider sent only a band and an attention sentence. It now also sends
  `summary` — the level and the attendance share, both standing facts about the
  teaching rather than judgements about the child. The attention sentence
  REPLACES it when there is one, because a row that needs a person should not
  also be reciting a percentage.
- The mobile frame's status bar, and the "Second-marking queue", "My Classes",
  "Schedule", "Resources", "Practice target", "Announcement", "Handbook" and
  "Help" rows of its More sheet, are **not** built: those are the destinations
  the legacy dashboard still serves. They are reachable through "Everything
  else" rather than reproduced as dead rows.

## Verified in a browser, not asserted in a test

Playwright/Chromium at **320 / 390 / 430 / 900 / 1440**, signed in as a seeded
instructor with a cohort, six students, three sessions, three submissions and
three teach-backs.

- All seven screens render; every More-sheet row resolves to its own screen and
  hash, none falls back to Home; no page errors at any width; no horizontal
  overflow at any width.
- **Writes driven end to end:** a register mark ("Saved 2 of 6" → "Saved 3 of
  6"), the authorised/unauthorised follow-up appearing only under an absence, a
  teach-back review (queue 3 → 2) including its no-verdict guard, a grade save
  (queue 3 → 2), and a termly-report comment.
- Targets: every control ≥44px on touch, 40px on the desktop rail.
- The 120px scroll reserve leaves **47px** of clearance below the last row at
  the bottom of the page (bar is 73px).
- Desktop: 248px rail with the brand block, the accent edge on the active item,
  and the who-you-are footer; content capped at 1180px with the wide controls
  capped at 560px — the same reasoning this stylesheet already gives for
  `.p-methods` and `.p-kpis`.
- Display & access applies and persists (`data-text`, `data-theme` on the root;
  `mam-guardian-a11y` in storage).

⚠️ The suite's guards here are **static**. They do not measure a box. Drive it.
