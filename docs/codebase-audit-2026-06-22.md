# MAM Academy — Codebase Audit

**Date:** 2026-06-22 · **Scope:** entire repository (API, portals, admin, public site, schema, tests, config, CI)
**Baseline (verified this session):** `php api/tests/run.php` → **778 passed, 0 failed**; every PHP file passes `php -l`; every JS file passes `node --check`; no live secrets in tracked files (`api/.env` and `api/storage/*` are gitignored). The findings below are what review found beyond that green baseline.

> **Relationship to the 2026-06-12 audit:** every finding from the prior audit
> (E1–E7, G1–G8) was re-checked and confirmed **still resolved** — login open-redirect
> guard, stale-countdown fix, admin multi-role gating, `raw-debug.php`/`/api/diag`
> `DEBUG_TOKEN` gating, `COOKIE_SECURE` default, clean-URL robots/sitemap, the
> now-wired `instructor_assignments` table. None are re-reported here. Everything
> below is **new or newly-surfaced** since that audit.

Severity: 🔴 fix soon · 🟡 should fix · 🔵 nice-to-have / informational.

---

## 1 · Index — what the codebase is

A server-rendered PHP site (no build step) plus a small hand-rolled API.

| Area | Location | Notes |
|------|----------|-------|
| Public site | `*.php` in repo root | index, apply, checkout, book, store, tuition, pathways, faculty, policies, studio (`tools.php`), power-play, etc. Clean URLs via root `.htaccess` (`.php`/`.html` → 301 → clean). |
| Admin dashboard | `admin/index.php` (~196 KB) | Server-rendered + JS; gated by `mam_require_admin`/`mw_admin_cap` with super/manager tiers. |
| Portals | `portal/{student,instructor}/index.html`, `portal/{login,signup,reset}.html` | SPA-style, talk to the API via `assets/js/config.js` (`MAM_API`). |
| API | `api/` | `index.php` front controller → `lib/router.php`; **32 libs** in `api/lib/`, **~90 routes** in `api/routes/`, **36 test files** (`api/tests/test_*.php`, auto-discovered). PHP 8.4, SQLite (auto-migrate, schema target **v30**) or MySQL. |
| Schema | `db/schema-mysql.sql` | Mirrored by `api/modules/kernel/lib/db.php` migrations. |
| Payments | Paystack + Flutterwave (server-authoritative), Stripe is a disabled placeholder. |
| CI | `.github/workflows/ci.yml` | Runs the PHP suite + `php -l` + `node --check`. |

~25K lines of PHP across 186 files (excluding `api/vendor/`).

---

## 2 · Errors (bugs & security)

### 🟡 S1 — Rate-limit bypass via client-spoofable IP
`api/lib/bootstrap.php:209` (`mam_client_ip`) returns the first value of
`HTTP_CF_CONNECTING_IP` / `HTTP_X_FORWARDED_FOR` with **no trusted-proxy allowlist**,
and that value is the rate-limit key (`api/lib/router.php:128`, `mw_rate_limit → mam_rate_limit($bucket, mam_client_ip(), …)`).
Any client can send a fresh `X-Forwarded-For:` per request to get a new bucket,
defeating the login (8/600), reset (5/600) and every other per-IP limit — i.e. it
re-opens online password brute-forcing despite the limiter the prior audit credited
as the mitigation. The same value is also stored as `sessions.ip` and in audit logs
(`api/modules/kernel/lib/session.php:23`), so the "your active sessions" IP shown to users and the
forensic IP shown to admins are attacker-controllable.
**Fix:** only trust forwarded headers when `REMOTE_ADDR` is in a configured
trusted-proxy CIDR set (e.g. Cloudflare ranges); otherwise key off `REMOTE_ADDR`.
Make the trusted set configurable via env.

### 🟡 S2 — CSRF token is global, not bound to the session/user
`api/modules/kernel/lib/csrf.php:48` mints `timestamp.nonce.HMAC(APP_KEY)` with no per-user/per-session
binding, and `/csrf` (`api/index.php:28`) is unauthenticated. One token minted by
anyone validates state-changing requests for **every** user for 2 hours. The cookie
is `SameSite=Lax` and CORS is same-origin, so this is defense-in-depth rather than a
direct exploit today — but the CSRF layer provides no real per-session guarantee; a
token leaked via logs/referrer or by a lower-privilege user is replayable across users.
**Fix:** bind the token to the current session-token hash (include it in the HMAC
payload and verify it), or move to a double-submit cookie pattern.

