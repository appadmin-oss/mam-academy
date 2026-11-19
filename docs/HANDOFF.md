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
| The console's phone subset (`#5b`) | ✅ **shipped 2026-09-28** — `admin/phone.php`, four panels, reversible actions only; the refusal is in the read layer and greppable |
| Console tables on a phone | ✅ **shipped 2026-09-28** — every table stacks below 720px with a derived label and explicit ARIA roles; was 23 of 31 screens scrolling sideways |
| **Instructor portal redesign** | ✅ **shipped 2026-09-19** — seven screens on portal-v2; the legacy 13-tab dashboard stays at `classic.html`. `docs/design/2026-08-portal-redesign/BUILD-NOTES-instructor.md` |
| **Portal UX audit (WCAG 2.2 AA + conventions)** | ✅ **shipped 2026-09-21** — 4 major and 9 minor findings, all fixed and pinned; §4s |
| **Student portal redesign** | ✅ **shipped 2026-09-21** — five destinations on portal-v2; the legacy dashboard stays at `classic.html`. All three portals now share one palette and one a11y key; §4r |
| **Guardian portal reachable from sign-in** | ✅ **shipped 2026-09-21** — `/api/auth/me` returns the portal map; three client copies of it are gone; §4r |
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
- ~~**`.step-num` renders at 11.84px**~~ — **done** (0.75rem = 12px; see the
  paragraph above). This bullet was left open by mistake (audit 2026-11).
- ~~The design handoff for this page has not arrived~~ — it did:
  `docs/design/2026-09-instructor-application/`, built in the 2026-09 rework.

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

**All three are now done** (§4r). The HIGH finding above is the one to read
next: `script-src 'unsafe-inline'` is still load-bearing, because the two
`classic.html` dashboards still carry the ~5,271 lines of inline JS and are
still served. Dropping it is now a *retirement* decision, not a rewrite.


## 4r. The student portal, and the portal map (2026-09-21)

Two things shipped together, because the second was found while testing the
first.

**The guardian portal was orphaned.** Sign-in, the chooser and `config.js`
each carried their own copy of the role → portal mapping, and none of the
three listed `parent`. A guardian who signed in was sent to the student
portal, which refused them. The fix is one server-owned list —
`mam_portal_targets($roles)` on `GET /api/auth/me` — with the three client
copies reduced to a last-resort fallback, so the next portal cannot be added
in one place and missed in three. `test_site_chrome.php` guards it. Verified
end to end for all four cases: student, instructor, guardian, and the
dual-role account that lands on the chooser.

**The student portal v2** is the last of the three. Five destinations
(Home · Train · Learn · Attend · More) plus Fees and Repertoire behind the
sheet; class-only markup, one provider per screen, one request per screen.
Full notes, including the four refusals the provider enforces and the shared
`--on-ink` trap, are in CLAUDE.md under "The student portal v2".

### What only a browser found

Every one of these passed the suite and the linters:

