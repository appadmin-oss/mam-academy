<?php
/**
 * GET /api/calendar/feed?t=TOKEN — a subscribed calendar (calendar-feeds.php).
 * The token is the whole of the credential: hashed at rest, revocable, lapsing.
 * An unknown, revoked or lapsed token is a plain 404, so nothing here is an oracle.
 */
declare(strict_types=1);
$row = mam_cal_feed_resolve((string) ($_GET['t'] ?? ''));
if (!$row) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Not found\n"; if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true); return; }
[, $kind, $subject] = explode(':', (string) $row['scope']) + [null, '', 0];
mam_db_update('calendar_tokens', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
$ics = mam_cal_ics(mam_cal_feed_sessions((string) $kind, (int) $subject), mam_cal_feed_title((string) $kind, (int) $subject));
header_remove('Content-Type');
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="mam-academy.ics"');
header('Cache-Control: private, max-age=900');
echo $ics;
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
