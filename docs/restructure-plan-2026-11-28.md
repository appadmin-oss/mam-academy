# MAM restructure: what the research found, and the plan

| | |
|---|---|
| **Owner** | Academy technical lead (delivery); academy owner (the decisions marked ⚖️) |
| **Version** | 1.0 — 2026-11-28 |
| **Evidence** | Twelve audits and research tracks run 2026-11-27/28 on the latest branch: code structure, data, frontend, docs, v2 schema, identity (NGG and external), architecture, operations, security, smart features, finance, learner/instructor/guardian links, learning structure (pending), end-to-end journeys (pending) |
| **Related** | `api/modules/README.md`, `docs/data-model-audit-2026-11-27.md`, `docs/live-data-migration-plan.md` |

## 1. Where MAM stands

MAM is not bad at its rules: frozen FX, refund calendar, safeguarding gate,
NDPA register and mastery bands are all careful work. It is bad at
**structure**. Features were added beside what existed instead of into it, so
most concepts now exist two to seven times. The research measured:

| Area | What is duplicated or missing |
|---|---|
| Code | 1,555 global functions loaded on every request; a 3,234-line admin if-chain beside the API; a second admin write path that drifted from the first |
| Schema | Two hand-synced schemas (SQLite strings in PHP, a MySQL file); a migrator that hides its own errors and runs ~19 queries on **every** request on MySQL; a third, dormant v2 schema that is itself faulted |
| People | `users` + `customers` + five application tables joined by **email**, which has already merged parents with children in real data |
| Teaching | ~22 copies of "does this instructor teach this learner", with 6 different status sets; a former teacher keeps access to a cohort's videos forever |
| Guardians | Scope enforced on some screens and not others; removing a guardian leaves thread and billing access; adults get guardians by default; nothing happens at 18 |
| Money | 3 invoice concepts, 5 payment concepts, 7 ways of storing an amount; no double entry; refunds of online fee payments stay credited; settlement can stop halfway with nothing to finish it; Flutterwave cannot settle USD |
| Smart features | AI placement, triage, advisor, progress narrative and practice coach are **built but unreachable**; analytics duplicated three ways and some console figures are wrong |
| Operations | No monitoring, no off-site backup, no tested restore, no deploy process; a missing cron silently stops payment reconciliation, reminders and FX updates |
| Security | Nine verified holes, **fixed 2026-11-28** (see §5) |

## 2. The target

A **modular monolith** on the same hosting: one PHP application, split into
domain modules with measured boundaries, one schema source, one write path per
rule. No framework rewrite; each step keeps the site live and the suite green.

```
api/modules/<module>/
  module.php      manifest: libs, depends (measured by test_module_boundaries.php)
  lib/            today's functions → tomorrow's classes (Mam\<Module>\…)
  routes.php      the module's routes (step 2)
  migrations/     the module's schema changes, one file each (step 4)
```

### 2.1 People and identity (first module to move)

From NextGenGen's lessons, the v2 audit, and Nigerian and international
practice (NDPA 2023 s.31, GAID 2025, Google and Microsoft education accounts,
Clever and Khan Academy):

- **people**: one row per human, child or adult. Holds full name exactly as
  typed and date of birth. The identity is the person, never an address.
- **contact_points**: email, phone, WhatsApp. Not unique, so a family inbox
  can sit on several people. Verified flag.
- **accounts**: one per person who signs in. `username` is unique and
  system-issued (`MAM-26-0014`, never reused). `login_email` is optional, the
  person's own, verified, and may be shared (then a "Who's signing in?"
  picker appears). Managed flag for under-13s: no self-service recovery; the
  guardian or office resets.
- **credentials**: password, and a QR badge for young children if wanted.
- **memberships**: learner, guardian or staff. One person can be all three.
- **guardianships**: guardian person → learner person, with relationship,
  scope (billing / standard / full), consent record, start and end dates,
  bill-payer flag and review date. Under 18 only by default; at 18 the
  guardianship goes to review (the learner re-consents or it ends). An adult
  learner has none unless they nominate someone.
- **Matching** at admission is on email + date of birth (or name). Never
  merged automatically; a staff review queue decides.

### 2.2 Teaching

- **cohort_staff** (cohort, instructor, role lead / assistant / cover, start
  and end dates, single session for cover, vetting checked at). Ending an
  assignment closes the row, so history keeps its teacher.
- **One predicate**, `mam_teaches(instructor, learner, at, purpose)`, which
  every one of the ~22 copies calls. Vetting is folded in whenever the
  learner is a minor. A `historical` purpose gives read-only access to
  past marking.
