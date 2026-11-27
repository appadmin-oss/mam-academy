# ETL — what the real export says

**Milestone:** M2 (transform), grounded in the production snapshot taken
2026-08-16 at schema **v59** (72 tables, 2,206 rows, MySQL).

> The snapshot itself is production data — identity, payments, audit, consent —
> and is **not** in this repository. Everything below is aggregate counts and
> shapes. Fixtures in `test_v2_identity.php` mirror the shapes with invented
> content for the same reason.

---

## 0 · Scale, and what it changes

| | rows |
|---|---|
| users (portal accounts) | 10 |
| customers (applicants) | 16 |
| applications | 26 |
| payments | 15 |
| legacy `enrollments` | 2 |
| `course_enrollments` | 1 |
| **tables that are completely empty** | **34 of 72** |

This is a young academy, and it changes the shape of the work. There is no
volume problem to solve; the risks are all correctness ones, and they are
concentrated in identity, money and the enrolment models.

Two operational facts worth stating plainly, because they are the academy's
actual daily job: **21 of 26 applications sit at `submitted`**, and **14 of 15
payments are unsettled** (11 of those awaiting a manual bank-transfer
confirmation).

---

## 1 · v59 → v81 is clean

Loading the export into a fresh v81 database: all 34 populated tables loaded,
**no table in the export is missing from v81**, and **no column was dropped**.
The 22 migrations between them are additive as far as this data is concerned.

---

## 2 · Reference data must be re-seeded, never copied

The design doc already said reference tables are "re-seeded from canonical
definitions rather than copied". The prototype load proved that is
load-bearing rather than tidy-mindedness, and showed the failure mode is
**silent**.

`programs.code` is UNIQUE. The seed creates programme `ktams` with **id 5**;
production has `ktams` with **id 22**. Inserting the real row resolves the code
conflict by deleting the seeded one — and the three seeded `program_courses`
rows that pointed at programme 5 were orphaned, with no error raised.

**Rule:** the load creates reference rows from the canonical definitions and
maps v1 ids to them through `legacy_reference_map`. It never inserts a v1
reference row into a table the seed has already populated.

---

## 3 · An erasure must stay erased

Two rows in the live data are data-rights tombstones, marked with a sentinel
address under `.invalid` (RFC 2606, guaranteed unroutable) and a blanked name.
One of them still has **six applications** attached.

This is the single sharpest hazard in the transform. Identity resolution that
inferred a person from the rows around them — six applications, a payment, an
ID card — would quietly rebuild the person the academy was asked to forget.

`api/modules/v2/lib/identity.php` detects the sentinel **first** and carries the row
across as a tombstone: present, so its applications keep a valid owner and the
counts reconcile, but holding no name, phone or date of birth, never emailable,
and never given credentials. An erasure on *either* the customer or the user row
makes the person a tombstone — a customer erased while the portal account
survives is a v1 inconsistency, and "forget me" reads the stricter way.

---

## 4 · Identity: 26 v1 rows → 21 people

v1 keeps a person in two places that never reference each other: `customers` is
who applied (name, DOB, address, phone), `users` is who can log in. They overlap
on **5** addresses.

```
16 customers + 10 users − 5 merged = 21 people        ✅ 0 unaccounted rows
```

Decisions taken, and why:

- **Match on the normalised address only** — lowercase and trim, nothing else.
  Gmail dot- and plus-folding is *not* applied: it is true for Gmail and false
  for most hosts, and the asymmetry decides it. A wrong merge fuses two people's
  records and cannot be undone without returning to the snapshot; a missed merge
  leaves two rows a human can join later.
- **The login address is authoritative** when both rows exist — it is the one
  the person types — while the applicant record still contributes the DOB and
  address the account never had.
- **An applicant with no account becomes a user with no credentials.** v2
  separates `user_credentials` from `users` precisely so this is expressible.
- **A row with no address gets its own person**, keyed on its source row. The
  obvious alternative collapses every address-less row into one shared blank,
  silently merging unrelated people.

Every v1 row appears in exactly one person's `sources`, so "row-preserving" is
checkable rather than asserted — `mam_v2_identity_unaccounted()` is what
`verify.php` will gate on.

---

## 5 · Three things that need a decision before the load

These are genuine blockers: the data cannot satisfy the v2 schema without an
answer, and guessing would be worse than asking.

### 5.1 Money — DECIDED: tenant 1's base currency is NGN ✅

v2 requires that whenever a transaction's currency differs from the tenant's
base currency, `base_amount_minor` **and** the frozen `fx_rate` are both
present (CHECK `chk_pay_fx` / `chk_inv_fx`). That constraint is the whole point
of the money redesign.

