# MAM Academy — Enterprise LMS: Analysis & Architecture Proposal

**Status:** proposal for review (v2 — deepened) · **Author:** engineering · **Date:** 2026-06-14
**Scope:** turn the working LMS into a professional, strategic, flexible, automated, AI-optimized, production-standard learning platform — without throwing away what already works.

> **What changed in v2.** Reflects work shipped on this branch since v1 (enrolment lifecycle state machine + audit, durable mail outbox, 225 passing tests); adds a precise, costed AI-layer design (model tiering, caching, batch, structured outputs, PII/retention guardrails); and adds the things a v1 strategy lacked: **KPIs, risk register, rollout/migration discipline, non-functional requirements (a11y/security/data-protection), and a target data-model appendix.**

---

## 0 · TL;DR

The LMS foundation is genuinely good: a normalized academic model (programmes → levels → courses → enrolments → weighted assessments → results → certificates), an instructor gradebook, flexible self-service course registration with admin approval, level progression, and a portable SQLite↔MySQL migrator. Since v1 of this proposal we have also **paid down the single biggest structural debt's first instalment** — enrolment status is no longer free text; it moves through an enforced state machine with an append-only audit (who/when/why).

It is **not yet enterprise-grade** because it still lacks: unified scheduling/cohorts, a submissions & feedback loop, an automation spine (events → jobs → notifications), analytics, deep RBAC, and any AI layer. This proposal keeps the schema and PHP API spine and layers on **(1)** a clean domain/state model, **(2)** automation, **(3)** a **provider-agnostic** AI optimization layer (free/local by default, premium optional) with explicit cost and PII guardrails, and **(4)** production hardening (RBAC, observability, accessibility, data protection, testing). Delivered in 5 phases, each shippable and test-covered — exactly how this branch has been delivered so far.

---

## 1 · Current state (honest assessment)

**What exists today** (verified in `api/lib/lms.php`, `api/lib/db.php`, `db/schema-mysql.sql`, the portals, `admin/index.php`):

| Area | State |
|------|-------|
| Academic model | ✅ `programs`, `levels`, `courses`, `course_enrollments`, `assessment_types`, `assessments`, `results`, `certificates`, `modules` |
| **Enrolment lifecycle** | ✅ **NEW (v18):** enforced state machine `mam_lms_transition()` (`pending → active → completed\|failed\|withdrawn`, terminals enforced) + append-only `enrolment_events` audit (who/when/why) + `status_changed_at`. Free-text status writes removed. |
| Enrolment | ✅ self-service add, duplicate guard, admin approve + instructor assign (with assignment **memory**); `withdraw`/`finalize` reachable via the admin route |
| Grading | ✅ weighted components → auto-computed result + grade band + pass/fail (`mam_lms_compute_result`) |
| Progression | ✅ pass advances to the same course, next level (`mam_lms_promote_enrollment`) — policy-gated |
| Certificates | ✅ issued on a passed programme enrolment; web-viewable |
| Syllabus | ✅ programme week-plan + authored modules (`course/outline`) |
| Practice | ✅ server-backed practice logs + stats; auto-fed from the Studio |
| **Email** | ✅ **NEW (v17):** durable outbox (`mail_log`) — every transactional send recorded; failures retained + retryable (admin button + `mail-retry.php` cron). Transport verified-correct; deliverability is config (SPF/DKIM/`MAIL_PASSWORD`). |
| Migrations | ✅ idempotent, versioned (`MAM_SCHEMA_TARGET = 18`), dual-driver (SQLite + MySQL) |
| Tests | ✅ **225 passing** (pricing, payments, LMS, lifecycle, progression, certificates, auth, store, mailer, migrations) |

**The remaining structural debt: two parallel enrolment systems.** The legacy `classes`/`enrollments` (attendance, announcements, resources, instructor portal) and the v16 `course_enrollments` (gradebook, results, certificates) are still not unified — a student's "class" and their "course" are different rows. **L1 (below) finishes the unification the v18 lifecycle started.**

---

## 2 · Gaps vs enterprise-grade

