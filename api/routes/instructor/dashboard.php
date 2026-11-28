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

/**
 * Cohort model only. The legacy class model (classes + enrollments +
 * attendance) is retired: mam_legacy_carry() carried every class into its
 * cohort, every enrolment into course_enrollments and every mark into
 * session_attendance, and nothing here reads the old tables.
 *
 * A cohort is this instructor's when they are on its staff (teaching.php) —
 * the same rule as mam_lms_instructor_owns_cohort(). A student whose own
 * enrolment names them is listed too, under that student's class.
 */
[$mine, $mineArgs] = mam_teaching_cohort_sql('co.id', $instructorId); // teaching.php
[$tq, $targs] = mam_teaching_enrolment_sql('ce', $instructorId);

$cohorts = $soft(fn () => mam_db_all(
    "SELECT co.id, co.title, co.status, co.capacity, co.mode, co.starts_on, co.ends_on,
            c.name AS course_name, l.name AS level_name,
            (SELECT COUNT(DISTINCT ce.student_id) FROM course_enrollments ce
              WHERE ce.cohort_id = co.id AND ce.status = 'active') AS enrolled_count
       FROM cohorts co
       LEFT JOIN courses c ON c.id = co.course_id
       LEFT JOIN levels l  ON l.id = co.level_id
      WHERE $mine AND co.status = 'active'
      ORDER BY co.starts_on DESC, co.id DESC",
    $mineArgs
), []);

/* One row per enrolment, so a student in two cohorts appears under each. */
$students = $soft(fn () => mam_db_all(
    "SELECT u.id, u.name, u.enrollment_ref, ce.id AS enrollment_id, ce.cohort_id, co.title AS cohort_title
       FROM course_enrollments ce
       JOIN cohorts co ON co.id = ce.cohort_id
       JOIN users u ON u.id = ce.student_id
      WHERE $tq AND ce.status = 'active'
      ORDER BY co.title, u.name",
    $targs
), []);

/**
 * What this instructor teaches: active cohorts and the DISTINCT students in
 * them (a student in two of their cohorts is one person).
 */
$teaching = $soft(fn () => [
    'cohorts'  => count($cohorts),
    'students' => count(array_unique(array_map(static fn ($s) => (int) $s['id'], $students))),
], ['cohorts' => 0, 'students' => 0]);

$recent_attendance = $soft(fn () => mam_db_all(
    "SELECT sa.id, substr(cs.scheduled_at, 1, 10) AS session_date, sa.status, sa.notes,
            u.name AS student_name, co.title AS cohort_title, cs.title AS session_title
       FROM session_attendance sa
       JOIN class_sessions cs ON cs.id = sa.session_id
       JOIN cohorts co ON co.id = cs.cohort_id
       JOIN users u ON u.id = sa.student_id
      WHERE $mine
      ORDER BY cs.scheduled_at DESC, sa.id DESC LIMIT 20",
    $mineArgs
), []);

$profile = $soft(fn () => mam_db_row('SELECT * FROM instructor_profiles WHERE user_id = ?', [$instructorId]), null);

$announcements = $soft(fn () => mam_db_all(
    "SELECT an.id, an.title, an.body, an.created_at, an.cohort_id, co.title AS cohort_title
       FROM announcements an JOIN cohorts co ON co.id = an.cohort_id
      WHERE $mine ORDER BY an.created_at DESC LIMIT 30",
    $mineArgs
), []);

$resources = $soft(fn () => mam_db_all(
    "SELECT r.id, r.title, r.url, r.type, r.created_at, r.cohort_id, co.title AS cohort_title
       FROM class_resources r JOIN cohorts co ON co.id = r.cohort_id
      WHERE $mine ORDER BY r.created_at DESC LIMIT 50",
    $mineArgs
), []);

// Assignments this instructor has set (most recent first, with the pupil + state).
$assignments = $soft(fn () => mam_db_all(
    "SELECT a.id, a.title, a.due_on, a.status, a.completed_at, a.created_at, a.cohort_id,
            u.name AS student_name, co.title AS cohort_title
       FROM assignments a
       JOIN users u ON u.id = a.student_id
       LEFT JOIN cohorts co ON co.id = a.cohort_id
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
    'cohorts' => $cohorts,
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
