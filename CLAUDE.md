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
   open item (portal-audit S2). Be deliberate about which one you touch.
3. **`mam_client_ip()` (bootstrap.php)** trusts `CF-Connecting-IP`/`X-Forwarded-For`
   ONLY from a trusted proxy (private + Cloudflare ranges by default; `TRUSTED_PROXY_CIDRS`
   to override). It's the rate-limit + audit IP key. Don't revert to blindly trusting headers.
4. **SSRF guard** for the curriculum remote-import is in `api/lib/lms.php`
   (`mam_lms_public_ips_for` / `mam_lms_fetch_url`): per-hop re-validation, `CURLOPT_RESOLVE`
   pinning, denies private/CGNAT/IETF-special ranges, ports 80/443 only. Keep it strict.
5. **`auditer_results` has no writer.** The student Auditer tab now falls back to live
   `mam_auditer_status()`; the detailed-report table is reserved for a future capture flow.
6. **`admin/index.php` is ~200KB.** Grep for the section; make surgical edits; don't reflow.

## Security posture (don't weaken)

bcrypt + rehash-on-login; hashed session tokens; HttpOnly/SameSite=Lax cookies
(`COOKIE_SECURE`); rate-limited auth; password reset/change revoke other sessions;
CSRF on all mutations + session-bound; webhooks HMAC-verified + idempotent; payments
re-check amount≥owed & currency; store stock decrement race-guarded; SSRF guard above.
Diagnostics (`api/raw-debug.php`, `/api/diag`) are `DEBUG_TOKEN`-gated. No secrets are
committed (`.env`, `storage/`, logs are gitignored). The job runner (`api/tools/run-jobs.php`)
holds an exclusive `flock`. See `docs/codebase-audit-2026-06-22.md` for the full picture.

## Feature state — the "portal brief" (14 items)

Decisions captured so far: **Wallet = admin-issued credits, store-only spend** (no
real-money top-up/withdrawal). **Scoring tool before the notation showcase.**
**Instructor pay model = still TBD** (don't build #7 earnings until decided).

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
- ✅ **#12 Wallet** — `wallet_ledger` (v32), `api/lib/wallet.php`, `POST /api/admin/wallet-credit`,
  `GET /api/student/wallet`, `POST /api/student/store-redeem`; student "Wallet" tab +
  "Pay with credit" on pending orders. **TODO:** admin button to issue credit (endpoint ready).
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
- ✅ **#9/#10 QR attendance** — instructor shows a per-session QR (signed, day-scoped
  token via `mam_attendance_token`, `GET /api/instructor/attendance-qr`), enrolled
  students scan → `attend.php` → `POST /api/attendance/checkin` (`api/lib/attendance.php`,
  enrolment-gated, idempotent, never downgrades an instructor mark). Sits over the LEGACY
  attendance model (enrollments+classes+attendance), not cohorts. QR rendered by a
  self-hosted MIT lib at `assets/js/vendor/qrcode.min.js` (CDN is CSP-blocked). The
  **metronome** half of #9 already existed as a studio tool.
- ⏸ **#7 Instructor earnings** — blocked on pay-model decision.
- 🔬 **#14 Notation showcase** (audio→Classical/Contemporary/African 6-line, interactive,
  realistic playback) — large R&D; not a quick task. **#5 Google Drive video upload** —
  needs Drive API/OAuth credentials.

## Where to look
- Audits: `docs/codebase-audit-2026-06-22.md` (security/correctness),
  `docs/portal-audit-2026-06-22.md` (portal logic/UX). Proposals: `docs/*-proposal.md`.
- Deploy/runbook: `DEPLOYMENT.md`. Env reference: `api/.env.example`.

## Workflow
- Branch for this work: `claude/friendly-tesla-qe0d6z`. Commit + push there; don't open
  PRs unless asked. Keep `php api/tests/run.php` green and add tests for new behavior.
- Don't put model identifiers in commits/code/docs.
