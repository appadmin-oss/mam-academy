<?php
/**
 * GET /api/student/portal?screen=home|train|learn|attend|more|money|repertoire
 *   → the payload for ONE screen of the student portal v2.
 *
 * One request per screen rather than one per panel: the design is mobile-first
 * and its readers are on Nigerian mobile data, where six round trips to paint
 * a dashboard is the difference between a screen that appears and one that
 * unfolds. Mirrors GET /api/instructor/portal exactly.
 *
 * ⚠️ Every screen name in MAM_SPORTAL_SCREENS must have a `case` here. A name
 * that passes the unknown-screen check and then falls through to `default`
 * renders the HOME payload under another screen's heading — silently, because
 * the client has something to draw. That shipped once on the instructor portal
 * (`report`), and `test_student_portal.php` now asserts every name dispatches.
 */
declare(strict_types=1);

// Session times leave with their +01:00 offset (session-time.php, UX quick win 9).
$mamPortalOk = fn(array $p) => mam_ok(mam_session_iso_payload($p));

$user = mam_require_role('student');
$me   = (int) $user['id'];

$screen = strtolower(trim((string) ($_GET['screen'] ?? 'home')));
if (!in_array($screen, MAM_SPORTAL_SCREENS, true)) mam_fail('unknown_screen', 404);

switch ($screen) {
    case 'train':      $mamPortalOk(['screen' => 'train']      + mam_sportal_train($me));
    case 'learn':      $mamPortalOk(['screen' => 'learn']      + mam_sportal_learn($me));
    case 'attend':     $mamPortalOk(['screen' => 'attend']     + mam_sportal_attend($me));
    case 'money':      $mamPortalOk(['screen' => 'money']      + mam_sportal_money($me));
    case 'repertoire': $mamPortalOk(['screen' => 'repertoire'] + mam_sportal_repertoire($me));
    case 'more':       $mamPortalOk(['screen' => 'more']       + mam_sportal_more($me));
    case 'card':       $mamPortalOk(['screen' => 'card']       + mam_sportal_card($me));
    case 'home':
    default:           $mamPortalOk(['screen' => 'home']       + mam_sportal_home($me));
}
