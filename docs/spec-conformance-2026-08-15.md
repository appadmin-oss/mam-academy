# Conformance: *Digital Academic, Learning, Attendance & Performance Management System*

Section-by-section verification of the MAM Academy portal-upgrade proposal
(§1–§47, six phases) against the code as it stands on **2026-08-15**.

**How this was checked.** Every row below was verified against the repository —
the library function, table, route or admin view is named so the claim can be
re-checked in a minute. Where behaviour (rather than existence) was the
question, it was driven against a running instance in a real browser, not
asserted from the source. Nothing here is marked done on the strength of a
plausible filename.

**Result.** All 47 sections are implemented (§1, §47 are narrative). The five
gaps found by this audit — §9's readiness threshold, §10's clock-out, §12's
missing statuses, §15's request flow, §16's modes, §23/§24 mastery and §43's
unified record — were built and shipped as part of it (`56ddf1c`, `2cdfdda`),
and the two items first written up here as partial have since been completed:
§11's live Google Meet fetch (`e7f4565`) and the §34/§35 Upper Admin portal
(`13b1400`).

One operational caveat remains, and it is a credential rather than a gap: the
live Meet integration is inert until a Google Workspace service account is
configured on the admin Meet screen. Until then §11's own fallback applies —
the portal clock-in is the official attendance record and participation comes
from CSV import.

---

## Phase 1 — Academic Foundation

| § | Requirement | Status | Evidence |
|---|---|---|---|
| 1 | Executive summary | n/a | Narrative. |
| 2 | DISCOVER → DECLARE → DEVELOP → DEMONSTRATE → MASTER | ✅ | The cycle is the system's spine: `preclass.php` (discover/declare), `lms2.php` module statuses (develop/demonstrate), `mastery.php` (master). |
| 3 | System objectives | ✅ | Each objective maps to a shipped surface; see the rows below. |
| 4 | Five connected portals | ✅ | `portal/{student,instructor,parent}/index.html`, `admin/index.php`, and the Upper Admin surface (`?v=upper`, super-admin only) beside `?v=academic` and `?v=bi`. |
| 5 | Programme → Instrument → Grade → Stream → Module → Competency → Assessment | ✅ | `programs`, `courses`, `levels`, `program_courses`, `modules`, `lessons`, `rubric_criteria`, `assessments`. |
| 6 | Curriculum vs schedule vs mastery | ✅ | `lesson_progress` carries `week_scheduled`, `week_started`, `week_completed` and `mastery_pct` as four separate facts. |
| 7 | 14 module statuses | ✅ | `MAM_MODULE_STATUSES` (`lms2.php`) — all fourteen, validated on write by `mam_module_status_set()`. |
| 20 | Competency assessment engine | ✅ | `rubrics` + `rubric_criteria` (label, max_points, weight); `mam_lms2_rubric_score()` computes the result. Admin `?v=rubrics`. |
| 21 | Instrument-specific rubrics | ✅ | Rubrics are authored per cohort with free-text criteria, so piano/drums/guitar/saxophone grids are data, not code. `mam_lms2_rubric_create/_update/_delete`. |

## Phase 2 — Learning & Teach-Back

| § | Requirement | Status | Evidence |
|---|---|---|---|
| 8 | Pre-class materials + "YOUR NEXT CLASS" panel | ✅ | `lessons.objectives/prep_instructions/resources_json/teachback_required`; `mam_checkin_next_class()` assembles the panel, rendered in the student Check-in tab. Preparation reports §8's three-way state (`completed`/`partial`/`not_started`), not a boolean. |
| 18 | Instructor pre-class view + readiness decision | ✅ | `mam_preclass_instructor_queue()`, `mam_preclass_session_view()`; the three decisions live in `mam_preclass_teachback_review()`. |
| 19 | Teach-back assessment on six dimensions | ✅ | `teachbacks.{understanding,accuracy,vocabulary,explanation,application,confidence}`. |
| 22 | Video/audio/PDF submission + instructor actions | ✅ | `submissions` + `submission_files`; recorded takes via `take-recorder.js` → `/api/media/take` (access-gated). Review actions in `submission_reviews`. |

## Phase 3 — Attendance & Time

