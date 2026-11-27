<?php
/**
 * Student support hub — feedback, surveys, and complaint tickets (v42).
 * All writes are student-scoped; read aggregates expose nothing personal.
 */
declare(strict_types=1);

/* ── Feedback ─────────────────────────────────────────────────────────────── */

function mam_feedback_submit(int $studentId, int $rating, string $category, string $body, ?string $subject, ?int $enrollmentId, ?int $courseEnrollmentId, bool $anonymous): int {
    if ($rating < 1 || $rating > 5) throw new \InvalidArgumentException('bad_rating');
    $body = trim($body);
    if ($body === '') throw new \InvalidArgumentException('empty_body');
    return mam_db_insert('student_feedback', [
        'student_id'           => $studentId,
        'rating'               => $rating,
        'category'             => mb_substr(trim($category), 0, 64) ?: 'general',
        'subject'              => $subject !== null ? mb_substr(trim($subject), 0, 200) : null,
        'body'                 => mb_substr($body, 0, 4000),
        'enrollment_id'        => $enrollmentId,
        'course_enrollment_id' => $courseEnrollmentId,
        'is_anonymous'         => $anonymous ? 1 : 0,
    ]);
}

function mam_feedback_by_student(int $studentId, int $limit = 50): array {
    return mam_db_all(
        "SELECT id, rating, category, subject, body, is_anonymous, created_at
           FROM student_feedback WHERE student_id = ?
           ORDER BY created_at DESC LIMIT " . max(1, min(200, $limit)),
        [$studentId]
    );
}

function mam_feedback_aggregate(): array {
    $rows = mam_db_all(
        "SELECT category, COUNT(*) AS count, ROUND(AVG(rating),2) AS avg_rating
           FROM student_feedback GROUP BY category ORDER BY count DESC"
    );
    $recent = mam_db_all(
        "SELECT f.id, f.rating, f.category, f.subject, f.body, f.is_anonymous, f.created_at,
                CASE WHEN f.is_anonymous=1 THEN 'Anonymous' ELSE u.name END AS student_name
           FROM student_feedback f JOIN users u ON u.id = f.student_id
           ORDER BY f.created_at DESC LIMIT 50"
    );
    return ['by_category' => $rows, 'recent' => $recent];
}

/* ── Surveys ──────────────────────────────────────────────────────────────── */

function mam_surveys_active(string $programTrack = 'MAM'): array {
    return mam_db_all(
        "SELECT id, title, description, questions_json, closes_at, created_at
           FROM surveys
          WHERE status = 'active'
            AND (target_track IS NULL OR target_track = ?)
            AND (closes_at IS NULL OR closes_at > ?)
          ORDER BY created_at DESC",
        [$programTrack, gmdate('Y-m-d H:i:s')]
    );
}

function mam_survey_responded(int $surveyId, int $studentId): bool {
    return (bool)mam_db_row(
        'SELECT id FROM survey_responses WHERE survey_id = ? AND student_id = ?',
        [$surveyId, $studentId]
    );
}

function mam_survey_respond(int $surveyId, int $studentId, array $answers): int {
    if (!mam_db_row('SELECT id FROM surveys WHERE id = ? AND status = ?', [$surveyId, 'active'])) {
        throw new \RuntimeException('survey_not_found');
    }
    if (mam_survey_responded($surveyId, $studentId)) {
        throw new \RuntimeException('already_responded');
    }
    return mam_db_insert('survey_responses', [
        'survey_id'  => $surveyId,
        'student_id' => $studentId,
        'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE),
    ]);
}

function mam_survey_create(int $adminId, string $title, ?string $description, array $questions, ?string $targetTrack, ?string $closesAt): int {
    $title = trim($title);
    if ($title === '') throw new \InvalidArgumentException('empty_title');
    return mam_db_insert('surveys', [
        'title'          => mb_substr($title, 0, 200),
        'description'    => $description ? mb_substr(trim($description), 0, 2000) : null,
        'questions_json' => json_encode($questions, JSON_UNESCAPED_UNICODE),
        'status'         => 'active',
        'target_track'   => $targetTrack ?: null,
        'created_by'     => $adminId,
        'closes_at'      => $closesAt ?: null,
    ]);
}

function mam_surveys_admin(int $limit = 100): array {
    $surveys = mam_db_all(
        "SELECT s.id, s.title, s.description, s.status, s.target_track, s.closes_at, s.created_at,
                u.name AS created_by_name,
                (SELECT COUNT(*) FROM survey_responses sr WHERE sr.survey_id = s.id) AS response_count
           FROM surveys s LEFT JOIN users u ON u.id = s.created_by
           ORDER BY s.created_at DESC LIMIT " . max(1, min(500, $limit))
    );
    return $surveys;
}

function mam_survey_results(int $surveyId): array {
    $survey = mam_db_row('SELECT * FROM surveys WHERE id = ?', [$surveyId]);
    if (!$survey) throw new \RuntimeException('survey_not_found');
    $questions = json_decode((string)($survey['questions_json'] ?? '[]'), true) ?: [];
    $responses = mam_db_all(
        'SELECT sr.answers_json, sr.submitted_at, u.name AS student_name
           FROM survey_responses sr JOIN users u ON u.id = sr.student_id
          WHERE sr.survey_id = ? ORDER BY sr.submitted_at DESC',
        [$surveyId]
    );
    foreach ($responses as &$r) {
        $r['answers'] = json_decode((string)$r['answers_json'], true) ?: [];
        unset($r['answers_json']);
    }
    unset($r);
    // Aggregate answers per question index for the admin results view.
    $byQuestion = [];
    foreach ($questions as $qi => $q) {
        $type = (string)($q['type'] ?? 'text');
        $entry = ['text' => (string)($q['text'] ?? ''), 'type' => $type, 'answers' => [], 'distribution' => [], 'avg' => null];
        if ($type === 'rating') {
            $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]; $sum = 0; $n = 0;
            foreach ($responses as $resp) {
                $val = (int)($resp['answers'][$qi] ?? 0);
                if ($val >= 1 && $val <= 5) { $dist[$val]++; $sum += $val; $n++; }
            }
            $entry['distribution'] = $dist;
            $entry['avg'] = $n > 0 ? round($sum / $n, 1) : null;
        } else {
            foreach ($responses as $resp) {
                $val = trim((string)($resp['answers'][$qi] ?? ''));
                if ($val !== '') $entry['answers'][] = $val;
            }
        }
        $byQuestion[$qi] = $entry;
    }
    return ['survey' => $survey, 'responses' => $responses, 'response_count' => count($responses), 'by_question' => $byQuestion];
}

