# Is the site ready for the load? — v2 cutover readiness

**Asked:** 2026-08-18 · **Answered from measurement, not estimate.**

## The short answer

**The ETL is ready. The site is not.** Those are different questions and only the
first one has been solved.

`api/tools/v2-load.php` loads the production snapshot into a v2 database and
verifies it — 16 of 16 checks, on SQLite and on a real MySQL-family engine, with
the money reconciling to the naira. That work stands.

But **nothing in the running application reads v2.** Not one file outside
`api/lib/v2/`, the tests and the tools references a `mam_v2_*` function. Loading
v2 today produces a populated database that no page, route or screen serves.

## What was measured

| | Count |
|---|---|
| Real v1 tables | **113** |
| …of those, tables the running app queries | **111** |
| Tables in v2 | **64** |
| v1 tables sharing a name with a v2 table | **19** |
| Runtime files reading v2 | **0** |

v2 is not a superset of v1 — it is a deliberately smaller platform core, 64
tables against 113. **92 of the 111 tables the application reads have no v2
counterpart by name at all.**

And a shared name is not a drop-in. `users` is the most favourable case:

```
v1: id email name  phone role status password_hash  admin_tier customer_id
    enrollment_ref must_change_password student_type leaderboard_opt_in …
v2: id email full_name phone status public_id row_version deleted_at
    date_of_birth pronouns preferred_name locale timezone email_verified_at …
```

`name` → `full_name`. `role` moves to `membership_roles`. `password_hash` moves
to `user_credentials`. Every query touching users is a rewrite, and there are
~56K lines of PHP written against v1.

## What the ETL actually covers

Of the 38 v1 tables holding real production rows, the ETL transforms **4**:
`customers`, `users`, `applications`, `payments` — **67 of 2,206 rows.**

That is the identity-and-money core, and it was the hard part: identity
resolution across two tables, exact minor-unit arithmetic, erasure tombstones
that survive. It is not a cutover.

## The five gaps that are decisions, not oversights

Some tables *should* be dropped — `rate_limits` (1,042 rows), `sessions`,
`schema_version`, `events` are transient by design. Others were recorded as
"being recreated" (courses, modules, programme mappings, classes, cohorts). The
following are neither, and each needs an answer before any cutover:

| v1 table | Rows | v2 home | Consequence if it stays unmigrated |
|---|---|---|---|
| `settings` | 58 | `tenant_settings` | Every price, fee, window and feature toggle. A loaded v2 has **no prices**. |
| `user_roles` | 9 | `roles` / `membership_roles` | Nobody holds a role, so nobody can reach an admin or instructor surface. |
| `application_drafts` | 48 | — | The **largest single pool of prospects** — half-finished applications — silently lost. |
| `audit_log` | 232 | `audit_log` (exists) | See below: this one is a contradiction, not just a gap. |
| `summer_applications` | 10 | `applications` (type=summer) | Real applicants for a real intake. |

Also unmigrated with no recorded decision: `id_cards` (8), `user_roles`,
`instructor_profiles`, `enrollments`, `course_enrollments`, `enrolment_events`,
`newsletter`, `mail_log`, `practice_logs`, `faculty_invites`,
`instructor_assignments`, `automations`, `store_items`, `application_events`.

### The audit log is a contradiction, not a gap

The NDPA retention schedule shipped in `api/lib/dataprotection.php` states:

```php
'audit' => [ 'table' => 'audit_log', 'days' => 2555,
  'basis' => 'Legal obligation — the audit trail is the evidence that
              everything else here was done properly' ],
```

v2 *has* an `audit_log` table. The ETL does not touch it. So the academy would
simultaneously be asserting a seven-year legal-obligation retention on the audit
trail and dropping 232 rows of it at cutover. **Both cannot be true**, and the
one written down in the compliance layer is the one an auditor would read.

## What "ready" would require

Three honest options, cheapest first.

**A — Don't cut over.** Keep v1 running. v2 stays a designed schema plus a proven
ETL, ready for when the application is rebuilt. Cost today: nothing. This is the
right choice unless something is forcing the move.

**B — v2 as a read model first.** Load it, serve nothing from it, and use it for
reporting and BI where the queries are new code anyway. Gets value from the work
already done, at close to zero risk, and exercises the load on real data
repeatedly before anything depends on it. **Recommended next step if the v2
direction is being kept.**

**C — Full cutover.** Extend the ETL over the five tables above (plus whatever
the recreate list does not cover), then rewrite the application against v2. The
schema work and the ETL are perhaps a tenth of that; the other nine tenths is
~56K lines of PHP and every screen behind it.

## The other reading of the question

If "the load" meant **traffic** rather than the data migration: that has **not**
been measured and cannot be from here. The dev server is single-process `php -S`,
which tells you nothing about shared hosting behind Cloudflare. What would be
needed is a real staging host, `ab`/`k6` against the actual origin, and a look at
the two things most likely to hurt — `admin/index.php` is ~200KB of PHP rendered
per request, and the portals each fetch a single large dashboard payload. Neither
has a measured number attached to it today, and I am not going to invent one.
