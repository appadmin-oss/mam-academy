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

/* ─── Public forms (CSRF + rate-limited) ─── */
$r->post('/application', $f('application.php'), [mw_csrf(), mw_rate_limit('apply',       10, 600)]);
$r->post('/newsletter',  $f('newsletter.php'),  [mw_csrf(), mw_rate_limit('newsletter',  5,  600)]);
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
    $r->get ('/dashboard',       $f('student/dashboard.php'),       [mw_role('student')]);
    $r->post('/update-profile',  $f('student/update-profile.php'),  [mw_csrf(), mw_role('student')]);
});

/* ─── Instructor portal ─── */
$r->group('/instructor', function (MamRouter $r) use ($f) {
    $r->get ('/dashboard',        $f('instructor/dashboard.php'),       [mw_role('instructor', 'admin')]);
    $r->post('/mark-attendance',  $f('instructor/mark-attendance.php'), [mw_csrf(), mw_role('instructor', 'admin')]);
});

/* ─── Admin (JSON endpoints; the HTML dashboard is at /admin/) ─── */
$r->group('/admin', function (MamRouter $r) use ($f) {
    $r->get ('/list-users',      $f('admin/list-users.php'),     [mw_admin()]);
    $r->post('/create-user',     $f('admin/create-user.php'),    [mw_csrf(), mw_admin()]);
    $r->post('/reset-password',  $f('admin/reset-password.php'), [mw_csrf(), mw_admin()]);
    $r->post('/create-class',    $f('admin/create-class.php'),   [mw_csrf(), mw_admin()]);
    $r->post('/enroll-student',  $f('admin/enroll-student.php'), [mw_csrf(), mw_admin()]);
});

/* ─── Dispatch ─── */
$route  = '/' . trim((string)($_GET['route'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$r->dispatch($method, $route);