1. **Two enrolment systems, not one.** The v18 lifecycle formalised *transitions*; it has not yet *merged* `classes`→cohorts. Until it does, scheduling/attendance and grading live on different spines.
2. **No first-class scheduling.** Sessions, timetables, rescheduling, capacity, attendance live only on legacy `classes`.
3. **No submissions/feedback loop.** Assignments are title+done; no artifact upload (audio/video/PDF), no rubric, no feedback thread.
4. **No automation.** No events/jobs/queue; auto-progression is manual; no reminders, nudges, deadline escalations, digests. (The v17 outbox is the first durable async primitive — generalise it.)
5. **No analytics.** No cohort dashboards, retention, at-risk detection, practice-vs-outcome correlation.
6. **Shallow RBAC.** `user_roles` exists but most checks are single-role; no per-resource scoping, no granular permissions.
7. **No AI layer**, despite high-value, data-ready use cases.
8. **Observability/scale.** File-based logging; no metrics, request tracing, per-account quotas, or background processing.

---

## 3 · Target architecture

### 3.1 Domain model (finish unifying the two enrolment systems)

```
Program ─┬─ Level ─┬─ Course (offering) ─┬─ Cohort ──┬─ Session (scheduled)
         │         │                     │           └─ Attendance
         │         │                     └─ Enrolment(student) ─┬─ Assessment* → Result → Certificate
         │         │                                            ├─ Submission* → Feedback
         │         │                                            └─ enrolment_events* (audit — SHIPPED v18)
```

- **Cohort** (new): a scheduled run of a course at a level (term, capacity, instructor(s), meeting pattern). Merges legacy `classes` into the v16 model — one source of truth. Migration maps `classes`→`cohorts`, `enrollments`→a cohort link on `course_enrollments`.
- **Enrolment state machine** — ✅ **shipped (v18).** Extend the same `mam_lms_transition()` discipline to cohort membership.
- **Session** + **Attendance** attach to cohorts (keeps the instructor portal working, now unified).
- **Submission** + **Feedback**: artifact (file URL via the existing upload pattern), rubric JSON, instructor comments, status — each state change audited the same way enrolments now are.

### 3.2 API & service layer

- Keep the lean PHP router. Continue the **service-layer** pattern (`api/lib/lms.php` and, as it grows, `api/lib/lms/*.php`) so routes stay thin and business rules are unit-testable in isolation (the v18 lifecycle is the template: pure `mam_lms_can_transition()` + audited `mam_lms_transition()`, 35 tests).
- **Idempotency + optimistic concurrency** on state-changing endpoints (idempotency keys already exist for payments; generalize).
- **Versioned, documented contract:** publish an OpenAPI description generated from the router table.

### 3.3 Automation (the "automated" requirement)

A lightweight, dependency-free **event → job** spine — and we already have the first durable async primitive to build on (the v17 mail outbox + its cron).

- **Domain events** to an `events` table on every meaningful action (enrol, transition, assess, pass, miss-deadline, pay). The `enrolment_events` table is the pattern; generalise it.
- **Job runner:** generalise `api/tools/mail-retry.php` + `recovery-email.php` into one `api/tools/run-jobs.php` (single cron, every 5 min) draining due jobs: reminders, deadline escalations, **auto-progression on pass**, certificate issuance, weekly digests, at-risk nudges.
- **Notification service:** one templated channel abstraction (email now via the outbox; SMS/WhatsApp/push later) so every automated touch uses one audited, retryable path.

### 3.4 AI optimization layer (the "AI-optimized" requirement)

All AI runs **server-side, through one audited, provider-agnostic gateway** (`api/lib/ai.php`). The gateway is the only thing that talks to a model: it owns prompt templates, output schemas, provider selection, caching, quotas, PII redaction, logging, and the human-in-the-loop gate. Crucially it is **not tied to any one vendor** — providers are pluggable adapters behind a single contract, and the academy can run **every AI feature for free** on local/open models, then *optionally* upgrade individual use-cases to a paid provider for higher quality. **No feature ever hard-depends on a paid API.**

**Provider adapters — free by default, premium optional.** Each adapter implements one contract — `complete(prompt, schema?, opts) → {json|text, provider, model, usage, ok}` — so callers never know or care which ran:

