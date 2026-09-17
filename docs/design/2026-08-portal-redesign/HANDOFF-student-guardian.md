# Developer handoff — Student & Guardian portals

**For:** Claude Code · **From:** design · **Date:** 19 Aug 2026
**Target repo:** the MAM Academy app (`portal/student/`, `portal/parent/`)
**Schema:** v2 (`db/schema-v2.sql`) — read it before writing queries; this document uses its column names.

---

## 0. Read this first

Three things about this handoff will save you time:

1. **The mobile portals are the primary experience.** Desktop is the same information given room, not a different product. Where they disagree, mobile wins.
2. **Several refusals in these designs are deliberate.** They are marked **⛔ by design** below. If a ticket asks you to remove one, push back and cite the reason here — each one exists because the research in `docs/` says the obvious alternative harms students or families.
3. **Vocabulary is not cosmetic.** In MAM a **session** is what most schools call a term (~14 weeks; 4 a year, named **TONI / MEDI / SUMMER / DOMI**, renameable in Settings). A **cohort** is a group of learners. A **class** is one meeting (`class_sessions` in the schema — do not surface that table's name to users). Getting this wrong makes fee copy ambiguous.

### Design files

| File | Contains |
|---|---|
| `Portals Mobile.dc.html` | Student, guardian and instructor mobile screens (390) — the source of truth |
| `Portals Desktop.dc.html` | Student and guardian desktop (1440) |
| `Admin Console.dc.html` | Staff console (1440 + 390) — reference for shared components |
| `mam-logo-mark.png` | Emblem for small slots. The full lockup does not read below ~80px |

Open them in a browser; they are self-contained. Click the left rail / bottom tabs to move between screens.

---

## 1. Design tokens

Lift these exactly. They are already consistent across all three surfaces.

```css
/* Surfaces */
--bg:            #F1F3F6;   /* app background */
--raised:        #FFFFFF;   /* cards, rails */
--sunken:        #EFF1F4;   /* chips, segmented control, ghost buttons */
--ink:           #1B2430;   /* primary text, dark panels, primary button */

/* Text */
--text:          #1B2430;
--text-2:        #3B4453;   /* body copy */
--text-3:        #66707E;   /* meta — 4.6:1 on #FFFFFF, 4.5:1 on #F1F3F6 */
--text-on-chip:  #57616F;   /* REQUIRED on #EFF1F4; #66707E is 4.44:1 and fails */

/* Accent — from the logo */
--accent:        #E2650F;   /* fills, rules, active bars. Pair with #211A13 text */
--accent-text:   #B34708;   /* accent-coloured TEXT on light surfaces (5.5:1) */

/* Semantic */
--good:          #0F8A5F;
--warn:          #8A5A00;   /* text */  --warn-fill: rgba(176,116,15,0.14)
--bad:           #B0203C;   /* text */  --bad-line:  #C6435C  /* borders */

/* Lines */
--hairline:      rgba(24,32,44,0.08);   /* inside a card, between list rows */
--border:        rgba(24,32,44,0.12);   /* card edge */
--border-strong: rgba(24,32,44,0.14);   /* input edge */

/* Geometry */
--radius-card:   12px;   --radius-control: 8px;   --radius-pill: 100px;
```

**Type.** `Inter` for UI. `Cormorant Garamond` 600/700 for headings and pull-quotes. `Bebas Neue` for numerals that should read as figures (counts, money, times) — always with `font-variant-numeric: tabular-nums`.

Scale actually used: 26px section heading · 19px panel title · 14.5px row title · 13px body · 12.5px meta · 11px uppercase label (letter-spacing .12em, weight 800).

**Never below 12px.** Guardians skew older and students include under-16s on mid-range Android.

### Component recipes

- **Ghost button** — `background:#EFF1F4; border:0; radius:8px; height:34px; padding:0 13px; font:600 12.5px`. Icon at 11px in `--text-3`.
- **Primary button** — same metrics, `background:#1B2430; color:#fff; weight:700`. **One per view.**
- **Segmented control** — wrapper `background:#EFF1F4; padding:2px; radius:9px`; active pill `background:#fff; box-shadow:0 1px 3px rgba(24,32,44,0.12); weight:700`; inactive label **`#57616F`**.
- **Master–detail** — `grid-template-columns:1fr 1px 1fr; gap:26px`, middle child is a 1px `rgba(24,32,44,0.10)` div with `align-self:stretch`. Do **not** put a card around either pane.
- **List rows** — hairline `border-bottom`, no card, no zebra. 13px padding block.

⚠️ **Flexbox gotcha, hit twice in this design:** any `<input>` inside a flex row needs `min-width:0` (and `size` if it holds a short value). `flex:1` alone loses to the automatic-minimum-size rule — a text input's min-content is ~20 characters, so it overflows and gets clipped.

---

## 2. Student portal

### Information architecture

Mobile bottom bar, in order: **Home · Train · Learn · Attend · More**.
Desktop left rail: **Train · Learn · Where I stand · Attend · Repertoire**.

`Pay` is deliberately **not** in the bar. It surfaces on Home when something is owed. For an under-16 the guardian pays; Train earns the slot because it is the only destination the student should open daily.

### 2.1 Train — the deliberate-practice loop

The core of the product. Three steps in a fixed order:

**1 · Practice room.** target → attempt → recorded take → self-mark the breakdown point → instructor sees it.

The hard parts already exist in the repo: `practice-player.js` (waveform, tempo scaling, A–B loop, timestamped markers) and `take-recorder.js`. They are currently buried inside panels — promote them to the screen.

```
GET  /api/practice/target?enrolment={id}     → { module, target_text, set_by, est_minutes }
POST /api/practice/take                      → multipart audio + { enrolment_id, module_id }
POST /api/practice/take/{id}/marker          → { at_ms, note }
```

⛔ **by design:** no automatic pitch/rhythm scoring. Record and host takes for a human to mark. A wrong automatic mark on a child's playing costs more trust than the feature is worth.

**2 · Teach it back.** Audio-only recording of the student explaining a concept, then **exactly three** self-checks (explained *why*; gave an own example; where they got stuck as free text).

⛔ **by design:** three dimensions, not six, and the score is **formative only** — it never enters the mastery band. `docs/` flags the original six-dimension rubric as overlapping constructs scored by one untrained rater. Method is well supported; that scoring was not.

**3 · Keep it warm.** Three retrieval questions a day drawn from earlier weeks. Self-rated → reschedules itself:

| Rating | Next due |
|---|---|
| I'd forgotten it | +1 day |
| Got there, slowly | +4 days |
| Straight away | +2 weeks |

⛔ **by design:** opt-in, never scored, skipping costs nothing. The moment it is graded it stops being retrieval practice and becomes surveillance.

### 2.2 Where I stand — mastery reporting

Five rules, all load-bearing:

1. **A band, never a decimal.** `70 – 79`, not `74.3`.
2. **Coverage on the face:** "Based on 8 of 11 parts of your work — 72% of what counts."
3. **"Not enough evidence yet" is a visible row** with a dashed border. Never a silent zero, never renormalised away.
4. **Horizontal bars per competency. No radar chart** — it scores worst for accuracy and misleads through area.
5. **80% is labelled a convention** the academy chose and reviews, not a fact about music.

```
GET /api/student/mastery?enrolment={id}
→ { band:[70,79], coverage:{ evidenced:8, total:11, weight_pct:72 },
    competencies:[ { code, name, weight_pct, level:'strong|secure|working|insufficient',
                     pct|null, first_assessed_week } ] }
```

Competencies are a foreign key (`rubric_criteria.competency_id`), not a keyword match. v1 matched by string and silently reclassified renamed criteria.

### 2.3 Your next step

One forward-looking action plus a **named, tappable advisor**. An assigned human is the most consistent feature across the retention research; automation without one hurts.

⛔ **by design:** intervention flags are **staff-side only**. The student and guardian see the booking, never the flag. An explicit at-risk label increases social distance and can become self-fulfilling.

⛔ **by design:** **no cohort effort board / leaderboard.** It exists in v1 — remove it. Gamification's effect on intrinsic motivation is small (g ≈ 0.26), extrinsic-heavy designs can reduce it, and for minors the Children's Code forbids manipulative nudges. A ranking among ~20 named children is the highest-risk form. The personal streak stays: it is a commitment device, not a ranking, and **nobody else sees it** — say so on the screen.

### 2.4 Offline behaviour — not optional

Metered, intermittent data is the norm. Every write says what actually happened:

> **Saved on this phone.** Take 2 will upload when you're back on data — you can close the app.

⛔ **by design:** never an optimistic tick. A dishonest success state loses a practice log, and a lost log breaks the streak the whole loop depends on.

Queue writes in IndexedDB, flush on `online`, and show queue depth. Reads may be stale but must be labelled stale.

---

## 3. Guardian portal

### Information architecture

Desktop rail: **Overview · Progress & reports · Attendance · Fees · Consents · Announcements**, with a **child switcher pinned above** the rail. Multi-child households are the default case, not an edge case.

### 3.1 Overview

Two panes. Left: *Needs you* (owed money, pending consent), next class, session-so-far figures. Right: the instructor's latest note, ratified reports, and an explicit **"What you cannot see, and why"** panel.

### 3.2 Access scopes

Roles attach to a membership (`membership_roles`) and can be narrowed to a centre.

| Scope | Sees |
|---|---|
| Billing only | Invoices, receipts. No marks, no attendance |
| Standard | + ratified reports, attendance, timetable |
| Full | + consents, and can act for the child |

⛔ **by design:** **no scope grants** practice logs, teach-back recordings, or a teenager's messages with their instructor. Enforce server-side, not by hiding UI. Say it on the screen so the boundary is understood rather than discovered.

### 3.3 Reports

A report appears **only after a board has ratified it**. Ratification is refused while an appeal is open or the moderation sample is short. A provisional mark a family can see and then have overturned is worse than one arriving a week late.

```
GET /api/guardian/reports?student={id}   → only status='ratified'
```

### 3.4 Fees

- Base currency **NGN** (`MAM_V2_BASE_CURRENCY`). The wallet ledger stays USD cents — deliberate, do not "fix".
- Money is **integer minor units + ISO currency + frozen FX**, enforced by a CHECK. Decimal→minor conversion is **string arithmetic**: `8.07 * 100` is `806.9999999999999` in float.
- Compute totals from the base-currency source. **Never sum rounded per-line conversions** — that one-naira mismatch reads to a parent as a pricing error.
- Show the frozen rate on each invoice so a May figure still reconciles next year.

**Dunning, not disconnection.** Reminders at day 0, 3, 7; grace window 14 days; next step is a conversation.

⛔ **by design:** an access hold never touches the academic record, a safeguarding-relevant file, or a minor's report. State it on the screen: *"Nothing is withheld from a child over money."*

**Aid is not a discount** — four distinct things:

| | Treatment |
|---|---|
| Bursary | Needs-based gift aid · credit from the aid pool |
| Scholarship | Merit gift aid · same treatment, different criteria |
| Waiver | Reduces the invoice line · non-revenue, never a cash entry |
| Sibling | Discount · stacks **after** aid, capped at the published maximum |

Bursaries are assessed on a declared **income band**, never a written case for hardship, and applying **never** affects an admission decision.

### 3.5 Make-up credits

24 hours' notice earns a credit · 5 a year · non-transferable · expire · no cash refund in lieu · **a teacher cancellation always credits and never counts against the five**. State the rule on the screen where the credit is spent.

---

## 4. Accessibility — contractual, not aspirational

Students include under-16s and learners with disabilities; guardians span a wide age range.

- Text contrast **4.5:1** (3:1 large text and non-text UI). `#66707E` passes on white and on `#F1F3F6`; on `#EFF1F4` use **`#57616F`**.
- Touch targets **≥44px**. Desktop controls ≥24px, 34px used here.
- Every input has a **programmatically associated persistent label**. Never placeholder-as-label. A caption above a *group* of buttons is not a label — use `role="group"` + `aria-labelledby`.
- Errors never signalled by colour alone.
- Keyboard reaches everything; focus visible and not hidden behind sticky bars.
- Respect `prefers-reduced-motion`.
- Survives 200% zoom and ~30% string expansion.

**Sticky-bar reserve:** a scroller under a sticky bottom bar needs `padding-bottom` ≥ bar height + 40px. The mobile bar is 73px; use **≥120px**. Under-reserving clipped the last row twice in this design.

---

## 5. Build order

1. Tokens, ghost/primary buttons, segmented control, master–detail, list row, chip
2. Student **Train** — practice room first, wiring the existing player and recorder
3. Offline queue + honest write states (before anything else depends on writes)
4. Teach-back (3 checks, formative)
5. Keep it warm (spaced retrieval)
6. Mastery band + coverage floor
7. Guardian overview, reports (ratified only), fees
8. Access scopes — **server-side**
9. Consents, announcements, repertoire

**Ahead of all of it,** per `docs/v2-cutover-readiness-2026-08-18.md`: vetting gates, NDPA consent records, the academic calendar, and minor-unit money with pinned FX. These designs assume those exist.

---

## 6. Open questions for the academy

1. Safeguarding gate: ships in `warn`. Moving to `enforce` is a decision, not a default.
2. Session names are editable. Do existing cohort references keep the name they were created under? The design says **yes** — a family has `TONI-26A` on a receipt.
3. Retention sweep is in `report` mode; 12 non-enrolled applicant files are past two years.
4. **Last tested restore: never.** Not a design item, but it is the highest-risk line in the console.
