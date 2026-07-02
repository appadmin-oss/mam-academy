# MAM Academy — Production Readiness Audit & Index

**Date:** 2026-07-02
**Branch audited:** `claude/friendly-tesla-qe0d6z` (the current app: full LMS, payments,
wallet, store, studio, AI, admissions, invoicing — 164 PHP files + a test suite).
**Method:** Four parallel subsystem audits (money/commerce, auth/session/RBAC/admin,
LMS/portal routes, DB-portability/validation/config/frontend), with the headline
findings independently spot-verified.
**Deployment target:** Shared cPanel/Apache. `api/.env.example` ships
`DB_DSN=mysql:host=…`, so **production is MySQL/MariaDB**; SQLite is only the
zero-config local/CI default. The most serious findings are "passes on SQLite/CI,
breaks on MySQL in production."

> This branch is materially more mature and better-defended than the older
> `nifty-clarke` line. Verified-correct now: **payment amount + currency are
> re-checked server-side** in both verify and both webhooks (underpayment via a
> tampered popup is rejected); settlement is **race-safe & idempotent**
> (`UPDATE … WHERE status<>'succeeded'` + `UNIQUE(provider,event_id)`);
> `mam_client_ip()` correctly **refuses forged `X-Forwarded-For`** from untrusted
> peers; **CSRF tokens are session-bound** and not disabled when `APP_KEY` is
> unset; **session tokens** are 160-bit, hashed at rest, re-minted per login (no
> fixation); **pricing is server-authoritative** (client cart/total is intent
> only); admin mutations are all behind `mw_csrf` + capability RBAC; the
> curriculum-import SSRF guard and output escaping are sound. The items below are
> the remaining gaps — the top three block a MySQL launch.

Severity: 🔴 Blocker · 🟠 High · 🟡 Medium · 🔵 Low / Improvement

---

## ✅ Remediation status (2026-07-02)

All findings triaged; **20 of 25 fixed in code**, 5 consciously deferred (rationale
below). Full PHP lint clean; `php api/tests/run.php` → **817 passed, 0 failed**
(three attendance tests updated to seed cohort rosters, matching the new
server-side roster invariant).

**Fixed**
- **#1** reset now binds a PHP timestamp (`perform-reset.php`) — portable across MySQL/MariaDB/SQLite.
- **#2** dropped MariaDB-only `IF NOT EXISTS` from all `ADD COLUMN/INDEX/KEY` in `schema-mysql.sql` (valid on MySQL 8; idempotency comes from the migrator's 1060/1061 swallowing) + the migrator now drops `1146` from "expected" and asserts required columns exist, logging an **error** if any are missing.
- **#3** wallet history inlines the clamped `LIMIT` int (no bound `LIMIT ?` under native prepares).
- **#4** `/api/setup` now requires `SETUP_TOKEN` in production (missing token = closed).
- **#5** `mam_wallet_apply` takes a `SELECT … FOR UPDATE` row lock (MySQL) inside the txn — no more negative-balance race.
- **#6** setup admin (re)set is now audited + logged on every use (plus #4's prod-token gate).
- **#7** per-account login throttle added; the limiter can now **fail closed** for login/reset buckets.
- **#8** `mam_lms_mark_attendance` enforces cohort-roster membership (no off-roster writes).
- **#9** per-user daily AI sub-cap (`ai_user_daily_cap`, default 60) in the gateway.
- **#10** an unfulfillable paid store order is flagged `oversold` + logged `error` + audited (was a silent warn).
- **#11** `app_delete`/`class_delete`/`enrollment_delete`/`seed_starter` are now super-only in the admin RBAC map.
- **#12** `/api/diag` rate-limited + driver/schema/PHP-version removed from the pre-auth summary.
- **#13** `/api/health` public payload reduced to `{service,time,db}`; config posture gated behind admin/`DEBUG_TOKEN`.
- **#15** application-draft saves are email-locked (a guessed key can't reassign a draft's email).
- **#16** `Require all denied` added to `api/tests|bin|vendor`; `api/tests/run.php` now refuses non-CLI SAPI.
- **#17** HTTPS redirect + HSTS enabled, Cloudflare-aware (X-Forwarded-Proto guarded, no redirect loop).
- **#19** payment webhooks rate-limited and reject an invalid signature **before** persisting.
- **#20** legacy `mark-attendance` drops the `"admin"` SQL literal (ANSI_QUOTES-safe) + honours secondary-role admins.
- **#21** honeypot logs a length, not the raw submitted value.
- **#23** `mam_lms_compute_result` guards divide-by-zero with `NULLIF(max_score,0)`.

