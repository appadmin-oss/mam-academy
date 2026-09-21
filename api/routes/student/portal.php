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

$user = mam_require_role('student');
$me   = (int) $user['id'];

$screen = strtolower(trim((string) ($_GET['screen'] ?? 'home')));
if (!in_array($screen, MAM_SPORTAL_SCREENS, true)) mam_fail('unknown_screen', 404);

switch ($screen) {
    case 'train':      mam_ok(['screen' => 'train']      + mam_sportal_train($me));
    case 'learn':      mam_ok(['screen' => 'learn']      + mam_sportal_learn($me));
    case 'attend':     mam_ok(['screen' => 'attend']     + mam_sportal_attend($me));
    case 'money':      mam_ok(['screen' => 'money']      + mam_sportal_money($me));
    case 'repertoire': mam_ok(['screen' => 'repertoire'] + mam_sportal_repertoire($me));
    case 'more':       mam_ok(['screen' => 'more']       + mam_sportal_more($me));
    case 'home':
    default:           mam_ok(['screen' => 'home']       + mam_sportal_home($me));
}
