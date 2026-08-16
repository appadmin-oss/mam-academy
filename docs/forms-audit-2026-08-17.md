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

| Finding | Severity | Measured |
|---|---|---|
| The wizard has no `<form>` element | **3** | 57 of 58 fields orphaned |
| `autocomplete` on 13 of 58 fields | **3** | 45 fields cannot autofill |
| `required` on 2 of 58 fields | **2** | validation is entirely custom JS |
| Target sizes | **0** | no violations — measured on the label |
| Error announcement plumbing | **0** | 22 live regions present |

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

## [Severity 3] 45 of 58 fields cannot autofill

**Where:** 13 fields carry `autocomplete`; the form asks for name, email, phone,
address, LGA, state, country and date of birth.

**Why it matters.** Autofill is the single cheapest reduction in form effort
that exists, and it compounds with the finding above — without a form wrapper
even the 13 that are annotated work less well, because the browser cannot see
them as one address block.

**Fix:** add the standard tokens — `given-name`, `family-name`, `email`,
`tel`, `street-address`, `address-level1`, `country-name`, `bday`. On a phone,
this is the difference between one tap and forty keystrokes.

## [Severity 2] `required` appears on 2 of 58 fields

**Where:** two fields across a five-step application.

**Why it matters.** Validation is therefore entirely bespoke JavaScript. That is
not wrong in itself — the wizard needs step-aware rules the browser cannot
express — but it means there is no floor. If the JS path fails, is blocked, or
misses a case, nothing catches it, and the browser's own "please fill in this
field" never appears.

**Fix:** mark genuinely mandatory fields `required` as a backstop beneath the
custom logic. With a `<form>` in place (finding 1) that becomes a real second
line of defence rather than an unused attribute.

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

## Not yet measured

Honest scope. This pass covered structure, labelling, target size, input types,
autocomplete and validation plumbing on the **entry step**. Still to do:

- Per-step measurement past step 1 (the wizard advances by click handler, and
  driving it reliably needs a longer pass).
- **Error behaviour under real failure** — submit with a bad email, a dropped
  connection, an expired CSRF token. The portal audit found silent failures
  elsewhere in the codebase, so this is worth its own pass.
- The **headshot upload step**, which CLAUDE.md records as having failed in four
  separate places historically.
- The admin-side forms.

---

## Recommendation

Do finding 1 first. It is one wrapper per step plus a listener change, it makes
findings 2 and 3 actually effective, and it converts a 46%-abandonment form from
"custom JS is the only thing holding it together" to "the browser helps too."

None of this needs the v2 schema, so it can ship independently of the ETL.
