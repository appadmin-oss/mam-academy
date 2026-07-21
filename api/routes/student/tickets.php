<?php
/**
 * GET  /api/student/tickets          → student's ticket list
 * GET  /api/student/tickets?id=X     → single ticket + thread
 * POST /api/student/tickets          → open new ticket
 * POST /api/student/tickets?reply=1  → reply to existing ticket
 */
declare(strict_types=1);
$u = mam_require_role('student');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0) {
        $ticket = mam_ticket_get($id, (int)$u['id']);
        if (!$ticket) mam_fail('not_found', 404);
        mam_ok(['ticket' => $ticket]);
    } else {
        mam_ok(['tickets' => mam_tickets_by_student((int)$u['id'])]);
    }
    exit;
}

$in = mam_json_input();

// Reply to an existing ticket
if (!empty($in['reply']) || !empty($_GET['reply'])) {
    $id   = (int)($in['ticket_id'] ?? 0);
    $body = trim((string)($in['body'] ?? ''));
    if ($id < 1)      mam_fail('missing_field', 400, ['field' => 'ticket_id']);
    if ($body === '')  mam_fail('empty_body',    422, ['field' => 'body']);
    if (!mam_db_row('SELECT id FROM support_tickets WHERE id = ? AND student_id = ?', [$id, (int)$u['id']])) {
        mam_fail('not_found', 404);
    }
    $msgId = mam_ticket_reply($id, (int)$u['id'], $body);
    mam_ok(['message_id' => $msgId]);
    exit;
}

// Open a new ticket
$category = trim((string)($in['category'] ?? 'general'));
$subject  = trim((string)($in['subject']  ?? ''));
$body     = trim((string)($in['body']     ?? ''));
$priority = trim((string)($in['priority'] ?? 'normal'));

if ($subject === '') mam_fail('missing_field', 400, ['field' => 'subject']);
if ($body === '')    mam_fail('missing_field', 400, ['field' => 'body']);

try {
    $result = mam_ticket_create((int)$u['id'], $category, $subject, $body, $priority);
    mam_notify((int)$u['id'], 'ticket_opened', 'Support ticket opened', 'Ref: ' . $result['reference'] . '. We\'ll respond within 24 hours.');
    mam_ok($result);
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
}