- Vetting is re-checked when a minor joins a cohort and when cover is
  assigned.

### 2.3 Finance (from the finance audit)

- **accounts** (family, adult learner, sponsor, corporate) with a billing
  currency; members with roles and payment shares.
- **price_books** with effective dates.
- **invoices**: application, term or store, frozen at issue. Changes after
  issue are credit notes.
- **adjustments**: waiver, aid, sibling, code, credit note; each with a reason
  and an approver.
- **payments** and **allocations**. Unallocated money is account credit, and
  can move to a sibling's invoice.
- **refunds**, applied through allocations and the published refund schedule.
- **A double-entry ledger** (transactions whose entries sum to zero per
  currency): AR, cash per channel, gateway fees, deferred tuition recognised
  over the term, aid expense, instructor payables. Every console money
  figure reads from it.
- **Settlement as an outbox**: one transaction records the payment, its
  allocations and the ledger entries. Enrolment, email and stock are
  retryable jobs.
- **Money is always an integer in minor units plus currency**, with FX quotes
  stored (rate, source, time).

**Done on v1 now (2026-11-28), ahead of the module move** — the audit's live
faults, each pinned by `test_finance_fixes.php` and each reproduced on the
unfixed code first:
- **Settlement is recoverable.** The `pending → succeeded` claim and a
  `settle_effects` job are one transaction; the effects run at once and the
  job is marked done, or the job runner finishes them later
  (`mam_payment_settle_effects()`, idempotent per purpose). Console → Ledger
  lists "Settled, not yet finished" (the job backlog plus pre-job payments
  whose effect is visibly missing) with a **Finish** button
  (`payment_settle_finish`, `settings` cap). A held identity stays held.
- **Refunds reverse the fee credit.** An admin refund voids the linked
  `fee_payments` row, so the balance is owed again. A Paystack
  `refund.processed` sets `gateway_state = 'refunded'` (out of every console
  paid figure) and voids the fee credit; the payment row and what it paid for
  are left to a person, as the guard rules already required.
- **An adult learner (18+, known age, no guardian) is their own payer**:
  invoice, review and **Pay online** in the student portal
  (`POST /api/student/fee-pay`). Unknown age is never treated as adult.
- **Console money compares like with like**: Collected is fee money received
  per invoice at its frozen rate (desk cash included) against billed; aid is
  converted per invoice; store revenue is integer cents from settled payments.
- **Bank-confirmed payments settle through `mam_payment_settle()`**, so a
  confirmed fee transfer credits its invoice.
- **Only a unique-constraint violation reads as "already recorded"**
  (`mam_db_is_unique_violation()`); an outage now leaves the job pending.
- **Flutterwave**: non-200 on a processing error (the retry is processed, not
  acknowledged), a second charge is flagged `refund_due`, and any currency but
  NGN is refused **explicitly** (logged `flw_currency_refused`, recorded as a
  payment event). Test/live: every charge is re-read with this install's own
  key; `mam_flw_mode()` is logged beside refusals.
- Left for the owner (§6): billing currency per family, and which gateway
  takes USD.

### 2.4 Learning (from the learning-structure audit)

Today there are:
- **three syllabus hierarchies**: `modules`, cohort `lessons` (never linked
  to a module), and the PHP curriculum seed;
- **12 scoring systems** (letters A–F, rubric points,
  Pass/Merit/Distinction, exam bands, tiers, 0–5 teach-backs, mastery %,
  report levels and grades, GPA, an IRT ability estimate…);
- **pass/fail thresholds in 9 places**, so "Distinction" means ≥80, ≥85,
  ≥90 or 27/30 depending on the screen;
- exams and syllabus lessons that never reach the gradebook, mastery or
  reports.

The most serious faults:

1. **No termly report can be ratified.** Ratify, moderate and
   appeal-decide have no button or route, so families never see a report
   and a parent's appeal can never be answered.
2. **Performance marks from the new marking screen land in a field
   nothing reads.** A second marker would overwrite the first.
3. **Rubric edits orphan old marks.** Criteria are deleted and
   re-inserted.
4. **A "finalised" gradebook is rewritten on every recalculation.**
   Certificates issue from provisional totals.
5. **The old authoring paths skip lesson governance.** No version bump,
   and new lessons are published by default.
6. **Two assignment models, no late policy,** and students can mark
   their own assignment complete.
7. **Mastery bugs:**
   - attendance leaks into mastery via the gradebook;
   - one score across all courses feeds per-course reports;
   - two of these were fixed 2026-11-28 (see §5).

