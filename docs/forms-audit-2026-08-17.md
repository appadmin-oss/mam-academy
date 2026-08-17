# Forms audit — the wizard, the payment step, the admin, the portals

**Measured in a browser** against the running site, 2026-08-17. Numbers below
come from the DOM and the accessibility tree, not from reading the source.

Five passes, in the order they were done: the wizard's structure (pass 1), its
error paths and the headshot step (pass 2), the payment step (pass 3), the
admin's 38 views (pass 4), and the instructor + guardian portals (pass 5).

**Why the wizard first:** it is the only revenue-critical form the academy has,
and the funnel says it is leaking. The production export holds **48 application
drafts against 26 submissions** — a 46% abandonment rate on a form people have
already started. The later passes followed the money and then the people who
use the system daily.

---

## Summary

The wizard's *structure* is good — five steps, autosave, a progress indicator,
22 live regions already wired for error announcements, and touch targets that
measure fine once the label is measured rather than the hidden radio.

The defect is underneath: **the wizard is not a form.** `apply.php` contains
**zero `<form>` elements**. 58 fields, **57 of them outside any form**, and
`apply.js` has no `submit` listener — every control is wired by click.

That one fact causes most of what follows.

| Finding | Severity | Measured | Status |
|---|---|---|---|
| **The phone field is dead without a CDN** | **4** | wizard unfinishable | ✅ fixed |
| **"Payment received" shown when it wasn't** | **4** | verify 404 → success screen | ✅ fixed |
| **Portal errors written only to `console.log`** | **4** | the CDN fallback was not one | ✅ fixed |
| The wizard has no `<form>` element | **3** | 57 of 58 fields orphaned | ✅ fixed |
| **A failed submit doesn't look like a failure** | **3** | `role=null`, transparent, unfocused | ✅ fixed |
| **"A photo is required" is written to nothing** | **3** | element absent from the page | ✅ fixed |
| ~~`autocomplete` on 13 of 58 fields~~ | ~~3~~ | **withdrawn — my error** | see below |
| **The payment step spoke in machine tokens** | **3** | `init_failed`, `api_unavailable` | ✅ fixed |
| **70 admin controls with no accessible name** | **3** | 70 of 350, all per-row | ✅ fixed |
| **20 instructor controls with no name** | **3** | 20 of 51, caption ≠ label | ✅ fixed |
| `required` on 2 of 58 fields | **2** | validation is entirely custom JS | ✅ fixed |
| Upload failures all read alike | **2** | one message for 429/413/5xx | ✅ fixed |
| Banner border contrast | **2** | 1.6:1 and 2.4:1 vs 3:1 | ✅ fixed |
| Destructive admin actions with no confirm | **2** | 3 of 10 | ✅ fixed |
| Admin-set passwords offered to autofill | **2** | 2 fields | ✅ fixed |
| Instructor errors pasted raw server codes | **2** | 17 call sites | ✅ fixed |
| A silent note-delete failure | **2** | looked identical to success | ✅ fixed |
| Failed boot left the user card "Loading…" | **2** | no retry offered | ✅ fixed |
| Target sizes | **0** | no violations — measured on the label | — |
| CSRF expiry, dropped-connection recovery | **0** | both recover; draft survives | — |
| CSRF coverage on admin forms | **0** | every mutating form carries a token | — |
| Guardian portal | **0** | 4 of 4 named; both failures announced | — |

---

## [Severity 3] The wizard is not a form

**Where:** `apply.php` — 51 `<input>`, 4 `<select>`, **0 `<form>`**. The only
form on the page is the footer newsletter signup.

**Why it matters.** A `<form>` is not decoration; the browser gives real
behaviour to fields inside one, and none of it is available here:

- **Enter does nothing.** Implicit submission requires a form. On a five-step
  wizard where people type an email and press Enter — the universal habit —
  nothing happens. There is no feedback either; it simply does not respond.
