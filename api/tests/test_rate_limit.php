<?php
/**
 * Rate limiting — and the difference between counting requests and counting
 * failures, which is what locked real people out of the live site.
 *
 * The login limiter counted REQUESTS. Eight sign-ins from one address in ten
 * minutes returned 429 whether or not a single password was wrong — and the key
 * is the IP, so on carrier-grade NAT (most Nigerian mobile traffic) or any
 * office connection those eight are shared by unrelated people. A family with
 * two children on two devices could lock out the building.
 *
 * The brute-force property has to survive the fix, so both directions are
 * asserted: a guesser is still stopped, and somebody who knows their password
 * is never charged for it.
 */
declare(strict_types=1);

t_group('rate limit — requests vs failures');

$rlKey = 'rl-' . bin2hex(random_bytes(4));

// The request counter, unchanged, and still right for a public form: there the
// request itself is the cost.
for ($i = 0; $i < 3; $i++) mam_rate_limit('rl_req', $rlKey, 3, 600);
$hit = false;
try { mam_rate_limit('rl_req', $rlKey, 3, 600); } catch (Throwable $e) { $hit = str_contains($e->getMessage(), 'rate_limited'); }
t_ok($hit, 'a request counter still throws once the budget is spent');

// The failure counter: checking must never consume budget, or it is a request
// counter wearing a different name.
$k2 = 'rl2-' . bin2hex(random_bytes(4));
for ($i = 0; $i < 50; $i++) mam_rate_limit_check('rl_fail', $k2, 3);
t_ok(true, 'fifty checks with no failures recorded do not trip the limit');
t_eq((int) (mam_db_row('SELECT COUNT(*) c FROM rate_limits WHERE bucket = ?',
    ['rl_fail:' . sha1($k2)])['c'] ?? 0), 0, 'and write no row at all — a success leaves no trace to forgive');

// Failures accumulate, and the check fires exactly at the limit.
mam_rate_limit_record('rl_fail', $k2, 600);
mam_rate_limit_record('rl_fail', $k2, 600);
mam_rate_limit_check('rl_fail', $k2, 3);
t_ok(true, 'two failures out of three is still allowed through');
mam_rate_limit_record('rl_fail', $k2, 600);
$blocked = false; $retry = null;
try { mam_rate_limit_check('rl_fail', $k2, 3); }
catch (Throwable $e) { $blocked = str_contains($e->getMessage(), 'rate_limited'); }
t_ok($blocked, 'the third failure closes the door');

// A success while locked out must not forgive anything: an attacker holding one
// valid credential could otherwise reset the counter between guesses.
mam_rate_limit_check('rl_fail', 'someone-else-entirely', 3);
$still = false;
try { mam_rate_limit_check('rl_fail', $k2, 3); } catch (Throwable $e) { $still = true; }
t_ok($still, 'and nothing a different key does reopens it');

// The window runs from the FIRST failure, so a paced guesser cannot hold a
// bucket open forever by spacing attempts out.
$row = mam_db_row('SELECT count, reset_at FROM rate_limits WHERE bucket = ?', ['rl_fail:' . sha1($k2)]);
t_eq((int) $row['count'], 3, 'the count is the number of failures, nothing else');
$before = (int) $row['reset_at'];
mam_rate_limit_record('rl_fail', $k2, 600);
$after = (int) mam_db_row('SELECT reset_at FROM rate_limits WHERE bucket = ?', ['rl_fail:' . sha1($k2)])['reset_at'];
t_eq($after, $before, 'a further failure does not extend the window');

// An elapsed window forgives on its own.
mam_db()->prepare('UPDATE rate_limits SET reset_at = ? WHERE bucket = ?')->execute([time() - 1, 'rl_fail:' . sha1($k2)]);
mam_rate_limit_check('rl_fail', $k2, 3);
t_ok(true, 'once the window elapses the budget is fresh again');

t_group('rate limit — the login route counts failures');

// The wiring, asserted at the route rather than trusted: this is the line that
// was wrong, and it is one word away from being wrong again.
$idx = (string) file_get_contents(dirname(__DIR__) . '/index.php');
t_ok(preg_match("#post\('/login',.*mw_rate_limit_failures\('login', *8\)#", $idx) === 1,
    'POST /api/auth/login throttles FAILURES, not requests');
t_ok(!preg_match("#post\('/login',.*mw_rate_limit\('login'#", $idx),
    'and no longer charges a correct password to the budget');

$login = (string) file_get_contents(dirname(__DIR__) . '/routes/auth/login.php');
t_ok(str_contains($login, "mam_rate_limit_check('login_email'"),
    'the per-account throttle checks without consuming');
