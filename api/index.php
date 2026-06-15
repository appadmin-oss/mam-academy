<?php
/**
 * MAM Academy API
 * ─────────────────────────────────────────────────────────────
 * Single entry point. The bootstrap loads .env, libraries, CORS
 * and structured logging. The router below declares every route
 * with its HTTP method, handler file, and middleware chain.
 *
 * Routes resolve from the `route` query parameter set by the
 * project root .htaccess rewrite (/api/foo → /api/index.php?route=foo).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/router.php';

/** Helper: include a route file as the request handler. The router calls the
 *  returned closure with the captured path params, so route files (invoice,
 *  policy, certificate, …) can read them as $params['name']. */
$f = function (string $path): callable {
    return function ($params = []) use ($path) { require __DIR__ . '/routes/' . $path; };
};

$r = new MamRouter();

/* ─── Public ─── */
$r->get('/health',  $f('health.php'));
$r->get('/csrf',    $f('csrf-token.php'));
$r->get('/diag',    $f('diag.php'));
// First-run installer (self-disables once any user exists). Token-guarded via
// SETUP_TOKEN env when set. No CSRF: it's a one-time bootstrap before any login.
$r->get ('/setup', $f('setup.php'), [mw_rate_limit('setup', 30, 600)]);
$r->post('/setup', $f('setup.php'), [mw_rate_limit('setup', 10, 600)]);
// Booking-fee status — public (no PII surfaced). /book polls this to decide
// whether to show the $5 CTA or the unlocked Google appointment embed.
$r->get('/booking/status',  $f('booking-status.php'),  [mw_rate_limit('booking_status', 60, 60)]);
$r->get('/booking/pricing', $f('booking-pricing.php'), [mw_rate_limit('booking_pricing', 60, 60)]);
// Public, admin-driven pricing/config — lets the front-end show live programme
// prices & fees without a redeploy (server stays authoritative at charge time).
$r->get('/config/public',   $f('config-public.php'),   [mw_rate_limit('config_public', 120, 60)]);

// Academic session resolved from the calendar — applied silently by the form.
$r->get('/session/current', $f('session-current.php'), [mw_rate_limit('session_current', 120, 60)]);

// Public academic catalogue (programmes, levels, courses) — drives the apply
// flow and the student "add a course" picker.
$r->get('/courses', $f('courses.php'), [mw_rate_limit('courses', 120, 60)]);
// Course week-by-week outline (programme plan + authored modules).
$r->get('/course/outline', $f('course-outline.php'), [mw_rate_limit('course_outline', 120, 60)]);

// Web-viewable invoice + signed policy. Replace the .html attachments that
// most mail clients render as source code with proper standalone pages
// (applicants click the link in the email and view in their browser, where
// "Save as PDF" + Print just work).
$r->get('/invoice/:ref', $f('invoice.php'), [mw_rate_limit('invoice', 30, 60)]);
$r->get('/policy/:ref',  $f('policy.php'),  [mw_rate_limit('policy',  30, 60)]);
// Web-viewable, printable course/programme certificate (issued on a pass).
$r->get('/certificate/:ref', $f('certificate.php'), [mw_rate_limit('certificate', 30, 60)]);

/* ─── Store / Library (public catalogue) ─── */
$r->get ('/store/items',       $f('store-list.php'),     [mw_rate_limit('store_list', 60, 60)]);
$r->get ('/store/item/:slug',  $f('store-detail.php'),   [mw_rate_limit('store_detail', 60, 60)]);
$r->post('/store/checkout',    $f('store-checkout.php'), [mw_csrf(), mw_rate_limit('store_checkout', 10, 60)]);

