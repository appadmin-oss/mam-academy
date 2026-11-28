# Classes audit: how a class works, and why the teacher–student link breaks

| | |
|---|---|
| **Owner** | Academy technical lead |
| **Version** | 1.0 — 2026-11-28 |
| **Method** | The real library code was run against a fresh SQLite database seeded with 4 instructors, 6 students, several cohorts and 2 sessions. Every fault below was reproduced, not inferred. A browser run of the portals confirmed the visible symptoms. |
| **Related** | `docs/restructure-plan-2026-11-28.md` §2.2 (teaching target) |

## 1. What a "class" is today

There is no single "class". Seven things together make one:

```
programs ─┐   courses ─┐   levels ─┐        academic_terms (Africa/Lagos)
          └────────────┴───────────┴─► cohorts ◄── cohorts.term is FREE TEXT (no link to a term)
                                        │  instructor_id   ◄─ the teacher, copy 1
                                        ├─► course_enrollments (student, status, instructor_id ◄─ copy 2, requested_instructor_id — never read)
                                        ├─► class_sessions (scheduled_at TEXT, mode, room, meeting_link, substitute_id ◄─ copy 3)
                                        │      └─► session_attendance
                                        ├─► lessons (cohort lessons) ─► lesson_progress
                                        └─► class_spaces ─► space_posts (marks live here, nowhere else)
instructor_assignments (instructor, course, level) — both "may teach" and "default teacher" ◄─ copy 4
legacy classes / enrollments / attendance — kept, unread
```

Live calls are made three different ways:
- `meeting-links.php`: Meet, then Zoom, then JaaS
- `live-classes.php`: JaaS
- `meetings.php`: a plain Jitsi link

**How scattered it is.** 114 files read or write the four core tables
(cohorts 80, enrolments 76, sessions 51, attendance 36). There are about **41
inline copies** of "does this teacher teach this student" across 19 files,
and **five different enrolment status sets** in SQL.

| | Number of code paths |
|---|---|
| Ways a class is created | 8, two of them on a plain page view |
| Ways a student is put into a class | 8 |
| Ways a teacher is attached | 7 |

## 2. The same class, seen by each teacher

Setup:
- A is the class's teacher.
- C was picked when one student's enrolment was approved.
- D is the cover teacher for one session.

| Teacher | Home: sessions | Register | Students | Class space | Live call | Owns the class (API) |
|---|---|---|---|---|---|---|
| A (lead) | sees session 1 | 2 students | both | teacher | teacher | yes |
| C (picked at approval) | **none** | **refused** | 1 student | teacher | teacher | **yes, even after that student withdrew** |
| D (cover) | **none** | **refused** | **none** | **no** | teacher | **no** |

A cover teacher can open the live call but cannot take the register they were
brought in for. A teacher who taught one withdrawn student keeps owning the
whole class.

**Since v111 (teaching model, `teaching.php`):** A is unchanged. C teaches only
the student whose enrolment names them, never owns the class, and loses even
that student on withdrawal. D sees, registers, clocks in and writes lesson notes
for the one session they cover, on its day, and nothing else of the class.
Pinned by `api/tests/test_teaching_model.php`.

## 3. Faults, most serious first

1. **Times are an hour apart.** The admin timetable saves the time as typed
   in Lagos. Check-in, instructor clock-in and live classes read it as UTC.
   The calendar feeds and meeting links read it as Lagos.
   - Symptom: a class starting in 20 minutes shows "starts in 79 minutes",
     and check-in is refused.
   - Status: **open, needs the decision in §5.**
2. **Instructor-made sessions used a different date format**
   (`2026-11-28T08:07`), so they dropped out of the day's check-in list and
   the clash check. **Fixed** (v110), and existing rows normalised. Production
   MySQL stores a real DATETIME and was mostly unaffected; SQLite installs
   were not.
3. **Opening an application in the console created an empty class.**
   **Fixed:** the enrolment preview is read-only, and the class is made only
   when an admin confirms. Class spaces are still created on view; that is
   open.
4. **The same course and level splits into several classes.**
   - Classes are keyed on course, level and programme, and KTAMS overrides
     the programme, so the same piano/pre-cert students land in two classes
     with the same title.
   - A year-round Crash enrolment can land in the SUMMER class, because the
     term is ignored.
   - Status: **open.**
