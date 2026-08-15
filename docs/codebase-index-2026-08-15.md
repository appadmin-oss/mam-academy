# MAM Academy — Codebase Index & Audit (2026-08-15)

Snapshot of `claude/repo-audit-index-nr17nr` @ `28d3213`, schema **v79**, suite
**1902 passing / 0 failing**.

This is the navigational map the repo did not have. `docs/codebase-audit-2026-06-22.md`
(security) and `docs/portal-audit-2026-06-22.md` (portal logic) remain the deep dives;
this document indexes *what exists now* and records what the index pass found.

---

## 1. Shape of the system

Server-rendered PHP 8.1+, **no build step**, plus a hand-rolled JSON API. SQLite by
default (auto-migrating at `api/storage/mam.db`), MySQL in production via `DB_DSN`.

| Area | Files | Lines |
|---|---:|---:|
| Root public pages (`*.php`) | 38 | 11,507 |
| `api/lib/` (domain libraries) | 63 | 19,316 |
| `api/routes/` (route handlers) | 44 dirs/files | 2,579 |
| `api/tests/` | 78 test files | 6,202 |
| `assets/js/` | 26 | 13,375 |
| `admin/index.php` | 1 | 8,585 |
| Portals (`portal/**/*.html`) | 7 | 5,691 |
| **All PHP (excl. vendor)** | **358** | **56,094** |

Registered API routes: **120**. Database tables: **105**. Migrations: **79**.
Automation rules: **11**. Roles: `student`, `instructor`, `parent`, `admin`.

### Request path

```
.htaccess ──► /api/*  ──► api/index.php ──► api/lib/router.php ──► api/routes/<handler>.php
           └► clean URL ──► <page>.php (root)   [bootstrap.php loads $mam_libs]
```

Middleware composes per route: `mw_csrf()`, `mw_user()`, `mw_role(...)`,
`mw_admin_cap(...)`, `mw_rate_limit(bucket, limit, window)`.

---

## 2. Library index (`api/lib/`, 63 files)

**Core / infrastructure**
`bootstrap.php` (autoload + `mam_env`/`mam_client_ip`/`mam_after_response`) ·
`db.php` (PDO, SQLite migrations, `MAM_SCHEMA_TARGET`) · `router.php` · `response.php`
(`mam_ok`/`mam_fail`) · `session.php` (cookie `mam_sid`, hashed tokens) · `auth.php`
(roles, admin caps, provisioning) · `csrf.php` · `rate-limit.php` · `log.php` ·
`settings.php` · `validation.php` (also the pricing engine + `mam_ip_in_cidr`) ·
`idempotency.php` · `jobs.php` · `mailer.php` · `money.php` · `fx.php` · `uploads.php`

**Admissions & applications**
`admissions.php` (state machine, summer enrolment) · `auditer.php` (placement) ·
`scholarship.php` · `faculty.php` (invites + instructor applications) ·
`funnel.php` (first-party analytics) · `academic-session.php` (term windows)

**Commerce**
`payments.php` · `paystack.php` · `flutterwave.php` · `invoicing.php` · `store.php` ·
`wallet.php` (instructor earnings ledger, USD cents) · `promo.php` ·
`booking-pricing.php` · `referrals.php`

**Learning (two enrolment models — see §5)**
`lms.php` (cohorts, enrolments, waitlist, curriculum, SSRF-guarded import) ·
`lms2.php` (LMS 2.0 teaching helpers) · `curriculum-seed.php` · `progress.php` ·
`progress-reports.php` · `attendance.php` (QR, both models) · `checkin.php` ·
`preclass.php` (pre-class learning + teach-back) · `meetings.php` (clock-in, Meet
reconciliation) · `reschedule.php` · `makeup.php` · `practice.php` · `repertoire.php` ·
`challenges.php` · `achievements.php` · `leaderboard.php` · `accountability.php` ·
`academic-emails.php` · `analytics.php` · `analytics2.php`

**People & governance**
`parents.php` (guardian portal) · `consent.php` (safeguarding) · `datarights.php`
(export/erasure) · `audit.php` (per-person timeline) · `notifications.php` ·
`support.php` (feedback/surveys/tickets) · `cards.php` (ID cards) · `drive.php`
(Apps Script gateway) · `ai.php` (provider-agnostic gateway) · `studio.php` ·
`automations.php`

