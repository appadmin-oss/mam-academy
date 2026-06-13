# MAM Academy — Enterprise LMS: Analysis & Architecture Proposal

**Status:** proposal for review · **Author:** engineering · **Date:** 2026-06-13
**Scope:** turn the working v16 LMS into a professional, strategic, flexible, automated, AI-optimized, production-standard learning platform — without throwing away what already works.

---

## 0 · TL;DR

The LMS foundation is genuinely good: a normalized academic model (programmes → levels → courses → enrolments → weighted assessments → results → certificates), an instructor gradebook, flexible self-service course registration with admin approval, level progression, and a portable SQLite↔MySQL migrator. It is **not yet enterprise-grade** because it lacks: a real enrolment/learning lifecycle (state machine), scheduling/sessions as first-class data, submissions & feedback, notifications/automation, analytics, role/permission depth, audit/observability at scale, and any AI layer.

This proposal keeps the schema and PHP API spine and layers on: **(1)** a clean domain/state model, **(2)** automation (events → jobs → notifications), **(3)** an AI optimization layer via the Claude API (placement, adaptive practice, grading assist, progress narratives), and **(4)** production hardening (RBAC, observability, testing, migrations discipline). Delivered in 5 phases, each shippable.

---

## 1 · Current state (honest assessment)

**What exists today** (verified in `api/lib/lms.php`, `db/schema-mysql.sql`, the portals, `admin/index.php`):

| Area | State |
|------|-------|
| Academic model | ✅ `programs`, `levels`, `courses`, `course_enrollments`, `assessment_types`, `assessments`, `results`, `certificates`, `modules` |
| Enrolment | ✅ self-service add (`student/add-course`), duplicate guard, admin approve + instructor assign (now with assignment **memory**) |
| Grading | ✅ weighted components → auto-computed result + grade band + pass/fail (`mam_lms_compute_result`) |
| Progression | ✅ pass advances to same course, next level (`mam_lms_promote_enrollment`) — currently manual/policy-gated |
| Certificates | ✅ issued on a passed programme enrolment; web-viewable |
| Syllabus | ✅ programme week-plan + authored modules (`course/outline`) |
| Practice | ✅ server-backed practice logs + stats; **now auto-fed from the Studio** |
| Attendance / announcements / resources | ✅ per legacy `classes`/`enrollments` |
| Migrations | ✅ idempotent, versioned (`MAM_SCHEMA_TARGET`), dual-driver |
| Tests | ✅ 157 passing (pricing, payments, LMS, progression, certificates, auth, store) |

**Two parallel enrolment systems exist** — the legacy `classes`/`enrollments` (attendance, announcements, resources, instructor portal) and the v16 `course_enrollments` (gradebook, results, certificates). They are not unified; a student's "class" and their "course" are different rows. This is the single biggest structural debt.

---

## 2 · Gaps vs enterprise-grade

1. **Lifecycle is implicit.** Enrolment status is a free `TEXT` (`pending/active/completed/…`) with no enforced transitions, no timestamps per transition, no audit of *who/when/why*.
2. **No first-class scheduling.** Sessions, timetables, rescheduling, capacity, and attendance live only on the legacy `classes` table; the v16 courses have no sessions.
3. **No submissions/feedback loop.** Assignments are title+done; there's no artifact upload (audio/video/PDF), no rubric, no instructor feedback thread.
4. **No automation.** No events/jobs/queue; emails are sent inline (and unreliable — see the separate email workstream). No reminders, nudges, deadline escalations, or auto-progression on pass.
5. **No analytics.** No cohort dashboards, retention, at-risk detection, practice-vs-outcome correlation.
6. **Shallow RBAC.** `user_roles` exists but most checks are single-role; no per-resource scoping (e.g., "this instructor, this course, this cohort"), no granular permissions.
7. **No AI layer** despite obvious high-value use cases.
8. **Observability/scale.** Logging is file-based; no metrics, no structured request tracing, no rate-limit/quotas per account, no background processing.

---

## 3 · Target architecture

### 3.1 Domain model (unify the two enrolment systems)

