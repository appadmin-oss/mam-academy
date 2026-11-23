<?php
/**
 * Live online classes (api/lib/live-classes.php, v101).
 *
 *   GET  /api/live/join?session=N           → what the class page needs to put this person in the call
 *   POST /api/live/beat {session}           → "still here", once a minute while the call is open
 *   POST /api/live/register {session}       → staff: work out attendance from the call and write it
 */
declare(strict_types=1);
$u = mam_require_user();
$step = (string) ($GLOBALS['mam_live_step'] ?? 'join');
$say = ['not_found' => [404, 'That class was not found.'], 'not_yours' => [403, 'That is not one of your classes.'],
        'cancelled' => [409, 'That class was cancelled.'], 'not_open_yet' => [409, 'The class opens 15 minutes before it starts.'],
        'over' => [409, 'That class has finished.'], 'jaas_key_invalid' => [500, 'The online class service is not set up correctly. Tell the office.'],
        'live_not_set_up' => [503, 'Live classes are not set up yet. The office adds JaaS keys in the console (Timetable & rooms → Integrations).']];
try {
    if ($step === 'join') mam_ok(['live' => mam_live_join((int) ($_GET['session'] ?? 0), $u)]);
    $in = mam_json_input();
    $sid = (int) ($in['session'] ?? 0);
    if ($step === 'beat') { mam_live_beat($sid, $u); mam_ok(['ok' => true]); }
    $sess = mam_live_session($sid);
    if (!$sess) throw new RuntimeException('not_found');
    if (!in_array(mam_live_role($sess, $u), ['teacher', 'admin'], true)) throw new RuntimeException('not_yours');
    mam_ok(['register' => mam_live_reconcile($sid)]);
} catch (RuntimeException $e) {
    [$st, $msg] = $say[$e->getMessage()] ?? [400, 'That could not be done.'];
    mam_fail($e->getMessage(), $st, ['message' => $msg]);
}