| § | Requirement | Status | Evidence |
|---|---|---|---|
| 9 | 30-minute portal opening, 15-minute readiness | ✅ | `mam_checkin_window()` returns both boundaries, the window state and §9's sentence verbatim. Settings `checkin_open_before_min` (30) and `readiness_before_min` (15) — the page reads the server, so the two can never disagree. |
| 10 | Digital clock-in / clock-out | ✅ | `mam_checkin_self()` / `mam_checkin_clock_out()`; `session_attendance` carries `checked_in_at`, `clocked_out_at`, `duration_min`, `minutes_late`. Durations are stored, not recomputed, so editing a session's schedule afterwards cannot rewrite history. |
| 11 | Google Meet reconciliation | ✅ | Live Admin SDK Reports fetch: `mam_google_jwt_assertion()` (RS256, domain-wide delegation via `sub`) → `mam_meeting_google_token()` → `mam_meeting_google_fetch()` → `mam_meeting_google_parse_activities()`. Reports gives a leave time and a duration, never a join time, so the join is derived. Credentials are set on admin `?v=meet`; `mam_meeting_google_sweep()` reconciles finished online sessions from the cron, deduped through `events`. Inert and silent until configured — §11's fallback, not an error. |
| 12 | Seven attendance statuses | ✅ | `MAM_ATTENDANCE_STATUSES` — present, late, absent, excused, left_early, technical_failure, rescheduled. Enforced by a CHECK constraint (schema v81). |
| 16 | Onsite / online / hybrid modes | ✅ | `MAM_ATTENDANCE_MODES`; `session_attendance.mode` and `class_sessions.mode`. All three share one academic record. |
| 39 | Online class workflow | ✅ | Portal opens → prepare → teach-back → clock in → reconcile → clock out → notify → record. Every arrow is a shipped call. |
| 40 | Onsite class workflow | ✅ | Same clock with `mode='onsite'`; QR self-check-in (`attend.php`) also writes it. |
| 41 | Hybrid class workflow | ✅ | Mode confirmation at check-in; the record stays unified. |

## Phase 4 — Accountability

| § | Requirement | Status | Evidence |
|---|---|---|---|
| 13 | Student lateness → $1 charge | ✅ | `mam_accountability_charge()` at `late_charge_student_usd` (default 1), raised automatically on a late check-in, idempotent per (student, session, reason). Gated behind `late_charge_enabled` so switching it on is a deliberate, policy-backed act. |
| 14 | Instructor lateness → $2 | ✅ | Same ledger, `late_charge_instructor_usd` (default 2), raised from `mam_meeting_clock_in()`. |
| 15 | Exception requests + waivers | ✅ | `MAM_ACCT_EXCEPTION_REASONS` (all seven §15 reasons); `mam_accountability_request_exception()` → `mam_accountability_decide_exception()`. Approve waives; reject restores `outstanding` — §15's "Rejected — Charge Maintained". Every decision audited and notified. Student UI in the portal ledger, admin queue at `?v=accountability`. |
| 36 | Academic audit trail | ✅ | `mam_audit()` on every mutation, plus `audit.php`'s per-person timeline. Carry-forwards, score changes, attendance corrections, waivers, reschedules and overrides all write it. |
| 37 | Accountability ledger, six statuses | ✅ | `MAM_ACCT_STATUSES` — pending, outstanding, paid, waived, disputed, reversed. `pending` exists so a student with an open request is not shown a debt the academy has not yet decided is owed. |
| 38 | Automated escalation | ✅ | `mam_analytics2_intervention_list()` derives the level and its escalation targets; `mam_acadmail_absence_sweep()` sends, deduped via `events`. |

## Phase 5 — Mastery & Progression

| § | Requirement | Status | Evidence |
|---|---|---|---|
| 23 | Six-level competency scale | ✅ | `MAM_MASTERY_LEVELS` 0–5 with §23's labels and blurbs; `mam_mastery_level_from_pct()`. |
| 24 | MAM Mastery Score™ | ✅ | `mam_mastery_score()` — eleven weighted components summing to 100. **Attendance and punctuality are returned beside the score and never inside it**, which §24 requires. A component with no evidence is excluded and the weights renormalised (scoring an unassessed beginner as zero would report them as failing); `coverage_pct` discloses how much of the picture the score rests on, and thin records are flagged `provisional`. |
| 25 | Student progress dashboard | ✅ | Student Grades tab (Mastery Score + components + attendance/punctuality) and My Learning (courses, weeks, results, certificates). |
| 29 | Module carry-forward | ✅ | `mam_module_carry_forward()` appends a `module_carryforward` row capturing the prior status, mastery and week, then marks the module `carried_forward` and bumps `attempts`. **Nothing is deleted.** |
| 30 | Three-week module example | ✅ | Falls out of §29 + §6: `week_started`/`week_completed`/`attempts` express "Completed — Extended Learning" without special-casing. |
| 31 | Three intervention levels | ✅ | `mam_analytics2_intervention_list()` — L1 instructor carry-forward, L2 academic intervention, L3 academic review, highest trigger wins, with human-readable reasons. |
| 43 | Final unified student academic record | ✅ | `mam_academic_record()` — all fourteen §43 sections, in §43's order, under §43's names. Read-only and delegating, so it cannot drift from the surfaces it summarises. Admin `?v=record`. |
| 46 | The final MAM model | ✅ | The journey is traversable end to end in the shipped system. |