/* ── Support tickets ──────────────────────────────────────────────────────── */

function mam_ticket_reference(): string {
    return 'TKT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function mam_ticket_create(int $studentId, string $category, string $subject, string $body, string $priority = 'normal'): array {
    $subject = trim($subject);
    $body    = trim($body);
    if ($subject === '') throw new \InvalidArgumentException('empty_subject');
    if ($body === '')    throw new \InvalidArgumentException('empty_body');
    $ref = mam_ticket_reference();
    // A support ticket is the wrong queue for a safeguarding worry (NGG keeps it out
    // of support entirely): words about harm also reach the officer (safeguarding.php).
    if ((stripos($category, 'safeguard') !== false || (function_exists('mam_lesson_safeguarding_hit') && mam_lesson_safeguarding_hit($subject . ' ' . $body)))
        && function_exists('mam_sg_auto')) {
        mam_sg_auto($studentId, 'ticket', 0, 'wellbeing', 'Raised in a support ticket: ' . mb_substr($subject, 0, 120));
    }
    $id  = mam_db_insert('support_tickets', [
        'student_id' => $studentId,
        'reference'  => $ref,
        'category'   => mb_substr(trim($category), 0, 64) ?: 'general',
        'subject'    => mb_substr($subject, 0, 300),
        'body'       => mb_substr($body, 0, 8000),
        'status'     => 'open',
        'priority'   => in_array($priority, ['low','normal','high','urgent'], true) ? $priority : 'normal',
    ]);
    // Opening message = the ticket body itself
    mam_db_insert('ticket_messages', [
        'ticket_id' => $id, 'author_id' => $studentId,
        'body' => mb_substr($body, 0, 8000), 'is_internal' => 0,
    ]);
    mam_audit('ticket_open', 'support_ticket', $id, ['ref' => $ref, 'category' => $category]);
    return ['id' => $id, 'reference' => $ref];
}

function mam_tickets_by_student(int $studentId): array {
    return mam_db_all(
        "SELECT t.id, t.reference, t.category, t.subject, t.status, t.priority,
                t.created_at, t.updated_at, t.resolved_at,
                u.name AS assigned_to_name,
                (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id AND m.is_internal = 0) AS message_count
           FROM support_tickets t
           LEFT JOIN users u ON u.id = t.assigned_to
          WHERE t.student_id = ?
          ORDER BY t.updated_at DESC",
        [$studentId]
    );
}

function mam_ticket_get(int $ticketId, int $studentId): ?array {
    $ticket = mam_db_row(
        "SELECT t.*, u.name AS assigned_to_name
           FROM support_tickets t LEFT JOIN users u ON u.id = t.assigned_to
          WHERE t.id = ? AND t.student_id = ?",
        [$ticketId, $studentId]
    );
    if (!$ticket) return null;
    $ticket['messages'] = mam_db_all(
        "SELECT m.id, m.body, m.created_at, u.name AS author_name, u.role AS author_role
           FROM ticket_messages m JOIN users u ON u.id = m.author_id
          WHERE m.ticket_id = ? AND m.is_internal = 0
          ORDER BY m.created_at ASC",
        [$ticketId]
    );
    return $ticket;
}

function mam_ticket_reply(int $ticketId, int $authorId, string $body, bool $internal = false): int {
    $body = trim($body);
    if ($body === '') throw new \InvalidArgumentException('empty_body');
    $msgId = mam_db_insert('ticket_messages', [
        'ticket_id'   => $ticketId,
        'author_id'   => $authorId,
        'body'        => mb_substr($body, 0, 8000),
        'is_internal' => $internal ? 1 : 0,
    ]);
    mam_db_update('support_tickets', ['updated_at' => date('Y-m-d H:i:s')], ['id' => $ticketId]);
    return $msgId;
}

function mam_ticket_resolve(int $ticketId): void {
    mam_db_update('support_tickets', [
        'status'      => 'resolved',
        'resolved_at' => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ], ['id' => $ticketId]);
}

function mam_tickets_admin(string $status = '', int $limit = 100): array {
    $where = $status !== '' ? "WHERE t.status = ?" : "WHERE 1=1";
    $params = $status !== '' ? [$status] : [];
    return mam_db_all(
        "SELECT t.id, t.reference, t.category, t.subject, t.status, t.priority,
                t.created_at, t.updated_at, t.resolved_at,
                s.name AS student_name, s.email AS student_email,
                a.name AS assigned_to_name,
                (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id AND m.is_internal = 0) AS message_count
           FROM support_tickets t
           JOIN users s ON s.id = t.student_id
           LEFT JOIN users a ON a.id = t.assigned_to
           $where ORDER BY t.priority DESC, t.updated_at DESC LIMIT " . max(1, min(500, $limit)),
        $params
    );
}