| Tier | Examples | Cost | Why / when |
|------|----------|------|-----------|
| **Free · self-hosted** *(default)* | Open-weight models via **Ollama** / **llama.cpp** on the server (Llama, Mistral, Qwen, Gemma…) | **$0**, no external calls | The zero-cost default. Best for **PII** — student data never leaves the box. Wants modest server resources (a small GPU helps latency). |
| **Free-tier hosted** | **Groq** (fast, open models), **Google Gemini** free tier, **OpenRouter** free models, **Mistral**, **Hugging Face** | $0 within each provider's free limits | When the host has no GPU — fast, nothing to run, just an API key. |
| **Premium** *(opt-in)* | **Claude** (Anthropic) — Sonnet (workhorse), Opus (high-stakes + zero-data-retention-safe for PII), Haiku (volume); or any other paid provider | paid per-token | Reserve for the calls where quality matters most (placement, grading assist). Configured per use-case; never required. |

**Selection is config-driven, not hard-coded.** An admin setting (`ai_provider`, default a free adapter) sets the global default; high-stakes use-cases may override to a stronger provider. With **no provider configured or reachable, AI features simply stay off** and the manual workflow is unaffected — graceful degradation, never a hard dependency.

**One contract across providers (capability negotiation).** The gateway asks each adapter what it supports (JSON-schema output, tool use, vision). Where a provider offers native **structured output** it's used directly; where it doesn't, the gateway enforces the schema by **validating + repairing the returned JSON server-side**. Either way the schema *is* the contract — placement decisions, rubric scores, and structured feedback come back guaranteed-parseable, whatever the vendor.

