# The v1 → v2 cutover map

**Written 19 Aug 2026.** The authoritative destination for every v1 table, so the
call-site rewrite is mechanical rather than exploratory.

Measured surface at the time of writing:

| | |
|---|---|
| v1 tables (SQLite runtime, schema v88) | **129** |
| v2 tables (`db/schema-v2.sql`, before this work) | **64** |
| v2 tables now (all five batches authored) | **141** |
| ETL tables the plan writes (was 25) | **112** |
| SQL call sites in `api/lib/` | 1,035 |
| SQL call sites in `api/routes/` | 279 |
| SQL call sites in `admin/` | 157 |
| SQL call sites in root pages | 7 |
| **Total call sites to rewrite** | **1,478** |
| Test files seeding v1 tables | 114 |

`db/schema-v2.sql` was checked against the academy's own generated
`v2full.sql`: the DDL is **identical** for all 64 tables, so the repo's schema is
current. What that file adds is 1,206 rows of transformed v1 data — 31 users, 31
memberships, 3 guardianships, 36 applications, 15 payments, 12 courses, 5 cohorts,
3 enrolments, and the 452-row `legacy_reference_map`. It loads clean (verified
against the SQLite mirror; the only two statements that fail are the
`schema_version` and `currencies` seeds the mirror already contains).

---

## 1 · Why "110 tables have no v2 counterpart" overstates the gap

A name diff counts renames as gaps. Read against v2's actual DDL, the 110 split
four ways:

- **RENAME** — the same concept under v2's name. The code changes; the data model
  does not.
- **ABSORB** — folded into a *richer* v2 table. The code changes shape too, and
  usually gains a guarantee (v1's three boolean consent flags become
  `consents` rows carrying purpose, evidence and an effective period).
- **NEW** — a real gap. v2 cannot hold the feature and a table must be added.
- **DROP** — v1 mechanism with no future in v2 (its job is done by a v2 primitive,
  or it was scaffolding).

## 2 · RENAME — 21 tables, code-only change

| v1 | v2 | Note |
|---|---|---|
| `enrollments`, `course_enrollments` | `enrolments` | **The two parallel enrolment models converge here.** CLAUDE.md gotcha #2 exists because v1 never reconciled them; v2 has one. Every dual-read in the codebase collapses to a single read — this is the largest single simplification in the cutover. |
| `classes` | `cohorts` | A private class is a cohort with capacity 1 — one code path for registers, credits and reports. |
| `class_sessions` | `class_sessions` | Same name, but v2 adds `room_id`, `delivery_mode` and a status vocabulary. |
| `session_attendance` | `attendance` | v2 carries all seven §12 statuses, `delivery_mode`, the clock (`clocked_in_at`/`clocked_out_at`/`minutes_present`/`minutes_late`) and `method` (instructor\|self\|qr\|card\|api). Keyed on `enrolment_id`, not `student_id`. |
| `programs` | `programmes` | |
| `program_courses` | `programme_courses` | |
| `lessons` | `modules` | |
| `lesson_progress` | `module_progress` | Keyed on `enrolment_id`; keeps `mastery_pct` and the week fields. |
| `module_carryforward` | `module_carryforwards` | |
| `parent_links` | `guardianships` | ⚠️ Carried by the ETL. **`guardianships.scope` already exists** (`VARCHAR(24) DEFAULT 'standard'`), so the v86 access scope needs no new column — but the VALUES differ: v1 spells the narrowest scope `billing`, v2 spells it `billing_only`. The ETL translates; `mam_parent_scope()` must too when it is cut over. `permissions JSON` is the finer grain. |
| `user_roles` | `membership_roles` | Roles attach to a MEMBERSHIP, not a user, so one person can be an instructor at one centre and a guardian at another. |
| `sessions` | `auth_sessions` | |
| `settings` | `tenant_settings` | Per tenant, not global. |
| `idempotency_cache` | `idempotency_keys` | |
| `mail_log` | `message_deliveries` | |
| `student_consents` | `consents` | See ABSORB — richer, not just renamed. |
| `dp_requests` | `data_subject_requests` | |
| `submission_reviews` | *(see NEW: `assessments`)* | v2's rubric tables exist; the review row does not. |
| `results` | *(see NEW: `enrolment_results`)* | |
| `customers` | `users` + `memberships` | The ETL already merges them; see the identity rule in `docs/v2-cutover-readiness-2026-08-18.md`. |

