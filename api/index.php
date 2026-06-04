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

/** Helper: include a route file as the request handler. */
$f = function (string $path): callable {
    return function () use ($path) { require __DIR__ . '/routes/' . $path; };
};

$r = new MamRouter();

/* ─── Public ─── */
$r->get('/health',  $f('health.php'));
$r->get('/csrf',    $f('csrf-token.php'));
$r->get('/diag',    $f('diag.php'));
// First-run installer (self-disables once any user exists). Token-guarded via
// SETUP_TOKEN env when set. No CSRF: it's a one-time bootstrap before any login.
$r->get ('/setup', $f('setup.php'), [mw_rate_limit('setup', 30, 600)]);
$r->post('/setup', $f('setup.php'), [mw_rate_limit('setup', 10, 600, true)]);
// Booking-fee status — public (no PII surfaced). /book polls this to decide
// whether to show the $5 CTA or the unlocked Google appointment embed.
$r->get('/booking/status',  $f('booking-status.php'),  [mw_rate_limit('booking_status', 60, 60)]);
$r->get('/booking/pricing', $f('booking-pricing.php'), [mw_rate_limit('booking_pricing', 60, 60)]);
// Public, admin-driven pricing/config — lets the front-end show live programme
// prices & fees without a redeploy (server stays authoritative at charge time).
$r->get('/config/public',   $f('config-public.php'),   [mw_rate_limit('config_public', 120, 60)]);

// Academic session resolved from the calendar — applied silently by the form.
$r->get('/session/current', $f('session-current.php'), [mw_rate_limit('session_current', 120, 60)]);

// Web-viewable invoice + signed policy. Replace the .html attachments that
// most mail clients render as source code with proper standalone pages
// (applicants click the link in the email and view in their browser, where
// "Save as PDF" + Print just work).
$r->get('/invoice/:ref', $f('invoice.php'), [mw_rate_limit('invoice', 30, 60)]);
$r->get('/policy/:ref',  $f('policy.php'),  [mw_rate_limit('policy',  30, 60)]);

/* ─── Store / Library (public catalogue) ─── */
$r->get ('/store/items',       $f('store-list.php'),     [mw_rate_limit('store_list', 60, 60)]);
$r->get ('/store/item/:slug',  $f('store-detail.php'),   [mw_rate_limit('store_detail', 60, 60)]);
$r->post('/store/checkout',    $f('store-checkout.php'), [mw_csrf(), mw_rate_limit('store_checkout', 10, 60, true)]);

/* ─── Public forms (CSRF + rate-limited) ─── */
$r->post('/application', $f('application.php'), [mw_csrf(), mw_rate_limit('apply',       10, 600)]);
$r->post('/newsletter',  $f('newsletter.php'),  [mw_csrf(), mw_rate_limit('newsletter',  5,  600)]);
// Unsubscribe is reached from email links / mail-client one-click — no CSRF
// cookie available, so it relies on a signed token instead. Both verbs map
// to the same handler (GET = human click page, POST = RFC 8058 one-click).
$r->get ('/newsletter/unsubscribe', $f('newsletter-unsubscribe.php'), [mw_rate_limit('unsubscribe', 60, 600)]);
$r->post('/newsletter/unsubscribe', $f('newsletter-unsubscribe.php'), [mw_rate_limit('unsubscribe', 60, 600)]);
$r->post('/contact',     $f('contact.php'),     [mw_csrf(), mw_rate_limit('contact',     5,  600)]);

/* ─── Payments ─── */
$r->group('/payment', function (MamRouter $r) use ($f) {
    $r->post('/initialize', $f('payment-init.php'),   [mw_csrf(), mw_rate_limit('payment_init',   20, 600, true)]);
    $r->post('/verify',     $f('payment-verify.php'), [mw_csrf(), mw_rate_limit('payment_verify', 30, 600, true)]);
    // Webhooks rely on HMAC signatures, not CSRF cookies.
    $r->post('/webhook/paystack',    $f('webhook-paystack.php'));
    $r->post('/webhook/flutterwave', $f('webhook-flutterwave.php'));
});