**Cost & latency guardrails (designed in, not bolted on):**
- **Free-first, tier-up deliberately.** The default path costs nothing; a paid provider is an explicit, per-use-case choice — so a runaway loop can't run up a surprise bill.
- **Cache stable prefixes** (rubric library, policy/FAQ corpus, a course's syllabus): native prompt caching where the provider offers it, an app-level response cache otherwise.
- **Batch the non-urgent** (overnight narratives, bulk syllabus drafts, digests) through `run-jobs.php` — and on a self-hosted model, off-peak generation is free.
- **Per-account quotas** in the gateway, plus per-call token/length caps tuned per use-case.

**High-value use cases mapped to existing data:**
1. **AI-assisted MAM Auditer placement** — entry assessment (`auditer_results.dimensions`) + a short played/sung sample (Rhythm/Pitch telemetry) → recommended programme/level + written rationale (schema-constrained). *Human confirms.*
2. **Adaptive practice coach** — `practice_logs` + game telemetry → a personalised weekly plan + encouragement, surfaced in the portal.
3. **Grading assist** — draft rubric-aligned feedback for a submission; the instructor edits & approves (never auto-final).
4. **Syllabus & module generation** — week-by-week `modules` content for a course+level from the programme plan.
5. **Progress narratives** — the human-readable summary on certificates and term reports from `results`/`attendance`/`practice`.
6. **Support assistant** — a retrieval-grounded helper over the FAQ/policy corpus (already structured content) for applicants and students.

**Guardrails (non-negotiable):**
- **Human-in-the-loop** for anything affecting a grade, placement, payment, or certificate. AI drafts; a human signs.
- **Every AI output is stored with its prompt + provider + model + `reviewed_by`** — auditable, reproducible, and provider-portable.
- **PII minimisation & data residency:** redact PII before it reaches a prompt where possible; **keep raw student-PII calls on the self-hosted/local adapter (data never leaves the server) or a zero-data-retention-compatible paid model** — never a provider/model whose retention policy doesn't permit it. Honour the NDPA/GDPR posture the privacy policy already promises (export + delete). For an academy serving minors, the free self-hosted adapter is the safest default precisely because nothing leaves the box.
- **Quality/eval gate:** a golden-case eval set (representative placements, gradings, narratives) run before any prompt or model change ships — the same "test before merge" discipline as the PHP suite.
- **Prompt-injection defence:** untrusted content (student submissions, uploaded docs) is delimited and never trusted as instructions; the gateway strips/escapes control sequences.

### 3.5 Skill graph (the "strategic" requirement)

Model competencies explicitly (`skills`, `skill_assessments`) — *rhythm reading, intonation, sight-singing, technique* — fed by both instructor assessments and game telemetry (Rhythm Tap is Trinity-graded; Pitch Game scores intonation/timing/consistency). Every student gets a living **skill profile** that powers adaptive practice, at-risk detection, and Trinity-aligned reporting — and is the substrate the AI layer reasons over.

---

## 4 · Production-standard hardening

- **RBAC:** a `permissions` matrix (role × action × scope) over the existing `user_roles`; per-cohort instructor scoping. Replace ad-hoc single-role checks.
- **Observability:** structured JSON logs with a request id; counters for enrolments/transitions/assessments/AI-calls/email-sends; extend `/api/diag` (already gated) with job-queue health, last-cron time, and outbox backlog.
- **Reliability:** all state changes idempotent + transactional; dashboards already degrade gracefully on partial migrations — extend that discipline everywhere.
- **Accessibility:** target **WCAG 2.2 AA** across the portals and studio (keyboard, focus order, contrast, ARIA, reduced-motion) — codify it as an acceptance criterion, not an afterthought.
- **Data protection (NDPA/GDPR):** PII minimisation in logs/AI prompts; per-student data **export + delete**; explicit consent records; minors' data handling (the academy serves under-18s — parental-consent fields already exist on applications).
- **Security:** keep CSRF/HMAC + session discipline; add per-account AI quotas; OWASP-aligned review of every new write endpoint; secrets only in `.env`.
- **Testing:** grow the zero-dependency suite (225 today) to cover cohorts, scheduling, submissions, automation jobs (with a faked clock), and the AI gateway contract (mocked). Target: every transition + money/grade path tested.
- **Performance/scale:** composite indexes (enrolment by student+status, assessments by enrolment, sessions by cohort+date — the `enrolment_events` index is the pattern); paginate every list endpoint; cache the public catalogue.

---

## 5 · Phased roadmap (each phase shippable & tested)

| Phase | Outcome | Key work | Status |
|-------|---------|----------|--------|
| **L1 · Unify & formalize** | One enrolment model with an enforced lifecycle | Enrolment state machine + `enrolment_events` audit; **then** introduce `cohorts`, migrate `classes`/`enrollments` | 🟢 **lifecycle shipped (v18)**; cohort merge next |
| **L2 · Sessions & submissions** | Real teaching loop | `sessions`/attendance on cohorts; `submissions`+`feedback` with file artifacts + rubrics; instructor & student UIs | ✅ (schema v81, `session_attendance`, rubrics, `submission_reviews`; audit 2026-11) |
| **L3 · Automation spine** | The platform runs itself | `events`+`jobs` tables; single cron `run-jobs.php` (generalising the v17 outbox cron); notification service; auto-progression, reminders, digests, at-risk nudges | 🟡 outbox primitive shipped |
| **L4 · AI optimization** | Measurably better outcomes | `api/lib/ai.php` gateway + guardrails; placement assist, practice coach, grading assist, syllabus/narrative generation; skill graph | ✅ gateway + narrative, feedback draft, practice coach, placement (`ai.php`); ⬜ skill graph (audit 2026-11) |
| **L5 · Analytics & hardening** | Enterprise operations | Cohort/retention/at-risk dashboards; RBAC matrix; observability; a11y AA; pagination/indexes; export/delete; load + contract tests | ⬜ |

**Sequencing rationale:** L1 removes the structural debt everything else would inherit; automation (L3) and AI (L4) are only as good as the unified, audited data they sit on. **Relative effort:** L1-cohorts ≈ M, L2 ≈ L, L3 ≈ M, L4 ≈ L (mostly prompt/eval/gateway work + UI), L5 ≈ M.

---

## 6 · Immediate, low-risk next steps (fit the current codebase)

1. **Cohort merge (finish L1).** Introduce `cohorts`; back-map `classes`→`cohorts` and link `course_enrollments.cohort_id`; route attendance/announcements/resources through cohorts. Dual-read during migration, then cut over. *Test: every legacy class resolves to exactly one cohort; no orphaned enrolments.*
2. **`events` table + `run-jobs.php`.** Generalise the outbox/recovery crons into one job drainer; move auto-progression-on-pass behind it. *Test with a faked clock (pattern already exists).*
3. **`api/lib/ai.php` gateway (stub + contract test).** One audited, provider-agnostic entry point — pluggable adapters, schema-constrained output, quota, PII redaction, `reviewed_by` storage — before any feature. Ship with a **free adapter (Ollama/local) as the default** and a mocked adapter for the contract test; wire the first use case (progress narrative — read-only, low-risk). Premium adapters (Claude, etc.) are opt-in config.
4. **Skill-profile read-model.** Derive a per-student skill snapshot from `results` + game telemetry; surface in the portal. No new teacher workflow.

Each is additive, test-covered, and ships independently.

---

## 7 · Success metrics (how we know it worked)

| Dimension | Metric | Why |
|-----------|--------|-----|
| Retention | % of enrolments reaching `completed` per cohort | The lifecycle now makes this measurable |
| Teaching loop | Median time from final assessment → finalized result | L2/L3 should shrink it |
| At-risk | Recall of the at-risk model vs. actual withdrawals | L5 analytics quality |
| Automation | % of transactional emails delivered first-try; outbox backlog | v17 outbox already instruments this |
| AI quality | Human acceptance rate of AI placement/feedback drafts | Guards against AI drift |
| AI cost | Cost per graded submission / per placement | Caching + tiering + batch should keep it low |
| Satisfaction | Student & instructor NPS | North-star |

---

## 8 · Risk register

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Cohort migration corrupts/loses legacy class data | Med | Dual-read + backfill verification test; reversible migration; snapshot before cutover |
| AI cost runs away | Med | Per-account quotas in the gateway; tier-down by default; caching + Batch; cost dashboard (KPI §7) |
| AI produces a wrong grade/placement | Low (human-gated) | Human-in-the-loop sign-off; eval gate; stored prompt+model+reviewer |
| Student-PII sent to a non-ZDR model | Low | Gateway pins PII calls to ZDR-compatible tiers; redaction; policy test |
| Scope creep stalls shippable increments | Med | Strict phase gates; each step independently tested & deployable (proven on this branch) |
| Email still "doesn't work" after code is correct | High (operational) | Already mitigated: readiness banner + outbox + docs; needs `MAIL_PASSWORD` + SPF/DKIM on the host |

---

## 9 · Rollout & migration discipline

- **Additive-first migrations.** Every schema change is idempotent and dual-driver (the v17/v18 migrations are the template); new columns nullable; no destructive change without a verified backfill.
- **Dual-write / dual-read for the cohort merge.** Write to both spines, read from the new one behind a flag, verify parity with a test, then retire the legacy read path.
- **Feature flags via the existing `settings` table** (e.g. `lms_ai_enabled`, `lms_cohorts_enabled`) so each capability lands dark and is enabled per-environment.
- **Zero-downtime:** migrations run on cold start (SQLite) / via the admin migrate endpoint (MySQL); dashboards already degrade gracefully if a deploy lands mid-migration.

---

## 10 · Target data model (appendix — additive tables)

```
cohorts(id, course_id, level_id, program_id, instructor_id, term, capacity,
        meeting_pattern, starts_on, ends_on, status, created_at)
course_enrollments.cohort_id  → cohorts(id)            -- link, nullable during migration
sessions(id, cohort_id, scheduled_at, duration_min, kind, status, created_at)
attendance(id, session_id, enrollment_id, status, marked_by, created_at)   -- re-homed onto sessions
submissions(id, enrollment_id, assignment_id, artifact_url, status, submitted_at)
feedback(id, submission_id, instructor_id, rubric_json, comments, created_at)
events(id, type, entity, entity_id, payload_json, created_at, processed_at)  -- automation spine
jobs(id, kind, run_after, payload_json, attempts, status, created_at, updated_at)
ai_runs(id, use_case, model, prompt_hash, input_ref, output_json,
        reviewed_by, reviewed_at, created_at)          -- AI audit + reproducibility
skills(id, code, name)
skill_assessments(id, student_id, skill_id, score, source, created_at)      -- source: instructor|game
permissions(role, action, scope)                       -- RBAC matrix
```

Every new table follows the conventions already in `db.php`: integer PK, FK with sensible `ON DELETE`, `created_at` default, an index on the hot query path, and a parallel idempotent entry in `db/schema-mysql.sql`.

---

*This proposal is deliberately incremental: it preserves the working LMS, has already paid down the first instalment of the one real structural debt (enrolment lifecycle, v18), and adds automation + AI as audited, human-in-the-loop, cost-guarded layers rather than a rip-and-replace. L1's cohort merge is the next shippable step.*
