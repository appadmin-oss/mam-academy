# CLAUDE.md — MAM Academy

Memory file for agents working on this repo. Read this first; it captures the
architecture, conventions, and the non-obvious gotchas that cost time to learn.

> MAM = **Music, Arts, Mystery**. This is **MAM Academy** (academy.mamcentre.org),
> part of MAM Centre. It is NOT "AfroTech" — there are deliberately no AfroTech
> references anywhere; don't introduce any.

## What this is

A server-rendered **PHP site (no build step)** plus a hand-rolled JSON API.
~56K lines of PHP across 358 files. PHP **8.1+** (8.4 locally). Runs on shared
hosting behind Cloudflare.

- Public pages: 38 `*.php` at the repo root (index, apply, store, book, tuition,
  pathways, terms, etc.). Clean URLs via root `.htaccess` (`.php`/`.html` → 301 → clean).
- `admin/console.php` — the **redesigned admin console**, 31 screens, reads via
  `api/lib/console.php` and renderers in `partials/admin-console/`. Where new
  admin work goes. See "The admin console" below.
- `admin/index.php` — **draws no screens** since the legacy dashboard was
  RETIRED (2026-11-19, owner's call). It is now only the **one implementation of
  every admin write** — the console and the phone subset post every form here —
  plus the downloads under `?v=export`. Any other GET redirects to the console.
  See "The legacy admin dashboard is retired" below.
- `portal/{student,instructor,parent}/index.html` — the three redesigned
  portals (note the **parent/guardian portal**), all on `portal-v2.css` with
  their apps in `assets/js/portal-{student,instructor,guardian}.js`;
  `portal/{login,signup,reset,choose}.html`. The **legacy** dashboards survive
  beside two of them as `portal/{student,instructor}/classic.html` — that is
  where the ~4,800 lines of inline `<script>` live, and they are not retired
  because they still serve the tabs the redesigns have not rebuilt.
- `assets/js/` — `config.js` (the `MAM_API` client + `MAM_CONFIG`), `portal.js`
  (shared portal kit `P.*`), `main.js`, `tools.js` (studio), etc.
- `api/` — front controller `api/index.php` → `lib/router.php`; the libs in
  `api/lib/`, the routes registered in `api/index.php`, the tests in
  `api/tests/`. Counts are deliberately not repeated here: three separate
  numbers in this paragraph went stale within a fortnight. `ls api/lib | wc -l`,
  `grep -c '\$r->\(get\|post\)' api/index.php` and `ls api/tests/test_*.php |
  wc -l` are always right.
- `db/schema-mysql.sql` — canonical MySQL schema, applied with **`mysql
  --force`** (see below); mirrored by SQLite migrations in `api/lib/db.php`.
  `db/schema-v2.sql` is the 141-table v2 target, not yet read by anything.

📍 **`docs/codebase-index-2026-08-15.md` is the map** — the full library, route,
schema and test index, plus a findings list. Read it before a wide change; this
file covers conventions and gotchas, not inventory.

DB: **SQLite by default** (auto-migrates at `api/storage/mam.db`), MySQL in prod
(set `DB_DSN`; apply `db/schema-mysql.sql` once).

## Run & test

```bash
# Full test suite (zero-dependency runner; CI runs this). MUST stay green.
php api/tests/run.php            # → "✅ PASS: N passed, 0 failed"

# The same suite against MySQL — eight defects have existed only there. See
# "Running the site against MySQL" below for the --force and fresh-DB caveats.
DB_DSN='mysql:host=127.0.0.1;dbname=mam_suite;charset=utf8mb4' \
  DB_USERNAME=… DB_PASSWORD=… php api/tests/run.php

# Lint everything
find . -name '*.php' -not -path './api/vendor/*' -print0 | xargs -0 -n1 php -l
for f in $(find assets/js -name '*.js' -o -name '*.cjs'); do node --check "$f"; done

# The four JS checks CI runs. `node --check` above only PARSES — these execute.
node .github/scripts/check-inline-js.cjs   # inline <script> in every HTML page
node assets/js/notation.selftest.cjs       # pitch detector + segmenter
node assets/js/card-export.selftest.cjs    # imposition + cut guides
node assets/js/headshot.selftest.cjs       # photo thresholds + downscale
```

⚠️ Two of those self-tests were only ever *parsed* by CI, and the headshot one
sat broken for months as a result (it asserted against functions a rewrite had
deleted). **A self-test that CI does not execute is not a test.** If you add
one, add it to `.github/workflows/ci.yml` in the same commit.

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
- **One source each** (2026-11-20; each was written two to four times):
  libraries load from `api/lib/libs.php`; signed tokens use
  `mam_b64url_encode()`/`_decode()` (validation.php); "may we measure this
  visitor" is `MAM_PRIVACY.mayMeasure()` (config.js — Do-Not-Track, Global
  Privacy Control, or Decline on the cookie banner: any no is a no); the portals'
  Display & access screen is `MAM_PORTAL.a11yHtml()`/`a11yHandle()`
  (portal.js). The two exporters deliberately keep their own copies of the
  checksum helpers (export-web.php must upload as one file) and
  `test_export.php` requires the copies to match.
- **Routes** are registered in `api/index.php` with method + handler + middleware
  (`mw_csrf`, `mw_role('student'|'instructor')`, `mw_admin_cap('users'|'rbac'|…)`,
  `mw_rate_limit(bucket,limit,window)`).

## Critical gotchas (these have bitten)

