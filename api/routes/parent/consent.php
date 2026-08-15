<?php
/**
 * POST /api/parent/consent {student_id, media, photo, data, safeguarding_contact}
 *
 * A guardian records media/photo/data consent and a safeguarding contact for a
 * linked child (Advanced Modernization §8.4). Admins may set it for any student.
 */
declare(strict_types=1);

$u   = mam_require_role('parent', 'admin');
$in  = mam_json_input();
$sid = (int) ($in['student_id'] ?? 0);
if ($sid < 1) mam_fail('validation', 422, ['fields' => ['student_id']]);

$isAdmin = function_exists('mam_user_has_role') ? mam_user_has_role($u, 'admin') : ($u['role'] === 'admin');
if (!$isAdmin && !mam_parent_can_view((int) $u['id'], $sid)) mam_fail('forbidden', 403);

mam_consent_set($sid, [
    'media'                => !empty($in['media']),
    'photo'                => !empty($in['photo']),
    'data'                 => !empty($in['data']),
    'safeguarding_contact' => (string) ($in['safeguarding_contact'] ?? ''),
], (int) $u['id']);

mam_ok(['consent' => mam_consent_get($sid)]);
