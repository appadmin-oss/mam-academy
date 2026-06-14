# MAM Academy — Application & Admissions: Enhancement Proposal

**Status:** proposal for review · **Author:** engineering · **Date:** 2026-06-14
**Scope:** lift conversion and make admissions enterprise-grade, automated, and AI-optimized — across the whole applicant journey (discover → apply → pay → confirmed → onboarded) — without destabilising the working flow.

> Companion to `docs/lms-enterprise-proposal.md` and `docs/studio-enhancement-proposal.md`. The three share one spine: the durable mail outbox (v17), the automation cron, and the audited AI gateway proposed for the LMS. This document grounds every claim in the live code (`assets/js/apply.js`, `assets/js/headshot.js`, `assets/js/validate.js`, `api/routes/application.php`, `api/routes/payment-verify.php`, `api/lib/validation.php`, `api/lib/mailer.php`, `api/lib/auth.php`).

---

## 0 · TL;DR

The application is a polished, well-engineered 5-step funnel: auto-saving, idempotent, server-authoritative on price, with an on-device AI headshot check and genuinely thoughtful validation. Its three biggest limitations are **structural, not cosmetic**:

1. **Drafts live only in `localStorage`** — there is no cross-device resume and no server-side draft. A prospect who starts on their phone cannot finish on a laptop, and an abandoned draft is invisible to us.
2. **There is no applicant status portal** — after paying, the applicant gets an inline "You're In!" panel and an email, then nothing they can return to. No "received → under review → decision → next steps."
3. **There is no admissions pipeline for staff and no funnel analytics** — admissions can't see or work a pipeline, and we can't see where applicants drop.

Plus one **latent bug** worth fixing today: `showFieldError('payment')` is called at `apply.js:1128` but never defined — clicking *Pay* with no method selected throws a `ReferenceError`.

This proposal fixes those in four additive phases, reusing the LMS automation + AI spine, with a handful of same-day quick wins.

---

## 1 · Current state (honest assessment)

**Genuinely strong, verified in the client + server:**

| Area | State |
|------|-------|
| Guided flow | ✅ 5 steps with conditional sub-steps (level/expression/minors shown per pathway; renumbered so no gaps) — `apply.js:390-425` |
| Auto-save & resume | ✅ Saves on nearly every interaction to `mam.apply.draft`; restores to the saved step (capped at Review), with a device-scoped "Welcome back" banner — `apply.js:152-154, 1428-1464` |
| Idempotency | ✅ One persisted key per draft (`MAMK-…`), passed to submit — `apply.js:71-74` |
| Pricing | ✅ Server-authoritative: only the regional **booking fee** is shown/charged (`GET /api/booking/pricing`); tuition is deferred to post-assessment **by design** — `apply.js:1065-1095, 374-377` |
| Headshot | ✅ On-device MediaPipe face check (one face, framing, tilt, eyes) with graceful degradation; unsigned Cloudinary upload of the URL only — `headshot.js` |
| Validation | ✅ On-blur live + on-submit gating + server-422 reconciliation; gibberish-name block, disposable-email *warn*, email-typo one-tap fix, intl-tel-input phone — `apply.js:206-305, 1326-1380`, `validate.js` |
| Track logic | ✅ KTAMS vs MAM derived from DOB; guardian fields for under-16; policy agreement captured — `apply.js:100-103, 476-479` |
| Payments | ✅ Paystack + Flutterwave inline + bank transfer; verify via `POST /api/payment/verify`; CSRF + idempotency in `MAM_API` |
| Resilience | ✅ API-down → prefilled `mailto` fallback; optimistic success on transient verify errors, reconciled by the signed webhook — `apply.js:926-946, 1186-1207` |
| Provisioning | ✅ Paid applicant → student account (`mam_ensure_student_user`) + confirmation/invoice email (`mam_email_application_confirmation`) |
| A11y / mobile | ✅ `aria-current` stepper, `role="alert"/"status"` live regions, keyboard step nav, sticky mobile summary bar |

---

## 2 · Gaps & weaknesses (grounded)

1. **`localStorage`-only state → no cross-device resume, no expiry, invisible abandonment.** The single biggest conversion leak. (`apply.js:152-154`)
2. **No applicant status portal.** Success is an inline panel that `clearDraft()`s; there's no return URL showing application state, decision, or next steps. (`apply.js:1219-1239`)
3. **No admissions pipeline for staff.** Applications land in the DB + an admin list, but with no stages, notes, assignment, decision capture, or templated applicant comms.
4. **Zero funnel analytics.** No step/submit/pay events anywhere — we are blind to where applicants drop. (confirmed absent across `apply.js`/`headshot.js`/`validate.js`)
5. **Latent bug — P1.** `showFieldError('payment')` at `apply.js:1128` is undefined → `ReferenceError` when *Pay* is clicked with no method selected. The correct `#err-payment` pattern is used elsewhere (`apply.js:300-302`).
6. **Error focus is `scrollIntoView` only, not `.focus()`** — screen-reader users aren't moved to the failing field. (`apply.js:303`) — a WCAG gap.
7. **Optimistic payment success on 5xx/network verify errors** relies on the webhook; if the webhook is misconfigured the applicant believes they paid while the record stays pending, with no surfaced reconciliation. (`apply.js:1204`)
8. **No abandoned-draft recovery.** Because drafts are client-only, we can't email "you're almost there" nudges.
9. **No magic-link resume** and **no document uploads beyond the headshot** (e.g., prior certificates for advanced-placement pathways).
10. **Headshot upload is hard-required when Cloudinary is configured** — a failed upload can block submission even though the *AI check* degrades gracefully. (`apply.js:276`)

