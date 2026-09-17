# MAM Academy — design package for Claude Code

**Date:** 19 Aug 2026 · **Base currency:** NGN · **Schema:** v2

## What's in here

| Path | What it is |
|---|---|
| `HANDOFF-student-guardian.md` | **Start here.** Tokens, component recipes, endpoint shapes, build order, and every deliberate refusal with its reason |
| `Portals Desktop.dc.html` | Student, guardian **and instructor** desktop, 1440. Open in a browser, click the left rail |
| `Portals Mobile.dc.html` | Student, guardian and instructor mobile, 390 — **the primary experience** |
| `Admin Console.dc.html` | Staff console, 1440 + 390. 18 destinations, 6 drawers, shared component reference |
| `mam-logo-mark.png` | Emblem for small slots |
| `mam-logo.png` | Full lockup, for sizes above ~80px |

All three HTML files are self-contained (fonts and icons from CDN) and need no build step to view.

## Vocabulary — get this right first

- **Session** = what most schools call a term. ~14 weeks, four a year, named **TONI / MEDI / SUMMER / DOMI**, renameable in Settings.
- **Cohort** = a group of learners. A **private class is a cohort with capacity 1** — one code path for registers, credits and reports.
- **Class** = one meeting. The schema calls this `class_sessions`; never surface that name to users.
- Capacity `NULL` = uncapped. `0` would read as permanently full, so the field refuses it.

## The seven refusals

These are load-bearing. Each exists because the research in the repo's `docs/` says the obvious alternative harms someone. If a ticket asks for one to be removed, cite the handoff.

1. No automatic pitch/rhythm scoring of student audio
2. No cohort leaderboard or effort board — the personal streak stays, and nobody else sees it
3. Teach-back is formative only, three self-checks, never in the mastery band
4. No at-risk flag visible to student or guardian — staff-side only
5. No guardian scope reaches practice logs, teach-back recordings, or a teenager's messages
6. No optimistic write states — "saved on this phone, will upload" is the honest one
7. No access hold on a child's academic record, safeguarding file or report over money

## Order of work

The handoff has the detailed sequence. In short: tokens and shared components → student **Train** (practice room first, wiring the existing `practice-player.js` and `take-recorder.js`) → offline queue and honest write states → teach-back → spaced retrieval → mastery band with coverage floor → guardian overview, ratified reports, fees → access scopes **server-side**.

Ahead of all of it, per `docs/v2-cutover-readiness-2026-08-18.md`: vetting gates, NDPA consent records, the academic calendar, and minor-unit money with pinned FX. These designs assume those exist.

## Known gaps in this package

- Every figure in every screen is placeholder: fees, cohort counts, years teaching, attendance.
- Photographs are labelled placeholders sized to their real slots.