---

## 3. Route index (`api/index.php`, 120 routes)

**Public / unauthenticated** — `/health` `/csrf` `/diag` `/setup` `/booking/status`
`/booking/pricing` `/config/public` `/promo/active` `/verify-certificate`
`/referral/capture` `/session/current` `/advisor/recommend` `/courses`
`/course/outline` `/invoice/:ref` `/tuition-invoice/:ref` `/og` `/policy/:ref`
`/certificate/:ref` `/store/*` `/application` `/application/status`
`/application/draft` `/application/resume-link` `/scholarship/*` `/summer/*`
`/tuition/status` `/upload/headshot` `/media/headshot` `/track` `/newsletter*`
`/contact` `/faculty-apply` `/report` `/report-print` `/learner-lookup` `/drive/file`

**Payments** — `/payment/initialize` `/payment/verify` `/payment/webhook/{paystack,flutterwave}`

**Auth** — `/auth/{me,login,logout,change-password,request-reset,perform-reset,sessions,revoke-session,revoke-others}`

**Student** (36 routes, `mw_role('student')`) — dashboard, courses, curriculum,
grades, assessments, submissions, practice-log, checkin, preclass, accountability,
repertoire, challenges, leaderboard, makeup, waitlist, reschedule, session-attendance,
upcoming-sessions, progress, session-reports, my-card, avatar, feedback, surveys,
tickets, ai-narrative, practice-coach, wallet*, store-redeem*, add-course,
update-profile, assignment-complete
<br>*`wallet` / `store-redeem` remain wired but the student UI no longer uses them.*

**Instructor** (~40 routes) — dashboard, student drill-down, gradebook, analytics,
assessments, assess, rubrics, submissions, markers, feedback (+ AI draft), sessions,
session-attendance (+ QR), attendance-qr, mark-attendance, card-scan, clock, module,
preclass, progress-report, lesson-record, curriculum, announce, resource,
drive-upload, assignment{,-update,-delete}, earnings, reschedule

**Parent** — `/parent/children` `/parent/child` `/parent/consent`

**Admin** (~40 routes, each behind an `mw_admin_cap`) — users, RBAC, enrolments,
course-enrolments, curriculum, store, mail, migrate, media, support, fines,
accountability, parent-link, analytics, schedule, waitlist, heal, summer, id-cards,
session-remind, placement/AI-triage, survey-forms, progress-report, meet-reconcile,
wallet-credit, set-student-type

Consistency check: **every** `$f('…')` reference resolves to a file on disk, and
**every** file under `api/routes/` is registered. No orphans, no dangling handlers.

---

## 4. Admin dashboard (`admin/index.php`, 8,585 lines)

37 views: `overview` `applications` `users` `guardians` `enrollments` `courseenroll`
`classes` `modules` `rubrics` `academic` `curriculum`-adjacent `schedule`
`accountability` `fines` `meet` `payments` `storeitems` `storeorders` `referrals`
`summer` `analytics` `funnel` `audit` `datarights` `automations` `media` `messages`
`newsletter` `support` `forms` `logs` `webhooks` `programs` `export` `search`
`settings` `account`.

---

## 5. The two enrolment models

Still the single most important structural fact. Legacy
`enrollments`+`classes`+`attendance` vs `course_enrollments`+`cohorts`+`session_attendance`.

Per CLAUDE.md the **portal-audit S2 divergence is closed by dual-read**: every
student/instructor read path spans both, and `mam_lms_converge_cohort_enrollments()`
is the one sanctioned cross-model write. **This index confirms the read-side claim but
notes the newest features are cohort-only, not dual-read:**

| Feature | Model |
|---|---|
| `leaderboard.php` | cohort-only (`course_enrollments`) |
| `makeup.php` | cohort-only (`session_attendance` → `class_sessions`) |
| `challenges.php`, `repertoire.php`, `achievements.php` | student-keyed (model-agnostic) |

