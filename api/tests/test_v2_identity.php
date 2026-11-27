<?php
/**
 * ETL identity resolution — one human, one row, and an erasure stays erased.
 *
 * The fixtures below mirror the SHAPES found in the real production export
 * (16 customers, 10 users, 5 shared addresses, two data-rights tombstones,
 * one applicant holding six applications) without carrying any of its content.
 * Production identity data does not belong in a repository.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/v2/lib/identity.php';

t_group('ETL identity — matching');

t_eq(mam_v2_email_key('  Ada@Example.TEST '), 'ada@example.test', 'matching folds case and whitespace');
t_eq(mam_v2_email_key(null), '', 'a null address folds to empty rather than throwing');

// Gmail-style dot folding is deliberately NOT done. It is true for Gmail and
// false for most hosts, and a wrong merge fuses two people's records — which
// cannot be undone without going back to the snapshot. Under-merging leaves two
// rows a human can join later; over-merging destroys data.
t_ok(mam_v2_email_key('a.b@gmail.com') !== mam_v2_email_key('ab@gmail.com'),
    'dot-folding is NOT applied — a wrong merge is unrecoverable, a missed one is not');
t_ok(mam_v2_email_key('x+tag@a.test') !== mam_v2_email_key('x@a.test'), '…nor plus-addressing');

t_group('ETL identity — the merge');

$customers = [
    ['id' => 1, 'email' => 'erased-c1@deleted.invalid', 'first_name' => 'Erased', 'last_name' => 'User', 'phone' => '+2340000000', 'dob' => '2001-01-01'],
    ['id' => 2, 'email' => 'Shared@Example.test',  'first_name' => 'Ada',  'last_name' => 'Obi',  'phone' => '+2348011111', 'dob' => '2009-03-04'],
    ['id' => 3, 'email' => 'applicant@example.test','first_name' => 'Femi', 'last_name' => 'Bello','phone' => '+2348022222', 'dob' => '2010-05-06'],
    ['id' => 4, 'email' => '',                      'first_name' => 'No',   'last_name' => 'Email','phone' => null, 'dob' => null],
];
$users = [
    ['id' => 9,  'email' => 'shared@example.test', 'name' => 'Ada Obi',   'phone' => '+2348011111', 'role' => 'student'],
    ['id' => 10, 'email' => 'staff@example.test',  'name' => 'Ngozi Eze', 'phone' => null,          'role' => 'instructor'],
    ['id' => 2,  'email' => 'erased-u2@deleted.invalid', 'name' => '',    'phone' => null,          'role' => 'student'],
];

$r = mam_v2_resolve_identities($customers, $users);
$people = $r['people'];

// 4 customers + 3 users, one address shared → 6 humans.
t_eq(count($people), 6, 'a shared address merges the applicant and the account into one person');
t_eq($r['report']['merged'], 1, '…and exactly one merge is reported');

$byEmail = [];
foreach ($people as $p) $byEmail[mam_v2_email_key($p['email'])] = $p;

t_eq(count($byEmail['shared@example.test']['sources']), 2, 'the merged person names both source rows');
t_ok(in_array('customers:2', $byEmail['shared@example.test']['sources'], true), '…the customer row');
t_ok(in_array('users:9', $byEmail['shared@example.test']['sources'], true), '…and the user row');
t_eq($byEmail['shared@example.test']['customer_id'], 2, 'the v1 customer id is kept so applications can be repointed');
t_eq($byEmail['shared@example.test']['user_id'], 9, '…and the v1 user id, for sessions and roles');
// The login address is authoritative: it is the one the person types.
t_eq($byEmail['shared@example.test']['email'], 'shared@example.test',
    "the account's address wins over the applicant's, casing and all");
t_eq($byEmail['shared@example.test']['dob'], '2009-03-04',
    'and the applicant record still contributes what the account never had');

t_ok(isset($byEmail['applicant@example.test']), 'an applicant with no account is still a person');
t_ok($byEmail['applicant@example.test']['credentials'] === false,
    '…carrying no credentials, because they never had a login');
t_ok($byEmail['staff@example.test']['credentials'] === true, 'an account holder does carry credentials');

t_group('ETL identity — an erasure stays erased');

// The tombstone is the rule this file exists for. One of the erased rows in the
// live data still has six applications attached; an ETL that inferred identity
// from surrounding rows would rebuild the person the academy was asked to
// forget.
$erased = array_values(array_filter($people, fn($p) => $p['erased']));
t_eq(count($erased), 2, 'both tombstones are recognised (customer AND user side)');
foreach ($erased as $e) {
    t_eq($e['full_name'], '', 'an erased person carries no name');
    t_eq($e['phone'], null, '…no phone');
    t_eq($e['dob'], null, '…no date of birth');
    t_ok($e['emailable'] === false, '…and is never emailable');
    t_ok($e['credentials'] === false, '…and is never given credentials');
}
t_ok(mam_v2_is_erased('someone@deleted.invalid'), '.invalid marks a tombstone');
t_ok(mam_v2_is_erased('SOMEONE@DELETED.INVALID'), '…whatever the casing');
t_ok(!mam_v2_is_erased('someone@invalid-domain.test'), 'but a real address containing "invalid" is not one');
t_ok(!mam_v2_is_erased(''), 'and an empty address is not a tombstone');

// The tombstone still EXISTS, so its applications keep a valid owner and the
// row counts reconcile. Deleting it would break both.
t_ok($erased[0]['customer_id'] !== null || $erased[0]['user_id'] !== null,
    'a tombstone keeps its v1 id, so its applications still have an owner');

// An erasure on either side wins. A customer erased while the portal account
// survives is a v1 inconsistency, and "forget me" reads the stricter way.
$mixed = mam_v2_resolve_identities(
    [['id' => 50, 'email' => 'both@example.test', 'first_name' => 'Still', 'last_name' => 'Here']],
    [['id' => 50, 'email' => 'both@example.test', 'name' => 'Still Here']]
);
t_ok($mixed['people'][0]['erased'] === false, 'a person erased on neither side is not a tombstone');

t_group('ETL identity — nobody is dropped');

t_eq(count(mam_v2_identity_unaccounted($customers, $users, $people)), 0,
    'every v1 row lands in exactly one person — the row-preserving claim is checkable, not asserted');

// A row with no address must not collapse into a shared blank with every other
// such row, which would silently merge unrelated people.
t_eq($r['report']['no_email'], 1, 'a row with no address is counted');
$blank = array_values(array_filter($people, fn($p) => $p['email'] === ''));
t_eq(count($blank), 1, '…and gets its own person rather than joining a shared blank');

$c2 = array_merge($customers, [['id' => 5, 'email' => '', 'first_name' => 'Also', 'last_name' => 'Blank']]);
$r2 = mam_v2_resolve_identities($c2, $users);
t_eq(count(array_filter($r2['people'], fn($p) => $p['email'] === '')), 2,
    'two address-less rows stay two people, never one');
t_eq(count(mam_v2_identity_unaccounted($c2, $users, $r2['people'])), 0, '…and both are still accounted for');

t_group('ETL identity — the report reconciles');

t_eq($r['report']['customers'], 4, 'the report states what went in');
t_eq($r['report']['users'], 3, '…from both tables');
t_eq($r['report']['people'], 6, '…and what came out');
t_eq($r['report']['customers'] + $r['report']['users'] - $r['report']['merged'], $r['report']['people'],
    'in + in − merged = out, so a silent drop cannot balance');
