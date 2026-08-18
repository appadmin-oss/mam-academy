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

---

# The transform, delivered as a dataset (2026-08-18)

The read model was the answer to "can we see v2 without cutting over". The
question changed: the schema restructure is being loaded by hand, and what is
wanted is the **data**, transformed, in a form somebody else's tooling can apply.

So the ETL now has a second, load-free path:

```bash
php api/tools/v2-transform.php --export=DIR --out=DIR [--dialect=mysql|sqlite]
php api/tools/v2-verify.php    --export=DIR --dsn=…   # after you load it
```

`v2-transform.php` opens no database at all — not even to read one. It maps the
whole export and writes `v2-data.sql` with every foreign key already resolved,
plus one JSON file per table for review, `_coverage.json`, `_review.json`,
`_rejected.json` and `_manifest.json`. `v2-verify.php` then reconciles the
database you loaded against the export by an independent arithmetic path, over a
connection it opens **read only** — a verifier that can write is a verifier that
can make itself pass.

## Coverage went from 4 tables to 24

Measured on the real export (2,206 rows across 38 tables with data):

| v2 table | rows | from |
|---|---:|---|
| tenants / centres | 1 / 1 | configuration, the one thing not from v1 |
| tenant_settings | 54 | `settings` (4 cached FX keys dropped, secret-shaped keys carried with a NULL value) |
| users | 31 | `customers` + `users` + `summer_applications` |
| user_credentials | 9 | bcrypt hashes carried; v1's own `must_change_password` honoured |
| memberships | 31 | one per person |
| roles / membership_roles | 4 / 12 | `user_roles` ∪ `users.role` ∪ `admin_tier` |
| guardianships | 3 | `summer_applications.guardian_email`, where it resolved to a real person |
| programmes / levels / courses | 5 / 4 / 12 | the catalogue, repointed by CODE — `ktams` is id 22 here and would be another number anywhere else |
| programme_courses | 51 | level resolved from v1's `level_code` |
| modules | 96 | `content` → `objectives`; `kind` → a custom field |
| competencies | 5 | `assessment_types` — same shape, same purpose |
| cohorts / cohort_staff | 5 / 5 | `cohorts` + `classes`, one spine |
| enrolments | 3 | `course_enrollments` + `enrollments`, converged |
| applications | 36 | 26 programme + 10 summer (`type='summer'`) |
| payments | 15 | ₦515,998.00, exact to the kobo |
| custom_field_defs / values | 9 / 114 | v1 columns with no v2 column |
| message_deliveries | 3 | `mail_log` — the record, never the message body |
| audit_log | 239 | `audit_log` + `application_events` + `enrolment_events`, hash-chained |
| legacy_reference_map | 452 | every mapped row |

16 tables with rows are deliberately not carried, each named with its reason in
`_coverage.json` — session tokens, rate-limit counters, funnel events, abandoned
drafts, ID cards (re-issued, not migrated), newsletter subscribers (a subscriber
is not a member). Nothing is unaccounted for: a table the plan has never heard of
is raised as a review item rather than ignored.

## The identity rule changed, and it was wrong before

v1 records the customer↔user link **explicitly**, in `users.customer_id`. Three
of the four real merges are stated there, and the email-keyed rule was ignoring
it while inferring merges from addresses. The rule is now:

1. `users.customer_id` wins outright.
2. A shared address merges only where the names agree.
3. A shared address with disagreeing names and no explicit link **does not
   merge** — it is raised for review.

Rule 3 is the one that mattered. In the live data one address belongs to an
instructor and was also used to apply on behalf of a child; the old rule fused
them into one record. That is the mistake with no way back. The split needs
distinct addresses because `users.email` is UNIQUE, so the login keeps the real
one and the applicant gets an unroutable derived address — with the real address
on the review item, so it can be acted on without going back to the export.

Result on the real export: **22 people, not 21.** 3 merged by the explicit link,
1 by address+name, 2 kept apart and flagged, 0 name conflicts left.

## What was proved, not asserted

* The emitted SQL applies to **both** `db/schema-v2.sql` (MariaDB 10.11, under
  `STRICT_ALL_TABLES`) and `db/schema-v2-sqlite.sql`, with constraint checking
  **on** in both — it never disables foreign keys, because the statement order
  makes that unnecessary.
* `PRAGMA foreign_key_check` is clean afterwards.
* `v2-verify.php` passes **16/16** against each loaded database, and fails when a
  single kobo is altered under it (asserted in `test_v2_plan.php`).
* The audit hash chain re-verifies straight out of the loaded database, including
  the IP pack/unpack round-trip: 239 rows, 0 broken links.
* Two transforms of one export are **byte-identical**, so a diff between them is
  a real change and not a new set of surrogate keys.

## Still not a cutover

Zero runtime screens read v2. The five unmigrated-table decisions listed above
are still open, `MariaDB 10.11 is not MySQL 8` (the load has not been rehearsed
on 8.x), and the `?v=v2read` read model shipped earlier is now surplus to the
stated need — it is env-gated and absent unless `V2_READ_DB` is set, so it is
inert, but it should be removed or kept deliberately rather than by default.