## Phase 6 — Parent & Management Intelligence

| § | Requirement | Status | Evidence |
|---|---|---|---|
| 17 | Instructor Learning Command Centre | ✅ | Instructor portal overview + `mam_preclass_instructor_queue()`, cohort analytics, at-risk list, pending assessments. |
| 26 | Parent portal | ✅ | `portal/parent/index.html`, `mam_parent_child_detail()` — weekly status, attendance, mastery, instructor feedback, plus the §5.2 KPI strip. |
| 27 | Parent attendance notifications | ✅ | `mam_acadmail_*` + `mam_notify` cover present, late (with the charge), absent, repeated absence, preparation missed, module carried forward, achievement. |
| 28 | Seven automated emails | ✅ | E1 `mam_acadmail_preclass_reminder_sweep`, E2 `_attendance_confirmation`, E3/E4 `_late_notice` (student and instructor), E5 `_absence_sweep`, E6 `_prep_alert_sweep`, E7 `_weekly_report_sweep`. All wired into `api/tools/run-jobs.php`. |
| 32 | Admin portal academy overview | ✅ | Admin Overview + the "⚡ Needs your attention" queue. |
| 33 | Real-time academic health | ✅ | `mam_analytics2_academy_health()` + `_today()`, admin `?v=academic`. |
| 34 | Upper-admin strategic intelligence | ✅ | `api/lib/upper-admin.php`, admin `?v=upper`, `GET /api/admin/upper`. `mam_upper_health()` computes §34's measures for ANY scope and `mam_upper_breakdown()` returns the same metric set one level down, so siblings are comparable. The full §34 chain — Academy → Programme → Instrument → Grade → Instructor → Student — with a breadcrumb back to Academy. Super-admin only (`settings` capability). |
| 35 | Instructor performance analytics | ✅ | `mam_analytics2_instructor_performance()` returns exactly §35's table — students, average mastery, completion, interventions — rendered on the Upper Admin screen alongside the drill-down, and still on `?v=academic`. |
| 42 | Communication hierarchy | ✅ | Routing is per-audience: student notifications, guardian mail via `parent_links`, instructor queues, admin escalation. |
| 44 | Key dashboard metrics | ✅ | Student, instructor, programme and academy tiers all reported across `analytics2.php`, `bi.php` and `mastery.php`. |
| 45 | Implementation phases | ✅ | All six phases delivered; this document is the phase-by-phase account. |
| 47 | Conclusion | n/a | Narrative. |

---

## What is genuinely outstanding

Nothing in the specification. One **configuration** step remains, and it is the
academy's to take, not the code's:

- **Google Workspace credentials for §11.** Paste a service-account key and the
  delegated admin's address into admin → Meet Reconciliation. The service
  account needs domain-wide delegation authorised for
  `https://www.googleapis.com/auth/admin.reports.audit.readonly`. Until then the
  live fetch stays off and CSV import is used, which §11 explicitly sanctions.

A second item is a **policy** decision rather than a technical one:

- **Late charges ship disabled** (`late_charge_enabled = 0`). §13 requires the
  charge to be incorporated into MAM's published student terms first, so turning
  it on should be a deliberate act by the academy.

## Notes on things that could be misread as gaps

- **Late charges are off by default.** `late_charge_enabled` ships at `0`. §13
  itself says monetary charges must be incorporated into MAM's published student
  terms first, so the switch is deliberate — not an unfinished feature.
- **`mam_checkin_clock_out()` can record a zero-minute duration.** That is
  correct: it measures time *present*, from the later of check-in and scheduled
  start. A student who arrives twenty minutes late and leaves immediately was
  present for approximately none of the class, and the record should say so.
- **The Mastery Score of a new student is `null`, not `0`.** §23 level 0 is
  "not assessed — insufficient evidence", which is a different statement from a
  score of zero, and the system makes that distinction everywhere.
