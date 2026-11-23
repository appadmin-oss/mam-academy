<?php
/**
 * GET  /api/calendar/mine — the calendars this person can subscribe to, with
 *      links for any already issued (reads only).
 * POST /api/calendar/mine {kind, rotate?} — issue one (or rotate: the old link
 *      stops working). A person can only issue feeds about themselves.
 */
declare(strict_types=1);
$u = mam_session_user();
if (!$u) mam_fail('authentication_required', 401);
$kinds = mam_cal_my_feed_kinds($u);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;
    $kind = (string) ($in['kind'] ?? '');
    if (!in_array($kind, $kinds, true)) mam_fail('not_allowed', 403);
    !empty($in['rotate']) ? mam_cal_feed_rotate($kind, (int) $u['id'], (int) $u['id']) : mam_cal_feed_mint($kind, (int) $u['id'], (int) $u['id']);
}
$out = [];
foreach ($kinds as $k) {
    $row = mam_cal_feed_row($k, (int) $u['id']);
    $out[] = ['kind' => $k, 'label' => ['student' => 'My classes', 'guardian' => 'My children\'s classes', 'instructor' => 'My teaching'][$k],
              'links' => $row ? mam_cal_subscribe_links(mam_cal_feed_url($row)) : null];
}
mam_ok(['feeds' => $out]);
