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

**B — v2 as a read model first.** ✅ **CHOSEN AND SHIPPED** — see the section
below. Load it, serve nothing from it, and use it for reporting where the queries
are new code anyway. Gets value from the work already done, at close to zero
risk, and exercises the load on real data repeatedly before anything depends
on it.

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


---

# Option B, shipped: the v2 read model

`api/lib/v2/readmodel.php` + admin `?v=v2read`. Configured by env
(`V2_READ_DB` or `V2_READ_DSN`); unset, the feature is **absent rather than
empty**, and nothing else on the site changes.

## The two properties it rests on

**It cannot write.** SQLite is opened with `mode=ro`, which the engine refuses at
open, plus `PRAGMA query_only = 1` at the statement level; MySQL gets
`SET SESSION TRANSACTION READ ONLY`. `mam_v2_rm_all()` also refuses anything that
is not a SELECT or a CTE before the driver sees it, and there is no insert,
update or delete path in the file to call. The tests prove it by *attempting*
both a DDL and an UPDATE — a read model that can write is a second source of
truth, which is strictly worse than none.

**It fails closed and never falls back to v1.** Not configured, unreachable, or
missing a tenant → every function returns an absent shape and the screen says
why. A figure attributed to the wrong database is worse than a missing figure,
because the reader cannot tell.

Staleness leads the screen rather than sitting in a footnote: `loaded_at` comes
from the newest `legacy_reference_map.migrated_at`, so it is the load's own
record of when it ran, and a snapshot over 24h old says so in gold.

## What it reports, and why only this

Three things, because only the identity-and-money core is loaded. Anything v1
answers well is deliberately **not** duplicated — two screens computing one
figure by two routes is how a discrepancy gets discovered by a parent.

1. **Money in exact minor units**, with `awaiting_transfer` distinguished from
   `pending`: NGN 405,034.00 waiting on bank transfers somebody must confirm, as
   against NGN 104,046.00 waiting on a gateway, which needs nobody.
2. **Duplicate people that folded together** — the one thing v1 *cannot* show,
   since a person is a `customers` row, a `users` row or both with no reliable
   join.
3. **Resolution of an old reference** — the code on a receipt or in a
   confirmation email, resolved to whatever it became.

## What loading it as a read model immediately found

This is the argument for option B, and it paid for itself on the first real read.

**Two of the five identity merges disagree about the person's name.** Identity is
keyed on the lower-cased email, and one pair is a `customers` row and a `users`
row sharing an address while naming **two different people**. At a children's
academy the obvious explanation is a guardian's email used to apply for a child —
and merging a parent with their child is not something a cutover can undo.
`identity.php` says as much in its own header: "under-merging leaves two rows a
human can join later; over-merging destroys data". The email rule over-merges
here.

Nothing has been changed about the rule, because that is a data decision with
real consequences either way. What changed is that the decision is now visible:

* `mam_v2_resolve_identities()` keeps every distinct name its source rows carried
  (`name_variants`) and counts the disagreements;
* the loader writes the disagreement into `legacy_reference_map.note` **at merge
  time** — v2 stores only the winning name, so if it is not captured there the
  discarded one is gone and the decision can never be reviewed;
* `v2-load.php` prints a warning;
* the admin screen lists them under "Merges a human should check before any
  cutover", with the fix: give the two people distinct addresses in v1 and re-run
  the load, which is idempotent.

**Payment references were being dropped.** The map carried
`payments.provider_reference`, which is NULL on all 15 real payments because a
pending bank transfer never reaches a gateway. So the reference a customer
actually holds — the one on the receipt, `MAM-…-BK` — resolved to nothing, in the
one feature whose entire purpose is resolving the references people hold. v1's own
`reference` now goes in the map and the gateway's stays on the payment row; all
41 human-facing references (26 applications + 15 payments) resolve.

## What this does NOT change

The site still runs entirely on v1. Zero screens outside `?v=v2read` read v2, the
92-table gap is untouched, and none of the five unmigrated-table decisions has
been made. Option B buys time and information, not a cutover.
