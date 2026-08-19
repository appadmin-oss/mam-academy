<?php
/**
 * Tiny zero-dependency test runner for the MAM API.
 *
 * Boots the library layer against a throwaway SQLite DB (no Composer, no
 * PHPUnit — matches the project's buildless philosophy) and runs every
 * api/tests/test_*.php. Exits non-zero on any failure so CI fails the build.
 *
 *   php api/tests/run.php
 */
declare(strict_types=1);

// CLI only. Refuse to run over HTTP so `GET /api/tests/run.php` can't execute
// the suite (info disclosure / remote code execution of the harness) on any
// host that serves this directory despite the .htaccess deny.
if (PHP_SAPI !== 'cli') { http_response_code(403); header('Content-Type: text/plain'); echo "Forbidden: CLI only.\n"; exit(1); }

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$ROOT = dirname(__DIR__);                       // .../api
define('MAM_API_ROOT', $ROOT);
$tmp = sys_get_temp_dir() . '/mam-tests-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($tmp, 0775, true);
@mkdir($tmp . '/logs', 0775, true);
define('MAM_STORAGE', $tmp);
define('MAM_LOGS', $tmp . '/logs');
register_shutdown_function(function () use ($tmp) { @array_map('unlink', glob("$tmp/logs/*") ?: []); @array_map('unlink', glob("$tmp/*") ?: []); @rmdir("$tmp/logs"); @rmdir($tmp); });

// ---- Settable env (tests can inject keys/secrets) --------------------------
$GLOBALS['__env'] = ['APP_KEY' => str_repeat('k', 48)]; // deterministic CSRF key
function mam_env($key, $default = null) { return $GLOBALS['__env'][$key] ?? $default; }
function mam_test_env(array $kv) { foreach ($kv as $k => $v) $GLOBALS['__env'][$k] = $v; }

// ---- Stubs the libs expect from bootstrap (kept harmless for tests) --------
// mam_fail/mam_ok normally exit; here they THROW so an unexpected error path
// fails a test loudly instead of silently killing the runner.
function mam_fail($error, $status = 400, $extra = []) { throw new RuntimeException("mam_fail:$error:$status"); }
function mam_ok($data = [], $status = 200) { throw new RuntimeException('mam_ok:unexpected'); }
function mam_log(...$a) {}
function mam_audit(...$a) {}
function mam_client_ip() { return '127.0.0.1'; }
// Real impl (bootstrap.php) defers to after the HTTP response; in tests we
// no-op so mint-trigger helpers that queue an email (cards.php) are exercisable
// without attempting to send mail.
if (!function_exists('mam_after_response')) { function mam_after_response(callable $fn): void {} }

