# Handoff: MAM Academy admin console

**Date:** 17 Sep 2026 · **Design file:** `Admin Console.dc.html` · **Fidelity: high** · **Base currency:** NGN · **Schema:** v2

## Overview

The staff-facing console for MAM Academy: admissions, students, teaching operations, finance, people, reporting and governance. It replaces **40 flat PHP views** (`mamacademywithfixes/admin/index.php`, 11,236 lines, one file) with **31 destinations under 9 navigable areas**, plus a command palette.

The console is desktop-first — registrars and bursars work at a desk with two windows open — with a separate mobile layout for the two jobs that genuinely happen away from a desk: marking a register and completing an enrolment.

## About the design files

`Admin Console.dc.html` in this bundle is a **design reference created in HTML**. It is a prototype of intended look and behaviour, not production code to lift. The task is to **recreate these designs in the target codebase's existing environment**, using its established patterns, component library and data layer.

The existing codebase is PHP 8 with a hand-rolled router and Tailwind-free inline CSS (`mamacademywithfixes/`). If the team stays there, these designs translate to server-rendered partials plus a small amount of progressive JS — nothing here requires a SPA. If the team is moving to a JS framework, the structure below maps cleanly onto components. Either way, do not copy the inline styles: they exist because the design tool requires them, and a real codebase should express them as tokens.

The prototype's interactivity is a single delegated click handler plus a keydown handler. Treat it as a behaviour spec, not an architecture.

## Fidelity

**High-fidelity.** Colours, type, spacing, border radii and states are final and should be matched. Three qualifications:

- **Every figure is placeholder.** Cohort counts, naira amounts, dates, learner names, attendance percentages, rail badge counts. All of it is demo data chosen to exercise the layout, including the awkward cases. Wire to real queries; expect the layout to hold and the numbers to change.
- **No photography.** The console uses no imagery. ID cards show a name, a membership number and a session only, by design (see *Files & ID cards*).
- **Interactions are demonstrated, not complete.** Drawers open and close, screens switch, the palette filters. Forms do not validate, tables do not sort, and nothing persists.

---

## Information architecture

This is the part most worth getting right, and it was the last thing redesigned. The legacy 40-view flat sidebar was unscannable; the first redesign pass made it 31 items grouped four ways, which was still too long to scan on every navigation.

### Two levels, plus a flat escape hatch

**Level 1 — the rail.** Nine areas. These are places you *go*, not things you *do*. Nine is the ceiling: the rail is re-scanned on every single navigation, so its length is paid repeatedly.

**Level 2 — a section strip** under the page title, holding that area's sections. Same-page switching, no rail movement. This is where breadth lives — you only ever see the siblings of where you already are, never all 31.

**The escape hatch — ⌘K.** All 31 destinations by name, searchable by section *or* area name. Frequent users stop using the rail almost entirely. This is what makes demoting 22 destinations to a second level safe: layered navigation costs discoverability, and the palette is the compensation.

### The map

| # | Area | Icon | Sections |
|---|---|---|---|
| 1 | Dashboard | `gauge` | — |
| 2 | Admissions | `file-lines` | Applications · Auditions · Intake funnel · Bursary applications |
| 3 | Students | `user-group` | Students · Guardians & consent · Files & ID cards |
| 4 | Teaching | `chalkboard-user` | Cohorts · Enrol & staffing · Timetable & rooms · Attendance · Calendar · Recitals & events |
| 5 | Curriculum | `book-open` | Modules · Assessment & boards |
| 6 | Finance | `naira-sign` | Invoices & payments · Ledger & FX · Price books · Charges & exceptions · Store & orders · Instructor deductions |
| 7 | People | `user-gear` | People & access · Staff & vetting |
| 8 | Reports | `chart-line` | — |
| 9 | Governance | `scale-balanced` | Audit trail · Data protection · Communications · Support · System health · Settings & vocabulary |

Six is the widest strip (Teaching, Finance, Governance). Past about eight a strip needs its own overflow, and at that point the area should split instead.

### Four IA decisions worth preserving

