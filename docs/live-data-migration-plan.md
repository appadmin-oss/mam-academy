# Live data: how the existing records move to the corrected model

| | |
|---|---|
| **Owner** | Academy technical lead runs it; the academy owner (or a named office lead) signs off each review queue. |
| **Version** | 1.0 — 2026-11-27 (plan; nothing has been run) |
| **Applies to** | Production MySQL/MariaDB, every module as it moves (identity first) |
| **Read with** | `docs/data-model-audit-2026-11-27.md` (what is wrong), `api/modules/README.md` (module map) |

## The short answer

**No record is deleted, overwritten or merged by a script.** Every change
follows the same five-step pattern:

1. **Back up**, off the server, and prove the backup restores.
2. **Expand.** Add the new tables beside the old ones. The live site keeps
   running on the old tables and notices nothing.
3. **Copy.** A rerunnable script fills the new tables from the old. Where the
   right answer is unclear, it **does not guess**: the record goes into a
   review queue for a person.
4. **Switch.** One module at a time, the code reads and writes the new tables.
   The old tables stay, untouched, as the fallback. Rollback means switching
   the module back.
5. **Contract.** The old tables are removed only after a grace period (one
   full term), and only after their final contents are archived.

## How much data there is

The last real export (August 2026) held about **21 people** (16 customer
records + 10 user accounts, 5 of which were the same person), **26
applications** and **15 payments** (₦515,998.00, all NGN). Production has
grown since, but it is still on the order of tens to low hundreds of people.

So **every ambiguous record can be checked by a person**, and that is what
this plan does. Re-measure on the day: the counts above are from the August
snapshot, not today.

## Step 0: before anything (once)

- A native `mysqldump` and the app snapshot
  (`php api/tools/export.php --with-uploads --verify-roundtrip`), both
  **copied off the server**.
- A **restore drill**: load the dump into a scratch database and run the
  suite against it. A backup that has never been restored is not a backup.
- Freeze schema v108 as the baseline. From here on, migrations are one file
  each, run deliberately at deploy, never on a visitor's request.
- Fix the two live risks first, because the move should not start on a
  faulted base:
  - **Recoverable settlement.** Record the work to do in the same step that
    marks a payment paid.
  - **No new parent/child merges at application time.**

## Identity (the first module)

| Today | Becomes | How existing rows are handled |
|---|---|---|
| `customers` row + `users` row joined by `users.customer_id` | one **person** | Joined on the explicit `customer_id` link **only**. Never on email. |
| a `customers` row and a `users` row that share an email but are **not** linked | two people, or one after review | **Review queue.** The screen shows both names, both dates of birth, the applications and payments on each, and asks "same person? / parent and child? / two unrelated people?". |
| a record where the name, date of birth or application says it is really a parent and child merged into one | two people + a guardianship | **Review queue**, flagged automatically by: application age under 18 while the account email is also the `parent_email`; the user name differing from the customer name; one email on two different names across applications. The reviewer splits: the child gets their own person, username and enrolments, and the parent becomes their guardian. Payments stay with whoever paid. |
| `users.name` + `customers.first_name/last_name` | `full_name` kept **exactly as typed**, with optional given and family names | Nothing is re-split. Where the two differ, the user's own most recent edit wins and the other is kept in history. |
| email on `users`/`customers` | a **contact point** (not unique), plus an optional login email on the account | Copied as-is. A shared address becomes a contact on each person, never an identity. |
| `applications.parent_name/phone/email`, `summer_applications.guardian_*` (text) | guardian **contact points**, and a **proposed guardianship** | If an account already exists for that guardian, the link is **proposed, not made**. The office confirms it. Linking a guardian to a child is never automatic (NGG learned this the hard way). |
| no username today | every learner gets one, issued by the system (e.g. `MAM-26-0014`, never reused) | Minted during the copy. Existing email sign-in keeps working: someone who signs in with their own email today keeps doing so. |
| `users.role` + `user_roles` | **memberships** (learner / guardian / staff) and roles | Union of both sources. Any disagreement goes to the log and the console. |
| adult learners | self-managed accounts, **no guardian** | A learner aged 18+ (or with no date of birth and no guardian on any application) is treated as an adult. Nothing is attached to them. |

Every old id is recorded in a **reference map** (old table + old id →
new id). Invoice links, certificates, ID-card serials, emailed links and the
audit log keep resolving after the switch.

## The other faults, and what happens to their data

| Fault | What happens to existing rows |
|---|---|
| Course chosen as free text (`discipline` = "violin") | A small mapping table (text → course) that the office confirms once per distinct value. Unmapped rows keep their text and stay in the "needs placement" backlog they are in today. |
| Money in three forms | The integer columns added in v61 are already backfilled. Rows whose FX rate was **estimated** (`fx_backfilled`) are listed for the office to confirm against the bank statement. The float columns are kept read-only until contract; nothing is recomputed silently. |
| Settlement left half-done by a crash | One-off **reconciliation report**: payments marked `succeeded` whose application is not `paid`, whose invoice was not credited, or whose order was not fulfilled. Each is finished by the same code the webhook runs, or refunded. A person ticks each one. |
| Legacy `classes`/`enrollments`/`attendance` (already carried into cohorts in v108) | Archived to a file, then dropped at contract. Nothing reads them today. |
| Two schema sources | No data change: the baseline is frozen at v108 and the MySQL file is generated from it. |

## Checks that must pass before each switch

- Counts: every old row is either in the new table, in the reference map as
  merged (with the reviewer's name), or in a queue. **Zero unaccounted.**
- Money: totals per currency **to the kobo** before and after (the existing
  `v2-verify` checks do this today; 16 of 16 passed on the August snapshot).
- Every login that worked before still works: each existing user signs in
  in a scripted check against a staging copy.
- The suite passes against a copy of production, on MySQL.

## Rollback

- **Before contract:** switch the module back to the old tables. They were
  never changed, so nothing is lost. Writes made in the meantime are replayed
  from the new tables by the same copy script, run in reverse for that module
  only.
- **After contract:** restore from the pre-contract dump, which is why the
  grace period is a full term.

## People's rights while this happens

- **Erasure.** An erased person stays erased. The copy carries the
  tombstone, and the review queue never shows erased data.
- **Children and adults.** The copy decides minor or adult from date of
  birth; with no date of birth, it does not guess, and the person goes to
  the review queue.
- **NDPA.** GAID 2025 Art. 28(3)(m) requires a Data Privacy Impact
  Assessment for student records. Write it before the identity switch and
  file it with the data-protection register (Console → Data protection).
