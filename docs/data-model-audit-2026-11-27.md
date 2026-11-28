# Data model audit — people, applications, enrolment, money

| | |
|---|---|
| **Owner** | Academy technical lead. Decisions marked ⚖️ are the academy owner's. |
| **Version** | 1.0 — 2026-11-27, measured on schema v108 (latest branch) |
| **Method** | The SQLite schema built by the app's own migrator, read with `PRAGMA table_info / foreign_key_list / index_list`. The MySQL schema was compared table by table (170 tables and 1,787 columns on both). Call sites counted with grep. No production data was read. |

## Summary

The data layer holds real, valuable rules: frozen FX, the refund calendar, the
safeguarding gate, the NDPA register. But the **shape** underneath them is
wrong in four ways, and patching has made each one worse:

1. **A person has no single record.** Identity is spread over `users`,
   `customers` and five application tables, and joined by **email
   address**. At a children's academy one email often belongs to a parent who
   applied for a child.
2. **Students, guardians and staff are one table with every role's columns
   bolted on.** Roles are stored twice.
3. **What a student applied for is free text, not a reference.**
   Applications store `discipline`/`program`/`level` as strings that may
   not name a course.
4. **The schema itself has no single source.** There are two hand-synced
   definitions plus a third, dormant one, and the migrator masks its own
   failures.

## 1. People: who is a person?

| Table | Holds | Linked by |
|---|---|---|
| `users` (17 cols) | login, `name` (one field), email, phone, avatar, `role`, `admin_tier`, `student_type`, `enrollment_ref` | `customer_id` (nullable) |
| `customers` (15 cols) | `first_name`/`last_name`, email, phone, dob, sex, address, state, LGA | — |
| `applications` (40 cols) | the application **plus** `parent_name`, `parent_phone`, `parent_email`, `emergency_contact`, `headshot_url` as text | `customer_id` |
| `summer_applications` (31 cols) | its **own full copy** of the person and guardian: names, email, phone, dob, city, guardian name/phone/email | **no FK**: "summer applicants are not necessarily enrolled students" (`db.php:1628`), linked to a customer later by code |
| `scholarship_applications` | `full_name`, `email` | the **text** `application_reference`, no FK |
| `instructor_applications` | `full_name`, email, phone, headshot | no FK |

Findings:

- **Email is the identity key.** It is `UNIQUE` in both `users` and
  `customers`. A child applying with a parent's address cannot have their own
  account, and the parent's and child's details land on the same row. This has
  already happened in real data: loading the v2 read model found identity
  merges where the `customers` row and the `users` row sharing an address
  **name two different people** (`docs/v2-cutover-readiness-2026-08-18.md`
  §"What loading it… found"). A merged parent and child cannot be separated
  automatically.
- **Name is stored two ways and synced lossily.** `users.name` is one field;
  `customers` has first and last. Profile edits split on the first space
  (`api/routes/student/update-profile.php:19`): "Mary Ann Okafor" becomes first
  name "Mary", last name "Ann Okafor". Email and phone are copied between the
  tables by hand in at least 9 files (24 `mam_db_update('users'|'customers')`
  sites), so the copies can drift.
- **The person's date of birth is on `customers`, not on the person who logs
  in.** Everything age-dependent (the safeguarding gate, guardian required
  under 16, the adult-only exemption) reads through a nullable join. "Unknown
  age counts as a minor" is a sound default, but it is needed because the
  data is often missing.
- **The guardian exists twice.** It is free text on the application
  (`parent_name/phone/email`) and also a real account linked by
  `parent_links`. The text is never reconciled with the account.

## 2. Roles: what is this person?

- `users.role` (one value) **and** `user_roles (user_id, role)` both exist:
  95 code references read the column and 50 read the table.
  `mam_user_roles()` merges them, but any query that filters `u.role =
  'student'` misses a secondary role.
- Role-specific columns sit on the shared table. `admin_tier` (admins),
  `student_type` and `enrollment_ref` (students) and `leaderboard_opt_in` (a
  removed feature) are on every row, including guardians'. There is no
  student record, so a student's attributes live wherever the feature that
  needed them put them.

## 3. Applications and enrolment

- **Five application tables** (student, summer, scholarship, instructor,
  bursary). Each has its own status vocabulary and its own copy of the
  person. Only `applications` and `bursary_applications` have foreign keys
  to a person.
- **What was applied for is text.** `applications.discipline` and
  `program`/`level`/`extra_courses`/`courses_label` are strings.
  `discipline` holds instruments that are not courses (`violin`,
  `clarinet`), so `mam_enrol_resolve()` refuses them and a paid applicant
  waits in a backlog for a human. The cohort also still carries
  `discipline`/`pathway` text next to its real `course_id`.
- **Two enrolment models.** `course_enrollments` (cohorts) is live.
  `enrollments`/`classes`/`attendance` were carried across (v108) but are
  kept, unread. `cohorts.legacy_class_id` and the `class_id` columns on
  announcements, resources and assignments remain.
- **"Is this student enrolled in this cohort?"** is written by hand in 9 places
  with two different status sets (backend audit), because there is no
  owning function.

## 4. Money