Target: one versioned catalogue (programme → course × level →
**syllabus version** → unit → activity) and **one scoring pipeline**:

> evidence (submission or sitting) → **mark** (per criterion, first or
> second marker, draft → moderated → released; null = not assessed) →
> **result** per enrolment per competency → **mastery** snapshot per
> enrolment and term (coverage floor, band) → **termly report**
> (moderation → appeals closed → board ratifies, through a real console
> action) → **certificate** only from a ratified report or exam.

Thresholds and pass rules live in one place:
- one `band_scheme` table and one `pass_rule` per programme and kind
  replace the nine thresholds;
- one `can_assess()` check, which includes cover teachers.

The academy's refusals stay:
- no automatic scoring of a child's playing;
- no rankings or percentiles;
- AI may suggest marks for written work only, and a person confirms
  them.

### 2.5 v2: corrected, not adopted as-is

Keep from v2:
- minor units and frozen FX
- `row_version`
- idempotency and webhook tables
- the hash-chained audit log
- `legacy_reference_map`
- one cohort/enrolment spine
- guardian scopes

Cut:
- tenants and every `tenant_id`
- translations
- custom fields
- outbox without a worker
- API clients
- ULIDs except on tables exposed in URLs

Change:
- RESTRICT (not CASCADE) on academic, money and safeguarding records
- the identity core above
- one applications table with a `kind` and course choices as references
- give v1's newer features a home in v2: safeguarding concerns, incidents,
  collectors, media, spaces, live classes, lessons and exams

## 3. Making MAM smarter: ranked by value and how much is already built