/* ─── Public forms (CSRF + rate-limited) ─── */
$r->post('/application', $f('application.php'), [mw_csrf(), mw_rate_limit('apply',       10, 600)]);
// Public applicant status lookup (reference + matching email; rate-limited).
$r->get ('/application/status', $f('application/status.php'), [mw_rate_limit('app_status', 30, 600)]);
// Server-side resumable application drafts + magic-link (A1).
$r->get ('/application/draft',       $f('application/draft.php'),       [mw_rate_limit('app_draft',  240, 600)]);
$r->post('/application/draft',       $f('application/draft.php'),       [mw_csrf(), mw_rate_limit('app_draft', 240, 600)]);
$r->post('/application/resume-link', $f('application/resume-link.php'), [mw_csrf(), mw_rate_limit('app_resume', 6, 600)]);
$r->post('/newsletter',  $f('newsletter.php'),  [mw_csrf(), mw_rate_limit('newsletter',  5,  600)]);
// Unsubscribe is reached from email links / mail-client one-click — no CSRF
// cookie available, so it relies on a signed token instead. Both verbs map
// to the same handler (GET = human click page, POST = RFC 8058 one-click).
$r->get ('/newsletter/unsubscribe', $f('newsletter-unsubscribe.php'), [mw_rate_limit('unsubscribe', 60, 600)]);
$r->post('/newsletter/unsubscribe', $f('newsletter-unsubscribe.php'), [mw_rate_limit('unsubscribe', 60, 600)]);
$r->post('/contact',     $f('contact.php'),     [mw_csrf(), mw_rate_limit('contact',     5,  600)]);

/* ─── Payments ─── */
$r->group('/payment', function (MamRouter $r) use ($f) {
    $r->post('/initialize', $f('payment-init.php'),   [mw_csrf(), mw_rate_limit('payment_init',   20, 600)]);
    $r->post('/verify',     $f('payment-verify.php'), [mw_csrf(), mw_rate_limit('payment_verify', 30, 600)]);
    // Webhooks rely on HMAC signatures, not CSRF cookies.
    $r->post('/webhook/paystack',    $f('webhook-paystack.php'));
    $r->post('/webhook/flutterwave', $f('webhook-flutterwave.php'));
});

/* ─── Auth ─── */
$r->group('/auth', function (MamRouter $r) use ($f) {
    $r->get ('/me',                $f('auth/me.php'));
    $r->post('/login',             $f('auth/login.php'),            [mw_csrf(), mw_rate_limit('login',          8,  600)]);
    $r->post('/logout',            $f('auth/logout.php'),           [mw_csrf()]);
    $r->post('/change-password',   $f('auth/change-password.php'),  [mw_csrf(), mw_user()]);
    $r->post('/request-reset',     $f('auth/request-reset.php'),    [mw_csrf(), mw_rate_limit('reset_request',  5,  600)]);
    $r->post('/perform-reset',     $f('auth/perform-reset.php'),    [mw_csrf(), mw_rate_limit('reset_perform', 10, 600)]);
});

/* ─── Student portal ─── */
$r->group('/student', function (MamRouter $r) use ($f) {
    $r->get ('/dashboard',           $f('student/dashboard.php'),           [mw_role('student')]);
    $r->get ('/courses',             $f('student/courses.php'),             [mw_role('student')]);
    $r->post('/add-course',          $f('student/add-course.php'),          [mw_csrf(), mw_role('student'), mw_rate_limit('add_course', 30, 600)]);
    $r->post('/update-profile',      $f('student/update-profile.php'),      [mw_csrf(), mw_role('student')]);
    $r->post('/practice-log',        $f('student/practice-log.php'),        [mw_csrf(), mw_role('student')]);
    $r->post('/assignment-complete', $f('student/assignment-complete.php'), [mw_csrf(), mw_role('student')]);
    // Per-component gradebook breakdown for one of the student's own courses.
    $r->get ('/assessments',         $f('student/assessments.php'),         [mw_role('student')]);
    // AI progress note (provider-agnostic gateway; off unless ai_provider set).
    $r->get ('/ai-narrative',        $f('student/ai-narrative.php'),        [mw_role('student'), mw_rate_limit('ai_narrative', 20, 600)]);
    // Cohort session attendance (LMS L2 dual-read view).
    $r->get ('/session-attendance',  $f('student/session-attendance.php'),  [mw_role('student')]);
    // Submissions against assignments (LMS L2).
    $r->post('/submit',              $f('student/submit.php'),              [mw_csrf(), mw_role('student'), mw_rate_limit('submit', 60, 600)]);
    $r->get ('/submissions',         $f('student/submissions.php'),         [mw_role('student')]);
});