## 3 · ABSORB — 9 tables, folded into a richer v2 primitive

| v1 | v2 destination | What is gained |
|---|---|---|
| `student_consents` (3 boolean columns) | `consents` (row per purpose) | Purpose, `is_granted`, who granted it for a minor, `evidence` JSON (form version, IP, wording shown), and an effective period. v1 could not say *when* a consent was withdrawn, which is the NDPA-relevant fact. |
| `wallet_ledger` | `ledger_accounts` + `ledger_entries` | Double-entry with a balanced `transaction_id` set, base-currency mirror and frozen rate. Instructor earnings become an account, not a bespoke table. |
| `application_events`, `enrolment_events` | `lifecycle_events` | One stage machine with `from_stage`/`to_stage`/`outcome`/`reason_code`, so admissions and enrolment transitions read the same way. |
| `events` (dedupe keys) | `idempotency_keys` | v1 used `events` as a dedupe ledger for cron sweeps; that is what an idempotency key is. |
| `funnel_events` | `audit_log` + `lifecycle_events` | |
| `session_history` | `audit_log` | |
| `resource_versions` | `attachments` | v2's attachment row carries version provenance. |
| `submission_files` | `attachments` | Polymorphic, so takes/recordings/photos are one mechanism. |
| `progress_report_tokens`, `calendar_tokens` | *(see NEW: `signed_tokens`)* | Both are single-use hashed expiring tokens; v1 has three separate tables for one idea (`password_resets` is the third). |

## 4 · DROP — 4 tables

| v1 | Why |
|---|---|
| `rate_limits` | v2 puts throttling outside the schema. If it must be persisted, it is a cache concern, not a business table. **Decision needed before cutover** — the auth failure-throttle (`mw_rate_limit_failures()`) is a security control and cannot simply vanish. Recorded as NEW below rather than dropped. |
| `user_roles` | superseded by `membership_roles` (listed as RENAME). |
| `module_carryforward` | superseded by `module_carryforwards`. |
| `idempotency_cache` | superseded by `idempotency_keys`. |

## 5 · NEW — the real gap

**~72 tables.** These are features the academy runs today that v2 cannot hold. In
the order they should be authored, because later batches depend on earlier ones.

### Batch 1 · The guardian layer (18 tables) — ✅ AUTHORED, carried over in v2 idiom

The 2026-08 redesign's subsystems. v2 has `invoices`/`invoice_lines`, but they
**cannot express the design's fee model**: one flat `discount_minor` where the
design requires four distinct mechanisms in a fixed order, and no `waiver_minor`
on the line. Folding them together would collapse exactly the distinction the
design says changes what the academy's books say happened.

- `termly_reports`, `termly_report_criteria`, `report_moderations`, `mark_appeals`
- `invoice_aid_awards`, `invoice_credits` (gift aid), `invoice_payment_plans`, `invoice_plan_instalments`, `bursary_applications`
- plus `invoice_lines.waiver_minor` + `waiver_reason`, and `invoices.waiver_minor`/`aid_minor`/`sibling_minor` so the four stages stay separable
- `guardian_threads`, `guardian_thread_participants`, `guardian_thread_messages`, `guardian_channel_prefs`
- `makeup_credits`

### Batch 2 · Assessment and progress (12) — ✅ AUTHORED

`enrolment_results`, `assessments`, **`assessment_marks`**,
**`assessment_kinds`**, `submissions`, `submission_markers`, `teachbacks`,
`assignments`, `assignment_groups`, `certificates`, `module_proposals`,
`tool_scores`