A legacy-only student sees an **empty** leaderboard and **no** make-up suggestions.
That is arguably correct (both are cohort-shaped concepts) but it is a new divergence
in a codebase that spent significant effort closing the old one — worth a deliberate
decision rather than an accident.

---

## 6. Test suite

78 files, 1902 assertions, zero-dependency runner (`api/tests/run.php`). **The harness
does not load `bootstrap.php`** — it stubs `mam_env`, `mam_client_ip`, `mam_ok`/`mam_fail`
(which *throw* in tests) and requires libs individually, so a new lib must be registered
in **both** `bootstrap.php`'s `$mam_libs` **and** `run.php`.

Registration audit: all 63 libs are present in `$mam_libs`. `run.php` loads 56 of them.
The six it omits are `response`, `router`, `log`, `rate-limit` (deliberately stubbed by
the harness) plus **two real domain libs with no coverage at all** — see F3 below.

CI (`.github/workflows/ci.yml`) runs: `php -l` over every non-vendor PHP file on 8.1/8.2/8.3,
the full suite, `node --check` over `assets/js/**/*.{js,cjs}`, `notation.selftest.cjs`,
and `card-export.selftest.cjs`.

---

## 7. Findings

Ordered by how much they cost if left alone. None is a live security hole.

### F1 — `headshot.selftest.cjs` is dead code that fails when run
`assets/js/headshot.selftest.cjs` asserts against `MAM_HEADSHOT._internals`
(`metricsFromGray`, `assessQuality`, `borderStd`). Those functions **no longer exist**:
commit `3a42c11` replaced the photo quality-scan (exposure / contrast / focus / even
lighting / background uniformity) with `basicQualityCheck`, and the self-test was left
behind. Running it today:

```
$ node assets/js/headshot.selftest.cjs
FATAL: MAM_HEADSHOT._internals not exposed        exit=1
```

CI never executes it — it only `node --check`s the file — so this rotted invisibly.
Two separate issues: a **silently dropped feature** (applicants no longer get exposure
/ focus / lighting feedback on their registration photo) and a **test file that lies
about being a test**. Fixing the CI gap first would have surfaced the feature loss.

### F2 — CI does not execute two of the four self-tests, nor the portals' inline JS
`headshot.selftest.cjs` is only syntax-checked (F1). And the portals carry ~4,800 lines
of **inline** `<script>` (student 2,760 / instructor 2,058 / parent 181) that no CI step
parses — `node --check` covers `assets/js` only. The handoff's manual gate extracts and
checks them; CI does not. A syntax error in portal inline JS ships green.

### F3 — Two domain libs have zero test coverage
- **`academic-session.php`** — `mam_session_current()` is called from 6 files and decides
  which academic term every application is stamped with. It is pure date arithmetic
  (`mam_first_monday_on_or_after`, `mam_session_windows`, `mam_session_pick`) — the
  cheapest possible thing to test, and the most embarrassing to get wrong. Untested.
- **`practice.php`** — `mam_practice_stats()`, used in 4 files. Untested.

Both are absent from `run.php`'s require block, so a test *cannot* currently be written
for them without adding the require first.

### F4 — `db/schema-mysql.sql` defines two tables twice, with disagreeing columns
```
program_courses   @561  level_code VARCHAR(32) NULL
program_courses   @1148  level_code VARCHAR(24)
progress_reports  @1333 / @1391
```
`CREATE TABLE IF NOT EXISTS` makes the **second** definition a silent no-op, so the
first wins and a fresh MySQL install gets `VARCHAR(32)`. Nothing breaks today, but the
trap is obvious: editing the later copy changes nothing, with no error. SQLite installs
are unaffected (they migrate from `db.php`). Delete the duplicates.

### F5 — `POST /api/referral/capture` is an unauthenticated, un-deduped write
The endpoint is public and CSRF-less by design (30 per 10 min per IP). But
`mam_referral_capture()` only de-dupes **when an email is supplied**:

```php
if ($email && mam_db_row("SELECT id FROM referrals WHERE referrer_id = ? AND referred_email = ? AND status != 'rewarded'", …)) return null;
```

