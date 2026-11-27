# Forms, Programmes, Sessions & Promotions — Implementation Plan

Status: **PLAN — no code yet.** Awaiting confirmation of the open data points
in §9 before pricing/form phases begin.

Decisions locked (from review):
- **Grand Musician can be online or onsite** (backend price adjustment for
  online). The onsite-only lock added earlier will be removed.
- **Mode-based pricing is never shown publicly.** The applicant sees only the
  final adjusted price. All "+20% / onsite standard" copy added earlier will be
  removed from pathways, policies, compare and apply. The adjustment stays
  100% backend.

---

## 1. Principles

- **Backend is the source of truth** for every price; the client never computes
  or is trusted for totals (already the case in `application.php`).
- **Session is silent**: resolved server-side from the academic calendar and
  stamped onto the application; never a form step, never user-editable.
- **Recurring programme pages are permanent** (pathways, crash, metamusic-order,
  etc.) — one-time programmes never delete or override them.
- **No build step / vanilla JS + PHP** architecture is preserved.

---

## 2. Data model

New / changed tables (SQLite dev + `db/schema-mysql.sql` prod, via the existing
migration mechanism; bump `schemaTarget`).

### 2.1 `sessions`
| col | type | notes |
|---|---|---|
| id | pk | |
| code | text | TONI \| MEDI \| SUMMER \| DOMI |
| year | int | academic year |
| starts_on | date NULL | admin override; NULL → default logic |
| ends_on | date NULL | admin override |
| promo_lead_days | int | default 14 (admin-adjustable) |

If no row for the active window, the **default-date engine** (§4) computes it.

### 2.2 `courses` (catalog)
| col | type | notes |
|---|---|---|
| id | pk | |
| code | text | e.g. rhythm, metamusic, theory, ape, + majors |
| name | text | "Rhythm Intelligence", … |
| kind | text | `major` \| `compulsory` \| `elective` |
| expression | text NULL | classical/contemporary/african (majors) or NULL |
| active | bool | |

Compulsory set = {rhythm, metamusic, theory, ape}. **Pricing is relative to the
chosen primary course**, so `courses` does NOT need per-course prices (see §5).

### 2.3 `programmes` (recurring config — drives form + pricing)
Seed rows: `crash`, `standard`, `certified`, `grand`. Columns capture the rules:
weeks, hours_per_week, max_days, allow_expression (bool), expression_count
(1 or 3), compulsory (bool), online_adjust (bool), primary_price_usd (or a
`level_price` join — see §9).

### 2.4 `one_time_programmes`
| col | type | notes |
|---|---|---|
| id | pk | |
| slug | text uniq | **custom public URL** (admin-set), e.g. `power-play-aug` |
| title, blurb, hero_img | | for the auto-generated page |
| location, event_time, course | text | free-form admin-defined |
| external_form_url | text NULL | if set, applicants redirect here |
| promote | bool | home-page promotion toggle |
| starts_on, ends_on | date | |
| status | text | draft \| live \| ended |

### 2.5 `one_time_fields` (custom form fields)
`id, programme_id, label, type (text/email/tel/select/checkbox/textarea),
options_json, required, sort`.

### 2.6 `applications` — new/added columns
`mode, level, programme, expressions_json, discipline, primary_course,
additional_courses_json, session_code, session_year`. Keep `pathway` as a
compatibility alias mapped from `programme`.

---

## 3. Application Form rebuild (`apply.html` + `apply.js`) — ✅ DONE (Phase 2)

Shipped: Step-1 substeps reordered to Mode → Programme → Level → Expression →
Focus → Course → Add-courses, all inside the existing 5-step top stepper (so
deep-links, server-field mapping and submit-on-review stayed intact). Level &
Expression substeps render conditionally (hidden + cleared for Crash/Meta and
Grand respectively); visible substeps renumber dynamically. Minors render from a
catalog excluding the chosen major; Grand discount surfaced (badge + summary
line). Session is fetched from `/api/session/current`, shown read-only, and
re-resolved server-side at submit. Client pricing mirrors the engine; the server
recomputes authoritatively. Verified end-to-end: Grand+diploma+2-minors online =
$756.68, Crash = $75 flat, Standard-missing-level rejected. Backend: validation
extended (level/expression/extraCourses/coursesLabel, session optional),
`application.php` switched to `mam_compute_application_price()` +
`mam_session_current()`, DB migration v13 adds level/expression/extra_courses/
courses_label.

### Original plan (for reference)

