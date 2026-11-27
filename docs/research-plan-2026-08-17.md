# The five research documents — reconciled, ranked, and started

**Input:** five commissioned research reports (implementation blueprint ×2, an
evidence-based review of the shipped system, a portal/front-end UX strategy, and
a terminology/conventions update).

**What this document is:** the reconciliation. The five reports overlap heavily,
disagree in places, and — importantly — several of their most urgent
recommendations are about code that is *already shipped*, where the reports'
assumptions about what it does turned out to be partly wrong. This maps every
recommendation onto measured ground truth, resolves the conflicts, and states
what has been built versus what has not.

**Scale, stated plainly.** The five documents describe roughly forty distinct
workstreams and budget them in the reports' own words at **weeks 1–26**. This
turn implemented the blocking tranche — the items both blueprints put in Stage 0
plus the three the review marked "immediately" — completely, with tests. It did
not implement six months of work, and any claim otherwise would be worth less
than the code.

---

## 1. Where the documents agree

Convergence is high, and where all five agree the sequencing is settled:

1. **Safeguarding and staff vetting first.** Both blueprints put it at Stage 0
   and blocking. Doc 2 is far better sourced on Nigeria and is the one to follow.
2. **NDPA 2023 compliance next**, with registration thresholds and a 72-hour
   breach duty.
3. **The academic calendar as a first-class entity** is the dependency for
   refunds, scholarship pro-ration and the withdrawal outcome. Nothing downstream
   is correct without it.
4. **Money as integer minor units with pinned FX and a double-entry ledger.**
5. **Scholarship AWARD as an entity distinct from the application.**
6. **Interoperability last, and mostly not at all** (OneRoster, LTI, QTI, SCORM,
   xAPI) — there is no counterparty demanding it.
7. **Phinx for migrations, MySQL 8 + MariaDB in CI**, strict nonce-based CSP
   approached incrementally.

---

## 2. Where they conflict — and how each is resolved

### 2.1 The Mastery Score: research vs a documented invariant

**The conflict.** Doc 3 says the composite's "weighting and renormalisation are
statistically indefensible as specified" and to stop renormalising. `CLAUDE.md`
documents renormalisation as a *deliberate fix*: "a component with NO evidence is
excluded and the weights renormalised (scoring an unassessed beginner 0 reports
them as failing)".

**Both are right about different things.** Doc 3's own LMS section endorses the
excused-vs-zero distinction — "an 'insufficient evidence' component is *not* a
zero and must not be silently treated as one" — which is precisely what the
exclusion does. The operative word in its criticism is **silently**. Excluding is
correct; *publishing the renormalised result as a comparable number* is not.

**Resolved:** exclusion kept, and the composite now withheld entirely below a
declared coverage floor, banded rather than decimal, with the arithmetic bounds
the unscored weight permits. Implemented — see §4.

### 2.2 Both monetary charges: the reports were measurably wrong about the code

**The claim.** Doc 3 says to "immediately suspend both monetary charges", reading
the $1 student charge and $2 instructor deduction as live.

**Measured.** `late_charge_enabled` defaults to **0**, and both call sites already
tested it. Neither charge fires on a default install. The instructor
progress-report "fine" writes `amount_kobo = 0` — a flag an admin may later price,
and no code path anywhere ever sets a non-zero amount.

**But three real defects sat underneath**, and they are worse than the headline:

| Defect | Consequence |
|---|---|
| `mam_accountability_charge()` read the flag and never checked it | Correct only because two callers remembered to. A third would impose money unopposed. |
| One flag governed student *and* instructor | Enabling student punctuality charges switched on **wage deductions** in the same click. |
| Both call sites *also* tested the flag | Short-circuited the function, so with charging off **no row was written at all** — the lateness vanished. |
| The instructor call site tested `$cfg['enabled']` (the *student* flag) | An instructor deduction needed the student flag too; the instructor setting could not govern it. |
| The cron told instructors "A fine has been recorded" | Untrue when sent: ₦0 was recorded. |

The last three were found by an adversarial verification pass over my *own*
first implementation, not by reading the reports. All fixed — see §4.