/* ─── Instructor portal ─── */
$r->group('/instructor', function (MamRouter $r) use ($f) {
    $r->get ('/dashboard',        $f('instructor/dashboard.php'),       [mw_role('instructor', 'admin')]);
    $r->get ('/student',          $f('instructor/student.php'),         [mw_role('instructor', 'admin')]);
    $r->post('/mark-attendance',  $f('instructor/mark-attendance.php'), [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/update-profile',   $f('instructor/update-profile.php'),  [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/announce',         $f('instructor/announce.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/resource',         $f('instructor/resource.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/assignment',        $f('instructor/assignment.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/assignment-delete', $f('instructor/assignment-delete.php'), [mw_csrf(), mw_role('instructor', 'admin')]);
    // LMS gradebook — weekly weighted assessments → auto-computed results.
    $r->get ('/gradebook',        $f('instructor/gradebook.php'),       [mw_role('instructor', 'admin')]);
    $r->post('/assess',           $f('instructor/assess.php'),          [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('assess', 120, 600)]);
    // LMS L2 — cohort scheduling + session attendance.
    $r->get ('/sessions',           $f('instructor/sessions.php'),           [mw_role('instructor', 'admin')]);
    $r->post('/sessions',           $f('instructor/sessions.php'),           [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->get ('/session-attendance', $f('instructor/session-attendance.php'), [mw_role('instructor', 'admin')]);
    $r->post('/session-attendance', $f('instructor/session-attendance.php'), [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('sess_att', 240, 600)]);
    // Submissions review + feedback (LMS L2) + AI grading assist (L4).
    $r->get ('/submissions',        $f('instructor/submissions.php'),     [mw_role('instructor', 'admin')]);
    $r->post('/feedback',           $f('instructor/feedback.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/feedback-draft',     $f('instructor/feedback-draft.php'),  [mw_csrf(), mw_role('instructor', 'admin'), mw_rate_limit('ai_draft', 30, 600)]);
});

/* ─── Admin (JSON endpoints; the HTML dashboard is at /admin/) ─── */
$r->group('/admin', function (MamRouter $r) use ($f) {
    // RBAC: sensitive control is super-only; operational endpoints allow managers.
    $r->get ('/list-users',      $f('admin/list-users.php'),     [mw_admin_cap('users')]);
    $r->post('/create-user',     $f('admin/create-user.php'),    [mw_csrf(), mw_admin_cap('users')]);
    $r->post('/reset-password',  $f('admin/reset-password.php'), [mw_csrf(), mw_admin_cap('users')]);
    $r->post('/create-class',    $f('admin/create-class.php'),   [mw_csrf(), mw_admin_cap('enrollments')]);
    $r->post('/enroll-student',  $f('admin/enroll-student.php'), [mw_csrf(), mw_admin_cap('enrollments')]);
    $r->post('/mail-test',       $f('admin/mail-test.php'),      [mw_csrf(), mw_admin_cap('mail'), mw_rate_limit('mail_test', 10, 600)]);
    // Outbound-mail outbox: viewing is operational; re-sending is super-only.
    $r->get ('/mail-log',        $f('admin/mail-log.php'),       [mw_admin_cap('view')]);
    $r->post('/mail-retry',      $f('admin/mail-retry.php'),     [mw_csrf(), mw_admin_cap('mail'), mw_rate_limit('mail_retry', 30, 600)]);
    $r->post('/migrate',         $f('admin/migrate.php'),        [mw_csrf(), mw_admin_cap('migrate'), mw_rate_limit('migrate', 5, 300)]);
    // Store catalogue admin CRUD (one file dispatches by method).
    $r->get ('/store/items',     $f('admin/store-items.php'),    [mw_admin_cap('view')]);
    $r->post('/store/items',     $f('admin/store-items.php'),    [mw_csrf(), mw_admin_cap('store_catalog')]);
    // LMS course-registration approvals + instructor assignment (operational).
    $r->get ('/course-enrollments', $f('admin/course-enrollments.php'), [mw_admin_cap('enrollments')]);
    $r->post('/course-enrollments', $f('admin/course-enrollments.php'), [mw_csrf(), mw_admin_cap('enrollments')]);
    // Assign admin tiers (super-only).
    $r->post('/set-admin-tier',  $f('admin/set-admin-tier.php'), [mw_csrf(), mw_admin_cap('rbac')]);
});

/* ─── Dispatch ─── */
$route  = '/' . trim((string)($_GET['route'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$r->dispatch($method, $route);
