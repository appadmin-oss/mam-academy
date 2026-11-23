<?php
/** GET /api/calendar/session?id=N — one session as an .ics download, for someone it concerns. */
declare(strict_types=1);
$u = mam_session_user();
if (!$u) mam_fail('authentication_required', 401);
$id = (int) ($_GET['id'] ?? 0);
$s = mam_db_row("SELECT cs.*, co.title AS cohort_title, r.name AS room_name,
                        (SELECT COUNT(*) FROM session_history h WHERE h.session_id = cs.id AND h.field_changed = 'scheduled_at') AS moves
                   FROM class_sessions cs JOIN cohorts co ON co.id = cs.cohort_id LEFT JOIN rooms r ON r.id = cs.room_id WHERE cs.id = ?", [$id]);
if (!$s || !mam_cal_user_may_see($u, $id)) mam_fail('not_found', 404);
header_remove('Content-Type');
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="mam-session-' . $id . '.ics"');
echo mam_cal_ics([$s], 'MAM Academy');
if (!defined('MAM_RESPONSE_SENT')) define('MAM_RESPONSE_SENT', true);