### 2.3 Dunning cadence: doc 1 vs doc 2

Doc 1: retry at 24h then days 3/7/14, cap 3–5, grace 7–14 days.
Doc 2: Day 1/3/5/7, four retries over ~14 days, grace 3–7 days.

Both cite vendor analyses; neither is peer-reviewed. **Resolved: adopt doc 2's
schedule** (it is the more specific and cites Recurly's 40-million-transaction
analysis) but **doc 1's longer grace period**, because an education provider's
billing cycle is a term, not a month. Doc 2's ethical modifier is the binding
constraint and overrides both: **never suspend a child's lessons for a guardian's
non-payment during the dunning window; escalate to the guardian, not the child.**
Not yet implemented.

### 2.4 Nigeria vetting: doc 1 vs doc 2

Doc 1 leads with the Police Character Certificate. Doc 2 is materially better
sourced: it cites Child Rights Act 2003 s.2(2) on the *suitability of staff*, and
the Lagos State Safeguarding and Child Protection Policy 2016's Safer Recruitment
mandate — two verified references, a signed policy commitment, an affidavit of
good behaviour, a trained panel member, a Sex Offenders Register search, and a
named Designated Safeguarding Officer reporting quarterly.

**Resolved: doc 2's requirement set is the implemented one.** Doc 1's Police
Character Certificate is included as an *alternative* satisfier, not the anchor —
it is oriented to visa use and does not by itself evidence suitability.

### 2.5 Terminology: British/Nigerian register

Doc 5 wants "programme / course / term / session / enrolment". The codebase is
already substantially there (`enrol-complete.php`, "programme" throughout). This
is a **display-layer relabel**, and the existing `status_definitions` glossary —
which doc 5 explicitly calls *better than convention* — is the right vehicle.
Cheap, low risk, not yet done.

### 2.6 htmx-first front end

Doc 4 recommends htmx as the primary interactivity layer plus nonce-based strict
CSP. Doc 4 itself calls the CSP refactor "a funded workstream, not a side task".
**Not started, and correctly so** — it is an architecture decision with an
explicit cost that needs the academy's sign-off, not something to begin
unilaterally mid-turn.

### 2.7 The instructor comparison table

Doc 3 calls it "the single highest-risk feature". **Measured: it was already
formative in fact** — nothing automated reads it, no pay or wallet path touches
it, and it was ordered by instructor *name*, not by score. What it lacked was
every signal that would stop a reader ranking it. Fixed — see §4.

---

## 3. What the reports get wrong or overstate, and should not be built as written

Recorded because a plan that treats commissioned research as infallible is worse
than one that reads it.

- **Both blueprints assume the charges are live.** They are not (§2.2).
- **Doc 3 assumes the $2 instructor deduction is applied.** The shipped
  progress-report path records ₦0. The $2 lives in a *different* mechanism that
  was gated off.
- **Doc 3's own caveats disown several figures**: the "~6.5% uplift" it could not
  corroborate, the RSL mark boundaries from a secondary source, the Purdue Course
  Signals retention claim it then rebuts. Its dunning numbers come from SaaS
  vendors and should be tuned to MAM's own decline data.
- **Doc 4 flags its own conversion statistics as vendor-inflated** — the
  "multi-step +86%/+206%/+160%", "inline validation +22%", "progress bars −20–30%"
  figures. Only the Baymard forced-account finding and Smith et al. (2015) are
  trustworthy magnitudes. Do not quote the rest to anyone.
- **Doc 5's US-specific code sets** (transcript-withholding bans, SAP components,
  catalogue-rights time limits) are best-practice references, not Nigerian legal
  requirements, as it says itself.
- **All five disclaim legal advice.** The Labour Act analysis in particular turns
  on whether MAM's instructors are workers or contractors (s.91) — a question for
  counsel, which is why the implementation takes the safe default rather than
  deciding.

---

## 4. Implemented this turn

Every item measured before and after, tests added, suite green (**3,448
assertions**).

