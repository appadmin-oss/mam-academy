<?php
/**
 * POST /api/parent/thread-post {thread_id, body}            — reply.
 * POST /api/parent/thread-post {student_id, subject, kind, body} — open + first message.
 *
 * A `safeguarding` message does not open a thread: it is raised as a concern
 * with the named safeguarding officer (safeguarding.php), who is told at once.
 * A thread would make it look answered when it is only filed.
 */
declare(strict_types=1);
$u  = mam_require_role('parent');
$in = mam_json_input();
$body = trim((string) ($in['body'] ?? ''));
if ($body === '') mam_fail('validation', 422, ['fields' => ['body']]);

try {
    $tid = (int) ($in['thread_id'] ?? 0);
    if ($tid < 1) {
        $sid  = (int) ($in['student_id'] ?? 0);
        $subj = trim((string) ($in['subject'] ?? ''));
        $kind = (string) ($in['kind'] ?? 'general');
        if ($sid < 1 || $subj === '') mam_fail('validation', 422, ['fields' => ['student_id', 'subject']]);
        if ($kind === 'safeguarding') {
            // Not a message queue: it goes to the safeguarding officer as a concern (safeguarding.php).
            if (!mam_parent_can_view((int) $u['id'], $sid)) mam_fail('forbidden', 403);
            $cid = mam_sg_raise((int) $u['id'], ['category' => 'wellbeing', 'about_id' => $sid, 'ref_type' => 'user', 'ref_id' => $sid,
                'body' => $subj . "\n\n" . $body]);
            mam_ok(['concern_id' => $cid, 'message' => MAM_SG_RECEIVED]);
        }
        if (!mam_parent_scope_allows((int) $u['id'], $sid, 'messages_staff')) mam_fail('forbidden', 403);
        $tid = mam_thread_open($sid, $subj, $kind, (int) $u['id']);
    }
    mam_ok(['thread_id' => $tid, 'message_id' => mam_thread_post($tid, (int) $u['id'], $body)]);
} catch (\InvalidArgumentException $e) { mam_fail('validation', 422, ['reason' => $e->getMessage()]); }
catch (\RuntimeException $e) { mam_fail($e->getMessage(), $e->getMessage() === 'forbidden' ? 403 : 409); }