1. **Reports is its own area, not a Governance section.** Governance holds quarterly work (data protection, system health, audit). Reports is daily. Mixing cadences in one area makes the frequent thing hard to reach.
2. **No area overview pages.** An index page whose only content is links to the tabs already visible below it is a click tax. A rail click lands on the area's first working section (Admissions → Applications) with siblings in the strip.
3. **Rail highlight follows the *area*, not the screen.** Opening Attendance keeps Teaching lit. A child screen must never orphan the rail. Implementation: each screen carries `data-area`; the nav painter matches on that, falling back to screen id.
4. **Three levels of navigation, three different shapes.** Rail (dark, vertical, icon+label) → section strip (underline tabs) → in-screen filters (pill group). Five screens have all three. If levels 2 and 3 shared a shape, eleven controls would read as one flat row. Do not unify these.

### Third-level pill groups

Screens with an in-screen tab group, which must stay visually distinct from the section strip:

- **Attendance** — Register · Reconciliation · Absence follow-up
- **Files & ID cards** — Documents · ID cards · Storage
- **Store & orders** — Orders · Catalogue
- **Data protection** — Overview · Requests · Breaches · Register · Retention · Processors · DPIA
- **System health** — Automations · Events · Logs · Read model · Forms

---

## Design tokens

Extract these as real tokens. The prototype repeats them inline out of necessity.

### Colour

| Token | Hex | Use |
|---|---|---|
| `ink` | `#1B2430` | Primary text, rail background, primary button fill |
| `ink-2` | `#3B4453` | Body copy in cards |
| `ink-3` | `#57616F` | Inactive tab labels, secondary metadata |
| `ink-4` | `#66707E` | Table column headers, captions, eyebrows — **the lightest text permitted on any background** |
| `canvas` | `#F1F3F6` | App background, scroll region |
| `surface` | `#FFFFFF` | Cards, tables, drawers, palette |
| `surface-2` | `#F6F7F9` | Table headers, inline note blocks, footers |
| `surface-3` | `#EBEEF2` | Pill group track, progress track, `kbd` chips |
| `hairline` | `rgba(24,32,44,0.10)` | Card borders, table row dividers |
| `hairline-2` | `rgba(24,32,44,0.12)` | Section strip underline, palette dividers |
| `accent` | `#E2650F` | **Rail active bar and ID card wordmark only.** Never a fill behind text |
| `accent-focus` | `#B34708` | Focus ring on light backgrounds |
| `accent-rail` | `#F7C08A` | Focus ring inside the dark rail |
| `accent-ink` | `#8A3D07` | Warning text and badge text |
| `success` | `#0A6344` | Success text; badge bg `rgba(15,138,95,0.12)` |
| `warning-bg` | `rgba(226,101,15,0.14)` | Warning badge; row tint `rgba(226,101,15,0.05)` |
| `danger` | `#B0203C` | Danger text; badge bg `rgba(176,32,60,0.10)`; row tint `rgba(176,32,60,0.04)` |
| `danger-border` | `#C6435C` | Border on cards stating a blocking problem |

**Contrast:** every text colour above meets 4.5:1 on its intended background. `#66707E` on `#F1F3F6` is 4.6:1 — it is the floor, not a starting point. An earlier pass used `#8A93A0` for eyebrows (2.7:1); it was removed from 62 places. Do not reintroduce a lighter grey for "quiet" text.

### Typography

- **Display:** Cormorant Garamond, 700 — page titles only, 30px, `letter-spacing` normal.
- **UI:** system sans stack. Sizes: 10px eyebrow (800, `0.16em`, uppercase), 10.5px column header (800, `0.1em`, uppercase), 11.5px metadata, 12px note, 12.5px body/button, 13px tab/rail, 13.5px card title / rail active / palette row, 14px card heading (700), 26px KPI value (700, `-0.02em`).
- **Monospace:** `ui-monospace, Menlo, monospace` at 11–11.5px for ids, emails, table names, timestamps. Ids are data, not prose.
- **Tabular numerals:** `font-variant-numeric: tabular-nums` on every money, time, count and ratio cell. Non-negotiable in a console where columns of figures are compared vertically.

### Spacing, radius, elevation

- Scale: 6 · 8 · 10 · 12 · 14 · 16 · 20 · 22 · 24 px. Card padding `16px 18px`; table cell `12px 16px`; compact cell `10px 13–14px`.
- Radius: 4 (`kbd`) · 8 (buttons, rail items) · 9 (primary button, inner panels) · 10–12 (cards, note blocks) · 16 (palette, outer cards) · 100px (badges, pills).
- Elevation: cards use a 1px hairline, no shadow. Only two shadows exist — palette `0 40px 90px rgba(9,13,19,0.34)`, and the rail's lifted sticky header `0 8px 22px rgba(9,13,19,0.34)`.

