# CLAUDE.md — MAM Academy

Memory file for agents working on this repo. Read this first; it captures the
architecture, conventions, and the non-obvious gotchas that cost time to learn.

> MAM = **Music, Arts, Mystery**. This is **MAM Academy** (academy.mamcentre.org),
> part of MAM Centre. It is NOT "AfroTech" — there are deliberately no AfroTech
> references anywhere; don't introduce any.

## What this is

A server-rendered **PHP site (no build step)** plus a small hand-rolled JSON API.
~25K LOC. PHP **8.1+** (8.4 locally). Runs on shared hosting behind Cloudflare.

- Public pages: `*.php` at the repo root (index, apply, store, book, tuition,
  pathways, terms, etc.). Clean URLs via root `.htaccess` (`.php`/`.html` → 301 → clean).
- `admin/index.php` — large (~200KB) server-rendered + JS admin dashboard. Edit carefully.
- `portal/{student,instructor}/index.html` — SPA-style portals; `portal/{login,signup,reset}.html`.
- `assets/js/` — `config.js` (the `MAM_API` client + `MAM_CONFIG`), `portal.js`
  (shared portal kit `P.*`), `main.js`, `tools.js` (studio), etc.
- `api/` — front controller `api/index.php` → `lib/router.php`; ~35 libs in
  `api/lib/`, ~95 routes in `api/routes/`, ~38 test files in `api/tests/`.
- `db/schema-mysql.sql` — canonical MySQL schema; mirrored by SQLite migrations in `api/lib/db.php`.

DB: **SQLite by default** (auto-migrates at `api/storage/mam.db`), MySQL in prod
(set `DB_DSN`; apply `db/schema-mysql.sql` once).

## Run & test

```bash
# Full test suite (zero-dependency runner; CI runs this). MUST stay green.
php api/tests/run.php            # → "✅ PASS: N passed, 0 failed"

# Lint everything
find . -name '*.php' -not -path './api/vendor/*' -print0 | xargs -0 -n1 php -l
for f in $(find assets/js -name '*.js' -o -name '*.cjs'); do node --check "$f"; done
```

### Running the app locally (to click through / screenshot the portals)

PHP's built-in server doesn't read `.htaccess`, so use a tiny router that maps
`/api/*` → front controller and resolves clean URLs. Recipe that works here:

```php
// router.php  (run: php -S 127.0.0.1:8099 router.php)
<?php $DOC='/abs/path/to/repo'; $u=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('#^/api/(.*)$#',$u,$m)){$_GET['route']=$m[1];chdir($DOC.'/api');require $DOC.'/api/index.php';return true;}
$p=realpath($DOC.$u); if($u!=='/'&&$p&&strpos($p,$DOC)===0&&is_file($p))return false;
if($p&&is_dir($p)&&is_file($p.'/index.html')){readfile($p.'/index.html');return true;}
foreach(['.html','.php'] as $e){$c=$DOC.rtrim($u,'/').$e; if(is_file($c)){if($e==='.php'){chdir(dirname($c));require $c;}else readfile($c);return true;}}
if($u==='/'){chdir($DOC);require $DOC.'/index.php';return true;} http_response_code(404);echo"404 $u";return true;
```

- The API base is **`/api`** (root-absolute), set in `config.js`.
- Seed data via a CLI script that `require`s `api/lib/bootstrap.php` and uses the
  lib functions (`mam_create_admin`, `mam_db_insert`, `mam_wallet_post`, …).
- Headless browser: Playwright Chromium is installed at
  `/opt/node22/lib/node_modules/playwright`. Log in via `#email`/`#password`/`#loginBtn`;
  the HttpOnly session cookie carries across the context. To trigger an SPA tab,
  **click the `a[data-tab="…"]` nav link** — navigating by URL hash alone does NOT
  re-run the SPA (same-document, no reload).
- Console "errors" for `ERR_CERT_AUTHORITY_INVALID` are just CDN assets (Font
  Awesome, fonts, currency API) blocked offline — not app bugs. Icons render blank offline.

## Conventions (match these)

