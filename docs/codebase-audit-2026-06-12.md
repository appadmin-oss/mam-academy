# MAM Academy — Codebase Audit

**Date:** 2026-06-12 · **Scope:** entire repository (API, portals, admin, public site, schema, tests, config)
**Baseline:** all 121 API tests pass, every PHP file lints, every JS file parses. The findings below are what review found beyond that.

Severity: 🔴 fix soon · 🟡 should fix · 🔵 nice-to-have / informational.

---

## 1 · Errors (bugs & security)

### 🔴 E1 — Unauthenticated diagnostics leak server internals
- `api/raw-debug.php` is reachable by anyone (the API `.htaccess` serves real files directly, and only `lib/`+`routes/` are 403'd). It returns: PHP version + SAPI, absolute filesystem paths, **every key name in `api/.env`**, DB driver/version, and the **last 30 lines of `php-error.log`**.
- `api/routes/diag.php` (routed `/api/diag`) similarly returns the last 10 lines of `php-error.log` + last 20 buffer-leak lines to any visitor. Error logs are not guaranteed PII/secret-free (stack traces, paths, occasionally request data).
- **Fix:** gate both behind `mam_require_admin()` or a `DEBUG_TOKEN` env check (`?key=…`), or strip the log/env sections from the unauthenticated response. Keep the "callable even when bootstrap is broken" property by checking a token before printing, not by removing the file.

### 🟡 E2 — Open redirect via `next` on the login page
`portal/login.html:220` guards the post-login redirect with `/^\//.test(next)` — which **passes protocol-relative URLs**: `/portal/login?next=//evil.com` redirects to `https://evil.com` after a successful sign-in (phishing vector).
**Fix:** `if (next && !/^\/(?!\/)/.test(next)) next = null;`

### 🟡 E3 — Multi-role users can never be admin
`api/lib/auth.php` has two role systems that disagree:
- `mw_role(...)` → `mam_user_has_role()` honours the v16 `user_roles` join table (multi-role).
- `mw_admin()` → `mam_require_admin()` (auth.php:135) and the admin dashboard gate (`admin/index.php:29`) check **only the primary `users.role === 'admin'`**.

A user granted admin via `user_roles` passes `mw_role('instructor','admin')` routes but is rejected by every `/api/admin/*` endpoint and `/admin/`. Either route admin checks through `mam_user_has_role($u, 'admin')` or document that admin must be the primary role.

### 🟡 E4 — Stale homepage intake: countdown frozen at zero
`assets/js/config.js:178` has `intake.startDate: '2026-05-05'` (five weeks in the past) with `label: 'May 2026'`, `spotsRemaining: 7`. `main.js:566` renders `00 00 00 00` forever once the date passes — the homepage currently shows a dead countdown and a stale scarcity claim. Update the intake (or have `main.js` hide/auto-roll the section when `diff <= 0`).

### 🔵 E5 — Reference-as-capability endpoints echo applicant PII
Unauthenticated `POST /api/payment/initialize` (Flutterwave branch) returns the applicant's **name, phone and email** for any valid application reference; `GET /api/booking/status?ref=` returns the first name; `GET /api/invoice/:ref` renders the invoice. References carry ~32 bits of entropy (`bootstrap.php:228`) plus per-route rate limits, so brute force is impractical — but the Flutterwave payload could omit `phone_number`/`name` (Flutterwave accepts email-only) to shrink the blast radius of a leaked reference.

### 🔵 E6 — robots.txt rules drifted from the clean-URL migration
`robots.txt` disallows `/apply.html`, `/book.html`, `/thank-you.html`, `/diag.html` — but those pages now live at `/apply`, `/book`, `/thank-you` (the `.html`/`.php` forms 301 to clean URLs). The live conversion/transactional URLs are crawlable again. Update the disallow list to the clean paths (and decide deliberately whether `/apply` should be indexable — see G2).

### 🔵 E7 — Session cookie `secure` flag depends on proxy header detection
`api/lib/session.php:32` sets `secure` only when `HTTPS`/`X-Forwarded-Proto` indicates TLS. Behind a misconfigured proxy the session cookie is minted without `Secure`. Since production is HTTPS-only by policy, consider forcing `secure => true` via an env flag.

*(Fixed during this session, listed for the record: `MAM_STUDIO.logScore` was called by both games but never existed; Rhythm Tap kept clicking after Stop, stacked duplicate Space-bar listeners on tab re-entry, mis-scored early taps at card boundaries, and `/studio#…` deep links never opened the right tab.)*

---

## 2 · Feature gaps

### 🟡 G1 — `instructor_assignments` is a dead table
Migrated in `api/lib/db.php:590`, asserted by `api/tests/test_migrations.php:16`, mentioned in `lms.php`'s header — but **no code ever reads or writes it**. Instructor↔course assignment actually happens per-enrolment via `course_enrollments.instructor_id`. Either implement course/level-scoped instructor assignment (so admins don't re-pick the instructor on every approval) or drop the table + test line.