---

## Component recipes

### Rail (`236px` expanded, `62px` collapsed)

`#1B2430`, full height, own scroll. Contains: logo lockup, centre picker (`Lagos · Ikeja centre`), **⌘K search trigger**, nine area buttons, a collapse toggle pinned bottom.

- Area button: `min-height:40px`, `padding:0 12px`, `gap:11px`, radius 8. Icon 13px in a 16px box, label 13.5px.
- Active: `background:rgba(255,255,255,0.10)`, label `#FFFFFF` 700, plus a 3px `#E2650F` bar inset left, `top:8px;bottom:8px`, radius `0 3px 3px 0`. Inactive label `rgba(255,255,255,0.72)`, bar `opacity:0`.
- Badge: `rgba(255,255,255,0.14)` pill, white 10.5px 800, `min-width:19px`.
- **Accessible name carries the badge meaning**, not the number: `aria-label="Attendance, 2 registers late, 6 sections"`. A collapsed rail is icon-only, so the name is the only label — a bare "2" tells a screen reader nothing.
- **Collapse must be imperative.** Width and min-width are set in JS, never bound to render state. Binding them caused a one-frame phase lag against React reconciliation. `[` collapses, `]` expands; the toggle's tooltip states the key.
- Sticky rail header lifts on `scrollTop > 4`: background to `rgba(27,36,48,0.72)` + `blur(14px) saturate(140%)`, shadow on, border brightens.

### Section strip

Sticky at the top of the scroll region, bleeding to its edges via negative margin (`margin:-22px -24px 20px; padding:20px 24px 0`) so nothing slides underneath. Contains a 10px uppercase area eyebrow, then the tabs.

`<nav aria-label="Teaching sections">` — **not** `role="tablist"`. These navigate between separate screens rather than toggling panels in place, so tab semantics would be a lie to a screen reader. Active item carries `aria-current="page"`, 2px `#1B2430` bottom border, `#1B2430` 700; inactive transparent border, `#57616F` 500. `margin-bottom:-1px` so the active border covers the container's hairline.

### KPI row

`display:flex; gap:14px; flex-wrap:wrap`. Each: `flex:1; min-width:150px`, white card, `padding:14px 16px` — 10.5px uppercase label, 26px value (coloured `#B0203C` / `#8A3D07` when the number is the problem), 11.5px `#66707E` sub-line naming *what the number is of*.

### Table

White card, radius 12, hairline border, `overflow:hidden`. Header row `#F6F7F9` + bottom hairline, 10.5px 800 uppercase `#66707E`. Body rows `padding:12px 16px`, bottom hairline — **the last row omits it** (a trailing divider above a card edge reads as a missing row). Flex columns with explicit `flex:0 0 Npx` per column and `flex:1` on the one that absorbs slack.

Row tints carry meaning: warning `rgba(226,101,15,0.05)`, danger `rgba(176,32,60,0.04)`, resolved/inactive `#F6F7F9` with text dropped to `#57616F`.

### Badge

`height:22px; padding:0 9px; radius:100px`, 11px 800, uppercase. Four semantics only — success, warning, danger, muted (`#EBEEF2` / `#57616F`). Text states the condition (`NOT CLEARED`, `44 MIN SHORT`, `AWAITING PICKUP`), never a bare status word.

### Buttons

- Primary: `height:36px; padding:0 15px`, `#1B2430`, white 12.5px 700, radius 9, optional 11.5px leading icon.
- Secondary: same metrics, white fill, `1px solid rgba(24,32,44,0.17)`, `#1B2430` label.
- In-table: `height:28px; padding:0 10px`, same two variants.
- Disabled: `opacity:0.5; cursor:not-allowed`, **with the reason stated beneath in `#B0203C` 11.5px**. A disabled control that does not say why is a dead end.
- **One primary action per screen header, rightmost.** Ten screens have one: Students (*Add a student*), Cohorts (*New cohort*), Guardians (*Link a guardian*), Calendar (*Add a closure*), Files (*Upload a document*), Store (*New order*), Deductions (*Record a flag*), Vetting (*Record a check*), Attendance (*Mark a register*), Support (*Assign to me*).

### Command palette

`560px` (`max-width:92vw`), radius 16, `max-height:60vh`, `12vh` from top. Scrim `rgba(16,22,31,0.42)` + `blur(3px)`; click-scrim dismisses. `role="dialog" aria-modal="true"`.

