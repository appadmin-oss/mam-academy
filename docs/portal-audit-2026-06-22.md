# MAM Academy — Portal Audit (logical · structural · UI/UX)

**Date:** 2026-06-22 · **Scope:** student portal, instructor portal, the auth
pages (login/signup/reset) and the shared `assets/js/portal.js`, plus the
backend dashboard contracts they consume.

**How it was tested:** the app was run locally (PHP built-in server + a dev
router emulating the production `.htaccess`), a realistic dataset was seeded
(admin + instructor + student, a class, an enrolment, attendance history,
assignments in mixed states, notifications), and each portal was driven with a
headless browser (Playwright/Chromium) — logging in as all three roles and
screenshotting desktop (1366px) + mobile (390px), then each changed tab.

**Result of the run:** all three roles log in and render correctly; **zero
runtime JS errors** in either portal (the only console noise is external CDN
resources — Font Awesome, the currency API — which the offline sandbox blocks;
not app bugs). The findings below are what the run + a source read surfaced
*beyond* a clean render.

Status key: ✅ fixed this pass · 📝 documented (feature/refactor — recommended, not done).
Severity: 🔴 bug · 🟡 should-fix · 🔵 polish.

---

## Logical

| ID | Sev | Status | Finding & fix |
|----|-----|--------|---------------|
| L1 | 🔴 | ✅ | **Attendance % disagreed between the overview bar and the Attendance tab.** The dashboard counted only `present` (`student/dashboard.php`) while the client counts `present`+`late` everywhere. Server SQL now `IN ('present','late')` — one consistent definition of "attended". |
| L4 | 🟡 | ✅ | **`data-variant` never cleared.** `setAttribute('data-variant','')` left an empty-but-present attribute; now `removeAttribute` for non-KTAMS students so `body:not([data-variant])` selectors match. |
| L5 | 🟡 | ✅ | **Timezone roll-over on "today".** Practice/attendance date inputs and the CSV filename used `toISOString()` (UTC), pre-filling *tomorrow* for evening users in WAT (the academy's own timezone). New shared `P.today()` builds the date in local time; all four call sites updated. |
| L2 | 🟡 | 📝 | **Attendance is double-sourced** (legacy `attendance` vs LMS session-attendance) and the KPI/rate derive only from the legacy table's latest 10 rows (`LIMIT 10`) while presenting it as the all-time record. Recommend deriving the rate from the full `attendance_*` counts the server already computes and folding in session-attendance. |
| L7 | 🔵 | 📝 | Submission "latest wins" trusts server array order rather than comparing `created_at`. Harden the dedupe if `student/submissions` ordering ever changes. |

## Structural

| ID | Sev | Status | Finding & fix |
|----|-----|--------|---------------|
| S1 | 🔴 | ✅ | **`auditer_results` is a write-orphaned table** → the student "MAM Auditer" tab could *only ever* show an empty state. The dashboard now also returns live placement validity from `mam_auditer_status()` and the tab renders it (student-exempt / valid-until / expired + a "Book the Auditer" CTA), so it's no longer dead. The detailed-report read is kept for when a capture flow exists. |
| S2 | 🟡 | 📝 | **Two enrolment models surfaced side-by-side**: the student "Classes" + instructor roster/attendance use legacy `enrollments`+`classes`, while "My Courses"/Gradebook/Sessions use `course_enrollments`+cohorts. A student/instructor can be "enrolled" in one and absent from the other. Recommend unifying on one model (or explicit copy distinguishing "private class" vs "course registration"). Larger refactor — left as a deliberate decision. |
| S3 | 🟡 | 📝 | **Copy-paste divergence**: both SPAs re-declare their own `kpi()`/`empty()` instead of the shared `P.*` kit, and `P.skeleton` is defined but unused. Recommend routing both portals through the shared builders. |

## UI / UX

| ID | Sev | Status | Finding & fix |
|----|-----|--------|---------------|
| UX1 | 🔴 | ✅ | **An enrolled student was told "No applications yet — start one."** The overview now shows a neutral "No applications on file." once the student has any class or course enrolment (no misplaced nudge). |
| UX3 | 🟡 | ✅ | **Raw DB timestamps** (`2026-06-22 14:00:00`) shown in instructor Sessions and student session-attendance. Both now go through `P.fmtDateTime`. |
| UX4 | 🟡 | ✅ | **Bulk-mark used native `confirm()`** despite a styled dialog kit existing; now routed through `MAM_DIALOG.confirm` (with a native fallback). |
| UX7 | 🔵 | ✅ | **Attendance student-picker had no AT semantics.** Buttons now carry `aria-pressed` (toggled on select) and the "Selected: …" label is `aria-live="polite"`. |
| UX8 | 🔵 | ✅ | **`reset.html` deep-links to `/portal/login#forgot`** but login ignored the hash. Login now opens the forgot-password panel when `#forgot` is present. |
| UX2 | 🟡 | 📝 | **Instructors can create and delete assignments but not edit/close them** — fixing a typo or due date means deleting (which discards the student's submissions tied to that id). Recommend an `instructor/assignment-update` endpoint + an edit affordance and a non-destructive "close" state. |
| UX5 | 🔵 | 📝 | Tuition "pay now" desync uses `alert()` + full `location.reload()`; recommend an inline "verifying…/pending" pill with polling. |
| UX6 | 🔵 | 📝 | A couple of raw `<table>`s (Sessions / mark-attendance / gradebook) lack the `.ptable-wrap` scroll wrapper and use fixed-px field widths — can overflow narrow phones. Wrap them and prefer `min-width`. |

---

## What was verified clean
- Login → redirect works for student, instructor and admin; sessions persist via the HttpOnly cookie.
- No runtime JS errors on any portal page or tab.
- Responsive: KPI cards stack and data-tables reflow to label/value rows at 390px in both portals.
- Notification centre, attendance breakdown, class-fill, gradebook and recent-attendance render with seeded data.

## Follow-ups worth scheduling (the 📝 items)
1. **UX2** — instructor assignment edit/close endpoint (highest user value).
2. **S2/L2** — converge the two enrolment/attendance models (removes a whole class of "enrolled-but-missing" confusion).
3. **S3** — fold both SPAs onto the shared `portal.js` render kit.
