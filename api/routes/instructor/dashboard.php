<?php
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');

$instructorId = (int)$u['id'];

/**
 * Resilience (mirrors student/dashboard.php): a deployment whose DB is behind
 * on migrations must not 500 the whole instructor home over one missing table.
 * Each data source runs through $soft() — logs a warning, returns the fallback —
 * while the admin overview banner + /api/diag still tell the operator to migrate.
 */
$soft = function (callable $fn, $default) {
    try { return $fn(); }
    catch (Throwable $e) { mam_log('warn', 'instructor_dashboard_section_failed', ['err' => $e->getMessage()]); return $default; }
};

$classes = $soft(fn () => mam_db_all(
    "SELECT c.*, (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id AND e.status = 'active') AS enrolled_count FROM classes c WHERE c.instructor_id = ? AND c.status = 'active' ORDER BY c.starts_on DESC",
    [$instructorId]
), []);

$students = $soft(fn () => mam_db_all(
    "SELECT u.id, u.name, u.enrollment_ref, e.id AS enrollment_id, e.class_id, c.title AS class_title FROM enrollments e JOIN classes c ON c.id = e.class_id JOIN users u ON u.id = e.student_id WHERE c.instructor_id = ? AND e.status = 'active' ORDER BY c.title, u.name",
    [$instructorId]
), []);

/**
 * What this instructor teaches, counted across BOTH enrolment models.
 *
 * `$classes` and `$students` above read `classes` + `enrollments` only, so an
 * instructor whose teaching lives in cohorts saw "You teach 0 active classes
 * and 0 students right now" on the same screen as their outstanding progress
 * reports for a cohort. That is gotcha #2 showing through on the first line of
 * the page — dual-read everywhere was the rule and this line was missed.
 *
 * Students are counted DISTINCT across the two models: a student who is both in
 * a backfilled legacy class and its cohort is one person, and counting them
 * twice would overstate a roster on the instructor's own dashboard.
 */
$teaching = $soft(fn () => [
    'classes'  => count($classes),
    'cohorts'  => (int) (mam_db_row(
        "SELECT COUNT(*) c FROM cohorts WHERE instructor_id = ? AND status = 'active'",
        [$instructorId]
    )['c'] ?? 0),
    'students' => (int) (mam_db_row(
        "SELECT COUNT(*) c FROM (
             SELECT e.student_id AS sid FROM enrollments e JOIN classes c ON c.id = e.class_id
              WHERE c.instructor_id = ? AND e.status = 'active'
             UNION
             SELECT ce.student_id AS sid FROM course_enrollments ce JOIN cohorts co ON co.id = ce.cohort_id
              WHERE co.instructor_id = ? AND ce.status = 'active'
         ) x",
        [$instructorId, $instructorId]
    )['c'] ?? 0),
], ['classes' => count($classes), 'cohorts' => 0, 'students' => 0]);

$recent_attendance = $soft(fn () => mam_db_all(
    'SELECT a.id, a.session_date, a.status, a.notes, u.name AS student_name, c.title AS class_title FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id JOIN users u ON u.id = e.student_id JOIN classes c ON c.id = e.class_id WHERE c.instructor_id = ? ORDER BY a.created_at DESC LIMIT 20',
    [$instructorId]
), []);

$profile = $soft(fn () => mam_db_row('SELECT * FROM instructor_profiles WHERE user_id = ?', [$instructorId]), null);

$announcements = $soft(fn () => mam_db_all(
    "SELECT an.id, an.title, an.body, an.created_at, an.class_id, c.title AS class_title
       FROM announcements an JOIN classes c ON c.id = an.class_id
      WHERE c.instructor_id = ? ORDER BY an.created_at DESC LIMIT 30",
    [$instructorId]
), []);

$resources = $soft(fn () => mam_db_all(
    "SELECT r.id, r.title, r.url, r.type, r.created_at, r.class_id, c.title AS class_title
       FROM class_resources r JOIN classes c ON c.id = r.class_id
      WHERE c.instructor_id = ? ORDER BY r.created_at DESC LIMIT 50",
    [$instructorId]
), []);

// Assignments this instructor has set (most recent first, with the pupil + state).
$assignments = $soft(fn () => mam_db_all(
    "SELECT a.id, a.title, a.due_on, a.status, a.completed_at, a.created_at, a.class_id,
            u.name AS student_name, c.title AS class_title
       FROM assignments a
       JOIN users u ON u.id = a.student_id
       LEFT JOIN classes c ON c.id = a.class_id
      WHERE a.author_id = ? ORDER BY a.created_at DESC LIMIT 80",
    [$instructorId]
), []);

mam_ok([
    'user' => [
        'id' => $instructorId,
        'name' => $u['name'],
        'email' => $u['email'],
        'phone' => $u['phone'],
        'avatar_url' => $u['avatar_url'],
        'must_change_password' => (int)($u['must_change_password'] ?? 0) === 1,
    ],
    'profile' => $profile,
    'classes' => $classes,
    'students' => $students,
    'recent_attendance' => $recent_attendance,
    'announcements' => $announcements,
    'resources' => $resources,
    'assignments' => $assignments,
    // What this instructor OWES right now — the overview reported what had
    // happened and never what was outstanding, so the first news of a progress
    // report being due was the notification saying it was already overdue.
    // Soft-loaded like every other block: one failing count must not take the
    // dashboard with it.
    'teaching' => $teaching,
    'worklist' => $soft(fn () => function_exists('mam_lms2_instructor_worklist')
        ? mam_lms2_instructor_worklist($instructorId) : null, null),
    // #5: whether file uploads to Drive are configured (drives the resource
    // form's file uploader; off → the URL-only path is shown).
    'drive_enabled' => function_exists('mam_drive_enabled') ? mam_drive_enabled() : false,
]);