Two names changed from the plan above, both for a reason worth keeping:

- **`assessment_marks` was added.** Without it a mark is a JSON blob
  (v1's `submission_reviews.scores_json`) and nothing can join a mark to a
  competency, which is the whole reason the mastery score matches rubric
  criteria BY LABEL today.
- **It is `assessment_kinds`, NOT `assessment_types`.** v1 has a table
  called `assessment_types` and it means something else: its rows are the
  mastery COMPONENTS and they map to `competencies`. A v2 table sharing
  that name invites exactly the mis-mapping described below.

⚠️ **The trap this batch actually sprang.** v1's `assessment_types`
(`assignment|final|continuous|practical|attendance`) are KINDS of
assessment weighted for the gradebook. v2's `competencies` are the ELEVEN
mastery components (`rhythm_timing`, `theory`, …) which live in
`mastery.php`'s registry as a code constant. The inherited ETL seeded
`competencies` from `assessment_types`, so the loaded vocabulary had
nothing a `termly_report_criteria` row could resolve to and **every
criterion on every report was rejected** — 22 of 22 in the export tested.
The reports would have reached families with no marks on them. Fixed, and
`test_v2_plan.php` now asserts the mirrored registry in `plan.php` matches
`mastery.php` exactly so the copy cannot drift.

### Batch 3 · Teaching operations (12) — ✅ AUTHORED

`instructor_availability`, **`instructor_courses`**, `instructor_clockins`,
`instructor_fines`, `faculty_invites`, `instructor_applications`,
**`meeting_participants`**, **`session_progress_reports`**,
`reschedule_requests`, `reschedule_items`, **`cohort_waitlist`**,
**`learning_resources`**

(The calendar tables — `academic_terms`, `term_refund_schedule`, `term_census`,
`term_closures` — landed with batch 1, because they gate every money decision.)

Five names differ from v1's, each stating a decision:

- **`session_progress_reports`**, not `progress_reports`: v1's two-hour note
  about one lesson and its board-ratified `termly_reports` are different
  documents, and the bare name has already caused them to be confused in code.
- **`instructor_courses`**, not `instructor_assignments`: in v2 an "assignment"
  is set work. This is eligibility — a different question from `cohort_staff`.
- **`meeting_participants`**, and it never writes to `attendance`.
- **`cohort_waitlist`** and **`learning_resources`**, since v2 has no `classes`.

**Two v1 tables deliberately get no v2 table.** `instructor_profiles` (four
optional strings) is absorbed into `custom_field_values` on the membership, which
is where the ETL already put it — two homes for one fact is how they drift. And
`reschedule_events` goes to `lifecycle_events`, v2's one stage machine.

⚠️ **The session timezone is the one assumption only the academy can settle.**
v1 stored ONE datetime per session and recorded no zone. The ETL carries it as
the UTC instant, because that is how v1's own code read it (PHP is pinned to UTC
in bootstrap) and what the lateness figures stored on attendance were computed
against. If those values were typed as Lagos wall-clock times — which the admin
form's `datetime-local` input invites — every migrated session displays an hour
early and v1's recorded lateness was always an hour out. Raised in
`_review.json` as `session_timezone_assumption`, with a count.