- 52px search row with a leading magnifier and a trailing `ESC` chip; `aria-label="Search destinations"`.
- Rows 42px: section name left, **area name right in 11px `#66707E`** — the area is the disambiguator when two sections read similarly.
- Filters on `section name + area name`, so "finance" returns all six Finance sections.
- `↑` `↓` move with scroll-into-view by offset arithmetic (**not** `scrollIntoView`), `↵` navigates and closes, `Esc` closes.
- Empty state names three example searches rather than saying "no results".
- Footer states the corpus size: `31 destinations across 9 areas`.

### Drawers

Six exist (module editor, price-book creation, roster builder, and others). Right-hand sheet over a scrim, `Esc` closes any open drawer. Opened by `[data-open="x"]`, closed by `[data-close="x"]`, matched to `[data-overlay="x"]` within the same frame.

---

## Keyboard

Implemented and documented. The two on-screen keyboard strips list **only** these:

| Key | Action |
|---|---|
| `⌘K` / `Ctrl+K` / `/` | Open the palette |
| `G` then `D A S T C F P R G` | Go to Dashboard · Admissions · Students · Teaching · Curriculum · Finance · People · Reports · Governance |
| `[` / `]` | Collapse / expand the rail |
| `Esc` | Close the palette, or any open drawer |

`G` arms for 1400ms then disarms. All single-key shortcuts are suppressed when focus is in an `input`, `textarea`, `select` or `contenteditable`, and when any modifier is held.

**An earlier version advertised `J`/`K` row movement, `X` to select, `⌘Z` undo, and per-row `A`/`O` on Admissions. None existed.** They were removed rather than faked, and the strip now states that row selection is not built. If you implement a row cursor, do it as a designed pass — a cursor implies a selection model, and a selection implies bulk actions with defined consequences.

## Accessibility

- `:focus-visible` — 2px `#B34708`, `outline-offset:2px`; inside the rail, `#F7C08A` (the rust ring is invisible on `#1B2430`).
- Landmarks: rail `role="navigation" aria-label="Admin areas"`; scroll region `role="main" aria-label="Console content" id="consolemain" tabindex="-1"`.
- **Skip link** as the first focusable element — "Skip the rail, go to the console". Visually hidden until focused. Without it, eleven controls precede content on every screen change.
- Section strips: `<nav>` + `aria-current="page"`.
- Every icon-only control has `title` **and** `aria-label`.
- All 31 rail/palette entries are reachable and named. Collapsed-rail names include their badge meaning in words.

## State

Minimal by design.

| State | Type | Notes |
|---|---|---|
| `activeScreen` | one of 31 ids | Drives screen visibility, section strip, and (via `data-area`) rail highlight |
| `railCollapsed` | boolean | Persist per user. Imperative width application |
| `paletteOpen` | boolean | |
| `paletteQuery` | string | |
| `paletteCursor` | int | Index into *visible* rows, clamped on every filter |
| `openDrawers` | set of drawer ids | `Esc` clears all |
| `gArmed` | boolean, 1400ms | Two-key sequence latch |

Navigation resets the content scroll to top. In a real app, make `activeScreen` a URL route so back/forward and deep links work — the prototype cannot.

## Screens

Thirty-one. Every one carries real operational states rather than a happy path: blocked publishes, unpaid orders, missing officers, data discrepancies. The awkward cases are the point — they are what the legacy views handled badly.

### Dashboard
Today's operational state. Deliberately decluttered in an earlier pass: the question is "what needs me today", not "how is the academy doing" (that is Reports).

### Admissions — Applications · Auditions · Intake funnel · Bursary applications
Stage bar, a **wait-time column** (how long each applicant has been waiting, because that is the thing nobody sees), and decision consequences stated before the click.

**Intake funnel** is per-step drop-off for TONI 2026: 341 started, 112 submitted, 32.8%. Step 3 (*Experience & goals*, six free-text answers) loses 78 — median 6m 20s on that step alone, more than half the whole application. The screen names the cause and offers the fix. Conversion by intake type shows private tuition highest at 55% because it skips the placement audition: a fact about the form, not the applicants.

### Students — Students · Guardians & consent · Files & ID cards
**Students** is master–detail, two-pane.