### 🟡 G2 — Sitemap omissions and duplicates
`sitemap.xml` omits **`/apply`** and **`/book`** (the two conversion pages) while listing both `/tools` and `/studio`, which serve identical content (canonical is `/studio`). Add `/apply` (+ `/book` if it should be indexed), drop `/tools`.

### 🟡 G3 — Test-suite blind spots
The suite (121 green) covers pricing, payments/webhooks, migrations, CSRF, LMS, FX, certificates, modules, assessments, progression — but has **no coverage** for: `auth/login` + reset flow, `student/*` endpoints, `instructor/*` endpoints, store checkout → store-purpose payment verify, or booking status. The auth and store flows are the highest-value additions.

### 🔵 G4 — Instructor portal: assignments are write-only
An instructor can create assignments but can't edit, close, delete, or see per-student completion stats (`portal/instructor/index.html` renders a read-only list). Students can mark items done, so a mis-typed assignment lives forever.

### 🔵 G5 — Students can't see their assessment breakdown
The gradebook computes weighted component scores (`instructor/assess`), but the student portal shows only the final grade/outcome on the Courses tab. A "results" view showing the per-component breakdown would close the loop.

### 🔵 G6 — Operational config left empty (silent fallbacks)
- `cdn.uploadPreset: ''` → applicant headshot upload silently disabled (`config.js:81`).
- `booking.embedUrl: ''` → paid bookers see "calendar is being set up" (`book.php`).
- `powerPlay.meetUrl: ''` → no join link on Power Play.
- `cdn.cloudName: ''` → image CDN no-op.
These are documented defaults, but nothing surfaces them to the operator; the admin **Settings** view or `/api/diag` could flag unset ones.

### 🔵 G7 — Stripe advertised in config, unimplemented server-side
`config.js paymentMethods.stripe` exists (disabled). Fine as a roadmap marker; just noting it is UI-only today.

### 🔵 G8 — Instructor portal has no Practice/Games surface
Intentional per current product direction (Rhythm Tap is now student-exclusive), recorded here so it's a decision, not an accident.

---

## 3 · What's in good shape (verified, not assumed)

- **Router/handlers:** every registered route's handler file exists; every frontend `API.request`/`fetch` endpoint maps to a registered route (both directions checked).
- **Payments:** server-authoritative amounts on init; verify re-checks **amount ≥ owed and currency** against the provider; idempotent re-verification; webhook HMAC verification with duplicate-event dropping; store stock decrement is race-guarded.
- **Auth/session:** bcrypt + rehash-on-login, hashed session tokens, HttpOnly/SameSite=Lax cookies, session GC wired in bootstrap, rate-limited login/reset, reset tokens expire, enumeration-safe reset responses.
- **CSRF:** stateless HMAC tokens enforced on every state-changing route + the admin's server-rendered forms (with same-origin check).
- **Escaping:** portal/admin rendering consistently routes dynamic data through `escapeHtml`/`htmlspecialchars` (spot-checked every `innerHTML` builder in both portals + admin).
- **Schema ↔ code:** all tables referenced in code exist in `db/schema-mysql.sql`; the only orphan is the reverse direction (G1).
- **Client/server pricing:** `config.js` display prices match `validation.php` server prices (120/130/160/180, compulsory 120, grand −13%, +20% online, $5 registration), and the client hydrates from `/api/config/public` anyway.
- **Dashboard contracts:** every field the student/instructor portal reads exists in the corresponding dashboard response (field-by-field check).
- **CLI tools:** `api/tools/` is `Require all denied` + CLI-guarded; `api/lib` + `api/routes` direct access is 403'd.
