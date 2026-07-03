# MAM Academy — Application-Flow Guide: Fit Review & Integration Plan

**Status:** admissions product strategy · **Date:** 2026-07-02 · **Rev 5**
(Rev 5 makes it **placement _and_ conversion** — an explicit conversion/desire
discipline and a landing→enrolment north-star; Rev 2 folded in the 17 UX reservations; Rev 3 adds the executive assessment's
12 strategic reservations — vision, emotional journey, metrics, outcome-based
roadmap — and the newly-decided **Scholarship Journey**)
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

## ✦ Product vision (the north star)

> **MAM Academy exists to discover, develop and deploy musical potential. Every
> applicant should leave the admissions journey feeling _understood_, _accurately
> placed_ and _excited about their future_ — whether or not they ultimately enrol.**

**Placement _and_ conversion — they compound, they don't compete.** A university
sells admission; a course platform sells enrolment. MAM does both *better* by
leading with placement: an applicant who feels genuinely understood, accurately
placed and excited **converts at a far higher rate** than one pushed through a
funnel. Trust is the highest-performing conversion tactic we have. So the journey
delivers value even to those who don't enrol (an honest read of where they belong)
**and** is engineered, end to end, to make enrolling the easy, exciting, obvious
next step. **We optimise for both — measured conversion lift is an explicit goal
(§4b/§4c), pursued through desire and clarity, never through dark patterns.**

Every feature below must make the journey more **intelligent** (better placement),
more **transparent** (less uncertainty), more **encouraging** (less anxiety, more
belonging) — and more **enticing** (more desire to say yes). If it does none of
those, it doesn't ship. Contributors optimising an isolated feature should check it
against this line first.

Two signature experiences carry the brand:
- **The Learning Advisor** — the *entrance* to MAM. Not a form; the first
  conversation with an academy that understands you (§2).
- **The MAM Auditer** — MAM's defining differentiator. Internally it stays
  "Auditer"; experientially it is *"Discover your musical potential — find your
  starting point,"* an aspirational milestone, never a procedural hurdle (§0.1, §2c-5).

## ✦ Experience principles — design for the applicant's emotional state

The flow is engineered around **emotional transitions**, not screens. Each stage
has a dominant anxiety; each feature exists to convert it:

| Stage | Applicant is feeling | The product must deliver | → Transition |
|-------|---------------------|--------------------------|--------------|
| Landing / considering | *"I don't know where to begin."* | The Advisor: a warm, aspiration-first conversation with a confident recommendation | **Curiosity → Confidence** |
| During the wizard | *"Is this right for me? Is it too much?"* | Persistent recommendation + reasons, progress celebrated, contextual answers inline | **Confidence → Commitment** |
| After submission | *"Did it work? What now?"* | Instant roadmap ("what happens next"), living timeline, predictive next step | **Commitment → Validation** |
| Before the Auditer | *"What exactly happens there?"* | Auditer explainer threaded from the start: what/why/benefit/how to prepare | **Validation → Belonging** |
| After the Auditer / awaiting decision | *"Did I do well?"* | Honest status, typical durations, prepare-now resources, community welcome | **Belonging → Excitement** |

**Rule:** every roadmap item in §3 must name which transition it serves. Anything
that serves none is decoration.

## ✦ Design principles (implementation guardrails)

Vision says *why*; the emotional map says *for whom, when*; these say *how* — the
rules every screen obeys so two engineers build the same experience:

1. **Guide, never overwhelm.** One decision per screen; progressive disclosure.
2. **Recommend, never dictate.** Always show alternatives and a way to override.
3. **Explain every decision.** No black boxes — recommendations, readiness and
   next-steps always show their "because."
4. **Always preserve user control.** Skippable, editable, reversible; no dead ends.
5. **Celebrate progress.** Acknowledge each milestone; momentum is a feature.
6. **Consistency over cleverness.** Reuse the established patterns and tone before
   inventing new ones.

## ✦ Conversion & desire — make every step *want*-able

Attractiveness is a feature, and conversion is a goal, not a side effect. The
journey should make an applicant *lean in* at each step — pulled by desire, not
pushed by pressure. Tactics, each mapped to the funnel stage it lifts (§4c) and
each **honest** (no fake countdowns, no manufactured scarcity, no guilt):

