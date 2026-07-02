# MAM Academy — Application-Flow Guide: Fit Review & Integration Plan

**Status:** review for decision · **Date:** 2026-07-02 · **Rev 2** (folds in the
reviewer's 17 "Reservations & Strategic Improvements")
**Purpose:** A stakeholder shared a 15-point "improve the application flow" guide
(discovery → eligibility → scholarship detection → admissions dashboard →
orientation → lifecycle), then a second round of 17 reservations that push it
from a *feature set* toward a *distinctive admissions product*. This document maps
**each item to the code that already exists**, flags where a suggestion
**conflicts with MAM's decided model**, and recommends a high-impact first slice —
now designed as an **intelligent advisor**, not a questionnaire (§2), with the 17
elevations mapped in §2b.

> The guide is written as if for a greenfield admissions funnel. MAM already has
> a mature one. So the useful question isn't "build these 15 things" — it's
> "which of these 15 are missing, which already exist, and which fight the model
> we deliberately chose." Companion to `docs/application-enhancement-proposal.md`
> (the A1–A4 spine: server drafts, status portal, admissions pipeline, AI gateway),
> which this does **not** duplicate.

---

## 0 · The two hard constraints the guide doesn't know about

1. **The money model is not "apply → pay tuition → enrolled."** It is:
   **free application → flat regional booking fee (~$5, `payments.purpose='booking_fee'`) → MAM Auditer assessment → admin enrols after assessment → tuition discussed/charged later** (`tuition.php` + `GET /api/tuition/status`). `mam_payment_settle_booking()` deliberately **does not** enrol or mark the application paid. So any guide step that shows a **tuition quote up front**, or treats **payment as the end of the application**, must be reframed as "confirm your seat (booking fee) → Auditer → placement."
2. **Scholarships are admin-issued, not self-detected** (`users.student_type`, `mam_user_is_scholarship`, `POST /api/admin/set-student-type`; portal brief §#4). A public "you qualify for 5 scholarships" detector would contradict that decision and create commitments we can't honour automatically. The honest version is: *surface the scholarship program and let admissions award it* — which `config-public` + the site's scholarship banner already do.

Everything below is judged against these two facts.

---

## 1 · The 15 items, mapped to the live code

Legend — **✅ Exists** · **◑ Partial** · **➕ Fits (build)** · **⚠ Conflicts / reframe** · **🧱 Net-new subsystem**

| # | Guide item | Verdict | Where it lives / what's missing | Effort |
|---|-----------|---------|--------------------------------|--------|
| 1 | Pre-application **Discovery / Find Your Path** | ➕ **Build — top pick** | No discovery step today; wizard opens on Step 1 "Choose Your Programme" (`apply.php`). The wizard already prefills from allowlisted `?pathway=&mode=&level=&discipline=&expression=` (`apply.js:165–183`), so a finder can hand off with **zero new trust surface**. | S–M |
| 2 | "Choose Your Learning Path" premium cards + **Compare** | ◑ Partial | Programme cards exist in Step 1; a full compare page already exists (`compare.php`, linked from the mega-menu). Missing: per-card *weekly commitment / outcomes / intake* metadata surfaced in-flow. | S |
| 3 | **Instant eligibility screening** | ➕/⚠ Build, reframed | No eligibility gate today. Portal brief §#1 specifies rules (age 16–26, instrument ownership, prior experience, commitment, values). Reframe "auto-disqualify" → **soft signal + route to Foundation/Auditer**, since MAM places via Auditer, not hard rejection. Server re-derivation must live in `api/lib/validation.php`/`admissions.php`. | M |
| 4 | **Smart scholarship detection** | ⚠ Conflicts | Scholarships are admin-issued (see §0.2). Keep as *awareness* (the existing `config-public` scholarship link + banner); do **not** auto-promise eligibility. Optional: a "flag me for scholarship review" checkbox that sets an admissions note. | S (as awareness) |
| 5 | **Admissions dashboard** (applicant-facing status) | ✅ Exists (◑ polish) | `status.php` + `GET /api/application/status` (reference+email), backed by `mam_application_stage()` → `received → seat confirmed → under review → accepted/waitlisted → enrolled → closed/expired`. Missing: rendering it as a **visual milestone timeline** with outstanding-task hints. | S (polish) |
| 6 | Richer **Background** step (education, musical history, goals) | ◑ Partial | Step 3 "Background" exists; the guide's deeper profile (voice type, performance/production history, goals) would feed cohort placement. Additive fields + validation. | S–M |
| 7 | **Document verification earlier** (OCR, quality check) | 🧱 Net-new | No document pipeline exists (headshot is an on-device MediaPipe check + Cloudinary URL only, `headshot.js`). OCR/verification is a whole subsystem; MAM's flow currently needs no docs pre-Auditer. Defer unless a real document requirement appears. | L |
| 8 | **AI pre-submission review** (missing info, readiness %) | ◑ Partial | The audited AI gateway exists (`api/lib/ai.php`) and already powers an **admin** placement recommender (`mam_ai_placement_recommendation`, gated `mw_admin_cap('admissions')`, per-user-capped). An *applicant-facing* readiness hint could reuse it, but keep it advisory + rate-limited + provider-optional. | M |
| 9 | Separate **application from enrollment** | ✅ Already true | This is precisely MAM's model (§0.1): application ≠ payment ≠ enrolment. The guide's request is already the architecture. Only the *UI copy* needs to make the stages legible. | XS (copy) |
| 10 | Mandatory **orientation / Welcome Hub** | 🧱 Net-new | No orientation module. Natural home is the **student portal** post-enrolment (`portal/student/`), gated before course registration. Sizeable; sequence after the LMS onboarding work. | L |
| 11 | Assigned **admissions advisor** (contact card) | ◑ Partial | Staff-side assignee already exists (`applications.assignee_id`, `mam_admissions_assign`). Missing: surfacing that person as an applicant-facing contact card on `status.php`. Needs an advisor profile/contact source. | S–M |
| 12 | Real **application timeline** (lifecycle, not form progress) | ✅ Data exists (◑ render) | Same data as #5: `mam_application_stage()` + `mam_admissions_events()` (audited transition trail). Just needs a timeline render on `status.php`. | S |
| 13 | **Enhanced payment → onboarding** transition | ◑ Partial, reframe | Post-booking-fee, `mam_payment_settle_booking` emails a "seat reserved — pick your time" confirmation. Reframe the guide's "payment complete → onboarding" as "**booking fee in → book your Auditer slot → next steps**" (which `book.php` already anchors). | S |
| 14 | **Conditional application paths** | ◑ Partial | The wizard is already adaptive (level/expression/guardian sub-steps by pathway/age, `apply.js:476–493`). Item #1's finder would add the *entry-level branching* (Beginner→Foundation, Advanced→Auditer) the guide wants. | S (with #1) |
| 15 | **Admission readiness score** | ◑ Partial | A live client-side "profile completeness %" is cheap and motivating; a true "admission readiness" score overlaps #8 (AI) and shouldn't imply an automated admit decision. Ship the completeness meter; treat AI readiness as advisory. | S |

**Summary:** of 15, **~2 already exist** (9, and 5/12 as data), **~8 are partial/fit** small-to-medium additive builds, **1 conflicts** with the scholarship decision (4), and **2 are net-new subsystems** (7 doc-OCR, 10 orientation) that belong on a later roadmap.

---

## 2 · Recommended first slice — "Find Your Path" as an **intelligent advisor** (guide #1 + #2 + #14; reservations #1–#4)

The single highest-leverage, self-contained addition. The reviewer's key
reservation is right: a pre-application *questionnaire* adds friction before the
applicant has committed. So this is designed as an **advisor**, not a form — it
should feel like MAM *understanding* the applicant, not collecting data.

**Experience shape**
- A **skippable advisor** on `apply.php` *before* Step 1 (persistent "I know what
  I want — skip" link). Framed warmly: *"Let's find the best place to begin."*
- **Psychologically-ordered** questions (aspiration first, admin last), each
  updating a **live recommendation panel** beside them:
  1. *What do you dream of becoming?* (performer · producer · worship leader · teacher · well-rounded musician)
  2. *What excites you most?* (an instrument · your voice · rhythm · theory/composition · the philosophy of music)
  3. *Where are you now?* (never studied · self-taught · some formal training · advanced)
  4. *Which instrument or discipline?* (maps to the real `discipline` tokens)
  5. *How do you want to learn?* (online · onsite Lagos)
  6. *Age* — **last, and explicitly** *"only because it affects guardian requirements."*
- After each answer the side panel re-scores and re-renders. No "submit."

**Weighted, transparent recommendation (reservations #3, #4)**
- Not rigid `if beginner → crash`. Each answer contributes **weights** to every
  pathway; the panel shows a **ranked set with match %** and a **confidence badge**:
  - `Recommended with high confidence` (clear leader) · `Good match` · `Multiple great options` (close scores → show the top 2–3).
- Every recommendation shows its **"Because:"** reasons (✓ beginner · ✓ wants
  performance · ✓ weekend availability) — deterministic and legible (reservation #17:
  core logic stays deterministic; AI can *explain* later, never decide).
- Scoring is **pure client-side** over `MAM_CONFIG`/`config-public` data — no new
  endpoint, no new trust surface.

**Persistent throughout the journey (reservations #2, #13)**
- The chosen recommendation **rides along** as a compact "Recommended path" chip
  in the existing collapsible summary panel on every wizard step, with a
  **"change"** affordance that reopens the advisor **without restarting** the form.
- Progressive personalization: once known, later steps greet contextually
  (*"Applying for Certified Musician — recommended because you have choir
  experience"*), reusing the `data` draft object.

**Hand-off = today's prefill path.** Selecting a path routes into the wizard via
`?pathway=&mode=&level=&discipline=&expression=` — reusing the XSS-safe allowlist
at `apply.js:177–182`. Emits **only** valid tokens (`crash | standard |
certified-musician | grand-musician | meta-order`; `precert | cert | prediploma |
diploma`; `classical | contemporary | african`; real disciplines). No DB change.

**Honesty guardrails (per §0)** — a *recommended starting point*, always with
"**MAM Auditer fine-tunes your placement**." No hard tuition quote, no fabricated
scholarship eligibility (links to the existing scholarship info). Advanced → routed
toward **Auditer**; beginner → foundational path. A **soft branch, never a hard
disqualification** (this is why §6 reframes "eligibility" as "readiness").

**Progressive enhancement & a11y** — JS-off or "skip" → `apply.php` behaves exactly
as today (advisor is additive). Reuse `.choice-card`/`.field` patterns, `aria-live`
on the recommendation panel so updates are announced, `aria-current`, reduced-motion;
persist answers into the `data` draft so a return visit remembers them.

**Verification** — `php api/tests/run.php` green; `node --check` changed JS;
`php -l apply.php`; Playwright: complete advisor → wizard **pre-filled with valid
tokens**; "skip" + JS-off leave the current flow intact.

**Est. effort:** ~M, front-end only (`apply.php` markup + `assets/js/apply-discovery.js`
+ `assets/css/apply.css` rules). The weighted-scoring table is a small data
structure, not a rules tree.

---

## 2b · The 17 reservations, mapped (elevation, not rework)

The reviewer's reservations are UX/product elevations. Almost all fit the existing
backend **without new subsystems** and without touching the two hard constraints
(§0). Legend as §1.

| # | Reservation | Verdict | How it lands in this codebase |
|---|-------------|---------|-------------------------------|
| 1 | Advisor, not questionnaire | ➕ **In §2** | Live side panel + conversational framing; no "submit". |
| 2 | Discovery persists through the journey | ➕ **In §2** | "Recommended path" chip in the existing summary panel; "change" reopens advisor, no restart. |
| 3 | Weighted scoring, not rule routing | ➕ **In §2** | Per-answer weight table → ranked match %; client-side over `config-public`. |
| 4 | Confidence indicators | ➕ **In §2** | `high confidence / good match / multiple options` from score spread. |
| 5 | Outcome-centric programme cards | ➕ Build | Extend Step-1 cards + `compare.php`: *Ideal for · Become · Duration · Skills · After graduation*. Content, not backend. |
| 6 | "Readiness", not "Eligibility" | ➕ **Reframe** | Rename the planned screen to **Learning Readiness**: *current experience · recommended start · optional prep*. Removes the exclusion frame; matches placement-by-Auditer. Server re-derivation in `validation.php`. |
| 7 | Background → **Placement Profile** | ➕ Build (additive) | Reframe Step 3 into Learning / Technical / Community sub-profiles (genres, goals, practice availability, instruments owned, notation/ear, church/band/choir/teaching). New nullable columns (MySQL `TEXT` no-DEFAULT rule) feed cohort placement + the admin AI placement recommender. |
| 8 | Dashboard → **mission control** | ◑ Extend `status.php` | Add task list, booking status, payment status, upcoming Auditer, announcements, support + advisor contact around the existing stage data. Messages/announcements need a small channel — sequence later. |
| 9 | Predictive next steps | ➕ Build | Extend `mam_application_stage()` to also return *typical duration* + *likely next step* + *prepare-now* links per stage. Pure data on existing states. |
| 10 | Auditer introduced **early** | ➕ Copy/UX | Thread Auditer explainer through the advisor, wizard, and post-submit roadmap (what/why/benefit/expect/prepare), so `book.php` feels like an exciting milestone. Mostly copy. |
| 11 | "What happens after I apply?" roadmap | ➕ Build | Post-submit success panel + `status.php` render the real chain: *received → booking fee → book Auditer → placement review → decision → enrolment → orientation → portal → first class*, each with a timeframe + one line. Uses existing stages. |
| 12 | Contextual trust builders | ➕ Content | Surface testimonials / instructor profiles / affiliations *contextually* in-flow (faculty data already exists on `faculty.php`), not a separate page. |
| 13 | Progressive personalization | ➕ **In §2** | Contextual greetings from the `data` draft ("recommended because you have choir experience"). |
| 14 | Timeline → **living history** | ✅ Data exists → render | `mam_admissions_events()` already stores the dated, audited transition trail (submitted → fee → review → Auditer invite → completed → placement). Render it chronologically on `status.php`. |
| 15 | Admissions **knowledge center** (contextual) | ➕ Content | Inline expandable answers (why Auditer? how are placements decided? can I switch programmes? what if I miss my Auditer?) beside the relevant step — reuse the FAQ accordion pattern. |
| 16 | Emotional journey | ➕ UX | Celebrate completed milestones; frame Auditer as *opportunity*; welcome into the community pre-enrolment. Tone/microcopy + the existing reduced-motion-safe celebration on payment success. |
| 17 | AI-ready, not AI-dependent | ✅ **Already the stance** | Core recommendation/readiness/next-steps stay **deterministic**; the audited AI gateway (`api/lib/ai.php`, per-user-capped) *augments* — explanations, smart FAQ, resource hints — behind `ai_provider` and never on the critical path. |

**Net:** of 17, most are **content/UX elevations** over data and patterns that
already exist. Only three imply new backend surface — a **placement-profile**
schema (#7), a **messages/announcements** channel for the mission-control
dashboard (#8), and the **living-history render** (#14, which just reads existing
events). None conflict with §0.

---

## 3 · Recommended sequence (fit-ordered, elevated)

1. **"Find Your Path" advisor** (§2) — weighted, transparent, persistent. The real
   top-of-funnel gap; folds in reservations #1–#4, #13.
2. **"What happens next" roadmap + living-history timeline** on `status.php` and the
   post-submit panel (reservations #11, #14, #9) — render over `mam_application_stage()`
   + `mam_admissions_events()`, adding typical-duration / next-step hints. High
   transparency, low code.
3. **Auditer-forward copy + emotional tone + knowledge center** (reservations #10,
   #15, #16) — near-zero-code content pass that makes the Auditer the differentiator
   and reduces support questions inline.
4. **Readiness (not eligibility) soft-screen** (reservation #6 + portal-brief §#1) —
   *signal + routing*, server re-derived, never a hard gate.
5. **Outcome-centric programme cards** (reservation #5) + **contextual trust
   builders** (reservation #12) — content over existing faculty/compare data.
6. **Placement Profile** (reservation #7) — additive nullable columns feeding cohort
   placement + the admin AI recommender; the one schema change worth doing early.
7. **Mission-control dashboard** (reservation #8) — extend `status.php`; the
   messages/announcements piece needs a small channel, so it trails the rest.
8. **AI augmentation** (reservation #17) — explanations / smart FAQ / resource hints
   on top of the deterministic core, behind `ai_provider`.
9. **Later roadmap / product decision:** #7-doc-OCR and #10-orientation-hub from the
   original guide; auto-scholarship only ever as *awareness*.

---

## 4 · Explicitly out (with reasons)
- **Auto-scholarship detection (#4):** conflicts with admin-issued scholarships.
- **Tuition-quote-at-apply / payment-as-enrolment:** conflicts with the
  booking-fee → Auditer → enrol model.
- **Document OCR/verification (#7)** and **orientation hub (#10):** net-new
  subsystems; no current requirement in the pre-Auditer flow — roadmap items.

## 5 · Guiding principle (from the reviewer's final assessment)

The elevation goal, in one line: make the flow feel **less like completing forms
and more like beginning a guided educational journey** — recommendations that feel
genuinely advisory, the **Auditer as the central differentiator** throughout, richer
applicant personalization, a dashboard that is a real workspace, and an experience
that moves the applicant from **curiosity → confidence → commitment → anticipation** —
all on the current deterministic backend, with AI as augmentation, not dependency.

*No code changed by this document. It exists to pick the next build with eyes open.*