- **Server is authoritative.** Prices, totals, status, currency are computed
  server-side; the client cart/payload is treated as intent only. Never trust a
  client-supplied amount/total/status.
- **Escaping:** every dynamic value in `innerHTML` goes through `escapeHtml`/`P.escapeHtml`
  (JS) or `htmlspecialchars` (PHP). URLs into `href`/`location` get a scheme guard.
- **Money:** USD is the accounting unit. FX (USD→NGN/GBP/EUR) lives in `api/lib/fx.php`,
  cached, bounds-checked (`MAM_FX_MIN/MAX`); the cron warms the cache so display == charge.
  The wallet ledger is in **USD cents** (integers).
- **CSRF:** every state-changing route uses `mw_csrf()`; tokens are HMAC, bound to
  the session (anonymous tokens for public forms). The `MAM_API` client auto-attaches
  `X-CSRF-Token` and retries once on `csrf_invalid`.
- **Audit:** mutations call `mam_audit(action, entity, id, meta)`.
- **Migrations:** add `N => "SQL"` to the `$migrations` array in `api/lib/db.php`,
  bump `MAM_SCHEMA_TARGET`, AND add the equivalent to `db/schema-mysql.sql` (with its
  `INSERT INTO schema_version`). MySQL `TEXT` can't carry a `DEFAULT` — add nullable
  columns without one and treat NULL as the default in code (see `student_type`).
- **Routes** are registered in `api/index.php` with method + handler + middleware
  (`mw_csrf`, `mw_role('student'|'instructor')`, `mw_admin_cap('users'|'rbac'|…)`,
  `mw_rate_limit(bucket,limit,window)`).

## Critical gotchas (these have bitten)

1. **The test harness does NOT load `bootstrap.php`.** `api/tests/run.php` stubs
   `mam_env`, `mam_client_ip`, `mam_fail`/`mam_ok` (these THROW in tests) and
   `require`s lib files individually. So:
   - A new lib must be added to **both** `bootstrap.php`'s `$mam_libs` autoload list
     **and** `api/tests/run.php`'s require block.
   - A shared helper used by a lib must live in a file the harness loads (e.g.
     `mam_ip_in_cidr` lives in `validation.php`, not `bootstrap.php`, so both prod
     and tests have it).
   - `test_migrations.php` asserts the version equals `MAM_SCHEMA_TARGET` — keep it
     dynamic, don't hardcode the number.