t_ok(preg_match('#if \(!\$user\) \{.*?mam_rate_limit_record\(\'login_email\'.*?mam_rate_limit_record\(\'login\'#s', $login) === 1,
    'and both buckets are charged only where authentication actually failed');
// Ordering matters: a fresh install answers `setup_required`, which is not a
// failed guess and must not cost anybody their budget.
t_ok(strpos($login, 'setup_required') < strpos($login, "mam_rate_limit_record('login_email'"),
    'setup_required is answered before any failure is recorded');

t_group('rate limit — what the person locked out is told');

$pj = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/portal.js');
t_ok(str_contains($pj, 'retry_after'),
    'the portal uses the server\'s own retry_after rather than guessing');
$lh = (string) file_get_contents(dirname(__DIR__, 2) . '/portal/login.html');
t_ok(str_contains($lh, 'retry_after') && str_contains($lh, 'Too many failed attempts'),
    'and so does the sign-in page, saying failed attempts — not attempts');

t_group('the signing key must be STABLE, and say so when it is not');

/* ⚠️ The failure this guards is the worst silent state the app has. When
   APP_KEY is unset AND storage/.app_key cannot be written, mam_app_key()
   returns a freshly generated key to EVERY process. Nothing signed in one
   request verifies in the next:

     · every CSRF token is rejected, so the whole site's forms stop
       submitting with `csrf_invalid` and no other symptom;
     · attendance QR tokens, faculty invite links, application draft keys and
       unsubscribe links all become unverifiable.

   MEASURED with the persist blocked: three consecutive processes minted three
   unrelated keys. It reads as a front-end bug, which is why the detection has
   to be a predicate the health route and the console can report. */

t_ok(function_exists('mam_app_key_persisted'),
    'there is a predicate for whether the signing key survives the request');
t_ok(mam_app_key_persisted(),
    'the suite runs with a stable key — if this fails, every other CSRF assertion is meaningless');

/* A length check is NOT the test. An ephemeral key is a perfectly well-formed
   64 characters, so `strlen() >= 32` reports healthy in exactly the state
   where nothing works — which is what /api/health used to do. */
$SK_health = (string) @file_get_contents(MAM_API_ROOT . '/routes/health.php');
t_ok(str_contains($SK_health, 'mam_app_key_persisted()'),
    'the health route reports the key\'s STABILITY, not merely its length');
t_ok(!preg_match("/'csrf'\s*=>\s*strlen\(mam_app_key\(\)\)\s*>=\s*32\s*,/", $SK_health),
    '…and no longer reports csrf healthy on a length check alone');

/* The console surfaces it where an admin looking for "why are the forms
   broken" will actually be. */
$SK_con = (string) @file_get_contents(MAM_API_ROOT . '/modules/admin/lib/console.php');
t_ok(str_contains($SK_con, "'signing'"), 'the console health card carries the signing state');
t_ok(str_contains($SK_con, 'mam_app_key_persisted'), '…derived from the predicate, not re-implemented');
$SK_sys = (string) @file_get_contents(dirname(MAM_API_ROOT) . '/partials/admin-console/system.php');
t_ok(str_contains($SK_sys, "signing"), 'the System screen renders it');

/* ⚠️ No fallback to a shared directory. This key signs attendance and
   invitation tokens; on shared hosting a key in a world-writable /tmp is a
   worse failure than a broken form. An unwritable storage directory is an
   operator fix, not something this function may route around. */
$SK_csrf = (string) @file_get_contents(MAM_API_ROOT . '/modules/kernel/lib/csrf.php');
/* Comments stripped first: the reason for the rule is written in this very
   file, and a test that cannot tell the explanation from the implementation
   forbids documenting the decision. (Second time this bit — the phone
   subset's WhatsApp guard had the same flaw.) */
$SK_code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $SK_csrf);
t_ok(!preg_match('~sys_get_temp_dir\s*\(~', $SK_code),
    'mam_app_key() never falls back to a shared temp directory for a signing key');
t_ok(str_contains($SK_csrf, 'app_key_not_persisted'),
    'the unpersisted case is logged under its own name, at error');

/* The honest-message rule: the old log line said it had "generated a
   persistent fallback ... so forms work". On the branch where the write
   FAILED that sentence is false, and it is the sentence an operator would
   have found while hunting a broken form. */
$SK_pos = strpos($SK_csrf, 'app_key_not_persisted');
t_ok($SK_pos !== false && !str_contains(substr($SK_csrf, $SK_pos, 600), 'so forms work'),
    'the failure branch does not claim forms work');
