<?php
/**
 * ETL transform — money is exact, and nothing is guessed.
 *
 * The money assertions are the point. A transform that approximates an amount,
 * invents an FX rate, or silently defaults a status has produced a database
 * that looks complete and is wrong — the worst outcome available to this
 * project, because the snapshot gets deleted and the error is discovered by a
 * parent disputing a balance.
 *
 * Fixtures mirror the shapes of the real v59 export (decimal-string amounts,
 * pending_bank_transfer dominant, an erased applicant holding applications)
 * without carrying its content.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/v2/lib/transform.php';

t_group('ETL transform — minor units by string arithmetic');

t_eq(mam_v2_minor('7500.00'), 750000, 'a decimal string becomes exact minor units');
t_eq(mam_v2_minor('333280.00'), 33328000, '…at any size');
t_eq(mam_v2_minor('0.01'), 1, 'one kobo');
t_eq(mam_v2_minor('0'), 0, 'zero is zero, not null');
t_eq(mam_v2_minor('12'), 1200, 'a whole number with no point still scales');
t_eq(mam_v2_minor('-45.50'), -4550, 'a refund keeps its sign');

// The reason this is string arithmetic. In binary floating point 8.07 * 100 is
// 806.9999999999999, so `(int) round($v * 100)` is a coin flip on values a
// currency produces every day — and money one kobo short reconciles against
// nothing.
t_eq(mam_v2_minor('8.07'), 807, '8.07 → 807 (float maths gives 806.9999999999999)');
t_eq(mam_v2_minor('1.15'), 115, '1.15 → 115');
t_eq(mam_v2_minor('2.675'), null, 'a third decimal in a 2-exponent currency is REFUSED, not rounded');
t_eq(mam_v2_minor('1.100'), 110, '…but trailing zeros beyond the exponent are harmless');

t_eq(mam_v2_minor('1200', 0), 1200, 'a zero-exponent currency does not gain a fake ×100');
t_eq(mam_v2_minor('12.50', 0), null, '…and refuses a fraction it cannot represent');
t_eq(mam_v2_minor('1.234', 3), 1234, 'a three-exponent currency keeps all three');

t_eq(mam_v2_minor(null), null, 'a null amount stays null');
t_eq(mam_v2_minor(''), null, 'so does an empty one');
t_eq(mam_v2_minor('N7,500'), null, 'and anything unparseable is refused rather than coerced to 0');
t_eq(mam_v2_minor('abc'), null, '…including outright junk');

t_group('ETL transform — status mapping');

t_eq(mam_v2_map_payment_status('pending_bank_transfer'), 'awaiting_transfer',
    'a bank transfer keeps its own state — it is the only one that needs a human');
t_eq(mam_v2_map_payment_status('pending'), 'pending', 'a gateway payment stays plain pending');
t_eq(mam_v2_map_payment_status('succeeded'), 'succeeded', 'settled is settled');
t_eq(mam_v2_map_payment_status('SUCCESS'), 'succeeded', 'casing and provider spelling are folded');
t_eq(mam_v2_map_payment_status('who knows'), 'pending', 'an unknown status falls back to the SAFE side');
// Safe means "still owed". Defaulting an unrecognised status to `succeeded`
// would silently mark money as received.
t_ok(mam_v2_map_payment_status('') !== 'succeeded', 'an empty status is never read as paid');

t_eq(mam_v2_map_application_status('enrolled'), 'accepted',
    '"enrolled" ends at accepted — the enrolment row carries what happened next');
t_eq(mam_v2_map_application_status('submitted'), 'submitted', 'submitted is submitted');
t_eq(mam_v2_map_application_status('cancelled'), 'withdrawn', 'cancelled reads as withdrawn');
t_eq(mam_v2_map_application_status('mystery'), 'submitted', 'an unknown status stays in the funnel');

// Every mapping target must exist in the v2 vocabulary, or the load fails on a
// CHECK constraint after the transform has already claimed success.
require_once dirname(__DIR__) . '/modules/v2/lib/status-catalogue.php';
$cat = mam_v2_status_catalogue();
foreach (['pending_bank_transfer', 'pending', 'succeeded', 'failed', 'refunded', 'disputed', 'nonsense'] as $v1) {
    t_ok(isset($cat['payment'][mam_v2_map_payment_status($v1)]),
        "payment '$v1' maps to a status the v2 schema accepts");
}
foreach (['draft', 'submitted', 'enrolled', 'accepted', 'cancelled', 'nonsense'] as $v1) {
    t_ok(isset($cat['application'][mam_v2_map_application_status($v1)]),
        "application '$v1' maps to a status the v2 schema accepts");
}

t_group('ETL transform — payments');

$people = mam_v2_resolve_identities(
    [['id' => 1, 'email' => 'erased-c1@deleted.invalid', 'first_name' => 'Erased', 'last_name' => 'User'],
     ['id' => 2, 'email' => 'ada@example.test', 'first_name' => 'Ada', 'last_name' => 'Obi']],
    [['id' => 9, 'email' => 'ada@example.test', 'name' => 'Ada Obi']]
)['people'];

$pay = [
    ['id' => 1, 'provider' => 'bank', 'status' => 'pending_bank_transfer', 'amount' => '7500.00', 'currency' => 'NGN', '_customer_id' => 2],
    ['id' => 2, 'provider' => 'paystack', 'status' => 'pending', 'amount' => '89636.00', 'currency' => 'NGN', '_customer_id' => 2],
    ['id' => 3, 'provider' => 'bank', 'status' => 'succeeded', 'amount' => '6918.00', 'currency' => 'NGN', '_customer_id' => 2, 'verified_at' => '2026-07-15 14:19:00'],
];
$t = mam_v2_transform_payments($pay, $people);

t_eq($t['report']['out'], 3, 'every payment transforms');
t_eq(count($t['rejected']), 0, '…with nothing rejected');
t_eq($t['report']['by_status']['awaiting_transfer'], 1, 'the bank transfer keeps its own state');
t_eq($t['report']['total_minor'], 750000 + 8963600 + 691800, 'the total is exact minor units');

// The whole point of choosing NGN as the base: same currency means the base
// figure IS the amount and there is no rate to freeze, so nothing is invented.
foreach ($t['rows'] as $r) {
    t_eq($r['base_currency'], 'NGN', 'the base currency is NGN');
    t_eq($r['base_amount_minor'], $r['amount_minor'], '…so the base amount is the amount, not a conversion');
    t_eq($r['fx_rate'], null, '…and no FX rate is invented');
}

// A non-NGN payment has no historical rate, so it is REJECTED rather than
// written with a 1:1 conversion that would later be read as fact.
$fx = mam_v2_transform_payments(
    [['id' => 4, 'provider' => 'stripe', 'status' => 'succeeded', 'amount' => '75.00', 'currency' => 'USD', '_customer_id' => 2]],
    $people);
t_eq($fx['report']['out'], 0, 'a non-base-currency payment is not transformed');
t_eq(count($fx['rejected']), 1, '…it is rejected');
t_ok(str_contains($fx['rejected'][0]['why'], 'no historical rate'), '…and the reason names the missing rate');

// An amount that cannot be represented exactly must never be written.
$bad = mam_v2_transform_payments(
    [['id' => 5, 'provider' => 'bank', 'status' => 'pending', 'amount' => '10.005', 'currency' => 'NGN', '_customer_id' => 2]],
    $people);
t_eq($bad['report']['out'], 0, 'an inexact amount is not written');
t_ok(str_contains($bad['rejected'][0]['why'], 'not exactly representable'), '…and says so');

$unowned = mam_v2_transform_payments([['id' => 6, 'provider' => 'bank', 'status' => 'pending', 'amount' => '1.00', 'currency' => 'NGN']], $people);
t_eq($unowned['report']['unowned'], 1, 'a payment with no resolvable owner is counted, not hidden');
t_eq($unowned['report']['out'], 1, '…and still carried across, because the money is real');

t_group('ETL transform — applications');

$apps = [
    ['id' => 10, 'customer_id' => 2, 'status' => 'submitted', 'reference' => 'MAM-1', 'goals' => 'learn piano', 'pathway' => 'standard'],
    ['id' => 11, 'customer_id' => 2, 'status' => 'enrolled',  'reference' => 'MAM-2'],
    ['id' => 12, 'customer_id' => 1, 'status' => 'submitted', 'reference' => 'MAM-3', 'goals' => 'identifying free text'],
    ['id' => 13, 'customer_id' => 99, 'status' => 'submitted', 'reference' => 'MAM-4'],
];
$ta = mam_v2_transform_applications($apps, $people);

t_eq($ta['report']['out'], 3, 'applications with a resolvable applicant transform');
t_eq($ta['report']['unowned'], 1, '…and one with no applicant is rejected, not silently dropped');
t_ok(str_contains($ta['rejected'][0]['why'], 'no resolvable applicant'), '…with a reason');
t_eq($ta['report']['by_status']['accepted'], 1, '"enrolled" landed as accepted');

// An erased person KEEPS their applications — the counts must reconcile and the
// funnel is the academy's own record — but the payload holds free-text answers
// that identify them, so it is dropped.
t_eq($ta['report']['erased_owner'], 1, 'an erased applicant\'s applications are still carried');
$erasedApp = null;
foreach ($ta['rows'] as $r) if ($r['legacy'][1] === '12') $erasedApp = $r;
t_ok($erasedApp !== null, '…the row exists');
t_eq($erasedApp['payload'], null, '…but its free-text payload is dropped, not migrated');
$liveApp = null;
foreach ($ta['rows'] as $r) if ($r['legacy'][1] === '10') $liveApp = $r;
t_eq($liveApp['payload']['goals'], 'learn piano', 'while a live applicant keeps theirs');

// A rejection must carry a reason — v2 enforces it with a CHECK, so a transform
// that emits NULL there fails the load rather than the test.
$rej = mam_v2_transform_applications([['id' => 20, 'customer_id' => 2, 'status' => 'rejected']], $people);
t_ok($rej['rows'][0]['decision_reason'] !== null,
    'a rejected application always carries a reason (v2 CHECKs it, so NULL would fail the load)');

t_group('ETL transform — the legacy map');

$map = mam_v2_legacy_rows($ta['rows']);
t_eq(count($map), 3, 'every transformed row records where it came from');
t_eq($map[0]['legacy_table'], 'applications', '…naming the v1 table');
t_ok($map[0]['legacy_id'] !== '', '…and the v1 id, so an old reference stays resolvable after cutover');