Promote to first-class, with **explicit state machines** and transition audit:

```
Program ─┬─ Level ─┬─ Course (offering) ─┬─ Cohort ──┬─ Session (scheduled)
         │         │                     │           └─ Attendance
         │         │                     └─ Enrolment(student) ─┬─ Assessment* → Result → Certificate
         │         │                                            ├─ Submission* → Feedback
         │         │                                            └─ ProgressEvent* (audit)
```

- **Cohort** (new): a scheduled run of a course at a level (term, capacity, instructor(s), meeting pattern). Merges legacy `classes` into the v16 model — one source of truth. Migration maps existing `classes`→`cohorts` and `enrollments`→`enrolments`.
- **Enrolment state machine** (enforced in code, not free text): `requested → approved → active → (completed|withdrawn|failed)` with `*_at` + `*_by` columns and an `enrolment_events` audit table.
- **Session** + **Attendance** attach to cohorts (keeps the instructor portal working, now unified).
- **Submission** + **Feedback**: artifact (file URL via the existing store/upload pattern), rubric JSON, instructor comments, status.

### 3.2 API & service layer

- Keep the lean PHP router. Introduce a thin **service layer** (`api/lib/lms/*.php`) so routes stay thin and business rules (transitions, scoring, eligibility) are unit-testable in isolation (extends the existing `test_*.php` pattern — already proven).
- **Idempotency + optimistic concurrency** on state-changing endpoints (idempotency keys already exist for payments; generalize).
- **Versioned, documented contract**: publish an OpenAPI description generated from the router table (`MamRouter::routes()` already introspects).

### 3.3 Automation (the "automated" requirement)

A lightweight, dependency-free **event → job** spine (no external queue needed for current scale):

- **Domain events** written to an `events` table on every meaningful action (enrol, assess, pass, miss-deadline, pay).
- **Job runner**: the existing cron entry (`api/tools/recovery-email.php`) generalizes into `api/tools/run-jobs.php` (single cron, every 5 min) that drains due jobs: reminders, deadline escalations, **auto-progression on pass**, certificate issuance, weekly digests, at-risk nudges.
- **Notification service**: one templated channel abstraction (email now; SMS/WhatsApp/push later) so every automated touch uses one audited path — and fixes the "emails are scattered" problem structurally.

### 3.4 AI optimization layer (the "AI-optimized" requirement)

Server-side, via the **Claude API** (Anthropic) — latest models (Claude Opus 4.x for high-stakes reasoning like placement/grading assist; Sonnet for high-volume, latency-sensitive feedback). All calls go through one audited `api/lib/ai.php` gateway with: prompt templates, schema-constrained tool/JSON output, per-account quotas, redaction of PII, caching, and human-in-the-loop for anything that affects a grade or placement.

High-value, concrete use cases mapped to existing data:

1. **AI-assisted MAM Auditer placement** — turn the entry assessment (`auditer_results.dimensions`) + a short played/sung sample (the new Rhythm/Pitch telemetry) into a recommended programme/level with a written rationale. *Human confirms.*
2. **Adaptive practice coach** — read `practice_logs` + game telemetry (rhythm/pitch scores, weak figures already computed client-side) and generate a personalised weekly practice plan + encouragement, surfaced in the portal.
3. **Grading assist** — draft rubric-aligned feedback for a submission from the audio/notes; the instructor edits & approves (never auto-final). Speeds the gradebook without removing judgment.
4. **Syllabus & module generation** — generate week-by-week `modules` content for a course+level from the programme plan (admin authors today by hand).
5. **Progress narratives** — auto-write the human-readable progress summary on certificates and term reports from `results`/`attendance`/`practice`.
6. **Auditer/Support assistant** — a retrieval-grounded helper over FAQ/policies (already structured content) for applicants.

**Guardrails:** AI never writes a final grade, payment, or certificate without human approval; every AI output is stored with its prompt + model + a `reviewed_by`. This keeps it production-safe and auditable.

### 3.5 Skill graph (the "strategic" requirement)

