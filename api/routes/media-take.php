<?php
/**
 * GET /api/media/take?k=<key>
 *
 * Streams a stored practice take (Advanced Modernization §1.4).
 *
 * UNLIKE the headshot proxy, this one is ACCESS-GATED rather than
 * unguessable-key-only. A take is a recording of a student — often a child —
 * playing, so "whoever holds the link may listen" is the wrong policy even
 * though the key is 128 bits of randomness. A URL leaks: it ends up in a
 * forwarded email, a screenshot, a browser history on a shared machine.
 *
 * Who may listen:
 *   • the student who recorded it,
 *   • an instructor who teaches them (either enrolment model),
 *   • a linked guardian,
 *   • an admin.
 *
 * The key is resolved back to its submission to answer that question. A key
 * with no submission row is 404 — the same answer as a malformed key, so
 * nothing here is an enumeration oracle.
 */
declare(strict_types=1);

$key  = (string) ($_GET['k'] ?? '');
$path = mam_take_path($key);

$deny = function (int $status, string $error) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $error]);
    if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
};

if ($path === null || !is_file($path)) { $deny(404, 'not_found'); return; }

$u = mam_session_user();
if (!$u) { $deny(401, 'authentication_required'); return; }
$uid = (int) $u['id'];

// Resolve the key back to its submission. LIKE on the key is safe: the grammar
// is already validated by mam_take_path, so it cannot carry a wildcard.
$sub = mam_db_row(
    "SELECT s.id, s.student_id
       FROM submissions s
       JOIN assignments a ON a.id = s.assignment_id
      WHERE s.artifact_url LIKE ?
      ORDER BY s.id DESC",
    ['%k=' . str_replace('/', '%2F', $key)]
);
// A stored file with no submission row cannot be authorised, so it is not served.
if (!$sub) { $deny(404, 'not_found'); return; }

$studentId = (int) $sub['student_id'];
$allowed   = ($studentId === $uid);

if (!$allowed && function_exists('mam_user_has_role') && mam_user_has_role($u, 'admin')) $allowed = true;

if (!$allowed && function_exists('mam_user_has_role') && mam_user_has_role($u, 'instructor')) {
    // A teacher of this student: the enrolment's instructor or its cohort's.
    // Cohort model only — the legacy class roster is retired (mam_legacy_carry()).
    $cohort = mam_db_row(
        'SELECT 1 FROM course_enrollments ce LEFT JOIN cohorts co ON co.id = ce.cohort_id
          WHERE ce.student_id = ? AND (co.instructor_id = ? OR ce.instructor_id = ?)',
        [$studentId, $uid, $uid]
    );
    if ($cohort) $allowed = true;
}

if (!$allowed && function_exists('mam_parent_can_view') && mam_parent_can_view($uid, $studentId)) $allowed = true;

if (!$allowed) { $deny(403, 'forbidden'); return; }

$mime = mam_take_mime($key) ?? 'application/octet-stream';
$size = (int) filesize($path);

header_remove('Content-Type');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) $size);
// PRIVATE and short-lived, unlike the headshot's immutable year: this response
// depends on WHO asked, so a shared cache must never reuse it for someone else.
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="take.' . pathinfo($key, PATHINFO_EXTENSION) . '"');

readfile($path);
// Claim the response so bootstrap's shutdown handler does not append a JSON
// envelope to the audio bytes.
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