// ---- Library under test ----------------------------------------------------
require $ROOT . '/lib/db.php';
require $ROOT . '/lib/settings.php';
require $ROOT . '/lib/academic-session.php'; // term-window engine (needs settings.php above)
require $ROOT . '/lib/practice.php';        // practice-log aggregation
require $ROOT . '/lib/fx.php';
require $ROOT . '/lib/money.php';           // currency-safe money normalization (v61)
require $ROOT . '/lib/validation.php';      // pricing engine lives here
require $ROOT . '/lib/booking-pricing.php';
require $ROOT . '/lib/payments.php';        // shared payment settlement (verify + webhooks)
require $ROOT . '/lib/csrf.php';
require $ROOT . '/lib/rate-limit.php';    // request and FAILURE throttles
require $ROOT . '/lib/paystack.php';
require $ROOT . '/lib/flutterwave.php';
require $ROOT . '/lib/lms.php';
require $ROOT . '/lib/curriculum-seed.php'; // bundled starter syllabus (data for the seeder)
require $ROOT . '/lib/session.php';         // cookie/DB sessions + session management
require $ROOT . '/lib/auth.php';            // account provisioning + role checks
require $ROOT . '/lib/store.php';           // store totals (server-authoritative)
require $ROOT . '/lib/mailer.php';          // mail helpers + outbox (record/retry)
require $ROOT . '/lib/ai.php';              // provider-agnostic AI gateway
require $ROOT . '/lib/admissions.php';      // applicant status lookup
require $ROOT . '/lib/enrol-complete.php'; // one-action enrolment completion
require $ROOT . '/lib/vetting.php';        // instructor vetting + safeguarding gate
require $ROOT . '/lib/calendar.php';       // the academic calendar as data
require $ROOT . '/lib/dataprotection.php'; // NDPA compliance layer
require $ROOT . '/lib/v2/readmodel.php';   // v2 read model (fails closed when unconfigured)
require $ROOT . '/lib/invoicing.php';       // application invoice lifecycle (auto-invoice + expiry)
require $ROOT . '/lib/jobs.php';            // automation spine (events + jobs)
require $ROOT . '/lib/funnel.php';          // first-party funnel analytics
require $ROOT . '/lib/notifications.php';   // in-app notification centre
require $ROOT . '/lib/analytics.php';       // learning analytics (read-only)
require $ROOT . '/lib/datarights.php';      // data-subject export + erasure
require $ROOT . '/lib/studio.php';          // studio leaderboard (opt-in)
require $ROOT . '/lib/auditer.php';         // MAM Auditer placement validity
require $ROOT . '/lib/wallet.php';          // internal credit ledger (#12)
require $ROOT . '/lib/reschedule.php';      // paid class rescheduling (LMS 2.0 Part C)
require $ROOT . '/lib/lms2.php';            // LMS 2.0 teaching helpers (Parts B/D)
require $ROOT . '/lib/attendance.php';      // QR self-check-in (#9/#10)
require $ROOT . '/lib/checkin.php';         // NGG-style student check-in (LMS 2.0)
require $ROOT . '/lib/accountability.php';  // punctuality charges ledger (LMS 2.0)
require $ROOT . '/lib/preclass.php';        // pre-class learning + teach-back (LMS 2.0)
require $ROOT . '/lib/meetings.php';        // instructor clock-in + Meet/Jitsi reconciliation
require $ROOT . '/lib/parents.php';         // parent accounts + guardian portal (LMS 2.0)
require $ROOT . '/lib/analytics2.php';      // LMS 2.0 academic analytics (upper-admin)
require $ROOT . '/lib/academic-emails.php'; // event-driven academic email engine (§28)
require $ROOT . '/lib/drive.php';           // Google Drive gateway via Apps Script (#5)
require $ROOT . '/lib/scholarship.php';     // MAM Scholars Initiative (§2c)
require $ROOT . '/lib/faculty.php';         // instructor applications + invite tokens
require $ROOT . '/lib/automations.php';     // lifecycle automation toggles
require $ROOT . '/lib/support.php';         // feedback, surveys, support tickets
require $ROOT . '/lib/progress.php';        // lesson records + parent progress tokens
require $ROOT . '/lib/progress-reports.php'; // post-session progress reports + fines (v46)
require $ROOT . '/lib/idempotency.php';      // idempotency cache helpers (v51)
require $ROOT . '/lib/promo.php';            // promotional email banner helpers (v55)
require $ROOT . '/lib/uploads.php';      // first-party headshot storage
require $ROOT . '/lib/cards.php';            // ID card mint/verify/revoke/email (v56)
require $ROOT . '/lib/achievements.php';     // badges + guardian wins feed (§3.1/§3.5)
require $ROOT . '/lib/consent.php';           // consent & safeguarding center (§8.4)
require $ROOT . '/lib/repertoire.php';         // repertoire → recital (§1.7)
require $ROOT . '/lib/challenges.php';        // practice challenges (§3.3)
require $ROOT . '/lib/audit.php';             // audit-log timeline (§8.5)
require $ROOT . '/lib/referrals.php';         // referral program (§6.3)
require $ROOT . '/lib/leaderboard.php';       // opt-in effort leaderboards (§3.4)
require $ROOT . '/lib/makeup.php';            // make-up class suggestions (§8.2)
require $ROOT . '/lib/bi.php';                // admin business intelligence (§5.4)
require $ROOT . '/lib/inline-edit.php';       // allowlisted inline field editing (§4.4)
require $ROOT . '/lib/recital.php';           // the Recital Hall
require $ROOT . '/lib/mastery.php';           // mastery scale + MAM Mastery Score (PDF §23-24)
require $ROOT . '/lib/academic-record.php';   // unified student academic record (PDF §43)
require $ROOT . '/lib/upper-admin.php';       // upper-admin strategic intelligence (PDF §34-35)
require $ROOT . '/lib/reports.php';           // ratified termly reports + moderation + appeals (v85)

require __DIR__ . '/assert.php';

echo "MAM API test suite (PHP " . PHP_VERSION . ")\n\n";
foreach (glob(__DIR__ . '/test_*.php') as $f) { require $f; }
mam_test_summary();
