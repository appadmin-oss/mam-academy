<?php
/**
 * ETL load + verify — the half that writes.
 *
 * A transform bug is a failing assertion; a load bug is a database somebody has
 * to unpick. So the assertions here are less about "did the row arrive" and
 * more about the four properties that make a real migration survivable:
 *
 *   • a dry run writes NOTHING, and it is the default;
 *   • re-running writes nothing twice;
 *   • a failure rolls back completely, leaving no half-loaded database;
 *   • the verifier catches a corrupted load, checked by corrupting one.
 *
 * That last one matters most. A verifier nobody has ever seen fail is not
 * evidence of anything, and one built on the transform's own arithmetic would
 * agree with a transform bug enthusiastically.
 *
 * Fixtures mirror the real v59 export's shapes — decimal-string amounts,
 * `pending_bank_transfer` dominant, a person appearing as both a customer and a
 * user, an erased applicant still holding applications — with invented content.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/v2/lib/clarity.php';
require_once dirname(__DIR__) . '/modules/v2/lib/identity.php';
require_once dirname(__DIR__) . '/modules/v2/lib/transform.php';
require_once dirname(__DIR__) . '/modules/v2/lib/load.php';
require_once dirname(__DIR__) . '/modules/v2/lib/verify.php';

/** A fresh in-memory v2 database from the generated SQLite mirror. */
function v2l_db(): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec((string) file_get_contents(dirname(__DIR__, 2) . '/db/schema-v2-sqlite.sql'));
    return $db;
}

/**
 * The fixture snapshot. Ada appears twice (customer 1 + user 1) and must merge
 * into one person; Chidi was erased and keeps his applications; Bola applied
 * and never got an account.
 */
function v2l_source(): array {
    return [
        'customers' => [
            ['id' => 1, 'email' => 'Ada.Okoye@example.test', 'first_name' => 'Ada', 'last_name' => 'Okoye',
             'phone' => '+2348000000001', 'dob' => '2011-04-02', 'created_at' => '2026-01-04 09:12:00'],
            ['id' => 2, 'email' => 'bola@example.test', 'first_name' => 'Bola', 'last_name' => 'Adeyemi',
             'phone' => null, 'dob' => null, 'created_at' => '2026-01-06 10:00:00'],
            ['id' => 3, 'email' => 'erased-c3@deleted.invalid', 'first_name' => 'Erased', 'last_name' => 'User',
             'phone' => null, 'dob' => null, 'created_at' => '2026-01-08 11:00:00'],
            ['id' => 4, 'email' => '', 'first_name' => 'Walkin', 'last_name' => 'Enquiry',
             'phone' => null, 'dob' => null, 'created_at' => '2026-01-09 12:00:00'],
        ],
        'users' => [
            // Same human as customer 1, address differing only in case.
            ['id' => 1, 'email' => 'ada.okoye@example.test', 'name' => 'Ada Okoye', 'role' => 'student',
             'status' => 'active', 'password_hash' => '$2y$10$abcdefghijklmnopqrstuv', 'phone' => '+2348000000001',
             'enrollment_ref' => 'MAM-0001', 'created_at' => '2026-01-05 08:00:00'],
            ['id' => 2, 'email' => 'tutor@example.test', 'name' => 'Ngozi Eze', 'role' => 'instructor',
             'status' => 'active', 'password_hash' => '$2y$10$zyxwvutsrqponmlkjihgfe', 'phone' => null,
             'created_at' => '2026-01-02 08:00:00'],
        ],
        'applications' => [
            ['id' => 10, 'reference' => 'APP-0010', 'customer_id' => 1, 'status' => 'paid', 'pathway' => 'certified',
             'mode' => 'onsite', 'discipline' => 'piano', 'created_at' => '2026-01-04 09:20:00', 'source' => 'website'],
            ['id' => 11, 'reference' => 'APP-0011', 'customer_id' => 2, 'status' => 'pending', 'pathway' => 'standard',
             'mode' => 'online', 'discipline' => 'voice', 'created_at' => '2026-01-06 10:05:00', 'source' => 'referral'],
            // The erased applicant keeps the application; the payload must not.
            ['id' => 12, 'reference' => 'APP-0012', 'customer_id' => 3, 'status' => 'pending', 'pathway' => 'standard',
             'mode' => 'onsite', 'discipline' => 'guitar', 'goals' => 'A sentence that identifies him.',
             'created_at' => '2026-01-08 11:05:00', 'source' => 'website'],
        ],
        'payments' => [
            ['id' => 100, 'application_id' => 10, 'reference' => 'PAY-100', 'provider' => 'paystack',
             'status' => 'success', 'amount' => '75000.00', 'currency' => 'NGN',
             'provider_reference' => 'ps_100', 'verified_at' => '2026-01-04 09:30:00', 'created_at' => '2026-01-04 09:25:00'],
            ['id' => 101, 'application_id' => 11, 'reference' => 'PAY-101', 'provider' => 'bank',
             'status' => 'pending_bank_transfer', 'amount' => '52500.50', 'currency' => 'NGN',
             'provider_reference' => 'bk_101', 'verified_at' => null, 'created_at' => '2026-01-06 10:10:00'],
            ['id' => 102, 'application_id' => 12, 'reference' => 'PAY-102', 'provider' => 'paystack',
             'status' => 'failed', 'amount' => '8.07', 'currency' => 'NGN',
             'provider_reference' => 'ps_102', 'verified_at' => null, 'created_at' => '2026-01-08 11:10:00'],
            // No application → no owner. Loaded anyway: the money is real.
            ['id' => 103, 'application_id' => null, 'reference' => 'PAY-103', 'provider' => 'cash',
             'status' => 'success', 'amount' => '1200.00', 'currency' => 'NGN',
             'provider_reference' => 'cash_103', 'verified_at' => '2026-02-01 10:00:00', 'created_at' => '2026-02-01 10:00:00'],
        ],
    ];
}

