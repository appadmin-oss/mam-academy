<?php
/**
 * GET /api/instructor/portal?screen=home|register|marking|teachbacks|report|students|earnings|more
 *   → the payload for ONE screen of the instructor portal v2.
 *
 * One request per screen rather than one per panel: the design is mobile-first
 * and its readers are on Nigerian mobile data, where six round trips to paint
 * a dashboard is the difference between a screen that appears and one that
 * unfolds.
 *
 * `?session=N` selects which register, `?student=N` which termly report.
 * Omitted, the register resolves to the next
 * session the caller teaches today, which is what the Home screen's "Take the
 * register" hands over.
 */
declare(strict_types=1);

// Session times leave with their +01:00 offset (session-time.php, UX quick win 9).
$mamPortalOk = fn(array $p) => mam_ok(mam_session_iso_payload($p));

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
        if ($sid <= 0) $mamPortalOk(['screen' => 'register', 'session' => null, 'roster' => []]);
        $data = mam_iportal_register($me, $sid);
        if (!$data) mam_fail('forbidden', 403);
        $mamPortalOk(['screen' => 'register'] + $data);

    case 'report':
        /* ⚠️ 'report' was in MAM_IPORTAL_SCREENS from the start, so it passed
           the unknown-screen check and then fell through to `default` — the
           Termly report screen silently received the HOME payload. A screen
           name that validates and does not dispatch is worse than one that
           404s, because the client renders something. */
        $sid = (int) ($_GET['student'] ?? 0);
        $data = mam_iportal_report($me, $sid);
        if (!$data) mam_fail('forbidden', 403);
        $mamPortalOk(['screen' => 'report'] + $data);

    case 'more':
        /* The More sheet's badges come from the queues themselves rather than a
           count endpoint of their own, so the sheet cannot disagree with the
           screens it links to. */
        $mamPortalOk([
            'screen'     => 'more',
            'marking'    => count(mam_iportal_marking($me)['queue']),
            'teachbacks' => count(mam_iportal_teachbacks($me)['queue']),
        ]);

    case 'marking':    $mamPortalOk(['screen' => 'marking']    + mam_iportal_marking($me));
    case 'teachbacks': $mamPortalOk(['screen' => 'teachbacks'] + mam_iportal_teachbacks($me));
    case 'students':   $mamPortalOk(['screen' => 'students', 'students' => mam_iportal_students($me)]);
    case 'earnings':   $mamPortalOk(['screen' => 'earnings']   + mam_iportal_earnings($me));
    default:           $mamPortalOk(['screen' => 'home']       + mam_iportal_home($me));
}
