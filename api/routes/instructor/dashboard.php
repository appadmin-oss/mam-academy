<?php
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');

$instructorId = (int)$u['id'];

// Resilient readers — a behind-migration DB (missing announcements v14 /
// assignments v15) must degrade to empty sections, not 500 the whole portal.
$qAll = function (string $sql, array $p = []) { try { return mam_db_all($sql, $p); } catch (Throwable $e) { mam_log('info', 'instr_dashboard_query_skipped', ['err' => $e->getMessage()]); return []; } };
$qRow = function (string $sql, array $p = []) { try { return mam_db_row($sql, $p); } catch (Throwable $e) { return null; } };

$classes = $qAll(
    "SELECT c.*, (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id AND e.status = 'active') AS enrolled_count FROM classes c WHERE c.instructor_id = ? AND c.status = 'active' ORDER BY c.starts_on DESC",
    [$instructorId]
);

$students = $qAll(
    "SELECT u.id, u.name, u.email, u.phone, e.id AS enrollment_id, e.class_id, c.title AS class_title FROM enrollments e JOIN classes c ON c.id = e.class_id JOIN users u ON u.id = e.student_id WHERE c.instructor_id = ? AND e.status = 'active' ORDER BY c.title, u.name",
    [$instructorId]
);

$recent_attendance = $qAll(
    'SELECT a.id, a.session_date, a.status, a.notes, u.name AS student_name, c.title AS class_title FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id JOIN users u ON u.id = e.student_id JOIN classes c ON c.id = e.class_id WHERE c.instructor_id = ? ORDER BY a.created_at DESC LIMIT 20',
    [$instructorId]
);

$profile = $qRow('SELECT * FROM instructor_profiles WHERE user_id = ?', [$instructorId]);

$announcements = $qAll(
    "SELECT an.id, an.title, an.body, an.created_at, an.class_id, c.title AS class_title
       FROM announcements an JOIN classes c ON c.id = an.class_id
      WHERE c.instructor_id = ? ORDER BY an.created_at DESC LIMIT 30",
    [$instructorId]
);

$resources = $qAll(
    "SELECT r.id, r.title, r.url, r.type, r.created_at, r.class_id, c.title AS class_title
       FROM class_resources r JOIN classes c ON c.id = r.class_id
      WHERE c.instructor_id = ? ORDER BY r.created_at DESC LIMIT 50",
    [$instructorId]
);

// Assignments this instructor has set (most recent first, with the pupil + state).
$assignments = $qAll(
    "SELECT a.id, a.title, a.due_on, a.status, a.completed_at, a.created_at, a.class_id,
            u.name AS student_name, c.title AS class_title
       FROM assignments a
       JOIN users u ON u.id = a.student_id
       LEFT JOIN classes c ON c.id = a.class_id
      WHERE a.author_id = ? ORDER BY a.created_at DESC LIMIT 80",
    [$instructorId]
);

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
]);