- **Autofill is degraded.** Browsers group fields by form to recognise a
  name/address/phone block. Orphaned fields are matched individually and much
  less reliably, so an applicant who could have filled six fields in one tap
  types them instead.
- **Password managers will not offer to save** the applicant's details.
- **Native constraint validation never fires.** `type="email"` and `required`
  are only *enforced* on form submission. Every field's `willValidate` is true,
  but nothing ever triggers the check — so the attributes are decorative and the
  custom JS is the only thing standing between a typo and a bad row.
- **Screen readers lose the form landmark**, so there is no "form with 12
  fields" affordance to navigate by.

**Fix:** wrap each step's fields in a `<form>` and listen for `submit` rather
than click. The JS validation stays exactly as it is — this adds the browser's
behaviour underneath it, it does not replace it. `preventDefault()` keeps the
wizard's own navigation intact.

This is a contained change: one wrapper per step, one listener swap. It is
listed first because it is the cheapest large win available on this page.

## ~~[Severity 3] 45 of 58 fields cannot autofill~~ — WITHDRAWN

**This finding was wrong, and the error was mine.** Recorded rather than
quietly deleted, because a withdrawn finding is as useful as a confirmed one.

I reported "13 of 58 fields carry `autocomplete`". The denominator was the
mistake: **45 of those 58 are radios and checkboxes**, which take no
autocomplete token at all. Counting them made a complete implementation look
two-thirds missing.

Checking the fields that can actually autofill — 18 of them — **13 carry a
token and every token is correct**: `given-name`, `family-name`, `email`,
`tel`, `street-address`, `address-level3`, `address-level2`, `address-level1`,
`country-name`, `bday`, `name`. The five without are `applyCurrency`,
`program`, `experience`, `goals` and `referrer` — application-specific choices
with no personal-data token to give them.

**No change made.** Autofill on this form was already right; what it lacked was
the `<form>` element for the browser to group those fields into, which the first
finding fixed.

The lesson for the next audit: a ratio is only a finding if the denominator is
the set that *could* have the property.

## [Severity 2] `required` appears on 2 of 58 fields

**Where:** two fields across a five-step application.

**Why it matters.** Validation is therefore entirely bespoke JavaScript. That is
not wrong in itself — the wizard needs step-aware rules the browser cannot
express — but it means there is no floor. If the JS path fails, is blocked, or
misses a case, nothing catches it, and the browser's own "please fill in this
field" never appears.

**Fixed.** `required` now marks exactly the eight fields `validateStep()`
enforces unconditionally — `firstName`, `lastName`, `email`, `phone`,
`address`, `lga`, `state`, `country` — joining `dob`, which already had it.

Deliberately NOT marked:
- **`city`** — the wizard does not validate it, so marking it required would
  invent a rule the application does not have.
- **`parentName` / `parentPhone`** — required only for minors. A hardcoded
  `required` would misdescribe an adult's application, and the JS already
  applies the conditional rule correctly.

Under `novalidate` these do not block submission, and that is intended: the
wizard's own messages stay the ones people see, rather than a second, blander
browser voice saying "Please fill in this field". What the attribute gives is a
screen reader announcing "required", a machine-readable description of the form,
and a real backstop the day `novalidate` is reconsidered.

---

## What is working

Worth saying, because these should not be "fixed":

- **Target sizes pass.** My first pass flagged 21 fields under 24px; that was a
  measurement error on my side — they are radios styled as cards, where the
  clickable target is the label. Measured correctly, there are **no**
  violations.
- **22 live regions** are already present, so error announcement is wired.
- **Five steps with autosave** and a progress indicator is the right shape for
  an application of this length. The structure is not the problem.
- The advisor and the draft-recovery flow are genuinely good work.

---

## Pass 2 — error paths and the headshot step

Measured 2026-08-17 by driving the wizard to Review five times in a real
browser, once per failure, with the CDNs blocked as they are on a bad network.
Everything below is an observation, not a code reading.

### [Severity 4] With the CDN blocked, the wizard cannot be finished

The phone field is bound entirely inside `initIti()`, which began:

```js
if (!el || typeof window.intlTelInput !== 'function') return null;
```

intl-tel-input is loaded from **jsdelivr**. When it doesn't arrive — ad-blocker,
corporate proxy, tightened CSP, one bad minute of network — that line returns
having bound **nothing**. `data.phone` stays empty however carefully the
applicant types, `validateStep()` fails the number it cannot see, and the
message says *"That number isn't valid for the selected country."*

Measured: a valid `+2348031234567` typed into the field, Continue pressed,
**step 1, forever**. There is no way through and nothing on screen hints at the
real cause. On the academy's only revenue form, on a market where mobile
networks are the norm, that is the whole funnel.

**Fixed.** The plain `input`/`blur` listeners are bound unconditionally; the
country picker is now an enhancement layered on top rather than the thing that
makes the field work. Re-measured: the same number now advances to step 2.

### [Severity 3] Two of the three failure banners were invisible

`ensureNotice()` returns a bare `<div>`; dressing it was the caller's job, and
only `apiError()` did it. Measured on `#apiNotice` after a real failure:

| Failure | `role` | background | border | focused |
|---|---|---|---|---|
| server rejects the email | `null` | `rgba(0,0,0,0)` | `0px` | no |
| connection dropped | `null` | `rgba(0,0,0,0)` | `0px` | no |
| generic `apiError` | `alert` | red 12% | 1px red | yes |

So the two most likely real failures printed as **ordinary body text** at the
top of the form — nothing to announce them to a screen reader, nothing to catch
anyone else's eye. Worse for the first: `showServerErrors()` also carries the
applicant back two steps, and focus was left on `<body>` — a keyboard user is
dumped at the top of the document with the wizard silently somewhere else.

**Fixed.** One `NOTICE_TONES` registry + one `setNotice(html, tone, takeFocus)`
renderer; tone travels with the message so a new caller cannot forget it.
A dropped connection is **amber, not red** — an unreachable server is not the
applicant's mistake. Server field errors now focus the first rejected field
rather than the banner (`role=alert` reads it either way). A successful retry
calls `clearNotice()`, so yesterday's red banner cannot sit over the payment
step reading as a failed payment.

Border alphas were recomputed while in there: the shipped red measured
**1.6:1** and amber **2.4:1** against `#2A2119`, under WCAG 1.4.11's 3:1 — the
outline that said "error" was itself too faint to see. Now 3.7 / 4.1 / 4.1:1
with text at 9.8–10.7:1.

### [Severity 3] The "photo required" message was written into nothing

`validateStep()` looked up `.hs-status` under the dropzone and wrote the
message there. **That element was not on the page.** So pressing Continue with
no photo produced a border-colour pulse and nothing else — silent to a screen
reader, and easy to miss for anyone who had scrolled the dropzone away. The
photo is the one hard gate on that step.

**Fixed.** `#hsStatus` exists as a `role="status"` region, one `setHsStatus()`
writer raises it (naming the actual reason: still uploading / the upload's own
error / the quality verdict / simply missing) and a stored photo lowers it. If
the uploader itself fails to load, it says so and offers the email route rather
than blocking silently. The pulse now has a `prefers-reduced-motion` branch that
holds the red border statically — the cue survives, the motion doesn't.

### [Severity 2] Every upload failure read the same

`/api/upload/headshot` is rate-limited to 12 per 10 minutes. An applicant whose
first shots fail the AI quality check reaches that honestly — I reached it just
by testing — and the widget said *"Could not save the photo."* So they retry,
hit the same wall, and give up at the step they cannot skip. 413 and 5xx were
equally anonymous. Each now names itself, and the rate-limit message says how
long to wait and that nothing else was lost.

### The headshot chain — all four historical failure points, re-walked

CLAUDE.md records this chain failing in four separate places. Driven end to end
against the running site:

1. **CSRF on the upload** — `POST /api/upload/headshot` went out with
   `X-CSRF-Token` present; `200`.
