<?php
/** POST /api/auth/revoke-others — sign out everywhere except this device. */
declare(strict_types=1);
$u = mam_require_user();
$n = mam_sessions_revoke_others((int)$u['id']);
mam_audit('session_revoke_others', 'user', (int)$u['id'], ['count' => $n]);
mam_ok(['revoked' => $n, 'sessions' => mam_sessions_list((int)$u['id'])]);
