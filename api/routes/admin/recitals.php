<?php
/**
 * GET  /api/admin/recitals                → all recitals + proposal counts
 * GET  /api/admin/recitals?id=N           → one recital, every item, the running order
 * POST /api/admin/recitals {action:…}
 *
 * Staff curation. Actions: create, update, status, decide, reorder, add_item.
 * The 'curriculum' capability owns this — programming a recital is an academic
 * judgement about what a student is ready to perform, not an office task.
 */
declare(strict_types=1);

$u  = mam_require_user();
if (!mam_admin_can($u, 'curriculum')) mam_fail('forbidden', 403);
$by = (int) $u['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $in     = mam_json_input();
    $action = (string) ($in['action'] ?? '');

    try {
        if ($action === 'create') {
            mam_ok(['id' => mam_recital_create($in, $by)]);
        }
        if ($action === 'update') {
            mam_ok(['updated' => mam_recital_update((int) ($in['id'] ?? 0), $in, $by)]);
        }
        if ($action === 'status') {
            mam_ok(['changed' => mam_recital_set_status((int) ($in['id'] ?? 0), (string) ($in['status'] ?? ''), $by)]);
        }
        if ($action === 'decide') {
            mam_ok(['decided' => mam_recital_decide((int) ($in['item_id'] ?? 0), (string) ($in['decision'] ?? ''), $by, $in['note'] ?? null)]);
        }
        if ($action === 'reorder') {
            mam_ok(['moved' => mam_recital_reorder((int) ($in['id'] ?? 0), (array) ($in['order'] ?? []), $by)]);
        }
        if ($action === 'add_item') {
            // Staff can place a piece directly — a teacher who knows what a
            // student should play does not need them to submit a form first.
            $rid = (int) ($in['recital_id'] ?? 0);
            $sid = (int) ($in['student_id'] ?? 0);
            if ($rid < 1 || $sid < 1) mam_fail('validation', 422, ['fields' => ['recital_id', 'student_id']]);
            $r = mam_recital($rid);
            if (!$r) mam_fail('not_found', 404);
            $title = trim((string) ($in['title'] ?? ''));
            if ($title === '') mam_fail('validation', 422, ['fields' => ['title']]);
            $itemId = mam_db_insert('recital_items', [
                'recital_id'   => $rid,
                'student_id'   => $sid,
                'title'        => mb_substr($title, 0, 200),
                'composer'     => isset($in['composer']) && $in['composer'] !== '' ? mb_substr(trim((string) $in['composer']), 0, 160) : null,
                'instrument'   => isset($in['instrument']) && $in['instrument'] !== '' ? mb_substr(trim((string) $in['instrument']), 0, 80) : null,
                'duration_min' => max(1, min(60, (int) ($in['duration_min'] ?? 4))),
                'status'       => 'proposed',
            ]);
            // Placed by staff means it is already a yes; decide() does the
            // positioning and the notification, so it is not duplicated here.
            mam_recital_decide($itemId, 'accepted', $by, null);
            mam_ok(['item_id' => $itemId]);
        }
    } catch (InvalidArgumentException $e) {
        mam_fail('validation', 422, ['reason' => $e->getMessage()]);
    } catch (RuntimeException $e) {
        $m = $e->getMessage();
        mam_fail($m, $m === 'not_found' ? 404 : 409);
    }
    mam_fail('unknown_action', 422);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $r = mam_recital($id);
    if (!$r) mam_fail('not_found', 404);
    $p = mam_recital_programme_full($id);
    mam_ok([
        'recital'     => $r,
        'items'       => mam_recital_items($id),
        'running'     => $p['items'],
        'total_min'   => $p['total_min'],
        'ends_at'     => $p['ends_at'],
        'transitions' => mam_recital_transitions()[(string) $r['status']] ?? [],
    ]);
}

mam_ok(['recitals' => mam_recital_admin_list()]);
