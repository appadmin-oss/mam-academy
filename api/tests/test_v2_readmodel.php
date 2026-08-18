<?php
/**
 * The v2 read model — option B of the cutover readiness assessment.
 *
 * The whole design rests on two properties, and the tests that matter are the
 * ones that try to break them:
 *
 *   1. It cannot write. Not "does not" — cannot, checked by attempting it.
 *   2. It fails CLOSED and never falls back to v1. A figure attributed to the
 *      wrong database is worse than a missing figure, because the reader has no
 *      way to tell.
 */
declare(strict_types=1);

t_group('v2 read model — unconfigured, it is absent rather than empty');

// The suite runs with no V2_READ_* env, which is the state of every install that
// has not run the load. Nothing may throw, and nothing may answer.
t_ok(mam_v2_rm_configured() === false, 'no pointer configured in the test environment');
$rmS = mam_v2_rm_status();
t_ok($rmS['configured'] === false, 'the status says so');
t_ok($rmS['available'] === false, 'and that it cannot answer');
t_eq($rmS['error'], 'not_configured', 'naming the reason rather than looking broken');
t_eq($rmS['counts'], [], 'with no counts invented');
t_eq(count(mam_v2_rm_people()), 0, 'people reads empty');
t_eq(count(mam_v2_rm_money()['rows']), 0, 'money reads empty');
t_eq(mam_v2_rm_money()['total_minor'], 0, 'and totals zero rather than null-erroring');
t_eq(count(mam_v2_rm_resolve('ANYTHING')), 0, 'resolve reads empty');
t_eq(count(mam_v2_rm_merge_review()), 0, 'and so does the merge review');
t_ok(mam_v2_rm_db() === null, 'no handle is opened');

t_group('v2 read model — a real snapshot, read through the public API');

/**
 * Build a genuine v2 database with the real loader, then read it back the way
 * the admin screen does. Fixtures, not the production export.
 */
$rmPath = sys_get_temp_dir() . '/mam-rm-test-' . getmypid() . '.db';
@unlink($rmPath);
$rmBuild = new PDO('sqlite:' . $rmPath);
$rmBuild->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rmBuild->exec('PRAGMA foreign_keys = ON');
$rmBuild->exec((string) file_get_contents(dirname(__DIR__, 2) . '/db/schema-v2-sqlite.sql'));

// v2l_source() lives in test_v2_load.php, which the runner loads alphabetically
// before this file. Rebuilt here rather than depended on, so the order cannot
// silently matter.
$rmSrc = [
    'customers' => [
        ['id' => 1, 'email' => 'shared@example.test', 'first_name' => 'Ngozi', 'last_name' => 'Eze',
         'created_at' => '2026-02-01 09:00:00'],
        ['id' => 2, 'email' => 'solo@example.test', 'first_name' => 'Solo', 'last_name' => 'Applicant',
         'created_at' => '2026-02-02 09:00:00'],
    ],
    'users' => [
        // v1 states the link explicitly (`customer_id`), and the two rows
        // DISAGREE about the name — a married name, a shortened one. The link
        // wins, so this merges, and the discarded name has to be captured at
        // merge time because v2 keeps only the winner.
        //
        // Note this is NOT the guardian-applying-for-a-child shape: that one
        // shares an address with NO explicit link, and identity resolution now
        // refuses to merge it (see test_v2_plan.php). Fusing a learner into a
        // staff record is the one identity mistake with no way back.
        ['id' => 1, 'email' => 'shared@example.test', 'name' => 'Amaka Eze', 'role' => 'student',
         'status' => 'active', 'customer_id' => 1, 'password_hash' => '$2y$10$rmtestrmtestrmtestrm',
         'enrollment_ref' => 'MAM-RM-1', 'created_at' => '2026-02-01 10:00:00'],
    ],
    'applications' => [
        ['id' => 90, 'reference' => 'RM-APP-90', 'customer_id' => 1, 'status' => 'paid',
         'pathway' => 'certified', 'mode' => 'onsite', 'created_at' => '2026-02-01 09:30:00'],
    ],
    'payments' => [
        ['id' => 90, 'application_id' => 90, 'reference' => 'RM-PAY-90', 'provider' => 'bank',
         'status' => 'pending_bank_transfer', 'amount' => '12345.67', 'currency' => 'NGN',
         'provider_reference' => null, 'verified_at' => null, 'created_at' => '2026-02-01 09:40:00'],
    ],
];
$rmLoad = mam_v2_load($rmBuild, $rmSrc, ['commit' => true]);
t_ok($rmLoad['ok'] === true, 'the fixture snapshot loads [' . json_encode($rmLoad['error'] ?? '') . ']');
t_eq($rmLoad['name_conflicts'], 1, 'and the one name-disagreeing merge is counted at load time');
$rmBuild = null;

