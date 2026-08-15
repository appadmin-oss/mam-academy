<?php
/**
 * Student repertoire → recital (Advanced Modernization §1.7).
 *
 *   GET  /api/student/repertoire                                → {repertoire, programme, statuses}
 *   POST /api/student/repertoire {action:'add', title, composer?, status?, notes?}
 *   POST /api/student/repertoire {action:'update', id, title?/composer?/status?/notes?}
 *   POST /api/student/repertoire {action:'delete', id}
 *
 * A student manages only their own repertoire.
 */
declare(strict_types=1);

$u   = mam_require_role('student');
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mam_ok([
        'repertoire' => mam_repertoire_list($uid),
        'programme'  => mam_repertoire_programme($uid),
        'statuses'   => mam_repertoire_statuses(),
    ]);
}

$in     = mam_json_input();
$action = (string) ($in['action'] ?? 'add');
try {
    if ($action === 'add') {
        mam_repertoire_add($uid, (string) ($in['title'] ?? ''), $in['composer'] ?? null, (string) ($in['status'] ?? 'learning'), $in['notes'] ?? null);
    } elseif ($action === 'update') {
        if (!mam_repertoire_update((int) ($in['id'] ?? 0), $uid, $in)) mam_fail('not_found', 404);
    } elseif ($action === 'delete') {
        if (!mam_repertoire_delete((int) ($in['id'] ?? 0), $uid)) mam_fail('not_found', 404);
    } else {
        mam_fail('unknown_action', 400, ['action' => $action]);
    }
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), $e->getMessage() === 'forbidden' ? 403 : 400);
}

mam_ok(['repertoire' => mam_repertoire_list($uid), 'programme' => mam_repertoire_programme($uid)]);
