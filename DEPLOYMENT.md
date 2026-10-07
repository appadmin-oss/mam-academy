# MAM Academy — Deployment & Recovery Runbook

Operator guide for standing up (or repairing) a MAM Academy deployment. Covers
the two things every fresh deploy needs: **a migrated database** and **an admin
account** — the **scheduled jobs** without which no sweep in the system ever
runs (§7), and **backup and restore** (§8). Every command here is real and
matches the code in `api/`; the backup and restore commands in §8 were run
against this codebase, not written from memory.

---

## 1 · Environment (`api/.env`)

Copy `api/.env.example` → `api/.env` and fill it in. The app runs with an empty
`.env` (SQLite + auto-migrate + auto-generated CSRF key), but production should
set at least:

| Variable | Why it matters |
|----------|----------------|
| `APP_KEY` | HMAC key for CSRF tokens (hex, ≥32 chars). Empty ⇒ a persistent fallback is auto-generated at `api/storage/.app_key`, but set it explicitly in production. |
| `APP_URL` | Absolute site URL used in emails and redirects. |
| `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD` | Production MySQL. **Empty ⇒ SQLite** at `api/storage/mam.db` (auto-migrates on every request). |
| `COOKIE_SECURE` | `1` forces the `Secure` flag on the session cookie (recommended behind any proxy). Empty ⇒ auto-detect from HTTPS / `X-Forwarded-Proto`. |
| `DEBUG_TOKEN` | Gate for full diagnostics (`/api/diag?key=…`, `/api/raw-debug.php?key=…`). Empty ⇒ those serve only a restricted summary. |
| `SETUP_TOKEN` | Enables the no-shell admin installer/recovery at `/api/setup?token=…` (see §3). |
| `PAYSTACK_SECRET_KEY` / `FLW_SECRET_KEY` | At least one payment provider. |
| `MAIL_HOST` (+ `MAIL_*`) | SMTP. Absent ⇒ falls back to PHP `mail()`. |

`api/.env` and `api/storage/mam.db*` are git-ignored and denied by `.htaccess`.

---

## 2 · Database schema

The canonical schema is `db/schema-mysql.sql` (target version **30** — the
single source of truth is the `MAM_SCHEMA_TARGET` constant in `api/lib/db.php`;
trust that constant over any number quoted here).

- **SQLite:** nothing to do — the migrator runs incrementally on every cold start.
- **MySQL:** the migrator executes `db/schema-mysql.sql` (idempotent) whenever
  `schema_version` is behind the target. It runs automatically on the next
  request, **or** force it:
  - In the admin dashboard overview, click **"Run migrations now"**, or
  - `POST /api/admin/migrate` (returns `{before, after, target}`).