// Point the read model at it the way an install would.
// The harness stubs mam_env() to read $GLOBALS['__env'], so the pointer goes in
// through its own hook rather than the process environment.
mam_test_env(['V2_READ_DB' => $rmPath]);
mam_v2_rm_reset_for_test();

t_ok(mam_v2_rm_configured() === true, 'the pointer is picked up from the environment');
$rmS2 = mam_v2_rm_status();
t_ok($rmS2['available'] === true, 'and the snapshot is readable [' . $rmS2['error'] . ']');
t_eq($rmS2['counts']['people'], 2, "two people: v1's explicit customer_id link folded one pair together");
t_eq($rmS2['counts']['applications'], 1, 'one application');
t_eq($rmS2['counts']['payments'], 1, 'one payment');
t_ok($rmS2['loaded_at'] !== null, 'the load stamped itself, so staleness is knowable');
t_ok($rmS2['age_hours'] !== null && $rmS2['age_hours'] >= 0, 'and an age can be computed from it');

t_group('v2 read model — it CANNOT write, checked by trying');

$rmDb = mam_v2_rm_db();
t_ok($rmDb instanceof PDO, 'a handle is open');
$rmWrote = false; $rmErr = '';
try { $rmDb->exec("CREATE TABLE rm_probe (x INTEGER)"); $rmWrote = true; }
catch (\Throwable $e) { $rmErr = $e->getMessage(); }
t_ok($rmWrote === false, 'a DDL statement is refused by the engine [' . substr($rmErr, 0, 48) . ']');

$rmWrote2 = false;
try { $rmDb->exec("UPDATE payments SET amount_minor = 1"); $rmWrote2 = true; }
catch (\Throwable $e) {}
t_ok($rmWrote2 === false, 'and so is an UPDATE — read-only is enforced, not merely intended');

// The query path refuses a non-SELECT before the driver sees it, so a mistake in
// a caller cannot become a write even against a handle that would permit one.
t_eq(count(mam_v2_rm_all("DELETE FROM payments")), 0, 'the query helper refuses a DELETE');
t_eq(count(mam_v2_rm_all("UPDATE users SET email = 'x'")), 0, 'and an UPDATE');
t_eq(count(mam_v2_rm_all("INSERT INTO currencies (code,name) VALUES ('ZZZ','Z')")), 0, 'and an INSERT');
t_ok(count(mam_v2_rm_all("SELECT 1 AS ok")) === 1, 'while a SELECT still works');
t_ok(count(mam_v2_rm_all("WITH x AS (SELECT 1 AS n) SELECT n FROM x")) === 1, 'as does a CTE');
// Nothing above changed anything.
t_eq((int) mam_v2_rm_row("SELECT COUNT(*) c FROM payments")['c'], 1, 'the payment is still there');
t_eq((int) mam_v2_rm_row("SELECT amount_minor FROM payments")['amount_minor'], 1234567,
    'with its amount untouched, exact to the kobo');

t_group('v2 read model — what it answers that v1 cannot');

$rmMerged = mam_v2_rm_merged();
t_eq(count($rmMerged), 1, 'the merged person is visible with both source rows');
t_ok(count($rmMerged[0]['sources']) === 2, 'naming the two v1 rows that folded together');

