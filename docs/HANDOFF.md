# Handoff — the redesigns, the ETL, and what only MySQL finds

**Branch:** verify with `git log --oneline -3` and `git status -sb`.
**Started from:** production at `f3ca21e`, schema v59.

⚠️ A branch name and a commit hash were written here and both went stale — the
header named `claude/repo-audit-index-nr17nr` at `545af41` while the guardian
redesign was five commits further on, so an agent told to "pick this up" checked
out a branch with none of the work in it. The same trap CLAUDE.md's Workflow
section already warns about for its own branch name: **do not write a branch or a
hash into a document that outlives them.**

This is the working file for the current stream of work. `CLAUDE.md` holds the
permanent conventions and gotchas; this holds *what is in flight*, *what was
decided and why*, and *what the next person needs to know before touching it*.
When something here stops being in flight, it graduates into `CLAUDE.md` or one
of the `docs/*-audit-*.md` files and comes out of here.

---

## 1. State of play

| Stream | Status |
|---|---|
| Schema v2 design + SQLite generator | ✅ built, reviewed, not loaded |
| ETL — identity, payments, applications | ✅ transform proven |
| **ETL load + verify** | ✅ **shipped** — real snapshot, 16 of 16 checks, on **both SQLite and MySQL** |
| **v2 CUTOVER readiness** | ❌ **not ready** — 0 runtime files read v2; see `docs/v2-cutover-readiness-2026-08-18.md` |
| **v2 READ MODEL (option B)** | ✅ **shipped** — `?v=v2read`, read-only, env-gated; found 2 questionable identity merges |
| Admin usability ("impossible to use") | ✅ fixed and measured |
| Enrolment completion chain | ✅ built and driven end to end |
| Forms audit — 6 passes | ✅ complete; see `docs/forms-audit-2026-08-17.md` |
| Auto-enrol on payment settlement | ✅ **shipped** — you said yes |
| **Research reconciliation (5 docs)** | ✅ **plan written** — `docs/research-plan-2026-08-17.md` |
| **Safeguarding vetting + gate (schema v82)** | ✅ **shipped** — ships in `warn`, needs a decision to `enforce` |
| **Mastery Score coverage floor / bands** | ✅ **shipped** |
| **Instructor comparison → formative-only** | ✅ **shipped** |
| **Charges: gated in depth, flags split** | ✅ **shipped** |
| **Academic calendar + pro-rata refunds (schema v83)** | ✅ **shipped** — the first refund authority in the repo |
| **NDPA compliance layer (schema v84)** | ✅ **shipped** — code half done; the operational half is the academy's |
| **The guardian layer (schema v85–v88)** | ✅ **shipped** — reports, fees, threads, make-up credits |
| **Schema v2 extended to the whole product** | ✅ **141 tables**; the ETL writes 112 of them |
| **Admin console redesign (31 screens)** | ✅ **shipped** — `admin/console.php`; §4o |
| **Homepage hero redesign** | ✅ **shipped** — the card is gone; §4o |
| **The suite runs against MySQL** | ✅ **shipped** — found 7 defects that existed only there |
| **Every route swept against MySQL, logs read** | ✅ clean — found 3 more (break-glass 401s) |
| The console's phone subset (`#5b`) | not built — §6.2 |
| **Instructor portal redesign** | ✅ **shipped 2026-09-19** — seven screens on portal-v2; the legacy 13-tab dashboard stays at `classic.html`. `docs/design/2026-08-portal-redesign/BUILD-NOTES-instructor.md` |
| Student portal redesign | not started — still on the old palette |
| **Instructor application platform (schema v90)** | ✅ **shipped 2026-09-17** — see §4p |
| **Instructor application v2 (four-step rework)** | ✅ **shipped 2026-09-18** — `docs/design/2026-09-instructor-application/BUILD-NOTES.md`; one DSO sign-off outstanding |
| **Instructor policy issued on faculty apply** | ✅ **shipped 2026-09-19** — the 12-clause policy is emailed and web-viewable at `/api/policy/FAC-<id>` |
| **Site chrome rework (header · menu · footer)** | ✅ **shipped 2026-09-20** — `docs/design/2026-09-site-chrome/BUILD-NOTES-chrome.md`; chrome 145px → 101px, measured at nine widths |
| **Student application rework (same handoff, Part 2)** | ✅ **shipped 2026-09-20** — 12 screens, one question each; `docs/design/2026-09-site-chrome/BUILD-NOTES-apply.md`. One instruction refused (the focus-area merge) — the academy may want to decide it |
| Scholarship funds/awards, instalments, dunning | not started |
| TanStack Table v8 | decided, **not implemented** |
| htmx + strict CSP | needs a funding decision (doc 4's own framing) |

### The one thing that is genuinely blocked

Nothing. The ETL is complete end to end — export → transform → load → verify —
and has been run against the real snapshot with all 16 checks passing (§4f).

⚠️ **The ETL being ready is not the SITE being ready.** Measured 2026-08-18:
**zero** runtime files read v2, 92 of the 111 v1 tables the application queries
have no v2 counterpart, and the ETL carries 4 of the 38 tables holding real data.
The full assessment, with the three options and a recommendation, is in
`docs/v2-cutover-readiness-2026-08-18.md`. Read it before scheduling anything.

What remains before a cutover is not code:

- **Recreate courses, classes and programmes** in the new install. They are
  deliberately not migrated (reference data is re-seeded, never copied).
- **Decide the cutover window** and re-export at that moment: the loaded
  database is only as current as the snapshot it came from.
- **Re-run the load against MySQL 8 specifically.** It has now been run on a real
  server (MariaDB 10.11 — see §4g), which found two bugs SQLite could not. MySQL
  8's native JSON type is the one difference that could still behave differently
  from MariaDB's LONGTEXT-plus-`json_valid` implementation.

---

## 2. Decisions already made — do not re-litigate

- **Base currency is NGN.** `MAM_V2_BASE_CURRENCY` in `api/lib/v2/transform.php`.
  The wallet ledger stays USD cents; that is deliberate and unchanged.
- **Reference data is re-seeded, never copied.** Copying it silently orphans
  children: `ktams` is id 5 in production and id 22 in a fresh install, and the
  `UNIQUE(code)` conflict resolves in favour of the wrong row.
- **Courses and classes are not migrated** — you are recreating them.
- **Money is integer minor units + ISO currency + frozen FX**, enforced by a
  CHECK. Decimal→minor conversion is **string arithmetic**, because
  `8.29 * 100` is 828.99999999999989 in float (measured on PHP 8.4).
- **Erasure tombstones use the RFC 2606 `.invalid` TLD.** `mam_enrol_complete()`
  checks it *first*, before anything else, so an erased applicant cannot be
  re-enrolled by a well-meaning admin.
- **TanStack Table v8, not v9** — v8 ships a UMD build (`build/umd/index.production.js`)
  that self-hosts with no bundler, which this repo requires; v9 is unbundled ESM
  only. Headless, so it preserves the pinned actions column and the mobile
  stacked-card layout. **Recommended as progressive enhancement over the
  server-rendered tables, not as a client rewrite.** Not yet implemented.
- **The production export never leaves the scratchpad.** It holds real identity,
  payment, audit and consent rows. Docs carry aggregate counts only; test
  fixtures mirror its *shapes* with invented content.

---

## 3. Two patterns that produced most of the real bugs

Worth internalising before touching anything else, because both recurred.

### 3.1 A third-party CDN script that is load-bearing, behind a fallback that is not one

Twice, a core function was one blocked request away from being dead, and in both
cases the fallback *existed* and did nothing useful:

| Where | The fallback | What the user got |
|---|---|---|
| `apply.js` `initIti()` | `return null` before binding anything | A valid phone number rejected forever. **The wizard could not be completed.** |
| `libs.js` `MAM_DIALOG.toast` | `console.log('[toast]', message)` | Every instructor error message, including "could not record this mark", written where nobody looks. |

Ad-blockers, corporate proxies, a tightened CSP and one bad minute of mobile
network all produce this. **When you add a CDN dependency, write down what
happens when it does not arrive, and check that answer is one a user can see.**

Remaining CDN dependencies that are still load-bearing to some degree, in rough
order of exposure: `intl-tel-input` (now degrades correctly), SweetAlert2 (now
degrades correctly), Paystack/Flutterwave SDKs (named when blocked, but there is
genuinely no local substitute — the emailed payment link is the fallback), Font
Awesome (cosmetic), Google Fonts (cosmetic).

### 3.2 A ratio is only a finding if the denominator is the set that *could* have the property

I got a headline number wrong **four times** in this stream, in both directions.
Each is written into the tests that guard it:

| Claimed | Actually | Cause |
|---|---|---|
| "45 of 58 fields cannot autofill" | no violations | 45 of the 58 are radios/checkboxes, which take no token |
| "21 fields under 24px" | no violations | they are radios styled as cards; the target is the label |
| "350 of 350 admin controls unnamed" | 70 | CDP computes accessible names only after `Accessibility.enable` |
| "0 of 350 admin controls unnamed" | 70 | `queryAXTree` returns a `<select>`'s options, so "first named node" reports the option text as the label |
| "23 empty catches in the instructor portal" | 2 | a loose regex counted multi-line catch bodies as empty |

**Measure, then check the measurement, then report.** The accessibility-tree
recipe that is actually correct is in `test_admin_tables.php`, written down so
it does not have to be rediscovered.

---

## 4. What shipped in this stream

Six audit passes, all measured in a real browser, all documented in
`docs/forms-audit-2026-08-17.md` with the numbers.

**Severity 4 — things that were actively costing money or lying to people**

1. **The apply wizard could not be completed with the CDN blocked.**
   `initIti()` bound nothing when `intl-tel-input` was absent, so `data.phone`
   stayed empty however carefully anyone typed and the wizard rejected the
   number it could not see. On the academy's only revenue form.
2. **"Payment received" was shown when nothing had received it.**
   `afterProviderCharge()` treated any non-402 verify error as transient and
   showed the confetti screen. A 404 after a real charge → "Payment received.
   Our system will confirm shortly." Now limited to network/5xx/429.
3. **Instructor errors went to the console.** See 3.1.

**Severity 3**

4. The wizard had no `<form>` element at all — 57 of 58 fields orphaned.
5. Two of three failure banners rendered as unstyled body text: `role=null`,
   transparent, unfocused. Only `apiError()` dressed it.
6. "A photo is required" was written into a `.hs-status` element **that was not
   on the page**. The only feedback was a border pulse.
7. The payment step showed raw machine tokens — `init_failed`,
   `api_unavailable` — as the entire user-facing message.
8. **70 of 350 admin controls had no accessible name**, all per-row table
   controls. 21 identical "combo box, submitted" on the Applications screen,
   including the admin-tier select.
9. **20 of 51 instructor and 8 of 26 student controls**, same
   caption-as-label idiom: `<label>Score</label><input>` — a sibling with no
   `for`, correct on screen, associated with nothing.

**Everything above is fixed, re-measured, and pinned by tests.**

**Measured and deliberately NOT changed** — do not "fix" these:

- Autofill on the apply wizard (13 of the 18 fields that can autofill carry
  correct tokens; the other five have no meaningful token).
- Target sizes everywhere.
- CSRF coverage on admin forms — the three `module_*` forms carry the token via
  an interpolated `$hid` a naive grep cannot see.
- `session_revoke` has no confirmation, on purpose: one revoked session is
  undone by signing in again. `session_revoke_others` does confirm.
- The **guardian portal** was already correct — 4 of 4 controls named, both
  failure paths announced, none of the caption idiom.
- Three `programs` controls that the AX tree reports as unnamed: they are
  correctly labelled and sit inside a **closed `<details>`**, which Chromium
  drops from the tree while `getBoundingClientRect()` still reports a height.

---

## 4b. Auto-enrol on payment settlement (shipped)

You said yes, so this is live. What it actually changed matters more than the
headline, because settlement was *already* doing part of the job — badly.

`mam_payment_settle_tuition()` called `mam_lms_add_course()` + `mam_lms_approve()`
itself. Three gaps, each of which produced a **paid student nobody was teaching**:

| Gap | Consequence |
|---|---|
| No **programme** passed | The cohort resolved on `(course, level, NULL)` while the admin screen resolves on `(course, level, programme)` — **the same applicant landed in a different class depending on which path settled them.** |
| Instructor written to the **enrolment only** | `mam_lms_instructor_cohorts()` keys off either, so the roster appeared — but the class itself stayed teacher-less, and everyone enrolled on it afterwards inherited nothing. |
| Waitlisted branch returns `id === null` | `(int)$row['id']` read it as 0 and quietly did nothing more. A paid applicant whose class was full got silence. |

Settlement now delegates to **`mam_enrol_complete()`** — the same function the
admin's "Complete enrolment" button runs. One implementation of
account → course → cohort → instructor → application status, so the two paths
cannot drift apart again.

**The invariant to protect:** a settled payment must *never* fail because the
placement could not be worked out. The money is taken and the account exists.
Two real cases refuse placement, both correctly:

- `discipline` holds instruments that are not courses — `violin` and `clarinet`
  are both in the live data. Guessing a near-match would enrol someone in the
  wrong subject.
- An **erased** applicant must not be rebuilt and emailed a password.

Both are logged and land in the admin backlog that already exists
(`mam_enrol_incomplete()` → `?v=applications&f=incomplete`, and the
"Needs your attention" queue). The application still reads `paid`, the account
is still provisioned, and a human finishes the placement.

Gated by a **new automation, `payment_enrol`** — deliberately separate from
`payment_provision`. Giving someone a login and putting them in a class with a
teacher are different decisions, and an academy mid-term, or one that places
students after an Auditer assessment, needs the first without the second.

Covered by `api/tests/test_payment_enrol.php`: the whole chain, idempotency on a
retried webhook, the payment-never-fails rule, and the erasure guard.

⚠️ **Never hardcode the automation count in a test** — derive it from
`mam_automations_registry()`. Adding a rule has broken the suite twice that way.

---

## 4c. The five research documents (shipped tranche)

Full reconciliation in **`docs/research-plan-2026-08-17.md`**. The three things
worth knowing without opening it:

**The reports were measurably wrong about the code in two of their three most
urgent findings.** Doc 3 says to suspend both monetary charges as live; measured,
`late_charge_enabled` defaults to 0 and both call sites already tested it, and the
instructor progress-report "fine" writes ₦0. Doc 3 calls the instructor comparison
the highest-risk feature; it was already formative in fact — nothing automated
reads it and it was ordered by name, not score. **Reading the reports without
measuring first would have produced two removals of things that were not there.**

**The real defects were underneath, and one of them I introduced.** The charge
gate was read but never enforced; one flag governed both student fees and
instructor *wage deductions*; and — found by an adversarial pass over my own first
implementation — both call sites *also* tested the flag, short-circuiting the
function so that with charging off no row was written at all and the lateness
vanished. The instructor call site was testing the student flag.

**Safeguarding was the genuine gap, and it was total.** Nothing recorded staff
clearance and nothing checked it, on a system that schedules adults one-to-one
with children. `api/lib/vetting.php` + schema v82 + `?v=vetting` now do, gated
into `mam_lms_approve()`. It ships in **`warn`** deliberately — see §9.

**Where the research disagrees with `CLAUDE.md`, and who wins:** doc 3 says stop
renormalising the Mastery Score weights; `CLAUDE.md` documents renormalisation as
a deliberate fix. Both are right about different things — excluding an unevidenced
component is correct, publishing the renormalised result as a comparable number is
not. The operative word in doc 3's criticism is *silently*. Resolved by withholding
the composite below a coverage floor rather than by scoring absent components zero.

---

## 4d. The academic calendar (shipped — schema v83)

Both blueprints made this the dependency for refunds, scholarship pro-ration and
the "W" outcome. They were right for a reason neither stated: **there was no
refund function anywhere in the codebase.** `academic-session.php` computes which
term it is from constants, which is enough for a label on a page and nothing else.
Every refund was therefore a manual decision with no published table to check it
against — the gap wasn't a missing feature, it was a missing *authority*.

`api/lib/calendar.php` + `?v=calendar` supply it. What it deliberately does **not**
do is replace the computed engine: `mam_calendar_current_term()` prefers an
authored row, falls back to the computed window, and tags which it returned
(`source`). An install with an empty calendar behaves exactly as before, which is
why this shipped without touching `/apply`. Strangler, not cutover — doc 4's own
recommendation.

Four design decisions that are load-bearing (all restated in `CLAUDE.md` #14):

| Decision | Why the obvious alternative is wrong |
|---|---|
| Refund % **computed from a date**, never stored per student | Two students withdrawing on the same day must get the same answer, reproducibly, a year later in an audit |
| `term_refund_schedule` rows win over `MAM_CALENDAR_REFUND_DEFAULT` | A family is entitled to the table published when they enrolled. Seeding defaults to `$replace = false` so re-saving dates can't revert an edited table |
| `term_census` **append-only** (UNIQUE on `term_id`) | "Enrolment as of the census date" must not move when somebody withdraws in week 9. Recompute-on-read rewrites history silently |
| Deadlines adjudicated in **Africa/Lagos**, displayed locally | A diaspora guardian in New York must not get a different refund because their clock disagrees about the date |

`mam_lms_withdraw()` now records **which kind** of withdrawal it was (add/drop vs.
a W with its percentage) into the transition reason, so it lands in
`enrolment_events` where anyone reconstructing "why does this record say that"
will read it. Best-effort by design — an unauthored calendar must never stop a
student leaving a course.

Verified in a browser: 4 terms seeded for 2026, the current term marked
`current · week 5`, the KPI source flipping from "computed dates" to "the
calendar" as soon as rows existed, and the published table rendering
`100% 100% 80% 60% 50% 40% 25% 10% 0%`. `api/tests/test_calendar.php` — 75+
assertions.


## 4e. The NDPA compliance layer (shipped — schema v84)

`datarights.php` could already export and erase. What did not exist was the
**record**: an admin typed an email, downloaded a bundle, and no trace of the
request survived. Being *able* to answer a rights request is not the compliance
artefact — the log of which requests arrived and whether they were answered in
time is, and so is the register of what is held, on what basis, for how long,
and who else sees it.

`api/lib/dataprotection.php` + `?v=dataprotection` (seven tabs) supply all of it:
the request log with its clock, the breach register with its 72 hours, the ROPA,
the retention schedule, the processor/DPA register and DPIA records.

**The design decision worth knowing.** The ROPA and the retention schedule are
**code registries**, not tables with a CRUD form. Each purpose names the tables
that implement it; each retention rule names a real table and date column; and
the test suite asserts both against the live schema. A register describing what
the software does has to be derived from the software — typed into a form it
drifts the day a feature ships, and a *drifted* register is worse than a missing
one, because somebody has signed it. A stale register now fails CI.

Four invariants, all restated in `CLAUDE.md` #15:

| Invariant | Why the obvious alternative is wrong |
|---|---|
| `delete` only on **leaf** tables (asserted against the live FK graph) | A cascade through an enrolment would destroy academic history to tidy up a note. Academic records, payments, vetting and the audit log are all `review` — never swept |
| A period may be **lengthened, never shortened** | The schedule states a lawful basis for each period; a smaller number typed into a form deletes lawfully-held records |
| Sweep ships in **`report`**; a dry run overrides `enforce` | Same reasoning as the safeguarding gate — a job that deletes on the first run of an unreviewed install is how an academy loses what it needed, and the reliable outcome is somebody disabling it |
| Breach clock runs from **awareness** (`discovered_at`), not occurrence | s.40(2)'s trigger. Measuring from occurrence makes an instantly-reported month-old breach look late |

**Two things the research got wrong, and one it could not have known.** The plan
said to "assess the >200 threshold, register with the NDPC, diarise the annual
CAR by 31 March". Measured: the threshold is 200 distinct subjects **in a rolling
six months**, and **Ordinary-High Level (201–1,000) registers but does not file
CARs at all** — filing starts at Ultra-High. MAM currently measures **19**, which
is below the DCPMI threshold entirely, so none of it bites yet. That is exactly
why `mam_dp_tier()` computes the position instead of leaving it as a diary note:
the academy crosses the line by *growing*, not by deciding to.

And the response deadline everyone quotes is **not in the NDPA** — the repealed
NDPR had one; the Act does not. Thirty days ships as the academy's own
commitment, labelled as such on the screen, and it is a setting.

⚠️ The day-counts and tier thresholds were verified 2026-08-17 from published
commentary — `ndpc.gov.ng` is blocked by this environment's egress proxy, so
none of it came from primary text. They live in one constant block with their
provenance. Confirm with counsel before enforcement.

**The operational half is not code and is not done**: registering (when the
count crosses), naming a DPO (the screen shows "not named"), and the DPIAs — the
gap list currently names six sensitive purposes with no assessment.

Verified in a browser across all seven tabs: requests logged and closed, a
refusal without a reason refused with a readable message, a breach recorded with
its clock badge, preview leaving the counts untouched, a shortened period
refused with an explanation, and a recorded DPA closing Cloudflare's gap.
`api/tests/test_dataprotection.php` — 130+ assertions.


## 4f. The ETL load phase (shipped)

`load.php` and `verify.php` were the last piece of the migration that did not
exist. They do now, and both have been run against the **real production
snapshot**, not just fixtures:

```
identity : 16 customers + 10 users → 21 people (5 merged, 2 erased, 0 without an address)
people   : 21 created, 9 credentials carried
apps     : 26 of 26 loaded, 0 rejected
payments : 15 of 15 loaded, 0 rejected, 0 with no owner
verify   : 16 of 16 checks passed
```

**The money reconciles, and the old figure is accounted for.** Total NGN
515,998.00 across 15 payments, of which **NGN 405,034.00 is the
`awaiting_transfer` bucket** — the exact figure the payment transform matched
against the admin screen earlier in this stream. It lands intact; the remainder
is NGN 104,046.00 still `pending` and NGN 6,918.00 `succeeded`.

**Four properties, each because the obvious alternative is unrecoverable:**

| Property | Why |
|---|---|
| Idempotent via `legacy_reference_map` | The first attempt at a real migration is never the last. A second run reports 0 created, 21/26/15 already present, and still verifies 16/16 |
| One transaction for the whole load | A partial load looks populated and reconciles against nothing — worse than no load |
| **Dry run is the default** | `--commit` has to be typed. The destructive direction is never what you get by forgetting a flag |
| Verify reconciles by an **independent path** | A verifier built on the transform's own arithmetic agrees with a transform bug enthusiastically. `mam_v2_verify_minor()` parses the source strings by regex where `mam_v2_minor()` splits and pads, so the two disagree if either is wrong |

The verifier is tested **by corrupting a good load** six ways — a deleted
payment, a one-kobo edit, a dropped person, a tombstone regaining a password, a
row that came from nowhere, and an empty target — because a verifier nobody has
watched fail is not evidence of anything. Note that it catches **invented** data
as well as missing data; that is the direction people forget to check.

**Two bugs the real run found that the tests did not**, both worth remembering:

1. **The dry run was writing.** Only the row loaders honoured `commit`; the seed
   steps (tenant, centre, currencies) wrote unconditionally, and outside the
   transaction, so they were not even rolled back. A dry run that writes anything
   is not a dry run. Now pinned by six assertions.
2. **"15 payments with no owner"** read as a catastrophic data finding and was an
   artefact: on a dry run there are no membership ids yet, so judging ownership
   by them condemned every row. The question that means something in both modes
   is whether an owner could be resolved *at all*.

**Decisions worth knowing:** password hashes ARE carried (bcrypt; forcing every
family to reset on cutover day turns a migration into a support incident and
trains people to click reset links in unexpected email) — but never for an
erased person, because a tombstone that can log in is not a tombstone. v1
timestamps are carried verbatim rather than converted, since no timezone is
recorded on the rows and a wrong conversion is worse than an honest carry-over.
A payment with no owner is still loaded — the money is real — and reported.

⚠️ **`.invalid` is the tombstone marker.** Test fixtures for *live* people must
not use it, or every fixture person reads as erased (this cost time). The load
also mints `@migrated.invalid` for people with no address at all, which is why
`erasure.live_invalid_addresses` pins that set to exactly the snapshot's blanks.


## 4g. The MySQL run (and the two bugs only it could find)

The load had only ever been exercised on SQLite. Run against a real server —
**MariaDB 10.11, not MySQL 8**, which is the honest caveat below — it failed
twice before it passed, and both failures were invisible in dev for the same
reason: **SQLite does not enforce VARCHAR lengths and MySQL in strict mode
does.**

**1. `applications.source_channel` (VARCHAR(48)) was receiving a landing URL.**
v1's `applications.source` holds
`https://academy.mamcentre.org/apply?pathway=certified-musician` — 62
characters. Truncating to fit would have been worse than the crash: the column
means an attribution *channel*, so 23 applications would have shared one
"channel" and two more differed only by query string. The fix derives the
channel (the host for a URL, the token itself when it is already one, `other`
for long free text) and **keeps the full URL in the application payload**, so
nothing is lost.

**2. `payments.status` was VARCHAR(16) while its own CHECK permitted
`awaiting_transfer` (17) and `partially_refunded` (18).** A column that cannot
hold a value its own constraint allows is always a bug, and this one sat on the
**dominant status in the real data** — 11 of 15 payments. Widened to VARCHAR(24)
in the canonical schema, mirror regenerated.

**The durable fix is the tests, not the fixes.** Two new checks run on SQLite
and would have caught both without a database:

- every transformed string value is measured against the width the **canonical
  MySQL schema** declares for its column, parsed out of `db/schema-v2.sql`;
- no CHECK anywhere in the schema permits a value its own column cannot hold
  (swept across all 64 tables).

**What the real engine then confirmed that SQLite cannot:**

> The table figures in this section and in §4m are the schema **as it stood on
> 2026-08-18/19** — 64 tables. It is 141 now (all five batches authored) and the
> ETL writes 112 of them. These runs are left as the record of what was verified
> when, not corrected into a claim about today.


| | Result |
|---|---|
| Canonical `db/schema-v2.sql` applies clean | 64 tables, 51 CHECK constraints registered |
| Full load | 21 people, 26 applications, 15 payments, **16 of 16 checks passed** |
| Money | NGN 515,998.00 total, **NGN 405,034.00 `awaiting_transfer`** — the admin-screen figure, on a real engine |
| Idempotency | second run: 0 created, all 62 rows recognised, still 16/16 |
| CHECKs actually enforced | `chk_pay_status`, `chk_app_rejection_reason` and `chk_pay_fx` all reject a bad row |
| Transaction rollback | a mid-load failure left **0 users, 0 memberships, 0 map rows, 0 tenants** |
| JSON | all 20 non-null payloads valid to the engine; 6 null (erased applicants) |

⚠️ **This was MariaDB 10.11.** It enforces CHECKs and strict mode exactly as
MySQL 8 does, which is what the run was for. The one thing it does not share is
the **native JSON type** — MariaDB implements `JSON` as `LONGTEXT` with a
`json_valid` CHECK. Everything above holds; a MySQL 8 run before cutover is
still worth ten minutes.

Reproduce (no init system in this container, so the server is started by hand):

```bash
mariadb-install-db --user=root --datadir=$SB/mysql/data
mariadbd --user=root --datadir=$SB/mysql/data --socket=/tmp/mam-m.sock --port=3399 &
mariadb --socket=/tmp/mam-m.sock -e "CREATE DATABASE mamv2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mariadb --socket=/tmp/mam-m.sock mamv2 < db/schema-v2.sql
php api/tools/v2-load.php --export=$SB/export \
  --dsn="mysql:unix_socket=/tmp/mam-m.sock;dbname=mamv2;charset=utf8mb4" --user=root --commit
```

---

## 4h. The transform, delivered as a dataset (shipped)

The brief changed mid-stream: the schema restructure is being loaded by hand, so
what is wanted is the **data**, transformed, in a form other tooling can apply.
That is a different shape from `v2-load.php` — it must open no database at all.

```bash
php api/tools/v2-transform.php --export=$SB/export --out=$SB/v2-out            # MySQL
php api/tools/v2-transform.php --export=$SB/export --out=$SB/v2-sq --dialect=sqlite
mariadb DB < db/schema-v2.sql && mariadb DB < $SB/v2-out/v2-data.sql
php api/tools/v2-verify.php --export=$SB/export --dsn=... --user=root          # 16/16
```

**Coverage went from 4 v1 tables to 24 v2 tables.** The full table is in
`docs/v2-cutover-readiness-2026-08-18.md`. The headline: 31 people, 36
applications (26 programme + 10 summer, now `type='summer'` rather than a
separate system), 15 payments at ₦515,998.00 exact, 96 modules, 51 programme
mappings, 5 cohorts with both v1 enrolment models converged onto one spine, 239
hash-chained audit rows, 452 reference-map entries. 16 tables with rows are
deliberately **not** carried and each says why in `_coverage.json`.

### Four decisions worth knowing

**Explicit ids, not AUTO_INCREMENT.** The output has to be loadable by somebody
else, which means the foreign keys must already be resolved in the file, which
means the plan assigns the keys. `public_id` is *derived* from the source row
rather than a random ULID for the same reason — two transforms of one export are
byte-identical, so a diff between them is a real change and not a new set of
surrogate keys. There is a test for that.

**Two quoters, not one.** MySQL treats `\` inside a string literal as an escape;
SQLite does not. A single "portable" quoter is quietly wrong on one of them, and
the damage lands on exactly the values most likely to contain a backslash — a
path, a regex in a settings row. `--dialect` picks. A NUL goes out as a hex
literal, because inside a quoted literal it truncates the statement in some
clients rather than erroring.

**Constraint checking stays ON.** The statement order satisfies every foreign
key, so nothing disables it. `SET FOREIGN_KEY_CHECKS = 0` during a load is how a
migration ends up with orphans nobody notices until a JOIN goes missing; a test
asserts the string never appears in the output.

**The audit chain is computed at transform time.** `row_hash = sha256(prev_hash
|| canonical(row))`, ordered by `occurred_at` with the source table and id as a
deterministic tiebreak. A chain stamped after the fact proves nothing about the
migration. It re-verifies straight out of the loaded database, including the IP
pack/unpack round-trip through `VARBINARY(16)`.

### The identity rule was wrong, and this found it

v1 records the customer↔user link **explicitly**, in `users.customer_id`, and the
email-keyed resolver was ignoring it — inferring from addresses what the data
already stated. Three of the four real merges are stated there. The rule now:

1. `users.customer_id` wins outright.
2. A shared address merges only where the names agree
   (`mam_v2_names_agree()` — exact token-set equality after case folding, and
   deliberately not fuzzy: every fuzzy rule that would merge "App Admin" with
   "Application Domain" also merges names that are genuinely different).
3. A shared address with disagreeing names and no explicit link **does not
   merge**; it is raised in `report['review']`.

Rule 3 is the point. In the live data one address belongs to an instructor and
was also used to apply for a child — the old rule fused a learner's record into a
staff one, which is the identity mistake with no way back. A split needs distinct
addresses because `users.email` is UNIQUE: the login keeps the real one, the
applicant gets an unroutable derived address, and the **real address is carried on
the review item** so it can be acted on without going back to the export.

Real export: **22 people, not 21** — 3 merged by the explicit link, 1 by
address+name, 2 kept apart and flagged, 0 name conflicts left.

⚠️ `mam_v2_person_index()` now reads `customer_ids` (plural). Two v1 customer
rows on one address resolve to one person, and indexing only the singular
`customer_id` silently lost the second — its applications would have come out as
"no resolvable applicant".

⚠️ `mam_v2_verify_people_expected()` restates the rule by a **different
mechanism** (a union-find over source rows, not the resolver's sequential passes)
so a sequencing bug in one shows up as a disagreement instead of being reproduced.
Its split counter must skip customers that ARE explicitly linked, or every
married or shortened name counts as a split — that was a real false failure.

### What was proved rather than asserted

* The emitted SQL applies to both `db/schema-v2.sql` (MariaDB 10.11 under
  `STRICT_ALL_TABLES`) and `db/schema-v2-sqlite.sql`, constraints on;
  `PRAGMA foreign_key_check` clean afterwards.
* `v2-verify.php` 16/16 against each, over a **read-only** connection — a
  verifier that can write is a verifier that can make itself pass.
* It fails when a single kobo is altered under it. Asserted, not hoped.
* 239 audit rows, 0 broken chain links, verified out of the database.
* Suite 4067 green.

### Still open

`MariaDB 10.11 is not MySQL 8` — the load has not been rehearsed on 8.x. Zero
runtime screens read v2. The five unmigrated-table decisions in §4f stand. And
the `?v=v2read` read model shipped in the previous turn is now surplus to the
stated need: it is env-gated and absent unless `V2_READ_DB` is set, so it is
inert, but it should be removed or kept deliberately rather than by default.

---

## 4i. The migration is loaded (2026-08-18)

It is done. The v2 database is loaded on the live host, in a database beside v1,
and verified. Nothing on the site reads it — this was the staging step, not a
cutover, and the next decision (which screens read v2 first) is separate work
that deliberately did not ride on the back of it.

**Final shape:** 29 people · 33 applications (23 programme + 10 summer) · 15
payments at ₦515,998.00 · 5 cohorts with both v1 enrolment models converged · 96
modules · 244 hash-chained audit rows · 447 reference-map entries. Verifier
16/16; the SQL checks 29/29.

### What the host's constraints forced, and why each was worth it

The academy runs on cPanel with **no SSH**. Three things came out of that, none
of which were in the original design:

* **`--with-schema` → `v2-full.sql`.** The first hand-load produced
  `ERROR 1146 … tenants doesn't exist` — the schema had not reached that
  database. Neither file was at fault, but "apply A, then B" is a step a person
  can get wrong at 1am in a phpMyAdmin tab, and the error it produces reads like
  a broken export. One file cannot be applied out of order. The data file's
  header now also names 1146 as its own symptom.
* **`v2-checks.sql`.** Whoever hand-loads a `.sql` is by definition least likely
  to have a PHP shell that can reach the database. It carries the same
  expectations as literals and covers every database-side check the verifier
  makes; its header names the two it cannot (whether each source amount parsed
  exactly, which is a question about the export, and a foreign-key sweep, which
  MySQL enforces on write).
* **`--batch=N`.** `max_allowed_packet` on a shared host can be smaller than one
  hundred-row INSERT, and that failure arrives as a dropped connection rather
  than an error naming the cause.

⚠️ For a re-export without a shell, `api/tools/export-web.php` already exists —
token-gated, dropped into a `Require all denied` directory. It is how the export
this migration ran on was taken. Set `MAM_EXPORT_TOKEN`, use it, then **delete
the directory**.

### MySQL 8 is no longer an untested claim

Installed 8.0.46 and ran the whole thing: `db/schema-v2.sql` applies clean (64
tables, `schema_version` 200), the data loads, verification 16/16, and Yorùbá
names round-trip byte-identically — 26 characters, 36 UTF-8 bytes, in and out.
Every identifier in the schema and the emitted column lists was checked against
MySQL 8.0's reserved-word list; none collide. MariaDB 10.11 and SQLite were
already proven.

### The three decisions came back, and one of them was measured

* `customers:6` and `customers:7` — **both test runs**, not a guardian applying
  for a child and not a real applicant. Recorded in `db/v2-exclusions.json`.
* The 48 abandoned drafts — **left out**. 26 carry no address at all and 11 of
  the 18 distinct addresses had already applied, so the "largest single pool of
  prospects" was **seven** reachable people. That measurement changed the answer;
  the earlier note was counting rows.

`db/v2-exclusions.json` is data, not code, because the answer came from the
academy. Read by both the transform and the verifier. Guards: it cascades to
applications but **refuses outright** if an excluded person holds a payment; an
exclusion matching nothing is an **error**, not a no-op; and every removal is
written into the v2 audit log as `migration.excluded` so the database explains
its own gaps.

⚠️ **A latent bug this exposed.** `audit_log.entity_id` was copied straight from
v1 while every other id in v2 is a v2 id — so the column looked joinable and
pointed at a different row. It is remapped through the reference map now, with
the v1 id kept in the metadata and NULL where it cannot resolve. Worth
remembering as a class of bug: *any* column carrying a foreign id through an ETL
needs remapping, and the ones without a foreign-key constraint are the ones
nobody notices.

### The one loose end

`api/lib/v2/readmodel.php` and admin `?v=v2read` were built for a plan the
academy then changed. They are env-gated and absent unless `V2_READ_DB` is set,
so they are inert — but they should be removed or kept deliberately rather than
by default. One commit either way.

---

## 4j. Check-in, corrected to the academy's actual model (2026-08-19)

⚠️ **The student-initiated flows were built on the wrong assumption.** The children
here are young: a nine-year-old does not arrive with a phone. The academy's model
is that **the instructor logs the session and signs each child in from the QR on
their ID card**, and **summer camp arrives as a group and is marked in one action**.

Everything student-facing still exists and still works — `/checkin`, `/attend`,
the portal tab — and is right for the older students and for anyone attending
online. It is just not the primary path, which is worth knowing before designing
anything else around it.

| Surface | Who initiates | Writes | Notes |
|---|---|---|---|
| **`/scan`** (new) | instructor, phone | `session_attendance`, `method='card'` | the primary path |
| group button on `/scan` | instructor | whole roster | summer camp |
| `/attend?token=` | student scans instructor's QR | either model | offers `/checkin` when the QR is the problem |
| `/checkin` | student, phone | `session_attendance`, `method='self'` | one-tap, no SPA |
| portal Check-in tab | student | as above | where you READ about a session |

`POST /api/instructor/card-scan` already existed with **no UI at all** — jsQR was
vendored, the endpoint worked, nothing anywhere opened a camera. `/scan` is that
page. `mam_lms_mark_attendance_all()` + `POST /api/instructor/session-checkin-all`
are the group half and are new.

Three things in `/scan` that are not obvious and will look like noise until they
are removed and something breaks:

* **The result map is keyed to the endpoint's exact strings.** It returns
  `marked`, not `present`, and an unknown key falls back to `invalid` — so my
  first version rendered every SUCCESSFUL scan as "Not a MAM card" while the child
  was being marked. Silent and total. The test derives the set from the route
  source so they cannot drift.
* **A code held in frame is debounced 4s.** Without it one card is twenty marks.
* **Only the framed centre is decoded.** Decoding the whole frame picks up the
  next card in the queue behind the one being presented.

⚠️ `mam_lms_mark_attendance_all()` does **not** overwrite by default. An
instructor who recorded somebody late must not lose it by pressing the group
button afterwards, and one student's failure is collected and NAMED rather than
aborting a register halfway — "three failed" is not actionable.

## 4k. Summer is a crash-programme intake whose students are students

Two earlier decisions reversed, both by the academy:

* **`MAM_SUMMER_PROGRAMME_CODE = 'crash'`.** The `SUMMER-<year>` cohort carries the
  crash programme, without which it sat outside the catalogue, the curriculum plan
  and every programme-grouped report. In the ETL the applications are
  `type='programme'` against crash with the route kept as `intake: 'summer'` in the
  payload — `type='summer'` would have rebuilt in v2 the parallel structure the
  migration exists to end.
* **Full accounts, not `roster` identities.** A summer student gets the portal, the
  gradebook, practice logging and their own attendance record. The **guardian** gets
  an account and a `parent_links` row in the same call, because an adult who can see
  everything the child can is what makes a child's login safe rather than merely
  convenient.

## 4l. No password is ever emailed

Every enrolment path used to email a temporary password — settlement printed one in
a dashed box and told the family to change it on first sign-in. That credential
then sits in an inbox for years, is usually reused, is readable by anyone holding
the phone, cannot be revoked once sent, and for a children's academy that inbox is
usually a shared parental one. It also teaches the habit phishing depends on.

`mam_auth_invite()` / `mam_auth_send_invite()` / `mam_auth_unusable_password()`
(`auth.php`) replace it. Non-obvious parts:

* **`password_resets` is reused on purpose.** It is already a single-use, hashed,
  expiring token that sets a password and revokes other sessions. A second
  `invites` table would mean two expiry rules, two revocation paths and two places
  to get the hashing wrong.
* **`MAM_INVITE_TTL_SEC` is 7 days, not the reset path's hour.** A reset is
  answered in minutes; an invitation is opened when a parent next looks at their
  phone. An expired invitation on enrolment day means a family that cannot get in.
* **The unusable password is real bcrypt over 32 random bytes**, not a sentinel
  string like `roster:…`. A column that sometimes holds a hash and sometimes holds
  a marker invites callers to pattern-match it.
* **`?welcome=1` changes only what the page SAYS.** The token is the whole of the
  security. "Set a NEW password" to somebody who never had one is confusing, and
  the failure state's 60-minute line is wrong for an invite.
* **The guardian gets their OWN link.** The child's link would set the child's
  password.

Driven in a browser, not just asserted: invite → "Create your password" → sessions
revoked → child signs in to the student portal; the guardian's own link lands in
the parent portal; the same link refused on reuse.

## 4m. The v2 artefacts, checked end to end (2026-08-19)

"Is v2 functional" has a precise answer, and it is worth separating from "does the
site run on v2", which it does not — **zero screens read a v2 table.** What exists
is a schema, a transform, a verifier and one admin read model, and all of it works:

| # | Check | Result |
|---|---|---|
| 1 | `schema-v2-sqlite.php --check` — the mirror is generated, not hand-edited | ✓ matches |
| 2 | Two transforms of one export | ✓ byte-identical |
| 3 | Rejected rows / unmapped tables with rows | 0 / 0 |
| 4 | `db/schema-v2.sql` + data on **MySQL 8.0.46**, constraints ON | ✓ 64 tables, `schema_version` 200 |
| 5 | `v2-verify.php` vs the export, read-only connection | **16/16** |
| 6 | `v2-checks.sql` | **29 PASS, 0 FAIL** |
| 7 | Foreign keys enforced by the engine | 112 |
| 8 | Same build on SQLite, `foreign_key_check` | ✓ clean, 16/16 |
| 9 | Audit hash chain re-verified from the database | 244 rows, 0 broken links |
| 10 | Read model, pointed at the loaded database | 29 people / 33 apps / 15 payments; an UPDATE refused |

Data: 29 people, 33 applications (10 summer-route crash), ₦515,998.00, 3
guardianships, 5 exclusions recorded in the audit log.

**What "functional v2" would additionally require** — none of it started:
authentication against `user_credentials`, a session layer on `auth_sessions`, and
one screen at a time repointed from v1 tables to v2 ones behind a flag. That is the
work the dashboard redesign should be sequenced with, not before it.

---

## 4n. The guardian portal redesign — the guardian surface is DONE (2026-08-19)

⚠️ **Read this before touching `portal/parent/`, `assets/css/portal-v2.css`, or
the five subsystems below.** The guardian portal is rebuilt and working; the
student and instructor portals are not, and both still use the old palette.

### What was asked

The academy sent `Mobilefirst_portal_redesign.zip` — a self-contained design
package (mobile 390, desktop 1440, plus a staff console) with a written developer
handoff. The instruction was **build exactly as-is**, integrating by **deleting
the old styles and redesigning, not patching**, guardian portal first, and *"some
features in it may have not been created — create them and ensure they work."*

| File | Role |
|---|---|
| `Portals Mobile.dc.html` → `#3b data-phone="guardianv2"` | **canonical**. Six screens: dashboard · children · reports · finance · consents · messages, a More bottom-sheet, a five-tab bottom bar |
| `Portals Desktop.dc.html` → `g-overview` `g-progress` `g-attend` `g-money` | the same information given room; child switcher **pinned above** the left rail |
| `HANDOFF-student-guardian.md` | the tokens, component recipes, the ⛔ refusals, build order |

Where mobile and desktop disagree, **mobile wins** — the handoff says so.

⚠️ **Two corrections to what an earlier pass of this section claimed:**

- The written handoff §3 specifies **SIX** desktop rail destinations (Overview ·
  Progress & reports · Attendance · Fees · Consents · Announcements). The mock
  implements **four** — Consents and Announcements are rail labels with no
  `data-goto`. The written handoff is authoritative for the destination set; the
  mock's gap is a gap, not the spec.
- The design's endpoints are written `/api/guardian/*`. They map onto this repo's
  existing `/api/parent/*` group and role `parent`. A parallel `/guardian` group
  would mean two middleware stacks to keep in step for one audience.

### The refusals — do not "fix" these into existence

**There are EIGHT**, not seven, and the package's own two documents disagree about
which seven they list — so this is the union, with the eighth (the access hold)
kept inside the numbered list because the fee subsystem is the thing most likely
to break it:

1. No automatic pitch/rhythm scoring of a child's playing.
2. Teach-back is **three** self-checks and **formative only** — never in the band.
3. Spaced retrieval is opt-in, never scored; skipping costs nothing.
4. Intervention flags are **staff-side only**. The family sees the booking, never the flag.
5. **No cohort effort board / leaderboard.** It exists in v1 and is to be removed — see the removal stage in the status table below.
6. **No guardian scope** — not even "Full" — grants practice logs, teach-back recordings, or a teenager's messages with their instructor. Enforced server-side in `mam_parent_scope_allows()` via `MAM_PARENT_NEVER`, which is checked FIRST.
7. Never an optimistic write tick.
8. **An access hold never touches the academic record, a safeguarding-relevant file, or a minor's report.** Kept by leaving the mechanism unbuilt: there is no function and no column in `fees.php` or v87 that suspends anything, and `mam_fee_statement()` returns *"Nothing is withheld from a child over money"* as DATA so the screen cannot forget it.

Also policy, not copy: **lateness is recorded for everyone and charged to nobody**
on a guardian-facing screen.

### Tokens — landed, and the provenance matters

`assets/css/portal-v2.css` was **rewritten clean** (twice — the second time after
the audit), and every value is marked:

- ✱DESIGN — one of the **21** custom properties §1 declares. Not 32, which an
  earlier pass of this section claimed: 21 come from the designer and the rest
  were named locally. An agent told all of them are contractual will not question
  the ones that are not.
- ✱MOCK — a value the guardian frame demonstrably uses that §1 never named (the
  18px card radius, `#F6F7F9`, the 73px bar).
- ✱OURS — five decisions, each with its reason in the comment.

**Three of the five ✱OURS exist because the mock breaks the written handoff's own
§4 accessibility contract**, and §4 wins because the handoff says it does:

- **44px, not 34px, on mobile.** 34px is explicitly a DESKTOP figure ("Desktop
  controls ≥24px, 34px used here") and now applies only above 900px.
- **12px is the floor for running text.** The frame puts 10px and 10.5px on the
  stat captions and 11.5px on the chips — below its own stated minimum, for
  exactly the readers §4 names as the reason for it.
- **`--good` is not a text colour.** Measured 4.36:1 on white and 3.83:1 on its
  own fill, against §4's contractual 4.5:1, and both places the mock writes words
  in it are small bold text (WCAG large text needs 18.66px bold). `--good-text`
  (#0B6E4C, 6.27:1) carries words; `--good` keeps the bars.

Two more measured corrections to figures copied verbatim from the design:
`--text-3` is **5.02:1** on white (§1 says 4.6) and **4.44:1** on `--sunken`,
which fails — that last pairing is the whole reason there are three greys.

**Sticky-bar reserve:** the mock's scroller uses `padding-bottom:108px` under a
73px bar. §4 requires bar + 40px, so the mock is below its own rule.
`--bar-reserve: 120px` is right, and both it and the bar grow by
`env(safe-area-inset-bottom)`.

⚠️ **The file is SCOPED under `[data-portal="v2"]`, reset included.** `main.css`
owns `--bg`/`--text`/`--raised` site-wide and `portal.css` reads them ~30 times
while declaring none, so a bare `:root` block here repaints the dark portal theme
with this light palette on any page loading both — and every surface this file
does not override keeps its dark value, giving dark text on dark ground. The
student and instructor portals are not redesigned yet, so both stylesheets have
to coexist. Put `data-portal="v2"` on `<html>` and it applies; leave it off and
the file is inert.

### The guardian payload was shipping a raw mean — now actually fixed

The first attempt added `mastery_band` BESIDE the raw gradebook mean and recorded
the defect as closed. Nothing read the new key: both places the screen rendered
mastery still printed `Math.round(w.mastery) + '%'`, and `mastery_light` was still
quantised from the mean, so a child with 15% of their work assessed showed
"Mastery 82%" behind a GREEN light.

**The lesson worth keeping: a correct value beside an incorrect one is not a fix
while anything downstream can still print the incorrect one.** The mean is now
ABSENT from the payload (not nulled — absent, so a client still reading it fails
visibly instead of rendering 0%), and `mastery_light` derives from the reported
LEVEL, so level 0 reads as an absence.

The claim that a key allowlist meant `raw_score` "cannot leak" was also false:
`bounds.min` is `raw × coverage ÷ 100`, so `raw = bounds.min × 100 ÷ coverage_pct`
recovered it from two allow-listed keys. `mam_mastery_publishable()` is now the
one predicate for what a student or guardian may see, shared with
`GET /api/student/mastery` — which was returning `raw_score` to the student's own
browser.

⚠️ It does **not** claim to make the figure unrecoverable, and the comment says
so: `components` carries a percentage and a weight each, the weight table is a
public constant, and subscores are published on purpose. What the coverage floor
governs is what the academy **states** as a comparable headline figure, not the
information content of the payload. The earlier claim was theatre.

### Five subsystems the design needed — all built

| Subsystem | Where | Schema |
|---|---|---|
| Ratified termly reports, moderation, mark appeals | `api/lib/reports.php` | v85 |
| Guardian access scopes (§3.2) | `api/lib/parents.php` | v86 |
| Fee invoices, four kinds of aid, payment plans, bursaries | `api/lib/fees.php` | v87 |
| Guardian↔staff threads, channel prefs, make-up credits | `api/lib/guardian-comms.php` | v88 |

Verified absent before building: there was **no invoice table at all**
(`invoicing.php` is the application processing-fee lifecycle), `messages` is the
public contact form with no participants and no reply, and `makeup.php` only ever
*suggested* sessions while two guardian screens count credits.

**Money.** The invoice states what is OWED in its own currency (USD, matching the
published price list) with the rate FROZEN at issue; the naira figure is a
conversion labelled an ESTIMATE, because the rate it settles at is the payment
row's own. `MAM_V2_BASE_CURRENCY` (`api/lib/v2/transform.php`) is `'NGN'` and is
the v2 ETL's, not this layer's; the wallet ledger stays USD cents, deliberately.
`mam_fee_to_minor()` is string arithmetic — the decimal point is MOVED, so `8.29`
is 829 and not the 828 a truncating float conversion gives.

**The four kinds of aid apply in a fixed order**, and `mam_fee_recalc()` is the
only implementation of it: gross → waivers → aid credits → sibling (capped) →
total. Sibling last, because a percentage of a post-aid balance is smaller than a
percentage of the gross. The cap is on the AMOUNT, not the percentage.

### Status

| Stage | State |
|---|---|
| Tokens + components (`assets/css/portal-v2.css`), scoped | ✅ |
| Schema v85–v88 + libs + routes + tests | ✅ |
| `portal/parent/index.html` rebuilt, class-only, app in `assets/js/portal-guardian.js` | ✅ |
| Old parent styles | ✅ the page loads portal-v2.css ONLY; portal.css is untouched and still serves the other two portals |
| Driven in Chromium at 390 and 1440, all twelve destinations | ✅ 0 inline styles, 0 controls under target, 0 text under 12px |
| **Remove the cohort leaderboard (refusal 5)** | ✅ **done 2026-09-17** — lib, both routes, the route file, the autoload entries and the student-portal panel. `test_engagement2.php` now holds a GUARD asserting it stays removed (deleting the tests with the feature would leave nothing stopping its return). CLAUDE.md updated in the same commit. The **studio** board is untouched — different feature. `users.leaderboard_opt_in` is deliberately retained: dropping a `users` column means a SQLite table rebuild on the most foreign-keyed table in the schema |
| Student portal (Train loop, offline queue, teach-back, spaced retrieval) | ⬜ |
| Instructor portal | ⬜ desktop is not designed yet; mobile only, in `Portals Mobile.dc.html` |
| Payment gateway on the Fees screen | ⬜ deliberately not wired: the Pay button says the office will send a link rather than opening a half-built flow |

### What running it found that no test would have

Worth reading before the student portal, because the same traps are there:

1. **Every competency bar drew full width regardless of the mark.** `paintBars()`
   ran at the end of the router, but the renders are asynchronous, so it painted
   the loading state. All render paths now go through one `paint()`.
2. **`classes.name` does not exist** — the legacy table has `title`. On the
   critical path of the whole portal, so a 500 on the first page a family opens.
   `test_parents.php` now CALLS every reader against the live schema.
3. **The child switcher landed inside the mobile bottom bar**, making a sixth
   grid column. It is a desktop control.
4. A `<button>` centres its text by UA default; `.p-row` was the only rule
   undoing it, so a thread card rendered centred.
5. The desktop header sat outside the content measure, so the bell flew to x=1424
   past a 1180px column.

## 4o. The admin console and the homepage hero (2026-08-20)

Two design frames landed and are both shipped. Same instruction as the guardian
portal: **delete the old styles and rewrite, not patch.**

### The console — `admin/console.php`

Thirty-one screens on the frame's information architecture (Dashboard, then
Recruit / Teach / Bursary / Govern). The frame is written against the v2 schema,
which nothing reads yet, so every screen is wired to **v1** and dual-reads both
enrolment models wherever the frame says "one roster".

The two decisions worth knowing:

**Reads record their failures.** `mam_console_rows()` / `_scalar()` / `_try()`
append to a per-request register instead of swallowing, and the page prints it
with the database's own words. This exists because a PDO exception in a legacy
view is caught, logged, and renders as *nothing* — a bad column silently empties
a table and the screen still looks fine. The register also makes the test strong:
it calls all 34 providers and asserts the register is empty. That caught **eight**
bad column names while this was being written, and **three status filters naming
values the CHECK constraints forbid** — a badge that could never be non-zero and
nothing failing. Worth remembering: `fee_invoices` has no `overdue` status
(derived from `due_on`), and `bursary_applications` uses `assessing`, not
`review`.

**Writes stay in `admin/index.php`.** Every action form posts to `/admin/`, so
there is one capability table, one audit call, one implementation of each verb.
`_console=<screen>` makes `mam_admin_console_return()` rewrite the handler's
`Location` header **at shutdown** — every handler exits having set a header and
no body, so it is still mutable there. One hook instead of a parameter threaded
through ~140 branches. It validates the screen against `mam_console_screens()`
rather than sanitising: `../evil?x=1` reduced to `evilx` is safe but lands an
admin nowhere. The helper sits **above the first `$view ===` branch** or it is a
fatal on any other screen, and `test_admin_screens.php` enforces that.

Verified in a browser against MySQL: all 31 destinations click through with no
failed read and no horizontal overflow, ⌘K searches/highlights/arrow-selects/
escapes, density and the collapsed rail persist across a navigation, and a real
POST lands back on its screen with the handler's own flash.

Two of my own bugs, both found by the tests I wrote for them:
`mam_console_usd_short()` compared a float to `floor()` strictly, so every KPI
tile grew two decimals; and `mam_console_ngn()` substituted today's rate for a
stored rate of zero, printing a plausible naira figure against a payment never
taken at it. A corrupt rate reads as a dash now. `null` still means "no rate of
its own, use today's and label it an estimate" — the two are different states.

### The hero — `index.php` + the hero block in `main.css` + `initHero()`

The coloured card is gone. Four panels meant four visual identities in
twenty-six seconds, the card duplicated the logo 40px above it, and a 620px
`backdrop-filter: blur(20px)` over a full-bleed photograph is a real cost on the
mid-range Android that is most of this academy's traffic. One editorial block,
varied only by an accent that `data-accent` selects as a custom property — so
the markup has no `style=` and a fifth slide is one CSS block. Every `.card-*`,
`.btn-signup*` and `.hero-content` rule is deleted, in all four places they were
scattered down the file.

Five accessibility failures fixed, all verified in a browser rather than
reasoned about: no pause control (WCAG 2.2.2), `prefers-reduced-motion` ignored,
nothing announced, steering restarted the timer instead of handing over control,
and `aria-selected` on a button in no listbox.

Two defects found by **measuring**, which is the point:

- The bottom furniture was three things positioned against one edge, and at 1440
  the proof strip overlapped the dot pill by five pixels — the pill is 278px wide
  once the dots are a comfortable 44px instead of the frame's 24px. It is a flex
  **row** now, so it cannot collide at any width. Checked at 1920/1440/1180/900/
  430/390/320.
- `visibility:hidden` takes a slide out of the tab order but transitions over
  .9s and computes as `visible` throughout, so for 900ms after every advance the
  outgoing slide's two links were still focusable. Inactive slides are `inert`.

The frame's corner treble clef is deliberately **not** reproduced: `motion.js`
already renders the site's signature wireframe at that exact position and calls
it "one sculptural element, never particles, never noise".

### MySQL, and the sweep

MariaDB installed and the suite made runnable against it. Seven defects existed
only there — two of which meant a shipped feature did not work at all on any
MySQL host (no guardian account could be created, because `users.role` was an
ENUM without `'parent'`; and all mail logging died silently on the first
`date('c')` written to a DATETIME). The full list is in CLAUDE.md under
"Running the site against MySQL".

**A green suite is not a working site.** The route sweep after it — every page,
every clean URL, all 31 console screens, all 40 legacy views, every GET API
route, then `api/logs` read for what a 200 hides — found three admin routes
still calling `mam_require_user()` inside a body already gated by
`mw_admin_cap()`, which 401s the Basic-Auth break-glass operator.
`recitals.php` did it unconditionally, so during a recovery nobody could even
read the programme. All three take `mam_admin_require_actor()` now, and
`test_console.php` asserts the class of bug rather than the three instances.

---

## 4p. The instructor application platform (2026-09-17, schema v90)

The v36 form asked for a name, a bio and a free-text list of disciplines
("Piano, Music Theory, Voice"). **Nothing can join on that.** The teaching
model is `instructor_assignments(instructor_id, course_id, level_id)`, so
approving a candidate meant re-keying their subjects by hand, and "who can
teach Diploma piano" — the question a capacity-aware enrolment asks every time
a cohort needs a teacher — was unanswerable.

### What it is now

Seven steps (Teaching · About you · Training · Experience · Availability ·
Safeguarding · Review), reusing the STUDENT wizard's components from
`assets/css/apply.css` so the two forms cannot drift into two house styles.
When the portal design handoff lands, restyling `.step` / `.field` / `.btn-step`
reaches both. No payment step — nobody pays to apply to teach.

| Piece | Where |
|---|---|
| Page | `instructor-apply.php` (markup only, 0 inline styles) |
| Wizard | `assets/js/instructor-apply.js` |
| Components | `assets/css/apply.css` (`.fac-*`) |
| Lib | `api/lib/faculty.php` (extended — no new lib, so gotcha #1 does not apply) |
| Route | `api/routes/faculty-apply.php` |
| Schema | v90 — `instructor_application_subjects`, `instructor_application_referees`, + 12 columns |
| Tests | `api/tests/test_faculty.php` (+44 assertions) |

### The five decisions worth not re-litigating

1. **The matrix is resolved against the LIVE catalogue and refuses to guess.**
   An unknown course code is DROPPED, never invented — the same refusal
   `mam_enrol_resolve()` makes for an instrument that is not a course.
   Hardcoding the course list would mean a course added in admin is one nobody
   can apply to teach.
2. **`disciplines` is still written, DERIVED from the matrix.**
   `admin/index.php` renders that column; dropping it would silently empty a
   live screen. Derivation is what stops the two disagreeing.
3. **Referees are CONTACTS, never check content.** `MAM_VETTING_REQUIRED` names
   reference_1 and reference_2 and an application is the moment to collect
   them — but `vetting_records` deliberately holds no check content, and an
   applicant's claim about themselves is never a clearance. There is nowhere in
   `instructor_application_referees` to type a certificate number or anything
   about an offence, and a test asserts the schema keeps it that way. The two
   *commitments* are legitimately self-reported: they are statements the
   candidate makes, not checks somebody performed.
4. **Open applications are a SETTING, not a removal of the gate.**
   `faculty_open_applications` (default on) publishes the form; invite links
   still work, still pre-verify the address, still consume once. Turning it off
   restores v36 exactly. ⚠️ On the invite path the email comes from the
   INVITE, never the body — otherwise a single-use link for one address becomes
   an application for any address.
5. **The triage band is advisory and staff-side only.** A band produced by
   counting fields is not a hiring judgement, and telling somebody their
   application scores low the moment they submit is neither useful nor kind.
   Same posture as intervention flags. It never gates submission.

### What running it found that no test would have

The same lesson as the guardian portal: drive it, do not reason about it.

1. **A student CTA bar sat over the form.** `lead.js` skips its "Next intake is
   open — five-step application" nudge on `/apply` via a path test that
   `/instructor-apply` does not match. A prospective TEACHER got the STUDENT
   wizard's nudge, with the wrong step count, covering the matrix on a phone.
   Fixed with `data-no-lead-cta` — the opt-out `lead.js` already provides.
   ⚠️ Any new root page should check this; the regex reads `/apply`, not "an
   application form".
2. **Level chips rendered "Pre-CertificationGrade 1-2"** — the `<small>` became
   a flex sibling of the name. `flex-direction: column`.
3. **An inline style and an 11.52px control**, both below the floors the
   stylesheet states about itself.
4. **`mam_db_exec()` does not exist** — the house idiom is
   `mam_db()->prepare(...)->execute(...)`. Same family as gotcha #4's
   `mam_session_get()`; caught before it shipped only because the lib was
   grepped rather than trusted.

Driven in Chromium at 1280 and 390: seven steps, save-and-resume, the
safeguarding gate refusing an empty submit, submit, and the row read back out
of the database. **Two full runs produced ONE application** with two subjects
and two referees, proving resubmit REPLACES rather than appends in the real
path and not just in a test.

### Second pass — what a hostile re-read and a browser found (same day)

Six defects in the first cut, all in code that passed its own tests:

1. **`mam_fail()` in a lib called from an HTML page.** `mam_fail()` is
   `mam_json()` — it EMITS AND EXITS. `mam_faculty_assign_subjects()`'s only
   caller is `admin/index.php`, so a refusal would have printed a raw JSON body
   into the admin screen and killed the request, and the surrounding try/catch
   could never run. **It looked correct because the harness stubs `mam_fail()`
   to throw.** The lib now throws; a test pins that it never calls `mam_fail()`.
   ⚠️ The general rule: a lib reachable from both an API route and an HTML page
   must not choose the HTTP response.
2. **`display:flex` outranks `[hidden]`.** `.resume-banner` and `.apply-stepper`
   both rendered while carrying the attribute, so a first-time visitor was told
   "We saved your draft on this device" over an empty form. The trap `apply.css`
   already documents at `.hs-status`/`.advisor` — these were just the first
   places to put `hidden` on them.
3. **A draft was written on bare page load** (`openForm` → `showStep(0)` →
   `saveDraft`), so there was always something to "resume". `saveDraft()` now
   writes nothing when there is nothing to resume.
4. **A blocked `config.js` left the page on "Loading the application…" forever**
   with `Cannot read properties of undefined (reading 'request')`. A bounded
   poll then a real message with a retry, same shape as the repo's `whenChart`.
5. **No `<noscript>`.** With scripting off the page showed a spinner implying
   something was still loading. It now says what is wrong and offers email.
6. **`mam_admin_actor_id()` and `mam_db_exec()` do not exist.** Two invented
   helpers in one feature — the gotcha #4 family. Grep before calling.

Also: `aria-invalid` on invalid controls (`main.js` already supplies
`role="alert"` on `.field-error`, so the message was announced but not the
field); server column names no longer leak into candidate-facing validation
text; `.step-num` raised 0.74→0.75rem, the last thing under the 12px floor and
shared with the student wizard; and the `headshot.js` cache-buster reverted,
since that file was never changed.

### Still open here

- **No payment/contract step and no offer workflow.** Approving routes through
  the admin status select + "create their instructor account".
- ~~Copying an approved matrix into `instructor_assignments`~~ — **done.**
  `mam_faculty_assign_subjects()` + the `faculty_assign_subjects` admin action
  ("Assign their subjects", shown only for an approved application with an
  instructor account). Idempotent, including the level-less row, which the
  UNIQUE index cannot carry on SQLite because NULLs compare distinct.
  ⚠️ It records **capability** — "may teach Diploma piano" — NOT placement with
  a student. The safeguarding gate still governs `mam_lms_approve()` /
  `mam_enrol_complete()`, and nothing here bypasses it. Deliberately not
  automatic on approval: a candidate who claimed five courses may be taken on
  for two.
- **`.step-num` renders at 11.84px**, below the 12px floor. It is the STUDENT
  wizard's shared component and predates this work, so it was reported rather
  than silently restyled on a live form. One-line fix in `apply.css` when
  somebody owns the student wizard's type scale.
- The design handoff for this page has not arrived; it is built on the
  student wizard's existing system so that it can be restyled in one place.

---

## 4q. Portal-layer audit (2026-09-17)

Measured, not estimated. The numbers below are the reason the student and
instructor portal redesigns matter beyond appearance.

| | Guardian (redesigned) | Student | Instructor |
|---|---|---|---|
| Page | 160 lines | 3,200 | 2,282 |
| Inline `style=` attributes | **0** | **706** | **358** |
| Inline JS | 0 (external app) | ~3,082 lines | ~2,189 lines |
| Stylesheets | 1 (`portal-v2.css`) | 3 | 3 |
| Palette | v2, scoped | old | old |

Findings, by severity:

- **HIGH — `script-src 'unsafe-inline'` is load-bearing and cannot be dropped
  while the two old portals exist.** ~5,271 lines of inline JS across them is
  exactly what the CSP in `.htaccess` is widened for. The guardian portal is
  already strict-CSP-ready. Extracting those two apps is the concrete security
  dividend of the redesign, and it is the same work the redesign needs anyway.
- **HIGH — `check-inline-js.cjs` walks only `.html`.** A `.php` page's inline
  `<script>` is syntax-checked by NOTHING. `instructor-apply.php` had a 75-line
  inline block in exactly that blind spot; its replacement is external. Worth
  extending the walker to `.php`, or the next root page repeats it.
- **MEDIUM — `.step-num` at 11.84px** in the shared wizard chrome (above).
- **LOW / not a finding — the Chart.js CDN fallback is exemplary.** It paints
  CSS bars immediately, upgrades if the CDN lands, catches and reverts. This is
  §3.1's lesson already applied; do not "fix" it.

Neither old portal carries `data-portal="v2"`, so `portal-v2.css` stays inert
for them and the three coexist — the redesign can be done one portal at a time.


## 5. Where things live

| What | Where |
|---|---|
| Schema v2 (canonical MySQL, 141 tables) | `db/schema-v2.sql` |
| SQLite mirror — **generated, never hand-edited** | `db/schema-v2-sqlite.sql` |
| The generator (`--check` is wired into CI) | `api/tools/schema-v2-sqlite.php` |
| Status catalogue — 67 statuses × 3 audiences | `api/lib/v2/status-catalogue.php` |
| Clarity read model | `api/lib/v2/clarity.php` |
| Identity resolution (22 people, 0 unaccounted, 2 tombstones, 2 flagged splits) | `api/lib/v2/identity.php` |
| Payment/application transform | `api/lib/v2/transform.php` |
| The enrolment completion chain | `api/lib/enrol-complete.php` |
| The ETL load + verify | `api/lib/v2/load.php`, `api/lib/v2/verify.php`, `api/tools/v2-load.php` |
| The load-free transform → a loadable dataset | `api/lib/v2/plan.php`, `api/lib/v2/emit.php`, `api/tools/v2-transform.php` |
| Verify a database somebody else loaded (read-only) | `api/tools/v2-verify.php` |
| The academy's migration exclusions (data, not code) | `db/v2-exclusions.json` |
| Browser-triggered export for a host with no shell | `api/tools/export-web.php` |
| Instructor's register — camera + card QR + group check-in | `scan.php` |
| Student one-tap check-in | `checkin.php` |
| Account invitations (a link, never a password) | `mam_auth_invite()` in `api/lib/auth.php` |
| Shared portal error translator (`P.errText` / `P.fail`) | `assets/js/portal.js` |
| Self-hosted toast (the CDN-proof one) | `assets/js/libs.js` |
| The academic calendar + refund authority | `api/lib/calendar.php`, admin `?v=calendar` |
| The NDPA layer — requests, breaches, ROPA, retention, processors, DPIAs | `api/lib/dataprotection.php`, admin `?v=dataprotection` |
| The forms audit, with every number | `docs/forms-audit-2026-08-17.md` |
| The codebase map | `docs/codebase-index-2026-08-15.md` |
| The admin console — shell, screens, reads, helpers | `admin/console.php`, `partials/admin-console/`, `api/lib/console.php` |
| The console's design system (scoped `[data-admin="v2"]`) | `assets/css/admin-v2.css` |
| The console → legacy-handler return hook | `mam_admin_console_return()` in `admin/index.php` |
| The homepage hero (markup / styles / behaviour) | `index.php`, the hero block in `assets/css/main.css`, `initHero()` in `assets/js/main.js` |

⚠️ **`api/lib/v2/*` is deliberately NOT in `bootstrap.php`'s `$mam_libs`** — it
queries v2 tables that do not exist in v1. Adding it there boots a 500 on every
request. It *is* in `api/tests/run.php`.

---

## 6. Next, in the order I would do it

1. ~~**The admin dashboard redesign**~~ — **done, see §4o.** That item carried a
   checklist of things a redesign would silently drop. Against the console as
   built, honestly:

   | Load-bearing thing | In the console? |
   |---|---|
   | `MAM_ANALYTICS_INSTRUCTOR_NOTICE` on every rendering surface | ✅ printed on Reports |
   | The mastery **band**, never a raw score | ✅ via `mam_mastery_publishable()` |
   | An em dash meaning "not measured", never zero | ✅ throughout — a cohort with no marks reads "nothing marked" |
   | `ORDER BY u.name` on the instructor comparison | ✅ by not duplicating the table; the console links to `?v=analytics` |
   | The pinned "in use" actions column | ❌ **dropped** — the frame replaces it with a grouped rail + live badge counts. Deliberate, but it is a loss for an admin who used the same four screens daily. |
   | `table.stack` + `data-label` mobile tables | ❌ **not carried.** The console scrolls its tables horizontally instead. The frame is explicit that the console is desktop-first, but this is weaker on a phone than what `admin/index.php` already does, and it is the gap most likely to be noticed first. |

2. **The console's phone subset (`#5b` in the frame) is not built.** Four
   panels — Decide / Today / Assign / Look up — deliberately narrower than the
   desktop console, with amending a record, voiding an invoice and closing a
   period unavailable on purpose ("a consequential, hard-to-reverse action taken
   one-handed on a bus is how a ledger acquires a mystery"). The desktop console
   is responsive down to 320px and usable there, so this is an enhancement
   rather than a blocker — but it is the other half of the frame.
3. **The student and instructor portals are still on the old palette.**
   `portal-v2.css` is scoped, so they and the guardian portal coexist; those two
   dashboards are the remaining redesign work.
4. **Features in those designs that do not exist yet.** Expect some. Build them
   against the existing libs where one fits; say so plainly where a design implies
   a table or an integration that is not there, rather than faking the screen.
5. **Watch the first real enrolment after deploying the invitation change.** The
   whole flow is proven in a browser, but nothing here has sent mail through the
   academy's own SMTP. `mail_log` is keyed by context — `summer_student_invite`,
   `summer_guardian_invite`, `portal_welcome` — so a failed send is findable and
   re-sendable. `portal_invite_failed` in the settlement return is the signal.
6. **Then, if a v2 cutover is still wanted:** authentication against
   `user_credentials`, sessions on `auth_sessions`, and one screen at a time
   repointed behind a flag. Sequence it WITH the redesign, not before — repointing
   a screen that is about to be rebuilt is work done twice.
7. **The read model decision.** `api/lib/v2/readmodel.php` and `?v=v2read` were
   built for a plan the academy changed. Inert without `V2_READ_DB`, so harmless,
   but there by default rather than by decision. One commit either way.

## 7. How to reproduce any of the measurements

The whole audit was driven against a local copy, because the numbers only mean
something if they came from a browser.

```bash
# 1. A router that serves clean URLs and /api (php -S ignores .htaccess).
#    Serve static files yourself — returning false makes PHP look in the
#    server's OWN doc root, not $DOC, and every asset 404s.
cd "$SCRATCH" && DOC="$SCRATCH/site" setsid nohup php -S 127.0.0.1:8125 router.php &

# 2. Playwright Chromium, CDNs blocked the way a bad network blocks them.
#    Do NOT wait for networkidle — it never settles with routes aborted.
#    await page.goto(url, { waitUntil: 'domcontentloaded' })

# 3. Accessible names, the ONLY correct recipe:
await cdp.send('Accessibility.enable');          # without this: everything reads unnamed
const d  = await cdp.send('DOM.describeNode', { nodeId });
const ax = await cdp.send('Accessibility.queryAXTree', { nodeId });
const n  = ax.nodes.find(x => x.backendDOMNodeId === d.node.backendNodeId);
#                            ^ match by backendNodeId — a <select>'s options
#                              each carry a name and would be taken as the label

# 4. Skip controls inside a closed <details> — Chromium drops them from the AX
#    tree, but getBoundingClientRect() still reports a height.
```

Gotchas that cost time and will again:

- **The auth and upload routes are rate-limited** (12 uploads / 10 min; login
  too). A long browser run exhausts them and the failures look like app bugs.
  `DELETE FROM rate_limits` between runs.
- **`php -S` is single-threaded.** Use `setsid nohup` and a fresh port per run;
  a foreground server plus a browser deadlocks.
- `ERR_CERT_AUTHORITY_INVALID` in the console is blocked CDN assets, not a bug.
- **`/apply` renders a chooser first** while the summer intake is open — click
  `#pcChooseRegular` before the wizard exists.
- The dev DB writes through `api/storage/mam.db`; `bootstrap.php` buffers output
  and appends `{"error":"no_response"}`, so query it with a **raw PDO** script,
  not one that requires bootstrap.

---

## 8. Test and CI expectations

```bash
php api/tests/run.php                      # 3,275 assertions, must stay at 0 failed
node .github/scripts/check-inline-js.cjs   # inline <script> in every HTML page
node assets/js/notation.selftest.cjs
node assets/js/card-export.selftest.cjs
node assets/js/headshot.selftest.cjs
```

A self-test CI does not **execute** is not a test — the headshot one sat broken
for months because CI only parsed it. If you add one, add it to
`.github/workflows/ci.yml` in the same commit.

New test files added in this stream: `test_apply_form.php`,
`test_admin_tables.php` (extended), `test_portal_forms.php`.

---

## 9. Open questions for you

**From the research tranche (the full list is §6 of the research plan):**

0. **When does the safeguarding gate move from `warn` to `enforce`?** It is live in
   `warn` now: gaps are recorded and surfaced, assignments proceed. Someone must
   own loading the clearance records the academy already holds offline and then
   flipping the mode. Also needed: **who is the Designated Safeguarding Officer**
   (the Lagos policy requires a named one, and the screen shows a standing warning
   until it is set).
0b. **Are MAM's instructors employees or contractors?** This decides whether
   Labour Act s.5(1) binds the punctuality deduction at all. Until answered it
   stays off — which is the safe default, not a verdict.
0c. **The policy handbook quotes fines the system does not levy.**
   `mam_policy_points()` in `api/lib/mailer.php` promises families ₦500/$0.50
   lateness, ₦1,000/$1 late submission and ₦2,000/$2 inconvenience fees. None match
   the ledger settings, and the ledger is switched off. Content decision, needs
   resolving either way.
0d. **Confirm or change two declared value judgements**: the Mastery Score coverage
   floor (40%) and the instructor-comparison roster floor (8 graded results).


1. ~~**Auto-enrol on payment settlement**~~ — **answered: yes, shipped.** See §4b.
2. **The student portal's gated forms** — worth a browser pass, or take the
   source-level fix as sufficient?
3. **Course-code aliases.** If `violin`/`clarinet` are meant to map onto real
   courses, tell me which and I will add the map. Right now they correctly
   refuse to guess and go to the admin backlog instead.
4. The research you mentioned — send it and I will fold it in here.