5. **Changing the teacher leaves the old one with access.** The plain admin
   edit leaves the old teacher on each enrolment, and "owns the class" checks
   no status. **Fixed** (v111): every lead change goes through
   `mam_cohort_set_lead()`, which ends the old lead's row; they keep read-only
   history and lose current access.
6. **The teacher chosen at approval differs from the class's teacher.** The
   student's requested teacher is stored and never read. **Open.**
7. **The enrolment preview's seat count was always 1.** **Fixed.**
8. **Online and onsite don't match.**
   - The class's mode is never used for its sessions.
   - Check-in told onsite students they were "expected to be online".
   - Status: the **message is fixed**; inheriting the mode is open.
9. **Capacity counts pending students that nobody can see** (the seat rule
   uses active + pending, both portals show active + completed). **Open.**
10. **Marks made in a class space go nowhere else.** They never reach the
    Marking queue, the gradebook or the student's Learn screen. **Open**
    (part of the learning pipeline, restructure plan §2.4).

## 4. The target: one Teaching domain

```
Term ─► Cohort (term_id, course, level, programme, age band, mode, capacity; a generated, unique title)
          ├─► cohort_staff (lead | assistant | cover, starts_on, ends_on, optional single session for cover)
          ├─► Enrolment (ONE status set: pending, active, paused, completed, withdrawn — and ONE seat rule)
          └─► Session (starts_at in UTC, duration, mode inherited from the cohort and overridable, room or link)
                 └─► Attendance (mode copied from the session)
```

- **One predicate for teaching:** `mam_teaches($instructor, $cohort, $at)`,
  read only from `cohort_staff`. It covers lead, assistant and cover, with
  their dates, and includes the vetting check when the class has minors.
- **One predicate for enrolment:** `mam_enrolled_in($student, $cohort)`.
- **Each predicate replaces every copy:** about 41 for teaching, and the
  five status sets for enrolment.
- **One place creates a class**, explicitly, by POST, never on a page view.
- **Teacher columns on enrolments and sessions go.** `instructor_assignments`
  goes back to meaning only "may teach this course".
- **The v2 draft already sketches `cohort_staff`**
  (`db/schema-v2-sqlite.sql`); build on it.

## 5. Decision needed ⚖️: how session times are stored

The recommended rule is to **store the moment in UTC** (`starts_at_utc`):
convert once when it's typed in and once when it's shown, always in
Africa/Lagos, and send times to browsers with their offset. Existing rows were
typed in Lagos time, so they convert by subtracting one hour. Nigeria has no
daylight saving, so the conversion is exact.

The alternative is to keep storing Lagos wall-clock time and make every
reader parse it as Lagos. That needs no data change, but every new reader
(calendar feeds, JaaS, emails) has to remember the rule, which is how the
current bug happened.

## 6. Order of work

1. ✅ Stop creating classes on view; fix the seat count; one stored time
   format; check-in message by mode (2026-11-28).
2. Time zone, per §5: one helper for reading and writing, migrate existing
   rows, and fix the 6 different reading styles.
3. ✅ `mam_teaches()` / `mam_teaches_student()` / `mam_teaches_session()` /
   `mam_enrolled_in()` replace the inline copies (about 50 sites across 30
   files); a static guard in `test_teaching_model.php` stops them returning.
   Rows C and D in §2 are fixed (2026-11-28). Deliberately left: the busy and
   clash checks (`lms.php` conflict detection, `slot-finder.php`), which ask
   whether a person is free rather than who may act; the gradebook's fallbacks
   for unmigrated enrolments; display joins naming the lead; progress-report
   fine attribution; analytics grouping by lead.
4. ✅ `cohort_staff` (v111): lead | assistant | cover with dates, a cover
   optionally tied to one session; backfilled from `cohorts.instructor_id` and
   `class_sessions.substitute_id`, and healed nightly by the job runner. Every
   change goes through `mam_cohort_set_lead()` / `mam_cohort_staff_add()` /
   `mam_cohort_staff_end()`, each running the vetting check. The two old
   columns stay as compatibility copies. Console: Enrol & staffing → Teaching
   staff. Still open: the enrolment's own `instructor_id` column (kept as the
   one-to-one teacher) and `instructor_assignments` as "may teach".
5. Class key: term + course + level + age band, so the KTAMS duplicates and
   the summer/Crash mix-up stop.
6. One series builder; the class's mode is the default for its sessions; no
   class space created on view.
7. Class-space marks feed the learning pipeline.
8. Capacity uses the enrolment status set; honour the requested teacher;
   drop the legacy tables.
