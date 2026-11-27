<?php
/**
 * Safeguarding concerns (api/modules/safeguarding/lib/safeguarding.php, schema v99).
 *
 *   GET  /api/safeguarding/concern  → {categories, mine:[{id, category, created_at, received}], officer_named}
 *   POST {category, body, urgency?, about_id?, ref_type?, ref_id?}  → {id, message}
 *
 * Anybody signed in may raise one. What they raised they see as RECEIVED and
 * nothing more: the outcome is about somebody else.
 */
declare(strict_types=1);

$u = mam_require_user();
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mine = mam_db_all("SELECT id, category, created_at FROM safeguarding_concerns WHERE raised_by = ? ORDER BY id DESC LIMIT 20", [$uid]);
    mam_ok(['categories' => MAM_SG_CATEGORIES, 'mine' => array_map(static fn($r) => $r + ['received' => true], $mine),
            'officer_named' => function_exists('mam_vetting_dso') && mam_vetting_dso() !== null, 'message' => MAM_SG_RECEIVED]);
}

$in = mam_json_input();
try {
    $id = mam_sg_raise($uid, $in);
    mam_ok(['id' => $id, 'message' => MAM_SG_RECEIVED]);
} catch (InvalidArgumentException $e) {
    mam_fail('validation', 422, ['message' => $e->getMessage()]);
}