2. **`MAM_HEADSHOT.upload()` reachable** — exports measured as
   `mount, upload, _internals`.
3. **The URL guard on the way in** — the form submitted
   `/api/media/headshot?k=…` and the server answered `200` with a reference,
   i.e. the first-party URL is no longer refused by an `https://`-only check.
4. **A form that submits anyway** — with no photo the wizard stops at step 1,
   and now says why.

Then the two things that actually prove it: the returned URL was re-fetched and
answered **`200 image/jpeg`**, and the row it landed in carries the URL rather
than an empty string. A URL that 404s is not a saved photo, and a stored photo
that never reaches the row is not a photo either.

### What recovers correctly, unchanged

- **An expired CSRF token** — the first POST 403s with `csrf_invalid`,
  `MAM_API.request()` refetches and retries; measured **2 POSTs, lands on the
  payment step**, no banner, applicant sees nothing. Correct.
- **A dropped connection** — the button re-enables in **1.3s**, the draft
  survives in `localStorage`, a one-tap mailto fallback is offered, and pressing
  Submit again once the network returns completes normally. The idempotency key
  makes that retry safe.

---

## Pass 3 — the payment step

Same method: driven to the payment step five times, once per failure.

### [Severity 3] The last screen before money spoke in machine tokens

`apiError(err.message)` prints whatever the API layer threw, and for a
`MAM_API` error `message` **is** the server's error code. Measured, verbatim,
as the whole of what the applicant was shown:

| Failure | What they read |
|---|---|
| Paystack unreachable | `init_failed` |
| our endpoint 500s | `api_unavailable` |
| SDK blocked after a good init | `script_load_failed` |

No cause, no route forward, at the exact moment someone is about to pay. The
generic fallback copy that *did* mention the emailed payment link was never
reached, because `err.message` was always truthy.

**Fixed.** A `PAY_ERRORS` map answers every code the two payment routes can
actually raise — read off `payment-init.php` and `payment-verify.php`, not
invented — and every branch ends in something the applicant can do. An unknown
code is never printed *as* the message; it becomes a short `(ref: …)` suffix so
support can still act on it, with the full body logged to the console.

Blocked payment SDKs get their own copy, because ad-blockers and office
networks block them routinely: *"Your browser or network blocked the secure
checkout window — ad-blockers and office networks often do."*

Also fixed while in there: a blocker that answers with an empty `200` loads
"fine" and leaves no `PaystackPop`, so the next line threw
*"Cannot read properties of undefined"* at the applicant — both providers now
check before use. A missing `amount_ngn` reached Paystack as `NaN` and failed
opaquely inside their iframe, where nothing can be said about it. And
`loadScript` was declared twice; identical today, a divergence waiting.

### [Severity 4] "Payment received" was shown when nothing had received it

`afterProviderCharge()` treated **any** non-402 error from our own verify call
as transient and showed the confetti success screen. Measured: with verify
answering `404 payment_not_found` after a real charge, the applicant got
*"Payment received. Our system will confirm shortly."*

The soft branch is a **promise that the webhook will reconcile**, and it may
only be made where that holds — the provider took the money and *our* call
failed transiently. It is now limited to network failures, 5xx and 429. Any
other 4xx says the true thing: the payment went through, we could not record it
automatically, here is the reference to quote — *and you will not be charged
again*, which is the thing people are actually afraid of.

---

## Pass 4 — the admin forms

### [Severity 3] 70 controls in the admin had no accessible name

Measured across 38 views with Chromium's accessibility tree: **350 visible
data-entry controls, 70 with no accessible name.** Every one was a per-row
control in a data table.

| View | Unnamed | What it was |
|---|---|---|
| Applications | 43 | status `<select>` + Save, once per row |
| Users | 15 | account status ×12, admin tier ×3 |
| Course enrolments | 8 | instructor ×4, status ×4 |
| Classes | 3 | class status |
| Summer | 1 | the filter select beside the search box |