/* ─── Auth ─── */
$r->group('/auth', function (MamRouter $r) use ($f) {
    $r->get ('/me',                $f('auth/me.php'));
    $r->post('/login',             $f('auth/login.php'),            [mw_csrf(), mw_rate_limit('login',          8,  600, true)]);
    $r->post('/logout',            $f('auth/logout.php'),           [mw_csrf()]);
    $r->post('/change-password',   $f('auth/change-password.php'),  [mw_csrf(), mw_user()]);
    $r->post('/request-reset',     $f('auth/request-reset.php'),    [mw_csrf(), mw_rate_limit('reset_request',  5,  600, true)]);
    $r->post('/perform-reset',     $f('auth/perform-reset.php'),    [mw_csrf(), mw_rate_limit('reset_perform', 10, 600, true)]);
});

/* ─── Student portal ─── */
$r->group('/student', function (MamRouter $r) use ($f) {
    $r->get ('/dashboard',           $f('student/dashboard.php'),           [mw_role('student')]);
    $r->post('/update-profile',      $f('student/update-profile.php'),      [mw_csrf(), mw_role('student')]);
    $r->post('/practice-log',        $f('student/practice-log.php'),        [mw_csrf(), mw_role('student')]);
    $r->post('/assignment-complete', $f('student/assignment-complete.php'), [mw_csrf(), mw_role('student')]);
});

/* ─── Instructor portal ─── */
$r->group('/instructor', function (MamRouter $r) use ($f) {
    $r->get ('/dashboard',        $f('instructor/dashboard.php'),       [mw_role('instructor', 'admin')]);
    $r->get ('/student',          $f('instructor/student.php'),         [mw_role('instructor', 'admin')]);
    $r->post('/mark-attendance',  $f('instructor/mark-attendance.php'), [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/update-profile',   $f('instructor/update-profile.php'),  [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/announce',         $f('instructor/announce.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/resource',         $f('instructor/resource.php'),        [mw_csrf(), mw_role('instructor', 'admin')]);
    $r->post('/assignment',       $f('instructor/assignment.php'),      [mw_csrf(), mw_role('instructor', 'admin')]);
});

/* ─── Admin (JSON endpoints; the HTML dashboard is at /admin/) ─── */
$r->group('/admin', function (MamRouter $r) use ($f) {
    $r->get ('/list-users',      $f('admin/list-users.php'),     [mw_admin()]);
    $r->post('/create-user',     $f('admin/create-user.php'),    [mw_csrf(), mw_admin()]);
    $r->post('/reset-password',  $f('admin/reset-password.php'), [mw_csrf(), mw_admin()]);
    $r->post('/create-class',    $f('admin/create-class.php'),   [mw_csrf(), mw_admin()]);
    $r->post('/enroll-student',  $f('admin/enroll-student.php'), [mw_csrf(), mw_admin()]);
    $r->post('/mail-test',       $f('admin/mail-test.php'),      [mw_csrf(), mw_admin(), mw_rate_limit('mail_test', 10, 600)]);
    $r->post('/migrate',         $f('admin/migrate.php'),        [mw_csrf(), mw_admin(), mw_rate_limit('migrate', 5, 300)]);
    // Store catalogue admin CRUD (one file dispatches by method).
    $r->get ('/store/items',     $f('admin/store-items.php'),    [mw_admin()]);
    $r->post('/store/items',     $f('admin/store-items.php'),    [mw_csrf(), mw_admin()]);
});

/* ─── AI assistant · "Ask MAM" (provider-agnostic gateway) ─── */
// One endpoint, four personas (site/student/practice/admin); the handler does
// per-context access control. CSRF + a per-IP rate limit here.
$r->post('/ai/chat', $f('ai-chat.php'), [mw_csrf(), mw_rate_limit('ai_chat', 30, 600, true)]);

/* ─── Practice Studio · Klangio transcription (auth + CSRF + rate-limited) ─── */
// Audio→notation. POST submits a clip; GET polls status / streams the result.
// Requires a logged-in user because it spends a paid third-party quota.
$r->post('/transcribe', $f('transcribe.php'), [mw_csrf(), mw_user(), mw_rate_limit('transcribe',      12, 600, true)]);
$r->get ('/transcribe', $f('transcribe.php'), [mw_user(),            mw_rate_limit('transcribe_poll', 180, 60)]);

/* ─── Dispatch ─── */
$route  = '/' . trim((string)($_GET['route'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$r->dispatch($method, $route);