2. **Two parallel enrollment models exist.** Legacy `enrollments`+`classes`+`attendance`
   (what the student dashboard "Classes"/attendance + instructor roster read) vs newer
   `course_enrollments`+cohorts (Courses/Gradebook/Sessions). They are NOT reconciled —
   a student/instructor can appear in one and not the other. Converging them is a known
   open item (portal-audit S2). Be deliberate about which one you touch. **UI convergence
   (P2) has begun** (dual-read, no data migration): the student **My Learning** tab is now
   the unified learning hub (Courses & results + Weeks + certificates + classes + attendance
   via shared `courseTableHtml`/`certPanelHtml`/`wireCourseActions`), and the instructor
   **overview** lazily surfaces a "Cohorts & sessions" panel beside the legacy class views.
   The legacy tabs still read both models as-is and remain until parity is verified.
   **More convergence since:** the student **Overview** now dual-reads too (an "Active
   learning" KPI + panel counting active classes AND active course enrolments, plus a
   session-attendance fold-in) so a course-only student isn't shown an empty overview.
   The instructor "Cohorts & sessions" panel folds the legacy class roster count of a
   backfilled cohort (`mam_lms_instructor_cohorts` returns `legacy_count`) so it never
   misreads as 0. And there is now an **opt-in write-path reconciliation** —
   `mam_lms_converge_cohort_enrollments($cohortId)` (admin `cohort_converge` on
   `?v=courseenroll`, settings cap) enrols a backfilled cohort's legacy roster into
   `course_enrollments` so those students become gradable/markable; it's additive,
   idempotent, audited, and **requires the cohort to map to a real course** (course_id is
   NOT NULL) — a purely-legacy cohort must be course-mapped first. It's the one sanctioned
   bridge that writes across the models; everything else stays dual-read.
   **Since then, two more read paths converged:** the instructor **student drill-down**
   (`GET /api/instructor/student`) now dual-reads — it returns the courses/cohorts the
   caller teaches the student in (not just legacy classes), grants access when EITHER
   model is shared (was legacy-only → 403 for a course-only student), and folds shared-cohort
   `session_attendance` into the summary. And **assignments** now target cohorts + course-only
   students: `POST /api/instructor/assignment` accepts `cohort_id` (fans out to the cohort
   roster) and its single-student ownership check accepts a shared `course_enrollment`
   (assignments are per-student — `assignments.student_id`, `class_id` optional — so no
   schema change was needed). **Announcements converge too (schema v39):**
   `announcements.cohort_id` (nullable) lets an instructor post to a cohort;
   `mam_student_announcements()` (`lms.php`) unions class-enrolled AND cohort-enrolled
   announcements so a course-only student finally sees updates. **Resources converge too
   (schema v40):** `class_resources` was rebuilt to make `class_id` nullable and add
   `cohort_id` — a SQLite table rebuild that's safe with `PRAGMA foreign_keys = ON` only
   because `class_resources` is a leaf table (no inbound FKs); `mam_student_resources()`
   unions both models. **QR self-check-in converged too:** a session-scoped twin
   (`mam_attendance_session_token`/`_verify_session_token`/`_session_self_checkin` in
   `attendance.php`; `GET /api/instructor/session-attendance-qr` + `POST /api/attendance/session-checkin`;
   `attend.php` reads `?stoken=`) writes `session_attendance`, enrolment-gated on
   `course_enrollments`, idempotent, never downgrades. Its token uses a distinct `sess:`
   grammar so it can't cross-verify with the legacy class token. **With that, the
   portal-audit S2 divergence is fully closed** — every student/instructor read+write path
   now spans both enrollment models (dual-read everywhere; `cohort_converge` the one
   sanctioned cross-model write).
   ⚠️ **SQLite compound-query gotcha** (bit twice building the announcement union): a
   `UNION`'s `ORDER BY` must reference **explicitly-aliased** result columns (`an.id AS id`,
   `an.created_at AS created_at`) — a bare `an.created_at`/`id` raises "ORDER BY term does
   not match any column" — and needs an `id` tiebreak to be deterministic when timestamps tie.