1. **The test harness does NOT load `bootstrap.php`.** `api/tests/run.php` stubs
   `mam_env`, `mam_client_ip`, `mam_fail`/`mam_ok` (these THROW in tests) and
   loads the libraries itself. So:
   - A new lib is added to **`api/lib/libs.php`** — the ONE list, read by both
     `bootstrap.php` and the harness (which skips only `log` and `response`, the
     two it stubs). Until 2026-11-20 it was two hand-kept lists, 91 and 89 long,
     and a lib in only one worked in production and was undefined under test, or
     the reverse. `test_code_structure.php` fails on a file in `api/lib/` that
     the list does not name.
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
   - **Payment settlement completes the WHOLE enrolment, via `mam_enrol_complete()`.**
     `mam_payment_settle_tuition()` used to call `mam_lms_add_course()` +
     `mam_lms_approve()` itself and stop. Three gaps, all of which produced a paid
     student nobody was teaching: no **programme** was passed, so the cohort resolved
     on `(course, level, NULL)` while the admin's "Complete enrolment" screen resolves
     on `(course, level, programme)` — the same applicant landed in a *different* class
     depending on which path settled them; the instructor was written to the
     **enrolment only**, leaving the cohort teacher-less for everyone enrolled after;
     and the waitlisted branch (`id === null`) read as 0 and silently did nothing.
     There is now ONE implementation of account → course → cohort → instructor →
     application status, shared by both paths, so they cannot drift apart again.
     Gated by the **`payment_enrol`** automation, which is deliberately separate from
     `payment_provision` — a login and a class placement are different decisions.
     ⚠️ **A settled payment must NEVER fail because the placement could not be worked
     out.** `discipline` holds instruments that are not courses (`violin`, `clarinet`),
     and an erased applicant must not be rebuilt. Both are refused by
     `mam_enrol_resolve()`, logged, and left to the admin backlog
     (`mam_enrol_incomplete()` → `?v=applications&f=incomplete`) — the account is still
     provisioned and the application is still marked paid. Covered by
     `test_payment_enrol.php`.
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
   - **Summer 'enrolled' really enrols, as a CRASH-programme intake.** Summer is not
     a parallel system: `MAM_SUMMER_PROGRAMME_CODE = 'crash'` and
     `mam_summer_programme_id()` put the SUMMER-<year> cohort under the crash
     programme, without which it drops out of the catalogue, the curriculum plan and
     every programme-grouped report. `mam_summer_enrol()` (`admissions.php`)
     provisions a **full student account** — `status='active'`, a real bcrypt password,
     `must_change_password=1`, exactly what a year-round student gets — plus a
     **guardian** account and `parent_links` row from `guardian_email`. ⚠️ This
     REVERSED an earlier decision to give summer children a login-less
     `users.status='roster'` identity; a `roster` row from before is promoted on
     re-enrol, but only from `roster`, so a suspended account is never quietly
     reopened. The generated password is **returned, not emailed** from the
     provisioner. **No password is ever emailed.** The account is created with
     `mam_auth_unusable_password()` (real bcrypt over 32 random bytes — not a
     sentinel string, so nothing downstream pattern-matches the column) and the
     result carries `invite: true`; the caller mints and sends a link with
     `mam_auth_send_invite()`. `MAM_INVITE_TTL_SEC` is **7 days**, not the reset
     path's hour: a reset is answered in minutes, an invitation when a parent next
     looks at their phone. Tokens reuse `password_resets` deliberately — it is
     already a single-use hashed expiring token that sets a password and revokes
     sessions, and a second table would mean two expiry rules and two places to get
     the hashing wrong. `?welcome=1` on the link only changes what `portal/reset.html`
     SAYS (the token is the whole of the security), because "set a NEW password" to
     somebody who never had one is confusing and the 60-minute expiry line is wrong
     for an invite. The guardian gets their OWN invite — the child's link would set
     the child's password. Mail goes via `mam_send()` (which takes the mail-log
     CONTEXT; `mam_send_mail()` takes an opts array instead). The guardian link is
     best-effort: no guardian address must never stop a child enrolling. It resolves the dedicated
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
5. **ID card faces + Card Studio** (`assets/css/id-card.css`, `partials/id-card-face.php`,
   `assets/img/idcard/`). One renderer serves `/card/{serial}` AND `/card-studio`, so a
   proofed sheet is what the student receives — don't fork the markup.
   - **The card is A7, 74×105mm.** `aspect-ratio` in id-card.css, `CARD_W_MM`/`CARD_H_MM`
     in card-export.js and the `@page`/`--mam-card-w` sizing in card.php + card-studio.php
     must change TOGETHER: a mismatch silently stretches every card in the PDF (it was
     54×86 while the artwork was 1:√2, which distorted the imposition). A7 is within 0.3%
     of the designer's 1414×2000 comps, so the plate stretch is imperceptible.
   - **The background is a raster PLATE, not CSS shapes.**
     `api/tools/build-id-card-plates.php <design-dir>` traces the designer's comps into
     `front-plate.png` (orange field + white region + doodle motif, motif masked to the
     white side) and `back-plate.png` (yellow texture, content stripped), and crops
     `signature.png` + `logo.png`. Re-run it if the artwork changes; the comps themselves
     are NOT committed (they are large flattened files).
     Why a plate: the divider is a shallow straight diagonal → circular photo bay → sharp
     kink → second shallower diagonal. `border-radius` approximated it as a soft blob that
     was visibly not the design, and the two things CSS *could* state it with are exactly
     what html2canvas cannot rasterise (**measured**: `clip-path` exports as a plain
     rectangle; an inline-SVG `background-image` exports as a SOLID rectangle of the fill
     colour covering the whole card — which hid the orange and left white text invisible
     on white). Only solid fills, border-radius, linear-gradient and RASTER backgrounds
     are safe on this face.
   - **The traced divider** (% of card height): x=0% 52.5, 30% 46.8, 50% 54.0 (bay floor),
     70% 44.5, 80% 36.3, 99% 32.6. The photo sits in the bay (centre 49.5%/40.9%) and must
     stay above the 54% floor. Column-tracing is ill-conditioned on the near-vertical
     right ramp, so that segment is least-squares fitted instead — otherwise it paints as
     a torn edge.
   - **The motif is black at ~4% opacity** (alpha 122–126). Any alpha cutoff above 127
     throws the whole layer away — a 120 cutoff silently produced a blank motif.
   - **Vertical budgets are tight and coupled.** Front lower block starts at 57% and
     totals ~41.8%; at 1:√2 the card is shorter relative to its width than a CR80, so a
     CR80-tuned type scale overflows and clips the surname. The back is a four-way budget:
     signature 59→70.3%, caption 71.4%, contact 77.5→90.2%, barcode 92.4→96%.
   - **Bleed is edge replication** (`addBleed`), not cropping or scaling: html2canvas's own
     `x`/`y`/`width`/`height` crop is DOCUMENT-relative, so the studio's off-canvas export
     node captured the wrong region (right dimensions, displaced content); scaling art to
     cover the bleed ate ~5% off every edge including the expiry text. `is-exporting` also
     squares the corner radius — cards are trimmed straight and die-cut round after.
   - **`scaleForDpi` has no `Math.max(1, …)` floor** — a floor silently breaks the DPI
     contract for any DPI below the export node's own resolution.
   - **A root HTML page that loads `bootstrap.php`** must `header_remove('Content-Type')`
     + set `text/html` (bootstrap forces `application/json`, so the page renders as source
     text) AND `define('MAM_HTML_RESPONSE', true)` (its shutdown handler otherwise appends
     `{"error":"no_response"}` into the markup). `/admin/` and `/card-studio` both do.
     ⚠️ **It is `MAM_HTML_RESPONSE`, and this note used to say `MAM_RESPONSE_SENT`,
     which was a security bug.** `mam_json()` opens with
     `if (defined('MAM_RESPONSE_SENT')) return;` — it means "bytes already went
     out". A page defining that up front to silence the shutdown net was also
     telling `mam_json()` a response had been written, so **every `mam_fail()`
     on that page returned instead of exiting and execution ran on past the
     guard**. Two things followed, both MEASURED on 2026-09-29:
     • `admin/index.php`'s CSRF and same-origin checks were inert. A POST with
       an expired token logged "CSRF FAILED → mam_fail" and then "CSRF PASSED"
       on the next line, ran the handler, and answered `302` — the same
       response as a successful save. ~140 write handlers, unprotected.
     • `/card-studio` was an **auth bypass**: it defines the constant BEFORE
       `mam_require_admin()`, so an unauthenticated GET returned **200 and
       14,506 bytes** of the admin studio. It answers 401 now.
     `MAM_RESPONSE_SENT` now means only what `mam_json()` reads it as, and a
     route that has genuinely streamed bytes (`media-headshot`, `og-image`)
     still sets it. The shutdown net accepts either. `test_admin_screens.php`
     asserts no page pre-defines the wrong one.
     ⚠️ The admin's two guards **redirect with `err=`** rather than calling
     `mam_fail()`: every caller is a browser posting a form, a JSON 403 renders
     as raw text, and the silent 302 it replaced was indistinguishable from
     success. And the same-origin check must **strip the port** before
     comparing — `HTTP_HOST` carries it, `parse_url()`'s host never does, so a
     bare comparison refuses every admin form on any install not on 80/443.
   - **`mam_setting()` ignores the default registered in `mam_settings_schema()`** — a new
     setting reads empty until someone saves the admin form. Use
     `mam_setting_or_default()` where the shipped default IS the intended value (the
     card's "if found" details printed blank because of this).
   - QR painting is shared (`assets/js/id-card-qr.js`, `[data-qr]`). qrcode.min.js emits
     BOTH a canvas and an img and hides one — size every candidate, never touch `display`.
     Always `await MamCardQR.ready()` before rasterising or printing.
   - ⚠️ **A card is signed with `mam_app_key()`, never `mam_env('APP_KEY', …)`.**
     `mam_card_sign()` used to read the env var directly with a `'dev-key'`
     default, which diverged from the resolver every other signer uses. On an
     install with **no APP_KEY in `api/.env`** — a state `mam_app_key()` exists
     to handle, logs as `app_key_autogenerated`, and carries on from with a
     persisted random key — every card was signed with the literal string
     **`dev-key`**, printed in this open-source file. The serial is on the
     card's own face and in its Code-128 barcode, and `mam_card_verify_payload()`
     takes `user_id` and `version` from the ROW, so a forgery needed nothing but
     sight of a real card: MEASURED, a signature computed offline from the
     public source alone matched `mam_card_sign()` exactly. A short (<32 char)
     APP_KEY diverged the same way, the resolver ignoring it while cards used
     it. Sign and verify both route through `mam_card_sign()`, so the fix is
     symmetric. **An install with a real APP_KEY set is byte-identical** — also
     measured — but one that was running without it must **re-mint**, which is
     correct: those cards were signed with a public constant and were never a
     control. Covered by `test_id_cards.php`.
   - **The admin health panel reports the key's STABILITY, not whether the env
     var is set.** `strlen(mam_env('APP_KEY')) >= 32` showed a red cross to
     every install running correctly on the persisted `storage/.app_key`
     (measured on this one), and the same cross for the real failure — a key
     that cannot be persisted at all and so changes every request, breaking
     CSRF, invite links and card signatures together. `mam_app_key_persisted()`
     is the predicate, the same one `/api/health` reports, and the banner
     beside it had to be rewritten because "missing or too short" was the old
     check's diagnosis.
   - The back's barcode is **Code 128 subset B of the SERIAL** (not the signed attendance
     payload, so a desk scan isn't an attendance mark), rendered as flex columns so it
     rasterises like any other box. `test_id_card_face.php` round-trips it through an
     independent decoder — a barcode that encodes the wrong string scans as another card.
   - **Card expiry is a REAL date (schema v59), not a label.** `id_cards.expires_at`
     is stamped at mint from the admin's validity period — `card_validity_months` (12)
     and `summer_card_validity_months` (**2** — a summer badge must lapse after the
     camp). Stamped, not computed on read: the card in a student's hand prints a date,
     so changing the setting must only affect cards minted afterwards.
     Before v59 the face printed `EXP.12/<issue year>` and NOTHING enforced it, so a
     lapsed card scanned into attendance forever.
     • `POST /api/instructor/card-scan` returns `result:'expired'` — the HMAC still
       verifies (the payload is static and the card genuine), so without that check the
       date on the face means nothing.
     • `mam_card_mint()` will NOT hand back a lapsed card: it retires it and mints a
       replacement, so a returning summer student gets a fresh badge and serial.
     • The face reads `mam_card_exp_display()` and shows an EXPIRED overlay — a lapsed
       card must not look valid at a door.
     • `mam_automation_card_expiry_sweep()` (rule `card_expiry`, wired into
       `api/tools/run-jobs.php`) warns holders `card_expiry_warn_days` ahead — once
       per card, deduped via the `events` table — then sets `status='expired'`
       (deliberately NOT `'revoked'`: revoked means somebody took the card away,
       expired means time did).
     • Anything filtering cards must filter on the **derived state**, not the status
       column: between two cron runs a lapsed card still reads `active`. Both the
       studio and `GET /api/admin/id-cards` do. Covered by `test_card_expiry.php`.
     • Month arithmetic clamps to the end of the target month — `strtotime('+1 month')`
       on the 31st lands in the month AFTER next.
   - **Trim is 60×85mm so NINE fit an A4 sheet (3×3).** A7 (74×105) could not: 3×74 =
     222mm is wider than A4, so 9-up was arithmetically impossible at that size. 85/60
     is within 0.2% of the artwork's 1:√2, so the plate stretches imperceptibly.
     `aspect-ratio` in id-card.css, `CARD_W_MM`/`CARD_H_MM` in card-export.js and the
     `@page` width in card.php must change TOGETHER or the PDF stretches every card.
   - **The default gutter is 2×bleed + 2mm, not 2×bleed.** At exactly 2×bleed the
     neighbouring bleeds MEET and there is no bare paper anywhere between cards — a
     cutting guide there is drawn on dark orange artwork and is effectively invisible.
     The extra 2mm is the white strip the guides live on.
   - **Cut guides (`cutGuideSegments`) are pure geometry and unit-tested** in
     `assets/js/card-export.selftest.cjs` (CI runs it). Guides run from the PAPER EDGE
     inward at both ends of every trim line — so a guillotine can be aligned to two
     marks before committing — plus a segment through every gutter each line crosses,
     inset by the bleed so it lands on paper. The invariant the test pins: **no segment
     ever crosses a card's trim box.** The old scheme was four ticks per card inside the
     bleed, which overlapped between neighbours, never reached the paper edge, and
     printed on the adjacent card's artwork. Registration crosshairs in all four sheet
     corners are identical front and back, so duplex register can be checked against
     the light before cutting.
   - **Prepress practices adopted from the NGG reference** (`vendor/card-export.js`
     in the staff-desk codebase — worth re-reading before changing the exporter):
     • **Marks are drawn in REGISTRATION colour, not black.** RGB/grey black
       separates to the K plate ONLY, so on a four-colour job the C/M/Y plates carry
       no marks and the operator cannot check register against them. jsPDF expresses
       100%-of-every-plate as a four-channel `setDrawColor(1,1,1,1)`, which it writes
       as a PDF `K` operator. Asserted in the self-test.
     • **0.25pt hairline** (the Illustrator/InDesign default). Note jsPDF states
       widths in POINTS in the stream, so 0.0882mm appears as `0.25 w`.
     • **MediaBox + TrimBox + BleedBox on every page.** jsPDF writes only a
       MediaBox; the boxes are the first thing an imposition RIP reads. An imposed
       sheet is a PRESS sheet, so its TrimBox is the sheet and the individual card
       trims are carried by the cut guides — stated explicitly so no RIP guesses.
     • **A SHORT-edge (work-and-tumble) duplex flip must also turn the art 180°.**
       Mirroring rows is not enough: tumbling puts the card's head at the sheet's
       foot, so every back prints upside down. Fixed by pre-rotating the raster
       (`rotate180`) rather than jsPDF's addImage rotation, which turns about the
       anchor and would need a compensating offset. Long-edge keeps the art upright,
       which is why it is the default.
     • Also independently agreed with the reference: marks must never sit inside a
       bleed, and generated bleed should CLAMP (replicate the edge) rather than
       mirror, since a mirrored motif reads as a printing error.
     • NOT adopted: ISO 19593-1:2018 processing-step OCG layers (switchable
       artwork/marks/slug layers tagged with /GTS_Metadata) and the CMYK control
       strip. Both are real prepress wins for a commercial run; both need jsPDF
       internals (`putXobjectDict`, `/OCProperties`) and a structural check on the
       output. Standard 4mm offset crop marks were also not added: at 9-up on A4 the
       margin is only 7mm and a standard mark needs bleed+gap+len = 8.5mm, so the
       edge-reaching guides serve that purpose instead.
   - **Registration photos are stored FIRST-PARTY** (`api/lib/uploads.php`,
     `POST /api/upload/headshot`, `GET /api/media/headshot?k=…`). Cloudinary is still
     used when configured; this is the floor. The original failure: the ONLY persist
     path was an unsigned browser upload to Cloudinary, and `headshot.js` decided
     whether Cloudinary was configured by reading `MAM_CONFIG.cdn` — which arrives via
     an ASYNC fetch of `/api/config/public`. An applicant who picked a photo before
     that landed was judged "not configured" **even when it was**, and the widget then
     showed a green "✓ Photo accepted" and uploaded NOTHING: empty `headshotUrl`,
     no photo in the DB, faceless printed card. `configReady()` now waits for
     `mam:config` (4s cap) before choosing, and there is no branch that reports success
     without a stored URL. Files live outside the web root and are re-encoded by GD
     (a renamed script cannot decode, so it cannot land); both form validators accept
     our own `/api/media/headshot` URLs — without that the stored photo is dropped on
     the way into the DB, reproducing the original bug. Covered by `test_uploads.php`.
   - **A photo upload has FOUR places to fail, and all four have failed.** The store
     working is not the same as a photo reaching a card. When "photos aren't saving",
     walk the whole chain rather than testing the store in isolation:
     1. **The CSRF token.** `/api/upload/headshot` is CSRF-gated, so a POST without a
        valid token is a 403 and the photo is gone. `headshot.js` asked `MAM_API` for a
        `csrfToken()` that never existed, then fell back to a `<meta name=csrf-token>`
        that no registration page carries — so the header was ALWAYS absent and every
        first-party upload failed with a 403 while the store itself was perfect. Tokens
        come from `GET /api/csrf`; `MAM_API.csrf(force)` now exposes it, and the
        uploader refetches once on `csrf_invalid` the way `MAM_API.request()` does.
     2. **Whether a file can be chosen at all.** `MAM_HEADSHOT` exported only
        `mount()`, but the student portal called `MAM_HEADSHOT.upload(file)` — so its
        Profile photo button always said "Photo upload is unavailable right now", and
        the admin card-photo editor offered nothing but a URL field. `upload(file)` is
        now a first-class export, used by both.
     3. **The URL guard on the way in.** `mam_card_set_photo` and
        `POST /api/student/avatar` both demanded `https://`, which refuses our OWN
        stored photo — upload succeeded, then Save answered `invalid_photo_url`. One
        predicate, `mam_upload_photo_url_ok()`, now decides for both: a first-party
        `/api/media/headshot?k=…` URL or an absolute `https://` one, nothing else
        (still no `javascript:`, `data:` or plain http). Client-side checks must match,
        or the UI rejects a URL the server would accept.
     4. **A form that submits anyway.** `apply.js` passed its headshot step when
        `!hasCloudinaryConfigured()` — a step that accepts "no photo stored" as success
        recreates the faceless card no matter how well the store works. Both forms now
        require a stored URL before submitting, and `summer.js` refuses to submit a
        photo it could not save rather than dropping it silently.
     Verify in a real browser, not just against the endpoint: drive
     `MAM_HEADSHOT.upload()` AND the mounted widget, then re-fetch the returned URL —
     a URL that 404s is not a saved photo. Bump the `?v=` on `headshot.js`/`config.js`
     when either changes, or browsers keep running the broken copy.
   - **Three html2canvas render traps, each measured from a real print run:**
     • `addBleed` must sample a strip a few px INSIDE the artwork. html2canvas
       anti-aliases the element boundary against the canvas backdrop, so the outermost
       row is a BLEND — replicating it put a uniform pale-peach frame on all four
       sides of every card (measured: bottom row `rgb(250,239,230)` where the artwork
       3px in is pure orange `rgb(240,127,38)`).
     • No `background` on a child of a rounded, `overflow:hidden` parent —
       html2canvas does not clip it, so the initials disc printed as a square
       overlapping the circle. Put the fill on the rounded element itself.
     • No `writing-mode: vertical-rl` — unimplemented; every glyph landed on top of
       the next and the printed expiry was a smudge. Use `transform: rotate(90deg)`.
   - Vendored on demand, self-hosted for CSP: html2canvas, jsPDF, jsQR
     (`assets/js/vendor/`, licences in `vendor/licenses/README.md`), ~700KB, loaded only
     when an admin exports.
6. **`mam_client_ip()` (bootstrap.php)** trusts `CF-Connecting-IP`/`X-Forwarded-For`
   ONLY from a trusted proxy (private + Cloudflare ranges by default; `TRUSTED_PROXY_CIDRS`
   to override). It's the rate-limit + audit IP key. Don't revert to blindly trusting headers.
7. **SSRF guard** for the curriculum remote-import is in `api/lib/lms.php`
   (`mam_lms_public_ips_for` / `mam_lms_fetch_url`): per-hop re-validation, `CURLOPT_RESOLVE`
   pinning, denies private/CGNAT/IETF-special ranges, ports 80/443 only. Keep it strict.
8. **`auditer_results` capture flow.** `mam_auditer_record()` (`auditer.php`) is the
   writer — admins capture a placement from the application detail card (`?v=applications&app=N`,
   `record_placement` action). When the `auditer_placement` automation is on it also
   ensures the student account + enrols them at the recommended level. The student Auditer
   tab still falls back to live `mam_auditer_status()` for validity/booking state.
9. **`admin/index.php` is the admin's write path, and nothing else.** It drew
   ~40 legacy views until 2026-11-19; they were retired, and with them the
   `.card` layout rules and the per-view helpers. What remains — sign-in, the
   `?v=export` downloads, ~2,300 lines of POST handlers, and a GET redirect to
   the console — keeps these rules:
   ⚠️ **A helper must be defined ABOVE the `$view === …` dispatch, never inside a
   branch.** PHP declares a top-level function at compile time, but one inside an
   `if` block is declared only when that block RUNS. `test_admin_screens.php`
   still fails on any such helper (the export branch is the dispatch now).
   ⚠️ **A handler redirects relative (`?v=…&ok=`)** and the console's
   `_console` hook rewrites it at shutdown; without `_console` the GET redirect
   maps the old view name to a console screen and carries `ok`/`err`. So a
   handler's `?v=` is a hint, not a page — never add a view branch to honour it
   (`test_admin_retired.php` fails on one).
   ⚠️ It has NO `mam_admin_redirect()` helper — writing one from memory produced a
   blank page on every POST of a new screen. Redirect with
   `header('Location: ?v=…&ok=' . urlencode($msg)); exit;`, which is what every
   other action does. **Read `api/logs/` after touching this file**; a
   clean-looking screen is not evidence.
10. **Safeguarding gate (`api/lib/vetting.php`, schema v82).** The academy teaches
   children one-to-one, so instructor vetting is the highest-consequence control
   here. Child Rights Act 2003 s.2(2) requires standards on the **suitability of
   staff**; the Lagos State Safeguarding and Child Protection Policy 2016 mandates
   the specific safer-recruitment checks and a named Designated Safeguarding
   Officer. Non-obvious invariants:
   - **`vetting_records` stores no check CONTENT and no offence data** — type,
     issuing body, reference, dates, verifier, and a pointer to where the evidence
     is filed offline. Under the NDPA this is sensitive personal data about the
     instructor; a certificate scan in a shared-hosting database is a liability,
     not a control. `test_vetting.php` asserts the schema has no room for it.
   - **`MAM_VETTING_REQUIRED` is a CONSTANT, not a setting.** An academy able to
     shorten the required set would have a gate that certifies nothing.
     `MAM_VETTING_ALTERNATIVES` is how a UK instructor's Enhanced DBS satisfies the
     criminal-record limb without a Lagos search they cannot obtain.
   - **Validity is DERIVED from `expires_on`, never a stored status column**, so a
     clearance lapsing at midnight reads as expired on the next page load rather
     than the next cron run (the same rule as ID-card expiry).
   - **UNIQUE(instructor_id, clearance_type)** — re-recording REPLACES. The gate
     asks "is there a current one"; a history of superseded certificate numbers is
     exactly the sensitive data this avoids accumulating.
   - **The gate ships in `warn`, not `enforce`.** `enforce` is the target state, but
     flipping a live academy to it with zero records refuses every assignment on
     the next click — and the reliable outcome of safeguarding theatre is somebody
     disabling the feature. Gaps are audited in every mode, so the record exists
     before enforcement, not after. `off` suits only an install teaching no children.
   - **An adult-only cohort is NOT gated**; unknown age counts as a minor by
     default. A control that fires on adult classes trains admins to ignore it.
   - Hooked into `mam_lms_approve()` (the choke point for admin, API and payment
     settlement) and `mam_enrol_complete()`. An unclear result never fails an
     enrolment — the student is enrolled and the instructor simply not attached,
     because "enrolled, awaiting a cleared teacher" is actionable and a refused
     placement is not.
11. **Punctuality charges are gated INSIDE `mam_accountability_charge()`.** It used
   to read `late_charge_enabled` into `$cfg` and never check it; the two call sites
   each tested it themselves, so the behaviour was right and the guarantee was
   theirs. Do not re-add a gate at a call site: doing so short-circuits the
   function and swallows the no-charge RECORD as well as the charge, which is how
   a lateness came to vanish entirely with charging off.
   - **Student and instructor gates are SEPARATE settings** (`late_charge_enabled`,
     `late_charge_instructor_enabled`), both off by default. One is a fee to a
     customer; the other is a **deduction from pay**, and Labour Act s.5(1) bars
     deductions from wages for fines absent an authorised labour officer's written
     consent. Never merge them.
   - With charging off a lateness is recorded as `MAM_ACCT_RECORDED` — amount 0,
     outside `MAM_ACCT_OWED`, never a debt.
   - `mam_accountability_charge(..., $adminInitiated: true)` deliberately bypasses
     the gate for a charge an admin enters by hand. Audited distinctly.
12. **The MAM Mastery Score withholds a thin composite.** Excluding an unevidenced
   component is correct (scoring an unassessed beginner 0 reports them as failing),
   but publishing that renormalised result as a comparable number is not — the
   OECD/JRC Handbook's point is that renormalising changes what the index means.
   Below `MAM_MASTERY_MIN_COVERAGE` there is **no composite at all**, only
   subscores; above it the score reports as a **ten-point band**, with the
   arithmetic bounds the unscored weight permits (not a confidence interval —
   there is no sampling model). `raw_score` is retained for audit and must NEVER be
   shown to a student, guardian or league table.
13. **The instructor comparison table is formative ONLY.** It reproduces
   value-added modelling, which lost on due-process grounds in *Houston Federation
   of Teachers v. HISD* (2017). It carries a 95% interval, discloses graded *n*,
   withholds comparative figures below `MAM_ANALYTICS_MIN_ROSTER`, and both
   rendering surfaces print `MAM_ANALYTICS_INSTRUCTOR_NOTICE`. **`ORDER BY u.name`
   is load-bearing** — sorting by a metric makes it a ranking whatever the heading
   says.
14. **The academic calendar (`api/lib/calendar.php`, schema v83) is the ONLY
   authority on a refund.** Before it there was **no refund function anywhere in
   the repo** — every refund was a manual decision with nothing to check it
   against. Five invariants, all of which are easy to "simplify" into being wrong:
   - **It does not replace `academic-session.php`.** That file computes
     TONI/MEDI/SUMMER/DOMI windows from constants and is fine for a label on a
     page. `mam_calendar_current_term()` prefers an authored row and **falls back
     to the computed window**, tagging which it returned (`source` =
     `'calendar'|'computed'`). An install with an empty calendar behaves exactly
     as before — strangler pattern, not cutover, which is why this shipped
     without touching `/apply`.
   - **A refund percentage is COMPUTED from a date, never stored per student.**
     Two students withdrawing the same day must get the same answer, and the
     answer must be reproducible a year later in an audit.
   - **`term_refund_schedule` rows are the truth once seeded**, not
     `MAM_CALENDAR_REFUND_DEFAULT`. That constant only *seeds*; a family is
     entitled to the table that was published when they enrolled, so
     `mam_calendar_seed_refund_schedule()` takes `$replace = false` by default —
     re-saving a term's dates must never silently revert an edited table.
   - **`term_census` is a SNAPSHOT and append-only** (UNIQUE on `term_id`).
     "Enrolment as of the census date" must not move when somebody withdraws in
     week nine. Recompute-on-read is the commonest way an official enrolment
     figure quietly rewrites history.
   - **Deadlines are adjudicated in `MAM_CALENDAR_TZ` (Africa/Lagos)**, displayed
     in the reader's zone. `mam_calendar_today()` never reads the server clock —
     a diaspora guardian in New York must not get a different refund from a Lagos
     guardian because their clocks disagree about the date.
   `mam_lms_withdraw()` consults `mam_calendar_withdrawal_outcome()` and records
   the kind of withdrawal (add/drop vs. a "W" with a percentage) into the
   transition reason so it lands in `enrolment_events`. It is **best-effort by
   design** — an unauthored calendar must never stop a student leaving a course.
   Overlapping sibling terms are refused in `mam_calendar_term_save()` (the
   schema's CHECKs can only police one row's own date ordering), because two
   terms covering one day makes "which refund table applies" ambiguous.

15. **The NDPA layer (`api/lib/dataprotection.php`, schema v84) is the RECORD,
   not the machinery.** `datarights.php` already exported and erased; what did
   not exist was any trace that a request had been made. An admin typed an email,
   downloaded a bundle, and nothing survived. Non-obvious invariants:
   - **The ROPA and the retention schedule are CODE registries, not tables.**
     `mam_dp_purposes()` names the tables each purpose actually touches and
     `mam_dp_retention()` names a real table and date column;
     `test_dataprotection.php` asserts both against the live schema. A register
     describing what the software does must be derived from the software — free
     text in a CRUD form drifts the day a feature ships, and a drifted register
     is worse than a missing one because somebody has signed it. The academy's
     additions (review date, note, a LENGTHENED period) live in
     `dp_register_notes` on top.
   - **`action => 'delete'` is legal only on a LEAF table**, asserted against the
     live foreign-key graph. A cascade through an enrolment would destroy
     academic history to tidy up a note. The academic record, payments, vetting
     and the audit log are all `review` — a human decision by construction, never
     applied by the sweep whatever the mode.
   - **A retention period may be LENGTHENED, never shortened** (clamped in
     `mam_dp_note_save()`). The schedule states a lawful basis for each period;
     a smaller number typed into a form would delete lawfully-held records.
     Shortening one means editing the basis in the lib, deliberately.
   - **The sweep ships in `report`** (`dp_retention_mode`), like the safeguarding
     gate and for the same reason. `mam_dp_retention_sweep(true)` is an explicit
     dry run that overrides `enforce`, so the admin's preview button cannot be
     made destructive by a setting.
   - **Every clock is DERIVED and measured from the statutory trigger.** The
     breach clock runs from `discovered_at` (**awareness**, NDPA s.40(2)), never
     from `occurred_at` — measuring from occurrence makes an instantly-reported
     month-old breach look late. `mam_dp_request_clock()` judges a CLOSED request
     against when it was closed, not against now, or every historic record turns
     overdue as time passes.
   - **The registration tier is MEASURED, not diarised.** `mam_dp_tier()` counts
     distinct subjects over a rolling six months — the window the definition
     uses — deduplicated by email across customers/users/newsletter/messages,
     because a guardian who did all four is one data subject and counting them
     four times puts the academy in a filing tier it does not belong in.
     ⚠️ **Ordinary-High Level (201–1,000) registers but does NOT file Compliance
     Audit Returns**; filing starts at Ultra-High. The research plan overstated
     this. MAM currently measures ~19 and is below the DCPMI threshold entirely.
   - **`MAM_DP_DEADLINES['request_days']` is NOT statutory.** The NDPA fixes no
     response deadline (the repealed NDPR did); 30 days is the academy's own
     commitment and the screen says so. The 72 hours, and SNAG's 7/30, are the
     instrument's. All carry a verified-on date because they are the facts here
     most likely to have moved — the NDPC's own site is unreachable from this
     environment, so they came from corroborated commentary.
   - **The processor register probes what is CONFIGURED right now.** An
     aspirational register lists the vendor somebody once considered and omits
     the SMTP host that has carried every password-reset email for a year; `gap`
     = live and no DPA on file.
   - **The application LOG is personal data, and it is swept too.**
     `mam_log()` writes a JSON line per event into `api/logs/app-YYYY-MM-DD.log`
     carrying an IP, a user agent and whatever `meta` the call site passed.
     Nothing ever deleted those files — the one store of personal data living
     on the filesystem sat outside the whole layer, accumulating for the life
     of the install on shared hosting. `mam_dp_prune_app_logs()` sweeps them
     from `run-jobs.php` under the SAME `dp_retention_mode` gate, with
     `dp_log_retention_days` (90) as the period. Three things to keep:
     it is deliberately **NOT** an entry in `mam_dp_retention()` — that
     registry is asserted table-by-table against the live schema, so a
     file-based rule would break the test that keeps the register honest;
     the day comes from the **FILENAME, never mtime**, because a restore or an
     rsync rewrites every mtime and would make a whole history look like it
     was written today; and only the exact `app-YYYY-MM-DD.log` pattern is
     touched, never a glob of the directory. Covered by
     `test_log_retention.php`.
   - ⚠️ **`mam_log()` takes the IP from `mam_client_ip()`, never `REMOTE_ADDR`.**
     MEASURED: with a request arriving at Cloudflare edge `172.68.10.5` carrying
     `CF-Connecting-IP: 102.89.44.17`, the log recorded **the edge** while
     `mam_audit()` — from the same request — recorded the caller. Every line
     named the CDN, so the one field anybody reaches for during an incident was
     useless, and the two writers disagreed about the same request. It was the
     only direct `REMOTE_ADDR` read outside the resolver itself.

## Security posture (don't weaken)

**The break-glass credential has an identity.** `ADMIN_USER`/`ADMIN_PASSWORD_HASH`
authorises a caller with no session, which used to mean admin API routes 401'd
it (their bodies re-checked `mam_require_role('admin')` on top of the
`mw_admin_cap()` already on the route — the middleware is the stronger check, so
the inner call only added a `mam_require_user()`). Routes now take the actor from
**`mam_admin_actor()`**, which returns the session admin or, under break-glass,
the `break-glass@mam.invalid` recovery row (`status='system'`, created on first
use). That row exists because `resolved_by`/`created_by`/`author_id` are
non-nullable FKs into `users(id)` — 0 breaks the constraint, NULL erases who
acted (gotcha #3). It is **not a usable account**: every login path requires
`status='active'`, and its hash is of 32 random bytes never stored. Two records
result and both matter — the ledger names the identity, `audit_log.actor` names
the credential (`PHP_AUTH_USER`). Don't re-add a role check inside an
`mw_admin_cap()` route, and don't make an actor id nullable to "simplify" this.

**Sign-in throttles count FAILURES, never requests.** `mw_rate_limit()` counts
requests, which is right for a public form — there the request itself is the
cost. On a login it locked out people who typed their password correctly: eight
sign-ins per IP per ten minutes, and the key is the IP, so on carrier-grade NAT
(most Nigerian mobile traffic) or an office connection those eight are shared by
unrelated people. `mw_rate_limit_failures()` + `mam_rate_limit_check()` /
`mam_rate_limit_record()` are the pair for auth: the middleware checks without
consuming, the handler charges the bucket only where authentication actually
failed. A success must neither increment **nor clear** it — clearing would let an
attacker holding one valid credential reset the counter between guesses at other
accounts. Don't put `mw_rate_limit_failures()` on a route where the request IS
the cost (mail, AI): there a success is exactly what you're limiting.

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
- ✅ **Homepage hero rewritten (2026-08-20)** from `Hero_Nav_Fixed.dc.html`. The
  coloured card is **gone** — four panels meant four visual identities in
  twenty-six seconds, the card duplicated the logo 40px above it, and
  `backdrop-filter: blur(20px)` on a 620px panel over a full-bleed photograph is
  a real cost on the mid-range Android that is most of this academy's traffic.
  One editorial block now, varied only by an accent that `data-accent` selects
  as a custom property (no `style=` in the markup; a fifth slide is one CSS
  block). Every `.card-*` / `.btn-signup*` / `.hero-content` rule is deleted, in
  all four places they were scattered. Non-obvious:
  • **The bottom furniture is a flex ROW** (`.hero-footer`), not three things
    positioned against one edge — measured, the proof strip overlapped the dot
    pill by 5px at 1440 once the dots became a comfortable 44px instead of the
    frame's 24px. Verified collision-free at 1920/1440/1180/900/430/390/320.
  • **Inactive slides are `inert`**, not merely `visibility:hidden`: visibility
    transitions over .9s and computes as `visible` throughout, so for 900ms
    after every advance the outgoing slide's links were still focusable.
  • The carousel **starts paused under `prefers-reduced-motion`**, has a real
    pause control (WCAG 2.2.2), announces into a live region, and **steering it
    hands over control** rather than restarting the timer.
  • The frame's corner treble clef is deliberately NOT reproduced: `motion.js`
    already renders the site's signature wireframe in that exact corner and
    calls it "one sculptural element, never particles, never noise".
  • The photograph is an `<img>` so the first can be preloaded as the LCP
    element and the other three deferred. `main.js` skips `#hero` when adding
    lazy attributes, which is why the explicit ones survive.
- ✅ **Nav patches from the design review (2026-09-17)** — `docs/design/2026-08-portal-redesign/PATCHES-hero-and-nav.md`.
  ⚠️ **Patches 1–6 are STALE — do not apply them.** They are written against the
  pre-2026-08-20 hero and would REGRESS it: patch 1 asks for `visibility:hidden`
  on inactive slides, which is exactly what the hero note above records as
  insufficient (visibility transitions over .9s and computes as `visible`
  throughout, so the outgoing slide stayed focusable for 900ms — the repo uses
  `inert`), and patch 2 asks for 24px dots where the repo already has 44px.
  Patches 10 and 12 were also already done, 10 more thoroughly (it marks
  dropdown items and the mobile menu too, and picks exactly one top-level
  underline). **Patches 7, 8, 9 and 11 were live and are now applied:**
  • the `menubar`/`menuitem`/`menu` roles are gone — they promise an
    application-menu keyboard contract (arrows move, Tab exits the bar as one
    stop) that the code never implemented, and `role="menu"` may not contain
    the `<h5>` headings these panels hold;
  • **Experiences** is a `<button class="nav-link-disclosure">`, not
    `<a href="#">` — it never navigated, and the bare hash jumped to the top of
    the page. 44px box, because padding around a 9px chevron measures 17px;
  • **Schools is merged into Disciplines**, desktop and mobile. Both resolved
    to `/schools`, so a parent hunting for piano had a coin-flip with no
    feedback. `initActiveNav()` still contains the workaround for the duplicate
    ("avoids two links … underlining at once") — harmless, and now unreachable;
  • the floating Apply pill uses **`inert`**, not `aria-hidden` on a container
    wrapping a focusable link.
  ⚠️ **The mobile floating pill needed more than the patch says.** Un-hiding it
  put it under the cookie banner (its own `docked` class is about the FOOTER
  and only runs above 1024px), so it now joins `window.mamDock` — the
  column-reverse flex stack the cookie banner already lives in. And because
  `lead.js` ALREADY puts a persistent "APPLY NOW" bar in that dock, the two
  shouted the same thing twice: they are now mutually exclusive, the bar
  winning while it is up and the pill taking over once it is dismissed (it
  remembers for 30 days, so deleting the pill would leave mobile with no
  persistent Apply path — the thing the patch wanted).
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
  Admin user-create emails an INVITATION to set a password (`mam_auth_send_invite`); no password is ever typed or emailed (HANDOFF §4w).
- ✅ **Versioning** — API: `MAM_API_VERSION` + `X-MAM-API-Version` header; `/api/v1/…`
  aliases the current major, unknown majors 404. UI: `MAM_CONFIG.uiVersion`; both shown
  in the admin sidebar (UI · API · schema).
- ✅ **Admin UX layer** — nav badge counts + '⚡ Needs your attention' queue on Overview
  (pending applications/registrations/unassigned/proposals/messages/faculty), toast
  feedback for ?ok/?err (string values carry the message; URL cleaned after), '/' or
  Ctrl+K focuses global search.
- ⛔ **Admin redesign of the legacy dashboard** — RETIRED with that dashboard on 2026-11-19; kept as history. `admin/index.php` adopted the
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
- ✅ **Lifecycle automations** — `api/lib/automations.php`: a registry of rules
  (`payment_provision`, **`payment_enrol`**, `enrol_welcome`, `payment_retry`,
  `auditer_placement`, `session_reminder`, `week_drip`, `store_receipt`,
  `ktams_birthday`, …) — **never hardcode the count in a test; derive it from
  `mam_automations_registry()`**, which is how adding a rule has broken the suite
  twice. Each has a persisted on/off flag (`automations` table, **schema v38**) honoured *at its trigger
  point* — `mam_automation_on($key)` gates the behaviour in `payments.php` / `lms.php` /
  `auditer.php`, and `mam_automation_touch()` records run telemetry (last-run + count).
  Admin screen `?v=automations` (grouped cards, per-rule toggle + telemetry, "Run
  scheduled sweeps now"). The three scheduled sweeps
  (`mam_automation_session_reminder_sweep` / `_ktams_birthday_sweep` / `_week_drip_sweep`)
  are events-deduped, never throw, and are wired into `api/tools/run-jobs.php` (the cron).
  A new lib → add it to `api/lib/libs.php` (gotcha #1).
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
  `docs/drive-apps-script.md`. New lib → in `api/lib/libs.php` (gotcha #1). Covered by `test_drive.php` (signing, guards, byte-for-byte
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

## LMS 2.0 — the teaching operations layer

Built after the portal brief and **not covered anywhere else in this file**, which
is why it kept getting rediscovered. Roughly a third of the system.

- **Parent / guardian portal** — **REDESIGNED (2026-08-19)** from the academy's
  design package; the FIRST portal on the new palette, and since 2026-09 all
  three are (see "The student portal v2" for how they fit together).
  `portal/parent/index.html` is class-only markup with its app in
  `assets/js/portal-guardian.js`, styled by `assets/css/portal-v2.css` — which is
  **scoped under `[data-portal="v2"]`** so it can coexist with
  `main.css`/`portal.css`, still loaded by the three `classic.html` dashboards.
  **Load exactly one palette per page.** Routes:
  `/parent/{dashboard,children,child,consent,reports,appeal,fees,plan,bursary,makeup,threads,thread-post,channels}`.
  Guardians link via `parent_links` (admin `?v=guardians`) and now carry an **access
  scope** (v86: billing / standard / full) enforced server-side by
  `mam_parent_scope_allows()`; `MAM_PARENT_NEVER` is checked first and is a refusal,
  not a missing permission. `mam_parent_child_detail()` remains the child payload.
  ⚠️ `mam_parent_weekly_status()` no longer returns `mastery` — the raw gradebook
  mean is gone on purpose, and `mastery_band` is what a guardian is shown.
  Read docs/HANDOFF.md §4n before touching any of it.
- **Pre-class + teach-back** (`preclass.php`) — students prepare before a session
  and teach the material back; instructors get a command centre to review it.
- **Instructor clock-in + meeting reconciliation** (`meetings.php`) — clock in/out
  against a session, reconcile against Meet/Jitsi attendance (admin `?v=meet`).
- **Rubrics** (`rubrics`, `rubric_criteria`, `submission_reviews`) — competency-grid
  scoring on submissions, authored by instructors (admin `?v=rubrics`).
- **Accountability + fines** (`accountability.php`, `progress-reports.php`) — a
  punctuality charge ledger for students, and a 2-hour post-session progress-report
  deadline for instructors backed by `instructor_fines`.
- **Paid rescheduling** (`reschedule.php`) — server-priced request → instructor
  approve/decline → wallet settlement.
- **Academic analytics** (`analytics2.php`) — academy health, instructor
  performance, today snapshot, intervention list (admin `?v=academic`).
- **Academic email engine** (`academic-emails.php`) — event-driven transactional
  academic mail.

### The guardian layer (schema v85–v88) — reports, money, and how a family is reached

Built for the 2026-08 portal redesign. Four libs, and the invariants that are
easy to "simplify" into being wrong:

- **`reports.php` (v85–86) — the RATIFIED termly report.** NOT `progress_reports`,
  which is the instructor's two-hour post-session note. A family sees a report only
  after a board ratifies it, and `mam_report_ratify()` **throws** on an open
  `mark_appeals` row, on a moderation sample below `MAM_REPORT_MODERATION_MIN` /
  `MAM_REPORT_MODERATION_SHARE`, on a report with no scored criterion, and on an
  unnamed ratifier. A boolean return would let a caller publish an unmoderated mark
  by ignoring it.
  - **The band comes from `mam_mastery_score()` and is never recomputed.** A mean
    computed here would be exactly the thin composite the coverage floor withholds.
  - **A band is whole numbers, ten points wide, or clamped at the top.** The v85
    CHECK enforces it, and `mam_report_band()` produces exactly what it admits —
    including `(100, 100)` for a perfect score, which an earlier
    `band_high > band_low` rejected. SQLite's INTEGER is an AFFINITY: without
    `x = CAST(x AS INTEGER)`, `74.3` stores verbatim.
  - **An unevidenced competency is a ROW** with `level='insufficient'` and a NULL
    pct. A zero there reports an unassessed beginner as failing.
  - **The one-report-per-slot rule is PHP's on BOTH engines** —
    `mam_report_slot_taken()`. No UNIQUE index can state it: with no status term a
    withdrawn report holds its slot forever (so the corrected report an upheld
    appeal produces can never be written), keying on `term_label` keys it on a
    renameable string, and `COALESCE(course_id,0)` turns `ON DELETE SET NULL` into
    an abort.
- **Guardian ACCESS SCOPES (v86)** — `parent_links.access_scope`: billing /
  standard / full, every existing link defaulting to **standard** because a
  migration that narrowed live access is worse than the bug. One predicate,
  `mam_parent_scope_allows()`. **`MAM_PARENT_NEVER` is checked FIRST** — practice
  logs, teach-back recordings, a teenager's messages — so adding a grant to `full`
  cannot open one. SQLite carries no CHECK on the column (ALTER can't add one), so
  `mam_parent_scope()` reads an unrecognised value as the NARROWEST scope.
- **`fees.php` (v87) — the fee invoice.** NOT `invoicing.php`, which is the
  application processing-fee lifecycle. **The four kinds of aid are four
  MECHANISMS**, and `mam_fee_recalc()` is the only implementation of the order:
  `gross → waivers → aid credits → sibling (capped) → total`.
  - a **waiver** reduces the LINE and is never a cash entry; a **bursary** and a
    **scholarship** are gift-aid CREDITS against the invoice; **sibling** is a
    discount on the POST-AID balance, because a percentage of the gross would
    discount money the aid pool paid. The cap is on the AMOUNT, not the percentage.
  - **`mam_fee_to_minor()` is string arithmetic** — the decimal point is MOVED.
    MEASURED on PHP 8.4: `8.29 * 100` is 828.99999999999989 and `0.29 * 100` is
    28.999999999999996, so a truncating conversion loses a kobo; and `2.675`
    rounds to 268 by string where the float route gives 267. ⚠️ An earlier note
    here cited `8.07`, which is EXACT on this build — the reasoning held, the
    example did not. Prefer it over `mam_money_to_minor()` wherever the value
    arrives as text.
  - Totals compute ONCE from the lines in the invoice's own currency and are
    STORED. **Never sum per-line conversions** — a one-naira gap reads to a parent
    as a pricing error. The rate is FROZEN at issue for reconciliation; the naira
    charge is labelled an estimate.
  - **There is no column and no function that suspends anything over money.** The
    refusal is kept by leaving the mechanism unbuilt.
  - **The office's side (v91, HANDOFF §4t).** Money received is `fee_payments`, one
    row per payment, voided never deleted; once an invoice has any, they are the
    authority for `paid_minor` (so a void brings the balance back).
    `mam_fee_plan_allocate()` lays them over a plan's instalments. A plan a guardian
    asks for is a REQUEST until `mam_fee_plan_approve()`. `mam_fee_dunning_sweep()`
    is the reminder ladder (automation `fee_reminders`), forward-only and claimed by
    compare-and-set. Console actions are `fee_*` / `bursary_decide` under the
    `fees` capability.
  - **Paying online (v92, HANDOFF §4u).** `POST /api/parent/fee-pay` opens an
    ordinary `payments` row, `purpose='fee'`, carrying `fee_invoice_id`,
    `fee_minor` (in the INVOICE's currency) and `fee_payer_id`; verify and both
    webhooks settle it through `mam_payment_settle()` →
    `mam_payment_settle_fee()`, which records a normal `fee_payments` row. What is
    credited is `fee_minor`, never the naira the gateway took. If the balance
    shrank before the charge cleared, only what is still owed is credited and the
    rest is shown on the console invoice as **to refund** — never thrown away,
    never a negative balance. Paystack only (it redirects; the inline checkouts
    need a script the portal CSP does not admit). Gateway amounts are compared in
    integer kobo by `mam_payment_amount_ok()`, never `amount * 100`.
  - **Bank transfers are reported, then confirmed (v93, HANDOFF §4v)** — the
    rules are NGG's `offline_payments`, ported. A guardian reports a transfer with
    the slip (`POST /api/parent/fee-transfer` → `mam_fee_transfer_claim()`); it is
    a CLAIM and credits nothing. The office confirms by RETYPING the reference and
    amount from the statement (`mam_fee_transfer_verify()`); the confirm form must
    not print the claimed figures beside the boxes. The slip's BYTES decide its
    type; one slip and one bank reference are one payment, ever — and
    `mam_fee_payment_record()` now refuses a reused reference too, which used to
    credit the same transfer twice. A rejected reference cannot be reported again.
    The verify is compare-and-set and REVERTED if crediting throws. Online money
    taken beyond the balance is a `fee_online_unapplied` row resolved as
    refunded / moved to the same child's invoice / dismissed. Slips live under
    `storage/uploads/fee-evidence` so the backup carries them, and stream only via
    `/api/admin/fee-evidence` (fees cap, attachment, hash re-checked).
  - ⚠️ **A charge the gateway confirmed is `status = 'succeeded'`.** The console
    read `'success'` in eight money queries, which nothing writes, so Collected
    and the ledger's accounts read zero. `MAM_CONSOLE_PAID` /
    `MAM_CONSOLE_SUSPENSE` in `console.php` are the one spelling; suspense is an
    APPLICATION charge with no application — store orders and fee payments have
    none by design.
  - A **bursary** is assessed on a declared income BAND; there is no hardship /
    circumstances / case_text column and nothing linking it to an admission
    decision, both asserted by test. The published table is a CEILING a committee
    may go under and never over.
- **`guardian-comms.php` (v88) — threads, channels, make-up credits.** NOT the
  `messages` table, which is the public contact form.
  - **A student is never a participant in a guardian thread.** The role CHECK
    admits no 'student' and `mam_thread_add_participant()` derives the role from
    the ACCOUNT, so there is no signature through which a student can be added.
  - **`mam_guardian_channels()` ignores the toggles for safeguarding and money**
    and returns `override: true` with the reason in words. `guardian_channel_prefs`
    has no per-topic column, so "mute safeguarding" is not a storable state.
  - **Only `guardian_notice` spends the make-up allowance.** The allowance limits
    how often a FAMILY cancels; a teacher cancellation, a closure and a goodwill
    credit are all free, forced by CHECK on both engines. No money column, no
    transfer column, and `expires_on` NOT NULL — swept on READ, so a lapsed credit
    reads expired on the next page load.
- **`mam_mastery_publishable()`** is the one predicate for what a student or
  guardian may be shown: it strips `raw_score`, and strips `bounds` while the score
  is withheld (`bounds.min × 100 ÷ coverage_pct` recovers the withheld figure). It
  does NOT claim the figure is unrecoverable — subscores are published on purpose —
  and the comment says so. What the floor governs is what the academy STATES as a
  comparable headline number.

⚠️ **`mam_db()` pins the MySQL session time zone to UTC**, because bootstrap pins
PHP's to UTC while `CURRENT_TIMESTAMP` resolves in the server's (WAT on this
academy's hosting). Without it, `CHECK(closed_at >= opened_at)` made an appeal
declined twenty minutes after it was raised a hard write failure SQLite never sees.

⚠️ **`db/schema-mysql.sql` requires MySQL 8.0.16+ / MariaDB 10.2.1+**, not the 5.7
its header used to claim: 5.7 PARSES a CREATE TABLE CHECK and silently discards it,
and the file now leans on ~20 of them. `mam_db_migrate_mysql()` logs an ERROR
naming the version on a server that ignores them.

### The attendance clock, mastery, and the unified record (schema v81)

Closed the last gaps in the academic proposal (see
`docs/spec-conformance-2026-08-15.md` for the full §1–§47 matrix).

- **`session_attendance` was rebuilt** to carry §12's SEVEN statuses (the CHECK
  admitted only four; `left_early`, `technical_failure` and `rescheduled` had to
  be recorded as an `absent` that misrepresented what happened), the §16 `mode`
  (onsite/online/hybrid — one unified record across all three), and the clock's
  other half: `clocked_out_at`, `duration_min`, `minutes_late`. It is a leaf
  table, which is the only reason the rebuild is safe; it runs `@no-tx` so it can
  toggle `PRAGMA foreign_keys`. `class_sessions.mode` was added alongside.
  ⚠️ **Durations are STORED, never recomputed on read** — a session's
  `scheduled_at` can be edited afterwards and the record of what actually
  happened must not move with it.
- **`mam_checkin_window()` is the ONE source for §9's boundaries** — the
  30-minute opening (`checkin_open_before_min`), the 15-minute readiness
  threshold (`readiness_before_min`) and the notice sentence. The student portal
  used to hardcode `READY = 15` in the browser, so an academy that changed the
  setting got a page that disagreed with the gate. Don't reintroduce a
  client-side boundary. `mam_checkin_next_class()` is the §8 panel.
- **`mam_checkin_clock_out()` measures time PRESENT**, from the later of
  check-in and scheduled start — a student who arrives 20 minutes late and
  leaves at once has a duration near zero, and that is correct, not a bug. It
  derives `left_early` with a 5-minute tolerance so packing up isn't an event.
- **`mastery.php` (§23-24).** Two rules that are easy to "fix" into being wrong:
  a component with NO evidence is **excluded and the weights renormalised**
  (scoring an unassessed beginner 0 reports them as failing), and **attendance
  and punctuality are never components** — §24 requires them beside the score so
  musical ability isn't confused with disciplinary performance. A new student's
  score is `null` at level 0 ("not assessed"), which is a different statement
  from zero. Rubric criteria map to components by LABEL, and `theory` is tested
  before `musical_accuracy` because §20's own criterion is "Score/Notation
  Accuracy" — checking 'accuracy' first swallows it.
- **`accountability.php` gained §15 and two §37 statuses.** An admin could waive
  a charge but nobody could ASK. A request carries one of seven reason codes,
  moves the charge to `pending` while open (a student shouldn't see a debt the
  academy hasn't decided is owed), and on rejection restores `outstanding` —
  §15's "Rejected — Charge Maintained", not a drop. `disputed` is non-terminal
  and still counts as owed; `MAM_ACCT_OWED` is the predicate, not `= 'outstanding'`.
- **`academic-record.php` (§43)** is read-only and owns no table: it delegates to
  the lib that owns each of the fourteen sections so it can't drift. Note it
  dual-reads both enrolment models (gotcha #2) — a half-record would be wrong —
  and joins `mail_log` on the account's current email, since that table is keyed
  by address, not user id.
- **`upper-admin.php` (§34-35, admin `?v=upper`, super-only).** `mam_upper_health()`
  computes the academy measures for ANY scope and `mam_upper_breakdown()` returns
  that *same* metric set one level down — keep them identical or the levels stop
  being comparable, which is the entire point of the screen. Two invariants:
  a filter matching nothing returns **nulls, not academy-wide figures** (silently
  widening the scope while the breadcrumb claims otherwise is the worst failure
  this screen can have), and **null ≠ 0** everywhere — an unassessed group is not
  a failing one, so it sorts last, not first. `{SCOPE}` in a query template is
  replaced using a cohort column named *by this file*, never by a request.
- **Live Google Meet (§11) is real now, not a scaffold.** `mam_google_jwt_assertion()`
  (RS256) → `mam_meeting_google_token()` → `mam_meeting_google_fetch()`. Two things
  that are silently wrong when you get them wrong: the assertion **must** carry
  `sub` (without it the token is the robot's own identity, which has no Reports
  access), and Reports emits the moment a participant **LEFT** plus a duration —
  there is no join timestamp, so the join is derived by subtraction. Failure is a
  degradation, never an exception: §11 says the portal clock-in stays the official
  record. Credentials live on `?v=meet`, not the generic settings form, which
  truncates at 300 chars (a service-account key is ~2KB).

## Advanced Modernization (~31 features shipped)

Driven by `MAMAcademyAdvancedModernizationFeatures.md` (§1–§8). ⚠️ **That spec
file is NOT in the repo** — it was an upload in the session that started this
work. Anything still unbuilt needs it back before the requirements can be
trusted; do not infer them from a section number.

Libs added: `achievements` `challenges` `consent` `repertoire` `referrals`
`makeup` `audit` `bi` `inline-edit`. Migrations v73–v79 (the
later batch — BI, celebrations, guardian KPIs, drag-scheduling, inline edit
and takes — added **no** migration).

Shipped: timestamped feedback · practice player + waveform · side-by-side
reference · repertoire→recital · achievements / streak-freeze / guardian wins ·
practice challenges · ~~opt-in effort leaderboards~~ (⛔ **REMOVED 2026-09** —
the 2026-08 portal redesign refuses a cohort ranking outright, refusal 5. The
lib, both routes, the route file and the student-portal panel are gone, and
`test_engagement2.php` now holds a GUARD asserting they stay gone rather than
simply dropping the tests. Two things deliberately survive: the **studio**
practice-tool board (`mam_studio_leaderboard`, `/api/studio/leaderboard`) is a
different feature — attempts at a TOOL, not children ranked against
classmates — and `users.leaderboard_opt_in`, because dropping a column from
`users` means a SQLite table rebuild and `users` is the most foreign-keyed
table in the schema; an unread column is cheaper than that risk) ·
**milestone celebrations
(§3.6)** · command palette · pins · bulk-accept · continue-where-left-off ·
practice insights · instructor cohort analytics · **admin Business Intelligence
(§5.4, `?v=bi`)** · **richer guardian KPIs (§5.2)** · referrals · certificate
verification · low-bandwidth / dyslexia / text-size · waitlist · make-up classes ·
arrival analytics · consent & safeguarding · per-person audit timeline ·
**drag-to-reschedule (§4.3)** · **inline edit with undo (§4.4)** ·
**recorded takes (§1.4)**.

**Not built, and why** — two different reasons, don't conflate them:
- *Needs the spec back*: §1.5 sheet music, §2.x AI features, §3.8/§6.1/§6.2
  recital hall + community, §7.1 multi-language. All are buildable with what
  the repo already has (the `ai.php` gateway, for instance, is exactly the
  "off until configured" pattern §2.x would use) — the blocker is that
  inventing the requirements from a one-line summary would build the wrong
  thing.
- *Needs real infrastructure*: §4.5 SSE presence (long-lived connections are
  hostile to shared hosting behind Cloudflare) and §4.6 WhatsApp/SMS (a
  provider account and keys). Don't ship dead buttons for these.

⚠️ **The newest features are cohort-only, not dual-read.** `makeup.php` reads
`course_enrollments` / `session_attendance` exclusively, so a legacy-only
student gets no make-up suggestions. That is defensible (it is a cohort-shaped
concept) but it is a *new* divergence in a codebase that spent real effort
closing the old one — see gotcha #2. Decide deliberately before adding another.
(`leaderboard.php` had the same divergence and is now removed outright.)

Two conventions these established:
- **`MAM_PORTAL.celebrate(kind, label)`** marks a milestone once. Guard every
  call with `MAM_PORTAL.seenOnce(key)` — it records the key per-device, so a
  re-render does not replay yesterday's badge. Never animates under
  `prefers-reduced-motion` (particles are not *created*, not merely hidden).
- **`api/lib/inline-edit.php`'s allowlist is a security boundary, not a config list.**
  (Its browser half, `assets/js/inline-edit.js`, went with the retired legacy
  dashboard — nothing loaded it — and was deleted on 2026-11-20; the server side
  and its allowlist remain, and a console screen that edits in place must reuse them.)
  `api/lib/inline-edit.php` is the only thing standing between "edit a cell"
  and "set `users.admin_tier`". Adding a field there is a security decision:
  never identity, authentication, roles, money already taken, or the audit log.
  `$table`/`$column` are interpolated into SQL and are only safe because they
  are matched against that registry before use.
- **A stored take is access-gated, a stored headshot is not.** `/api/media/take`
  resolves the key back to its submission and checks the caller; an unguessable
  key is not a policy for a recording of a child. Any new media proxy should
  copy the take one, not the headshot one.
- **Anything that stores a first-party URL must accept `/api/media/...` back.**
  This has now bitten three times (headshot into the card, headshot into the
  DB, take into `mam_lms_submit`): a store succeeds and a downstream `https://`
  guard silently drops the result. Check both ends when adding an upload.
- **BI money reads `payments.amount_usd_cents` only.** Never fall back to
  `payments.amount` — that is the *charge* currency, and summing NGN into a USD
  total is the bug the v61 column exists to prevent. NULL = unpriced, counted
  and disclosed separately rather than silently dropped.

## Class spaces, stored files, live classes, invoices, growth (HANDOFF §4z3, v99–v102)

- **Every stored file is read through `/api/media/object`**, which checks who is asking
  (`mam_media_can_read`) before a ten-minute presigned link or a local stream. The bucket
  is private on purpose — most files are videos of children. Never hand out a bucket URL.
- **A class space's rules live in `spaces.php`, not in the page**: marks are private,
  a minor without media consent shares video with the teacher only, words are screened
  before anybody sees them, and there are no private messages of any kind. Add a write
  path → run it through `mam_space_screen()`.
- **Concerns go through `mam_sg_raise()` / `mam_sg_auto()`.** Never refuse a safeguarding
  message — route it to the officer.
- **The office issues through `mam_fee_issue_reviewed()`** (a second person reviews a
  draft); `mam_fee_invoice_issue()` stays the primitive the tests use.
- **Live-class attendance never overwrites a teacher's mark** (`method = 'instructor'`).
- **The ability estimate learns only from first attempts at machine-marked questions** and
  is never shown to a student.
- `Permissions-Policy` allows camera/mic/screen for `https://8x8.vc` only — a bare
  `camera=()` silently kills the class call's camera.

## Lessons — the syllabus as something a student works through (HANDOFF §4z — ported from NGG)

`api/lib/lessons.php`, schema v97. NGG's curriculum (units, questions, per-member
progress, marking) ported onto MAM's `modules`. **A `modules` row (course · level
· week) IS a lesson now** — before v97 nothing recorded a student against the
syllabus at all; per-student progress lived only on cohort `lessons`, which
nothing links to a module. The rules, each a bug NGG shipped before it had it:

- **Draft → published → retired**, and only `published` reaches anyone — the
  student's board, the lesson itself and the public `/course/outline` (which now
  skips the others). Rows from before v97 migrated as `published`.
- **Publishing needs questions, and at least one a teacher marks**
  (`mam_lesson_publish_refusal()`): multiple choice and put-in-order are the
  machine's; short / task / reflection / evidence are a PERSON's, never the
  machine's — no AI marking, and nothing scores a child's playing (an `evidence`
  question takes a link to a recording). A pre-v97 week with no questions stays
  publishable when edited ("syllabus only"); its record is a teacher's TICK.
  The console's save keeps a lesson that fails only this rule as a DRAFT rather
  than throwing the typing away.
- **Answers stay hidden until a pass or `MAM_LESSON_REVEAL_AFTER` (3) tries** —
  NGG revealed them after one and the second attempt was copying. Put-in-order
  steps are a STABLE shuffle that is never the right order.
- **Completion is sticky**; the **version bumps only when what a question asks
  changes AND somebody has answered it** (a rubric reword does not), and check
  ids survive the edit so stored answers still point at their questions; an
  older-version attempt reads `stale`.
- **A started lesson is RETIRED, never deleted** — `mam_lms_delete_module()`
  throws `has_progress`; both callers turn it into a sentence.
- **A tick never overwrites online work**: an untick removes only a tick-made row
  with no attempts (`done_online` otherwise).
- **Which lessons a student has = their active/completed `course_enrollments` at
  that level.** A legacy-class-only student has none until the console's
  "converge" brings their cohort across (gotcha #2) — never guess a course from a
  class's free-text discipline.
- **A teacher acts only on students they teach on that course**
  (`mam_lesson_instructor_teaches()`: the enrolment's instructor or its cohort's),
  for marking, ticking and viewing alike; an admin reaches everyone.
- ⚠️ **"Why it is tempting" lines are keyed by option NUMBER (`2: …`), never by
  line position.** A browser drops a textarea's leading blank line, so the
  positional version moved every reason onto the wrong option, silently, on every
  re-save — measured in the first browser pass.
- Surfaces: the console's Curriculum → **Lessons** (list + bulk publish/retire,
  the editor in `_lesson-editor.php`, course management, a progress report);
  the student's **Learn** (a card per course, the lesson player — `#lesson/N`);
  the instructor's **Marking** (written answers) and **Students → a student**
  (their lessons, Tick done). `test_lessons.php`.
- **Phase 2 (v98, HANDOFF §4z2): `lessons-learn.php` + `lesson-exams.php`.**
  • **Placement is its own table, never the enrolment's level** — moving a
    child up a level of LESSONS must not move them between classes, change a
    capacity count or re-price anything. Stages: `current` (due on the
    schedule), `catchup` (a level below, never overdue), `early` (a level above,
    opened by finishing `MAM_LESSON_ADVANCE_PCT` of the one below). A lesson's
    clock starts at the LATER of enrolment and placement
    (`mam_lesson_clock_start()`), or a child moved up today is instantly overdue.
  • **Tiers** Starter → Grand Master per course (NGG's "God" renamed), a
    certificate per tier issued once; moving up a level issues a promotion one.
  • **Exams** are server-timed (deadline stored at start, + grace), pledged,
    shuffled per sitting, with spaced-recall questions from finished lessons;
    appeal ONCE within 7 days and a teacher must answer in a sentence. A lesson
    anyone sat an exam on is retired, not deleted (`has_progress`).
  • **AI marking and the study helper are OFF by default** and need a provider.
    AI marking is an ALLOW-list of written kinds — never `evidence` (a recording
    of a child playing); below 0.6 confidence it marks nothing; a mark it gave
    stays on the teacher's queue and is theirs to change (`by: 'ai'`). Words
    that read as a child at risk are HELD for a person and the DSO is told —
    never marked, never sent to a model (`mam_lesson_safeguarding_hit`).
  • **Deadlines**: reminders via the `lesson_deadlines` automation in
    `run-jobs.php`; **fines ship OFF** (`lesson_fines_enabled`) and changing
    them needs the `fees` cap as well as `curriculum` (gotcha #11). A fine is an
    ordinary `accountability_charges` row, once per lesson ever.
  • Notes are private to the student; lesson feedback is shown to staff
    WITHOUT names; teacher-sent materials are `pending` until the office
    approves, a refusal needs a reason, and the editor never deletes one it did
    not show. `test_lessons_phase2.php`.

## Member records, the roster and onboarding (HANDOFF §4w — ported from NGG)

`api/lib/members.php`, schema v94–v95. NGG's member management, ported to the
people MAM already had. The rules, each easy to "simplify" away:

- **One status setter, `mam_member_set_status()`.** Every change to whether
  somebody can sign in goes through it: a reason to suspend
  (`MAM_MEMBER_REASON_MIN`), a `member_status_log` row (who, when, why), the
  person's sessions ended at once, and two refusals — `own_account` and
  `last_super_admin`. `user_edit` refuses a role change that would do the same
  lockout (`mam_member_role_change_refusal()`). Erasure (`closed`) and
  break-glass (`system`) are written only by their own code.
- **A members list does not administer staff** (`staff_account`): an admin or
  instructor BY ANY ROLE they hold is refused by every roster action.
- **The member number is minted where an enrolment first goes active**
  (`mam_lms_transition()` → `mam_member_ensure_ref()`), beside the ID card,
  because the JE/MAM prefix depends on the programme. Highest + 1, never reissued.
- **The roster** (console Students): filters and sort ALLOW-LISTED with a
  fallback, true totals, paging, attendance in one grouped query, the band shown
  and never a sort key. Bulk actions are ONE declaration
  (`mam_member_bulk_actions()`), validated before any write, capped at
  `MAM_MEMBERS_BULK_MAX`, a verdict per row with `unchanged` kept apart, one
  `member_bulk_runs` row + one audit per run. "All N matching" is resolved on the
  server from the filter (`mam_console_students_ids()`), never from the page.
  Duplicates (same name / same phone) are LISTED, never merged.
- **No password is ever generated, typed or emailed.** `mam_ensure_student_user()`
  and `mam_parent_provision()` create accounts with `mam_auth_unusable_password()`
  and return `created`; every caller sends `mam_auth_send_invite()`. Admin create
  and reset send a link. Three paths used to make a password nobody received; two
  emailed one in plain text. `mam_email_account_created()` is deleted on purpose.
  `mam_auth_send_invite()` returns `sent` — report THAT to an operator, not
  whether a link exists.
- **Onboarding is DERIVED, never ticked** (`mam_onboarding_batch()`): signed in,
  member number, photo, guardian (under 18; unknown age counts as a child),
  consents RECORDED (a no counts), enrolled, ID card, first attendance. Money is
  deliberately not an item. The reminder tells a family only what THEY can do,
  and its quiet period reads `mail_log` rows that did not fail — a failed send
  must not block the retry.
- Not ported, deliberately: merging records, CSV import, two-person approvals.
- **The guardian on `/apply` (v96)** gives an email (`applications.parent_email`,
  required under 16), and `mam_application_link_guardian()` links and invites
  them wherever the student's account is made — payment, Complete enrolment,
  Auditer, admin paid. Add any NEW account-making path to that list (a test
  sweeps the call sites). An address equal to the student's is never linked:
  one inbox is one login, and linking it gives the child's login the guardian
  role.

## Performance marking — the audio-only control (`api/lib/marking.php`)

Built from the instructor mobile frame's **Grade entry** screen
(`docs/design/2026-08-portal-redesign/portals-mobile.dc.html`,
`data-phone="instructorv2"`). The screen's whole point is a bias control, and
it is stated on its own face: *"Video is hidden while you mark pitch, time and
tone. Seeing a performance measurably lifts the marks given for it, so the
technical criteria are heard, not watched."*

- **Modality is a property of the CRITERION, never a preference of the
  marker.** `pitch`/`time`/`tone` are marked from audio; `shape` is the one
  criterion that cannot be judged without sight, and it is sorted LAST so the
  technical marks are committed before the video is ever shown. There is
  deliberately no setting that turns this off — an instructor who could
  disable it is the instructor it exists for.
- **The server decides, and withholds rather than hides.**
  `GET /api/instructor/marking-sheet` computes `show_video` per criterion, and
  a sheet whose criteria are all technical is sent with **no artifact URL at
  all**. A client-side flag is one devtools toggle away; `display:none` on a
  `<video src>` is not a control.
- **An unknown criterion fails SAFE, to audio.** Withholding video from
  something that did not need it costs nothing; showing it for a technical
  mark is the failure being prevented. Labels are matched technical-first, the
  same ordering trap `mastery.php` documents ("Tone and shape" is technical).
- ⚠️ **Band thresholds are exact FRACTIONS, not decimals.** Written as
  `0.6667` the pass floor computed as 21 of 30 where two-thirds is 20 — a
  rounded decimal standing in for a fraction moves a band boundary, and a band
  boundary is a pass or a fail for a real child. At max 30 the table
  reproduces the design exactly: Pass 20–23, Merit 24–26, Distinction 27–30.
- **`null` is "Not assessed" and is NOT zero** — the same line the mastery
  score and the termly report hold. A real 0 is an assessment; not marking is
  not.
- **Second marking is DETERMINISTIC on the submission id**, 1 in 8. Not
  random: a marker must not be able to re-roll until it says no, a refresh
  must not change the answer, and an audit a year later has to reproduce which
  marks were double-marked from the ids alone.
- **No automatic scoring of a child's playing, anywhere.** The design's first
  refusal; a test asserts the file contains no such path. It helps a human
  mark; it never marks.

## The instructor portal v2 (`portal/instructor/`) — the 2026-09 redesign

Seven screens from the repo's own frame (`docs/design/2026-08-portal-redesign/`,
`portals-mobile.dc.html` `data-phone="instructorv2"` + the 1440 rail in
`portals-desktop.dc.html`): home · register · marking · teachbacks · report ·
students · earnings, plus Display & access. Class-only markup in
`portal/instructor/index.html`, the app in `assets/js/portal-instructor.js`,
styled by `assets/css/portal-v2.css` — the SAME design system the guardian
portal uses, extended with an `.i-*` block. **Load exactly one palette per
page.** Build notes + the open questions:
`docs/design/2026-08-portal-redesign/BUILD-NOTES-instructor.md`.

⚠️ **The legacy dashboard is NOT retired.** `portal/instructor/classic.html` is
the old 13-tab surface and still serves the six destinations the redesign has
not rebuilt (classes, sessions, assignments, gradebook, analytics, announce,
handbook, account). It is linked from the v2 More sheet as "Everything else"
and links back. (The admin's own legacy dashboard was retired outright on
2026-11-19; the portals' `classic.html` surfaces were not.) `test_portal_forms.php`,
`test_instructor_worklist.php` and `test_dashboard_nulls.php` assert against
THAT file and follow it.

Four things worth keeping:

1. ⚠️ **A screen name that validates and does not dispatch renders the wrong
   payload silently.** `report` was in `MAM_IPORTAL_SCREENS` from the start, so
   it passed the unknown-screen check in `api/routes/instructor/portal.php` and
   then fell through to `default` — the Termly report screen received the HOME
   payload. Adding a screen means adding a `case`, not just a constant.
2. **`mam_iportal_report()` READS; the POST builds.** `mam_report_build()`
   INSERTs, and a GET that creates an academic record every time a screen is
   opened fills `termly_reports` with drafts nobody asked for and burns the
   one-report-per-slot rule (`mam_report_slot_taken()`, which is PHP's on both
   engines because no UNIQUE index can state it) on a glance.
   `POST /api/instructor/report` reuses an open draft rather than building a
   second one — a blind build throws on the second press of a save button. It
   writes a DRAFT: only `mam_report_ratify()` releases anything to a family.
3. ⚠️ **`mam_marking_bands()` returns DESCENDING** — Distinction first —
   because `mam_marking_band_of()` walks it to find the first band a score
   reaches. The UI reads Pass · Merit · Distinction, so a client sorts a COPY;
   do not reorder the lib under its own lookup. And the grade screen's
   "Listen without the video" heading must follow the criterion the server
   sorted FIRST, not "does any criterion allow video" — that read "Audio and
   video" directly above the notice explaining the video was hidden, and a bias
   control that appears beside visible video reads as a malfunction.
4. **`.i-next-acts` belongs to the dark session card and fills its buttons
   white.** Reusing it as a generic button row silently overrode `.p-primary`.
   `.p-actions` is the generic two-button row.

The design's refusals are the SERVER's here and the client does not re-derive
them: the Students screen renders a band and a sentence and has no branch that
could compute a risk score; `show_video` and the artifact URL come from
`GET /api/instructor/marking-sheet` (a technical-only sheet is sent with no URL
at all); and every write goes through one helper that reports what actually
happened — never an optimistic tick, which on a register is the one thing that
cannot afford one.

## The student portal v2 (`portal/student/`) — the 2026-09 redesign

The third and last portal onto the redesign, and **the one place to read for
"how the three portals fit together"**. Five destinations from
`docs/design/2026-08-portal-redesign/HANDOFF-student-guardian.md` §2 —
**Home · Train · Learn · Attend · More** — plus Fees and Repertoire behind the
More sheet. Class-only markup in `portal/student/index.html`, the app in
`assets/js/portal-student.js`, one read provider per screen in
`api/lib/student-portal.php` (`GET /api/student/portal?screen=…`), styled by
the `.s-*` block of `assets/css/portal-v2.css`.

⚠️ **All three portals now share `portal-v2.css` and one a11y key.** Load
exactly one palette per page: `main.css`/`portal.css` are the warm dark set and
the three `classic.html` dashboards load them. The `mam-guardian-a11y` key is
deliberately shared — it is one person's preference about their own eyes, not a
per-portal setting, and a student who is also a guardian would otherwise set
larger text twice and wonder which one took.

⚠️ **The legacy dashboard is NOT retired.** `portal/student/classic.html` is
the old ~3,100-line surface and still serves every tab the redesign has not
rebuilt (courses, curriculum, assignments, grades, payments, orders, recitals,
support). Same arrangement as `portal/instructor/classic.html` and
the way the legacy admin dashboard once stood beside the console (that one was
retired on 2026-11-19); both link to each other. `test_checkin_page.php`, `test_dashboard_nulls.php`,
`test_engagement2.php` and `test_portal_forms.php` assert against THAT file.

**Four refusals are enforced in the PROVIDER, not the template**, because a
refusal a template can forget is not a refusal (`test_student_portal.php`
asserts each one at the layer the markup reads from):
no cohort effort board — the personal streak stays and `streak_private` carries
the sentence saying nobody else sees it, so a screen cannot show one without
the other; no automatic scoring of a child's playing — a take has no score
field; no intervention flag a student could be shown; and the mastery **band**
via `mam_mastery_publishable()`, never `raw_score`.

Non-obvious, and each measured in a browser rather than reasoned about:

- ⚠️ **`mam_preclass_next()` returns `{session, module, prep, teachback}`, not
  a flat row.** Reading `$pre['title']` off the top level is silently empty
  rather than an error — a card titled "Prepare for " with nothing after it.
  Same family: **`mam_accountability_list()` takes a STATUS, not a subject**
  (`_ledger()` is the per-subject reader), and **`mam_checkin_next_class()`
  returns `session_id`, not `id`.**
- ⚠️ **`mam_checkin_window()` reports a five-way `state`, not a boolean.**
  `open` is only the FIRST of the three states in which the door is open
  (`open` → `readiness_passed` → `in_progress`); testing a truthy `$w['open']`
  both misses the key and skips the two states a LATE student is most likely
  to be in. And no boundary is ever computed in the browser — the old
  dashboard hardcoded `READY = 15`, so an academy that changed the setting got
  a page that disagreed with the gate it described.
- **An imminent class outranks the next step, always.** The provider picks ONE
  next step in priority order, but a step rendered above the class it is
  subordinate to reads as a lie: "NEXT · Practise today" sat over a card saying
  the class began in eight minutes. When the step IS the check-in the hero is
  suppressed rather than shown twice.
- **The session title leads; the module is a meta line.** They are different
  strings — one session showed as "Circle of fifths" (the lesson) on the card
  and "Scales & sight-reading" (the session) in the record, which reads as two
  classes. Attend also no longer lists today's class as both a record row and a
  card, and **clocking out lives on the card** as a result: without it a
  student can check in and never record leaving, and no duration is written.
- **`mam_practice_stats()` measures two different weeks.** `weekMin` is a
  ROLLING seven days, `weekDays` is the calendar Monday–Sunday. Labelling the
  figure "this week" above the strip printed "1h 10m this week" over seven
  empty squares on a Monday. Don't relabel one to match the other — say what
  each measures.
- **The unevidenced competencies are named rows with ONE explanation.** §2.2
  requires them visible; repeating the sentence under each of eight made the
  card 1,180px tall at 390 and turned the names — the actual information — into
  captions on a wall of identical prose.

### The shared layer, and three traps in it

These are in the `.p-*` block and bite **all three** portals:

- ⚠️ **`--ink` INVERTS in dark mode** (#1B2430 → #E8ECF2, so a "dark panel"
  becomes a light one) and a hardcoded `#fff` on it does not. Use the
  **`--on-ink` / `--on-ink-2` / `--on-ink-line`** pair, and note
  **`--accent-on-ink` inverts too** — the light-mode tint `#F7C08A` measures
  **1.38:1** on the reversed panel, which is what made the instructor session
  card's eyebrow very nearly invisible. The pairing is why none of these rules
  needs a per-rule dark override.
- **`.p-row` wraps, and `.p-row-main` keeps a 55% basis.** MEASURED at 320 on
  the instructor marking queue: a 100px "2nd marker" chip beside a 52px avatar
  left the name column 56px wide and the caption spilled out of it — an
  annotation crushing the thing it annotates. Above ~360 nothing moves.
- **`.p-crit` is capped at 560px on a desktop** and `.p-who-main` stacks name
  over caption. A bar run to 1,110px makes 100% and 96% the same picture, and
  in the 248px rail the name and course ran together so "TONI-26A" broke after
  "TONI-".
- ⚠️ **No inline styling, anywhere — and that includes from JavaScript.** A
  `style=` attribute in markup is the obvious half; a script assigning
  `el.style.width` (or `setProperty`) after render is the same attribute
  arriving later, and it was how both the student and guardian portals drew a
  competency bar. Bar widths are a **`[data-pct="N"]` table in portal-v2.css**,
  one rule per whole percent, with `.p-bar > span { width: 0 }` as the base so
  an unmatched bar draws EMPTY rather than full. Round to an integer before
  putting the value in the attribute — a fractional `data-pct` matches no rule.
  One integer step is finer than a bar renders (1% is 5.6px at the 560px
  desktop cap), so the table costs nothing. `test_student_portal_ui.php` sweeps
  all three portals for both routes in one group; the two `classic.html`
  dashboards are exempt as a matter of SCOPE (hundreds of pre-redesign
  attributes each, retired rather than rewritten), and a test pins the one
  thing added to them — the back-strip — as a class.
- ⚠️ **`--gold-bright` is a FILL, not a text colour.** `portal.css` says so in
  its own light-theme block and gives the exact figure: 2.94:1 on
  `--bg-surface`, against WCAG 1.4.3's 4.5:1. The back-strip's link landed on
  precisely that the moment it became theme-aware, because the hardcoded
  version it replaced had pinned both colours and got 10:1 by accident.
  **`--gold-text`** (5.37:1 light, 8.53:1 dark) is the one for words. The same
  fill/text split exists for `--emerald`, `--red` and `--warn`.

⚠️ **Bump `portal-v2.css?v=` on ALL THREE pages when you touch it**, or
browsers keep running the copy with the bug in it.

### The accessibility contract (audited 2026-09-21 — `docs/HANDOFF.md` §4s)

Every one of these was MEASURED in a browser and none would fail a static
read, which is why `test_student_portal_ui.php` pins the mechanism rather
than the symptom:

- ⚠️ **`role="dialog" aria-modal="true"` is a promise.** It tells a screen
  reader the rest of the page is inert, so the sheet has to move focus in,
  trap Tab, close on Escape and return focus to its trigger. Two portals
  declared it and did none of it while the guardian's complete
  implementation sat 170 lines away. Same family as the `role="menu"` the
  site chrome removed for promising an arrow-key contract it never had:
  drop the role or honour it, never leave it half.
- ⚠️ **Focus moves on the PAINT, not on the navigation.** Every screen
  fetches, so `go()` returns while `#main` still holds the previous screen —
  focusing there finds the old heading and the repaint destroys it, dropping
  focus to `<body>` so the next Tab restarts at the skip link. `go()` arms
  `pendingFocus`; the next `paint()` consumes it, **once**, because `paint()`
  also runs after a write and pulling focus would throw the reader out of
  the card they are filling in.
- **16px is a FLOOR on every form control, `select` included.** Safari on
  iOS auto-zooms below it and does not zoom back out. Never "fix" that with
  `user-scalable=no` — blocking pinch zoom is a 1.4.4 failure.
- ⚠️ **`viewport-fit=cover` is load-bearing.** `env(safe-area-inset-bottom)`
  is 0 without it, and the stylesheet reads it in four places (bar height and
  padding, sheet foot, toast offset). All three pages must carry it or one
  portal letterboxes where its siblings go edge to edge.
- **An ERROR is not a polite status.** `role="status"` is queued until the
  screen reader is idle; a failure is `alert`/assertive and **does not time
  out**, because it is often the only record that the thing the reader
  pressed did not happen. Successes still clear themselves.
- **`.p-badge` is a DOT on mobile and a pill on desktop.** Writing a count
  into the 8px dot rendered it at **1.04:1** — it sets no `color` of its own.
  The number goes in the `aria-label`; the mobile rule paints no text at all.
- **A loading state is DEFERRED by 300ms, never dropped.** MEASURED: screens
  repaint in 8–48ms locally (a two-frame flicker) and in 480–541ms on Slow
  3G, where the indicator appears at ~315ms and is genuinely needed.
- **`--gold-bright`, `--good`, `--accent` and friends are FILLS.** Each has a
  text-safe sibling (`--gold-text`, `--good-text`, `--accent-text`); using a
  fill for words is how `.s-dot` reached 4.31:1 and the back-strip 2.94:1.
  ⚠️ And the reverse: a SOLID state that carries a word needs
  **`--fill-good` / `--fill-warn` / `--fill-bad` with `--on-fill`**, not a
  text or bar token borrowed for the job. MEASURED with white on them,
  `--good` is 4.36:1 and `--warn-bar` 3.92:1 — so the register's Present and
  Late marks both failed while Absent passed, from one ad-hoc pick each. The
  trio is chosen against its foreground (6.27 / 5.10 / 4.82 light,
  6.01 / 6.44 / 5.66 dark) and the pair INVERTS in dark mode, where the fills
  are light and the ink is dark.
- **`prefers-reduced-motion` must not stop a progress indicator** — 2.3.3
  exempts it, and a frozen spinner over "Loading" says the page has hung.
- **`.p-none` / `.p-none-list` is SHARED, not the student's.** The student's
  "Where I stand" and the guardian's termly report show the same competencies
  to the same family, so the unevidenced ones are one named set with the
  caveat stated ONCE. ⚠️ MEASURED on the guardian report: eight rows each
  repeating "First assessed next term. Not counted as a zero." filled 563px
  of a 1,788px page. §2.2 requires those competencies visible and named; it
  does not require the caveat eight times.
- ⚠️ **Guard the meaning, not the copy.** `test_dashboard_nulls.php` pinned
  the literal sentence "Not counted as a zero." and failed the moment the
  wording improved. Assert the phrase that carries the promise
  (`counted as a zero`) plus the structure (`p-none-list`), or the test
  blocks the next improvement instead of the next regression.

## The instructor application v2 (`/instructor-apply`) — the 2026-09 rework

Seven steps → four, from `docs/design/2026-09-instructor-application/`
(handoff + `BUILD-NOTES.md`, which records the four open questions as built and
the six defects that only appeared in a browser). Courses first then levels,
baseline inputs instead of filled boxes, a day-stacked week, referee 2 folded.
`instructor-apply.php` + `assets/js/instructor-apply.js` + the `.fac-*` block of
`assets/css/apply.css` (shared with the student wizard on purpose — anything
general goes in `.step`/`.field`/`.btn-step`). Guarded by
`test_instructor_apply.php`, one assertion per handoff invariant.

Three things here are **repo-wide**, not page-specific:

1. ⚠️ **`html.js .js-only { display: revert }` (apply.css) outranks `[hidden]`,
   and ties with a bare class.** `[hidden]{display:none}` is a UA rule, so
   `revert` beats it — the gate sat on "Loading the application…" for a whole
   session with `hidden` set and doing nothing. And `html.js .js-only` is
   specificity (0,2,1), the same as `html.js .fac-appbar`, so **source order**
   decided it: the app bar rendered `display:block`, its two links stacked, and
   the bar grew 48px → 76px. `html.js .js-only[hidden] { display: none }` fixes
   the whole class at (0,3,1); anything both `.js-only` AND a flex container has
   to restate its display **after** that rule. This is the fourth appearance of
   the `[hidden]` trap in this one file (`.resume-banner`, `.apply-stepper`,
   `.hs-status`, `.advisor` are the others) — **any** author `display:` on an
   element you toggle with `hidden` needs its own guard.
2. ⚠️ **`main.js`'s floating-CTA hide-list matches the WHOLE slug**, so `'apply'`
   does not cover `'instructor-apply'` — a prospective *teacher* was shown the
   *student* pill, and once this form grew a bottom action bar the pill sat on
   top of Submit. Exactly the blind spot `lead.js` has (it path-tests `/apply`),
   which is why the page carries `data-no-lead-cta data-no-lead-exit`. Both
   lists need the slug named explicitly; a new transactional page needs adding
   to both.
3. **A fixed bottom bar must ride above `window.mamDock`, never at `bottom:0`.**
   The dock is the column-reverse stack the cookie banner lives in at
   `z-index:950` — above a form control **deliberately**, because a consent
   notice must not be covered. The banner's Accept button landed directly over
   Submit and the form could not be sent on a first visit. `trackDock()` keeps
   `--fac-dock-h` equal to the dock's real height (it depends on how the
   banner's sentence wraps, which depends on the accessibility toolbar's text
   size, so it cannot be a constant) and measures an EMPTY dock as 0. Same
   collision the redesign's floating Apply pill hit, and the reason that pill
   had to join the dock rather than sit beside it.

Contract points the rework deliberately did NOT move: **highest-level-only is
presentation** — `collectSubjects()` expands the chosen index to the full 0..N
set, so `subjects[{course,level,years}]` and the `UNIQUE(course, level)` index
on `instructor_assignments` are untouched; the **one "Email or phone" field**
routes back into the `email`/`phone` keys `mam_faculty_normalise_referees()`
reads (an address to `email`, anything else to `phone` — the same split
`FILTER_VALIDATE_EMAIL` would make); and the **availability key stays
`'mon:morning'`**, so an old draft and the stored JSON both still load.
`loadDraft()` MIGRATES a seven-step draft rather than discarding it.
⚠️ The consent wording is the **approved** wording, not the handoff's trim —
the handoff's own open question 4 asks the DSO to re-approve it, and the draft
drops "I have read and" and widens "any safeguarding concern" to "any concern".
The layout is the handoff's; only the two strings wait. A test pins both
clauses so the swap is deliberate.

## The site chrome — the 2026-09 rework

Header, menu and footer, from `docs/design/2026-09-site-chrome/` (handoff +
`BUILD-NOTES-chrome.md`, which records the measured numbers, the four places
the handoff contradicts itself, and the three defects that only appeared in a
browser). `partials/header.html` + `partials/footer.html` + the nav/footer/
cookie blocks of `assets/css/main.css` + `initNav`/`initAnnounce`/
`initFooterGroups` in `assets/js/main.js`. Guarded by
`api/tests/test_site_chrome.php`. **Every page on the site wears this**, so
the traps below are repo-wide:

- ⚠️ **`--nav-h` is the ONE lever** — 56px, 64px from `min-width:1025px`.
  `#hero`'s `min-height`, `html{scroll-padding-top}` and
  `[id]{scroll-margin-top}` all derive from it. The old
  `@media (max-width:768px){--nav-h:64px}` override is gone; don't re-add a
  second source.
- ⚠️ **`.announce[hidden]{display:none}` is load-bearing** — the FIFTH
  appearance of this trap here. A class-level `display:flex` outranks the UA's
  `[hidden]{display:none}`, so **any** element you toggle with `hidden` that
  also carries an author `display:` needs its own guard. (`apply.css`
  documents the other four: `.js-only`, `.resume-banner`, `.apply-stepper`,
  `.hs-status`/`.advisor`.)
- **`--hairline` (0.16) is NOT `--border` (0.20).** The chrome's dividers are
  hairlines between rows, not card edges. Both lift under
  `[data-contrast="high"]`.
- **The announce dismissal is keyed to the MESSAGE**, as a djb2 digest of the
  rendered text, so a new campaign reappears for somebody who closed the last
  one. `MAM_CONFIG.intake` can arrive late from `/api/config/public`, so
  `initAnnounce` re-stamps `[data-intake-label]` on `mam:config` and
  re-evaluates — otherwise the key is a digest of JS's default label.
- **The strip sits BELOW the bar** (a sibling after `</nav>`) so dismissing it
  never moves the bar, and it is ONE line with `text-overflow:ellipsis` — it
  used to wrap to two at 390px and then scroll away for good.
- **The menu is `inset:0` and carries its OWN 56px head.** `positionMenu()`
  is deleted: it offset the panel to the navbar's measured bottom, which moved
  with the announce strip, and stacking the bar above a panel that has its own
  head shows two heads. The bar's hamburger and the head's ✕ are two halves of
  one toggle.
- **A chevron opens a sub-panel; an arrow navigates away.** Four rows and
  three rows respectively. The distinction is what stops a menu becoming a
  lucky dip, so only the chevron rotates.
- ⚠️ **On touch the back-to-top FAB and the floating Apply pill do not
  exist.** MEASURED on first visit at 390×700: pill + `lead.js`'s CTA bar +
  cookie banner was 304px of a 700px screen, and back-to-top was never
  enrolled in `window.mamDock` at all (fixed, z-index 901 against the dock's
  950), so it overlapped the stack and was painted over. Both return at
  `min-width:1025px`. **`.btn-nav-apply` in the bar is what makes that
  honest** — the design's own justification ("the bar already carries Apply")
  is false of its own frame, and without it touch loses every persistent Apply
  path once the strip and the CTA bar are dismissed (the bar remembers for 30
  days). Don't delete it to get back to "exactly two icon buttons".
- **The mega menus lost the photographic spotlight** (`.mega-spotlight
  {display:none}`) — the widest element in the chrome and the slowest thing on
  the page to paint, for a decorative image behind a menu. With it gone the
  2-column collapse below 1280px is deleted too: it existed only because three
  columns PLUS a 1.15fr photo panel needed 1080px. Column counts are now
  stated per variant (`mega-2col` / `mega-3col`), not inherited from a 3-track
  default with a fourth child wrapping underneath.
- **The footer's four groups are `<details>`** — they collapse and expand with
  no JS at any width. `initFooterGroups` only forces them open where they are
  *columns* (≥1025px) and closes them on the way back down, and
  `display:contents` drops the four straight into the desktop grid. The count
  beside each title is a promise: a test asserts it against the links actually
  inside it.
- **The footer wordmark is plain text with NO parallax.** The clip-path reveal
  keyed progress off the mark's own height — right for a full-bleed 190px SVG
  `<text>`, a snap for a 34px span, and MEASURED it left the mark fully
  clipped at every scroll position short of the page's exact bottom. Deleting
  it also drops a scroll listener and a `will-change:clip-path` layer from
  every page. `white-space:nowrap` on `.footer-logo-name`, because at 21px in
  a 196px column the Bebas *fallback* breaks MAM / ACADEMY onto two lines.
- The **cookie banner is a row that WRAPS on the text's measure**, not on a
  breakpoint (`flex-wrap:wrap` + `.cookie-text{flex:1 1 280px}`). MEASURED at
  320 with the actions `flex-shrink:0` and no wrap: the two buttons took 142px
  of a 296px banner and squeezed the sentence into a 108px column SIX lines
  deep — 141px of banner, 22% of a 700px screen. A smaller basis (180px) kept
  the row alive down to ~390 and made it non-monotonic: **430 rendered shorter
  than 390**, because it was still a row with a cramped three-line column. The
  copy is what decides the wrap point, so a longer sentence or a translation
  moves it; a hardcoded breakpoint would not.
  ⚠️ **The notice is in `rem` and its basis must be too.** The accessibility
  toolbar scales the ROOT font (`main.js` sets
  `documentElement.style.fontSize`), so a `px` consent notice refuses to grow
  for somebody who asked for bigger text — and a `px` basis beside `rem` text
  is worse than either: it goes on promising two lines at a width where the
  larger type needs three, which is the cramped-column bug the basis exists to
  prevent. The button LABELS scale too (a pinned 13.5px button beside 16.25px
  body copy at 1.3× reads as broken), but `min-height:44px` stays **px** — a
  touch target is a property of the finger, not of the type, and a rem floor
  computes to 37px at 0.85×. This banner is the one place in the design system
  that is not px; the rest is, deliberately.
  `#cookieDecline` **survives**: whether a consent notice offers a decline
  control is a legal decision, not a layout one, so it is never the thing that
  gives way. The handoff's "Decline stays available inside Details" was not
  taken.

## The student application v2 (`/apply`) — the 2026-09 rework

Part 2 of the same handoff (`BUILD-NOTES-apply.md`). Step 1 asked seven
questions and showed 38 choice cards on one ~4,600px scroll, with level and
expression appearing and disappearing mid-answer so the scroll length changed
under the applicant. Each question is its own screen now — **twelve `.a-sub`
screens across the five steps** — and on a desktop they fold back onto one page
beside a 320px summary column. `apply.php` + `assets/js/apply.js` + the `.a-*`
block of `assets/css/apply.css` (shared with the instructor form on purpose).
Guarded by `test_apply_wizard.php`; `test_apply_form.php` keeps the invariants
about the `<form>` element itself.

- ⚠️ **The focus area was NOT merged into the major, deliberately.** The
  handoff asks for "one searchable list of 14 rows" replacing both, on the
  reading that they ask "two versions of what will you study". They do not:
  `discipline` is an INSTRUMENT (gotcha #3 records `mam_enrol_resolve()`
  refusing `violin`/`clarinet` for exactly that reason) and `program` is a
  CAREER FOCUS. There is no mapping from the eleven onto the fourteen, and
  `applications.program` is a stored column `mam_validate_application()`
  rejects a submission without. Both survive as screens, in the same searchable
  row treatment; the handoff's own open question 5 asks the same thing. Step 1
  is therefore SEVEN screens, not six.
- **A screen that does not apply is `hidden`, not merely inactive**, and
  navigation walks `liveSubs()`. Skipping has to work in REVERSE: back from the
  major screen with `crash` chosen must land on programme, not on an empty
  level screen. `skipSub()` is driven by the same predicates that drove
  `substepLevel[hidden]` before, plus the guardian screen for an adult.
  `.a-sub[hidden]{display:none!important}` because a desktop shows the whole
  step at once.
- ⚠️ **`.a-sub{padding:0}` is load-bearing.** Each screen is a `<section>`, and
  `main.css` gives EVERY `<section>` on the site
  `padding:clamp(56px,8vw,96px) clamp(16px,4vw,24px)` — MEASURED at 1280, that
  put 96px of dead space above every question and inset it 24px from its own
  column. Any block reusing a sectioning element inherits that rule.
- ⚠️ **The `.js-only` specificity trap, twice more** (numbers six and seven in
  `apply.css`). `html.js .js-only{display:revert}` is (0,2,1) and beat a bare
  `.a-prog{display:none}` inside a media query, so the progress line stayed on
  at 1280; and a bare `.a-appbar{display:flex}` is (0,1,0) — the same as
  `.js-only{display:none}` — so SOURCE ORDER un-hid the whole wizard chrome for
  a visitor with no JS. Rules that fight `.js-only` must carry `html.js`, and a
  `.js-only` element's `display` belongs only on the `html.js` rule.
- ⚠️ **The action bar is FIXED, not sticky.** `position:sticky;bottom:0` holds
  an element up only while there is content after it inside its containing
  block, and the bar is the form's last child — MEASURED at 390 its top was at
  y=1444 in an 844px viewport. It rides above `--a-dock-h` (`window.mamDock`
  is z-index:950 on purpose), measured the same way `--fac-dock-h` is.
- **The progress bar never runs backwards.** `BANDS` gives step 1 the 0–40%
  band and 20% to each of the rest; until a programme is chosen `subTotal()`
  assumes the LONGEST path and the counter says "Question 2", not "Question 2
  of 4". Computing the denominator from the live set made the bar run
  9% → 18% → **15%** the moment Certified Musician added three screens.
- **Validation is one rule per QUESTION** (`QRULES`), and `validateStep()` runs
  every rule for the step's live screens — which a desktop fold-back and a
  server-side rejection both need. `SERVER_FIELDS` carries a `sub` so a
  rejected field opens the screen it lives on.
- **The review states the price BEFORE the consents**, each row carries its own
  Edit naming the question, and values are read off the row the applicant
  clicked (`drums` is "Drums & Percussion" on screen). The typed signature and
  its date stay — a legal artefact, not chrome. The policy excerpt collapses
  behind the consent it belongs to; the full version is emailed on submit.
- **The headshot chain is untouched** (four failure points, all four of which
  have failed in production). The one change: a failed check now OPENS the
  disclosure the widget sits behind, because an error written into a collapsed
  `<details>` is an error nobody reads.
- The action button on the review reads **"Continue to payment"**, not the
  design's "Pay $5": it submits the application and opens the payment step, it
  does not take a payment.

## The admin console (`admin/console.php`) — the 2026-08 redesign

Thirty-one screens, built 2026-08-20 and **re-navigated 2026-09-17** when the
design package finally reached the repo (`docs/design/2026-08-portal-redesign/`).

⚠️ **The IA is TWO levels: nine rail areas, each holding its sections.** The
original build was a flat rail of all 31 grouped four ways — and the handoff
names exactly that as the *superseded* pass: "the legacy 40-view flat sidebar
was unscannable; the first redesign pass made it 31 items grouped four ways,
which was still too long to scan on every navigation." The rail is re-scanned
on every navigation, so its length is paid repeatedly; nine is the stated
ceiling. Breadth lives in a section strip under the page title, and ⌘K (which
already existed) is what makes demoting 22 destinations to a second level safe.
Four decisions to preserve, each with its reason in `mam_console_nav()`:
Reports is its OWN area (daily work, not quarterly); no area overview pages (a
rail click lands on the area's first *visible* section); the rail highlight
follows the AREA not the screen (`mam_console_area_of`, so Attendance keeps
Teaching lit); and the three navigation levels keep three different shapes —
do not unify the rail, the strip and in-screen pill filters. Two more, held by
`test_console.php` since 2026-11-20: **six is the widest strip** (the test
checked eight, so Finance and Governance drifted to seven each — staff pay
moved to People, Communications and Support to Students & families), and **a
section's label is its page's title** (six screens were titled differently
from the entry that opened them). Moving a section between areas changes no
access: `cap` is per section and travels with it.
⚠️ The strip is a `<nav>`, deliberately **not** `role="tablist"` — it navigates
between separate screens rather than toggling panels, so tab semantics would
promise arrow-key movement that does not exist. **The legacy dashboard is RETIRED** (2026-11-19): `admin/index.php` serves no
screens, but it still holds — this is the load-bearing part — **every POST
handler the console uses**.

### The legacy admin dashboard is retired (2026-11-19)

The owner retired it outright, knowing what that cost, rather than phasing it
out. Measured before the cut: admin/index.php handled **116** kinds of action and
the console posted only **30**; the other **86** had their only button on a
legacy screen and have **no button now** until each is rebuilt in the console.
They are listed in `docs/HANDOFF.md` §4y — money (refunds, confirming bank
payments, instructor earnings), configuration (settings, programmes and courses,
term dates), compliance (the data-protection register, erasure, recording a
vetting check), recitals, rubrics, enrolments and classes, creating users and
admin tiers, the faculty pipeline. **The handlers are all still there** — a
rebuilt console screen posts to the existing action; it never grows a second one.
- Old `?v=<view>` names in the history above name retired screens. A GET to one
  redirects to the console screen that now covers it (the map is at the foot of
  admin/index.php), carrying `?ok=`/`?err=`.
- What the console needed and gained in the same change: **My account**
  (`?v=account`: details, password, devices — the temporary-password gate sends
  people there, so it must exist and needs no capability) and a real **Run
  migrations** button (`run_migrations`, `migrate` cap) on System health; the
  console's link for it had pointed at a view that never existed.
- 42 console links that opened a legacy screen were removed rather than left as
  dead buttons. `test_admin_retired.php` fails on a new one, on a new view
  branch, and on a redirect target that is not a console screen.

```
admin/console.php               the shell: rail, topbar, ⌘K, flash, screen dispatch
assets/css/admin-v2.css         the design system, scoped to [data-admin="v2"]
assets/js/admin-console.js      progressive enhancement ONLY — nothing depends on it
api/lib/console.php             one read provider per screen (mam_console_<screen>())
partials/admin-console/*.php    one renderer per screen, + _ui.php helpers (ac_*)
api/routes/admin/search.php     the palette's lookup
api/tests/test_console.php      the guards below
```

- **Reads go through `mam_console_rows()` / `_scalar()` / `_try()`, which RECORD
  a failure instead of swallowing it.** `mam_console_failures()` is a
  per-request register, and the page prints it — with the database's own words —
  at the bottom of the screen. This exists because of gotcha #9: a PDO
  exception in a legacy view is caught, logged, and renders as *nothing*, so a
  bad column silently empties a table and the screen still looks fine. That
  register is also what makes the test strong — it calls every provider and
  asserts the register is empty, which caught **eight** bad column names and
  **three** status filters naming values the CHECK constraints forbid (an
  always-zero badge nobody would ever have seen). Add a provider → add it to
  `$CN_providers` in the test, which asserts every screen is covered.
- ⚠️ **The DOCUMENT scrolls, not `.ac-scroll`.** The rail, top bar and section
  strip are `position:sticky` against the page; making `.ac-scroll` an overflow
  container again (`overflow:auto`) silently stops the strip sticking, because
  a sticky element sticks to its nearest scroll container and that one never
  scrolls. Sections get their 16px from `.ac-scroll > * + *`, not per screen.
- **Writes stay in `admin/index.php`.** Every action form posts to `/admin/`, so
  there is one capability table, one audit call and one implementation of
  "approve"/"waive"/"enrol". `_console=<screen>` on the form tells
  `mam_admin_console_return()` (defined **above the first `$view ===` branch** —
  gotcha #9 again, and `test_admin_screens.php` enforces it) to rewrite the
  handler's `Location` header at **shutdown**: each handler exits having set a
  header and no body, so it is still mutable there. That is one hook instead of
  threading a parameter through ~140 branches. It **validates the screen against
  `mam_console_screens()`**, not a character class — `../evil?x=1` sanitised to
  `evilx` is safe but lands an admin nowhere, and a name the console does not
  know means the POST was not from the console.
- **No partial carries a `style=` attribute.** The stylesheet says so about
  itself and the test enforces it; the one exception is `--ac-fill` on a meter,
  which is a *value*, not a design decision. The test also rejects a duplicated
  `class` attribute — a bulk edit produced 38 of them, and a browser keeps the
  first and drops the second.
- **A status filter must name a value the schema admits.** The test derives the
  legal set from the live CHECK constraints. Note `fee_invoices` has **no
  `overdue` status** — overdue is DERIVED from `due_on` on read, and
  `bursary_applications` uses `assessing`, not `review`.
- **`mam_console_ngn($cents, $rate)`**: `null` means "this figure has no rate of
  its own, use today's and label it an estimate"; a **rate ≤ 0 is a corrupt row
  and returns a dash**, never today's rate — substituting would print a
  plausible naira figure against a payment never taken at it.
  `mam_console_usd_short()` compares against `floor()` **loosely**, because
  `$cents / 100` is a float even when it divides exactly and a strict comparison
  makes every KPI tile grow two decimals.

### The console on a phone (2026-09-28)

Two halves, and they answer different questions.

**Every console table stacks below 720px.** MEASURED at 390px before this: 23
of the 31 screens scrolled a table sideways and the vetting matrix hid 918px
against 390 visible — more of the row off the screen than on it, with nothing
to say so, while `admin/index.php` had stacked its tables since its own
redesign. `ac_label_table_cells()` (in `_ui.php`, run over the buffered screen
in `console.php`) stamps each body cell with its column's header.
- **Derived, never hand-written.** Not only to avoid 229 attributes:
  `vetting.php` emits one column per required clearance in a `foreach`, so its
  headers do not exist until the row is rendered. Deriving also stops the label
  drifting from the header.
- ⚠️ **It sets explicit ARIA roles, and they are load-bearing.** `display:block`
  on a table element DROPS its implicit role, so the legacy `table.stack`
  pattern hands a screen reader an unstructured run of text at exactly the
  width where the visual structure has gone. The roles are what make the
  stacking rule safe.
- A header spanning columns labels the FIRST and leaves the rest empty (the map
  advances by colspan, never by array position), and a cell spanning the whole
  table is a message, not a value, so the empty state is left alone.
- The label sits BESIDE the value (54% of the 677 body cells are bare text),
  and ABOVE it for a cell holding a block — otherwise its second line starts at
  the left margin and reads as a separate unlabelled field.
- `test_console.php` strips the added attributes back off and compares against
  a bare DOM round-trip: the helper may add attributes and change nothing else.

**`admin/phone.php` is the frame's `#5b` subset** — Decide · Today · Assign ·
Look up, with `api/lib/console-phone.php` for the reads, `partials/admin-phone/`
for the renderers and the `.ap-*` block of `admin-v2.css`. Linked from the
console rail; links back.
- ⚠️ **It is a separate entry, not a breakpoint, and that is the whole point.**
  Thirty-one screens behind a media query are still thirty-one screens. The
  frame's argument is about consequence, not layout: *"Amending a record,
  voiding an invoice and closing a period are deliberately unavailable here. A
  consequential, hard-to-reverse action taken one-handed on a bus is how a
  ledger acquires a mystery."* The desktop console stays reachable on a phone.
- **The refusal is `MAM_PHONE_REFUSED_ACTIONS`, in the read layer.** Every
  phone write posts to `/admin/` like the desktop console's, so the only thing
  keeping a consequential action off this surface is which action names its
  files mention — which makes it greppable, and `test_console_phone.php` greps
  every phone file for each one. Nobody can eyeball four renderers and be sure
  none grew a delete button.
- **`_console=phone:<panel>`** extends `mam_admin_console_return()` rather than
  adding a second hook, and is VALIDATED against `mam_phone_panel_ok()` exactly
  as a console screen is validated against `mam_console_screens()`.
- **Assign lists what is refused, with the reason.** A list that silently drops
  the ineligible teaches a registrar the class does not exist. A full cohort
  offers the waitlist instead.
- ⚠️ **The frame contradicts itself:** its caption says "Three destinations"
  while its own tab bar and the handoff both say four. Four is right; a test
  pins it so nobody "corrects" the code to the caption.
- **Not faked:** the frame's WhatsApp row is absent — there is no messaging
  provider configured, and a dead button is refused here as elsewhere. The room
  clash IS real (`class_sessions.room_id` and `rooms` both exist). Today
  resolves its date through `mam_calendar_today()`, never the server clock.
- Controls are 52px here, the one place this surface departs from the console's
  own scale: the desk's density is wrong for a thumb.

## Running the site against MySQL, and what only MySQL finds

The suite ran on SQLite alone for its whole life. `api/tests/run.php` now reads
`DB_DSN` / `DB_USERNAME` / `DB_PASSWORD` from the environment and skips the 14
files that introspect `sqlite_master` or `PRAGMA`:

✅ **CI runs this now** — the `mysql` job in `.github/workflows/ci.yml`, against
**MariaDB 10.11**. Being able to run it by hand was not the same as running it:
every one of the eight defects below passed a green SQLite build, so the
production engine was unexercised before a deploy. The job also asserts the
**table count is ≥100** after the import, because `--force` swallows errors and
a stopped import leaves exactly **18** tables while the build stays green — the
failure then looks like a code regression. MariaDB and not MySQL 8 deliberately:
`db/schema-mysql.sql` uses `ADD COLUMN IF NOT EXISTS`, which is MariaDB syntax
(the file says so in two places), so covering MySQL 8 means making the schema
portable first. The PHP matrix now includes **8.4**, which is what this repo is
developed on and what `fees.php`'s money arithmetic was measured against.


```bash
DB_DSN='mysql:host=127.0.0.1;dbname=mam_suite;charset=utf8mb4' \
  DB_USERNAME=… DB_PASSWORD=… php api/tests/run.php
```

⚠️ Apply `db/schema-mysql.sql` with **`mysql --force`**: the file re-runs its own
migration blocks, so ~36 "duplicate column" errors on a fresh database are
expected and everything after them still applies. Without `--force` you get 18
tables instead of 129. Use a **fresh database** each run — the suite leaves its
fixtures behind, so a second run fails on a duplicate email.

Eight defects existed **only** on MySQL, and each was invisible to a green
SQLite suite:

1. **`users.role` / `user_roles.role` were `ENUM` without `'parent'`.** Under
   `STRICT_ALL_TABLES` that is error 1265, so no guardian account could be
   created on any MySQL host — the whole parent portal was unreachable. Both are
   `VARCHAR(20)` now (matching the SQLite authority, which enumerates nothing),
   via the v89 migration.
2. **`mailer.php` wrote `date('c')` into three DATETIME columns.** The ISO-8601
   offset is refused, the catch sets `$disabled`, and every `mail_log` row for
   the rest of the request is dropped silently.
3. **`termly_reports.fk_treport_ratby` had `ON DELETE SET NULL` on a
   CHECK-referenced column** — MariaDB error 1901 — which took four tables
   (`termly_reports`, `termly_report_criteria`, `report_moderations`,
   `mark_appeals`) out of the schema entirely.
4. **`ESCAPE '\'`** is one backslash in the SQL text. SQLite accepts it;
   MariaDB reads it as escaping the closing quote and the statement is a syntax
   error. Use an escape character that is not itself an escape (`!`).
5. **`||` is OR in MySQL, not concatenation.** Compose names in PHP.
6. **MySQL DDL causes an implicit COMMIT**, so a test that DROPs a table inside
   a transaction cannot roll it back — it leaves the table gone for every later
   test. Rename it aside and back instead.
8. **Half the tables had no collation**, so they took the server's default
   (`utf8mb4_general_ci` on MariaDB, `utf8mb4_0900_ai_ci` on MySQL 8) while
   the rest said `utf8mb4_unicode_ci`. Comparing text across the two is error
   1267, and a console read catches it and draws an EMPTY panel — the
   invoice's online payments and the refund owed on one showed nothing on
   every MySQL install. Every `CREATE TABLE` must end
   `COLLATE=utf8mb4_unicode_ci` (`test_schema_collation.php` fails otherwise);
   v105 converts the tables that already exist.
7. **MySQL's `INT` coerces a fractional value; SQLite's INTEGER is an
   AFFINITY** and stores it verbatim. Two `test_reports.php` assertions state
   both engines: the CHECK refuses on one, the column coerces on the other, and
   the guarantee — what comes back out is a whole number — is the same.

**A green suite is not a working site.** The route sweep that followed
(every page, every clean URL, all 31 console screens, all 40 legacy views,
every GET API route, then `api/logs` read for what a 200 hides) found three
more: `recitals.php`, `id-cards.php` and `session-remind.php` still called
`mam_require_user()` inside a body already gated by `mw_admin_cap()`, which
401s the Basic-Auth break-glass operator. `recitals` did it unconditionally, so
during a recovery nobody could even read the programme. Use
**`mam_admin_require_actor()`**; `test_console.php` now asserts the class.

## Backups — the app WATCHES, the cron takes them

`api/tools/export.php` already wrote a lossless, checksummed, verifiable
snapshot long before any of this: it is the ETL rollback artifact and it is
good. What was missing was everything around it — **nothing ran it**,
DEPLOYMENT.md never said the words backup or restore, the uploaded files were
in no archive, and nothing deleted an old one.

- ⚠️ **`api/lib/backup.php` does NOT take the backup, deliberately.** The
  obvious design shells out to export.php; it is wrong twice. Shared hosting
  commonly disables `exec`/`proc_open`, so the backup would never happen and
  the failure would be invisible — the exact disease. And export.php is
  **standalone on purpose** (it loads no application code because `mam_db()`
  runs the migrator, and a pre-migration safety net must never migrate), so
  pulling it into a bootstrapped process destroys the property that makes it
  trustworthy. The cron runs it directly (DEPLOYMENT.md §7) and the lib answers
  the question that actually prevents the classic failure: **is a backup still
  happening?** A job that stopped eleven months ago looks exactly like one that
  works, until somebody needs it.
- **A snapshot is dated by its DIRECTORY NAME, never mtime** — the same rule
  the log sweep holds. A restore or an rsync rewrites every mtime and would
  report a year of dead backups as healthy.
- **A directory with no `manifest.json` died mid-write and is NOT a backup.**
  It reports as `incomplete`; the status still says `none`. That is the one
  broken state which otherwise looks exactly like success.
- **No uploaded files is `partial`, not "ok with a note".** `--with-uploads` is
  not optional: without it a restore returns with every headshot and every
  recording of a child missing, and those cannot be re-derived from any table.
- ⚠️ **The signing key is NOT in the archive and must not be** — that turns a
  data backup into a credential store. But a restore needs it: `mam_card_sign()`
  derives a printed ID card's HMAC from it. So the manifest carries a
  **fingerprint** (`mam_backup_key_fingerprint()`), and `mam_backup_status()`
  compares it. It is written to `storage/.app_key.fingerprint` by the daily job
  and **READ** by export.php — never recomputed there, because a second copy of
  `mam_app_key()`'s fallback chain is exactly what had ID cards signed with a
  public constant.
- **The newest complete snapshot is never pruned, whatever its age.** A
  retention rule that can leave an academy with no backup has done more harm
  than the retention it enforced. `mam_backup_rmdir()` deletes recursively, so
  it refuses anything that is not a `YYYYmmdd-HHMMSS` directory **directly
  inside** the export root — both halves matter, and a test that only exercised
  the filename pattern let the containing-directory check be deleted without
  failing.
- ⚠️ **There is no one-command restore from a snapshot into a running v1
  database.** `v2-load.php` targets the *v2* schema. The snapshot is the
  engine-independent verifiable record that also carries the files; the
  **engine dump** (DEPLOYMENT.md §8.2 — `mysqldump --single-transaction`, or
  SQLite's `VACUUM INTO`, which needs no `sqlite3` binary) is what you restore
  from in a hurry. Take both.

## Where to look
- **Research plan: `docs/research-plan-2026-08-17.md`** — the five commissioned
  research documents reconciled: where they agree, the seven places they conflict
  (and how each was resolved), what they get factually wrong about the shipped
  code, what was built, and the eight decisions only the academy can make.
- **In-flight work: `docs/HANDOFF.md`.**
- **The v1 → v2 ETL: `docs/v2-cutover-readiness-2026-08-18.md`** — what the
  transform covers (24 v2 tables), what it deliberately does not carry and why,
  and the two ways to run it. `api/tools/v2-load.php` loads a database itself
  (dry run by default, `--commit` to write); `api/tools/v2-transform.php` opens
  **no database at all** and writes a `v2-data.sql` with every foreign key
  already resolved, for when the load is somebody else's to run.
  `api/tools/v2-verify.php` reconciles a loaded database against the export over
  a read-only connection.
  ⚠️ Three things here have bitten and are easy to "simplify" back:
  **(1) Identity is decided by v1's own `users.customer_id` link first**, then by
  a shared address ONLY where the names agree. A shared address with disagreeing
  names does NOT merge — in the live data one address belongs to an instructor
  and was also used to apply for a child, and the old email-only rule fused a
  learner's record into a staff one. Under-merging leaves two rows a human joins
  in a minute; over-merging is unrecoverable. The split is raised in
  `report['review']` with the real address on it.
  **(2) `mam_v2_person_index()` reads `customer_ids` (plural)** — two v1 customer
  rows on one address are one person, and the singular key lost the second one's
  applications.
  **(3) `mam_v2_verify_people_expected()` restates the merge rule by a different
  mechanism** (union-find, not the resolver's sequential passes) on purpose. If
  you change the rule you must change both, differently — a verifier that shares
  the resolver's code agrees with the resolver's bugs. Its split counter must
  skip explicitly-linked customers.
  Emitted SQL never disables foreign-key checking (the statement order makes it
  unnecessary) and public ids are DERIVED, not random, so two transforms of one
  export are byte-identical.
  **(4) Any column carrying a foreign id must be REMAPPED, and the ones with no
  foreign-key constraint are the ones nobody notices** — `audit_log.entity_id`
  was copied straight from v1 while every other id in v2 is a v2 id, so it
  looked joinable and pointed at a different row. The v1 id goes in the
  metadata; unresolvable means NULL, never a number that resolves to a stranger.
  **(5) Summer applications transform as `type='programme'` against the CRASH
  programme**, with the route kept in the payload as `intake: 'summer'`. Mapping
  them to `type='summer'` rebuilds the parallel system the migration exists to
  end. ⚠️ `mam_v2_json_like()` matches BOTH `"k":"v"` and `"k": "v"` because
  MySQL's native JSON type re-serialises on store while SQLite keeps the bytes
  written — one spelling passes on one engine and silently misses on the other.
  **(6) `db/v2-exclusions.json` is DATA, not code** — rows the academy decided
  are not real records (test runs). Read by the transform AND the verifier, so a
  correct load is not reported as a shortfall. It cascades to applications but
  **refuses the whole run** if an excluded person holds a payment; an exclusion
  matching nothing is an **error**, not a no-op; and every removal is written to
  the v2 audit log as `migration.excluded` so the database explains its own gaps.
  The migration is **loaded and verified** on the live host (29 people, 33
  applications, ₦515,998.00) — into a database beside v1, with nothing reading
  it yet. That was staging, not a cutover.
- **The v2 cutover map: `docs/v2-cutover-map-2026-08-19.md`** — every v1 table's
  v2 destination (RENAME 21 / ABSORB 9 / DROP 4 / NEW ~72 in five batches), the six
  conventions a new v2 table must follow, and the order of work. `db/schema-v2.sql`
  is at **141 tables** (all five batches authored: the guardian layer, the
  assessment layer, teaching operations, communications, and safeguarding /
  practice / compliance — all in v2 idiom) and `api/lib/v2/plan.php` writes
  **112** of them. Against a real export every v1 table now has a destination or
  a recorded reason: 144 accounted for, zero unmapped-with-rows.
  ⚠️ Three things here bite:
  **(1) v1's `assessment_types` are NOT v2's `competencies`.** v1's rows are KINDS
  of assessment (assignment|final|practical|attendance) weighted for the gradebook;
  a competency is one of the ELEVEN mastery components in `mastery.php`'s registry.
  The inherited ETL seeded `competencies` from `assessment_types`, so nothing a
  `termly_report_criteria` row carried could resolve and **every criterion on every
  report was rejected** — the reports would have reached families with no marks on
  them. v1's table maps to **`assessment_kinds`** (deliberately not named
  `assessment_types`, so the mis-mapping cannot be made again), and `competencies`
  is seeded from the registry, mirrored in `plan.php` with a test asserting the two
  agree.
  **(2) BOTH write paths are plan-driven, and the loader derives everything from
  the target's schema.** `api/tools/v2-load.php` walks `$plan['order']` (it used
  to have six hand-written stages carrying four tables while the plan carried
  112). Four rules there are easy to break: TRANSLATE ids before probing for an
  existing row (unique keys often include a foreign key — probing first asks
  whether a row exists carrying somebody else's id, and in an empty database plan
  ids and row ids coincide so the bug is invisible); not every FK points at an
  `id` (`payments.currency` → `currencies(code)`); idempotency needs the schema's
  column DEFAULTS (`levels` is keyed on a `deleted_seq` the plan never sets); and
  whether a row is recognisable is a per-ROW question, not per-table
  (`notifications` has a unique key most rows cannot fill). Its dry run INSERTS
  and rolls back, so it exercises every CHECK.
  ⚠️ **`MAM_V2_IP_COLUMNS` (emit.php) is load-bearing on MySQL and invisible on
  SQLite.** SQLite's BLOB affinity accepts a 45-character string into a
  `VARBINARY(16)` column; MySQL refuses it. Any new column holding an address
  must be registered there, or it passes every test here and fails on the real
  load.
  **(3) The session TIMEZONE is the one assumption only the academy can settle.**
  v1 stored one datetime per session and no zone; the ETL carries it as the UTC
  instant because that is how v1's own code read it (PHP pinned to UTC) and what
  its stored lateness figures were computed against. If the academy typed Lagos
  wall-clock times, every migrated session displays an hour early. Raised as
  `session_timezone_assumption` in `_review.json`.
  **(4) Anonymity in v1 was a flag beside the author's id.** `student_feedback`
  stored `is_anonymous` next to a NOT NULL `student_id`, and `survey_responses`
  was keyed UNIQUE(survey_id, student_id) — so "anonymous" feedback named its
  author to anybody with a backup. v2's rows have nowhere to put an identity and
  keep one-reply-per-person with a salted `respondent_hash`. Don't add a
  membership column back to either.
  **(5) v1's `promo_banners` was a PRICING authority** — `validation.php` and
  `store.php` read its `discount_percent` when pricing tuition and store orders,
  so editing a banner's colour and repricing every family were one operation on
  one row. v2 splits it into `promotions` (the percentage, in the money layer)
  and `announcement_banners` (the copy, with a palette token instead of a hex
  colour). Don't put a discount back on a banner.
  **(6) `auth_throttle` breaks v2's "no throttling in the schema" rule on
  purpose.** That rule is right for a public form, where the request itself is
  the cost; the AUTH throttle counts FAILURES so eight correct sign-ins from one
  carrier-grade-NAT address cannot lock out unrelated people, and a security
  control must not vanish in a migration. It is also the only table with no
  `tenant_id` — a login attempt has no tenant yet — and that exception is
  registered in the test enforcing the rule.
  **(7) Three v1 token tables become one `signed_tokens`, and NONE is carried.**
  `password_resets`, `progress_report_tokens` and `calendar_tokens` are one idea
  with three expiry rules; two stored the token in the CLEAR. v2 keeps only a
  hash and requires an expiry (v1's calendar tokens never expired). Live tokens
  are re-issued, not migrated.
  **(8) `mark_appeals.opened_at` has no DB default, on purpose.** `chk_appeal_order`
  compares it with `closed_at`, and a defaulted `CURRENT_TIMESTAMP(3)` carries
  milliseconds that a PHP-supplied `gmdate()` does not — so an appeal declined
  twenty minutes after it was raised compared BELOW its own opened_at and the write
  failed. Both timestamps must come from one writer at one precision. (Same family
  as the v1 MySQL-vs-PHP-UTC bug in this table.)
- **Index/map: `docs/codebase-index-2026-08-15.md`** — libs, routes, schema,
  tests, findings. Start here for "where does X live".
- **Spec conformance: `docs/spec-conformance-2026-08-15.md`** — the academic
  proposal (§1–§47) checked section by section against real code, with the two
  genuinely outstanding items and the three things that look like gaps but
  aren't. Read it before claiming any part of that spec is missing.
- Audits: `docs/codebase-audit-2026-06-22.md` (security/correctness),
  `docs/portal-audit-2026-06-22.md` (portal logic/UX). Proposals: `docs/*-proposal.md`.
- Deploy/runbook: `DEPLOYMENT.md`. Env reference: `api/.env.example`.

## Workflow
- **Work on a topic branch off the current tip of `master`** — **`afv/<topic>`**, e.g.
  `afv/enrolment-audit-fixes`. ⚠️ The owner (2026-11-18): never create a branch whose
  name starts with `claude/`. Don't commit straight to `master`, and don't open PRs
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