Model competencies explicitly (`skills`, `skill_assessments`) — e.g., *rhythm reading*, *intonation*, *sight-singing*, *technique* — fed by both instructor assessments and the game telemetry (Rhythm Tap is Trinity-graded; Pitch Game scores intonation/timing/consistency). This gives every student a living **skill profile**, powers adaptive practice, at-risk detection, and Trinity-aligned reporting — and is the data substrate the AI layer reasons over.

---

## 4 · Production-standard hardening

- **RBAC**: a `permissions` matrix (role × action × scope) layered over the existing `user_roles`; per-cohort instructor scoping. Replace ad-hoc single-role checks.
- **Observability**: structured JSON logs with a request id; counters for enrolments/assessments/AI-calls/email-sends; a `/api/diag` extension (already gated) surfacing job-queue health and last-cron time.
- **Reliability**: all state changes idempotent + transactional; the dashboards already degrade gracefully on partial migrations (shipped this session) — extend that discipline everywhere.
- **Testing**: grow the zero-dependency suite (157 today) to cover the enrolment state machine, scheduling, submissions, automation jobs (with a faked clock — the pattern already exists), and AI gateway contract (mocked). Target: every transition + money/grade path tested.
- **Migrations**: keep the versioned dual-driver migrator; add a CI check that `schema-mysql.sql` and the SQLite migrations agree (column-parity test — partially present in `test_migrations.php`).
- **Data protection**: PII minimisation in logs/AI prompts; per-student data export + delete (the privacy policy already promises this).
- **Performance/scale**: add the obvious composite indexes (enrolment by student+status, assessments by enrolment, sessions by cohort+date); paginate every list endpoint; cache the public catalogue.

---

## 5 · Phased roadmap (each phase shippable & tested)

| Phase | Outcome | Key work |
|-------|---------|----------|
| **L1 · Unify & formalize** | One enrolment model with an enforced lifecycle | Introduce `cohorts`; migrate `classes`/`enrollments`; enrolment state machine + `enrolment_events` audit; service layer + tests |
| **L2 · Sessions & submissions** | Real teaching loop | `sessions`/attendance on cohorts; `submissions`+`feedback` with file artifacts + rubrics; instructor & student UIs |
| **L3 · Automation spine** | The platform runs itself | `events`+`jobs` tables; single cron `run-jobs.php`; notification service; auto-progression, reminders, digests, at-risk nudges |
| **L4 · AI optimization** | Measurably better outcomes | `api/lib/ai.php` Claude gateway + guardrails; placement assist, adaptive practice coach, grading assist, syllabus/narrative generation; skill graph |
| **L5 · Analytics & hardening** | Enterprise operations | Cohort/retention/at-risk dashboards; RBAC matrix; observability; pagination/indexes; export/delete; load + contract tests |

Sequencing rationale: **L1 removes the structural debt everything else would otherwise inherit**; automation (L3) and AI (L4) are only as good as the unified, audited data they sit on.

---

## 6 · Immediate, low-risk first steps (fit the current codebase)

1. **Enrolment state machine in `lms.php`** — replace free-text status writes with `mam_lms_transition($id, $to, $by, $reason)` that validates the transition and writes an `enrolment_events` row. Backwards-compatible; unit-test every legal/illegal transition.
2. **`events` table + `run-jobs.php`** — generalize the existing recovery-email cron into a job drainer; move the auto-progression-on-pass behind it (today it's manual).
3. **`api/lib/ai.php` gateway (stub + contract test)** — one audited entry point so AI features land behind a tested, quota'd, PII-safe boundary from day one. Wire the first use case (progress narrative — read-only, low-risk).
4. **Skill profile read-model** — derive a per-student skill snapshot from existing `results` + game telemetry; surface it in the student portal. No new teacher workflow required.

Each is additive, test-covered, and ships independently — matching how this branch has been delivered so far.

---

*This proposal is deliberately incremental: it preserves the working v16 LMS, pays down the one real structural debt (two enrolment systems), and adds automation + AI as audited, human-in-the-loop layers rather than rip-and-replace. Ready to start L1 on approval.*
