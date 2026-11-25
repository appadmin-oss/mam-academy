<?php
/**
 * GET /api/instructor/session-attendance-qr?session_id=N
 *
 * Returns a signed, day-scoped check-in token + scannable URL for a cohort
 * session the caller teaches (admins: any). The instructor UI renders the URL as
 * a QR; enrolled students scan it to self-check-in against session_attendance.
 * The class QR (/api/instructor/attendance-qr) it once stood beside is retired with the old class model.
 */
declare(strict_types=1);

$u       = mam_require_role('instructor', 'admin');
$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) mam_fail('validation', 422, ['fields' => ['session_id']]);

$sess = mam_lms_session($sessionId);
if (!$sess) mam_fail('session_not_found', 404);
if (!$isAdmin && !mam_lms_instructor_owns_cohort((int)$u['id'], (int)$sess['cohort_id'])) mam_fail('forbidden', 403);

// Scope the QR to the session's scheduled day (fall back to today).
$date  = !empty($sess['scheduled_at']) ? date('Y-m-d', (int)strtotime((string)$sess['scheduled_at'])) : date('Y-m-d');
$token = mam_attendance_session_token($sessionId, $date);
$base  = rtrim((string)mam_env('APP_URL', 'https://academy.mamcentre.org'), '/');

mam_ok([
    'session_id'    => $sessionId,
    'session_title' => (string)($sess['title'] ?? ''),
    'cohort_title'  => (string)($sess['cohort_title'] ?? ''),
    'date'          => $date,
    'token'         => $token,
    'url'           => $base . '/attend?stoken=' . rawurlencode($token),
    'expires'       => (int)strtotime($date . ' 23:59:59'),
]);