### Safeguarding: `api/modules/safeguarding/lib/vetting.php` + schema v82 + admin screen — NEW

The reports' #1 blocking item, and nothing existed. `vetting_records` stores
type, issuing body, reference, dates, verifier and a pointer to where evidence is
filed — **never the content of a check and never offence data**, because under the
NDPA that is sensitive personal data and a police certificate scan in a
shared-hosting database is a liability, not a control. Tests assert the schema has
no room for it.

- The required set is `MAM_VETTING_REQUIRED`, a **constant** taken from the Lagos
  Safer Recruitment mandate. Not a setting: an academy able to shorten it would
  have a gate certifying nothing, and shortening the list is the first thing
  anyone does under pressure.
- Alternatives are honoured, so a UK-resident instructor's Enhanced DBS satisfies
  the criminal-record limb rather than being asked for a Lagos search they cannot
  obtain.
- Validity is **derived** from `expires_on`, never a stored status column, so a
  clearance lapsing at midnight reads as expired on the next page load.
- The gate hooks `mam_lms_approve()` — the choke point reached from the admin
  screen, the API routes *and* payment settlement — and `mam_enrol_complete()`.
- Three modes, shipping in **`warn`**. `enforce` is the target state; switching a
  live academy straight to it with zero records would refuse every assignment on
  the next click, and the reliable outcome of safeguarding theatre is somebody
  disabling the feature to get through the day. Every gap is audited from day one
  regardless, so the record exists *before* enforcement, not after.
- An adult-only cohort is not gated (a control that fires on adult classes trains
  admins to ignore it); unknown age counts as a minor by default.
- Admin screen at `?v=vetting`: KPIs, the officer worklist ordered with
  no-records-at-all first, per-instructor recording, gate control, and a standing
  warning when no Designated Safeguarding Officer is named.

### Mastery Score: coverage floor, bands, bounds

Composite withheld below `MAM_MASTERY_MIN_COVERAGE` (a declared value judgement,
as the OECD/JRC Handbook requires); reported as a ten-point band; carries the
arithmetic bounds the unscored weight permits — *not* a confidence interval, since
there is no sampling model and inventing one would be worse than silence.
Subscores stay visible throughout. The student portal now distinguishes "nothing
scored yet" from "not enough scored to be fair". Certification readiness explains
when coverage, rather than attainment, is the blocker.

### Instructor comparison: formative, with its uncertainty

Discloses graded *n*; carries a real 95% interval (sd/√n from E[x²] − E[x]²);
withholds comparative figures below `MAM_ANALYTICS_MIN_ROSTER`; both rendering
surfaces print one shared notice that it must not be used for pay, ranking or
discipline. `ORDER BY u.name` is now commented as load-bearing.

### Charges: gated in depth, flags split, notice made true

All five defects in §2.2 fixed. With charging off a lateness is **recorded**
(status `recorded`, amount 0, outside `MAM_ACCT_OWED`) rather than vanishing.

### Auto-enrol on payment settlement (earlier this session)

`mam_payment_settle_tuition()` now delegates to `mam_enrol_complete()` — one
implementation of account → course → cohort → instructor → status, shared with the
admin screen. Gated by a new `payment_enrol` automation, separate from
`payment_provision` because a login and a class placement are different decisions.

---

## 5. Not implemented, in the order I would take them

1. ~~**Academic calendar**~~ — ✅ **shipped** as `api/modules/academics/lib/calendar.php` +
   schema v83 + admin `?v=calendar`. `academic_terms` carries
   `add_drop_deadline` / `withdrawal_deadline` / `census_date` as dated rows;
   `term_refund_schedule` is the published pro-rata-by-week table (seeded from
   `MAM_CALENDAR_REFUND_DEFAULT`, 100% → 0% at the 60% point, then owned by the
   academy); `term_census` is an append-only snapshot; `term_closures` holds
   holidays. It coexists with the computed engine rather than replacing it —
   `mam_calendar_current_term()` falls back and tags its `source`. It supplies
   the refund authority the blueprints correctly identified as the dependency
   for items 3 and 4 below. See HANDOFF §4d and `CLAUDE.md` #14.