A caller posting `{code, name}` with no email inserts an unbounded row every time, so a
referrer's dashboard and the admin referral queue can be flooded with plausible-looking
pending referrals. The response (`captured: true|false`) is also a referral-code oracle.
Neither is severe — rewards are staff-approved, so there is no path to money — but a
name-only cap per (referrer, IP, day) would close both cheaply.

### F6 — Referral rewards credit a wallet students cannot see
`mam_referral_reward()` calls `mam_wallet_apply(referrer_id, …)`. Per CLAUDE.md the
wallet was repurposed to **instructor earnings** and the student Wallet tab was removed.
A student referrer therefore accrues a real `wallet_ledger` balance with **no UI
anywhere** to view or spend it. Either surface it or route student rewards elsewhere.

### F7 — `mam_leaderboard_for_student()` does not gate on the caller's own eligibility
The privacy design (opt-in + data consent, first-name + last-initial) is enforced in
`api/routes/student/leaderboard.php`, not in the lib — the lib happily returns opted-in
peers to any student id passed in. Correct today because there is exactly one caller;
a second caller leaks. Move the `mam_leaderboard_eligible($studentId)` check into the
lib. (Also N+1: one `SUM(minutes)` query per peer. Fine at a 12-seat cohort.)

### F8 — The four portal auth pages never bust their CSS cache
```
portal/{student,instructor,parent}/index.html   portal.css?v=20260815a
portal/{choose,login,reset,signup}.html         portal.css?v=20260706c
```
All seven load the *same* stylesheet. The four auth pages have been left on a stale
`?v=`, so a returning visitor keeps a months-old cached copy on exactly the pages that
form the first impression. `config.js?v=20260807a` is at least consistent across all six
references that use it.

### F9 — `CLAUDE.md` materially understates the system it is meant to onboard agents to
The memory file agents are told to read first says *"~35 libs in `api/lib/`, ~95 routes,
~38 test files"*. Reality: **63 libs, 120 routes, 78 test files**. More importantly it
has **zero mentions** of LMS 2.0 (parent portal, instructor clock-in, rubrics, pre-class
+ teach-back, accountability charges, meeting reconciliation) or of the ~25 shipped
Advanced Modernization features (achievements, challenges, consent, repertoire,
referrals, leaderboards, make-up). Its 607 lines instead go into exhaustive depth on ID
cards and enrolment invariants.

That imbalance is not cosmetic: an agent following CLAUDE.md will not know the parent
portal exists. Roughly 40% of the system is invisible in the document that exists to
make it visible. Recommend trimming the ID-card section to its invariants and adding an
LMS 2.0 + Advanced Modernization section — or simply pointing CLAUDE.md at this index.

---

## 8. What is healthy

Worth stating plainly, because the findings list is not the whole picture.

- **Zero `TODO`/`FIXME`/`HACK` markers** in the entire non-vendor tree.
- **No route drift** — 120 registrations, zero missing handlers, zero orphan files.
- **No raw SQL interpolation of user input.** Every `exec`/`query` with a variable is
  DDL, a hardcoded probe list, or a `$db->quote()`d introspection name.
- **CSRF discipline holds.** The six CSRF-less POSTs are each deliberate and documented:
  `/setup` (one-time bootstrap), `/track` (beacon + event allowlist), the two webhooks
  (HMAC), `/newsletter/unsubscribe` (signed token, RFC 8058), and `/referral/capture`
  (see F5).
- **All 63 libs registered** in `bootstrap.php` — gotcha #2 has not been violated.
- Migrations, MySQL mirror, and `MAM_SCHEMA_TARGET` agree at **79**;
  `test_migrations.php` asserts it dynamically.

---

## 9. Suggested order of work

1. **F2 → F1.** Wire the two unexecuted self-tests and the portals' inline JS into CI.
   That surfaces F1 immediately; then decide whether to restore the photo quality-scan
   or retire its orphaned test.
2. **F3.** Add `academic-session.php` + `practice.php` to `run.php` and write the term-window
   tests. Highest coverage-per-line in the repo.
3. **F9.** Reconcile CLAUDE.md against this index.
4. **F4, F8.** Two-minute hygiene fixes.
5. **F5, F6, F7.** Referral/leaderboard hardening — small, self-contained.
