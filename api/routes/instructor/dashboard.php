<?php
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');

$instructorId = (int)$u['id'];
$classes = mam_db_all(
    "SELECT c.*, (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id AND e.status = 'active') AS enrolled_count FROM classes c WHERE c.instructor_id = ? AND c.status = 'active' ORDER BY c.starts_on DESC",
    [$instructorId]
);

$students = mam_db_all(
    "SELECT u.id, u.name, u.email, u.phone, e.id AS enrollment_id, e.class_id, c.title AS class_title FROM enrollments e JOIN classes c ON c.id = e.class_id JOIN users u ON u.id = e.student_id WHERE c.instructor_id = ? AND e.status = 'active' ORDER BY c.title, u.name",
    [$instructorId]
);

$recent_attendance = mam_db_all(
    'SELECT a.id, a.session_date, a.status, a.notes, u.name AS student_name, c.title AS class_title FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id JOIN users u ON u.id = e.student_id JOIN classes c ON c.id = e.class_id WHERE c.instructor_id = ? ORDER BY a.created_at DESC LIMIT 20',
    [$instructorId]
);

$profile = mam_db_row('SELECT * FROM instructor_profiles WHERE user_id = ?', [$instructorId]);

$announcements = mam_db_all(
    "SELECT an.id, an.title, an.body, an.created_at, an.class_id, c.title AS class_title
       FROM announcements an JOIN classes c ON c.id = an.class_id
      WHERE c.instructor_id = ? ORDER BY an.created_at DESC LIMIT 30",
    [$instructorId]
);

$resources = mam_db_all(
    "SELECT r.id, r.title, r.url, r.type, r.created_at, r.class_id, c.title AS class_title
       FROM class_resources r JOIN classes c ON c.id = r.class_id
      WHERE c.instructor_id = ? ORDER BY r.created_at DESC LIMIT 50",
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
]);
