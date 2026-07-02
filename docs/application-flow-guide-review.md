# MAM Academy — Application-Flow Guide: Fit Review & Integration Plan

**Status:** review for decision · **Date:** 2026-07-02
**Purpose:** A stakeholder shared a 15-point "improve the application flow" guide
(discovery → eligibility → scholarship detection → admissions dashboard →
orientation → lifecycle). This document maps **each item to the code that
already exists**, flags where a suggestion **conflicts with MAM's decided model**,
and recommends a small, high-impact first slice.

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

## 2 · Recommended first slice — "Find Your Path" (guide #1 + #2 + #14)

The single highest-leverage, lowest-risk, self-contained addition. It targets the
one real gap at the top of funnel (decision fatigue) and folds in the guide's
recommendation cards and conditional-path branching.

**Shape**
- A **skippable discovery panel** on `apply.php` *before* Step 1, shown to
  undecided applicants (persistent "I know what I want — skip" link).
- 4–5 friendly questions: **age band · learning mode · experience · main interest/instrument · goal**.
- Deterministic client-side mapping → a recommended **pathway + mode + level +
  discipline/expression**, emitted **only** as the wizard's existing allowlisted
  tokens (`crash | standard | certified-musician | grand-musician | meta-order`;
  `precert | cert | prediploma | diploma`; `classical | contemporary | african`;
  real disciplines). One "recommended path" card + "see other options."
- **Hand-off = today's prefill path.** Choosing a path routes into the wizard via
  `?pathway=&mode=&level=&discipline=` — reusing the XSS-safe allowlist at
  `apply.js:177–182`. No new endpoint, no new trust surface, no DB change.

**Honesty guardrails (per §0)**
- Presents a *recommended starting point*, explicitly: "**MAM Auditer fine-tunes
  your placement**." No hard tuition quote. No fabricated scholarship eligibility —
  links to the existing scholarship info instead. Advanced applicants are routed
  toward **Auditer**, beginners toward the foundational path — a soft branch, never
  a hard disqualification.

**Progressive enhancement & a11y**
- JS-off or "skip" → `apply.php` behaves exactly as today (finder is additive).
- Reuse `.choice-card` / `.field` patterns, `aria-current`, reduced-motion, and
  persist answers into the existing `data` draft object so a return visit remembers them.

**Verification**
- `php api/tests/run.php` green; `node --check` changed JS; `php -l apply.php`.
- Playwright: complete finder → lands in wizard **pre-filled with valid tokens**;
  "skip" and JS-off both leave the current flow intact.

**Est. effort:** ~S–M, front-end only (`apply.php` markup + `assets/js/apply.js`
or a small `assets/js/apply-discovery.js` + a few `assets/css/apply.css` rules).

---

## 3 · Recommended sequence (fit-ordered, not guide-ordered)

1. **#1 Find Your Path** finder (this doc, §2) — the real top-of-funnel gap.
2. **#5/#12 lifecycle timeline** render on `status.php` — pure UI over data that
   already exists; makes the existing admissions stages legible to applicants.
3. **#9/#13 copy pass** — reframe apply→Auditer→enrol so the stages read clearly
   (near-zero code).
4. **#3/#14 eligibility soft-screen** — implement the portal-brief §#1 rules as a
   *signal + routing* (server re-derived), not a hard gate.
5. **#15 completeness meter** + optional **#8 applicant AI readiness** (advisory,
   reusing the existing gateway).
6. **#11 advisor card** on `status.php` (needs an advisor contact source).
7. **Later roadmap / product decision:** #7 document OCR, #10 orientation hub,
   #4 only ever as scholarship *awareness*.

---

## 4 · Explicitly out (with reasons)
- **Auto-scholarship detection (#4):** conflicts with admin-issued scholarships.
- **Tuition-quote-at-apply / payment-as-enrolment:** conflicts with the
  booking-fee → Auditer → enrol model.
- **Document OCR/verification (#7)** and **orientation hub (#10):** net-new
  subsystems; no current requirement in the pre-Auditer flow — roadmap items.

*No code changed by this document. It exists to pick the next build with eyes open.*