| Defect | Why it mattered |
|---|---|
| "1h 10m **this week**" above seven empty Mon–Sun dots | `mam_practice_stats()` measures a ROLLING week for the figure and a CALENDAR week for the strip. The label now says what the figure measures. |
| "NEXT · Practise today" above a class starting in 8 minutes | The provider's step was *correct*; the page was lying about which came first. An imminent class now leads, and the hero is suppressed when the step IS the check-in. |
| One session shown twice under two names | The card used the module title ("Circle of fifths"), the record used the session title ("Scales & sight-reading"). Reads as two classes. |
| Clock-out lost with the duplicate row | A student could check in and never record leaving, so no duration was written. It lives on the card now. |
| Eight identical "not counted against you" sentences | 1,180px of card at 390 where eight *names* were the information. |
| Competency bars at 1,110px | 100% and 96% are the same picture with no reference length. |
| **Dark mode: white text on a light button** | `--ink` inverts (#1B2430 → #E8ECF2) and `#fff` does not. The instructor session card had the same latent pairing and its accent eyebrow measured **1.38:1**. Fixed with `--on-ink` tokens across the shared layer — now 5.14:1. |
| **320px: a chip crushing the name it annotates** | A 100px "2nd marker" chip left the instructor marking queue's name column 56px wide. `.p-row` wraps now. |

Verified at 320/390/1440, in dark, at the largest text size, with
easier-to-read and low-data on. Every write driven for real: check in, clock
out, mark prepared, log practice, send a teach-back, ask for a charge to be
reviewed, add/restatus/remove a repertoire piece.

### What is deliberately NOT done

- **The legacy dashboards are not retired**, and that is the decision to make
  next. `portal/student/classic.html` still serves courses, curriculum,
  assignments, grades, payments, orders, recitals and support; the redesign
  covers five destinations. Retiring it is what finally lets
  `script-src 'unsafe-inline'` go.
- **"Keep it warm" stores nothing server-side.** The interval is a
  device-local reminder. There is no endpoint because there is nothing the
  academy should be told — the moment it is recorded and reported it stops
  being retrieval practice, which is §2.1's own refusal.
- **The handoff's `/api/practice/*` surface was not built.** The repo's
  equivalents (`student/practice-log`, `student/take`, `student/preclass`) are
  already rate-limited, CSRF-gated and tested; a parallel surface would give
  the academy two ways to record one take.


## 4s. The portal UX audit (2026-09-21)

An evidence-graded pass over all three portals, driven in a browser. The
thresholds are WCAG 2.2 AA plus the convention set; every number below was
measured, not estimated.

### What it found, by severity

**Major (3)**

| Finding | Why |
|---|---|
| The More sheet declared `role="dialog" aria-modal="true"` and implemented none of it (student + instructor) | `aria-modal` tells a screen reader the rest of the page is inert. Focus never entered the sheet, Tab walked out, Escape did nothing — on a sheet whose rows include Sign out. The **guardian portal had the complete implementation 170 lines away**; both others copied its markup without its behaviour. Same mistake the site chrome removed once (`role="menu"`). |
| Focus dropped to `<body>` on every in-content navigation (student + instructor) | Activating a control that navigates destroys it on repaint, so focus resets to the document and the next Tab restarts at the skip link. ⚠️ The fix must run on the **paint**, not the navigation — every screen fetches, so `go()` returns while `#main` still holds the old screen. A first attempt in `go()` measured as *still* landing on `<body>`. |
| The waiver request was a `window.prompt` filing everything as `other` | The server defines seven reason codes and the provider ships them; the client discarded them. Every row in the admin's waiver queue read "Other approved reason" — the field that makes it triageable. |
| Form controls at 14px | Safari on iOS auto-zooms below 16px and does not zoom back out. Four fields across two portals, on phone-first surfaces. |

**Minor (2)** — `.s-dot` at 4.31:1 (the *unpractised* day, the state that matters most) · `prefers-reduced-motion` freezing the loading spinner, which 2.3.3 exempts · errors announced through polite `role="status"` and auto-hidden with no recall · a loading state visible for ~2 frames · no back control on the two sheet-reached screens · the guardian sheet not restoring focus · "Ask" not reading alone · an empty state with no path forward.

**Found only after seeding guardian data** — `.p-badge` measured **1.04:1**: the guardian writes a *count* into an element the stylesheet documents as an 8px *dot*, with no `color` of its own. The instructor portal already did it right (aria-label only). And the guardian was **missing `viewport-fit=cover`**, which the shared stylesheet needs in four places for `env(safe-area-inset-bottom)` to be anything but 0.

### What measurement cleared

Zero target-size failures (2.5.8). 2.4.11 clean — the 120px `--bar-reserve`
genuinely keeps focused controls off the fixed bar. 200% text zoom: no
overflow, nothing clipped. Every input already had a programmatically
associated persistent label; none used a placeholder as one. A visible focus
ring on all 38 tab stops sampled. No viewport blocks pinch zoom.

### How to reproduce it

The harnesses are in the session scratchpad, but the three that matter are
worth rebuilding rather than trusting:

- **Real Safari engine.** WebKit is not in `/opt/pw-browsers` by default;
  `npx playwright@latest install webkit` plus `install-deps webkit` gets
  WebKit 26.6 running. Drive it with the `iPhone 13` device descriptor.
  ⚠️ It verifies the *precondition* (16px controls, a viewport that permits
  zoom) — desktop WebKit on Linux does not implement iOS Safari's form
  auto-zoom, so it cannot prove the zoom is gone, only that nothing invites it.
- **Throttled mobile data.** `Network.emulateNetworkConditions` over CDP.
  MEASURED on Slow 3G (400 Kbps, 400ms RTT): a screen lands in 480–541ms and
  the deferred indicator appears at ~315ms, so it is up for ~200ms. On
  Regular 4G (116–119ms) it is never shown. That is the 300ms deferral doing
  exactly its job, and every screen is inside Nielsen's 1s limit even on the
  slowest profile.
  ⚠️ A naive timing harness reads ~5ms here: the previous screen's markup is
  still in `#main` when the hash changes, so "is there a section?" is true on
  the first poll. Wait for the heading to become the new screen's.
- **Guardian data.** Its screens were empty and therefore under-audited.
  Seeding needs a ratified report (build → moderate ≥2 criteria → ratify with
  a real admin id), two issued invoices, make-up credits and a thread.
  ⚠️ `mam_report_build()` on an existing draft REUSES it rather than
  rebuilding, so a draft built before the evidence existed stays
  all-`insufficient` and `mam_report_ratify()` correctly refuses with
  `no_evidence`. Rebuild, don't reuse. And `mam_makeup_grant()`'s reason is
  `teacher_cancellation`, not `teacher_cancelled`, while `guardian_notice`
  requires `notice_hours`.

**One more the populated screens exposed:** the guardian's termly report gave
each of its eight unevidenced competencies its own row repeating "First
assessed next term. Not counted as a zero." — MEASURED at 563px of a 1,788px
page, 31% of a ratified academic report spent on one sentence, with the eight
NAMES reduced to captions. Now one named set, caveat once, 281px, every name
still on the page. `.p-none` was promoted from the student block to the shared
one, because both surfaces show the same competencies to the same family.
⚠️ Fixing it failed `test_dashboard_nulls.php`, which pinned the exact
sentence. That guard now asserts the phrase carrying the promise plus the
structure — a test on exact copy blocks the next improvement, not the next
regression.

### 4s-2. The styling pass (same day)

Visual defects, found by reading every screen at 390 with real data rather
than by measuring:

| Defect | Detail |
|---|---|
| Student Fees led with **$0.00** above an invoice chipped "Overdue" for $485 | `mam_sportal_owed()` returned the punctuality total as `cents` while taking `due_on` from `fee_invoices` — the biggest number on the screen answered a different question from the date beside it. It now returns `charges_cents` and `fees_cents` separately and the headline is their total, named underneath. |
| **Two of the three register marks failed contrast** | White on `--good` is 4.36:1 and on `--warn-bar` 3.92:1. Fixing the muddy Late fill by swapping `--warn` → `--warn-bar` *introduced* the second failure, which is how the whole set got checked. New `--fill-good/warn/bad` + `--on-fill`, inverting in dark mode. |
| "Saved 2 of 6" was a **dead `p-primary` button** | No handler anywhere, and the most prominent control on the register — a progress readout dressed as the primary action. Now a status with a bar beside the one real action. |
| `.p-card-foot` had no side padding inside `.p-card.is-flush` | Footnote text sat 1px from the card wall with the rounded corner drawn through it. |
| The payment methods looked like buttons | Three `<span>`s at 44px with a border and the button radius, directly under three real buttons. Now a caption strip. |
| Every screen change painted a **focus ring** | `querySelector('.p-back, .p-h1')` returns the earlier match in DOCUMENT order, and the back control renders above the heading — so the focus move landed on a real `<button>`. MEASURED: `:focus-visible` matched after a navigation with no keyboard interaction. All three portals had it. |
| The teach-back **compounded on resubmit** | The three self-checks were appended to the body, and the textarea is prefilled with the last submission — so "Send it again" posted the previous body with its checklist and appended it again. |
| The week strip read as part of the minute chooser | Two rows of same-sized pills 12px apart under one heading. |
| The guardian FX note printed the same rate twice | The "issued at" sentence was unconditional rather than shown only when the frozen and current rates differ. |
| `<audio controls>` sat in the card as a raw browser widget | Framed on `--sunken` with `color-scheme` so Chrome draws it dark in dark mode. |

⚠️ **My own 320px fix had regressed 390.** Giving `.p-row-main` a 55% basis
stopped the chip crushing the name at 320, but flexbox decides to wrap on the
sum of the BASES, so the marking queue's "2nd marker" chip dropped to its own
line at 390 where it had always fitted. Back to `flex: 1 1 0` with the wrap
asked for explicitly below 360. MEASURED after: 320 wraps (main 56px → 184px,
no clipping), 360/390/430 inline and un-clipped.

⚠️ **Two more harness errors, same lesson as below.** A wrap detector compared
the chip's top against the main column's — useless under `align-items: center`,
where a short chip always sits below a tall column's top on the same line;
compare the chip's LEFT against the column's RIGHT. And a `pkill -f sweep.cjs`
matched its own shell, whose command line contained the string, killing the
command before its heredoc had written the file.

⚠️ **Two of the harness's own first results were wrong**, which is the general
lesson: a "focus obscured by fixed chrome" detector flagged the nav's own
buttons overlapping themselves, and a contrast checker flagged the fixed badge
at 1:1 because it now paints nothing. Check what a failing measurement is
actually measuring before reporting it.


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
| The three redesigned portals | `portal/{student,instructor,parent}/index.html` + `assets/js/portal-{student,instructor,guardian}.js` + `assets/css/portal-v2.css` |
| Their read providers (one function per screen) | `api/lib/{student,instructor}-portal.php`, `api/lib/parents.php` |
| The legacy dashboards, still served | `portal/{student,instructor}/classic.html` |
| Which portal a sign-in goes to | `mam_portal_targets()` → `GET /api/auth/me` (the clients only fall back) |
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

2. ~~**The console's phone subset (`#5b` in the frame) is not built.**~~ —
   **done 2026-09-28** (`admin/phone.php`; see CLAUDE.md "The console on a
   phone"), and console tables now stack below 720px (`ac_label_table_cells`).
   The original note, kept for its reasoning: Four
   panels — Decide / Today / Assign / Look up — deliberately narrower than the
   desktop console, with amending a record, voiding an invoice and closing a
   period unavailable on purpose ("a consequential, hard-to-reverse action taken
   one-handed on a bus is how a ledger acquires a mystery"). The desktop console
   is responsive down to 320px and usable there, so this is an enhancement
   rather than a blocker — but it is the other half of the frame.
3. ~~**The student and instructor portals are still on the old palette.**~~ —
   **done** in the 2026-09 redesigns (`portal-student.js`, `portal-instructor.js`).
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
php api/tests/run.php                      # 6,223 assertions, must stay at 0 failed
node .github/scripts/check-inline-js.cjs   # inline <script> in every HTML page
node assets/js/notation.selftest.cjs
node assets/js/card-export.selftest.cjs
node assets/js/headshot.selftest.cjs
```

A self-test CI does not **execute** is not a test — the headshot one sat broken
for months because CI only parsed it. If you add one, add it to
`.github/workflows/ci.yml` in the same commit.

New test files added in this stream: `test_apply_form.php`,
`test_admin_tables.php` (extended), `test_portal_forms.php`,
`test_apply_wizard.php`, `test_site_chrome.php`, `test_student_portal.php`,
`test_student_portal_ui.php`.

⚠️ **The portal tests are static and say so in their own headers.** Every
defect §4r lists was found by driving a real browser, and not one of them
would have failed an assertion here: a label that contradicts the dots beside
it, a card ordered above the thing it is subordinate to, a token that inverts
while the colour paired with it does not. Run the sweep before claiming a
portal change is done — 320/390/1440, dark, largest text, and every write
actually clicked.

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


## 4t. Audit 2026-11-18 — the office's side of fees, and six defects

Re-audit with MAM as the focus: every open item in this file, the research plan
and the two proposals checked against the CODE (several were stale, both ways),
plus a bug hunt over 2026-09-29 → 2026-11-10. Branch `afv/mam-audit` (the owner
no longer wants `claude/` branch names). Suite 6706 → **6770, 0 failed**.

### Fees had no office side at all (the headline finding)

v87 (§4n) shipped invoices, aid, payment plans and bursary applications, and
"Five subsystems … all built" was true of the LIBRARY only. Nothing outside it
and its tests called `mam_fee_invoice_open`, `mam_fee_line_add`,
`mam_fee_credit_apply` or `mam_bursary_decide`. So: no invoice could ever be
raised (every family's Fees screen was empty), a guardian could apply for a
bursary and nobody could answer, nothing marked anything paid (`paid_minor`
moved only in tests), and a plan a guardian "requested" was ACTIVE on creation
with `approved_by` null and nothing reading it.

Built:
- **v91 `fee_payments`** — money the office receives (transfer, cash, POS, card),
  one row per payment, VOIDED with a reason, never deleted. `mam_fee_recalc()`
  takes payments as the authority once an invoice has any, so a void brings the
  balance back (the old rule only ever let paid go up). Recorded payments are
  receipts on the guardian's statement. Overpayment is refused (`more_than_due`).
- **Plans**: `mam_fee_plan_approve()`; a requested plan reads as a request on the
  guardian portal until approved; `mam_fee_plan_allocate()` marks instalments
  paid in order from the money received, back to pending on a void.
- **Reminders**: `mam_fee_dunning_sweep()` on `MAM_FEE_DUNNING_DAYS` (due date,
  +3, +7), to the billing guardian, ONE email per invoice per run and only ever
  forward (claimed with a compare-and-set on `dunning_stage`), following the
  next instalment once a plan is approved. Automation `fee_reminders`, wired into
  `run-jobs.php` and "Run scheduled sweeps now". It suspends nothing.
- **Console**: Invoices gained New invoice, Plans waiting for approval, and an
  invoice view with every action (lines, waive, issue, void, aid, credit,
  payments, plan approve/decline). Bursaries gained a decision form, with the
  band ceiling stated. Handlers in `admin/index.php` under a new **`fees`**
  capability, super-only by default like refunds. Driven end to end in a browser.
- ~~Still NOT built: paying a fee invoice online.~~ Built 2026-11-19, §4u.

### Six defects in the recent work, fixed

1. **A backup that failed to copy a photo reported itself complete.**
   `uploads.skipped` was written and read by nothing: export exited 0, status
   said ok, `--verify-only` said intact. Now the export exits 3, verify fails,
   status is `partial`.
2. **The instructor application refused its own uploaded headshot** on an
   install without Cloudinary (https-only validator) — the fourth "store
   succeeds, downstream guard drops it". Uses `mam_upload_photo_url_ok()`.
3. **The photo bug also wiped a returning family's stored age, country and
   city**, because the customer upsert wrote NULLs over the shared row. The
   upsert now never writes an empty value over a stored one, and the damaged
   listing says when a family has other applications affected.
4. A backup taken with `--with-uploads` on an install with no photos was told
   to add `--with-uploads`.
5. Snapshot names were server-local time read as UTC (`gmdate` now).
6. Two `?v=` assertions in `test_design_system.php` passed when a file stopped
   matching.
Also: the "Finish enrolment" filter only filtered the page on screen.

### Still open, and whose decision

Unchanged owner questions (§9): when safeguarding moves to `enforce` and who
the DSO is; employees or contractors; the handbook's fines; the mastery floors;
course aliases; the read model. From the research plan, buildable without an
owner but not done here: a capped aid fund, a refund record against the
calendar, transcripts, the "Enrolment" relabel (terms.php is legal text),
opt-in staff 2FA, a report-only strict CSP, MySQL 8 portability.
Not verified: whether short APP_KEY installs (<32 chars) have printed cards
that now fail to verify after f4d33fc — nothing marks them. ~~The restore
runbook does not name `api/storage/.app_key`~~ — it does now (§4u).

## 4u. 2026-11-19 — a guardian pays a fee invoice online

Branch `afv/mam-audit`. Suite 6770 → **6808, 0 failed**.

- **v92**: `payments.fee_invoice_id`, `fee_minor`, `fee_payer_id`. The Pay button
  calls `POST /api/parent/fee-pay` (billing scope required, rate-limited), which
  opens a `purpose='fee'` charge through `mam_fee_online_start()` and returns
  Paystack's checkout page. Back at `/portal/parent/?paid=REF`, the portal asks
  `/api/payment/verify` — never the address bar — and the webhook settles it too,
  whichever lands first, through the same race-safe `mam_payment_settle()`.
  Settlement records an ordinary `fee_payments` row (method from the gateway's
  channel: card / transfer / other), so the office sees it with the cash.
- **Credited in the invoice's currency.** A dollar invoice is charged in naira at
  the rate the statement already quoted and credited `fee_minor` cents. A naira
  invoice is charged to the kobo.
- **Money that clears after the balance moved is kept.** If the office records
  cash before the charge clears, only what is still owed is credited; the rest is
  on the payment's note and on the console invoice's new **Paid online** card as
  "to refund". Measured end to end: ₦100,000 charged against ₦90,000 owed →
  ₦90,000 credited, ₦10,000 shown to refund, invoice paid.
- An approved plan offers **Pay instalment N** for its next unpaid instalment; a
  requested plan does not.
- Paystack only: it redirects, and the inline checkouts need a gateway script the
  portal CSP does not admit. With no `PAYSTACK_SECRET_KEY` the button says online
  payment is not switched on, instead of opening a checkout that cannot finish.
- Gateway amounts are compared in integer kobo (`mam_payment_amount_ok()`, used
  by verify and both webhooks); `amount * 100` is float arithmetic and can fail
  an exact charge, which a kobo-exact fee charge is the first to produce.

**Found on the way: the redesigned console's money figures read zero.** Eight
queries in `console.php` asked for `status = 'success'`; settlement writes
`'succeeded'` (as BI and the legacy admin read). So Collected, the ledger's
accounts and the currency breakdown ignored every payment ever taken. Fixing the
spelling alone would have put every store order and fee payment into "suspense ·
unmatched", because that was defined as "no application"; it is now an
APPLICATION charge (tuition or booking) with no application.

Also: a fully-paid naira family was shown "Due now $0.00"; DEPLOYMENT.md §8.1 now
says the key of an install without `APP_KEY` lives in `api/storage/.app_key`, to
copy into `.env` before it is needed.

Verified: PHP suite; the four CI JS checks; three mutations (no fee branch in
settlement, crediting the full charge, the console's old spelling) each fail the
new tests; the guardian flow driven in Chromium at 390px with the gateway
simulated (no Paystack account here), and a real settlement against the dev
database checked on both the guardian statement and the console. Not exercised:
a live Paystack charge.

## 4v. 2026-11-19 — bank transfers families report, ported from NGG

MAM and NGG run the same business processes (enrol, fees, shop, ID cards,
guardians, check-in), so where one has solved a money problem the other usually
has the same problem. NGG's membership payments already had what MAM's fee layer
lacked; this ports two of them. Branch `afv/mam-audit`. Suite 6808 → **6859, 0 failed**.

- **Reported transfers (v93 `fee_transfer_claims`).** Most families pay by
  transfer, and the bank instructions said "email proof, we confirm within 24
  hours" with nothing recording either half. A guardian now reports the transfer
  on the invoice with a photo or PDF of the slip; the console queues it ("Transfers
  to check", and the decision queue); the office downloads the slip and confirms by
  retyping the reference and amount from the bank statement, or says why not. The
  family is emailed either way, and while a report is waiting the Pay button says
  to wait rather than pay twice.
- **NGG's rules, each one tested by breaking it:** the slip's bytes decide its
  type; one slip, ever; one bank reference, ever; a rejected reference cannot come
  back with a new photo; confirming is compare-and-set and reverted if crediting
  throws (so a claim never reads confirmed on an invoice it did not credit).
- **Found on the way:** `mam_fee_payment_record()` accepted the same bank
  reference twice, so two people recording one transfer credited it twice
  (measured: ₦51,000 on a ₦50,000 transfer). It now refuses `reference_reused`.
  And the console's "Plans waiting" card escaped names twice.
- **Online overpayments (v93 `fee_online_unapplied`)** are rows somebody resolves —
  refunded at the gateway, moved to the same child's other open invoice (same
  currency only), or dismissed with a reason — instead of a note.

Still in NGG and not ported, deliberately: a SECOND-PERSON rule for payments the
office records by hand (NGG requires a different staff member to verify an
offline payment). That changes how MAM's office works day to day, so it is the
owner's call. Also NGG-side: refunds through Paystack's refund API from the
console (built for NGG's shop) — MAM still refunds from Paystack's dashboard.

Verified in Chromium: a guardian at 390px reports a transfer with a JPEG; the
console lists it; the downloaded slip is byte-identical to the one sent; a wrong
retyped reference is refused; the right one credits the invoice and the guardian
sees nothing outstanding and a receipt.

## 4w. 2026-11-19 — member management and onboarding, ported from NGG

Branch `afv/mam-audit`, three commits. Suite 6859 → **6940, 0 failed**. CLAUDE.md
has the rules; this is what changed and what was found.

**Records and status (v94).** One status setter with a required reason, a
history table and two lockout refusals (your own account; the last super
admin), also on role changes. Staff notes on a person. The member number is
minted where an enrolment goes active, so office, Summer and Auditer enrolments
get one (only settlement did). The console's People & access "Sign out" posted an
action that only ever touched the operator's own sessions — it did nothing.

**Roster (v95).** The console Students screen was 200 rows, unsorted, unpaged,
with two queries per student. It now has allow-listed filters (status, fee type,
what is missing) and sort, paging, true totals, a member panel (status +
history, notes, guardians, checklist, sign-out), possible duplicates, and bulk
actions — suspend, reactivate, fee type, member number, invite, remind — with a
verdict per row, shown on the screen the run returns to.

**Onboarding.** Found: `mam_enrol_complete()` said "sign-in details emailed" and
sent nothing; Auditer placements and office guardian links made accounts with a
password nobody received; paid/enrolled status changes and admin create/reset
emailed passwords in plain text. Every path now sends a 7-day invite; no
password is generated anywhere. Each student has a derived checklist, the
console lists who is still joining, and a reminder tells families only what they
can do themselves.

Verified: suite; mutations on own-account, last-super-admin, the history write,
the nested-form check (the rendered screen is walked), unknown-age-as-child and
family-only reminders, each failing its test; the roster driven in Chromium
(filters, tick-all with indeterminate, refused-whole bulk, all-matching, panel,
note, suspension with reason, onboarding and duplicates views, phone width 390
with no sideways scroll, a name with an apostrophe rendered correctly).

Not ported, and why: **merge** (NGG needs a per-table policy and blocks on money;
the users↔customers split makes it riskier here), **CSV import**, and
**two-person approvals** (MAM managers cannot touch people at all, so there is no
queued role). (The missing guardian email on `/apply` is closed in §4x.)


## 4x. 2026-11-19 — the guardian's email on `/apply`, and the console's layout

**Guardian email (v96).** `/apply`'s guardian screen promised the guardian
"their own portal account once you are enrolled" and collected only a name and a
phone, so the account could not be made (the open item at the end of §4w). It
now asks for their email: required for an under-16 (`KTAMS`), refused when
malformed, optional for an adult, stored as `applications.parent_email`.
`mam_application_link_guardian()` (`parents.php`) provisions, links and invites
the guardian, and runs wherever the student's account is made: payment
settlement, "Complete enrolment" (reported as a step), an Auditer placement, and
an admin marking the application paid. It is idempotent and invites only an
account it has just created. **An address equal to the student's is not
linked** (`same_as_student`): one inbox is one login, and linking it would give
the child's login the guardian role. The form's help text says so; the office
links that guardian by hand on `?v=guardians`.

Verified in Chromium at 390: the field shows, a malformed address is refused on
the page, the review row shows it, the payload carries it, and the stored row
has it. `test_guardian_email.php` holds validation, linking, idempotence, the
same-inbox refusal and every call site.

**Console layout (`admin/console.php`, not the legacy `admin/index.php`).**
Measured on all 31 screens: sections had no gap between them; the section strip
never stuck, because `.ac-scroll` was an overflow container that never scrolled
(the document does); the heading sat under the strip; the top bar was
translucent, so text showed through it; a status select filled its row; the bulk
reason input shrank to a sliver on a phone; and the hidden mobile rail cast its
shadow down the left edge. Fixed in `admin-v2.css`, plus the Students member
panel laid out in two columns (details and history | onboarding checklist).
Swept afterwards: 31 screens × 1440 and 390, no overflow, no failed reads, no
flush sections, no page errors.

**The legacy admin's layout too (`admin/index.php`).** Measured on all 42 views
at 1440 and 390 (the export view is a download and was skipped): 101 runs of
text sat against a card's border on 26 screens — a `.card` has no padding of
its own, so a paragraph, form, pager or KPI label put straight into one had
none either; the pager's "total" was clipped by the rounded corner; every card
painted a dark scroll shadow down its right edge whether it scrolled or not; on
a phone the top bar wrapped to two rows with the email running off screen, the
hidden drawer cast its shadow down the left edge, card headers ran wider than
the card, and five inline two-column forms put their second column off screen;
the Overview said mail was not configured three times. Fixed with one gutter
rule for direct card children (tables stay full-bleed), a class-styled pager,
shadows only on a card that really overflows (`.is-overflowing`, set by a
ResizeObserver), a one-row phone bar with icon-only chips, attribute overrides
for the inline grids, and the Overview's two repeats suppressed while the red
banner shows. Also a pre-existing bug: `?v=courseenroll` read `$_GET['ce']`
after testing it with a default, so its filter was null and every visit
logged a warning. After: 0 flush text at 1440; at 390 only the week calendar
on `?v=schedule` scrolls sideways, deliberately. The PHP error log is empty
across the sweep. `test_admin_layout.php` pins the mechanisms (a mutation of
the pager fails it).

(The legacy-dashboard layout repairs above were made on 2026-11-19 and the
dashboard itself was retired the same day — see §4y. They are history.)

## 4y. 2026-11-19 — the legacy admin dashboard is retired

The owner's call, made with the cost in view: retire `admin/index.php`'s
interface outright rather than phase it out. **The file stays** as the one
implementation of every admin write — the console and the phone subset post
every form to it — plus the `?v=export` downloads. Its ~8,900 lines of screens,
styles and scripts are gone (11,757 → ~2,900 lines). Any other GET redirects to
the console screen that covers it, carrying `?ok=`/`?err=`.

**What has no button now.** Measured after the change: the file handles 117
kinds of action and the consoles post 34. The other **83** were reachable only
from a retired screen. Every handler is intact; each needs a console form that
posts to it, never a second implementation:

- **Money:** `payment_refund`, `payment_confirm_bank`, `wallet_credit`
  (instructor earnings), `referral_reward`.
- **People and access:** `user_create`, `user_edit`, `user_reset`,
  `user_status`, `set_admin_tier`, `guardian_link`, `avatar_clear`; the faculty
  pipeline (`faculty_invite`, `faculty_request_review`, `faculty_app_status`,
  `faculty_assign_subjects`).
- **Admissions:** `app_bulk_status`, `app_note`, `app_assign`, `app_delete`,
  `record_placement`, `resend_confirmation`, `summer_set_status`.
- **Teaching:** `class_create`, `class_edit`, `class_status`, `class_delete`,
  `enroll`, `enrollment_status`, `course_approve`, `course_promote`,
  `bridge_legacy_enrollments`, `meet_reconcile`, `meet_sync`,
  `save_google_meet`.
- **Curriculum and assessment:** `course_create`, `course_update`,
  `course_toggle`, `program_create`, `program_update`, `link_course`,
  `unlink_course`, `module_delete`, `module_scaffold`, `module_import`,
  `module_proposal_review`, `seed_starter`, `rubric_create`, `rubric_delete`.
- **Calendar:** `term_save`, `term_seed_year`, `term_refund_reseed`,
  `closure_add`, `closure_delete`.
- **Events:** `recital_create`, `recital_update`, `recital_status`,
  `recital_reorder`, `recital_email`, `recital_recording`, `recital_add_item`.
- **Compliance:** `vetting_record`, `datarights_erase`, and the whole
  data-protection register (`dp_request_*`, `dp_breach_*`, `dp_processor_save`,
  `dp_note_save`, `dp_assessment_save`, `dp_retention_run`).
- **Settings and content:** `save_settings`, `store_item_save`,
  `store_item_delete`, `resource_add`, `save_email_branding`,
  `save_promo_banner`, `delete_promo_banner`, `acadmail_toggle`,
  `notif_read_all`.

Read-only screens that went with it and have no console equivalent: the
incomplete-enrolment backlog list (`?v=applications&f=incomplete`), the
mail outbox, application logs, webhook log, the v2 read-model view, the
curriculum authoring screens, the BI and analytics screens.

**Added so the console stands alone.** *My account* (People → My account, no
capability): name, phone, password and signed-in devices, posting the existing
`change_own_account` / `session_revoke*` handlers. The temporary-password gate
used to send people to the retired `?v=account`; it now sends them here and
exempts this screen, or it would loop. The rail's name block opens it. *Run
migrations* is a real action (`run_migrations`, `migrate` cap) on System
health; its old link pointed at a view that never existed. 42 console links into
legacy screens were removed rather than left as dead buttons, with the empty
table columns they left.

**A bug the sweep found on the way.** Governance and Data protection printed
the NDPA registration tier as "Array" and read duty keys that
`mam_dp_tier()` does not return (`registers`, `files_returns`) — so
"Must register?" and "Files returns?" said **No at every size**. They read
`tier.label`, `must_register` and `must_file_car` now.

Verified: suite green (6916; `test_admin_tables.php` and `test_admin_layout.php`
pinned the retired markup and are replaced by `test_admin_retired.php`, which
fails on a planted legacy link and on the old tier keys); in Chromium, `/admin/`
and old bookmarks land on the right console screen with their message, the CSV
export still downloads, a password change and a wrong-password refusal work, an
admin on a temporary password is held on My account (desktop, phone view, old
URL) until they change it, and all 32 console screens at 1440 and 390 have no
overflow, failed reads or page errors, with an empty PHP error log.

## 4z. 2026-11-19 — lessons and learning tracking, ported from NGG

NGG's curriculum is units with questions, materials and a progress row per
member. MAM had a syllabus (`modules`: one course · level · week, a title and a
paragraph) and **nothing recording a student against it** — per-student
progress lived only on cohort `lessons`, which nothing links to the syllabus.
Phase 1 makes a module a lesson (schema v97, `api/lib/lessons.php`):

- **Content:** state (draft / published / retired), summary, objectives,
  duration, pass mark, version; questions in `module_checks` (multiple choice,
  put in order, short answer, task, reflection, evidence); materials in
  `module_materials`.
- **Tracking:** `module_progress`, one row per student per lesson — started,
  attempted, submitted (waiting for a teacher) or completed, with score, best %,
  attempts, answers and marks.
- **The rules carried over** (each was a bug NGG shipped first): publishing needs
  a question a teacher marks; answers hidden until a pass or three tries;
  completion sticky; version bumps only when an answered question changes; a
  started lesson is retired, never deleted; a tick never overwrites online work.
- **Who does what:** an admin writes and publishes lessons and manages courses on
  the console's Curriculum → Lessons; a student opens and answers them on Learn;
  their teacher marks written answers on Marking and ticks off classroom weeks
  from Students → the student. A teacher reaches only students they teach on that
  course (refused on view, queue and tick — measured with a second teacher).

Found in the browser and fixed: the editor matched each "why this option is
tempting" line to an option by position, with the right answer's line blank — a
browser drops a textarea's leading blank line, so every reason landed on the
wrong option and shifted again on each save. Lines are keyed by option number now.

Verified: suite (6979; `test_lessons.php`, with mutations of the reveal rule and
of sticky completion each failing it); in Chromium, an admin's mcq-only lesson
was kept as a draft with the reason, publishing worked once a written question
was added, a student at 390 opened it, answered (wrong on purpose, then told why
the option was tempting), the teacher marked the written answer, and the student
saw Done; a question-free syllabus week was ticked and unticked; the console's 32
screens at 1440 and 390 with no overflow, failed reads or page errors, and an
empty PHP log.

**Not in this phase:** fines for overdue lessons (the owner's decision — money
penalties on children are gated by law here), AI marking, exams, mastery tiers and
certificates, placement years, the study helper, materials submitted by
instructors, lesson feedback and private notes.


## 4z2. 2026-11-19 — lessons phase 2: everything NGG had around a lesson

Owner: "Add them all" (fines, AI marking, exams, tiers and certificates,
placement, the study helper, teacher materials, feedback, notes). Schema v98,
`api/lib/lessons-learn.php` + `api/lib/lesson-exams.php`; rules in CLAUDE.md
"Lessons". Decisions made, each reversible by a setting rather than code:

- Fines and AI are built and **switched off**. Fines need the `fees` permission
  to switch on; AI needs a provider configured.
- NGG's viva (model-generated follow-up questions) was **not** ported — it needs
  a model inside every sitting, and weak answers go to a teacher anyway.
- Found and fixed on the way: a lesson saved without a kind was stored as `''`
  (v98 repairs existing rows); bare `.p-outline` buttons had no size on any
  portal; the portal error helper ignored the server's own 4xx sentence;
  a two-part `.p-card-head` ran its label into its count.

Verified: suite 7079/0; Chromium end to end (student notes, feedback, exam with
pledge and clock, teacher exam marking, placement, material sent → approved in
the console, settings saved); console sweep 32 screens × 2 widths clean.

## 4z3. 2026-11-20 — class spaces, storage, live classes, invoices, growth, adaptive, safeguarding (v99–v102)

What was asked: invoice review and negotiation with discounts; assessments that adapt;
in-app accountability and growth tracking; NGG's community as class spaces for (mostly
video) assignments, with private classes; uploads compressed well and kept in a suitable
bucket; a recommendation for closely-monitored online classes with attendance and time;
strong safeguarding for minors. Forms were left alone at the owner's request (a design is
coming).

- **Storage (v99, `media-store.php`).** A PRIVATE S3-compatible bucket (Cloudflare R2
  recommended: cheapest that works, no download charges). Browser asks `/api/media/sign`
  for a presigned PUT (one key, one type, one exact size, 15 min), uploads straight to the
  bucket, then `/api/media/done` HEADs the object before it counts. Every read goes through
  `/api/media/object?id=N`, which checks who is asking and then redirects to a 10-minute
  link. With no bucket the same flow stores on this host (64 MB cap, Range-capable stream).
  Console → System health holds the keys and a write/read/delete test. R2 has no African
  region: note the transfer in the data-protection register.
- **Compression (`assets/js/media-upload.js`).** Mediabunny (WebCodecs, MPL-2.0, vendored
  at `assets/js/vendor/mediabunny.min.mjs`) re-encodes video to 720p H.264 ~2.3 Mbps with
  AAC; photos become 1600px WebP (drops EXIF location). Measured: 12.2 MB 1080p → 2.45 MB in
  ~4 s. Falls back to the original where WebCodecs is missing.
- **Class spaces (v99, `spaces.php`, `portal-spaces.js`).** Option 1 as agreed: a feed per
  class; `class` (the cohort) or `private` (added people only); tasks, hand-ins, marks
  private to student/family/teachers, comments at a moment in a video, reactions, reports.
  No private messages of any kind. A minor without media consent shares video with the
  teacher only. Words are screened (harm → held + urgent concern; contact details, links,
  "add me on…" and unkind words → held). Student videos wait for a teacher by default. A
  teacher's own held post is decided by an admin. The future public community must be a
  separate surface — nothing here is shown outside the space.
- **Safeguarding (v99, `safeguarding.php`).** Anybody signed in can raise a concern; the
  named officer (or every super admin) is told at once; closing needs an outcome; nothing
  deleted. Console → Governance → Safeguarding is the queue. A guardian's "safeguarding"
  message now raises a concern; harm words in any family thread open one. Console → Staff &
  vetting now has the forms to record a check and to name the officer.
- **Invoices (v100, `fee-review.php`).** A draft is checked and signed off by a second
  person before it is issued; editing it afterwards clears the sign-off. Families can query
  an invoice, propose a lower total or 2–6 instalments; the office answers, agrees,
  counters or declines; counters are accepted from the guardian portal. Agreed reductions
  are waivers naming the query. Reminders pause while a query is open. Discount codes
  (percent or amount, cap, end date, once per family) apply as waivers with the code as the
  reason. No interest, no automatic late charges.
- **Online classes (v101, `live-classes.php`, `portal/live.html`).** Recommendation: JaaS
  (8x8.vc) — signed identity per person, staff-only moderators, lobby for classes with
  children, private chat off, webhooks on join/leave; free to 25 monthly users. Presence =
  signed webhooks + a once-a-minute heartbeat from the class page, merged; "Take the
  register from the call" writes online attendance (minutes present, late, left early)
  without overwriting a teacher's mark, and flags one adult alone with children. Meet and
  plain Jitsi stay as link-out fallbacks. `.htaccess` now allows camera/mic/screen for
  `https://8x8.vc` only — `camera=()` would have silently killed the call's camera.
- **Adaptive + growth (v102, `adaptive.php`, `growth.php`, `portal-growth.js`).** Questions
  carry a difficulty (teacher's 1–5, then learnt); each student an ability per course from
  first attempts at machine-marked questions only. Spaced recall and the course trial pick
  near the student's level. Teachers see a suggestion; nothing moves a student by itself.
  Weekly stored snapshots, a twelve-week timeline, and goals that measure themselves, in
  all three portals.

Still open: the owner's form design (admissions/apply), NGG-side storage changes, and the
admissions restructure on NGG's model (task 18).
