# Next agent — MAM Academy: what is open, and what I would do next

Kept current as work lands. Read `CLAUDE.md` first (conventions, gotchas), then
`docs/HANDOFF.md` §4w–§4z3 for what was built in November 2026 and why. This file
is the to-do list; HANDOFF is the history.

Branch: `afv/mam-audit` (never a `claude/` branch — owner's rule). Suite:
`php api/tests/run.php` must stay green. Schema target: see `MAM_SCHEMA_TARGET` in
`api/lib/db.php` (102 at the time of writing).

---

## 1. Waiting on the owner (do not start without them)

| # | What | Why it waits |
|---|------|--------------|
| 1.1 | **Admissions / application forms** (`/apply`, summer, scholarship, store checkout) | The owner is sending a design. Known defects to fix *inside* that rework (proven, not yet fixed): the `/apply` headshot rejection is not mapped in `SERVER_FIELDS`, so no error shows; `summer.js` maps `rate_limit` but the server returns `rate_limited`; the summer signature rule for minors is server-only; scholarship never sends `application_reference`; store `notes` is never sent and `promo_pct`/the discount are not saved on the order; `post_max_size=16M` is below the 24 MB take limit (the new media store side-steps this for anything using `/api/media/*`). |
| 1.2 | **Admissions & enrolment restructure on NGG's model** | Mapped earlier, not started; needs the owner's agreement on which NGG flow to copy. |
| 1.3 | **Keys** | R2 bucket (Console → System health → File storage, plus the CORS rule shown there), JaaS (App ID, key ID, private key, webhook secret; point the webhook at `/api/hooks/jaas`). Until then files stay on the host (64 MB cap) and classes fall back to a plain Jitsi link. |
| 1.4 | **Name the safeguarding officer** | Console → Staff & vetting. Until then every super admin receives every concern. |
| 1.5 | **Data-protection register** | R2 and JaaS (8x8) are new processors with no African region — add both with their transfer basis on Console → Data protection. |
| 1.6 | **Public community** | The owner wants class spaces to open to the public "eventually". Must be a SEPARATE surface with its own consent (media consent is for the class, not the internet). Nothing in `spaces.php` may be shown outside a space. |

## 2. Open work I would do next (in order)

1. **NGG's safeguarding features not yet in MAM** — in progress; see §4 below, updated as each lands.
2. **Rebuild the 86 admin actions that lost their button** when the legacy dashboard was retired (list: HANDOFF §4y). Highest value first: refunds and bank-payment confirmation, instructor earnings, settings/programmes/term dates, data-protection register edits and erasure, creating users/admin tiers, recitals, rubrics, the faculty pipeline. The handlers all still exist in `admin/index.php`; each rebuilt screen only needs a form posting to the existing action.
3. **A console screen for class spaces** — admins can already open any space from the portal, but there is no console list of spaces, held items across all spaces, or reports. Read `mam_space_queue()`; it already does the work for a teacher.
4. **NGG storage** — the user asked for the bucket work to be broadened; NGG still uses a PUBLIC bucket (`api/_lib/object-store.php`) and has no video compression. Port `assets/js/media-upload.js` and switch children's media to private + presigned GET, as MAM now does.
5. **Live classes: admit from the lobby on the instructor portal** and show who is waiting; today the teacher uses JaaS's own lobby UI.
6. **Growth: a guardian monthly email** built from `mam_growth_summary()` (the sentences are already written for a family).
7. **Adaptive: expose per-question difficulty stats to authors** (`check_calibration`: answered, correct, learnt b) on the lesson editor, so a question everybody gets wrong is visible.
8. **Run the MySQL/MariaDB parity suite** for v99–v102 (`DB_DSN=… php api/tests/run.php` on a fresh DB with `mysql --force`). Not yet run for these four migrations.
9. **HawkScan / DAST** — the post-commit hook asks for it but `HAWK_API_KEY` is not set here.

## 3. Things I would reconsider (suggestions, not bugs)

- **Early-payment discount — deliberately not built.** It reads as a reward but is a
  penalty in disguise for families who cannot pay early (often the poorer ones), it
  fights the instalment plans just shipped (does instalment 1 "count" as early?), and
  it changes the amount owed after issue, which makes the frozen-rate invoice
  disagree with itself. If the owner wants an incentive, issue a dated discount CODE
  (`fee_discount_codes.ends_on`) to everybody — same effect, no new mechanism, and it
  is visible on the invoice as a waiver with a reason.
- **Two-person invoice review** suits an office of two or more. A one-person office
  should set `fee_review_two_person = 0` (the review still happens, by the same
  person) rather than switching review off. Consider a console toggle — today it is a
  setting with no screen.
- **Ability estimate (θ)** uses a simple online Rasch/Elo update. Good enough to pick
  questions; not good enough to grade anybody. Keep it out of reports and marks.
- **Heartbeat-based attendance** for non-JaaS classes measures "page open", not
  "in the call". The UI says so; keep it labelled.

## 4. NGG safeguarding port — status

(Updated as each item lands.)

- [ ] Inventory of NGG's safeguarding features — running.