On the Applications screen that means 21 identical combo boxes announced as
"combo box, submitted", with nothing to say **which applicant**. This is the
screen the admin called impossible to use, and the controls that change an
admissions decision could not be told apart. The admin-tier select — the
highest-privilege control in the whole interface — was among them.

A per-row control cannot carry a visible `<label>`: there is one column heading
for twenty rows. `aria-label` naming the row's subject is the pattern, and it is
what the six sites now do (*"Status for Adaeze Okonkwo"*, *"Admin tier for …"*),
with a fallback to the record number so a nameless row still produces a
distinguishable label rather than an empty one. Re-measured: **0 unnamed.**

⚠️ **Two measurement traps, both of which gave me a wrong number first**, worth
writing down because the next person will hit them:

- CDP computes accessible names only after `Accessibility.enable`. Without it
  every control reads as unnamed — 350 of 350, which looked like a catastrophe
  and was a bug in the harness.
- `Accessibility.queryAXTree` returns descendants, and a `<select>`'s
  `<option>`s each carry a name. Taking "the first named node" reports the
  option's text as the select's label and hides the defect completely — 0 of
  350, the opposite error.

Match the AX node by `backendNodeId`, with accessibility enabled, or the figure
is meaningless in one direction or the other.

**Three remaining hits are NOT defects.** The `programs` view reports three
controls unnamed; all three are correctly wrapped in a `<label>` whose
`.control` resolves, and they sit inside a **closed `<details>`**, which
Chromium excludes from the AX tree while `getBoundingClientRect()` still
reports a height — so my "is it visible" filter let them through. Recorded
rather than "fixed", because correct markup should not be changed on the word
of a tool already caught being wrong twice in this pass.

### [Severity 2] Three destructive actions asked nothing

`user_reset`, and `module_delete` at both its sites, submitted immediately.
Deleting a week removes its title, objectives and materials for every student
on the course; resetting a password locks someone out until they are told the
new one. Both now confirm, and the confirmation **states the consequence**
rather than asking "Are you sure?".

`session_revoke` deliberately still has none — a single revoked session is
undone by signing in again, and `session_revoke_others` (the one with real
blast radius) already confirmed.

### [Severity 2] Admin-set passwords were offered to the browser's autofill

Both admin-set password fields are `type="text"` on purpose — whoever sets the
password has to read it back to the person. That makes excluding them from
autofill mandatory rather than optional, or the browser offers to save someone
else's credential and may autofill the admin's own into it. Both now carry
`autocomplete="new-password" spellcheck="false"`, with the reason for the
visible type written beside them.

### What the admin got right

Checked and found sound, so nobody "fixes" them:

- **CSRF on every mutating form.** A first scan flagged the three `module_*`
  forms; they carry the token through an interpolated `$hid` variable my regex
  could not see. No gap.
- **No mutating form defaults to GET.** The six forms without a `method`
  attribute are all filters and JS-driven panels.
- The account-page password fields already carry `current-password` /
  `new-password` correctly.

---

## Pass 5 — the instructor and guardian portals

Signed into each portal with real teaching data seeded (four students on a
class and a cohort, one scheduled session) and walked every tab that renders a
control.

### [Severity 4] The portal's errors were written to the console and nowhere else

An instructor recorded a mark, the server answered 500, and **nothing
appeared**. The catch ran — the button re-enabled — and `MAM_DIALOG.toast`
existed. Its no-SweetAlert2 branch was:

```js
toast: function (message, icon) {
  if (!swal) { console.log('[toast]', message); return; }
```

`alert` and `confirm` degrade to native dialogs, which are at least visible.
Only `toast` degraded to somewhere no user ever looks — and **toast is the
instructor portal's entire error vocabulary**: "Could not record: …", "Could
not save attendance.", "Could not update the mark.", ~15 call sites. So on any
network that blocks the CDN — ad-blocker, corporate proxy, tightened CSP — an
instructor recording a child's mark watched the form reset and reasonably
concluded it had saved. It had not.

