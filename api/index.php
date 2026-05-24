<?php
/**
 * MAM Academy API router
 *
 * Routes are dispatched from the `route` query parameter populated by
 * the project root .htaccess rewrite (/api/foo → /api/index.php?route=foo).
 * Each route lives in /api/routes/<name>.php and includes the bootstrap.
 *
 * Available routes:
 *   GET   /api/health
 *   GET   /api/csrf
 *   POST  /api/application
 *   POST  /api/payment/initialize
 *   POST  /api/payment/verify
 *   POST  /api/payment/webhook/paystack
 *   POST  /api/payment/webhook/flutterwave
 *   POST  /api/newsletter
 *   POST  /api/contact
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$route = $_GET['route'] ?? '';
$route = trim($route, '/');

// Whitelist routes → file map
$map = [
    'health'                       => 'health.php',
    'csrf'                         => 'csrf-token.php',
    'application'                  => 'application.php',
    'payment/initialize'           => 'payment-init.php',
    'payment/verify'               => 'payment-verify.php',
    'payment/webhook/paystack'     => 'webhook-paystack.php',
    'payment/webhook/flutterwave'  => 'webhook-flutterwave.php',
    'newsletter'                   => 'newsletter.php',
    'contact'                      => 'contact.php',

    // Auth
    'auth/login'                   => 'auth/login.php',
    'auth/logout'                  => 'auth/logout.php',
    'auth/me'                      => 'auth/me.php',
    'auth/change-password'         => 'auth/change-password.php',

    // Student portal
    'student/dashboard'            => 'student/dashboard.php',
    'student/update-profile'       => 'student/update-profile.php',

    // Instructor portal
    'instructor/dashboard'         => 'instructor/dashboard.php',
    'instructor/mark-attendance'   => 'instructor/mark-attendance.php',

    // Admin (JSON; UI lives at /admin/)
    'admin/list-users'             => 'admin/list-users.php',
    'admin/create-user'            => 'admin/create-user.php',
    'admin/reset-password'         => 'admin/reset-password.php',
    'admin/create-class'           => 'admin/create-class.php',
    'admin/enroll-student'         => 'admin/enroll-student.php',
];

if (!isset($map[$route])) {
    mam_fail('route_not_found', 404, ['route' => $route]);
}

require __DIR__ . '/routes/' . $map[$route];
