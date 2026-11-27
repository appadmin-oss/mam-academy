<?php
/**
 * Student check-in endpoint (LMS 2.0, NGG-style).
 *
 *   GET  /api/student/checkin                    → {dashboard, next_class, config}
 *   POST /api/student/checkin {action:'checkin', session_id?, mode?, lat?, lng?}
 *   POST /api/student/checkin {action:'clock_out', session_id?}
 *
 * When session_id is omitted the server resolves today's session for the
 * student. All gating (enable toggle, enrolment, open window, late grace,
 * geofence) is server-authoritative in api/modules/live/lib/checkin.php. `next_class`
 * carries the §8/§9 preparation panel: topic, resources, preparation and
 * teach-back state, and the 30-minute/15-minute window.
 */
declare(strict_types=1);

$u   = mam_require_user();
$uid = (int) $u['id'];

$statusFor = function (string $code): int {
    switch ($code) {
        case 'invalid_request':                              return 422;
        case 'not_enrolled':                                 return 403;
        case 'session_not_found':                            return 404;
        case 'checkin_disabled':                             return 404;
        case 'location_required':                            return 428; // precondition required
        case 'out_of_range': case 'checkin_not_open':
        case 'checkin_closed': case 'session_unscheduled':
        case 'not_checked_in':                               return 409;
        case 'invalid_mode':                                 return 422;
        default:                                             return 400;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cfg = mam_checkin_config();
    mam_ok([
        'dashboard'    => mam_checkin_dashboard($uid),
        'next_class'   => mam_checkin_next_class($uid),
        'achievements' => function_exists('mam_ach_evaluate') ? mam_ach_evaluate($uid) : [],
        'config'       => [
            'self_enabled'     => $cfg['self_enabled'],
            'geofence_enabled' => $cfg['geofence_enabled'],
            'late_grace_min'   => $cfg['late_grace_min'],
            'open_before_min'  => $cfg['open_before_min'],
            'ready_before_min' => $cfg['ready_before_min'],
            'modes'            => MAM_ATTENDANCE_MODES,
        ],
    ]);
}

// ── POST ─────────────────────────────────────────────────────────────────
$in     = mam_json_input();
$action = (string) ($in['action'] ?? 'checkin');
if (!in_array($action, ['checkin', 'clock_out'], true)) mam_fail('unknown_action', 400, ['action' => $action]);

$sessionId = (int) ($in['session_id'] ?? 0);
if ($sessionId < 1) {
    $today = mam_checkin_session_on($uid);
    if (!$today) mam_fail('no_session_today', 404);
    $sessionId = (int) $today['id'];
}

try {
    if ($action === 'clock_out') {
        $res = mam_checkin_clock_out($uid, $sessionId);
        mam_ok(['clock_out' => $res, 'dashboard' => mam_checkin_dashboard($uid), 'next_class' => mam_checkin_next_class($uid)]);
    }
    $lat  = isset($in['lat']) && $in['lat'] !== '' ? (float) $in['lat'] : null;
    $lng  = isset($in['lng']) && $in['lng'] !== '' ? (float) $in['lng'] : null;
    $mode = (string) ($in['mode'] ?? 'onsite');
    $res  = mam_checkin_self($uid, $sessionId, $lat, $lng, $mode);
    mam_ok(['checkin' => $res, 'dashboard' => mam_checkin_dashboard($uid), 'next_class' => mam_checkin_next_class($uid)]);
} catch (\RuntimeException $e) {
    $code = $e->getMessage();
    mam_fail($code, $statusFor($code));
}