| # | Capability | Status | Notes |
|---|---|---|---|
| 1 | **At-risk learner watch-list** (attendance trend, practice gap, overdue lessons, unpaid fees, ages ~11 and 15–17 when dropout peaks) — **staff only** | 3 versions exist; merge and give it a screen | Never shown to a student; money is a reason, never a sanction |
| 2 | **Turn on what is built**: AI placement and triage on the admissions card, an AI provider setting, progress narrative and practice coach in the new portals | Built, unreachable | One settings field plus UI |
| 3 | **Make-up credit engine** with self-booking offers | Mostly built (`makeup.php`, slot finder) | The strongest retention lever in the market research |
| 4 | **Recurring payments**: Paystack card authorisation and Direct Debit, with smart retries | Not built | ~40% of churn comes from failed payments (Recurly) |
| 5 | **A dedicated bank account number per family** (Paystack virtual accounts) | Not built | Bank transfers reconcile themselves |
| 6 | **Guardian weekly digest** (attendance, teacher's note, practice, next class) | Partly built | Parental involvement predicts the motivation that keeps students |
| 7 | **WhatsApp utility messages** (reminders, receipts, make-up offers) | Transport built (Kapso) | Free inside the 24-hour window; needs Meta-approved templates |
| 8 | **Teacher copilot**: drafts termly report comments and lesson plans for a teacher to approve | Gateway built | ~31% planning time saved in a UK trial of 259 teachers; names pseudonymised before sending |
| 9 | **Smart waitlist auto-offer** with a time-limited hold | Partly built | |
| 10 | **Outage-resilient online classes**: automatic credit when a session fails, low-bandwidth mode | Not built | 281 major outages in two months (NCC) |

Not recommended, and against MAM's own refusals:
- audio AI that scores a child's playing;
- rankings of children. The studio leaderboard does this today; the
  owner decides ⚖️.

## 4. Order of work

Each step ships alone, keeps the suite green, and has a runbook with an owner
and a version date, per the organisation's operating standard.

| Step | What | Size |
|---|---|---|
| 0 | ✅ Libraries into 15 modules with measured boundaries · ✅ nine security holes closed | done |
| 1 | **Operations baseline**: cron heartbeat and health alerts, off-site backup with a restore drill, checkout never fetches FX live, no per-request migration on MySQL, isolated job sweeps, an HTML 500 page, opcache confirmed, roles memoised (removes 73 of the dashboard's 122 queries) | 1–2 weeks |
| 2 | **Money safety on v1**: recoverable settlement (outbox job), refunds that reverse fee credits, Flutterwave parity, correct console money figures | 1–2 weeks |
| 3 | **Tooling**: PHP ≥ 8.2, PHPStan baseline, Rector dry-run, a deploy pipeline with release folders and an explicit migrate step | 1–2 weeks |
| 4 | **One migration source**: one file per migration, baseline frozen at v108, MySQL schema generated and checked | 1 week |
| 5 | **Identity module onto the corrected model**, live data per `docs/live-data-migration-plan.md` (review queues for every ambiguous person) | 3–4 weeks |
| 6 | **Teaching and guardianship**: `cohort_staff`, `mam_teaches()`, dated guardianships with age review | 2–3 weeks |
| 7 | **Finance module** onto accounts, invoices, allocations and ledger | 4–6 weeks |
| 8 | **Learning module** onto one scoring pipeline: first wire ratification, moderation and appeals into the console, route marking-sheet marks into assessments, version rubrics | 4–6 weeks |
| 9 | **Routes and the 137 admin actions into their modules**: one write path, deny-by-default everywhere | 2–3 weeks |
| 10 | **Smart features**, top of §3 first, as their modules land | ongoing |

## 5. Fixed already (2026-11-28)

Each was verified against a local copy and is pinned by
`test_access_hardening.php`:

1. Any instructor could join any class's meeting.
2. Any instructor could open any student's ID card.
3. A manager-tier admin could mark an application paid without a payment;
   16 actions had no permission entry (now deny by default).
4. The break-glass login had no limit on guesses.
5. Any admin tier could export every user, payment and applicant.
6. Exported CSV cells could run as spreadsheet formulas.
7. Admin create-user reported a failed invite as sent.
8. A billing-only guardian received the child's marks and attendance.
9. Guardians could replay recordings of a child playing (`takes`), and any
   guardian could change consent.
10. **Learning:** a perfect teach-back counted as 5% in the mastery score
    (stored 0–5, divided by 100).
11. **Learning:** a cohort rank and percentile were sent to students, to
    guardians and to the printable report, against the academy's refusal
    to rank children.
12. **Identity (found by driving /apply in a browser):** a parent applying
    for a child with their own email **rewrote the parent's record into the
    child's**, and enrolment then put the child's course in the parent's
    account. Such an application is now **held** (schema v109,
    `applications.identity_hold`):
    - the existing person is left untouched;
    - the submitted identity is kept on the application;
    - settlement records the money but makes no account from it;
    - marking paid, Auditer placement and Complete enrolment all refuse until
      the office ticks "same person";
    - a different person must get their own email until the identity module
      lands.

    `test_identity_hold.php`.
13. An admin could link a student as their own guardian, which also gave the
    student's login the parent role.
14. The student ID-card email crashed with a TypeError on every send, so no
    card email was ever delivered.
15. **Finance (the finance audit, §2.3):** settlement recoverable with a
    console Finish; refunds give the invoice balance back; adult learners
    invoiced and paying online; console money like with like; bank
    confirmations through the one settlement; unique-only "already recorded";
    Flutterwave retries, duplicate and NGN-only refusals.
    `test_finance_fixes.php`.
16. **Guardian threads never notified anybody**: the notice's link was an
    array, a TypeError the post swallowed. Each participant now gets a link to
    their own screen (an instructor has no thread screen yet, so theirs opens
    the portal).

Still open from the browser run:
- **Session times are an hour apart** between the admin timetable (Lagos
  time) and student check-in (read as UTC), so check-in opens at the wrong
  time.
- **Scholarship applications don't appear in the console.**
- **Opening an application in the console creates an empty cohort.**
- **Work marked in a class space never reaches the Marking queue or the
  student's Learn screen.**
- **The instructor "Book your interview" link is a dead end:** it leads to
  the student $5 booking page.
- **The card-payment fallback message is wrong**, and the bank-transfer
  choice sends no bank details.

Still open from the security review (medium and low):
- child headshots are public and survive erasure;
- an open redirect after login;
- Apache may mark API responses as publicly cacheable;
- integration secrets are stored in plaintext in settings;
- debug tokens are passed in the query string;
- password-reset single use is not atomic;
- any instructor can read every material file.

## 6. Decisions waiting on the owner ⚖️

1. **Billing currency per family** (invoices default to USD while every card
   charge is in NGN), and **which gateway takes USD** from the diaspora.
2. **Child login for young children**: username and password only, or also a
   printable QR badge?
3. **Academy email addresses for students**: not needed for NGG parity, since
   NGG uses the student's own address. Build it only if wanted, as a
   separately consented feature.
4. **The studio leaderboard**, which ranks children by a microphone score.
5. **Who reviews the identity queue** during the move: the merged
   parent/child records found in real data.
6. **Name the safeguarding officer and switch the vetting gate to
   `enforce`** (outstanding from the handoff).
7. **File the Data Privacy Impact Assessment** required by GAID 2025
   Art. 28(3)(m) for student records.
