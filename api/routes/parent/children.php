<?php
/** GET /api/parent/children — the guardian's linked children + weekly status. */
declare(strict_types=1);
$u = mam_require_role('parent');
mam_ok(['children' => mam_parent_children((int) $u['id'])]);