New linear, logic-driven flow — each step's options derive from the previous:

```
Mode → Level → Programme → Expression → Discipline → Course → (Profile → Review → Pay)
```

Confirmed taxonomy (from review):
- **Level** — Pre-Certificate · Certificate · Pre-Diploma · Diploma. Drives price.
- **Programme** — Crash · Standard · Certified · Grand (low→high).
- **Expression** — Classical / Contemporary / African. **Conditionally rendered**
  (removed from the DOM, not just hidden) and the stepper renumbers 6→5 for
  **Grand** (all three auto-applied).
- **Discipline = a separate focus area** (Songwriting, Production, Music
  Education, Direction, Orchestration, Arranging, Film Scoring, Conducting,
  Music Business…) — the current `program` dropdown minus the expression
  entries and minus "Music Theory". It is metadata, NOT the priced course.
- **Course** — pick **one major** (primary course, full price) **+ optional
  minors** (each 50% of base). Catalog: Theory, MetaMusic, APE, Rhythm,
  Instruments (current list) + a free-text "Other". For Certified/Grand the 4
  compulsory (Theory/MetaMusic/APE/Rhythm) render in a locked "Included in your
  programme" group; minors live under "Add more courses (50% each)".
- **Session** — never a step; resolved from `/api/session/current` and shown
  read-only in the summary as a timeline (§8.1/§8.2).
- **Grand 13% discount** surfaced (strikethrough + "Save 13%"/"You save $X").

Implementation surface: rebuild Step-1 substeps in `apply.html`; rework
`apply.js` (data model, conditional Expression + stepper renumber, level-driven
price cards, course grid with locked compulsory + major + minors, discount line,
silent session fetch, submit payload, deep-links, XSS allowlist for new fields);
add `apply.css` for the locked-course group; backend: extend
`mam_validate_application` (level, programme incl. `standard`, expression(s),
discipline, major, minors[]; session optional → server-resolved), switch
`application.php` to `mam_compute_application_price()` + `mam_session_current()`,
a small DB migration for the new columns (level, expressions, primary_course,
extra_courses), and confirmation/invoice emails showing only the final total.

---

## 4. Sessions engine (`api/modules/academics/lib/academic-session.php`) — ✅ DONE (Phase 0)

> Named `academic-session.php` to avoid colliding with the existing auth
> `api/modules/kernel/lib/session.php`. Shipped with no DB migration — admin overrides ride on
> the existing key/value settings store (`academic_session_overrides`).

`mam_session_current(?DateTimeInterface $now = null, ?array $overrides = null): array`
→ `{code, label, year, starts_on, ends_on, status, source, next:{…}}`. Endpoint
`GET /api/session/current` is live. Verified against a year of dates + the live
bootstrap.

Default fallback (when no admin dates set):
- **TONI**: first Monday ≥ 2 weeks after Jan 1 → 12 weeks + 1 week break.
- **MEDI**: right after TONI break → 12 weeks + 1 week break.
- **Summer**: right after MEDI break → 8 weeks + 1 week break.
- **DOMI**: right after Summer break → 12 weeks.

Admin dates (`sessions` table) take precedence. Endpoint `GET /api/session/current`
(public, cached). Used by the form and anywhere "current session" is shown.

---

## 5. Pricing engine — ✅ IMPLEMENTED (`mam_compute_application_price`)

Level-based, computed server-side (B = level base price):

```
crash              → flat $70   (price_crash; NO levels, online exempt)
standard           → B
certified-musician → 2B + 0.5B·extra
grand-musician     → (3B + 0.5B·extra) · (1 − 13%)
online: subtotal *= (1 + surcharge%)  (backend only, silent; Crash exempt)
+ $5 registration (one-time)
```

### Currency / FX — ✅ live rate API (`api/modules/integrations/lib/fx.php`)
USD→NGN is fetched live from a key-less provider (`open.er-api.com`, override
via `FX_RATE_URL`), cached ~6h in `settings`, sanity-bounded, and falls back to
the admin `usd_to_ngn` rate when off (`fx_auto=0`) or unreachable — so a charge
never fails on an FX outage. Used by payment-init, booking tier conversion, and
the public config endpoint (read-only there, never blocks a page load).

- **Crash**: flat $70, no levels, 1 course, no online factor.
- **Standard**: 1 course, 0 compulsory → total = B (online factor applies).
- **Certified**: primary + 4 compulsory (each 50%) [+ optional additionals @50%]
  → 3× primary baseline.
