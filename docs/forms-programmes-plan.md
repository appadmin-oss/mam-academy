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

## 3. Application Form rebuild (`apply.html` + `apply.js`)

New linear, logic-driven flow — each step's options derive from the previous:

```
Mode → Level → Programme → Expression → Discipline → Course → (Profile → Review → Pay)
```

1. **Mode** — onsite | online. (Drives backend price only; no fee shown.)
2. **Level** — Pre-Certification | Certification I | Pre-Diploma | Diploma.
3. **Programme** — Crash | Standard | Certified | Grand (low→high).
4. **Expression** — Classical | Contemporary | African.
   - **Skipped entirely for Grand** (all three applied silently).
   - Crash/Standard/Certified: exactly one.
5. **Discipline** — programme focus (→ proposed: this becomes the *primary
   course*; needs catalog, §9).
6. **Course** — selection rules per programme (§6). Compulsory courses
   pre-checked & locked for Certified/Grand.

Implementation notes:
- Replace the current `pathway/mode/discipline` radio model with a step machine
  driven by a `PROGRAMME_RULES` config object (mirrors the server rules).
- **Session**: never rendered. On load, `GET /api/session/current` returns the
  active `{code, year}`; stored in `data.session` and submitted; server
  re-resolves authoritatively (never trusts client).
- Deep-link prefill (`?programme=…`) still supported, validated against rules.
- Live summary shows only the **final price** (post-adjustment), itemised by
  course at the adjusted rate — no mode line.

---

## 4. Sessions engine (`api/lib/academic-session.php`) — ✅ DONE (Phase 0)

> Named `academic-session.php` to avoid colliding with the existing auth
> `api/lib/session.php`. Shipped with no DB migration — admin overrides ride on
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

## 5. Pricing engine (`api/lib/booking-pricing.php` / `mam_prices`)

Course-based, computed server-side:

```
total = primary_price
      + 0.5 * primary_price * (num_compulsory + num_additional)
online: total *= online_factor   (backend only; default 1.20; Crash exempt)
+ $5 registration (one-time)
```

- **Crash / Standard**: 1 course, 0 compulsory → total = primary_price
  (Crash: no online factor; Standard: online factor applies).
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

## 9. OPEN DATA — needed before §3/§5/§6 code

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
2. **Phase 1** — Pricing engine (§5) + per-programme rules (§6) — needs §9.
3. **Phase 2** — Application Form rebuild (§3).
4. **Phase 3** — Sessions admin + Promotions (§7).
5. **Phase 4** — One-Time Programme builder (§8).

Each phase: DB migration (if any) → server → client → runtime smoke (`/api/health`,
authed admin views, form submit) → commit.
