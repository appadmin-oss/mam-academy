<?php
/**
 * Admin support hub — feedback, surveys, and tickets.
 * Gated by mw_admin_cap('support') in api/index.php.
 *
 * GET  ?action=feedback              → aggregated feedback
 * GET  ?action=surveys               → all surveys
 * GET  ?action=survey_results&id=X   → one survey's responses
 * GET  ?action=tickets[&status=X]    → ticket list (filtered by status)
 * GET  ?action=ticket&id=X           → single ticket + thread
 *
 * POST action=survey_create          {title,description?,questions,target_track?,closes_at?}
 * POST action=survey_status          {survey_id, status}
 * POST action=ticket_reply           {ticket_id, body, internal?}
 * POST action=ticket_assign          {ticket_id, assigned_to}
 * POST action=ticket_resolve         {ticket_id}
 */
declare(strict_types=1);
$u = mam_require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'tickets');

    if ($action === 'feedback')       { mam_ok(mam_feedback_aggregate()); exit; }
    if ($action === 'surveys')        { mam_ok(['surveys' => mam_surveys_admin()]); exit; }
    if ($action === 'survey_results') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) mam_fail('missing_field', 400, ['field' => 'id']);
        try { mam_ok(mam_survey_results($id)); } catch (\RuntimeException $e) { mam_fail($e->getMessage(), 404); }
        exit;
    }
    if ($action === 'tickets') {
        $status = trim((string)($_GET['status'] ?? ''));
        mam_ok(['tickets' => mam_tickets_admin($status)]);
        exit;
    }
    if ($action === 'ticket') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) mam_fail('missing_field', 400, ['field' => 'id']);
        $ticket = mam_db_row(
            "SELECT t.*, s.name AS student_name, s.email AS student_email, a.name AS assigned_to_name
               FROM support_tickets t
               JOIN users s ON s.id = t.student_id
               LEFT JOIN users a ON a.id = t.assigned_to
              WHERE t.id = ?", [$id]
        );
        if (!$ticket) mam_fail('not_found', 404);
        $ticket['messages'] = mam_db_all(
            "SELECT m.id, m.body, m.is_internal, m.created_at, u.name AS author_name, u.role AS author_role
               FROM ticket_messages m JOIN users u ON u.id = m.author_id
              WHERE m.ticket_id = ? ORDER BY m.created_at ASC", [$id]
        );
        mam_ok(['ticket' => $ticket]);
        exit;
    }
    mam_fail('unknown_action', 400, ['action' => $action]);
}

$in     = mam_json_input();
$action = (string)($in['action'] ?? $_GET['action'] ?? '');

if ($action === 'survey_create') {
    $title   = trim((string)($in['title'] ?? ''));
    $qs      = (isset($in['questions']) && is_array($in['questions'])) ? $in['questions'] : [];
    if ($title === '')   mam_fail('missing_field', 400, ['field' => 'title']);
    if (empty($qs))      mam_fail('missing_field', 400, ['field' => 'questions']);
    try {
        $id = mam_survey_create((int)$u['id'], $title, $in['description'] ?? null, $qs, $in['target_track'] ?? null, $in['closes_at'] ?? null);
        mam_ok(['id' => $id]);
    } catch (\InvalidArgumentException $e) { mam_fail($e->getMessage(), 422); }
    exit;
}

if ($action === 'survey_status') {
    $id     = (int)($in['survey_id'] ?? 0);
    $status = trim((string)($in['status'] ?? ''));
    if ($id < 1)     mam_fail('missing_field', 400, ['field' => 'survey_id']);
    if (!in_array($status, ['draft','active','closed'], true)) mam_fail('bad_status', 422);
    mam_db_update('surveys', ['status' => $status], ['id' => $id]);
    mam_ok(['updated' => true]);
    exit;
}

if ($action === 'ticket_reply') {
    $id       = (int)($in['ticket_id'] ?? 0);
    $body     = trim((string)($in['body'] ?? ''));
    $internal = !empty($in['internal']);
    if ($id < 1)     mam_fail('missing_field', 400, ['field' => 'ticket_id']);
    if ($body === '') mam_fail('empty_body', 422);
    if (!mam_db_row('SELECT id FROM support_tickets WHERE id = ?', [$id])) mam_fail('not_found', 404);
    $msgId = mam_ticket_reply($id, (int)$u['id'], $body, $internal);
    if (!$internal) {
        $ticket = mam_db_row('SELECT student_id, reference, subject FROM support_tickets WHERE id = ?', [$id]);
        if ($ticket) mam_notify((int)$ticket['student_id'], 'ticket_reply', 'New reply on ' . $ticket['reference'], mb_substr($body, 0, 120), '#support');
    }
    mam_ok(['message_id' => $msgId]);
    exit;
}

if ($action === 'ticket_assign') {
    $id  = (int)($in['ticket_id'] ?? 0);
    $uid = (int)($in['assigned_to'] ?? 0);
    if ($id < 1) mam_fail('missing_field', 400, ['field' => 'ticket_id']);
    mam_db_update('support_tickets', ['assigned_to' => $uid ?: null, 'status' => 'in_progress', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    mam_ok(['updated' => true]);
    exit;
}

if ($action === 'ticket_resolve') {
    $id = (int)($in['ticket_id'] ?? 0);
    if ($id < 1) mam_fail('missing_field', 400, ['field' => 'ticket_id']);
    mam_ticket_resolve($id);
    $ticket = mam_db_row('SELECT student_id, reference FROM support_tickets WHERE id = ?', [$id]);
    if ($ticket) mam_notify((int)$ticket['student_id'], 'ticket_resolved', 'Ticket ' . $ticket['reference'] . ' resolved', 'Your issue has been marked as resolved.', '#support');
    mam_ok(['resolved' => true]);
    exit;
}

mam_fail('unknown_action', 400, ['action' => $action]);