This is the **second** time in this audit that a third-party CDN script turned
out to be load-bearing for a core function behind a fallback that was not one;
the apply wizard's phone field was the first. Worth treating as a pattern to
look for rather than two coincidences.

**Fixed** with a self-hosted toast: styles injected, nothing fetched, so it
cannot fail the way the thing it replaces did. `role="alert"` for a failure and
`role="status"` for a confirmation, message set as `textContent` (callers pass
server-derived strings), errors linger 9s against a confirmation's 4s because a
missed error is the whole problem, always dismissible, reduced-motion aware.
Borders carry the tone and clear WCAG 1.4.11 — err 4.77:1, warn 7.17:1, ok
7.38:1, neutral 3.42:1 (the first neutral I picked measured 2.97:1).

### [Severity 3] 20 of 51 instructor controls had no accessible name

The portal's idiom is:

```html
<div class="field"><label>Score</label><input name="score" …></div>
```

A `<label>` that neither wraps the control nor carries `for` is a **caption**,
not a label. It looks correct on screen and associates with nothing. 31 sites
in this file.

Worst on the **gradebook**, where 13 of 13 controls were unnamed and the same
four repeat per student card — so even read aloud correctly, "Score" is
ambiguous between students the moment there is more than one. Those now name
the student (*"Score for Adaeze Okonkwo"*), because recording a mark against
the wrong child is the failure that screen has to prevent. Two cohort filters
had no text to take a name from at all — one sat inside a `<label>` whose only
content was a filter icon.

Re-measured: **0 of 51 unnamed.**

### [Severity 2] Errors pasted the server's machine code into the sentence

"Could not record: `server_error`". 17 call sites did this, so the translation
went into the portal kit rather than into each of them: `P.errText(err,
fallback)` maps the codes the routes actually raise, and `P.fail()` reports one
— falling all the way back to `alert()`, so a missing dialog library can never
again make an error invisible. An unrecognised code is never printed *as* the
reason; it becomes a `(ref: …)` the person can quote. A dropped connection gets
the network answer rather than a reference number, and only something that
looks like a code is offered as one — fetch's own "Failed to fetch" is not.

Two sites still read `err.body.error` directly; both already map it to words
themselves, so they were left alone.

### [Severity 2] Two other silent failures, and a boot that never resolved

`delMarker()` had an empty catch while `addMarker()` right above it toasted —
so a note delete that failed looked exactly like one that worked, and the note
stayed on screen as if nothing had been clicked. `loadMarkers()` had one too,
which reads as "this take has no notes" rather than "we could not fetch them",
and would have the instructor re-enter feedback that already exists.

*(A first scan of this file reported 23 empty catches. That was a loose regex
counting multi-line catch bodies as empty. Measured properly: 2.)*

With the API unreachable, the main area said "Could not load your dashboard"
while the sidebar's user card still said "Loading…" — a page claiming to be
both finished and still working, with nothing to retry with. Both now resolve,
and there is a Try again button.

### The guardian portal was already right

4 visible controls, **all named**. Both failure paths already say plainly what
happened — *"Could not load your children right now."*, *"Could not load this
child right now."* — and it carries none of the caption-as-label idiom. No
change made; recorded here so it is not "fixed" later.

---

## Not yet measured

- Per-step measurement past step 1 for structure/labelling (passes 2–5 drove
  every step, but audited the later ones for *failure behaviour*, not layout).
- **The student portal**, which carries the same caption-as-label idiom at
  **16 sites** — counted statically, not yet driven in a browser, so the real
  figure is unknown. It shares `libs.js`, so the toast fix already reaches it.
- Admin form **error** behaviour — pass 4 covered labelling, destructive
  confirmation and autofill; what an admin sees when a POST fails is a separate
  question from what the applicant sees.

---

## Recommendation

Do finding 1 first. It is one wrapper per step plus a listener change, it makes
findings 2 and 3 actually effective, and it converts a 46%-abandonment form from
"custom JS is the only thing holding it together" to "the browser helps too."

None of this needs the v2 schema, so it can ship independently of the ETL.