**Deferred (with rationale — tracked, not silently dropped)**
- **#14** invoice/certificate/policy PII-by-reference — kept as unguessable-reference + rate-limited access. Requiring an email factor would break the one-click links in emails (or push the email into the URL/referrer/logs, its own leak); certificates are intended to be shareable. Revisit with signed per-link tokens if these move to sensitive data.
- **#18** CSRF auto-key fallback file — already protected by `storage/.htaccess` (`Require all denied`) + gitignore; moving it outside the web root is host-specific. Recommend setting an explicit `APP_KEY` in production (documented).
- **#22** CSP `'unsafe-inline'` — tightening needs a nonce/hash refactor of inline bootstrap/theme/studio scripts + full regression; no reflected-XSS sink exists today, so this is defense-in-depth.
- **#24** `payments.amount` mixed currency — reporting-only; safe per-path. Normalising to USD cents is a data migration, not a bug fix.
- **#25** AI name egress — documented "PII-light" design decision; mitigation is to keep a local (`ollama`) provider default for PII-bearing use.

---

## 🔴 Production blockers (all MySQL/MariaDB-specific — invisible to the SQLite CI)

### 1. Password reset is dead on MySQL — SQLite-only `datetime()` in a live query
`api/routes/auth/perform-reset.php:27` filters reset tokens with
`AND expires_at > datetime("now")`. `datetime()` is a SQLite function; MySQL/MariaDB
have no such function, so the prepared statement throws and `mam_db_row()` bubbles
a **500 on every password-reset attempt in production**. `request-reset.php` emails
a link that then always errors — account recovery is entirely broken. This is the
*only* unguarded SQLite-ism left in the route/lib tree (all others branch on
`mam_db_driver()`). **Fix:** bind a PHP timestamp — `AND expires_at > ?` with
`date('Y-m-d H:i:s')` (the same file already does this for `used_at`/`updated_at`).

### 2. `db/schema-mysql.sql` is MariaDB-only → columns silently missing on MySQL 8
The migration blocks use `ALTER TABLE … ADD COLUMN/INDEX/KEY IF NOT EXISTS`
(`schema-mysql.sql:271-276, 284-294, 304-307, 424-427, 687-688, 737-738, 884, 891,
972`). `IF NOT EXISTS` on `ADD COLUMN/INDEX/KEY` is a **MariaDB extension**; MySQL 8
rejects it (error 1064). The migrator (`api/lib/db.php:104-121`) logs the error
non-fatally and still advances `schema_version` via the file's own
`INSERT INTO schema_version` rows — so a fresh **MySQL 8** install reports
"migration complete" while these ALTER-only columns never get created:
`applications.booking_fee_paid_at`, `payments.purpose`,
`applications.level/expression/extra_courses/courses_label`,
`course_enrollments.cohort_id`, `users.admin_tier`, `applications.assignee_id`,
`users.student_type`. Every path touching them (booking flow, store-payment
purpose routing, level-priced applications, cohorts, admin tiers, scholarship
type, admissions assignee) then throws `1054 Unknown column` → 500.
**MariaDB is unaffected.** **Fix:** move these columns into their `CREATE TABLE`
bodies (correct on any driver for fresh installs), or guard each ALTER with an
`information_schema` existence check (as the v10 block already does), or
standardize on MariaDB and say so in `.env.example`/DEPLOYMENT.

### 3. Student Wallet tab 500s on MySQL — bound `LIMIT ?` under native prepares
`api/lib/wallet.php:74` runs `… ORDER BY id DESC LIMIT ?` with the limit passed as
a bound parameter. `api/lib/db.php:32` sets `PDO::ATTR_EMULATE_PREPARES => false`,
so MySQL receives `LIMIT '20'` (a string) and rejects it (error 1210/1064).
`GET /api/student/wallet` therefore 500s in production. It works on SQLite, so CI
is green. (This is the only bound-`LIMIT` in the codebase — everywhere else the
limit is an inlined cast int, so the fix is local.) **Fix:** inline the
already-clamped integer (`'… LIMIT ' . (int)$limit`) or `bindValue(…, PARAM_INT)`.

