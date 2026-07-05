<?php
/**
 * GET /api/instructor/attendance-qr?class_id=N
 *
 * Returns a signed, day-scoped check-in token + the scannable URL for today's
 * session of a class the caller teaches (admins: any class). The instructor UI
 * renders the URL as a QR; students scan it to self-check-in. The token is
 * verified server-side on check-in — this endpoint only mints it.
 */
declare(strict_types=1);

$u = mam_require_role('instructor', 'admin');
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');

$classId = (int)($_GET['class_id'] ?? 0);
if ($classId <= 0) mam_fail('validation', 422, ['fields' => ['class_id']]);

$cls = mam_db_row('SELECT id, title, instructor_id FROM classes WHERE id = ?', [$classId]);
if (!$cls) mam_fail('class_not_found', 404);
if (!$isAdmin && (int)$cls['instructor_id'] !== (int)$u['id']) mam_fail('forbidden', 403);

$date  = date('Y-m-d');
$token = mam_attendance_token($classId, $date);
$base  = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');

mam_ok([
    'class_id'    => $classId,
    'class_title' => (string)$cls['title'],
    'date'        => $date,
    'token'       => $token,
    'url'         => $base . '/attend?token=' . rawurlencode($token),
    'expires'     => (int)strtotime($date . ' 23:59:59'),
]);
