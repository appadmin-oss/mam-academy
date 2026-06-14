# MAM Academy — Portals Upgrade Proposal

**Status:** draft for review · **Author:** engineering · **Date:** 2026-06-14
**Scope:** upgrade the three portals — **student**, **instructor**, and **admin** — into one coherent, accessible, real-time, AI-assisted experience, without destabilising the working dashboards. Additive and phased, riding the approved LMS (L1/L2 + automation + AI), Application, and Studio work.

> Grounded in `portal/student/index.html`, `portal/instructor/index.html`, `admin/index.php`, `assets/js/config.js` (`MAM_AUTH`/`MAM_API`), and the v16–v19 LMS backbone.

---

## 0 · TL;DR

All three portals are feature-rich and genuinely usable today. The upgrade is about **coherence, not rescue**. Five things hold them back:

1. **The two-systems split is visible to users.** The student portal shows *both* a **Classes** tab (legacy `enrollments`/attendance/announcements) *and* a **My Courses** tab (v16 `course_enrollments`/gradebook/results/certificates); the instructor portal runs attendance/students/announcements on legacy classes but the Gradebook on v16. Students and teachers see two parallel worlds for one reality.
2. **Three different architectures.** Student + instructor are vanilla-JS SPAs (render functions + `MAM_API`); admin is server-rendered PHP (`?v=` views). The same patterns (tab switching, panels, KPI rows, `escapeHtml`, empty states) are reimplemented per portal — no shared foundation.
3. **No notifications or real-time.** Only a single assignment badge; nothing aggregates "what changed."
4. **Accessibility, mobile, and performance gaps.** Tabs are `<a data-tab>` toggles (not ARIA tab widgets); long lists are unpaginated; loading is bare "Loading…" text.
5. **No personalization/AI surfaces yet** — the practice coach, skill profile, progress narratives, and grading assist (from the provider-agnostic AI layer) have nowhere to live.

The upgrade: **unify the IA around cohorts, build one shared portal foundation, add notifications + AI surfaces, and harden a11y/mobile/perf** — in additive phases sequenced with L1/L2.

---

## 1 · Current state (grounded inventory)

**Student portal** (`portal/student/index.html`, vanilla-JS SPA) — 13 tabs:
Overview · My Applications · **Classes** *(legacy)* · **My Courses** *(v16: results, certificates, add-course)* · Assignments *(badge)* · Attendance · Announcements *(+ resources)* · Practice *(KPIs, log, progress)* · Repertoire · Payments · Store Orders · MAM Auditer · Profile *(+ temp-password banner)*.

**Instructor portal** (`portal/instructor/index.html`, vanilla-JS SPA) — 8 tabs:
Overview *(KPIs, attendance breakdown, class fill)* · My Classes · Students *(roster + attendance history)* · Assignments · Attendance · **Gradebook** *(v16 course students, pass mark, grading)* · Announcements *(+ resources)* · Account.

**Admin** (`admin/index.php`, server-rendered) — 16 `?v=` views:
Overview · Applications · Payments · Users · Classes · **Enrollments** *(legacy)* · **Course Registrations** *(v16 + the new cohorts panel)* · Course Modules · Store/Library · Store Orders · Messages · Webhook Events · Newsletter · Pricing & Settings · Audit Log · My Account.

**Foundations already in place:** `MAM_AUTH` signed-in state + `MAM_API` (CSRF, fetch); a temp-password forced-change flow; kid-friendly empty-state copy for under-16s; per-course results breakdown; the v18 enrolment lifecycle and v19 cohorts underneath.

---

## 2 · Gaps & weaknesses

1. **Duplicated reality in the UI** — legacy "Classes" vs v16 "My Courses" (student), legacy attendance vs v16 gradebook (instructor), `Enrollments` vs `Course Registrations` (admin). Confusing; doubles maintenance.
2. **No shared front-end foundation** — three implementations of tabs/panels/KPIs/`escapeHtml`/empty-states. A change to one portal doesn't benefit the others.
3. **Accessibility** — `data-tab` anchors aren't an ARIA tab widget (no `role=tablist/tab/tabpanel`, `aria-selected`, arrow-key navigation); focus isn't managed on tab change; contrast/reduced-motion unverified against **WCAG 2.2 AA**.
4. **Mobile** — sidebar-of-links nav and wide tables need a responsive pass (drawer/bottom-nav; tables that reflow).
5. **Performance** — portals fetch everything up front; long lists (`LIMIT 300/400`) are unpaginated; no skeleton/optimistic states.
6. **No notification center / real-time** — only the assignment badge; no aggregation, no live updates, no per-user "what's new."
7. **No AI/personalization surfaces** — nowhere yet for the skill profile, practice coach, progress narrative (student) or grading assist (instructor).
8. **Auth/security depth** — no visible session management, no optional 2FA for staff, no magic-link login; recovery is functional but minimal.
9. **Student IA is broad** (13 tabs) — ripe for grouping once the learning views unify.