- **Aspiration first.** Open on the dream ("what do you dream of becoming?") and
  reflect it back as a vivid future self — desire is the strongest converter.
  *(Lifts: Landing → Advisor start.)*
- **Instant, personalised payoff.** The Advisor returns a confident, flattering-
  but-true recommendation within seconds — value *before* the form. *(Advisor →
  Application start.)*
- **Momentum & near-completion.** Visible progress, "you're 2 steps from your
  Auditer," celebrate every milestone — the closer it feels, the fewer drop off.
  *(Application start → submit.)*
- **Social proof at the decision moment.** Right where they hesitate ("1,200
  musicians started with Piano," a graduate story) — proof beats persuasion.
  *(Programme choice → submit.)*
- **Belonging pull.** Show the community they're about to join *before* they pay —
  people commit to rooms they can already picture themselves in. *(Submit →
  booking-fee.)*
- **Honest urgency only.** Real intake dates and real remaining-seat counts
  (already in `config.js`/`config-public`) create genuine now-ness; never invent it.
- **Radical friction removal.** Autosave, resume-anywhere, prefill, one-tap pay,
  currency shown in the applicant's money — every removed keystroke is conversion.
- **Price framed as value + access.** Lead with outcomes and the low booking fee;
  surface the Scholars Initiative so cost is never the silent reason someone leaves.
- **Reassurance at every commit point.** "You can change anything before payment,"
  "the Auditer fine-tunes this" — safety converts the anxious.

**Guardrail:** desire, clarity and momentum — never coercion. The design principles
(recommend-not-dictate, preserve control, explain everything) bound every tactic
here. Attractive *and* trustworthy is the only combination that compounds.

---

## 0 · The two hard constraints the guide doesn't know about

1. **The money model is not "apply → pay tuition → enrolled."** It is:
   **free application → flat regional booking fee (~$5, `payments.purpose='booking_fee'`) → MAM Auditer assessment → admin enrols after assessment → tuition discussed/charged later** (`tuition.php` + `GET /api/tuition/status`). `mam_payment_settle_booking()` deliberately **does not** enrol or mark the application paid. So any guide step that shows a **tuition quote up front**, or treats **payment as the end of the application**, must be reframed as "confirm your seat (booking fee) → Auditer → placement."
2. **The scholarship *decision* stays human — but the *intake* becomes a structured, applicant-initiated journey.** Rev 1–2 treated scholarships as "banner + admin award." Stakeholder direction in Rev 3 supersedes that: build an **opt-in Scholarship Journey** — *Request consideration → transparent soft requirements → an essay-based assessment (need · musical journey · commitment · values · community impact) → Auditer recommendation → committee review against a weighted rubric → signed agreement → activation.* This does **not** break the "admin-issued" principle — the award is still a human committee decision (`users.student_type` / `mam_user_is_scholarship` remain the source of truth). What changes: we **structure the intake in front of that decision** instead of a passive banner. Auto-*detection* of eligibility is still forbidden (it would imply a promise). Framing is **investment, not charity** (a CSR initiative entrusting talented, values-driven musicians). Full design in **§2c**.

Everything below is judged against these facts.

---

## 1 · The 15 items, mapped to the live code

Legend — **✅ Exists** · **◑ Partial** · **➕ Fits (build)** · **⚠ Conflicts / reframe** · **🧱 Net-new subsystem**

| # | Guide item | Verdict | Where it lives / what's missing | Effort |
|---|-----------|---------|--------------------------------|--------|
| 1 | Pre-application **Discovery / Find Your Path** | ➕ **Build — top pick** | No discovery step today; wizard opens on Step 1 "Choose Your Programme" (`apply.php`). The wizard already prefills from allowlisted `?pathway=&mode=&level=&discipline=&expression=` (`apply.js:165–183`), so a finder can hand off with **zero new trust surface**. | S–M |
| 2 | "Choose Your Learning Path" premium cards + **Compare** | ◑ Partial | Programme cards exist in Step 1; a full compare page already exists (`compare.php`, linked from the mega-menu). Missing: per-card *weekly commitment / outcomes / intake* metadata surfaced in-flow. | S |
| 3 | **Instant eligibility screening** | ➕/⚠ Build, reframed | No eligibility gate today. Portal brief §#1 specifies rules (age 16–26, instrument ownership, prior experience, commitment, values). Reframe "auto-disqualify" → **soft signal + route to Foundation/Auditer**, since MAM places via Auditer, not hard rejection. Server re-derivation must live in `api/lib/validation.php`/`admissions.php`. | M |
| 4 | **Smart scholarship detection** | ⚠→➕ **Reframed to a Scholarship Journey** | Auto-*detection* stays forbidden (implies a promise). **Superseded by §2c**: an opt-in, essay-based scholarship intake feeding a human committee — decision still admin-issued (`users.student_type`), but the intake is now a first-class journey. | M (own workstream) |
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

**The Advisor's voice (a defined character, so two builds feel the same)**
- **Persona:** a *warm, wise mentor* — the encouraging senior musician who has
  guided hundreds of students, not a chatbot and not an admissions officer.
- **Tone:** encouraging first, professional underneath, aspirational throughout.
  Speaks *to* the applicant ("you", "let's"), celebrates their ambition, never
  gatekeeps ("there's a place for you here — let's find it").
- **Recommendation philosophy:** *placement, not judgement.* It never says "you
  don't qualify"; it says "here's the best place to begin, and here's why." It is
  confident but humble — it always defers final placement to the Auditer.
- **Copy examples:** *"Beautiful — worship leaders thrive in our Performance track.
  Let's build from there."* / *"You're closer than you think. Two quick questions
  and I'll show you your path."*
- These voice rules are binding copy guardrails, owned per **§6 content governance**.

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

## 2c · The MAM Scholars Initiative (decided in Rev 3 — its own workstream)

Named, not generic: the **MAM Scholars Initiative** — *"We invest in exceptional
musical potential and outstanding character."* This is the mission framing the
reviewer asked for: it is **not "financial aid" and not "scholarship application"**;
it is a CSR initiative that *entrusts* talented, values-driven musicians with an
investment they are expected to multiply in their communities. Every word in this
flow should read as **investment and calling, not charity**.

Mechanically it is a **parallel review**, explicitly separate from admissions
(*Admission ≠ Scholars Initiative · it is earned, limited, mission-driven*), running
after the booking fee and feeding the Auditer + a human committee.

**Lifecycle (its own status track, distinct from the application stage):**
```
Scholarship Requested → Eligibility Acknowledged → Assessment Submitted
  → Auditer Recommendation → Committee Review → Decision
  → Agreement Signed → Activated → Ongoing Progress Reviews
```
Sits alongside the admissions chain: `Application → Booking Fee → [Scholarship
Interest → Assessment] → Auditer → Admission Decision → Scholarship Decision →
Enrolment`.

**Flow & components**
1. **Opt-in gate** — *"Would you like to be considered for the MAM Scholarship
   Programme?"* Yes opens the assessment; No continues normally. Never automatic.
2. **Transparent soft requirements** (shown up front, explicitly *not* pass/fail):
   ages 16–26 · prior musical experience (not a complete beginner) · owns/has
   reliable access to their instrument · available 5 days/week incl. Saturdays ·
   demonstrable financial need · values alignment · community commitment · will
   complete the programme. With the disclaimer: *"Meeting these does not guarantee
   a scholarship; every application is reviewed holistically."* **These are the
   portal-brief §#1 eligibility rules** — so the "Learning Readiness" screen (§6)
   and the scholarship requirements share one server-side derivation in
   `validation.php`, not two.
3. **Essay-based assessment** (5 sections): Financial Need · Musical Journey ·
   Commitment (with an explicit *"I understand and commit"* checkbox) · Values
   Alignment (**scenario prompts**, e.g. *"a time you served your community through
   music"* — reveals character better than yes/no) · Community Impact (*"how will
   you multiply this investment?"*).
4. **Auditer recommendation** — the Auditer returns a structured scholarship signal
   (`Highly recommended / Recommended / Recommend if funding permits / Not
   recommended`) **alongside** Musical Potential / Technical Readiness / Growth
   Potential — *input to* the committee, not the decision. **This is the natural
   first writer for the `auditer_results` table** (currently writer-less — CLAUDE.md
   gotcha #5), turning a reserved table into a real capture flow.
5. **Weighted review rubric** for staff (supports, never replaces, judgment):
   Financial Need 25% · Musical Competence 20% · Values Alignment 20% · Growth
   Potential 15% · Community Impact 10% · Commitment & Availability 10%.
6. **Digital Scholarship Agreement** — recipient signs before enrolment (attendance,
   participation, conduct, progress, values, review/withdrawal terms). Reuse the
   existing electronic-signature pattern from the application policy step.

**Backend surface (new, but bounded):** a `scholarship_applications` table
(opt-in, essay fields, status track) + its own status route mirroring
`application/status.php`; the Auditer recommendation lands in `auditer_results`;
the award endpoint still routes through the existing admin `set-student-type`.
No change to who decides. **Own workstream — sequence after the core journey (§3
Objective 3), since it depends on the readiness derivation and the Auditer capture.**

---

## 2d · Executive-assessment reservations (the strategic 12) — where they live now

Rev 3 feedback is *strategic* (vision/emotion/metrics), not technical. Most are now
addressed by the **Vision** + **Experience Principles** sections above; the table
records where each lands so none is lost.

| # | Strategic reservation | Where it now lives |
|---|----------------------|--------------------|
| 1 | Experience-driven, not solution-driven | **Experience Principles** table (emotional state → transition per stage). |
| 2 | Elevate the Advisor into a signature product | **Vision** ("the entrance to MAM") + §2 reframed as *Meet Your Learning Advisor*. |
| 3 | Start from **dreams/aspirations**, not pathways | §2 Q1 is *"What do you dream of becoming?"*; pathway is the *output*, surfaced late. |
| 4 | A product-vision north star | New **Product vision** section. |
| 5 | Auditer as brand identity, not operation | **Vision** + §2c-4 + §3 Obj-2: aspirational framing (*"Discover your potential"*), Auditer threaded from the start. |
| 6 | Deeper, proactive personalization | §2 persistence + examples ("two steps from your Auditer"; "you may enjoy meeting Pastor Samuel at orientation") — from the `data` draft + placement profile. |
| 7 | Dashboard → **"My Journey"** (user term) | Rename the mission-control surface to **My Journey / Admissions Journey**; "dashboard" is internal only. |
| 8 | **Social proof at decision moments** | Contextual proof *at the choice*, not a testimonials page: on picking Piano show "N students started here / graduate story." Data from faculty + (future) cohort counts. |
| 9 | **Community before enrolment** | Add belonging touchpoints: meet future classmates, watch student rehearsals, alumni stories — begins the "joining a movement" feeling pre-enrolment. |
| 10 | Leverage AI further (without deciding) | Explain recommendations · summarise programmes · answer admissions Qs · Auditer prep · resource suggestions · translation · a11y assist — all via the audited gateway, behind `ai_provider`, off the critical path. |
| 11 | **Success metrics** | New **§4b** below. |
| 12 | **Outcome-based roadmap** | §3 rewritten around objectives, not features. |

---

## 3 · Roadmap — organised by outcome, not feature

Per the executive assessment, work is grouped under **objectives** (the *why*),
each tagged with the emotional transition it serves. Features are the *how*.

### Objective 1 — Help applicants choose confidently  ·  *Curiosity → Confidence*
- The **Learning Advisor** (§2): aspiration-first, weighted, transparent, persistent.
- **Outcome-centric programme cards** (who it's for · what you become · duration ·
  skills · after graduation) + the existing `compare.php`.
- **Social proof at the decision moment** (§2d-8) and early **community** touchpoints (§2d-9).
- *Success signals:* higher discovery→apply start rate; more completed Step 1s.

### Objective 2 — Reduce uncertainty  ·  *Commitment → Validation → Belonging*
- **"What happens next" roadmap** on the post-submit panel + **My Journey** page (§2d-7):
  living-history timeline (`mam_admissions_events`) + predictive next-step & typical
  durations (extend `mam_application_stage`).
- **Auditer-forward** explainer threaded from the start, framed aspirationally (§2c-4, §2d-5).
- **Contextual knowledge center** (inline "why the Auditer?", "can I switch?") + an
  **emotional tone** pass (celebrate milestones, welcome to the community).
- *Success signals:* fewer admissions support tickets; higher booking-fee conversion;
  higher Auditer attendance.

### Objective 3 — Improve placement quality (and fund it)  ·  *Belonging → Excitement*
- **Learning Readiness** screen (§6) — server-derived signal + routing, never a gate.
- **Placement Profile** (§2b-7) — richer learning/technical/community data for cohorts.
- **The Scholarship Journey** (§2c) — opt-in intake → Auditer recommendation (first
  writer for `auditer_results`) → weighted committee review → signed agreement.
- *Success signals:* better cohort fit; scholarship pipeline throughput; instructor
  satisfaction with placement data.

### Cross-cutting — AI augmentation (never dependency)
Explanations, programme summaries, admissions Q&A, Auditer prep, resource hints,
translation, a11y assist — all via the audited gateway behind `ai_provider`, off the
critical path (§2d-10, reservation #17).

**First build (unchanged):** the Objective-1 **Learning Advisor** — highest leverage,
self-contained, front-end only, hands off via the existing allowlisted prefill.

---

## 4 · Explicitly out (with reasons)
- **Auto-*detection* of scholarship eligibility:** implies a promise; the decision
  stays a human committee (the *intake* is structured — §2c).
- **Tuition-quote-at-apply / payment-as-enrolment:** conflicts with the
  booking-fee → Auditer → enrol model (§0.1).
- **Document OCR/verification** and a full **orientation hub:** net-new subsystems;
  no current requirement pre-Auditer — later roadmap.

## 4b · Success metrics (define before building)

Targets are illustrative — set real baselines from the funnel analytics
(`api/lib/funnel.php`) first, then commit to numbers.

| Metric | Direction | Illustrative target |
|--------|-----------|----------------------|
| Application abandonment (start→submit drop-off) | ↓ | −30% |
| Completed applications | ↑ | +20% |
| Booking-fee conversion (submit→fee paid) | ↑ | +15% |
| Admissions support tickets per applicant | ↓ | −40% |
| Median time-to-complete the application | ↓ | measurable ↓ |
| Auditer attendance (booked→attended) | ↑ | measurable ↑ |
| Advisor engagement (started→acted on a recommendation) | ↑ | new baseline |
| **Landing → enrolment (end-to-end conversion)** | ↑ | **north-star** |

**North-star metric: end-to-end conversion (landing → enrolment)** — every other
row is a lever on it. The conversion tactics above are hypotheses; ship them behind
the funnel beacon and A/B where feasible so each "enticing" change is *proven* to
lift the north-star, not just assumed to. Instrument each via `POST /api/track` so
the Advisor, timeline and Scholars Initiative report against the goal, not a vanity
count.

### 4c · Full analytics plan (per-stage funnel + drop-off)

Targets need instrumentation. Capture the whole journey as an ordered funnel, plus
**drop-off per Advisor question** and **per wizard step**, all through the existing
beacon so optimisation is evidence-led, not guesswork:

`Landing → Advisor start → Advisor complete → Application start → Application
submit → Booking-fee paid → Auditer booked → Auditer attended → Placement/offer →
Enrolment` — and, in parallel, `Scholars: requested → assessment submitted →
committee decision → agreement signed`.

Per stage record: entry count · completion count · drop-off % · median dwell.
Per Advisor question and wizard step: abandonment rate (find the friction point).
Dashboards read from these; no new tracking framework — new named events on
`funnel.php` / `POST /api/track`.

---

## 5 · Experience refinements (rev-4 detail)

Small but load-bearing refinements the reviewer called out:
- **Richer Placement Profile** (§2b-7) — beyond technical fields, ask *creative
  influences · favourite musicians · biggest learning frustration · weekly practice
  habits · personal goals for the next six months.* These prepare instructors far
  better than notation/ear checkboxes alone.
- **"My Journey" needs priority, not just status** — lead with **"What needs your
  attention today?"** using a tier hierarchy: **Urgent → Recommended → Upcoming →
  Completed**, rather than a flat stage list.
- **Community *before* enrolment** — surface student performances, rehearsal clips,
  current-student stories and an applicant community (WhatsApp/Discord) *during* the
  journey, so applicants feel like members before they are students.
- **AI augmentation roadmap** (behind `ai_provider`, never on the critical path):
  Advisor recommendation *explanations* → programme Q&A → **Scholars essay feedback
  before submission** → Auditer prep → resource suggestions → translation → a11y
  assistance. Sequenced, additive, always augmenting a deterministic core.

---

## 6 · Resilience — the unhappy paths (design these explicitly)

Good strategy designs the failure modes, not just the happy path. Each needs
defined UX (most reuse existing machinery):

| Scenario | Intended experience |
|----------|--------------------|
| Applicant abandons midway | Server draft already persists (resume magic-link); add a gentle, value-led nudge email ("you're 2 steps from your Auditer") via the existing outbox/cron — never nagging. |
| Returns after months | Resume restores context + a warm "welcome back"; if prices/intake changed, show what's new rather than silently differing. |
| Programme becomes unavailable | Advisor + cards read live availability from `config-public`; an unavailable path is shown as "opening next intake — here's the closest available now," never a dead end. |
| Auditer missed / no-show | A clear, non-punitive reschedule path from **My Journey**; the stage explains next steps, not a failure state. |
| Booking-fee / payment expires | The `expired` stage already exists (`mam_application_stage`); pair it with a one-click restart and an honest explanation of the fresh fee. |
| Scholars funding exhausted | Show it transparently: *"this cycle is fully committed — join the next cycle,"* preserving dignity; never a silent rejection. |
| Recommendation ≠ Auditer outcome | The Auditer is authoritative and always wins; frame the shift positively ("the panel found your strengths sit better in X") — this is why the Advisor never over-promises. |

---

## 7 · Governance & evolution (so quality doesn't decay)

- **Recommendation governance.** The weighted matrix (§2, §2c) has an **owner**
  (admissions lead), a **review cadence** (each intake, and whenever a programme/
  price/outcome changes), a **validation step** (spot-check recommendations against
  expert placement), **tests** (fixture answers → expected top pathway, in the
  zero-dep runner), and **versioning** (the matrix carries a version; changes are
  logged). Without this, recommendation quality drifts as programmes evolve.
- **Content governance.** Advisor copy, programme outcomes, trust builders,
  knowledge-centre answers, Scholars guidance and Auditer explainers each have a
  **content owner**, a **review schedule**, an **approval step**, and live in
  versioned source (they already do — they're in the repo), so quality is auditable.
- **Scalability (acknowledge, don't build yet).** The architecture is already
  multi-tenant-shaped (server-authoritative config, cohorts, academic sessions). The
  strategy should keep the door open for **multiple campuses, additional countries,
  partner institutions, corporate cohorts, festivals/competitions and online-only
  pathways** — favour config-driven programmes/pricing over hard-coding so future
  expansion is a data change, not a rewrite. No expansion work now; just don't
  foreclose it.

---

## 8 · Definition of success

Quantitative targets live in §4b/§4c. The qualitative benchmark — the one to read
aloud in a review — is:

> **We'll know this has succeeded when applicants consistently report that they
> understood where they belonged, knew exactly what would happen next, trusted the
> admissions process, and entered MAM feeling welcomed before their first class —
> and when even those who don't enrol leave with an honest, encouraging read of
> their path.**

---

## 9 · Guiding principle

Make the flow feel **less like completing forms and more like beginning a guided
educational journey** — one so clear, personal and **enticing** that enrolling
feels like the natural next step. Carried by two signature experiences (the
**Learning Advisor** and the **Auditer**), governed by the design principles,
designed around the applicant's emotional arc (**curiosity → confidence →
commitment → validation → belonging → excitement**), on the current deterministic
backend, with AI as augmentation. **Placement and conversion compound**: every
feature is tied to the north-star (landing → enrolment), the unhappy paths are
designed with the same care as the happy one, and desire always beats pressure.

*No code changed by this document. It exists to steer the build — and the product —
with eyes open.*