$rmReview = mam_v2_rm_merge_review();
t_eq(count($rmReview), 1, 'and the disagreeing merge is flagged for review');
t_ok(strpos((string) $rmReview[0]['note'], 'SOURCE NAMES DISAGREE') !== false,
    'with the disagreement recorded — v2 keeps only the winning name, so this is captured at merge time or lost');
t_ok(strpos((string) $rmReview[0]['note'], 'Ngozi Eze') !== false
     && strpos((string) $rmReview[0]['note'], 'Amaka Eze') !== false,
    'and BOTH names are preserved in it, which is the whole point [' . $rmReview[0]['note'] . ']');

$rmMoney = mam_v2_rm_money();
t_eq($rmMoney['total_minor'], 1234567, 'money totals in exact minor units');
t_eq($rmMoney['rows'][0]['status'], 'awaiting_transfer',
    'and a bank transfer keeps its own status rather than collapsing into "pending"');

// The reference on the customer's receipt resolves — the reason the map exists.
$rmHit = mam_v2_rm_resolve('RM-PAY-90');
t_eq(count($rmHit), 1, 'a v1 PAYMENT reference resolves [this was dropped: the map carried provider_reference, NULL on every real bank transfer]');
t_eq($rmHit[0]['v2_table'], 'payments', 'to the payment it became');
$rmHitA = mam_v2_rm_resolve('RM-APP-90');
t_eq(count($rmHitA), 1, 'and an application reference too');
t_eq($rmHitA[0]['v2_table'], 'applications', 'resolving to the application');
t_eq(count(mam_v2_rm_resolve('NOT-A-REFERENCE')), 0, 'an unknown reference resolves to nothing, not to a guess');

t_group('v2 read model — a broken pointer fails closed');

mam_test_env(['V2_READ_DB' => $rmPath . '.does-not-exist']);
mam_v2_rm_reset_for_test();
$rmBad = mam_v2_rm_status();
t_ok($rmBad['configured'] === true, 'a pointer is configured');
t_ok($rmBad['available'] === false, 'but it cannot be read');
t_ok(strpos($rmBad['error'], 'unreachable') === 0, 'and it says so [' . $rmBad['error'] . ']');
t_eq(count(mam_v2_rm_people()), 0, 'no rows are returned');
// The critical one: it must not have answered from v1 instead.
t_eq(count(mam_v2_rm_money()['rows']), 0,
    'and NOTHING is answered from the live v1 database — a figure from the wrong source is worse than none');

mam_test_env(['V2_READ_DB' => '']);
mam_v2_rm_reset_for_test();
@unlink($rmPath);

t_group('v2 read model — the admin surface, and the one-way boundary');

$rmAdmin = file_get_contents(__DIR__ . '/../../admin/index.php');
t_ok(strpos($rmAdmin, "\$view === 'v2read'") !== false, 'the screen exists');
t_ok(strpos($rmAdmin, 'mam_v2_rm_status()') !== false, 'and leads with the snapshot status');
t_ok(strpos($rmAdmin, 'Merges a human should check before any cutover') !== false,
    'the merge review is surfaced, not just logged once at load time');
t_ok(strpos($rmAdmin, 'mam_v2_rm_merge_review()') !== false, 'from the read model');

$rmLib = file_get_contents(__DIR__ . '/../lib/v2/readmodel.php');
t_ok(strpos($rmLib, 'mode=ro') !== false, 'SQLite is opened read-only at the engine level');
t_ok(strpos($rmLib, 'PRAGMA query_only') !== false, 'with a second layer at the statement level');
t_ok(strpos($rmLib, 'SET SESSION TRANSACTION READ ONLY') !== false, 'and the MySQL equivalent');
foreach (['INSERT ', 'UPDATE ', 'DELETE FROM'] as $verb) {
    t_ok(!preg_match('/\$db->(exec|prepare)\([\'"]\s*' . $verb . '/i', $rmLib),
        "there is no $verb path in the read model to call");
}