t_group('ETL load — the independent money parser');

// The verifier's parser must agree with the transform's on good input and
// disagree the moment either is wrong. Both are exact; neither touches a float.
foreach (['75000.00' => 7500000, '52500.50' => 5250050, '8.07' => 807, '0.01' => 1, '1200' => 120000] as $in => $want) {
    t_eq(mam_v2_verify_minor((string) $in), $want, "verify parser: '$in' → $want minor");
    t_eq(mam_v2_minor((string) $in), mam_v2_verify_minor((string) $in), "both parsers agree on '$in'");
}
t_ok(mam_v2_verify_minor('8.075') === null,
    'more precision than the currency has is REFUSED, not truncated — a silent truncation reconciles while the money is wrong');
t_eq(mam_v2_verify_minor('8.070'), 807, 'a trailing zero beyond the exponent is not extra precision');
t_ok(mam_v2_verify_minor('not money') === null, 'a non-numeric amount is refused');
t_ok(mam_v2_verify_minor(null) === null, 'a null amount is refused');

t_group('ETL load — a dry run writes nothing, and it is the default');

$db = v2l_db();
$src = v2l_source();
$dry = mam_v2_load($db, $src);            // no 'commit' key at all
t_ok($dry['ok'] === true, 'the dry run completes');
t_ok($dry['committed'] === false, 'and reports that it committed nothing');
t_eq((int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn(), 0, 'no users were written');
t_eq((int) $db->query("SELECT COUNT(*) FROM payments")->fetchColumn(), 0, 'no payments were written');
t_eq((int) $db->query("SELECT COUNT(*) FROM legacy_reference_map")->fetchColumn(), 0, 'and the map is untouched');
// The seed steps are the ones that got this wrong: only the ROW loaders checked
// the flag, so a dry run left a tenant, a centre and four currencies behind —
// and outside the transaction, so not even rolled back. "Nothing" means nothing.
t_eq((int) $db->query("SELECT COUNT(*) FROM tenants")->fetchColumn(), 0, 'no tenant was seeded');
t_eq((int) $db->query("SELECT COUNT(*) FROM centres")->fetchColumn(), 0, 'no centre was seeded');
// The mirror's DDL seeds the ISO table itself, so the count to hold still is
// whatever a fresh schema has — not zero.
$freshCurrencies = (int) v2l_db()->query("SELECT COUNT(*) FROM currencies")->fetchColumn();
t_eq((int) $db->query("SELECT COUNT(*) FROM currencies")->fetchColumn(), $freshCurrencies,
    'no currencies were seeded beyond the ones the schema ships');
t_eq((int) $db->query("SELECT COUNT(*) FROM memberships")->fetchColumn(), 0, 'no memberships were written');
t_eq((int) $db->query("SELECT COUNT(*) FROM applications")->fetchColumn(), 0, 'no applications were written');
t_eq((int) $db->query("SELECT COUNT(*) FROM user_credentials")->fetchColumn(), 0, 'no credentials were written');
// The fixture has exactly one payment with no application. On a dry run there
// are no membership ids yet, so judging ownership by those reported every
// payment as unowned — an artefact that read as a catastrophic data finding.
// The question that means something in both modes is whether an owner could be
// resolved AT ALL.
t_eq($dry['payments']['unowned'], 1,
    'the one genuinely unowned payment is reported — and only that one');
// The counts it reports are nonetheless real.
t_eq($dry['people']['users_created'], 5, 'the dry run still says how many people WOULD load [got ' . $dry['people']['users_created'] . ']');
t_eq($dry['payments']['total_minor'], 7500000 + 5250050 + 807 + 120000, 'and the money it would load');

t_group('ETL load — the committed load');

$db = v2l_db();
$r = mam_v2_load($db, $src, ['commit' => true]);
t_ok($r['ok'] === true, 'the load succeeds [' . json_encode($r['error'] ?? '') . ']');
t_ok($r['committed'] === true, 'and says so');

// 4 customers + 2 users, with Ada appearing on both sides → 5 people.
t_eq($r['identity']['people'], 5, 'six source rows resolve to five people');
t_eq($r['identity']['merged'], 1, 'exactly one merge happened');
t_eq((int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn(), 5, 'five users exist');
t_eq((int) $db->query("SELECT COUNT(*) FROM memberships")->fetchColumn(), 5, 'each has a membership');

// The merge is auditable: both v1 rows point at one v2 user.
$adaRows = $db->query("SELECT legacy_table, legacy_id, v2_id, note FROM legacy_reference_map
                       WHERE v2_table = 'users' AND v2_id = (SELECT id FROM users WHERE email = 'ada.okoye@example.test')")
              ->fetchAll(PDO::FETCH_ASSOC);
t_eq(count($adaRows), 2, 'the merged person is reachable from BOTH v1 rows');
t_ok(str_starts_with((string) $adaRows[0]['note'], 'merged identity'),
    'and the merge is noted rather than left to be inferred [' . $adaRows[0]['note'] . ']');
t_ok(str_contains((string) $adaRows[0]['note'], 'via '),
    'naming WHICH rule merged them, which is what a reviewer needs to judge it');
t_eq((string) $db->query("SELECT email FROM users WHERE id = " . (int) $adaRows[0]['v2_id'])->fetchColumn(),
    'ada.okoye@example.test', 'the LOGIN address wins over the applicant record\'s casing');

// Credentials: carried for real accounts, never for a tombstone.
t_eq((int) $db->query("SELECT COUNT(*) FROM user_credentials")->fetchColumn(), 2,
    'both real accounts keep their password — a migration must not force every family to reset');
$erasedId = (int) $db->query("SELECT id FROM users WHERE status = 'closed'")->fetchColumn();
t_ok($erasedId > 0, 'the erased person still has a row, so the counts reconcile');
t_eq((int) $db->query("SELECT COUNT(*) FROM user_credentials WHERE user_id = $erasedId")->fetchColumn(), 0,
    'but no password — a tombstone that can log in is not a tombstone');
t_eq((string) $db->query("SELECT full_name FROM users WHERE id = $erasedId")->fetchColumn(), 'Erased user',
    'and no reconstructed name');
t_eq((string) $db->query("SELECT stage FROM memberships WHERE user_id = $erasedId")->fetchColumn(), 'withdrawn',
    'the erased membership is withdrawn, not active');

// The person with no address at all gets a synthetic, undeliverable one.
$noEmail = (string) $db->query("SELECT email FROM users WHERE email LIKE 'no-email-%'")->fetchColumn();
t_ok(str_ends_with($noEmail, '@migrated.invalid'),
    'a person with no address is kept, with an address nothing can be delivered to [' . $noEmail . ']');

// Applications: all three loaded, the erased one stripped of its payload.
t_eq((int) $db->query("SELECT COUNT(*) FROM applications")->fetchColumn(), 3, 'all three applications loaded');
t_eq((int) $db->query("SELECT COUNT(*) FROM applications WHERE membership_id IS NULL")->fetchColumn(), 0,
    'each is attached to its applicant');
$erasedApp = $db->query("SELECT payload FROM applications WHERE reference = 'APP-0012'")->fetch(PDO::FETCH_ASSOC);
t_ok($erasedApp['payload'] === null,
    'the erased applicant keeps the application and loses the free text that identifies him');
$livePayload = (string) $db->query("SELECT payload FROM applications WHERE reference = 'APP-0010'")->fetchColumn();
t_ok(strpos($livePayload, 'piano') !== false, 'a live application keeps its answers');

// Payments: the owner is joined through the application.
t_eq((int) $db->query("SELECT COUNT(*) FROM payments")->fetchColumn(), 4, 'all four payments loaded');
t_eq($r['payments']['unowned'], 1, 'the one with no application is reported as unowned');
t_eq((int) $db->query("SELECT COUNT(*) FROM payments WHERE membership_id IS NOT NULL")->fetchColumn(), 3,
    'the other three found their owner through the application');
t_eq((int) $db->query("SELECT amount_minor FROM payments WHERE provider_ref = 'ps_100'")->fetchColumn(), 7500000,
    '₦75,000.00 is stored as exactly 7,500,000 kobo');
t_eq((int) $db->query("SELECT amount_minor FROM payments WHERE provider_ref = 'bk_101'")->fetchColumn(), 5250050,
    'and ₦52,500.50 as 5,250,050 — the half-kobo case a float loses');
t_eq((string) $db->query("SELECT status FROM payments WHERE provider_ref = 'bk_101'")->fetchColumn(), 'awaiting_transfer',
    'a bank transfer keeps its own status rather than collapsing into "pending"');
t_ok($db->query("SELECT fx_rate FROM payments WHERE provider_ref = 'ps_100'")->fetchColumn() === null,
    'a same-currency payment carries no invented rate');
t_ok((string) $db->query("SELECT failure_reason FROM payments WHERE provider_ref = 'ps_102'")->fetchColumn() !== '',
    'a failed payment carries a reason — the schema CHECK requires one and the load must supply it');

t_group('ETL load — re-running writes nothing twice');

$before = [
    'users' => (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'apps'  => (int) $db->query("SELECT COUNT(*) FROM applications")->fetchColumn(),
    'pays'  => (int) $db->query("SELECT COUNT(*) FROM payments")->fetchColumn(),
    'map'   => (int) $db->query("SELECT COUNT(*) FROM legacy_reference_map")->fetchColumn(),
];
$again = mam_v2_load($db, $src, ['commit' => true]);
t_ok($again['ok'] === true, 'a second run succeeds');
t_eq($again['people']['users_created'], 0, 'and creates no people');
t_eq($again['people']['users_existing'], 5, 'recognising all five as already present');
t_eq($again['applications']['created'], 0, 'no applications are duplicated');
t_eq($again['applications']['existing'], 3, 'all three are recognised');
t_eq($again['payments']['created'], 0, 'no payments are duplicated');
t_eq((int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn(), $before['users'], 'the user count is unchanged');
t_eq((int) $db->query("SELECT COUNT(*) FROM payments")->fetchColumn(), $before['pays'], 'the payment count is unchanged');
t_eq((int) $db->query("SELECT COUNT(*) FROM legacy_reference_map")->fetchColumn(), $before['map'], 'and the map is unchanged');

t_group('ETL load — a failure rolls back completely');

// A half-loaded database looks populated and reconciles against nothing, which
// is worse than no load at all. Force a failure partway by making a payment
// name a currency the load did not seed.
$db2 = v2l_db();
$db2->exec("DELETE FROM currencies");   // emptied after the schema, before the load seeds
$bad = v2l_source();
$bad['payments'][0]['currency'] = 'ZZZ';   // not the base → the transform rejects it
$rb = mam_v2_load($db2, $bad, ['commit' => true]);
t_ok($rb['ok'] === true, 'a rejected row does not fail the load — it is reported');
t_eq(count($rb['rejected']['payments']), 1, 'the non-base-currency payment is rejected, not converted at 1:1');
t_ok(strpos($rb['rejected']['payments'][0]['why'], 'no historical rate') !== false,
    'and the reason says why: there is no rate to freeze [' . $rb['rejected']['payments'][0]['why'] . ']');
t_eq((int) $db2->query("SELECT COUNT(*) FROM payments")->fetchColumn(), 3, 'the other three still load');

// Now a real mid-load failure: a v2 table the loader needs, removed.
$db3 = v2l_db();
$db3->exec("DROP TABLE memberships");
$fail = mam_v2_load($db3, $src, ['commit' => true]);
t_ok($fail['ok'] === false, 'a mid-load failure is reported as a failure');
t_eq($fail['error'], 'load_failed', 'with the reason named');
t_ok($fail['committed'] === false, 'and nothing is claimed to be committed');
t_eq((int) $db3->query("SELECT COUNT(*) FROM users")->fetchColumn(), 0,
    'the users written before the failure were rolled back — no half-loaded database');
t_eq((int) $db3->query("SELECT COUNT(*) FROM legacy_reference_map")->fetchColumn(), 0,
    'and the map is clean, so a re-run after the fix starts from nothing');

t_group('ETL load — row preservation is refused, not assumed');

// If the identity resolver drops somebody, the load must not run at all.
$dbP = v2l_db();
$broken = v2l_source();
$broken['customers'][] = ['id' => 1, 'email' => 'Ada.Okoye@example.test', 'first_name' => 'Ada', 'last_name' => 'Okoye'];
$pres = mam_v2_load($dbP, $broken, ['commit' => true]);
t_ok($pres['ok'] === false, 'a duplicated source id makes the load refuse');
t_eq($pres['error'], 'identity_unaccounted', 'naming row preservation as the reason');
t_eq((int) $dbP->query("SELECT COUNT(*) FROM users")->fetchColumn(), 0, 'and nothing is written');

t_group('ETL verify — it passes a good load');

$v = mam_v2_verify($db, $src, $r['rejected']);
t_ok($v['ok'] === true, 'the loaded database reconciles against the snapshot [' .
    json_encode(array_column($v['failed'], 'check')) . ']');
t_ok($v['summary']['total'] >= 12, 'across a real set of checks [' . $v['summary']['total'] . ']');

$byName = [];
foreach ($v['checks'] as $c) $byName[$c['check']] = $c;
t_eq($byName['money.total_minor']['expected'], 7500000 + 5250050 + 807 + 120000,
    'the money total is recomputed from the source strings');
t_eq($byName['money.total_minor']['actual'], $byName['money.total_minor']['expected'], 'and the database matches it');
t_eq($byName['money.succeeded_minor']['expected'], 7500000 + 120000,
    'succeeded money counts only what v1 called success');
t_eq($byName['people.count']['expected'], 5, 'the people figure is recomputed independently of the resolver');
foreach (['people.every_source_row_mapped', 'erasure.no_credentials', 'erasure.tombstone_addresses',
          'applications.all_linked', 'money.no_invented_fx', 'provenance.applications',
          'provenance.payments', 'integrity.foreign_keys'] as $name) {
    t_ok(isset($byName[$name]) && $byName[$name]['ok'], "check '$name' passes");
}

t_group('ETL verify — it CATCHES a corrupted load');

// A verifier nobody has watched fail is not evidence. Each of these is a way a
// load could go wrong quietly, and each must be caught.

// (a) A payment vanishes: the money no longer adds up.
$dbc = v2l_db();
mam_v2_load($dbc, $src, ['commit' => true]);
$dbc->exec("DELETE FROM payments WHERE provider_ref = 'ps_100'");
$vc = mam_v2_verify($dbc, $src, []);
t_ok($vc['ok'] === false, 'a missing payment fails verification');
$failed = array_column($vc['failed'], 'check');
t_ok(in_array('money.total_minor', $failed, true), 'and the money check is what catches it');

// (b) A payment's amount is quietly altered by one kobo.
$dbc2 = v2l_db();
mam_v2_load($dbc2, $src, ['commit' => true]);
$dbc2->exec("UPDATE payments SET amount_minor = amount_minor - 1 WHERE provider_ref = 'bk_101'");
$vc2 = mam_v2_verify($dbc2, $src, []);
t_ok($vc2['ok'] === false, 'a one-kobo discrepancy fails verification');
t_ok(in_array('money.total_minor', array_column($vc2['failed'], 'check'), true),
    'because the independent parser recomputed the figure rather than trusting the loader');

// (c) A person is dropped.
$dbc3 = v2l_db();
mam_v2_load($dbc3, $src, ['commit' => true]);
$dbc3->exec("DELETE FROM legacy_reference_map WHERE legacy_table = 'customers' AND legacy_id = '2'");
$vc3 = mam_v2_verify($dbc3, $src, []);
t_ok($vc3['ok'] === false, 'an unmapped source row fails verification');
t_ok(in_array('people.every_source_row_mapped', array_column($vc3['failed'], 'check'), true),
    'and row preservation is what catches it');

// (d) A tombstone regains a password.
$dbc4 = v2l_db();
mam_v2_load($dbc4, $src, ['commit' => true]);
$eid = (int) $dbc4->query("SELECT id FROM users WHERE status = 'closed'")->fetchColumn();
$dbc4->exec("INSERT INTO user_credentials (user_id, password_hash, algo, must_change) VALUES ($eid, 'x', 'bcrypt', 0)");
$vc4 = mam_v2_verify($dbc4, $src, []);
t_ok($vc4['ok'] === false, 'an erased person holding a password fails verification');
t_ok(in_array('erasure.no_credentials', array_column($vc4['failed'], 'check'), true), 'named as an erasure failure');

// (e) A row appears that came from nowhere.
$dbc5 = v2l_db();
mam_v2_load($dbc5, $src, ['commit' => true]);
$tid5 = (int) $dbc5->query("SELECT id FROM tenants LIMIT 1")->fetchColumn();
$dbc5->exec("INSERT INTO payments (public_id, tenant_id, provider, status, currency, amount_minor, base_currency, base_amount_minor)
             VALUES ('" . mam_v2_ulid() . "', $tid5, 'cash', 'succeeded', 'NGN', 999, 'NGN', 999)");
$vc5 = mam_v2_verify($dbc5, $src, []);
t_ok($vc5['ok'] === false, 'a row with no source fails verification');
t_ok(in_array('provenance.payments', array_column($vc5['failed'], 'check'), true),
    'invented data is caught as well as missing data — the direction people forget to check');

// (f) An unloaded tenant is reported as such, not as a pass.
$dbc6 = v2l_db();
$vc6 = mam_v2_verify($dbc6, $src, []);
t_ok($vc6['ok'] === false && ($vc6['error'] ?? '') === 'tenant_not_loaded',
    'verifying a database nothing was loaded into reports that, rather than passing vacuously');

t_group('ETL load — the CLI and the wiring');

$cli = file_get_contents(dirname(__DIR__) . '/tools/v2-load.php');
t_ok(strpos($cli, "!empty(\$opt['commit'])") !== false, 'the CLI requires --commit to write');
t_ok(strpos($cli, 'dry run (nothing written)') !== false, 'and says plainly when it has written nothing');
t_ok(strpos($cli, 'mam_v2_verify') !== false, 'verification runs from the same command');
t_ok(strpos($cli, 'PHP_SAPI') !== false, 'the tool refuses to run over HTTP');

t_group('ETL load — every value fits the column the canonical schema declares');

/**
 * SQLite ignores VARCHAR lengths; MySQL in strict mode does not. So a transform
 * that writes 62 characters into a VARCHAR(48) loads perfectly in dev and dies
 * mid-migration on the real engine — which is exactly what happened with
 * `applications.source_channel`, where v1 stores a full landing URL and v2
 * means an attribution channel.
 *
 * This reads the declared widths out of the CANONICAL MySQL schema and checks
 * the transform's own output against them, so the failure surfaces here rather
 * than against a database nobody has stood up yet.
 */
$ddl = (string) file_get_contents(dirname(__DIR__, 2) . '/db/schema-v2.sql');
$widths = [];
foreach (preg_split('/CREATE TABLE\s+/i', $ddl) as $chunk) {
    if (!preg_match('/^`?([a-z_]+)`?\s*\(/i', $chunk, $tm)) continue;
    $table = strtolower($tm[1]);
    if (preg_match_all('/^\s*`?([a-z_]+)`?\s+VARCHAR\((\d+)\)/im', $chunk, $cm, PREG_SET_ORDER)) {
        foreach ($cm as $c) $widths[$table][strtolower($c[1])] = (int) $c[2];
    }
}
t_ok(($widths['applications']['source_channel'] ?? 0) === 48,
    'the width table was parsed out of the canonical schema [source_channel = ' . ($widths['applications']['source_channel'] ?? '?') . ']');
t_ok(count($widths) > 20, 'across the whole schema [' . count($widths) . ' tables]');

// Check the transform's output for the fixture AND for the shapes that broke:
// a landing URL in `source`, and a long free-text source.
$wideSrc = v2l_source();
$wideSrc['applications'][0]['source'] = 'https://academy.mamcentre.org/apply?pathway=certified-musician&utm_campaign=x';
$wideSrc['applications'][1]['source'] = str_repeat('long free text ', 12);
$idw  = mam_v2_resolve_identities($wideSrc['customers'], $wideSrc['users']);
$tw   = mam_v2_transform_applications($wideSrc['applications'], $idw['people']);
$tpw  = mam_v2_transform_payments(mam_v2_attach_payment_owners($wideSrc['payments'], $wideSrc['applications']), $idw['people']);

$over = [];
foreach ([['applications', $tw['rows']], ['payments', $tpw['rows']]] as [$table, $rows]) {
    foreach ($rows as $row) {
        foreach ($row as $col => $val) {
            if (!is_string($val)) continue;
            $max = $widths[$table][$col] ?? null;
            if ($max !== null && mb_strlen($val) > $max) {
                $over[] = "$table.$col " . mb_strlen($val) . " > $max";
            }
        }
    }
}
t_eq(implode('; ', $over), '',
    'no transformed value exceeds its declared column width — SQLite would accept these and MySQL would not');

// And the derivation itself, which is the fix rather than a truncation.
t_eq(mam_v2_source_channel('https://academy.mamcentre.org/apply?pathway=certified-musician'),
    'academy.mamcentre.org', 'a landing URL yields its HOST as the channel');
t_eq(mam_v2_source_channel('referral'), 'referral', 'a short token is already a channel and passes through');
t_eq(mam_v2_source_channel(str_repeat('x', 60)), 'other',
    'long free text becomes "other" rather than a truncated channel nobody chose');
t_ok(mam_v2_source_channel('') === null, 'an empty source is null, not an empty channel');
t_ok(mam_v2_source_channel('not a url but quite long ' . str_repeat('y', 40)) === 'other',
    'and the same for anything else that would not fit');

// The URL itself is preserved, so deriving the channel loses nothing.
$withUrl = null;
foreach ($tw['rows'] as $row) { if (($row['legacy'][1] ?? '') === '10') $withUrl = $row; }
t_ok($withUrl !== null, 'the application with the URL source is present');
t_ok(strpos((string) json_encode($withUrl['payload']), 'utm_campaign') !== false,
    'and the full landing URL is kept in the payload — deriving a channel must not throw the evidence away');

t_group('ETL load — no CHECK permits a value its own column cannot hold');

/**
 * `payments.status` was VARCHAR(16) while its CHECK listed `awaiting_transfer`
 * (17) and `partially_refunded` (18). SQLite ignores widths, so the
 * contradiction was invisible in dev and would have killed the MySQL load on
 * the DOMINANT status in the real data — 11 of 15 payments.
 *
 * A column that cannot hold a value its own constraint permits is always a bug,
 * so this checks the whole canonical schema rather than the one column.
 */
$tooNarrow = [];
foreach (preg_split('/CREATE TABLE\s+/i', $ddl) as $chunk) {
    if (!preg_match('/^`?([a-z_]+)`?\s*\(/i', $chunk, $tm)) continue;
    $table = strtolower($tm[1]);
    // CHECK (col IN ('a','b',...)) — the shape this schema uses throughout.
    if (!preg_match_all('/CHECK\s*\(\s*`?([a-z_]+)`?\s+IN\s*\(([^)]*)\)/i', $chunk, $cm, PREG_SET_ORDER)) continue;
    foreach ($cm as $c) {
        $col = strtolower($c[1]);
        $max = $widths[$table][$col] ?? null;
        if ($max === null) continue;
        preg_match_all("/'([^']*)'/", $c[2], $vm);
        foreach ($vm[1] as $value) {
            if (mb_strlen($value) > $max) $tooNarrow[] = "$table.$col($max) cannot hold '$value' (" . mb_strlen($value) . ')';
        }
    }
}
t_eq(implode('; ', $tooNarrow), '',
    'every value a CHECK permits fits the column it constrains');
t_ok(($widths['payments']['status'] ?? 0) >= 18,
    'payments.status is wide enough for partially_refunded [' . ($widths['payments']['status'] ?? '?') . ']');

t_group('ETL load — coverage is derived from the plan, not hand-listed');

/**
 * This group used to assert that the loader read exactly four source files —
 * customers, users, applications, payments — on the grounds that the coverage
 * claim should be checkable rather than remembered. The claim was checkable and
 * the coverage was WRONG: the loader carried four tables while the plan carried
 * 112, so every other table arrived empty and nothing said so.
 *
 * What is asserted now is the property that makes that impossible to repeat:
 * there is ONE list of what a migration carries, `$plan['order']`, and both the
 * SQL emitter and the loader walk it.
 */
$v2lLoadSrc = file_get_contents(dirname(__DIR__) . '/modules/v2/lib/load.php');
$v2lToolSrc = file_get_contents(dirname(__DIR__) . '/tools/v2-load.php');

t_ok(str_contains($v2lLoadSrc, "\$plan['order']") || str_contains($v2lLoadSrc, '$order = (array) ($plan'),
    'the loader walks the plan\'s own order rather than a list of stages');
t_ok(!str_contains($v2lLoadSrc, 'MAM_V2_LOAD_STAGES'),
    'and the six-stage constant is gone — it was the shorter of two answers to "what does this carry"');
t_ok(str_contains($v2lToolSrc, "glob(rtrim(\$exportDir"),
    'the tool reads the WHOLE export directory, so a new v1 table cannot be skipped by omission');
t_ok(str_contains($v2lToolSrc, 'unmapped') && str_contains($v2lToolSrc, 'BEFORE cutting over'),
    'and it prints any table with rows that the plan does not carry, rather than leaving it to be discovered');

// The load and the emitter must agree about the encoding rules, or the same plan
// produces two different databases depending on which path ran.
t_ok(str_contains($v2lLoadSrc, 'MAM_V2_IP_COLUMNS') && str_contains($v2lLoadSrc, 'MAM_V2_EMIT_JSON'),
    'both write paths read one registry of packed-address and JSON columns');

// Every table the plan can write must exist in the shipped schema, or the load
// fails on a table nobody noticed was missing.
$v2lPlanOrder = MAM_V2_PLAN_ORDER;
$v2lSchemaTables = [];
foreach (preg_split('/\r?\n/', (string) file_get_contents(dirname(__DIR__, 2) . '/db/schema-v2-sqlite.sql')) as $line) {
    if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?"?([a-z_0-9]+)"?/i', $line, $m)) $v2lSchemaTables[] = $m[1];
}
$v2lMissing = array_values(array_diff($v2lPlanOrder, $v2lSchemaTables));
t_eq(implode(', ', $v2lMissing), '',
    'every table the plan writes exists in the schema the loader creates');
t_ok(count($v2lPlanOrder) > 100,
    'and the plan carries the whole product, not a core [' . count($v2lPlanOrder) . ' tables]');

/**
 * The contradiction worth failing over: dataprotection.php asserts a seven-year
 * legal-obligation retention on `audit_log`, v2 HAS an audit_log table, and the
 * ETL does not carry it. Both statements cannot be true at cutover.
 *
 * This test does not demand the ETL migrate it — that is the academy's call. It
 * demands that the decision be WRITTEN DOWN somewhere, so nobody drops 232 rows
 * of the evidence trail by omission while the compliance screen promises to keep
 * it for seven years.
 */
$v2lReadiness = @file_get_contents(dirname(__DIR__, 2) . '/docs/v2-cutover-readiness-2026-08-18.md');
$v2lCarriesAudit = strpos($v2lLoadSrc, 'audit_log') !== false;
t_ok($v2lCarriesAudit || ($v2lReadiness && strpos($v2lReadiness, 'audit_log') !== false),
    'the audit log is either migrated, or its omission is recorded against the retention basis that claims to keep it');
t_ok($v2lReadiness !== false && strpos($v2lReadiness, 'The ETL is ready. The site is not.') !== false,
    'and the readiness of the SITE is distinguished from the readiness of the ETL');

t_group('ETL load — plan-driven: the loader and the emitter carry the same tables');

// The defect this replaced: the loader had six hand-written stages carrying four
// tables while the plan carried 112, so every other table arrived EMPTY on
// cutover day and nothing said so.
$dbPD = v2l_db();
// ⚠️ The ids are OFFSET before loading, and that is the whole point of doing it
// here. The plan numbers its rows 1..N per table; in a fresh database the
// database's own ids come out 1..N too, so a loader that wrote plan ids straight
// through would pass every check by coincidence. Advancing each AUTOINCREMENT
// sequence first makes the two sets of ids disagree, which is the only way the
// translation is actually tested. (Measured: without this, removing the
// soft-reference remapping entirely breaks nothing.)
foreach (['users', 'memberships', 'applications', 'payments', 'courses', 'cohorts',
          'programmes', 'levels', 'contact_messages'] as $t) {
    $dbPD->exec("INSERT INTO sqlite_sequence (name, seq) SELECT '$t', 500
                  WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence WHERE name = '$t')");
    $dbPD->exec("UPDATE sqlite_sequence SET seq = 500 WHERE name = '$t'");
}
$srcPD = v2l_source();
// A source with academic rows, so the load has to reach past the four tables the
// old stages knew about.
$srcPD['programs'] = [['id' => 3, 'code' => 'standard', 'name' => 'Standard', 'weeks' => 12,
                       'sort_order' => 1, 'active' => 1]];
$srcPD['levels']   = [['id' => 1, 'code' => 'precert', 'name' => 'Pre-Certification', 'sort_order' => 1]];
$srcPD['courses']  = [['id' => 5, 'code' => 'piano', 'name' => 'Piano', 'kind' => 'elective',
                       'sort_order' => 1, 'active' => 1]];
$srcPD['cohorts']  = [['id' => 1, 'course_id' => 5, 'level_id' => 1, 'program_id' => 3, 'instructor_id' => null,
                       'title' => 'Piano — Pre-Cert', 'capacity' => 12, 'status' => 'active',
                       'created_at' => '2026-01-10 09:00:00']];
$srcPD['messages'] = [['id' => 1, 'name' => 'A Parent', 'email' => 'enquirer@example.test', 'phone' => null,
                       'topic' => 'tuition', 'message' => 'How much?', 'ip' => '198.51.100.20',
                       'status' => 'unread', 'created_at' => '2026-01-15 09:00:00']];
$srcPD['newsletter'] = [['id' => 1, 'email' => 'sub@example.test', 'source' => 'footer',
                         'status' => 'active', 'created_at' => '2026-01-01 00:00:00']];
// An append-only row: audit_log has no public_id and nothing to probe on, which
// is what the skip-on-re-run path exists for.
$srcPD['audit_log'] = [['id' => 1, 'actor' => 'admin', 'action' => 'update', 'entity' => 'application',
                        'entity_id' => 90, 'meta' => null, 'ip' => '198.51.100.7',
                        'created_at' => '2026-01-13 09:05:00']];

$pd = mam_v2_load($dbPD, $srcPD, ['commit' => true]);
t_ok($pd['ok'] === true, 'the plan-driven load succeeds [' . json_encode($pd['error'] ?? '') . ']');
t_ok($pd['tables_written'] >= 15,
    'and writes far more than the four tables the old stages carried [' . $pd['tables_written'] . ']');
foreach (['programmes', 'levels', 'courses', 'cohorts', 'contact_messages', 'marketing_contacts'] as $t) {
    t_ok((int) $dbPD->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0,
        "$t arrives with rows — before this it arrived empty and nothing said so");
}
t_ok((int) $dbPD->query("SELECT COUNT(*) FROM legacy_reference_map")->fetchColumn() > 0,
    'and every row is still traceable to where it came from');

t_group('ETL load — no plan id reaches the database');

// The plan resolves foreign keys against its OWN synthetic ids. Writing one of
// those into the database is the defect this ETL has been bitten by five times,
// and the columns with no foreign key are the ones nobody notices.
$dangling = [];
foreach ($dbPD->query("SELECT v2_table, v2_id FROM legacy_reference_map")->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $t = (string) $m['v2_table'];
    $q = $dbPD->prepare("SELECT 1 FROM $t WHERE id = ?");
    $q->execute([(int) $m['v2_id']]);
    if ($q->fetchColumn() === false) $dangling[] = $t . ':' . $m['v2_id'];
}
t_eq(implode(', ', $dangling), '',
    'legacy_reference_map.v2_id resolves in the table its own row names — it has no foreign key, so nothing '
    . 'but this would catch a plan id written straight through');

$auditDangling = 0;
foreach ($dbPD->query("SELECT entity_type, entity_id FROM audit_log WHERE entity_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $map = ['application' => 'applications', 'payment' => 'payments', 'user' => 'users',
            'customer' => 'users', 'course' => 'courses', 'cohort' => 'cohorts'];
    $t = $map[strtolower((string) $a['entity_type'])] ?? null;
    if ($t === null) continue;
    $q = $dbPD->prepare("SELECT 1 FROM $t WHERE id = ?");
    $q->execute([(int) $a['entity_id']]);
    if ($q->fetchColumn() === false) $auditDangling++;
}
t_eq($auditDangling, 0, 'audit_log.entity_id resolves too — the column that WAS copied straight from v1');

$cfvDangling = 0;
foreach ($dbPD->query("SELECT d.entity_type t, v.entity_id i FROM custom_field_values v
                        JOIN custom_field_defs d ON d.id = v.field_def_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $q = $dbPD->prepare('SELECT 1 FROM ' . $r['t'] . ' WHERE id = ?');
    $q->execute([(int) $r['i']]);
    if ($q->fetchColumn() === false) $cfvDangling++;
}
t_eq($cfvDangling, 0,
    'and a custom field value points at the entity its DEFINITION names, resolved before any id was translated');

// A foreign key onto a natural column is a VALUE, not an id.
t_eq((string) $dbPD->query("SELECT currency FROM payments LIMIT 1")->fetchColumn(), 'NGN',
    'payments.currency references currencies(code) and is written as it stands — treating "NGN" as a plan id '
    . 'is how a loader turns money into a constraint violation');

t_group('ETL load — an address is stored packed, which SQLite hides and MySQL refuses');

$ipRaw = $dbPD->query("SELECT source_ip FROM contact_messages WHERE source_ip IS NOT NULL LIMIT 1")->fetchColumn();
t_ok($ipRaw !== false && strlen((string) $ipRaw) === 4,
    'a v4 address in a VARBINARY(16) column is four bytes, not fifteen characters — SQLite accepts the text '
    . 'and MySQL refuses it, so this is invisible until the real load [' . strlen((string) $ipRaw) . ']');
t_eq(@inet_ntop((string) $ipRaw), '198.51.100.20', 'and it reads back as the address it came from');

t_group('ETL load — a re-run writes nothing, across every table');

$countsBefore = [];
foreach ($dbPD->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $countsBefore[$t] = (int) $dbPD->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}
$pd2 = mam_v2_load($dbPD, $srcPD, ['commit' => true]);
t_ok($pd2['ok'] === true, 'the second run succeeds');
t_eq((int) ($pd2['totals']['created'] ?? -1), 0, 'and creates NOTHING — idempotent across all 112 tables');
$grew = [];
foreach ($countsBefore as $t => $n) {
    $now = (int) $dbPD->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    if ($now !== $n) $grew[] = "$t $n→$now";
}
t_eq(implode(', ', $grew), '', 'no table grew');
t_ok((int) ($pd2['totals']['skipped'] ?? 0) > 0,
    'the rows that cannot be recognised — no public_id, no fillable unique key — are SKIPPED and counted '
    . 'rather than appended [' . (int) ($pd2['totals']['skipped'] ?? 0) . ']');

t_group('ETL load — the dry run is a real one');

// The old dry run reported what it "would" write without writing it, so the
// first time a constraint was tested was the run that mattered. This one inserts
// inside a transaction and rolls back, which means a plan that violates a CHECK
// fails on the DRY RUN.
$dbDry = v2l_db();
$dry2 = mam_v2_load($dbDry, $srcPD);
t_ok($dry2['ok'] === true, 'a valid plan passes the dry run');
t_ok(($dry2['totals']['created'] ?? 0) > 0,
    'having actually inserted every row [' . ($dry2['totals']['created'] ?? 0) . ']');
$leftBehind = 0;
foreach (['users', 'cohorts', 'contact_messages', 'legacy_reference_map'] as $t) {
    $leftBehind += (int) $dbDry->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}
t_eq($leftBehind, 0, 'and rolled every one of them back');

// The proof that it is a real dry run: break a constraint and the DRY RUN says so.
$dbDry2 = v2l_db();
$dbDry2->exec("DROP TABLE contact_messages");
$dryBad = mam_v2_load($dbDry2, $srcPD);
t_ok($dryBad['ok'] === false,
    'a target the plan cannot be loaded into FAILS the dry run — the whole point of writing and rolling back');
t_ok(str_contains((string) ($dryBad['message'] ?? ''), 'contact_messages'),
    'naming the table: ' . ($dryBad['message'] ?? ''));