⚠️ `academic_terms` and `term_refund_schedule` are the **only authority on a
refund** in this codebase (CLAUDE.md gotcha #14). They must land before any money
code is cut over, or every refund becomes a manual decision again.

### Batch 4 · Communications and admissions (13) — ✅ AUTHORED

`announcements`, **`contact_messages`**, `support_tickets`, `ticket_messages`,
`surveys`, `survey_responses`, **`learner_feedback`**, `application_notes`,
`scholarship_applications`, `auditer_results`, **`marketing_contacts`**,
**`promotions`**, **`announcement_banners`**

**Three v1 tables get no v2 table.** `feedback` (per-submission rubric and
comments) is a MARK — `assessments` + `assessment_marks`, one marking mechanism
rather than two. `summer_applications` is an `applications` row against the CRASH
programme. `application_drafts` is abandoned wizard state keyed by a browser
token: a draft nobody submitted is not an application, and after cutover it is
personal data held for no purpose anybody can name.

⚠️ **Anonymity was a promise v1 did not keep.** `student_feedback` stored
`is_anonymous` beside a NOT NULL `student_id`, and `survey_responses` was keyed
UNIQUE(survey_id, student_id) — so "anonymous" feedback named its author to
anybody with database access, which is anybody with a backup. In v2 an anonymous
row has nowhere to put an identity, and one-reply-per-person is kept by a salted
`respondent_hash`. The ETL is where the promise is made true: an anonymous row
arrives with the author and the cohort removed (a cohort of nine identifies its
author) and keeps the course as context. No migrated survey is marked anonymous.

⚠️ **A banner was a pricing authority.** One `promo_banners` row held the
headline, the background colour and a `discount_percent` that `validation.php`
and `store.php` read when pricing tuition and store orders — so editing a colour
and repricing every family were the same operation, and "why was this invoice
cheaper" was answered by a marketing record somebody had since overwritten. v2
splits it: `promotions` owns the percentage (with a window, a cap that must name
its currency, and CHECKs refusing a negative discount or one above 100%), and
`announcement_banners` owns the copy — taking a palette TOKEN rather than a hex
value, because a colour typed into a marketing form is how a banner ends up at
2:1 contrast against its own text. An issued invoice stores its own totals, so
changing a promotion cannot restate what a family was already asked for.

**Newsletter subscribers are carried**, against the inherited ETL's decision to
drop them: dropping the list also drops the SUPPRESSION list, so the next import
mails the people who asked not to be mailed. They go to `marketing_contacts`
with consent provenance — a subscriber is a data subject, not a member, so
`consents` (which needs a membership) is the wrong home.

### Batch 5 · Identity, safeguarding, engagement, compliance, infra (22) — ✅ AUTHORED

`vetting_records`, `id_cards`, `card_scans`, **`signed_tokens`** (absorbing
`password_resets` + `progress_report_tokens` + `calendar_tokens`),
**`auth_throttle`**, `achievements`, `practice_logs`, `practice_challenges`,
`repertoire`, `recitals`, `recital_items`, `referral_codes`, `referrals`,
`dp_assessments`, `dp_breaches`, `dp_processors`, `dp_register_notes`,
**`automation_rules`**, `ai_runs`, **`store_products`**, `store_orders`,
**`store_order_items`**

⚠️ **`auth_throttle` exists against §6's convention on purpose.** v2 puts
throttling outside the schema, which is right for a public form where the request
itself is the cost. The AUTH throttle counts FAILURES, so eight correct sign-ins
from one carrier-grade-NAT address (most Nigerian mobile traffic) cannot lock out
unrelated people — and a security control that vanishes in a migration is a
regression whatever the convention says. It is also the ONE table with no
`tenant_id`: a login attempt has no tenant yet, and scoping the bucket by tenant
would let an attacker reset it by guessing at another academy. That exception is
registered in the test that enforces the tenant_id rule.

⚠️ **Three token tables become one, and none of them is carried.**
`password_resets`, `progress_report_tokens` and `calendar_tokens` are one idea
with three expiry rules and three places to get the hashing wrong — and two of
them stored the token in the CLEAR, so the table was a set of live credentials.
`signed_tokens` holds only a hash and REQUIRES an expiry (v1's calendar tokens
never expired, which makes a leaked URL a permanent subscription to a child's
timetable). Live tokens are re-issued, not migrated.

⚠️ **`store_items` → `store_products`, and `items_json` becomes ROWS.** In v1
"how many hoodies did we sell" was a question no query could answer, and a line's
product could be renamed underneath it. Merchandise stays out of
`price_books`/`price_items`, which model tuition — a hoodie in there misstates
what it is and lands in every tuition report.

⚠️ `vetting_records` is the safeguarding gate (CLAUDE.md gotcha #10) and
**stores no check content and no offence data** by design. That constraint is
carried: the v2 table has nowhere to put a certificate scan, and
`test_schema_v2.php` asserts the absence of `certificate_scan`, `attachment_id`,
`findings`, `disclosure` and `offences` on both the table and every planned row.

⛔ **`leaderboard` has no v2 destination and must not get one.** The 2026-08
redesign refuses a cohort ranking; the v1 table and its two routes are to be
removed, not migrated.

## 6 · Conventions every new table must follow

From `db/schema-v2.sql` §2. These are what make v2 a platform rather than v1 with
new names, and a table that skips one is a table that has to be revisited:

1. **`tenant_id` is the LEADING column of composite keys.** A cross-tenant
   collision becomes a constraint violation instead of something a `WHERE` clause
   has to remember.
2. **`public_id CHAR(26)` (ULID) for anything externally addressable**, so URLs
   and exports leak neither row counts nor growth rate.
3. **Soft delete scoped by `deleted_seq`, not `deleted_at`.** Both engines treat
   NULLs as distinct in a unique index, so a key ending in a nullable `deleted_at`
   silently permits two LIVE duplicates. `deleted_seq` is NOT NULL, 0 while live,
   set to the row id on soft delete — live rows collide, and a natural key can be
   recycled any number of times.
4. **`row_version` for optimistic locking.** Two admins editing one cohort is
   routine and last-write-wins discards an edit silently.
5. **Money is integer minor units + ISO currency**, with the base-currency amount
   and the frozen rate REQUIRED by CHECK whenever they differ.
6. **`DATETIME(3)` UTC.** A wall-clock time that must survive a timezone rule
   change is stored local + IANA zone, never as an instant.

## 7 · Order of work, and what gates what

1. **Extend `db/schema-v2.sql`** — batches 1→5 above.
2. **Regenerate `db/schema-v2-sqlite.sql`** (`php api/tools/schema-v2-sqlite.php`)
   and keep `test_schema_v2.php` green; it asserts the two files agree and that
   the canonical file uses no ENUM, generated column or FULLTEXT index, because
   none of the three mirrors.
3. **Extend the ETL** — ✅ done. `api/modules/v2/lib/plan.php` writes **112** tables (all
   five batches). A table in the schema but not in the plan is a table that
   silently arrives empty on cutover day, which is worse than one that is
   missing — so against a real export the coverage ledger now accounts for
   **144** v1 tables with **zero** unmapped-with-rows, and the emitted SQL loads
   with constraint checking ON and a clean `foreign_key_check`.
   ✅ **`api/tools/v2-load.php` is plan-driven too.** It reads the whole export
   directory, walks `$plan['order']`, and derives id translation, idempotency and
   value encoding from the TARGET's own schema. Both write paths — the loader and
   `v2-transform.php` → `v2-data.sql` — now carry the same tables by
   construction. Its dry run inserts inside a transaction and rolls back, so it
   exercises every CHECK rather than reporting what it "would" do.
4. **Rewrite the call sites**, subsystem by subsystem, against a repository layer
   that enforces §6 in one place rather than in 1,478.
5. **Migrate the 114 test files.** They seed v1 tables directly, so they are not
   incidental — they are the only thing that will tell you the rewrite is right.

⚠️ **The ETL is the part most likely to be skipped and most expensive to skip.**
`docs/v2-cutover-readiness-2026-08-18.md` records five defects already found in
it, all of the same shape: a column carrying a foreign id that was copied straight
from v1 and therefore pointed at a different row. Every new table in batches 1–5
needs its foreign ids remapped through `legacy_reference_map`, and the ones with
no foreign-key constraint are the ones nobody notices.
