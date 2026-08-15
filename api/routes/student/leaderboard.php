<?php
/**
 * Opt-in effort leaderboard (Advanced Modernization §3.4).
 *   GET  /api/student/leaderboard              → {opted_in, consent_ok, board}
 *   POST /api/student/leaderboard {opt_in:bool}
 */
declare(strict_types=1);
$u   = mam_require_role('student');
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = mam_json_input();
    mam_leaderboard_optin_set($uid, !empty($in['opt_in']));
}
$optin   = mam_leaderboard_optin_get($uid);
$consent = function_exists('mam_consent_allows') ? mam_consent_allows($uid, 'data') : true;
$board   = ($optin && $consent) ? mam_leaderboard_for_student($uid) : [];
mam_ok(['opted_in' => $optin, 'consent_ok' => $consent, 'board' => $board]);
