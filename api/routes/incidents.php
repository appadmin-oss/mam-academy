<?php
/**
 * Incidents for a teacher (safeguarding.php, v103).
 *   GET  /api/incidents?student=N        → {incidents, kinds}
 *   POST {action:'record', student_id, kind, what, happened_at?, place?, action_taken?, family_note?}
 *   POST {action:'tell', id} · {action:'close', id, outcome}
 */
declare(strict_types=1);
$u = mam_require_user();
if (!mam_user_has_role($u, 'instructor') && !mam_user_has_role($u, 'admin')) mam_fail('forbidden', 403);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sid = (int) ($_GET['student'] ?? 0);
        if ($sid < 1 || !mam_incident_can($u, $sid)) throw new RuntimeException('forbidden');
        mam_ok(['incidents' => mam_incident_list($u, $sid, 'all'), 'kinds' => MAM_INCIDENT_KINDS]);
    }
    $in = mam_json_input();
    switch ((string) ($in['action'] ?? '')) {
        case 'record': mam_ok(['id' => mam_incident_record($u, $in)]);
        case 'tell': mam_ok(mam_incident_tell($u, (int) ($in['id'] ?? 0)));
        case 'close': mam_incident_close($u, (int) ($in['id'] ?? 0), (string) ($in['outcome'] ?? '')); mam_ok(['done' => true]);
    }
    mam_fail('unknown_action', 400);
} catch (InvalidArgumentException $e) {
    mam_fail('validation', 422, ['message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $m = ['officer_decides' => 'The safeguarding officer decides who is told and when it closes.', 'forbidden' => 'That is not one of your students.', 'not_found' => 'That incident was not found.'];
    mam_fail($e->getMessage(), $e->getMessage() === 'officer_decides' ? 409 : 404, ['message' => $m[$e->getMessage()] ?? 'That could not be done.']);
}
