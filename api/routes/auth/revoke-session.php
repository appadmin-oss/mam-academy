<?php
/** POST /api/auth/revoke-session {id} — revoke one of the user's sessions (owner-scoped). */
declare(strict_types=1);
$u = mam_require_user();
$id = (string)(mam_json_input()['id'] ?? '');
if ($id === '') mam_fail('missing_id', 422);
$ok = mam_sessions_revoke((int)$u['id'], $id);
mam_audit('session_revoke', 'user', (int)$u['id'], ['ok' => $ok]);
mam_ok(['revoked' => $ok, 'sessions' => mam_sessions_list((int)$u['id'])]);
