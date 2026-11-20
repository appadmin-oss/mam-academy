<?php
/**
 * Authorised collectors and sign-out (safeguarding.php, v104). Staff only:
 * an admin, or a teacher of the child. A family reads the list through the
 * guardian portal and asks the academy to change it.
 *   GET  /api/collectors?student=N[&session=S] → {collectors, minor, recent, max, collected?}
 *   POST {action:'save', student_id, collectors:[{name, relation?, phone?}]}
 *   POST {action:'collect', student_id, session_id?, collector_id? | other_name + reason}
 */
declare(strict_types=1);
$u = mam_require_user();
if (!mam_user_has_role($u, 'instructor') && !mam_user_has_role($u, 'admin')) mam_fail('forbidden', 403);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sid = (int) ($_GET['student'] ?? 0);
        if ($sid < 1 || !mam_incident_can($u, $sid)) throw new RuntimeException('forbidden');
        $stu = mam_db_row('SELECT name FROM users WHERE id = ?', [$sid]);
        $session = (int) ($_GET['session'] ?? 0);
        $collected = $session > 0 ? (mam_collections_for_session($session)[$sid] ?? null) : null;
        mam_ok(['student' => ['id' => $sid, 'name' => (string) ($stu['name'] ?? '')], 'collectors' => mam_collectors_list($sid),
                'minor' => mam_collect_is_minor($sid), 'recent' => mam_collections_recent($sid), 'max' => MAM_COLLECTORS_MAX,
                'collected' => $collected]);
    }
    $in = mam_json_input();
    $sid = (int) ($in['student_id'] ?? 0);
    switch ((string) ($in['action'] ?? '')) {
        case 'save':
            mam_ok(['collectors' => mam_collectors_save($u, $sid, (array) ($in['collectors'] ?? []))]);
        case 'collect':
            $cid = (int) ($in['collector_id'] ?? 0);
            $sess = (int) ($in['session_id'] ?? 0);
            mam_ok(mam_collection_record($u, $sid, $sess ?: null, $cid ?: null, (string) ($in['other_name'] ?? ''), (string) ($in['reason'] ?? '')));
    }
    mam_fail('unknown_action', 400);
} catch (InvalidArgumentException $e) {
    mam_fail('validation', 422, ['message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    mam_fail($e->getMessage(), 404, ['message' => $e->getMessage() === 'forbidden' ? 'That is not one of your students.' : 'That could not be done.']);
}
