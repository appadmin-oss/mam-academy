<?php
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');

$instructorId = (int)$u['id'];
$classes = mam_db_all(
    "SELECT c.*, (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.id AND e.status = 'active') AS enrolled_count FROM classes c WHERE c.instructor_id = ? AND c.status = 'active' ORDER BY c.starts_on DESC",
    [$instructorId]
);

$students = mam_db_all(
    "SELECT DISTINCT u.id, u.name, u.email, u.phone, e.class_id, c.title AS class_title FROM enrollments e JOIN classes c ON c.id = e.class_id JOIN users u ON u.id = e.student_id WHERE c.instructor_id = ? AND e.status = 'active' ORDER BY u.name",
    [$instructorId]
);

$recent_attendance = mam_db_all(
    'SELECT a.id, a.session_date, a.status, a.notes, u.name AS student_name, c.title AS class_title FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id JOIN users u ON u.id = e.student_id JOIN classes c ON c.id = e.class_id WHERE c.instructor_id = ? ORDER BY a.created_at DESC LIMIT 20',
    [$instructorId]
);

$profile = mam_db_row('SELECT * FROM instructor_profiles WHERE user_id = ?', [$instructorId]);

mam_ok([
    'user' => [
        'id' => $instructorId,
        'name' => $u['name'],
        'email' => $u['email'],
        'phone' => $u['phone'],
        'avatar_url' => $u['avatar_url'],
    ],
    'profile' => $profile,
    'classes' => $classes,
    'students' => $students,
    'recent_attendance' => $recent_attendance,
]);
