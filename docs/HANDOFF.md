# Handoff — forms, error paths, and the ETL

**Branch:** `claude/repo-audit-index-nr17nr` · **Last commit at time of writing:** `545af41`
**Started from:** production at `f3ca21e`, schema v59.

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
| ETL — identity, payments, applications | ✅ transform proven; `load.php`/`verify.php` **not written** |
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
| Scholarship funds/awards, instalments, dunning | not started |
| TanStack Table v8 | decided, **not implemented** |
| htmx + strict CSP | needs a funding decision (doc 4's own framing) |

### The one thing that is genuinely blocked

Nothing is blocked on information any more. `load.php` and `verify.php` are the
next build, and all three of their old blockers are cleared:

- base currency → **NGN** (your call, recorded)
- courses/classes → **being recreated**, so they are not migrated
- schema → reviewed by you

And the one open policy question is now answered: **auto-enrol on payment
settlement is on** (`payment_enrol`, default on, admin-toggleable).

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
  `8.07 * 100` is `806.9999999999999` in float.
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

---

## 5. Where things live

| What | Where |
|---|---|
| Schema v2 (canonical MySQL, 64 tables) | `db/schema-v2.sql` |
| SQLite mirror — **generated, never hand-edited** | `db/schema-v2-sqlite.sql` |
| The generator (`--check` is wired into CI) | `api/tools/schema-v2-sqlite.php` |
| Status catalogue — 67 statuses × 3 audiences | `api/lib/v2/status-catalogue.php` |
| Clarity read model | `api/lib/v2/clarity.php` |
| Identity resolution (21 people, 0 unaccounted, 2 tombstones) | `api/lib/v2/identity.php` |
| Payment/application transform | `api/lib/v2/transform.php` |
| The enrolment completion chain | `api/lib/enrol-complete.php` |
| Shared portal error translator (`P.errText` / `P.fail`) | `assets/js/portal.js` |
| Self-hosted toast (the CDN-proof one) | `assets/js/libs.js` |
| The academic calendar + refund authority | `api/lib/calendar.php`, admin `?v=calendar` |
| The NDPA layer — requests, breaches, ROPA, retention, processors, DPIAs | `api/lib/dataprotection.php`, admin `?v=dataprotection` |
| The forms audit, with every number | `docs/forms-audit-2026-08-17.md` |
| The codebase map | `docs/codebase-index-2026-08-15.md` |

⚠️ **`api/lib/v2/*` is deliberately NOT in `bootstrap.php`'s `$mam_libs`** — it
queries v2 tables that do not exist in v1. Adding it there boots a 500 on every
request. It *is* in `api/tests/run.php`.

---

## 6. Next, in the order I would do it

1. **`load.php` + `verify.php`.** Nothing blocks these now. `verify.php` must
   reconcile by an independent path, the way the payment transform did — it
   matched the admin screen's NGN 405,034 by arithmetic that never touched the
   admin's own query, which is the only reason the figure is trustworthy.
2. **Watch the first real settlement after deploy.** The auto-enrol path is
   proven against fixtures; the live `discipline` column is the messy part, and
   `auto_enroll_needs_admin` in the log is the signal to read. If that line is
   common, the fix is a course-code alias map, not a fuzzier match.
3. **TanStack Table v8** over the admin's server-rendered tables, as progressive
   enhancement. The pinned actions column and the stacked mobile layout are both
   load-bearing and must survive; that is why headless was the choice.
4. **Admin form error behaviour.** Passes 4 covered labelling, destructive
   confirmation and autofill. What an admin sees when a POST *fails* has not
   been measured — a different question from what the applicant sees.
5. **The student portal's interaction-gated forms.** The default render measured
   clean, but reschedule, teach-back and upload forms only appear after a click
   and were fixed from source rather than driven. Worth one browser pass.

---

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