3. **Enrolment invariants (audit 2026-08-03 — `test_enrolment_fixes.php`).** These were
   all real bugs; don't regress them.
   - **`mam_lms_add_course()` is capacity-aware.** It resolves the (course, level,
     programme) cohort BEFORE writing and, when that cohort is full, returns
     `['id'=>null,'status'=>'waitlisted','position'=>N,'cohort_id'=>N]` instead of
     enrolling. Callers MUST read `id` defensively (`(int)($r['id'] ?? 0)`) — it is null
     on the waitlisted branch. Self-service enrol previously ignored capacity entirely,
     which made the v45 waitlist unreachable for students and let one auto-created
     cohort grow without bound.
   - **The duplicate guard is level-aware in BOTH directions.** A level-less add is
     blocked by an existing levelled enrolment and vice versa; distinct levels of the
     same course stay legal (that's progression). The admin "assign course" and
     class-enrol paths pass no level, and used to create a second parallel enrolment
     in a course the student was already taking.
   - **Never hand-roll the "already_enrolled → find the row" recovery.** Use
     `mam_lms_find_enrollment($studentId, $courseCode, $levelCode)`. The old inline
     `… AND c.code = ? … LIMIT 1` was unordered AND level-blind, so settling a payment
     for one level could activate the enrolment for another.
   - **Pass `null`, never `0`, as an actor id.** `enrolment_events.actor_id` carries a
     `users(id)` FK under `PRAGMA foreign_keys = ON`, so `0` raises a constraint
     violation that `mam_lms_log_event()` catches — silently destroying the audit row.
     `mam_lms_actor_id()` normalises this; the catch now logs instead of swallowing.
   - **`mam_lms_waitlist_promote()` returns null ONLY for an empty waitlist.** Every
     other outcome throws (`cohort_full`, `no_course_mapping`, `enrolled_elsewhere`) and
     leaves the student queued — it used to mark entries 'promoted' while enrolling
     nobody, or look like an empty list.
   - **Capacity counts DISTINCT students.** `mam_lms_cohort_fill_count()` (seats:
     active+pending) and `mam_lms_cohort_roster()` (teachable: active+completed) both
     count distinct students, so they can differ only by status, never by row
     multiplicity. The status sets differ deliberately — see the comments.
   - **A freed seat drains the queue.** Leaving a seat-occupying status inside
     `mam_lms_transition()` calls `mam_lms_drain_waitlist_for_enrollment()`, gated by the
     `waitlist_autopromote` automation. It is best-effort by design (promote signals by
     throwing) — a withdrawal must never fail because the queue couldn't be drained.
     Because capacity is now enforced on enrol, without this a withdrawal left an empty
     seat with people queued behind it.
   - **Promotion notifies.** `mam_lms_waitlist_promote()` stamps `class_waitlist.notified_at`
     and raises a `waitlist.promoted` notification + email via
     `mam_lms_notify_waitlist_promoted()`. That column shipped in v45 and nothing ever
     wrote it, so promotions were silent.
   - **`mam_summer_accepting()` is the ONE summer-window predicate.** `/summer`, the
     `/apply` chooser, `config-public` and the `POST /api/summer/apply` guard all call it.
     They each used to decide independently and disagreed — the pages demanded BOTH window
     bounds while the endpoint honoured either, so the form could be hidden while the
     endpoint accepted, and an entirely unconfigured programme accepted applications for
     an intake advertised nowhere. `mam_summer_is_configured()` is the "is it running at
     all" half. Don't reintroduce an inline date comparison.
   - **`mam_lms_create_cohort()` distinguishes an explicit null capacity from an absent
     one** (`array_key_exists`, not `isset`): null = uncapped, absent = the default 12. A
     literal 0 would read as permanently full, which is why `summer_capacity`'s documented
     "0 means unlimited" maps to null.
   - **Automation counts in tests must derive from the registry**, never a literal —
     adding `waitlist_autopromote` broke three hardcoded `=== 9` assertions in
     `test_automations.php`.
   - **Summer 'enrolled' really enrols.** `mam_summer_enrol()` (`admissions.php`)
     provisions a **roster identity** (`users.status='roster'`, which both `mam_login()`
     and `mam_session_user()` reject, and `request-reset` can't target — summer children
     get roster presence, NOT portal access), resolves the dedicated
     `SUMMER-<year>` cohort via `mam_summer_cohort()` (keyed on `(course_id, term)` so it
     never collides with `mam_lms_ensure_cohort()`'s tuple), and enrols them. The cohort AND
     the enrolment both take the remembered instructor (`mam_lms_default_instructor`) —
     `mam_lms_instructor_cohorts()` keys off `cohort.instructor_id` OR
     `enrolment.instructor_id`, so setting neither left summer students on a roster no
     instructor could open. Idempotent.
     Summer status is now a real state machine (`mam_summer_can_transition()`), and the
     application window honours each bound independently (`mam_summer_window_open()`) —
     it used to be enforced only when BOTH settings were set.
4. **Root page scripts that hand-roll lib loading (`card.php`, `join.php`).** These
   pages skip `bootstrap.php` and `require_once` a handful of libs directly. Two traps,
   both of which shipped as hard 500s on every request and went unnoticed for months
   because the suite loaded no root page at all — now guarded by
   `api/tests/test_root_pages.php` (static, token-based; it never executes a page,
   since that would emit headers and write the real `api/storage` dev DB):
   - **Never stub a function a required lib already defines.** PHP declares top-level
     functions at COMPILE time, *before* any `require_once` in the same file executes —
     so `function mam_log(...) {}` beside `require_once .../lib/log.php` is an
     unconditional `Cannot redeclare` fatal, not a harmless shadow or fallback. Both
     pages stubbed `mam_log`/`mam_audit` (and card.php `mam_automation_touch`). Only
     `mam_env`, `mam_client_ip` and `mam_after_response` genuinely need defining —
     they live in `bootstrap.php`, which these pages deliberately don't load.
   - **Use `mam_session_user()` for auth; don't read the cookie by hand.** Both pages
     called a `mam_session_get()` that **does not exist anywhere in the repo**, and read
     `$_COOKIE['mam_session']` when the real cookie is **`mam_sid`** (`MAM_COOKIE`, set
     in `session.php`) — so the auth check could never have resolved a session even
     without the fatal. `mam_session_user()` handles the cookie name, token-format
     check, hashing, expiry and the `users.status='active'` gate.
   Also: the emailed card PNG (`mam_card_generate_png`) draws with GD's built-in bitmap
   font, which has **no glyph at 0xB7** — keep separators ASCII. `'\xB7'` in single
   quotes isn't even an escape (it printed literally, and skewed the `strlen()` centring);
   double-quoting it just draws a stray mark instead. The `/card/{serial}` HTML page pulls
   **Barlow Condensed from Google Fonts**, so offline renders fall back to a font whose
   metrics collide at `line-height: 0.95` — name overlap in a local screenshot is an
   artifact, not a bug.
5. **`mam_client_ip()` (bootstrap.php)** trusts `CF-Connecting-IP`/`X-Forwarded-For`
   ONLY from a trusted proxy (private + Cloudflare ranges by default; `TRUSTED_PROXY_CIDRS`
   to override). It's the rate-limit + audit IP key. Don't revert to blindly trusting headers.
6. **SSRF guard** for the curriculum remote-import is in `api/lib/lms.php`
   (`mam_lms_public_ips_for` / `mam_lms_fetch_url`): per-hop re-validation, `CURLOPT_RESOLVE`
   pinning, denies private/CGNAT/IETF-special ranges, ports 80/443 only. Keep it strict.
7. **`auditer_results` capture flow.** `mam_auditer_record()` (`auditer.php`) is the
   writer — admins capture a placement from the application detail card (`?v=applications&app=N`,
   `record_placement` action). When the `auditer_placement` automation is on it also
   ensures the student account + enrols them at the recommended level. The student Auditer
   tab still falls back to live `mam_auditer_status()` for validity/booking state.
8. **`admin/index.php` is ~200KB.** Grep for the section; make surgical edits; don't reflow.

## Security posture (don't weaken)

bcrypt + rehash-on-login; hashed session tokens; HttpOnly/SameSite=Lax cookies
(`COOKIE_SECURE`); rate-limited auth; password reset/change revoke other sessions;
CSRF on all mutations + session-bound; webhooks HMAC-verified + idempotent; payments
re-check amount≥owed & currency; store stock decrement race-guarded; SSRF guard above.
Diagnostics (`api/raw-debug.php`, `/api/diag`) are `DEBUG_TOKEN`-gated. No secrets are
committed (`.env`, `storage/`, logs are gitignored). The job runner (`api/tools/run-jobs.php`)
holds an exclusive `flock`. See `docs/codebase-audit-2026-06-22.md` for the full picture.

## Feature state — the "portal brief" (14 items)

Decisions captured so far: **Wallet = INSTRUCTOR EARNINGS** (admin-recorded, read-only
statement; no self top-up/withdrawal). **Students no longer have a wallet** — their
money surface is the Payments tab (tuition/outstanding/receipts). **Scoring tool before
the notation showcase.**

- ✅ **#4 Scholarship tier** — `users.student_type` (NULL/''=paying, 'scholarship');
  `mam_user_is_scholarship`; invoice-exempt (`mam_invoice_is_scholarship`); audited
  `POST /api/admin/set-student-type`; portal badge; in `list-users`.
- ✅ **Scholarship flow (MAM Scholars Initiative, §2c)** — `scholarship.php` (opt-in
  page: transparent soft-requirements + 5-section essay assessment + commitment
  checkbox) → `POST /api/scholarship/apply` → `scholarship_applications` (schema **v34**,
  `api/lib/scholarship.php`, own status track, advisory readiness reuses
  `mam_application_readiness`). `GET /api/scholarship/status` (email-gated). Linked from
  footer + a post-apply opt-in CTA on `thank-you.php`. Awarding still routes through the
  existing admin `set-student-type` (committee decides; capture-only here). Auditer
  scholarship-signal writer (`auditer_results`) is the next piece, not yet built.
- ✅ **Student photos in the portal** — the application headshot auto-populates `users.avatar_url`
  at provisioning (`mam_ensure_student_user`, + backfill), so a student's photo shows as their
  portal avatar (was: initials only). Editable via `POST /api/student/avatar` + a "Your photo"
  panel in the portal Profile tab (headshot uploader).
- ✅ **Admin Media & Documents** — admin `?v=media` (new `media` manager cap): manage
  `class_resources` (add/delete) + portal photos (clear); `GET /api/admin/media` aggregates them.
- ✅ **#6 T&C** — clause 5 "Practice & the MAM metronome" in `terms.php`.
- ✅ **#12 Wallet → #7 Instructor earnings** — `wallet_ledger` (v32, user-keyed),
  `api/lib/wallet.php`. Now tracks INSTRUCTOR EARNINGS: admin records via the users-table
  "Earnings" form (`wallet_credit` action, reasons `earnings`/`earnings_adjust`); instructor
  sees a read-only statement at `GET /api/instructor/earnings` (Earnings tab). The student
  Wallet tab + "Pay with credit" were removed; `student/wallet` + `store-redeem` remain in
  code but are unused by the UI (students have no wallet).
- ✅ **#2/#3 verified** — no brand drift; admissions state machine
  (`mam_admissions_transition`) already enforced + audited; status routes are read-only.
- ✅ **#1 Learning Readiness** (was "Eligibility"; reframed per flow-guide §6 as
  *readiness*, not a gate) — **friction-free & advisory-only**. `mam_application_readiness()`
  (`validation.php`) derives `ready | foundation | review` **from data the wizard already
  collects** (age from DOB + experience) — **no new form questions**. Persisted as
  `applications.readiness_signal` (schema **v33**) + in the create audit; it routes/flags
  for admissions but **never rejects or blocks submit**. Warm, tone-aware note on Step 3
  (`renderReadiness` in `apply.js`) mirrors it. ⚠️ An earlier attempt that **added** three
  Step-3 questions (instrument access / weekend availability / values statement) was
  **reverted** — do NOT reintroduce applicant-facing eligibility questions.
- 🧭 **Apply "Find Your Path" advisor** (not a brief item, but shipped) —
  `assets/js/apply-discovery.js` + `#mamAdvisor` in `apply.php`. A *non-intrusive*
  invitation (never a takeover) that opens a warm advisor; it **uses AI** (server
  `mam_ai_advisor_recommend` in `ai.php` via public `GET /api/advisor/recommend`, off
  unless `ai_provider` set) to pick the programme + write the rationale, but is
  presented so it **never looks like AI** (deterministic instant preview + fallback,
  MAM mentor voice, no AI labels/spinner). Hands off via the wizard's allow-listed
  `?pathway/&mode/&level/&discipline/&expression` prefill.
- ✅ **#13 Scoring tool** — the studio already has server-tested pitch/rhythm scoring +
  leaderboard (`mam_studio_*`, `test_studio_leaderboard.php`). Hardened `studio/score.php`
  to reject malformed client input (no silent `(int)` coercion). The score stays a
  client-attested practice result (mic-driven; server can't recompute), range/tool/role
  validated + rate-limited.
- ✅ **Studio S2** — scored-tool cards show personal best + Trinity band + plays + a
  device-local practice streak (tracked in `logScore`); free tools show a dismissible
  "unlock the full Studio" apply bridge for signed-out visitors (`studio-gate.js`).
- ✅ **#11 How-it-works** — `how-it-works.php` (7-step journey; footer + sitemap + HowTo
  JSON-LD; clean URL via .htaccess).
- ✅ **Faculty pipeline** — teaching interest (contact topic) queues a `faculty_invites`
  REQUEST (v37); admin approves (?v=users#faculty) → single-use, 7-day HMAC link to
  `/instructor-apply` (details + headshot, v36 `instructor_applications`); submit consumes
  the invite (never reusable) and directs the candidate to book their interview (/book).
  Admin user-create emails credentials (`mam_email_account_created`).
- ✅ **Versioning** — API: `MAM_API_VERSION` + `X-MAM-API-Version` header; `/api/v1/…`
  aliases the current major, unknown majors 404. UI: `MAM_CONFIG.uiVersion`; both shown
  in the admin sidebar (UI · API · schema).
- ✅ **Admin UX layer** — nav badge counts + '⚡ Needs your attention' queue on Overview
  (pending applications/registrations/unassigned/proposals/messages/faculty), toast
  feedback for ?ok/?err (string values carry the message; URL cleaned after), '/' or
  Ctrl+K focuses global search.
- ✅ **Admin redesign (MAM Redesign design system)** — `admin/index.php` adopts the
  prototype's tokens/type (warm `--bg:#1B140D` … `--gold:#E8961F`; Bebas/Cormorant/Inter;
  radii 14/20). Grouped left nav (`#adminNav`, `aria-current`, gold-outline active pill,
  live `nav-count` badges); top-bar search chip → **⌘K command palette** (focus-return,
  Tab-trap); mobile off-canvas sidebar (hamburger + `.nav-scrim`); **stacked-card tables**
  on Applications/Users/Payments/Store Orders (`table.stack` + `data-label`, `thead`
  hidden on mobile); a **keyboard-shortcuts help overlay** (press `?`, `.kbd-scrim`); skip
  link + `:focus-visible`. Detail **drawers** (`.mam-drawer` + generic `data-param`
  dismiss) for Applications (`?app=`), Class (`?class=`), Module (`?module=`), Proposal
  (`?proposal=`). Bulk status actions on Applications (`app_bulk_status`). Reusable
  primitives: `.auto-switch` toggle, `.mam-drawer`, palette, `table.stack`.
- ✅ **Lifecycle automations** — `api/lib/automations.php`: a registry of **8 rules**
  (`payment_provision`, `enrol_welcome`, `payment_retry`, `auditer_placement`,
  `session_reminder`, `week_drip`, `store_receipt`, `ktams_birthday`), each with a
  persisted on/off flag (`automations` table, **schema v38**) honoured *at its trigger
  point* — `mam_automation_on($key)` gates the behaviour in `payments.php` / `lms.php` /
  `auditer.php`, and `mam_automation_touch()` records run telemetry (last-run + count).
  Admin screen `?v=automations` (grouped cards, per-rule toggle + telemetry, "Run
  scheduled sweeps now"). The three scheduled sweeps
  (`mam_automation_session_reminder_sweep` / `_ktams_birthday_sweep` / `_week_drip_sweep`)
  are events-deduped, never throw, and are wired into `api/tools/run-jobs.php` (the cron).
  A new lib → add to BOTH `bootstrap.php`'s `$mam_libs` AND `api/tests/run.php` (gotcha #1).
  Covered by `test_automations.php`.
- ✅ **Curriculum clone across levels** — `mam_lms_clone_syllabus(courseCode, fromLevelCode,
  toLevelCode)` (`lms.php`): copies a course's authored weeks between levels, idempotent
  (upsert by week, never duplicates), throws on same-level / unknown-course. Admin action
  `curriculum_clone`. Covered by `test_curriculum_clone.php` (which cleans up its fixture
  course + modules so the shared test DB keeps its seeded catalogue count).
- ✅ **Auditer Applicant Checklist** — `auditer.php#checklist`: the 19 preparation items
  (documents/identity, performance, professional materials, on-the-day) as a tickable,
  localStorage-persisted, printable checklist. Deep-linked from thank-you, status, book,
  how-it-works, and the student portal Auditer tab.
- ✅ **#9/#10 QR attendance** — instructor shows a per-session QR (signed, day-scoped
  token via `mam_attendance_token`, `GET /api/instructor/attendance-qr`), enrolled
  students scan → `attend.php` → `POST /api/attendance/checkin` (`api/lib/attendance.php`,
  enrolment-gated, idempotent, never downgrades an instructor mark). Sits over the LEGACY
  attendance model (enrollments+classes+attendance), not cohorts. QR rendered by a
  self-hosted MIT lib at `assets/js/vendor/qrcode.min.js` (CDN is CSP-blocked). The
  **metronome** half of #9 already existed as a studio tool.
- ✅ **#7 Instructor earnings** — shipped as the repurposed wallet (see #12): admin-recorded
  earnings statement in the instructor portal. Payout mechanics stay off-platform.
- ✅ **#5 Google Drive file upload** — via a Google **Apps Script web app**, NO OAuth /
  Drive API credentials. `api/lib/drive.php` signs each request (HMAC over ts+action+body)
  to the admin-configured web-app URL: `mam_drive_upload()` writes bytes → Drive (link-shared)
  → returns view/download URLs; `mam_drive_fetch()` pulls bytes back for an access-gated
  proxy. Instructor uploads (`POST /api/instructor/drive-upload`) store the file as a
  **dual-read learning resource** (course-only students get it too); the file stays
  fetchable via the stored view URL OR the gated proxy `GET /api/drive/file?rid=N`
  (visibility-checked per role). Off until `drive_webapp_url`+`drive_shared_secret` set
  (`DRIVE_TRANSPORT=mock` = in-process store for dev/tests). Deploy guide:
  `docs/drive-apps-script.md`. New lib → in BOTH `bootstrap.php` `$mam_libs` AND
  `run.php` (gotcha #1). Covered by `test_drive.php` (signing, guards, byte-for-byte
  upload→fetch round-trip).
- ✅ **#14 Notation showcase** — `notation.php` + `assets/js/notation.js`. **M1:** renders one
  melody in THREE systems (Classical 5-line treble staff, Contemporary piano-roll, African
  **MAM 6-line** tone grid) from a note model, with Web Audio playback + per-note highlight,
  tempo, system switcher and example melodies. **M2 (live audio→notation):** a self-contained
  autocorrelation pitch detector (`detectPitchAC`) + a frame→note segmenter/quantiser
  (`framesToNotes`) turn mic input into a note model that feeds the same renderers — a "Record
  a melody" control transcribes a sung/played monophonic tune into all three systems live.
  NO external libraries (the studio's pitchy is a CDN import — deliberately NOT reused here to
  keep the page CSP-safe/self-hosted); mic needs the `Permissions-Policy: microphone=(self)`
  meta. The pure DSP+segmenter are Node-testable (`assets/js/notation.selftest.cjs`, wired into
  CI's JS job); the browser flow (permission/record/stop/graceful-empty) is Playwright-verified.
  Linked in footer + sitemap; clean URL `/notation`. All 14 brief items now shipped.

## Where to look
- Audits: `docs/codebase-audit-2026-06-22.md` (security/correctness),
  `docs/portal-audit-2026-06-22.md` (portal logic/UX). Proposals: `docs/*-proposal.md`.
- Deploy/runbook: `DEPLOYMENT.md`. Env reference: `api/.env.example`.

## Workflow
- **Work on a topic branch off the current tip of `master`** — `claude/<topic>`, e.g.
  `claude/enrolment-audit-fixes`. Don't commit straight to `master`, and don't open PRs
  unless asked. (This used to name one specific branch, `claude/pensive-fermi-st9hvv`,
  which silently went 14 commits stale behind `master` — naming a branch here dates
  badly, so verify with `git log --oneline -3` + `git status -sb` and cut a fresh branch
  instead of trusting a branch name written down in this file.)
- **Check `git status` before staging.** Several `claude/*` branches share this working
  copy, so unrelated modified files are often already in the tree. Stage your own paths
  explicitly (`git add <path> …`), never `git add -A`.
- Keep `php api/tests/run.php` green and add tests for new behavior. Verify a commit
  stands alone (`git stash -u` the rest, re-run the suite) when the tree holds work that
  isn't yours.
- Don't put model identifiers in commits/code/docs.