> **Meta-cause (why these ship):** the MySQL migrator masks failures and CI never
> touches MySQL. `db.php:104-121` catches every `PDOException`, treats `1146`
> (missing table) as "expected", never re-throws, and reads back a
> `schema_version` its own INSERTs advanced — so it always logs success.
> `.github/workflows/ci.yml` installs only `pdo_sqlite` and runs the suite on a
> throwaway SQLite DB. **Add a CI job that applies `schema-mysql.sql` to a MySQL 8
> *and* a MariaDB service container and smoke-queries every table/column**, and
> make the migrator assert expected columns exist (and drop `1146` from the
> expected set). This single change would have caught all three blockers.

---

## 🟠 High

### 4. Unauthenticated first-run installer → admin takeover in the deploy window
`api/index.php:32-33` exposes `GET|POST /api/setup` with only a rate limit. In
`api/routes/setup.php:28,36`, when `SETUP_TOKEN` is unset, `$tokenOk = true` and
(with 0 users) `$canManage = true`, so **anyone** can POST and create the first
`admin`. Once created, `mam_user_count() > 0` self-disables the installer *and*
permanently no-ops `mam_maybe_seed_admin_from_env()` — the operator's env-seeded
admin never gets created and they're locked out. **Fix:** require `SETUP_TOKEN` to
be set for the installer to accept a POST at all (missing token = closed);
document it as a mandatory deploy step.

### 5. Wallet balance can go negative — double-spend of store credit (race)
`api/lib/wallet.php:36-42` (`mam_wallet_apply`) does read-then-write:
`$after = mam_wallet_balance() + $amountCents; if ($after < 0) throw;` where
`mam_wallet_balance()` is a plain non-locking `SELECT SUM(amount_cents)`. Auth is
DB-token based (not PHP `$_SESSION`), so concurrent same-user requests aren't
serialized. A student with $10 credit fires two `POST /api/student/store-redeem`
concurrently: both snapshot the balance as 1000¢, both pass the guard, both insert
`-1000`, wallet settles at **-$10** — $20 of goods for $10. Same race between an
admin `wallet-credit` debit and a redeem. **Fix:** take a locking read
(`SELECT … FOR UPDATE` on MySQL) inside the enclosing transaction, or debit via a
conditional `UPDATE … WHERE balance_cents >= ?` guarded on `rowCount()`.

---

## 🟡 Medium

### 6. `/api/setup` is a permanent unauthenticated admin-reset backdoor when `SETUP_TOKEN` is set
`api/routes/setup.php:31-41`: with a valid `?token=`, a POST resets/creates any
admin (idempotent by email) — no login, no CSRF, no 2FA, no audit line, no expiry.
If the token leaks (logs, shell history, backups, weak value) it's full account
takeover. **Fix:** gate behind a second explicit "recovery enabled" flag, require a
long random token, add a loud audit/alert on every use, and consider auto-expiry.

### 7. Login brute-force protection is IP-only and fails open
`login` is limited 8/600s keyed on IP only (`api/index.php:99`) with **no
per-account lockout**, and `mam_rate_limit()` swallows DB errors and proceeds
(`api/lib/rate-limit.php:33-37`). A distributed attacker gets 8 tries *per IP* per
account; inducing DB contention disables throttling entirely. **Fix:** add a
per-email failure counter/lockout; fail closed (or fall back to a short in-process
throttle) for the login/reset buckets.

### 8. Instructor can mark attendance for any `student_id` (off-roster write)
`api/routes/instructor/session-attendance.php:35-39` → `mam_lms_mark_attendance`
(`api/lib/lms.php:470`) validates cohort ownership and status but never checks the
`student_id` is on the cohort roster. Any instructor can POST
`{session_id, student_id:<any uid>, status:'absent'}` and fabricate/overwrite
attendance for a student in someone else's cohort — polluting their history and
the at-risk analytics heuristic (`analytics.php:112`). The GET mark-sheet already
restricts to the roster; the POST must too. **Fix:** assert `student_id ∈
mam_lms_cohort_roster($sess['cohort_id'])` before writing.