**Guardians & consent** separates three things the legacy system conflated: who may *see* a record, who may *collect* a child, who *pays*. Permissions are per guardian, not per child; where two guardians disagree, the child is treated as **not consented**. Recital photography, website use and lesson recording are three separate permissions and none inherits from another. The recital seating chart and the photographer's brief read this list directly. Four minors have no guardian on file: they can still attend — removing a child from class over a data gap punishes the wrong person — but reports cannot be released and payments cannot be attributed.

**Files & ID cards** — a document inherits retention from *the purpose it was uploaded for*, not from a folder someone chose, which is why a vetting note reads `Review` and never `Delete`. ID cards carry a name, membership number and session. No photograph, no date of birth, no address: a card a child loses on a bus should tell a stranger nothing.

### Teaching — Cohorts · Enrol & staffing · Timetable & rooms · Attendance · Calendar · Recitals & events
**Cohorts** — private classes are cohorts with capacity 1. Capacity `NULL` = uncapped; `0` is refused because it reads as permanently full.

**Enrol & staffing** — the roster builder shows **all** refusal reasons at once instead of failing on the first. Staffing filters by clearance date and timetable clash, so a registrar never sees staff they cannot assign.

**Attendance** — the register is what the instructor saw; the Meet log is what the platform recorded. Where they disagree **the register wins and the difference is kept** — it is evidence, not an error to overwrite. Absence escalation: a first absence sends nothing (people get ill), the second opens a conversation naming the classes rather than a percentage. **Escalation level is never shown to the learner** — a child who knows they are on a list stops turning up.

**Calendar** — four sessions a year (TONI/MEDI/SUMMER/DOMI). Closures withdraw a class from the timetable, the register and the fee calculation in one move. Publishing a session is blocked without a published price book, and the block states so. Publishing sends nothing to anybody: a calendar is not an announcement.

**Recitals & events** — running order with recalculating times, 84 of 90 minutes used and the hall booked to 17:45. Performers without photo consent are flagged inline. Accepting a piece places it at the end of its section; ordering is a separate step, so accepting never silently rewrites a printed programme.

### Curriculum — Modules · Assessment & boards
Module editor drawer. Grade bands show coverage and display **"not enough yet"** as a visible dashed row rather than hiding an under-evidenced band.

### Finance — Invoices & payments · Ledger & FX · Price books · Charges & exceptions · Store & orders · Instructor deductions
Double-entry ledger throughout; money in minor units with pinned FX.

**Price books** — creation drawer with an uplift preview before commit.

**Store & orders** — every order posts to the same ledger as tuition, but credits **materials income, not tuition income**. That separation is what lets the termly bursary figure mean anything: a family buying two books must not read as a family paying more fees. Two catalogue lines are orderable with zero stock, and the screen says what that costs — a parent pays for a book that does not exist and is refunded by hand.

**Instructor deductions** — ⛔ **This screen records deductions. It does not make them.** Deducting from wages requires the written consent of an authorised labour officer under the Labour Act. Nothing here reaches payroll: a flag is a conversation, every row can be resolved or waived without money moving, and no amount is ever set. If the academy does deduct, it happens in payroll with consent on file, and this screen is the evidence of what it was for.

### People — People & access · Staff & vetting
Role-based filtering on every picker.

**Staff & vetting** — five checks per instructor (two references, policy, affidavit, sex-offender search) with a clearance date. The gate has three modes: **Warn** records gaps and still allows assignment (correct while paper records are being loaded), **Enforce** refuses to assign an uncleared instructor to any class containing a child (the target state), **Off** suits an academy that teaches no children. Switching to Enforce states its cost up front: 3 of 18 instructors blocked from 11 classes.

⛔ **Stores the index, not the documents.** A reference number, a verification date, and which cabinet the paper sits in. Never the affidavit scan, the referee's words, or a search result. A system that holds the documents becomes the thing that leaks them.

### Reports
Analytics, as its own area. Distinct from Dashboard: Dashboard is today, Reports is the term.

### Governance — Audit trail · Data protection · Communications · Support · System health · Settings & vocabulary
**Audit trail** — hash-chained.

**Data protection** (7 pill tabs) — subject requests with due dates, breaches, the processing register, retention, processors, DPIA. The **register is derived from code, not typed into a form**: each of 14 purposes names the tables implementing it, and the test suite checks those names against the live schema, so a drifted register fails the build instead of sitting in a folder looking reassuring. The **retention sweep ships in report mode and stays there** until someone decides otherwise — a retention job that deletes on the first run of an unreviewed install is how an academy loses records it needed. Rules marked `Review` (academic record, ledger, clearance evidence, audit log) are never applied automatically in any mode.

