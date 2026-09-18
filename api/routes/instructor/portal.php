<?php
/**
 * GET /api/instructor/portal?screen=home|register|marking|teachbacks|students|earnings
 *   → the payload for ONE screen of the instructor portal v2.
 *
 * One request per screen rather than one per panel: the design is mobile-first
 * and its readers are on Nigerian mobile data, where six round trips to paint
 * a dashboard is the difference between a screen that appears and one that
 * unfolds.
 *
 * `?session=N` selects which register. Omitted, it resolves to the next
 * session the caller teaches today, which is what the Home screen's "Take the
 * register" hands over.
 */
declare(strict_types=1);

$user = mam_require_role('instructor', 'admin');
$me   = (int) $user['id'];

$screen = strtolower(trim((string) ($_GET['screen'] ?? 'home')));
if (!in_array($screen, MAM_IPORTAL_SCREENS, true)) mam_fail('unknown_screen', 404);

switch ($screen) {
    case 'register':
        $sid = (int) ($_GET['session'] ?? 0);
        if ($sid <= 0) {
            // Fall back to the next session today — the Home card's own target.
            $home = mam_iportal_home($me);
            $sid = (int) ($home['next']['id'] ?? 0);
        }
        if ($sid <= 0) mam_ok(['screen' => 'register', 'session' => null, 'roster' => []]);
        $data = mam_iportal_register($me, $sid);
        if (!$data) mam_fail('forbidden', 403);
        mam_ok(['screen' => 'register'] + $data);

    case 'marking':    mam_ok(['screen' => 'marking']    + mam_iportal_marking($me));
    case 'teachbacks': mam_ok(['screen' => 'teachbacks'] + mam_iportal_teachbacks($me));
    case 'students':   mam_ok(['screen' => 'students', 'students' => mam_iportal_students($me)]);
    case 'earnings':   mam_ok(['screen' => 'earnings']   + mam_iportal_earnings($me));
    default:           mam_ok(['screen' => 'home']       + mam_iportal_home($me));
}
