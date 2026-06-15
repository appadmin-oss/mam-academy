<?php
/** GET /api/auth/sessions — the signed-in user's active sessions (current flagged). */
declare(strict_types=1);
$u = mam_require_user();
mam_ok(['sessions' => mam_sessions_list((int)$u['id'])]);
