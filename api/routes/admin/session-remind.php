<?php
/**
 * POST /api/admin/session-remind
 *
 * Manual reminder dispatch and meeting-link management for admins.
 * Admins send to BOTH enrolled students AND the session/class instructor.
 * Instructors can only send to students (use POST /api/instructor/sessions).
 *
 *   {action:'send',     session_id:N}              — cohort session reminder
 *   {action:'send',     class_id:N}                — legacy class reminder
 *   {action:'set_link', session_id:N, meeting_link} — set link on cohort session
 *   {action:'set_link', class_id:N,   meeting_link} — set link on legacy class
 */
declare(strict_types=1);

// Gated by mw_admin_cap('enrollments'); what is needed here is an identity for
// the audit row, not a second session check that 401s a break-glass operator.
$adminId  = (int) mam_admin_require_actor()['id'];
$in       = mam_json_input();
$action   = strtolower(trim((string)($in['action'] ?? 'send')));
$appUrl   = rtrim((string)mam_env('APP_URL', ''), '/');
$loginUrl = $appUrl . '/portal/login';

// ── set meeting link ──────────────────────────────────────────────────────
if ($action === 'set_link') {
    $sessionId = isset($in['session_id']) ? (int)$in['session_id'] : 0;
    $classId   = isset($in['class_id'])   ? (int)$in['class_id']   : 0;
    $link      = trim((string)($in['meeting_link'] ?? ''));

    if ($link !== '' && !preg_match('#^https?://#i', $link)) {
        mam_fail('invalid_url', 422, ['detail' => 'Meeting link must start with https://']);
    }
    $safeLink = $link !== '' ? $link : null;

    if ($sessionId > 0) {
        if (!mam_db_row('SELECT 1 FROM class_sessions WHERE id = ?', [$sessionId])) mam_fail('session_not_found', 404);
        mam_db_update('class_sessions', ['meeting_link' => $safeLink], ['id' => $sessionId]);
        mam_audit('set_meeting_link', 'class_session', $sessionId, ['link_set' => ($link !== ''), 'by' => $adminId]);
        mam_ok(['ok' => true]);
    }
    if ($classId > 0) {
        if (!mam_db_row('SELECT 1 FROM classes WHERE id = ?', [$classId])) mam_fail('class_not_found', 404);
        mam_db_update('classes', ['meeting_link' => $safeLink], ['id' => $classId]);
        mam_audit('set_meeting_link', 'class', $classId, ['link_set' => ($link !== ''), 'by' => $adminId]);
        mam_ok(['ok' => true]);
    }
    mam_fail('validation', 422, ['detail' => 'session_id or class_id required']);
}

// ── send reminder ─────────────────────────────────────────────────────────
$sessionId = isset($in['session_id']) ? (int)$in['session_id'] : 0;
$classId   = isset($in['class_id'])   ? (int)$in['class_id']   : 0;

if ($sessionId > 0) {
    $sess = mam_db_row(
        "SELECT cs.*, co.instructor_id, co.title AS cohort_title
           FROM class_sessions cs
           JOIN cohorts co ON co.id = cs.cohort_id
          WHERE cs.id = ?",
        [$sessionId]
    );
    if (!$sess) mam_fail('session_not_found', 404);

    $title     = (string)($sess['title'] ?: ('Session — ' . ($sess['cohort_title'] ?: '#' . $sessionId)));
    $whenLabel = !empty($sess['scheduled_at'])
        ? date('l j M, g:i A T', strtotime((string)$sess['scheduled_at']))
        : 'upcoming';
    $joinUrl   = !empty($sess['meeting_link']) ? $appUrl . '/session/' . $sessionId . '/join' : '';

    // Students
    $students = mam_db_all(
        "SELECT DISTINCT u.name, u.email FROM course_enrollments ce
           JOIN users u ON u.id = ce.student_id
          WHERE ce.cohort_id = ? AND ce.status IN ('active','completed')
            AND u.email IS NOT NULL AND u.email <> ''",
        [(int)$sess['cohort_id']]
    );
    $sentStudents = 0;
    foreach ($students as $st) {
        mam_send(
            (string)$st['email'],
            'Reminder: ' . $title . ' is coming up',
            mam_email_session_reminder((string)($st['name'] ?? ''), $title, $whenLabel, $loginUrl, $joinUrl, 'student'),
            'session_reminder_manual', true
        );
        $sentStudents++;
    }

    // Instructor
    $sentInstructor = false;
    if (!empty($sess['instructor_id'])) {
        $instr = mam_db_row('SELECT name, email FROM users WHERE id = ?', [(int)$sess['instructor_id']]);
        if ($instr && !empty($instr['email'])) {
            mam_send(
                (string)$instr['email'],
                'Reminder: you have a session — ' . $title,
                mam_email_session_reminder((string)($instr['name'] ?? ''), $title, $whenLabel, $loginUrl, $joinUrl, 'instructor'),
                'session_reminder_manual', true
            );
            $sentInstructor = true;
        }
    }

    mam_event('session.reminder_manual', 'class_session', $sessionId,
        ['sent_by' => $adminId, 'students' => $sentStudents, 'instructor' => $sentInstructor]);
    mam_audit('send_reminder', 'class_session', $sessionId,
        ['students' => $sentStudents, 'instructor' => $sentInstructor, 'by' => $adminId]);
    mam_ok(['sent' => $sentStudents, 'instructor_notified' => $sentInstructor]);
}

if ($classId > 0) {
    $cls = mam_db_row(
        "SELECT c.*, u.name AS instructor_name, u.email AS instructor_email
           FROM classes c
           LEFT JOIN users u ON u.id = c.instructor_id
          WHERE c.id = ?",
        [$classId]
    );
    if (!$cls) mam_fail('class_not_found', 404);

    $title     = (string)($cls['title'] ?: 'your class');
    $whenLabel = !empty($cls['schedule']) ? (string)$cls['schedule'] : 'your next session';
    $joinUrl   = !empty($cls['meeting_link']) ? $appUrl . '/class/' . $classId . '/join' : '';

    $students = mam_db_all(
        "SELECT DISTINCT u.name, u.email FROM enrollments e
           JOIN users u ON u.id = e.student_id
          WHERE e.class_id = ? AND e.status = 'active'
            AND u.email IS NOT NULL AND u.email <> ''",
        [$classId]
    );
    $sentStudents = 0;
    foreach ($students as $st) {
        mam_send(
            (string)$st['email'],
            'Reminder: ' . $title . ' coming up',
            mam_email_session_reminder((string)($st['name'] ?? ''), $title, $whenLabel, $loginUrl, $joinUrl, 'student'),
            'session_reminder_manual', true
        );
        $sentStudents++;
    }

    $sentInstructor = false;
    if (!empty($cls['instructor_email'])) {
        mam_send(
            (string)$cls['instructor_email'],
            'Reminder: you have a class — ' . $title,
            mam_email_session_reminder((string)($cls['instructor_name'] ?? ''), $title, $whenLabel, $loginUrl, $joinUrl, 'instructor'),
            'session_reminder_manual', true
        );
        $sentInstructor = true;
    }

    mam_audit('send_reminder', 'class', $classId,
        ['students' => $sentStudents, 'instructor' => $sentInstructor, 'by' => $adminId]);
    mam_ok(['sent' => $sentStudents, 'instructor_notified' => $sentInstructor]);
}

mam_fail('validation', 422, ['detail' => 'session_id or class_id required']);