2. ~~**NDPA compliance layer**~~ — ✅ **shipped** as `api/modules/identity/lib/dataprotection.php`
   + schema v84 + admin `?v=dataprotection`. Retention as data, breach register
   with the s.40(2) 72-hour clock from *awareness*, a rights-request log with its
   clock (incl. GAID's SNAG 7/30), DPIA records, lawful-basis-per-purpose, and a
   processor/DPA register that probes what is actually configured.
   ⚠️ **Two corrections to what this item said.** The threshold is 200 distinct
   subjects **in a rolling six months**, and **Ordinary-High Level (201–1,000)
   registers but does NOT file Compliance Audit Returns** — filing starts at
   Ultra-High, so "diarise the annual CAR by 31 March" does not apply to an
   academy of this size. MAM measures **19** and is below the DCPMI threshold
   entirely; `mam_dp_tier()` now computes the position live, because the academy
   crosses that line by growing rather than by deciding to. Separately, the
   30-day response deadline is **not in the NDPA** (the repealed NDPR had one) —
   it ships as the academy's own commitment, labelled as such.
   The *operational* half remains the academy's: register when the count
   crosses, name a DPO, and complete the six outstanding DPIAs. See HANDOFF §4e
   and `CLAUDE.md` #15.
3. **Scholarship funds + awards** — `Award` as an entity attached to a term and
   enrolment, drawing on a capped fund, reducing an invoice through an
   attributable line; waiver (contra-revenue) distinguished from funded
   (scholarship expense).
4. **Instalments, dunning, refunds, disputes** — one invoice per term with a
   payment schedule, manual authorization charges (Paystack subscriptions do not
   retry), the §2.3 cadence, and the never-suspend-a-child rule.
5. **Auditions/placement + prerequisite gating** with hard/advisory/override modes.
6. **Transcripts and certificates** — grade-scale legend, verification codes,
   external board results recorded but never claimed as MAM's own award.
7. **Terminology relabel** via `status_definitions` (§2.5) — cheap, do it opportunistically.
8. **Engineering cross-cuts** — Phinx with a baseline migration, MySQL 8 + MariaDB
   CI containers, CSP report-only → nonce.
9. **Interoperability** — nothing, until a counterparty asks.

---

## 6. Decisions only the academy can make

Blocking the items above, and not answerable by me:

1. **Instructors: employees or contractors?** Determines whether Labour Act
   s.5(1) binds the punctuality deduction at all. Until answered, it stays off.
2. **Is the Mastery Score a decision-making instrument or an informational one?**
   Doc 3 asks this first, and it governs how much statistical machinery is
   warranted.
3. **The coverage floor and roster floor** are currently 40% and 8 — declared
   value judgements. Confirm or change them.
4. **Who is the Designated Safeguarding Officer**, and who is the DPO?
5. **When does the gate move to `enforce`?** It ships in `warn`; someone must own
   loading the existing records and flipping it.
6. **The published fine amounts in the policy handbook** (`mam_policy_points()` in
   `api/modules/integrations/lib/mailer.php`) quote ₦500/$0.50 lateness, ₦1,000/$1 late submission and
   ₦2,000/$2 inconvenience fees. **None of these match the ledger settings, and
   the ledger is switched off** — so families are being told about charges the
   system does not levy, at amounts it does not hold. That is a content decision,
   not a code one, and it needs resolving either way.
7. **Refund cutoff percentage**, and grade replacement vs averaging for retakes.
8. **Whether to fund the CSP/htmx workstream** as doc 4 frames it.

---

## 7. What would change this plan

- If enrolment of EU/UK minors grows materially → elevate GDPR and the ICO Age
  Appropriate Design Code.
- If involuntary churn from failed cards exceeds ~5% of billings → pull dunning forward.
- If a partner institution asks for data exchange → pull OneRoster/PESC forward.
- If the NDPC contacts MAM, or subject volume crosses 1,000 → the compliance layer
  becomes the only priority.
- If US under-13s enrol online → COPPA moves into the blocking tranche.