- **Grand**: primary + 2 other majors (50%) + 4 compulsory (50%) [+ optionals]
  → 4× primary baseline; online factor applies.

> Observation: 3×primary ≈ \$230 and 4×primary ≈ \$310 ⇒ **primary ≈ \$77**.
> This needs confirming (§9) — exact base, and whether it varies by level.

The named flat prices ($230/$310) are replaced by computed totals; the public
pages display the computed final figure.

---

## 6. Per-programme course rules (client + server, single shared rule set)

| Programme | Weeks | hrs/wk | Expression | Compulsory | Course selection |
|---|---|---|---|---|---|
| Crash | 6 | 1 | one | none | exactly 1 course |
| Standard | 12 | 1 | one | none | exactly 1 course |
| Certified | 12 | 3 | one | 4 (auto+locked) | 1 primary + N optional (@50%) |
| Grand | 12 | 6 (≤2 days) | all 3 (auto) | 4 (auto+locked) | 1 primary major + 2 majors + N optional (@50%) |

Server validates the submitted selection against these rules and **recomputes
price**; mismatch → 422.

---

## 7. Promotions

- **Recurring**: site auto-promotes the upcoming session's programmes starting
  `promo_lead_days` (default 14) before `starts_on`. A home-page promo slot
  reads `mam_session_current()`/next + lead setting.
- **One-time**: promoted on the home page **immediately on creation** (while
  `status=live` & `promote=1`), rendered into a dedicated home promo region.
- Admin controls lead time per session and the promote toggle per one-time.

---

## 8. One-Time Programme builder (admin) — largest phase

- **Admin CRUD** (`admin/index.php` new `v=onetime`): create/edit/delete,
  set custom **slug** (public URL), title/blurb/hero, location/time/course,
  promote toggle, external-form URL, and **custom fields** (repeatable rows).
- **Auto-generation on save**:
  - Public **page** at `/<slug>` — server route renders a template from the
    row (no static file needed); recurring pages are untouched.
  - Public **form** at `/<slug>` (or `/<slug>/apply`) built from
    `one_time_fields`; or **302 redirect** to `external_form_url` if set.
  - Submissions stored (own table) + admin notice email (reuse
    `mam_email_admin_notice`).
  - Home-page **promo** injected immediately.
- Routing: add `^([a-z0-9-]+)/?$ → router` lookup against `one_time_programmes.slug`
  (after existing static routes so real pages win).

---

## 9. OPEN DATA — ✅ RESOLVED (see spec + review answers)

Base prices per level (120/130/160/180), online adjustment kept (silent),
Grand 13% discount, Discipline = separate focus area, Course = one major +
minors. Pricing engine (Phase 1) implemented & tested against these.

### (original asks, for reference)

1. **Primary course price** (the base everything derives from). Is ~\$77 right,
   or give the exact figure? Does it **vary by Level** (Pre-Cert→Diploma) and/or
   by Programme?
2. **Online adjustment factor** — still +20%? (Crash exempt, confirmed.)
3. **Discipline catalog** — the list, and confirm Discipline = the *primary
   (major) course*. Current disciplines: piano, violin, guitar, drums, wind,
   voice, theory.
4. **Course catalog** — selectable electives beyond the 4 compulsory + majors.
5. **Standard programme price** — = primary price (1 course), correct?

---

## 10. Cleanups from the locked decisions (can start immediately)

- Remove **Grand onsite-only**: drop the client lock in `apply.js`, the server
  force in `application.php`, and the "onsite only" copy in pathways/compare/apply.
- Remove **public +20% / mode-fee copy**: pathways pricing note, policies
  "Mode of Learning" wording (state classification only, no fee), compare
  "Online surcharge" row, apply summary mode line. Keep the backend adjustment.

---

## 11. Suggested sequence

1. **Phase 0** — ✅ DONE. Cleanups (§10) + **Sessions engine** (§4).
2. **Phase 1** — ✅ DONE. Pricing engine (§5) + per-programme rules (§6),
   backend-only & tested; live charging still on legacy prices until Phase 2.
3. **Phase 2** — NEXT. Application Form rebuild (§3) — flips charging to the
   new engine. Also adds SEO structured data (done as a separate pass).
4. **Phase 3** — Sessions admin + Promotions (§7).
5. **Phase 4** — One-Time Programme builder (§8).

Each phase: DB migration (if any) → server → client → runtime smoke (`/api/health`,
authed admin views, form submit) → commit.