**Support** — one queue, answered in order, with median-first-reply and reopened counts. Three of nine open tickets are the same question, from guardians whose payment cleared but whose enrolment was never completed: a symptom of a gap in Enrol & staffing, not a support problem. ⛔ **A reply never carries the answer** — it points to the place in the portal where the parent can see the thing themselves. Pasting a balance into an email creates a second version of the truth, and the second version is the one quoted back six months later.

**System health** (5 pill tabs) — automations, outbound events, logs, read-model parity, forms. Five of eleven automations are **deliberately off**, and the screen says so rather than showing them as broken (fee reminders; auto-waitlist, because a human decides who waits). Read-model drift is surfaced as a first-class number: `INV-26-0188` reads ₦142,000 in the ledger and ₦138,000 in the portal's read model. The ledger is authoritative, and one guardian is currently being shown a figure ₦4,000 low. A discrepancy between two reads is how a parent discovers a bug; comparing them on a schedule is how the academy discovers it first.

**Settings & vocabulary** — session names and other terms are renameable. `class_sessions` is the schema name for one meeting and must never surface to a user.

### Mobile admin (390)
Two jobs only: marking a register, and an **Assign** tab for one-tap enrolment. Everything else is desktop work and is not reproduced small.

---

## Behaviour to reproduce

1. **Navigation.** Set screen visibility; repaint rail (match on `data-area`), section strip (`aria-current`), and any in-screen pill group; reset content scroll to top. In production, drive this from the router.
2. **Rail collapse.** Hide `[data-railtext]` nodes, centre the icons, drop labels and badges, swap the toggle glyph and its tooltip. Width applied imperatively.
3. **Palette.** Open on `⌘K` / `Ctrl+K` / `/`; filter on section+area; arrow keys with clamped cursor; `↵` navigates; `Esc` and scrim-click close. Focus the input on open; **return focus to the trigger on close** (the prototype does not — fix this in the real build).
4. **Drawers.** Open/close by id within the same frame; `Esc` closes all open.
5. **Disabled controls** always state their reason adjacently.

## Assets

- `mam-logo.png` — full lockup, above ~80px.
- `mam-logo-mark.png` — emblem for small slots and the collapsed rail.
- Icons: Font Awesome 6 solid, CDN in the prototype. Swap for the codebase's icon set; names are listed in the IA table and recipes.
- Fonts: Cormorant Garamond (display) and the system sans stack. No webfont for UI text.

## Known gaps

- Every figure is placeholder, including rail badge counts.
- No sorting, filtering or pagination on any table. Filter rows were deliberately not designed — they need to know what registrars actually filter by, which is a question for the academy, not a guess.
- No row selection or bulk actions anywhere, and therefore no row-level keyboard model.
- Forms do not validate. Field-level rules are not specified in this package.
- No loading or error states designed for the console (the student/guardian portals have them — see `HANDOFF-student-guardian.md` refusal 6 on honest write states, which applies here too).
- Palette does not return focus to its trigger on close.
- `activeScreen` is not a route.

## Files in this bundle

| Path | What it is |
|---|---|
| `Admin Console.dc.html` | **The design.** Desktop 1440 + mobile 390. Open in a browser; click the rail, press `⌘K` |
| `HANDOFF-student-guardian.md` | The portal handoff. Shares tokens and component recipes with this console — read its token and refusal sections alongside this one |
| `README-portals.md` | The earlier package index: vocabulary, the seven refusals, order of work |
| `mam-logo.png`, `mam-logo-mark.png` | Logo assets |

The console is self-contained (fonts and icons from CDN) and needs no build step to view.

**No screenshots are included.** Open `Admin Console.dc.html` in a browser instead — it is the authoritative reference, and every screen's content, states and values are documented above. Click the rail to move between areas, use the section strip for sections within an area, and press `⌘K` to reach any of the 31 destinations by name.

## Vocabulary — get this right first

- **Session** = what most schools call a term. ~14 weeks, four a year, named **TONI / MEDI / SUMMER / DOMI**, renameable in Settings.
- **Cohort** = a group of learners. A **private class is a cohort with capacity 1** — one code path for registers, credits and reports.
- **Class** = one meeting. The schema calls this `class_sessions`; never surface that name to users.
- Capacity `NULL` = uncapped.