**The #1 cause of a `/api/student/dashboard` 500 is a MySQL DB that is behind on
migrations** (a table the dashboard reads doesn't exist yet). The dashboard now
degrades gracefully — missing sections come back empty instead of 500'ing — but
the real fix is to migrate. Confirm the version gap without logging in:

```
GET /api/diag        →  { db: { schema_version, target } }
```

If `schema_version < target`, migrate (above). Also confirm `db/` deployed to
the server and `api/storage/` is writable.

---

## 3 · Create the administrator

Three ways, in order of convenience. All use `mam_create_admin()`, which is
**idempotent by email** — re-running resets the password rather than duplicating.

### a) Shell (simplest)
```bash
php api/bin/create-admin.php connect@mamcentre.org '<strong-password>' 'Connect Admin'
```

### b) No shell — the web installer / recovery tool
Set `SETUP_TOKEN=<random secret>` in `api/.env`, then open:
```
https://<your-domain>/api/setup?token=<SETUP_TOKEN>
```
With a valid token this works **even when accounts already exist** (it doubles as
password recovery). Loading it also runs migrations, so it can clear a §2 500 in
the same step. Without a token it self-disables once any user exists.

### c) Env auto-seed (fresh DB only)
On a database with **zero users**, the first login attempt seeds an admin from:
```
ADMIN_EMAIL=connect@mamcentre.org
ADMIN_PASSWORD=<plain, ≥8 chars>         # or:
ADMIN_PASSWORD_HASH=<bcrypt hash>        # password_hash($pw, PASSWORD_BCRYPT)
ADMIN_NAME=Connect Admin
```
This is a no-op once any account exists (use (a) or (b) thereafter).

Admins sign in at `/portal/login` and are redirected to `/admin/`.

---

## 4 · Verify

1. `GET /api/diag` → `schema_version == target`, DB driver as expected.
2. Sign in at `/portal/login` with the admin → lands on `/admin/`.
3. The admin **overview** banner flags anything still unconfigured (payment
   provider, `APP_KEY`, SMTP, and the operational-readiness items:
   `BOOKING_EMBED_URL`, `powerPlay.meetUrl`, `cdn.cloudName`, `cdn.uploadPreset`).

---

## 5 · Troubleshooting

| Symptom | Likely cause → fix |
|---------|--------------------|
| `/api/student/dashboard` 500 | MySQL behind on migrations → §2. Dashboard now degrades instead of 500'ing; migrate to restore full data. |
| Login page: "No account exists yet" | Fresh DB, no admin → §3. |
| `/api/setup` says "Already set up" | An account exists → set `SETUP_TOKEN` and use `?token=…` to recover, or §3a. |
| CSRF / forms fail; `csrf_not_configured` | `APP_KEY` unset/short → set it (≥32 hex chars). |
| Admin re-prompts for Basic Auth forever | Host strips the `Authorization` header → the bundled `.htaccess` re-injects it; ensure it deployed. Prefer a session admin (§3) over env Basic Auth. |
| API returns HTML/PHP source instead of JSON | PHP handler not active for `/api/` → check the host's PHP handler; `/diag.html` surfaces this. |
| `/api/diag` won't show full detail | By design — pass `?key=<DEBUG_TOKEN>` or use an admin session. |
| **Emails don't arrive** | 99% config, not code (see §6). Admin → *Email delivery* shows the exact failing SMTP step; *Recent email activity* shows the stored failures. Fix `api/.env`, then **Retry failed**. |

---

## 6 · Email delivery

The mail transport (built-in SMTP client + PHPMailer fallback, STARTTLS/SSL
auto-correct, retry-on-transient) is verified by the test suite. When mail
"doesn't work" in production it is almost always **configuration**, and the app
is built to make that obvious and recoverable:

1. **Set the SMTP block in `api/.env`** — the #1 cause is an empty
   `MAIL_PASSWORD` (the app then silently falls back to PHP `mail()`, a black
   hole on most hosts):
   ```
   MAIL_HOST=mail.mamcentre.org
   MAIL_PORT=587            # 587 ⇒ STARTTLS, 465 ⇒ SSL (auto-corrected)
   MAIL_ENCRYPTION=tls
   MAIL_USERNAME=no-reply@mamcentre.org
   MAIL_PASSWORD=<the mailbox password>     # ← must NOT be empty
   MAIL_FROM_EMAIL=no-reply@mamcentre.org
   MAIL_FORCE_SMTP=1        # recommended in prod: fail loudly instead of mail()
   ```
2. **Confirm the path** — from the server: `php api/tools/mail-test.php you@example.com`
   prints the resolved config (password redacted) and a full per-step SMTP
   transcript. The admin dashboard → *Email delivery* does the same (with a port
   probe) without SSH.
3. **Deliverability (DNS)** — even with SMTP working, mail lands in spam or is
   rejected without **SPF** + **DKIM** for the sending domain. Publish an SPF
   record authorising your relay and enable DKIM signing at the mail host; add a
   DMARC record once both pass.
4. **Nothing is lost while you fix it** — every send is recorded in the `mail_log`
   outbox (migration v17). Failed sends are **retained with their rendered body**;
   re-send the backlog from admin → *Recent email activity* → **Retry failed**, or
   automatically on cron:
   ```
   */15 * * * * php /path/to/site/api/tools/mail-retry.php >> /var/log/mam-mail.log 2>&1
   ```

---

## 7 · Scheduled jobs

Two cron entries beyond the mail retry above. Without the first, **no sweep in
the system ever runs** — session reminders, the card-expiry warnings, the
retention sweeps and the backup freshness check are all driven by it.

```
# The job runner — every hour. Holds an exclusive flock, so overlapping
# crons cannot run the same sweep twice.
0 * * * * php /path/to/site/api/tools/run-jobs.php >> /var/log/mam-jobs.log 2>&1

# The nightly snapshot (see §8). --with-uploads is not optional: without it
# the archive holds no headshots and no recordings.
30 2 * * * php /path/to/site/api/tools/export.php --with-uploads --quiet >> /var/log/mam-backup.log 2>&1
```

---

## 8 · Backup & restore

> **Read this before you need it.** The academy holds children's academic
> records, safeguarding decisions, payment history and photographs of children.
> None of it is reconstructible.

### 8.1 · The two things to back up, and why they are different

| | What it is | Restores with |
|---|---|---|
| **Engine dump** | A native database dump | One command (§8.3) — this is the fast path |
| **`export.php` snapshot** | Per-table JSON + **the uploaded files**, every table checksummed | No single command; see §8.4 for what it is actually for |

Take **both**. They answer different questions, and the snapshot is the only
one that carries `api/storage/uploads` — the headshots and recorded takes. A
row pointing at a photograph restores from the dump; the photograph does not.

⚠️ **Also keep `APP_KEY` somewhere other than the backup.** It is deliberately
NOT in either archive — putting a signing key in the data archive turns a
backup into a credential store. But a restore needs it: `mam_card_sign()`
derives a printed ID card's HMAC from it, so restoring under a different key
leaves every card already in a student's hand failing at a door, with nothing
on screen to explain why. Each snapshot manifest records a **fingerprint** of
the key it was taken under (`signing_key_fingerprint`), so you can tell before
restoring whether the key you have is the right one. The admin console's
**System** screen compares them for you.

### 8.2 · Taking a backup

The nightly snapshot is the cron line in §7. For the engine dump:

**MySQL / MariaDB**

```bash
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 \
  -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_NAME" > mam-$(date -u +%Y%m%d).sql
```

`--single-transaction` takes the dump without locking the tables, so the site
stays up while it runs.

**SQLite** — do not `cp` a live database; a copy taken mid-write is torn.
This needs no `sqlite3` binary, which shared hosting often lacks:

```bash
php -r '$d=new PDO("sqlite:api/storage/mam.db");
        $d->exec("VACUUM INTO ".$d->quote("mam-".gmdate("Ymd").".db"));'
```

**The uploaded files**, if you are not relying on the snapshot for them:

```bash
tar czf mam-uploads-$(date -u +%Y%m%d).tar.gz -C api/storage uploads
```

### 8.3 · Restoring

1. **Check the key first.** Compare the fingerprint in the archive's
   `manifest.json` with the live one:
   ```bash
   php -r 'require "api/lib/bootstrap.php"; echo mam_backup_key_fingerprint(), "\n";'
   ```
   If they differ, restore the `APP_KEY` that matches **before** going further,
   or accept that every issued ID card must be re-minted.

2. **Restore the database.**
   ```bash
   # MySQL / MariaDB — into an EMPTY database
   mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_NAME" < mam-YYYYMMDD.sql

   # SQLite — with the site off, so nothing is mid-write
   cp mam-YYYYMMDD.db api/storage/mam.db
   ```

3. **Restore the uploaded files.**
   ```bash
   cp -a api/storage/export/<stamp>/uploads/. api/storage/uploads/
   #   …or, from the tarball:
   tar xzf mam-uploads-YYYYMMDD.tar.gz -C api/storage
   ```

4. **Verify** with §4, then open the console's **System** screen: it reports
   the schema version, the signing key's stability and the backup state.

### 8.4 · What the snapshot is for, and what it is not

`export.php` writes per-table JSON with an order-independent checksum per
table, a sha256 per uploaded file, and the table DDL — so an archive can be
checked years later with no live database to compare against:

```bash
php api/tools/export.php --verify-only=api/storage/export/<stamp>
```

It re-imports every table into a scratch database and re-hashes every file.
**Exit code 2 means the archive does not match its own manifest — do not
restore from it.** Run this against a real archive occasionally; a backup
nobody has ever verified is a hope, not a backup.

⚠️ **There is no one-command restore from a snapshot into a running v1
database.** `v2-load.php` loads a snapshot into the *v2* schema, not back into
v1. The snapshot is the engine-independent, verifiable record — the thing that
proves what the data was, carries the files, and survives a change of database
engine. The engine dump in §8.2 is what you restore from in a hurry. This is
why §8.1 says take both.

### 8.5 · Knowing it still works

The commonest backup failure is not corruption — it is a job that silently
stopped months ago. `run-jobs.php` checks on every run and warns when the
newest snapshot is older than `backup_max_age_hours` (36), when it holds no
uploaded files, or when it was taken under a different signing key. The same
state is on the console's **System** screen. A half-written snapshot — one
whose export was interrupted before the manifest landed — is reported as
**incomplete** and never counts as a backup.

Snapshots are pruned by `backup_keep_days` (30) under the same
`dp_retention_mode` gate as every other retention rule: each one is a full copy
of every table and every photograph, so they are the largest stores of personal
data on the disk. **The newest complete snapshot is never deleted, whatever its
age** — a retention rule that can leave an academy with no backup has done more
harm than the retention it enforced.
