<?php
/**
 * Class spaces (api/lib/spaces.php, schema v99). One route for every role; the
 * library decides, per call, what this person may see and do.
 *
 *   GET  /api/spaces                         → {spaces}           every space this person can open
 *   GET  /api/spaces?id=N[&before=M][&task=T]→ {space, tasks, feed, members}
 *   GET  /api/spaces?queue=1                 → {queue}            held items and work to mark (staff)
 *   POST {action:'post', space_id, kind, body, media_id?, task_id?}
 *   POST {action:'comment', post_id, body, at_sec?}
 *   POST {action:'react', post_id, kind, on}
 *   POST {action:'report', type:'post'|'comment', id, reason, note?}
 *   POST {action:'remove', post_id, note?}
 *   POST {action:'moderate', type, id, decision:'approve'|'remove', note?}   staff
 *   POST {action:'review', post_id, score, feedback}                       staff
 *   POST {action:'task', space_id, id?, title, brief, wants, share, points, due_at, status?}  staff
 *   POST {action:'settings', space_id, title?, about?, visibility?, review_media?, students_comment?, status?}  staff
 *   POST {action:'create', cohort_id? | title, visibility}                  staff (a teacher only for a class they teach)
 *   POST {action:'members_add', space_id, user_ids, role}                  staff
 *   POST {action:'member_remove', space_id, user_id}                       staff
 */
declare(strict_types=1);

$u = mam_require_user();
$uid = (int) $u['id'];

$refuse = static function (Throwable $e): void {
    if ($e instanceof InvalidArgumentException) mam_fail('validation', 422, ['message' => $e->getMessage()]);
    $c = $e->getMessage();
    $map = ['not_found' => [404, 'That was not found.'], 'forbidden' => [403, 'You cannot do that in this space.'],
            'file_not_ready' => [409, 'That file has not finished uploading.']];
    [$st, $msg] = $map[$c] ?? [400, 'That could not be done.'];
    if (!isset($map[$c])) mam_log('error', 'spaces_failed', ['err' => $c]);
    mam_fail($c === 'not_found' || $c === 'forbidden' ? $c : 'failed', $st, ['message' => $msg]);
};

$space = static function (int $id) use ($u): array {
    $s = $id > 0 ? mam_space_get($id) : null;
    if (!$s || !mam_space_role($s, $u)) mam_fail('not_found', 404, ['message' => 'That space was not found.']);
    return $s;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if (!empty($_GET['queue'])) mam_ok(['queue' => mam_space_queue($u)]);
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) { mam_space_ensure_for_user($u); mam_ok(['spaces' => mam_space_list_for($u)]); }
        $s = $space($id);
        $role = (string) mam_space_role($s, $u);
        $feed = mam_space_feed($s, $u, (int) ($_GET['before'] ?? 0), 20, (int) ($_GET['task'] ?? 0));
        if (!empty($_GET['before'])) mam_ok(['feed' => $feed]);
        mam_ok(['space' => mam_space_summary($s, $role, $uid), 'tasks' => mam_space_tasks($s, $u, $role), 'feed' => $feed,
                'members' => mam_space_members($s), 'can_post' => !in_array($role, ['guardian'], true) && $s['status'] === 'open',
                'needs_consent' => $role === 'student' && mam_space_needs_media_consent($uid)]);
    } catch (Throwable $e) { $refuse($e); }
}

$in = mam_json_input();
$action = (string) ($in['action'] ?? '');
try {
    switch ($action) {
        case 'post':
            mam_ok(mam_space_post($space((int) ($in['space_id'] ?? 0)), $u, $in));
        case 'comment':
            mam_ok(mam_space_comment((int) ($in['post_id'] ?? 0), $u, (string) ($in['body'] ?? ''),
                isset($in['at_sec']) && is_numeric($in['at_sec']) ? (float) $in['at_sec'] : null));
        case 'react':
            mam_space_react((int) ($in['post_id'] ?? 0), $u, (string) ($in['kind'] ?? ''), !empty($in['on']));
            mam_ok(['done' => true]);
        case 'report':
            mam_ok(mam_space_report($u, ($in['type'] ?? '') === 'comment' ? 'comment' : 'post', (int) ($in['id'] ?? 0),
                (string) ($in['reason'] ?? 'other'), (string) ($in['note'] ?? '')));
        case 'remove':
            mam_space_post_remove((int) ($in['post_id'] ?? 0), $u, (string) ($in['note'] ?? ''));
            mam_ok(['done' => true]);
        case 'moderate':
            mam_space_moderate($u, ($in['type'] ?? '') === 'comment' ? 'comment' : 'post', (int) ($in['id'] ?? 0),
                (string) ($in['decision'] ?? ''), (string) ($in['note'] ?? ''));
            mam_ok(['done' => true]);
        case 'review':
            mam_space_review((int) ($in['post_id'] ?? 0), $u, (int) ($in['score'] ?? -1), (string) ($in['feedback'] ?? ''));
            mam_ok(['done' => true]);
        case 'task':
            mam_ok(['id' => mam_space_task_save($space((int) ($in['space_id'] ?? 0)), $in, $u)]);
        case 'settings':
            $s = $space((int) ($in['space_id'] ?? 0));
            if (!mam_space_is_staff(mam_space_role($s, $u))) throw new RuntimeException('forbidden');
            mam_space_update((int) $s['id'], $in, $uid);
            mam_ok(['space' => mam_space_summary(mam_space_get((int) $s['id']), (string) mam_space_role($s, $u), $uid)]);
        case 'create':
            $isAdmin = mam_user_has_role($u, 'admin');
            if (!$isAdmin && !mam_user_has_role($u, 'instructor')) throw new RuntimeException('forbidden');
            $cid = (int) ($in['cohort_id'] ?? 0);
            if ($cid > 0) {
                if (!$isAdmin && !mam_space_teaches_cohort($cid, $uid)) throw new InvalidArgumentException('You can open a space only for a class you teach.');
                mam_ok(['id' => mam_space_for_cohort($cid, $uid)]);
            }
            $id = mam_space_create((string) ($in['title'] ?? ''), 'private', $uid, null, (string) ($in['about'] ?? ''));
            // The teacher who opens a private space is its first member, so they can then add their students.
            if (!$isAdmin) mam_db_insert('space_members', ['space_id' => $id, 'user_id' => $uid, 'role' => 'teacher', 'status' => 'active',
                'added_by' => $uid, 'created_at' => gmdate('Y-m-d H:i:s')]);
            mam_ok(['id' => $id]);
        case 'members_add':
            mam_ok(mam_space_members_add($space((int) ($in['space_id'] ?? 0)), (array) ($in['user_ids'] ?? []),
                (string) ($in['role'] ?? 'student'), $u));
        case 'member_remove':
            mam_space_member_remove($space((int) ($in['space_id'] ?? 0)), (int) ($in['user_id'] ?? 0), $u);
            mam_ok(['done' => true]);
    }
    mam_fail('unknown_action', 400);
} catch (Throwable $e) { $refuse($e); }