### 🟡 S3 — `change-password` does not invalidate other sessions
`api/routes/auth/change-password.php` updates the hash but never revokes the user's
other sessions — unlike `api/routes/auth/perform-reset.php:47` and
`api/routes/admin/reset-password.php:19`, which both
`DELETE FROM sessions WHERE user_id = ?`. A user who changes their password (commonly
*because* they suspect compromise) does not evict an attacker's existing stolen session.
**Fix:** after the update, revoke all sessions except the current one
(`mam_sessions_revoke_others((int)$u['id'])`).

### 🟡 F1 — Invoicing cron: duplicate-email race + expired email re-sends on failed transition
`api/modules/finance/lib/invoicing.php:96-118`, `api/tools/run-jobs.php` (no lock).
Two issues in `mam_invoices_process`:
1. **Race / no lock.** The due-reminder dedup is `mam_invoice_reminded()` (SELECT)
   followed by an `application_events` INSERT with no unique constraint, no transaction,
   and `run-jobs.php` has **no `flock`**. The same pass also flushes up to 50 SMTP
   emails (each a TLS handshake + transient retry), so a slow pass can overrun the cron
   interval; two overlapping passes both read "not reminded" and both send the
   "invoice due" email.
2. **Unconditional expired email.** `mam_invoice_email_expired($app)` is sent
   *after* the `try { mam_admissions_transition(…'expired'…) } catch {}` regardless of
   whether the transition succeeded. If it throws, the row stays `status='submitted'`
   and the next pass re-sends the "application expired" email.
Impact is duplicate emails (not double-charging — money paths are idempotent), hence 🟡.
**Fix:** wrap `run-jobs.php` in an exclusive `flock` (fail-fast if held); send the
expired email only when the transition returns `changed === true`; optionally add a
`UNIQUE(application_id, reason)` guard for `invoice_reminded`.

### 🟡 F2 — SSRF guard treats CGNAT space (100.64.0.0/10) as public; no port restriction
`api/modules/academics/lib/lms.php:1108` (`mam_lms_public_ips_for`). The guard is otherwise **strong**
(see §4), but `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` does **not** reject
RFC 6598 carrier-grade-NAT space — `100.64.0.1` is accepted as public. On hosts behind
CGNAT this can reach internal infrastructure. Separately, the fetch validates the IP but
**accepts any port** (`api/modules/academics/lib/lms.php:1150`), so a public host can be probed on
non-HTTP ports (`:22`, `:6379`, `:25`).
**Fix:** add an explicit deny for `100.64.0.0/10` (and consider `192.0.0.0/24`,
`198.18.0.0/15`) by range-checking the resolved IP; restrict the fetch port to 80/443.

### 🟡 F4 — Invoice/expiry date math mixes UTC DB timestamps with PHP-local time
`api/modules/finance/lib/invoicing.php:45` (`mam_invoice_ts` uses `strtotime`), but `created_at` is
written in UTC (SQLite `datetime('now')`, MySQL `CURRENT_TIMESTAMP`). There is **no**
`date_default_timezone_set()` anywhere in app code, so parsing runs on the php.ini
default; if that isn't UTC (e.g. Africa/Lagos UTC+1), due/expiry instants and the
`expires_at` shown to applicants skew by the offset. With 3-day/14-day windows it's a
boundary skew, not a correctness disaster.
**Fix:** pin `date_default_timezone_set('UTC')` at bootstrap and parse `created_at`
explicitly as UTC.

### 🟡 F5 — SMTP TLS peer verification disabled by default
`api/modules/integrations/lib/mailer.php:250` (PHPMailer) and `:454` (built-in client) default to
`verify_peer=false`, `verify_peer_name=false`, `allow_self_signed=true` unless
`MAIL_STRICT_TLS=1`. This is a **deliberate, documented** shared-host accommodation and
STARTTLS still encrypts — but it leaves SMTP credentials and message contents open to an
active MITM on the path to the relay. The STARTTLS↔implicit-SSL auto-fallback logic
itself is correct.
**Fix (operational):** set `MAIL_STRICT_TLS=1` whenever the relay presents a valid cert
(Gmail/SES/SendGrid/Postmark all do); call the tradeoff out in ops docs.

### 🟡 FE3 — Bank-transfer details render as literal HTML on the booking page
`book.php:344` builds the bank lines with `<br>/<strong>` markup, but `book.php:195`
(`status()`) assigns via `msg.textContent`, so the paid booker sees raw
`&lt;br&gt;&lt;strong&gt;…` text instead of formatted bank details. The sibling
`checkout.php` does the same construction but its `status()` uses `innerHTML` with
`esc()` and renders correctly — `book.php` diverged. **Not** an XSS (textContent is
inert); a broken-display bug on a transactional page.
**Fix:** mirror `checkout.php` — switch `book.php`'s `status()` to escaped `innerHTML`
for this path, or build the bank lines as DOM nodes.

