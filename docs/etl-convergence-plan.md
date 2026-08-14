# MAM Academy — Staged ETL Convergence Plan (LMS 2.0)

**Status:** active plan · **Owner:** engineering · **Companion tool:** `api/tools/etl-audit.php`

This is the written plan the audit tool refers to when it prints *"See the
staged ETL plan for phases 1–6."* It governs how the app's **legacy models**
converge onto the **LMS 2.0 canonical models** — safely, additively, and
reversibly — without a big-bang migration.

> **Prime directive:** the app must keep working at every step. Every phase is
> independently shippable, dual-read holds until parity is proven, and no legacy
> data is dropped until a later, explicitly-gated phase.

---

## 1 · The dualities

Five legacy⇄canonical pairs are tracked. These are exactly what
`etl-audit.php` profiles, so the plan and the tool never drift.

| Domain | Legacy model | Canonical model (LMS 2.0) | Convergence key |
|--------|--------------|---------------------------|-----------------|
| **Money** | `payments.amount` (mixed currency) | `payments.amount_usd_cents` (v61) | currency-normalise each succeeded payment |
| **Assignments** | per-student `assignments` rows | `assignment_groups` (v63) | group rows sharing (cohort/class, title, due) |
| **Enrolment** | `enrollments` (class-based) | `course_enrollments.cohort_id` | map legacy `class_id` → `cohort_id`; student → course-enrolment |
| **Scheduling** | `classes` | `cohorts` + `class_sessions.cohort_id` | class → cohort; session → cohort |
| **Attendance** | `attendance` (enrollment_id, day) | `session_attendance` (v22, per session) | key (enrollment_id, session_date) → a `class_session` |

Canonical is always **additive** — new columns/tables alongside the legacy
ones, never in place of them, until Phase 5/6.

---

## 2 · Principles

1. **Read-only until proven.** Phase 0 only profiles. No writes happen until a
   backfill phase is explicitly run.
2. **Dual-read.** While both models exist, the app reads legacy as the source of
   truth and canonical as a shadow, so a wrong backfill can never surface bad
   data to users.
3. **Idempotent, resumable backfills.** Every backfill can be re-run; it only
   fills rows still missing their canonical value (`… WHERE <canonical> IS NULL`).
4. **Gated cutover.** The switch of the *source of truth* from legacy → canonical
   happens only after a parity check passes, and is reversible by flipping one
   read flag back.
5. **Defensive to migration lag.** Deployments behind on migrations are the norm;
   every metric and backfill degrades to "n/a"/no-op rather than aborting.

---

## 3 · Phases

### Phase 0 — Profile (read-only) · ✅ shipped
`php api/tools/etl-audit.php` (add `--json` for machine output). Reports, per
duality: total rows, uncovered rows, coverage %, and a recommended action.
Exit code is always 0 — it is diagnostics, never a pipeline gate. **Run this
before and after every later phase**; it is the go/no-go instrument.

### Phase 1 — Backfill canonical columns (additive writes)
Fill the canonical fields for existing rows. Each is idempotent and safe to
re-run:

- **Money:** `php api/tools/backfill-money.php` → sets `payments.amount_usd_cents`
  for every `status='succeeded'` row where it is `NULL`.
- **Assignments:** `php api/tools/backfill-assignment-groups.php` → creates/links
  `assignment_groups` for `assignments WHERE group_id IS NULL`.
- **Enrolment:** backfill `course_enrollments.cohort_id` from each cohort's
  `legacy_class_id`, and create canonical course-enrolments for legacy students
  with none (`students_unmigrated` in the audit).
- **Scheduling:** ensure every `class` has a `cohort`, and every `class_session`
  a `cohort_id`.
- **Attendance:** project legacy `attendance` (enrollment_id, session_date) onto
  the matching `class_session` as `session_attendance` rows.

**Go criterion:** re-run Phase 0 — every `uncovered`/`no_cohort`/`unmigrated`
count is `0` (or explained and accepted as unmappable). **Rollback:** canonical
columns are additive; truncate/null them to undo — legacy is untouched.

### Phase 2 — Dual-write
Application writes update **both** models on every new payment, assignment,
enrolment, session and attendance mark, so canonical stops drifting behind
legacy from the moment it ships. Legacy remains the read source of truth.
**Rollback:** disable the canonical side of the writer; legacy path is unchanged.

### Phase 3 — Parity verification
A comparison pass (extends `etl-audit.php`) asserts legacy and canonical agree
row-for-row on the money totals, enrolment counts per cohort, and attendance per
session. Discrepancies are listed, not silently reconciled. **Gate:** parity
must be green (or every diff explicitly signed off) before Phase 4.

### Phase 4 — Read cutover (per domain, flagged)
Flip the *source of truth* to canonical, **one duality at a time**, behind a
read flag (e.g. `read_source=canonical`). Dual-write from Phase 2 continues, so
the flip is reversible instantly by setting the flag back to `legacy`. Order:
money → attendance → assignments → enrolment → scheduling (lowest blast radius
first). Re-run Phase 0/3 after each flip.

### Phase 5 — Retire legacy reads & duplicate UI
Once a domain has run on canonical in production without a rollback for a full
term, remove its legacy read paths and the duplicate portal surfaces the
portals-upgrade proposal calls out (student *Classes* vs *My Courses*,
instructor *My Classes* vs *Gradebook*, admin *Enrollments* vs *Course
Registrations*). UI-only; data still present.

### Phase 6 — Drop legacy columns/tables (final, irreversible)
After a full term of Phase 5 with backups verified, drop the legacy
columns/tables. This is the only irreversible step and requires a fresh
backup + a green Phase 0/3 immediately beforehand.

---

## 4 · Go/no-go summary

| From → to | Gate |
|-----------|------|
| 0 → 1 | Audit read cleanly; backfill tools present |
| 1 → 2 | Audit `uncovered = 0` for the domain (or accepted) |
| 2 → 3 | Dual-write live; canonical no longer drifting |
| 3 → 4 | Parity green (or diffs signed off) |
| 4 → 5 | One term on canonical, zero rollbacks |
| 5 → 6 | One term dual-present, backup verified |

**Current state:** Phase 0 shipped; money & assignments have Phase-1 backfill
tools; enrolment/scheduling/attendance backfills are the next build. Run
`php api/tools/etl-audit.php` for the live coverage numbers on any deployment.