### 9. AI spend is one global daily bucket — a single user can exhaust it (cost/DoS)
`api/lib/ai.php:238-244,263`: the only ceiling is a shared `ai:<date>` counter
(`ai_daily_cap`, default 1000); the per-route limiters are keyed on IP, not user.
One authenticated student can drain the academy-wide daily AI budget (disabling
narrative/coach/grading-assist for everyone) and, with a paid provider configured,
run up real cost. Fails closed, so no crash. **Fix:** add a per-user/day sub-bucket
(`ai:<uid>:<date>`) and/or key the AI route limiters on user id.

### 10. Store overselling — last unit sells twice, second fulfilment silently lost
`api/lib/store.php:59` checks stock at checkout, but decrement happens only at
settle (`api/lib/payments.php:112-119`) guarded by `WHERE stock >= ?`. Two buyers
check out the last unit, both pay; first settle decrements to 0, second matches 0
rows and is swallowed as a `warn`. Both orders show paid/delivered; only one ships,
with no admin-visible failure. **Fix:** reserve/decrement stock at order creation,
or flag the order for refund when the guarded decrement affects 0 rows.

### 11. Manager-tier admins can hard-delete records (RBAC gap)
`admin/index.php:186-196` only marks a fixed `$actionCaps` set as super-only.
Destructive actions absent from it — `app_delete` (`:745`), `class_delete`
(`:729`), `enrollment_delete` (`:737`), `seed_starter` (`:601`) — are thus allowed
to operational `manager`-tier admins, beyond their intended scope. **Fix:** add
those actions to `$actionCaps` (super-only) or introduce a `delete` capability.

---

## 🔵 Low / Improvements

- **12. `/api/diag` has no rate limit and leaks posture unauthenticated**
  (`api/index.php:29`; handler `diag.php:24-36` returns PHP version, DB driver,
  schema_version on every hit → cheap DB-load amplification + fingerprinting).
  Add `mw_rate_limit`; drop driver/schema from the pre-auth summary.
- **13. `/api/health` discloses config** (which payment providers, SMTP,
  `app_key_source:'auto'` i.e. APP_KEY unset) to anonymous callers
  (`health.php:13-24`). Reduce the public payload to `{ok, db}`.
- **14. Invoice / certificate / policy pages expose PII by reference alone**
  (`invoice.php:15-31`, `certificate.php:12-16`, `policy.php:16-22`) — unlike the
  `application/status` and `tuition/status` endpoints, which also require a matching
  email. References are 32-bit; rate limits make brute force impractical, but a
  leaked URL discloses name/email/address / signed legal name. Require the email
  second factor (or accept the certificate as a deliberately shareable credential).
- **15. Application-draft POST overwrite with no token** (`application/draft.php:15-20`):
  the GET load is HMAC-gated (good), but save authenticates only possession of the
  client-minted `draft_key` — a guessed key lets an attacker overwrite a victim's
  draft/email. Integrity only, no read exposure. Require the HMAC token on save too.
- **16. `api/tests`, `api/bin`, `api/vendor` are web-reachable** — `api/.htaccess`
  only denies `lib/` + `routes/` and a fixed extension list, and those three dirs
  have no `.htaccess`. `api/tests/run.php` has **no `PHP_SAPI` CLI guard**, so
  `GET /api/tests/run.php` runs the whole suite over HTTP; `/api/vendor/**` exposes
  the PHPMailer tree/version. Add `Require all denied` to each (mirroring
  `api/lib`) and a CLI guard to `run.php`.
- **17. HTTPS redirect + HSTS are commented out** in the root `.htaccess`
  (`:11-13`, `:106`). `COOKIE_SECURE=1` is set, so enable both for production.
- **18. CSRF auto-key fallback file** `api/storage/.app_key` (`csrf.php:27-45`)
  relies solely on `storage/.htaccess` for secrecy — web-readable on any host that
  ignores `.htaccess`. Prefer refusing to boot without an explicit `APP_KEY` when
  `APP_ENV=production`; treat the auto-key as dev-only.
- **19. Webhooks are un-rate-limited and persist the raw payload before signature
  check** (`index.php:92-93`; `webhook-*.php` insert into `webhook_events` first) —
  an unauthenticated flood is a DB-write DoS. Add `mw_rate_limit` and reject invalid
  signatures before persisting (or store only a truncated marker).