---

## 3 · Target upgrades (phased, additive)

### P1 · One shared portal foundation
- Extract a small **shared portal kit** (`assets/js/portal-kit.js` + `assets/css/portal.css`): the tab/shell controller, panel/KPI/table/empty/skeleton components, `escapeHtml`, and `MAM_API` conventions — adopted by both SPAs and mirrored as PHP partials/design-tokens in admin.
- Ship it as an **ARIA tab widget** (`role=tablist/tab/tabpanel`, `aria-selected`, arrow-key + Home/End nav, managed focus) and **responsive** shell (drawer nav on mobile) — so a11y/mobile are fixed once, for all three.
- *Low-risk:* refactor toward shared assets behind the existing markup; no behavioural change.

### P2 · Unify the learning view around cohorts *(rides LMS L1/L2)*
- Collapse the student's **Classes + My Courses** into a single **"My Learning"** view keyed on the cohort (schedule/attendance/announcements *and* gradebook/results/certificate in one place). Likewise an instructor **"Teaching"** view per cohort.
- Dual-read during migration (show the unified view behind a flag, keep the legacy tabs until parity is verified), then retire the duplicates — the same discipline used for the cohort backfill.
- Admin: fold `Enrollments` + `Course Registrations` into one cohort-centric registrations view (the cohorts panel is the seed).

### P3 · Notifications + real-time *(rides the L3 automation spine)*
- A **notification center** in every portal, fed by the `events` table (assignment set, graded, attendance marked, decision made, payment confirmed). Generalises today's lone assignment badge into one per-user inbox, with read state.
- Delivery via the existing **outbox** (email) now; in-portal live updates (poll first, push later) as a fast follow.

### P4 · Personalization & AI surfaces *(rides the provider-agnostic AI gateway — free by default)*
- **Student:** a skill-profile card (rhythm/intonation/etc. from `results` + Studio telemetry), an **adaptive practice coach** ("what to practise next"), and an auto-drafted **progress narrative** on the term/results view.
- **Instructor:** **grading assist** in the Gradebook — a rubric-aligned feedback draft the instructor edits and signs (never auto-final).
- All via `api/lib/ai.php`, defaulting to the **free/local adapter** so these cost nothing to run.

### P5 · Admin modernization + auth/security
- **Management surfaces the data now supports:** cohort create/edit (not just the read panel), session scheduling (L2), the **admissions pipeline** (Application proposal), and AI-assist panels — all on shared design tokens so admin stops looking like a different product.
- **Auth/security:** session management (see/revoke active sessions), **optional 2FA for staff** (instructor/admin), magic-link login, and a polished recovery flow. Role-aware routing throughout.

---

## 4 · Success metrics

| Dimension | Metric |
|-----------|--------|
| Engagement | Portal WAU/MAU; tabs used per session; return rate |
| Clarity | Support questions about "where is my class/course"; task-completion rate |
| Instructor efficiency | Median time to grade a student / mark attendance |
| Accessibility | Automated + manual WCAG 2.2 AA pass; keyboard-only task completion |
| Performance | Time-to-interactive; list render time on large rosters |
| AI value | Practice-coach engagement; grading-assist acceptance rate |

---

## 5 · Quick wins (ship now, low risk)

1. **ARIA tab semantics + keyboard nav** on the existing `data-tab` controllers (both SPAs) — immediate a11y lift, no redesign.
2. **Skeleton loaders** in place of bare "Loading…" text (shared helper).
3. **De-duplicate the SPA helpers** (`escapeHtml`, tab switch, empty-state) into one `portal-kit.js` consumed by both portals.
4. **Pagination** on the longest lists (attendance, applications, roster).

---

## 6 · Risks & sequencing

| Risk | Mitigation |
|------|------------|
| Refactor regresses a working SPA | Extract shared kit behind current markup; portal-by-portal; no behaviour change in P1 |
| Unified learning view loses legacy data | Dual-read + parity check before retiring legacy tabs (rides L1/L2) |
| Admin rewrite risk | Keep server-rendered robustness; adopt shared *tokens*, add *new* surfaces — don't rip-and-replace |
| 2FA/session changes lock people out | Opt-in for staff first; recovery path tested before enforcement |

**Sequence:** **P1** (shared foundation + a11y/mobile — benefits everything) → **P2** (unify learning view, with L1/L2) → **P3** (notifications, with L3) → **P4** (AI surfaces, with the AI gateway) → **P5** (admin + auth). Each phase ships independently and test-covered.

---

*The portals don't need rescuing — they need **converging**. One shared foundation, one learning view (cohort-centric), one notification center, and AI surfaces that default to free — built additively on the LMS, Application, and Studio work already approved.*
