<?php
/** GET /api/student/makeup — eligible make-up slots for missed sessions (§8.2). */
declare(strict_types=1);
$u = mam_require_role('student');
mam_ok(['suggestions' => mam_makeup_suggestions((int) $u['id'])]);