### 🔵 S4 — New users get only the legacy `users.role`, not a `user_roles` row
`api/routes/admin/create-user.php:21`, `api/modules/identity/lib/auth.php` (`mam_create_admin`). The v16
`user_roles` join is the authoritative multi-role store, but newly-created users only
set the primary `users.role`. It works today because `mam_user_roles()` always folds in
the primary role — but the two stores diverge from creation, so any future code reading
`user_roles` alone would miss these users.
**Fix:** idempotently insert the primary role into `user_roles` on creation.

### 🔵 S6 — Login/reset passwords are silently `trim()`med
`api/routes/auth/login.php`, `perform-reset.php`, `change-password.php` read the password
through `mam_str()`, which `trim()`s it. A password with leading/trailing spaces can't be
entered consistently. (The length cap is harmless given bcrypt's 72-byte limit.)
**Fix:** read the raw password without `trim()` (type/length guard only).

### 🔵 F3 — SSRF guard's reliance on PHP filter flags is version-sensitive
`api/modules/academics/lib/lms.php:1109`. On the PHP 8.4 here, `0.0.0.0`, IPv4-mapped IPv6
(`::ffff:169.254.169.254`) and integer-encoded IPs are all correctly rejected. Older PHP
builds historically did not. **Fix:** confirm the production PHP version, or add explicit
checks for `0.0.0.0/8` and strip `::ffff:` prefixes before classifying.

### 🔵 F6 — FX display rate vs charge rate can disagree when the cache is stale
`api/routes/config-public.php:46,50` read the rate in no-fetch mode while
`api/routes/payment-init.php:86` fetches live, so the disclosed "approx ₦…" can differ
from the charge when the cache is stale. Bounded by `MAM_FX_MIN/MAX` (100–100000), so a
disclosure/UX discrepancy, not corruption — the "can never disagree" comment is
overstated. GBP/EUR are display-only (booking tiers convert via hardcoded cross rates),
so a bad GBP/EUR rate cannot misprice a charge.

### 🔵 F7 — Booking country/tier from spoofable geo header
`api/modules/finance/lib/booking-pricing.php:37` (`mam_detect_country`) trusts `HTTP_CF_IPCOUNTRY` etc.,
so a user can self-select the cheapest regional booking tier. Tiers are small and roughly
equivalent, so low-impact arbitrage — but the price is client-influenced despite the
file's "never client-controlled" claim.

### 🔵 FE1 — Notification URL has no scheme guard (latent `javascript:` sink)
`assets/js/portal.js:184` builds `href="…escapeHtml(n.url)…"` and `:206` does
`window.location.href = href`. `escapeHtml` neutralizes quotes/angle-brackets but **not**
dangerous URL schemes. **Not reachable today** — every `mam_notify()` call site passes a
hardcoded internal path (`/portal/student/`) — but it's a latent sink if a notification
URL ever derives from user input.
**Fix:** accept only `^(https?:\/\/|\/)` at both sites; else fall back to `#`.

### 🔵 FE2 — Unescaped `i.qty` in the student order summary
`portal/student/index.html:734` — `P.escapeHtml(i.name) + ' × ' + i.qty`; `i.name` is
escaped but `i.qty` (from `JSON.parse(o.items_json)`) is concatenated raw into
`innerHTML`. The one unescaped field in an otherwise fully-escaped row.
**Fix:** `Number(i.qty) || 0` (or `escapeHtml(String(i.qty))`).

---

## 3 · Feature gaps / hygiene

### 🟡 C1 — PWA manifest shortcuts use legacy `.html` URLs
`manifest.webmanifest:17-19` points the app shortcuts at `/apply.html`,
`/pathways.html`, `/metamusic-order.html`; root `.htaccess` 301-redirects each `.html`
to its clean URL, so installed-app shortcuts take an extra hop and contradict the
canonical-URL policy. **Fix:** use `/apply`, `/pathways`, `/metamusic-order`.

### 🟡 C2 — `DEPLOYMENT.md` schema target is stale (says 16, actual 30)
`DEPLOYMENT.md:33-34` says "target version **16**" while `api/modules/kernel/lib/db.php:11` defines
`MAM_SCHEMA_TARGET = 30`. An operator following the runbook will misread a current DB as
14 versions ahead. **Fix:** update the doc to v30, or drop the hardcoded number and point
to `MAM_SCHEMA_TARGET`.

### 🔵 C3 — `auditer_results` is write-orphaned
The student dashboard reads the latest row (`api/routes/student/dashboard.php:67`) but
**no code ever inserts/updates** the table (`db/schema-mysql.sql:242`) — the `/auditer`
page is marketing-only with no save path, so the dashboard's auditer panel can only ever
be empty. (This slipped the prior audit's "all tables referenced" check because it has a
read reference.) **Fix:** implement the result-capture endpoint, or drop the dead
read + table until the feature exists.

### 🔵 C4 — CI runs no real linter and skips `.cjs`
`.github/workflows/ci.yml` only `node --check`s `assets/js/*.js` (syntax parse, not lint)
and that glob misses `assets/js/headshot.selftest.cjs`; PHP coverage is `php -l`
(syntax-only) + the test suite. **Fix:** include `*.cjs` in the check loop (and wire the
headshot self-test as a real CI step); optionally add ESLint / a PHP static analyzer.
*(Positive: the prior audit's G3 test-coverage gap is closed — the suite auto-discovers
all 36 `test_*.php`, now including auth and store.)*

### 🔵 C5 — CI step label is stale
`.github/workflows/ci.yml:37` still reads "pricing · payments · migrations · csrf · lms ·
fx" though the suite now spans 36 files. Functionally fine (glob runs them all); just a
misleading label.

---

## 4 · What's in good shape (verified, not assumed)

- **SSRF guard (new, highest-risk surface) is genuinely strong.** `mam_lms_fetch_url`
  (`api/modules/academics/lib/lms.php:1139`) rejects non-http(s) schemes before any I/O, resolves DNS and
  rejects the whole host if *any* A/AAAA record is private/loopback/link-local/metadata
  (incl. IPv6 and `169.254.169.254`), rejects integer/octal/hex-encoded IPs, follows
  redirects **manually** re-validating every hop, and **pins** the validated IP via
  `CURLOPT_RESOLVE` — defeating DNS-rebinding TOCTOU. Response is size-capped (2 MB) and
  protocols are restricted. The §2 gaps (CGNAT, port) are residual hardening, not breaks.
- **No SQL injection.** Every query in the audited libs/routes uses prepared statements;
  `mam_db_insert/update/upsert` build column lists from server-side whitelists, not
  request strings (`store-items.php:32` whitelists editable columns).
- **Auth/session.** bcrypt + rehash-on-login, fresh 160-bit session token per login
  stored SHA-256-hashed, HttpOnly/SameSite=Lax cookies (`Secure` via `COOKIE_SECURE`),
  session GC wired, no fixation, enumeration-safe reset, reset tokens expire.
- **Authorization.** `/api/admin/*` consistently gated by `mw_admin_cap` with a
  super/manager tier split and a self-demotion lockout guard; `revoke-session`/
  `revoke-others` are correctly `user_id`-scoped.
- **CSRF coverage.** Every state-changing route carries `mw_csrf()`; webhooks correctly
  use HMAC instead; the client auto-attaches `X-CSRF-Token` and retries on `csrf_invalid`.
- **Payments.** Server-authoritative amounts; verify re-checks amount ≥ owed and currency
  against the provider; idempotent; webhook HMAC with duplicate-event dropping; NGN charge
  rate is bounds-checked on both fetch and use, so a glitched provider rate can't misprice.
- **Pricing.** Display price == charged price across the application flow (surcharge folded
  server-side, same rounding), and the client hydrates from `/api/config/public`.
- **Escaping.** Admin's `innerHTML` builders route dynamic data through `esc()`; portals
  through `escapeHtml`/`htmlspecialchars` (the two §2/§3 misses are the exceptions).
- **No committed secrets.** `api/.env`, `api/storage/mam.db*`, `api/storage/.app_key`,
  and `api/logs/*.log` are gitignored; no live-key patterns in tracked files.
- **`.htaccess` posture.** `api/lib` + `api/routes` are `Require all denied` and 403'd;
  `.env`/`.sql`/`.git`/`composer.*` blocked; `api/tools|logs|storage` denied;
  `raw-debug.php` + `/api/diag` gated by `DEBUG_TOKEN`; clean-URL rewrites loop-guarded.
- **Prior-audit fixes (E1–E7, G1–G8) all still in place** (re-verified).

---

## 5 · Suggested priority order

1. **S1** — restore real login brute-force protection (trusted-proxy IP resolution).
2. **F1** — `flock` the job runner + fix the unconditional expired email.
3. **S3** — revoke other sessions on password change (one-line consistency fix).
4. **F2** — CGNAT deny + port restriction on the import fetch.
5. **S2** — bind CSRF tokens to the session.
6. **C1 / C2 / FE3** — quick correctness/doc fixes (manifest URLs, schema-target doc, book.php bank render).
7. Remaining 🔵 items as cleanup.
