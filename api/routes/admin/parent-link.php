<?php
/**
 * Admin guardian management (PDF §26).
 *   GET  ?student_id=N                                  → {guardians:[…]}
 *   POST {action:'link',   parent_email, student_id, relationship?}
 *   POST {action:'unlink', parent_id, student_id}
 * Linking provisions a parent account if needed (returns a temp password once).
 */
declare(strict_types=1);
// Read access is authorised by mw_admin_cap() on the route. The WRITE path
// below additionally needs a named actor, so it calls
// mam_admin_require_actor() at the point it starts mutating.

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sid = (int) ($_GET['student_id'] ?? 0);
    if ($sid < 1) mam_fail('validation', 422, ['fields' => ['student_id']]);
    mam_ok(['guardians' => mam_db_all(
        "SELECT pl.id, pl.relationship, u.id AS parent_id, u.name, u.email, u.must_change_password
           FROM parent_links pl JOIN users u ON u.id = pl.parent_id
          WHERE pl.student_id = ? ORDER BY u.name",
        [$sid]
    )]);
}

$u = mam_admin_require_actor();   // writes record who performed them

$in     = mam_json_input();
$action = (string) ($in['action'] ?? 'link');
try {
    if ($action === 'link') {
        $email = mam_str($in, 'parent_email', 190);
        $sid   = (int) ($in['student_id'] ?? 0);
        $rel   = mam_str($in, 'relationship', 32) ?: 'guardian';
        if ($email === '' || $sid < 1) mam_fail('validation', 422, ['fields' => ['parent_email', 'student_id']]);
        $res = mam_parent_link($email, $sid, $rel, (int) $u['id']);
        // A guardian account made just now is invited; it used to be made with a
        // password nobody was given.
        $res['invited'] = !empty($res['account_created'])
            && (bool) mam_auth_send_invite((int) $res['parent_id'], 'parent', 'guardian_invite');
        mam_ok(['link' => $res], $res['created'] ? 201 : 200);
    }
    if ($action === 'unlink') {
        $pid = (int) ($in['parent_id'] ?? 0);
        $sid = (int) ($in['student_id'] ?? 0);
        if ($pid < 1 || $sid < 1) mam_fail('validation', 422, ['fields' => ['parent_id', 'student_id']]);
        mam_parent_unlink($pid, $sid);
        mam_ok(['unlinked' => true]);
    }
    mam_fail('unknown_action', 400, ['action' => $action]);
} catch (\InvalidArgumentException $e) {
    mam_fail($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 409);
}