- **20. `instructor/mark-attendance.php:70`** uses a double-quoted SQL literal
  `… OR ? = "admin"` — fine today, but breaks under MySQL `ANSI_QUOTES` sql_mode;
  also compares primary `$u['role']` instead of `mam_user_has_role($u,'admin')`
  (secondary-role admins mishandled). Use `'admin'` / the role helper.
- **21. Honeypot logs the raw submitted trap value** (`validation.php:421`) —
  attacker-controlled log content / minor PII. Log a length/boolean instead.
- **22. Relaxed CSP** (root `.htaccess:114`, `script-src 'unsafe-inline'`,
  blanket `https:`) — defense-in-depth only (no reflected-XSS sink was found; the
  only request data echoed server-side is `embed.php`'s `bpm`, which is int-clamped).
  Tighten toward nonces over time.
- **23. `mam_lms_compute_result`** (`lms.php:703`) lacks the `NULLIF(max_score,0)`
  guard used in `analytics.php:106`; a legacy `max_score=0` row divides by zero.
- **24. `payments.amount` mixes currencies** (NGN for tuition/store-init, USD for
  wallet redemption) — safe per-path today, but any aggregate revenue report over
  the column mixes naira and dollars. Normalise to one unit or always read
  `amount` with `currency`.
- **25. AI PII redaction is regex-only** (`ai.php:222-226`) — redacts emails/phones
  but sends student first names + free-text to non-local providers. Keep a local
  (`ollama`) default for any PII-bearing use, or redact names.

---

## Verified clean (no action)
Payment amount/currency verification (verify + both webhooks); idempotent race-safe
settlement; `X-Forwarded-For`/`CF-Connecting-IP` trusted-proxy handling; session-
bound CSRF (and not disabled when APP_KEY is unset); 160-bit hashed session tokens,
new per login (no fixation); server-authoritative pricing & DOB-derived
track/age (no client trust, allowlisted enums, https+host-allowlisted headshot URL);
student routes scope to the current user id (no cross-student IDOR found);
instructor mutation routes gate on ownership; HMAC draft/unsubscribe tokens;
`DEBUG_TOKEN`-gated `raw-debug`/full `diag`; curriculum-import SSRF guard; admin
dashboard CSRF + same-origin + output escaping; committed PHPMailer 6.12.0 matches
`composer.lock`; `.env`/DB/logs gitignored; no reflected XSS in the root pages.

## Suggested fix order
1. **#1** reset `datetime()` → bound param (one line; a core flow is dead on MySQL).
2. **#2 + #3** MySQL schema completeness + wallet `LIMIT` — the other two MySQL 500s.
3. **Meta:** add a MySQL/MariaDB CI job (prevents this whole class recurring).
4. **#4** require `SETUP_TOKEN`; **#5** lock the wallet debit.
5. Then the Medium RBAC/quota/roster items, then the Low hardening list.

## Subsystem index
| Subsystem | Key files | Findings |
|-----------|-----------|----------|
| Payments/settlement | `lib/payments.php`, `payment-verify.php`, `webhook-*.php` | core clean; #19, #24 |
| Wallet/store | `lib/wallet.php`, `lib/store.php`, `student/store-redeem.php` | #3, #5, #10 |
| Invoicing/certs | `invoice.php`, `certificate.php`, `policy.php` | #14 |
| Auth/session/CSRF | `lib/{auth,session,csrf,rate-limit,bootstrap}.php`, `auth/*` | #1, #7; core clean |
| Setup/recovery | `routes/setup.php`, `raw-debug.php`, `diag.php` | #4, #6, #12 |
| Admin/RBAC | `admin/index.php`, `routes/admin/*`, `index.php` middleware | #11 |
| LMS/teaching | `lib/{lms,analytics,ai,admissions}.php`, `instructor/*`, `student/*` | #8, #9, #15, #20, #23, #25 |
| DB/portability | `lib/db.php`, `db/schema-mysql.sql`, `ci.yml` | #1, #2, #3 (meta) |
| Config/exposure | `.htaccess` (root/api), `api/tests|bin|vendor`, `health.php` | #13, #16, #17, #18, #22 |
