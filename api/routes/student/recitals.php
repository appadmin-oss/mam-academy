<?php
/**
 * GET  /api/student/recitals            → open calls, my submissions, my repertoire
 * POST /api/student/recitals {action:'propose', recital_id, repertoire_id|title, …}
 * POST /api/student/recitals {action:'withdraw', item_id}
 *
 * The student's side of the Recital Hall: see what is open, put a polished
 * piece forward, and track what happened to it.
 */
declare(strict_types=1);

$u   = mam_require_role('student');
$uid = (int) $u['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $in     = mam_json_input();
    $action = (string) ($in['action'] ?? '');

    if ($action === 'propose') {
        try {
            $id = mam_recital_propose((int) ($in['recital_id'] ?? 0), $uid, $in);
            mam_ok(['item_id' => $id]);
        } catch (InvalidArgumentException $e) {
            mam_fail('validation', 422, ['reason' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            $m = $e->getMessage();
            mam_fail($m, $m === 'not_found' ? 404 : ($m === 'not_your_piece' ? 403 : 409));
        }
    }

    if ($action === 'withdraw') {
        try {
            mam_ok(['withdrawn' => mam_recital_withdraw((int) ($in['item_id'] ?? 0), $uid)]);
        } catch (RuntimeException $e) {
            mam_fail($e->getMessage(), $e->getMessage() === 'not_found' ? 404 : 403);
        }
    }

    mam_fail('unknown_action', 422);
}

// Only POLISHED or PERFORMED pieces may be offered — a recital is not the
// place to debut something still being learned, and the repertoire tab is
// where a student marks that transition. Offering the whole list would invite
// a student to put forward a piece they cannot yet play in public.
$eligible = array_values(array_filter(
    mam_repertoire_list($uid),
    fn($p) => in_array((string) $p['status'], ['polished', 'performed'], true)
));

// The public card deliberately carries no row id — a logged-out visitor has no
// use for one. A signed-in student does: it is what they propose against. So
// the id is added back here rather than widening the public shape.
$calls = array_map(function (array $r) {
    return ['id' => (int) $r['id'], 'submissions_close_at' => $r['submissions_close_at']] + mam_recital_public_card($r);
}, mam_recital_open_calls());

mam_ok([
    'open_calls'  => $calls,
    'submissions' => mam_recital_for_student($uid),
    'eligible'    => $eligible,
]);