- **Three representations.**
  - Legacy floats: `payments.amount` DECIMAL/REAL in the row's currency,
    `applications.total_usd`, `store_*_usd`.
  - v61 integers added beside them: `amount_minor`, `amount_usd_cents`,
    plus FX columns. Old rows were backfilled at an **estimated** rate.
  - Per-feature units: `wallet_ledger` (USD cents), `instructor_fines`
    (kobo and USD cents), `fee_*` (minor units).

  Store revenue is still summed from the float column
  (`console.php:1952`).
- **Settlement is not recoverable.** A payment is marked paid by
  compare-and-set, then its effects (fee, store, booking, enrolment) are
  applied **outside any transaction** (`payments.php:83-130`). A crash
  between the two leaves money taken with nothing granted. A retried
  webhook then sees `already` and does nothing, so nothing finishes the job. There are 15 `beginTransaction` sites against
  ~500 write sites, and no transaction helper.

## 5. The schema has no single source

- SQLite builds from 108 migrations held as strings inside one ~3,950-line
  function in `db.php`. MySQL builds from the 4,010-line
  `db/schema-mysql.sql`, re-applied in full whenever the version is behind.
  They match today (170 tables, 1,787 columns) by discipline only; CI checks
  table names and 5 columns.
- The MySQL path swallows four error codes, only *warns* on the rest, and
  stamps the version anyway. A repair list and a column check run **on every
  request** (~16 queries).
- `db/schema-v2.sql` (141 tables) is a third schema with a proven ETL. **It
  already fixes most of §1–§3**: one `users` row per person, `memberships`
  for their place in the academy, `membership_roles`, `guardianships`
  between memberships, one `enrolments` table keyed to the membership and
  the cohort, money in minor units with FX. Nothing in the running
  application reads it.

## The options ⚖️

Each option changes production data, so none is started without the owner.

**A. Repair v1 in place (contract migrations).** Keep the 170 tables and fix
them one at a time:
- add a `people` record that `users` and `customers` point to, with a
  person no longer keyed by email;
- one roles source;
- turn application course choices into foreign keys;
- move student-only columns to a `student_profiles` table;
- drop the legacy tables;
- money in minor units only, with a transaction helper around settlement.

Lowest risk per step, and every step ships alone. The cost is that it
re-derives much of what v2 already designed.

**B. Adopt v2 module by module (strangler).** The modules created on
2026-11-27 make this possible. Each module gets a data-access layer, then
switches its tables from v1 to v2 one at a time, with the ETL keeping the two
in step until that module is cut over. Identity goes first, because every
other module hangs off it. This is the most work up front and it reuses
everything already built. It is the long-term target the v2 design was made
for.

**C. Leave it.** Not recommended: the email-identity merge and the
non-atomic settlement are live risks to children's records and to money.

### Recommendation

**B, starting with identity**, plus two fixes that are worth making **now on
v1 whatever is chosen**, because they are risks today and cost little:

1. **Make payment settlement recoverable.** Not one big transaction: the
   rule "a settled payment must never fail because the placement could not
   be worked out" (CLAUDE.md gotcha #3) means enrolment must not roll back
   the money. Some effects (fee crediting) also open their own
   transactions, and PDO cannot nest them. The fix is to write a
   `settle:<ref>` job into the existing `jobs` queue **in the same
   transaction as the `pending → succeeded` claim**. The job runner then
   applies the effects idempotently and retries. A crash after the claim
   then leaves a job to finish instead of nothing. Needs one transaction
   helper and no schema change; the effects are already idempotent, as
   the webhook-retry tests show.
2. **Stop new parent/child merges.** At application time, when the
   applicant is a minor and the email matches an existing adult, record it
   as the guardian's address and give the child their own identity. Never
   overwrite the existing person. This needs the owner's rule on what a
   child's login is when they have no email of their own.

Two owner decisions are needed first:

- ⚖️ **A or B**: repair v1, or move to v2 module by module.
- ⚖️ **The identity rule**: what identifies a child with no email of their
  own (the guardian's email plus a name and date of birth? a username?), and
  what happens to the existing merged records. The read model lists them for
  a human to separate.

## Decisions (owner, 2026-11-27)

- **Direction: B, module by module, identity first. But v2 is also
  faulted** and must be audited and corrected before any module moves onto
  it. The target is "v2-corrected", not v2 as it stands.
- **Identity (corrected 2026-11-28).** MAM teaches **adults as well as
  children**, so the model is not child-first:
  - **Every learner, adult or child, has their own person record and a
    system-issued username** (e.g. `MAM-26-0014`, never reused). The
    username always works, whatever happens to email.
  - **Students may have their own email, as on NextGenGen**, where a
    member's own address sits on their record and is used to reach them and
    to sign in. It is **optional, verified, and not unique**: siblings may
    share a family inbox, in which case a "Who's signing in?" picker
    appears (NGG's fix). An adult normally signs in with their own email.
    NGG does **not** create academy mailboxes; if MAM ever wants
    `@academy` addresses, that is a separate, consented feature.
  - **A guardian is a separate person, linked by a dated, scoped
    guardianship.** Under 18 only by default: an adult learner has no
    guardian unless they nominate one, and at 18 every guardianship comes
    up for review.
  - **A parent's email is never the child's identity.** Matching at
    admission is on email plus date of birth (or name), and suspected
    duplicates go to staff review.
