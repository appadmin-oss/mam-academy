# Forms audit — the application wizard

**Measured in a browser** against the live page, 2026-08-17. Numbers below come
from the DOM, not from reading the source.

**Why this form and not another:** it is the only revenue-critical form the
academy has, and the funnel says it is leaking. The production export holds
**48 application drafts against 26 submissions** — a 46% abandonment rate on a
form people have already started.

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
| The wizard has no `<form>` element | **3** | 57 of 58 fields orphaned | ✅ fixed |
| **A failed submit doesn't look like a failure** | **3** | `role=null`, transparent, unfocused | ✅ fixed |
| **"A photo is required" is written to nothing** | **3** | element absent from the page | ✅ fixed |
| ~~`autocomplete` on 13 of 58 fields~~ | ~~3~~ | **withdrawn — my error** | see below |
| `required` on 2 of 58 fields | **2** | validation is entirely custom JS | ✅ fixed |
| Upload failures all read alike | **2** | one message for 429/413/5xx | ✅ fixed |
| Banner border contrast | **2** | 1.6:1 and 2.4:1 vs 3:1 | ✅ fixed |
| Target sizes | **0** | no violations — measured on the label | — |
| CSRF expiry, dropped-connection recovery | **0** | both recover; draft survives | — |

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

## Not yet measured

- Per-step measurement past step 1 for structure/labelling (this pass drove
  every step, but audited the later ones for *failure behaviour*, not layout).
- The admin-side forms.
- Payment-step failures (`initializePayment` / `verifyPayment`) — a separate
  provider surface with its own error vocabulary.

---

## Recommendation

Do finding 1 first. It is one wrapper per step plus a listener change, it makes
findings 2 and 3 actually effective, and it converts a 46%-abandonment form from
"custom JS is the only thing holding it together" to "the browser helps too."

None of this needs the v2 schema, so it can ship independently of the ETL.