---

## 3 · Target improvements (phased, additive, test-covered)

### A1 · Server-side drafts + applicant status portal — *the conversion unlock*
- **Resumable drafts on the server.** Mirror the `localStorage` draft to a `application_drafts` row keyed by email + idempotency key; offer a **magic-link resume** ("email me a link to finish later") so any device can continue. `localStorage` stays as the instant local cache; the server becomes the source of truth.
- **Applicant status portal.** A lightweight authenticated page (reuse the portal/auth foundation) showing the lifecycle: `received → under review → decision → enrolment/onboarding`, the reference, the invoice/policy links, and the booking-fee status. Replaces the dead-end inline success panel with something the applicant returns to.
- *Tests:* draft round-trips server↔client; magic link resolves to the right draft; status reflects the application/payment state machine.

### A2 · Admissions pipeline for staff (CRM-grade, code-free)
- An `admissions` view over applications with **stages** (new / reviewing / waitlist / accepted / declined / enrolled), assignee, internal notes, and a decision record — modelled exactly like the LMS enrolment lifecycle (`mam_lms_transition`-style state machine + an audit table, so who/when/why is recorded).
- **Templated communications** sent through the v17 outbox: acknowledgement, request-more-info, decision letters — all retryable and audited.
- *Tests:* every stage transition legal/illegal; each decision writes an audit row + queues the right email.

### A3 · Funnel analytics + accessibility + the quick-win fixes
- **Privacy-respecting funnel instrumentation** (first-party, no third-party pixel): emit `apply_step_view`, `apply_step_complete`, `apply_submit`, `apply_pay` to a first-party endpoint so we can measure drop-off per step and per pathway.
- **A11y:** move programmatic focus to the first invalid field (not just scroll); verify the whole flow to **WCAG 2.2 AA**.
- **Quick-win bug fixes** (see §5).

### A4 · AI-assisted admissions (via the LMS AI gateway)
- **Auditer placement recommendation:** turn the entry assessment + any Studio telemetry into a recommended pathway/level with a written rationale — schema-constrained JSON, **human-confirmed** (reuses the `api/lib/ai.php` gateway, model-tiered to Opus 4.8 for this PII-bearing, high-stakes call, kept on a zero-data-retention-compatible model).
- **Automated abandoned-draft nudges** via the automation cron: if a server draft is idle N hours, queue a gentle "finish your application" email (one-touch resume link), capped and unsubscribable.
- **Smart admissions triage:** draft a reviewer summary per application (strengths, fit, flags) for staff — advisory only, stored with prompt+model+reviewer.

---

## 4 · Success metrics

| Funnel stage | Metric |
|--------------|--------|
| Discovery → start | Application starts / unique visitors |
| Start → submit | Step-completion + overall start→submit rate (per pathway) |
| Submit → pay | Booking-fee conversion |
| Cross-device | Resume rate via magic link (today: **0** — not possible) |
| Recovery | Abandoned-draft → completed after nudge |
| Admissions ops | Median time submit → decision; decisions per reviewer |
| Quality | % AI placement recommendations accepted by staff |

---

## 5 · Quick wins (ship now, low risk)

1. **Fix the `showFieldError` bug** (`apply.js:1128`) — replace with the existing `setError('payment')` + `#err-payment` pattern already used at `apply.js:300-302`. *Prevents a real `ReferenceError`.*
2. **Programmatic focus** on the first invalid field in `validateStep` (add `.focus()` alongside the existing `scrollIntoView`). *A11y + UX.*
3. **Surface a pending-payment state** when verify returns a transient error, instead of unconditional optimistic success — show "confirming…" with the reference and a "check status" link (lands on the A1 portal once it exists).
4. **Add the first analytics events** (step view/complete, submit, pay) behind a first-party endpoint — unblocks measuring everything else.

(1) and (2) are a few lines each and independently testable.

---

## 6 · Risks & sequencing

| Risk | Mitigation |
|------|------------|
| Server drafts add PII surface | Minimise fields stored; encrypt at rest if needed; honour export/delete; short TTL + purge on completion |
| Magic-link abuse | Signed, single-use, short-expiry tokens (the codebase already uses HMAC tokens for unsubscribe/reset) |
| Pipeline scope creep | Model it exactly like the shipped LMS lifecycle (state machine + audit) — proven, small, testable |
| Analytics ≠ surveillance | First-party only, no third-party pixels, respect DNT; aggregate reporting |

**Sequence:** quick wins (§5) → **A1** (drafts + status portal — biggest conversion lever) → **A2** (pipeline) → **A3** (analytics/a11y) → **A4** (AI), each shippable alone.

---

*The apply flow is already well-built; the gap is that it ends at "submitted" and lives only on one device. Closing that — server drafts, a status portal, an admissions pipeline, and the abandoned-draft nudge — is where the conversion and the enterprise-grade admissions experience come from. All of it reuses the outbox, automation cron, and AI gateway already proposed for the LMS.*
