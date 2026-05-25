<?php
declare(strict_types=1);

$u = mam_current_user();
mam_session_destroy();
if ($u) mam_audit('logout', 'user', (int)$u['id']);
mam_ok(['logged_out' => true]);