Production is at v59, which **predates `amount_usd_cents` entirely** — so there
is no USD figure for any historical payment, and no stored rate for the day each
one was taken. Every one of the 15 payments is in NGN.

Reconstructing historical FX would mean inventing a rate per transaction and
presenting it as frozen fact, which is exactly the sort of number that later
gets treated as authoritative.

**Decided (2026-08-16): the base currency is NGN.** `currency = base_currency`
for every existing row, so the CHECK is satisfied honestly and no rate is
invented. Reporting in another currency stays available through `fx_rates`
going forward, where a real observed rate exists. `db/schema-v2.sql` now
defaults `tenants.base_currency` to NGN and `MAM_V2_BASE_CURRENCY` in
`transform.php` is the single constant the transform reads.

A non-NGN payment is **rejected with a reason**, not written with a 1:1
conversion — a fabricated rate stored in the `fx_rate` column would be read as
fact by everything downstream. Today that branch never fires, because every v1
payment is naira; it exists so the first foreign payment stops the load instead
of quietly corrupting the ledger.

### 5.2 Cohorts without a course

**3 of 5 cohorts have `course_id = NULL`.** v2's `cohorts.course_id` is NOT
NULL, deliberately — a cohort is a delivery of a course, and a cohort that is
not one has no gradebook, no curriculum and no certificate path.

The three legacy `classes` rows are private lessons identified by title only
(`"PRIVATE PIANO CLASS - …"`), with an instructor and no course, no cohort link.

Someone at the academy has to say which course each of these represents. This is
the same requirement CLAUDE.md already records for `cohort_converge`: *"a purely
legacy cohort must be course-mapped first"*.

### 5.3 One student, two enrolment models

Exactly one student holds enrolments, and holds them in **both** models — two
legacy `enrollments` and one `course_enrollments`. v2 has one spine, so these
must be reconciled into one enrolment set rather than migrated twice.

At this volume it is a hand-checkable exercise, which is the one advantage of
migrating early.

---

## 6 · Not migrated, on purpose

| table | rows | why |
|---|---|---|
| `rate_limits` | 1,042 | operational state; regenerates in minutes |
| `sessions` | 8 | everyone re-authenticates at cutover, which is correct |
| `password_resets` | 4 | short-lived tokens; carrying them across extends their life |
| `idempotency_cache` | 0 | request-scoped |

Nearly half the snapshot's rows are `rate_limits`. Counting it toward a
"rows migrated" figure would make the load look far more thorough than it is.

---

## 7 · Next

1. Answers to §5.1–5.3.
2. `transform.php` — applications, payments and enrolments repointed onto the
   resolved people, writing `legacy_reference_map` as it goes.
3. `load.php` + `verify.php`, gated on the row-preservation check and the
   existing test suite run against the loaded database.


---

## 8 · Transform results against the real snapshot

`transform.php` is pure — v1 arrays in, v2 arrays out, no database — which is
what lets it be run against the real export and asserted against fixtures
without either touching the other.

```
PEOPLE        21
PAYMENTS      in=15  out=15  rejected=0  unowned=0
              awaiting_transfer 11 · pending 3 · succeeded 1
              total 51,599,800 kobo = NGN 515,998.00
APPLICATIONS  in=26  out=26  rejected=0  erased_owner=6
              submitted 21 · accepted 5
```

**Reconciliation.** The transformed total is compared against a sum computed
directly from the source JSON by a different route: 51,599,800 kobo both ways.
And the 11 awaiting-confirmation payments total **NGN 405,034** — the same
figure the admin Payments screen computes in SQL, by an entirely separate code
path. Two independent derivations agreeing is worth more than either one
checked against itself.

**Money is exact by construction.** Amounts arrive as decimal strings and are
converted by string arithmetic, never `(int) round($v * 100)`: in binary
floating point `8.07 * 100` is `806.9999999999999`, and money one kobo short
reconciles against nothing. An amount that cannot be represented exactly at the
currency's own exponent is **rejected with a reason**, never rounded.

**`pending_bank_transfer` became a first-class v2 state**, `awaiting_transfer`,
rather than collapsing into `pending`. It is 11 of the 15 rows and it is the only
payment state that needs a human — a card payment pending the gateway needs
nobody. Collapsing them would have erased exactly the distinction the admin acts
on. It carries its own wording per audience: the learner is told "if you have
already sent it, nothing more is needed — we check every day", the staff member
is told to confirm it against the statement.

**Six applications belong to an erased person.** They are carried across, so the
counts reconcile and the funnel stays the academy's own record — but their
free-text payload is dropped, because that is where the answers that identify
somebody live.

**`enrolled` is not a v2 application status.** The application ends at
`accepted` and the enrolment row carries what happened next. Keeping it in both
places is the v1 pattern this rebuild exists to end.
