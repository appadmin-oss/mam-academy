<?php
/**
 * GET /api/admin/upper — Upper Admin strategic intelligence (§34-35).
 *
 *   ?dimension=programme|instrument|grade|instructor|student
 *   &program_id=&course_id=&level_id=&instructor_id=&student_id=
 *
 * Returns the scoped headline, the breadcrumb back to Academy, the next
 * drill-down level and §35's instructor comparison. Read-only.
 *
 * Gated on the `settings` capability, which in this system means a super admin:
 * §34 is management intelligence across every instructor's performance, not a
 * day-to-day operations screen.
 */
declare(strict_types=1);

// Access is already enforced by mw_admin_cap('settings') on the route, which is
// the stronger check. Calling mam_require_role('admin') here as well would add
// nothing except a mam_require_user() that 401s a Basic-Auth break-glass
// operator, who legitimately has no session.

$dimension = isset($_GET['dimension']) ? (string) $_GET['dimension'] : null;
$filter    = mam_upper_filter($_GET);

mam_ok(['upper' => mam_upper_dashboard($filter, $dimension)]);
