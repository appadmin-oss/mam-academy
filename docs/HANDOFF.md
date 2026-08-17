# Handoff — forms, error paths, and the ETL

**Branch:** `claude/repo-audit-index-nr17nr` · **Last commit at time of writing:** `d4c8309`
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
| TanStack Table v8 | decided, **not implemented** |

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

1. ~~**Auto-enrol on payment settlement**~~ — **answered: yes, shipped.** See §4b.
2. **The student portal's gated forms** — worth a browser pass, or take the
   source-level fix as sufficient?
3. **Course-code aliases.** If `violin`/`clarinet` are meant to map onto real
   courses, tell me which and I will add the map. Right now they correctly
   refuse to guess and go to the admin backlog instead.
4. The research you mentioned — send it and I will fold it in here.
