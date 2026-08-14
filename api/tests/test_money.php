<?php
/**
 * Tests for the v61 money-normalization layer (api/lib/money.php) and the
 * currency-safe revenue query. These lock in the invariant the old
 * SUM(amount) violated: naira and dollars are never added in the same total.
 */
declare(strict_types=1);

t_group('money — minor units & USD canonicalisation');

// Minor units of an amount in its own currency (2-decimal).
t_eq(mam_money_to_minor(90000.0), 9000000, '90,000 NGN → 9,000,000 kobo');
t_eq(mam_money_to_minor(60.0), 6000, '60.00 USD → 6000 cents');
t_eq(mam_money_to_minor(59.999), 6000, 'minor units round to nearest cent');

// Canonical USD cents. The key invariant: ₦90,000 at ₦1500/$ and $60 are the
// SAME money and must produce the SAME amount_usd_cents.
t_eq(mam_money_usd_cents(60.0, 'USD', 1500.0), 6000, '$60 → 6000 usd cents (USD passthrough)');
t_eq(mam_money_usd_cents(90000.0, 'NGN', 1500.0), 6000, '₦90,000 @1500 → 6000 usd cents');
t_eq(mam_money_usd_cents(90000.0, 'ngn', 1500.0), 6000, 'currency match is case-insensitive');
t_eq(mam_money_usd_cents(60.0, 'USD', 1500.0), mam_money_usd_cents(90000.0, 'NGN', 1500.0),
     '$60 and ₦90,000@1500 canonicalise to the identical USD figure');

// Rate safety — never divide by zero; a bad/absent rate falls back to a
// positive rate instead of producing INF/garbage.
t_ok(mam_money_safe_rate(1500.0) === 1500.0, 'safe_rate passes a plausible rate through');
t_ok(mam_money_safe_rate(0.0) > 0, 'safe_rate(0) falls back to a positive rate');
t_ok(mam_money_safe_rate(null) > 0, 'safe_rate(null) falls back to a positive rate');
t_ok(is_int(mam_money_usd_cents(90000.0, 'NGN', 0.0)) && mam_money_usd_cents(90000.0, 'NGN', 0.0) > 0,
     'NGN conversion with rate=0 uses fallback, never crashes');

// NGN display from USD cents, and round-trip stability.
t_eq(mam_money_ngn_from_usd_cents(6000, 1500.0), 90000, '6000 usd cents ($60) @1500 → ₦90,000');
$rt = mam_money_usd_cents(mam_money_ngn_from_usd_cents(6000, 1500.0), 'NGN', 1500.0);
t_near($rt, 6000, 'usd→ngn→usd round-trips within a cent', 1.0);

// Dual display carries both the honest USD figure and a naira aside.
$dual = mam_money_format_dual(6000, 1500.0);
t_ok(strpos($dual, '$60.00') !== false, 'dual display shows the USD total');
t_ok(strpos($dual, '&#8358;') !== false, 'dual display shows the naira symbol');

t_group('money — currency-safe revenue query');

// Schema smoke: the v61 columns exist and are selectable.
t_ok(is_array(mam_db_all('SELECT amount_minor, amount_usd_cents, fx_rate_usd_ngn, fx_backfilled FROM payments LIMIT 0')),
     'payments carries the v61 money columns');

// Baseline first — the suite shares one DB and other tests may have written
// payments, so we assert on the DELTA our fixtures introduce.
$base = mam_money_revenue_usd_cents();

// A naira sale and a dollar sale that, pre-v61, SUM(amount) would have added as
// 150000 + 60 = 150060 (nonsense). Canonically they are $100 + $60 = $160.
mam_db_insert('payments', [
    'reference' => 'REV-NGN-1', 'provider' => 'paystack', 'purpose' => 'tuition',
    'status' => 'succeeded', 'amount' => 150000, 'currency' => 'NGN',
    'amount_minor' => 15000000, 'amount_usd_cents' => 10000, 'fx_rate_usd_ngn' => 1500.0, 'fx_backfilled' => 0,
]);
mam_db_insert('payments', [
    'reference' => 'REV-USD-1', 'provider' => 'wallet', 'purpose' => 'store',
    'status' => 'succeeded', 'amount' => 60.0, 'currency' => 'USD',
    'amount_minor' => 6000, 'amount_usd_cents' => 6000, 'fx_rate_usd_ngn' => 1500.0, 'fx_backfilled' => 0,
]);
// A pending row must never count toward revenue.
mam_db_insert('payments', [
    'reference' => 'REV-PENDING-1', 'provider' => 'paystack', 'purpose' => 'tuition',
    'status' => 'pending', 'amount' => 999999, 'currency' => 'NGN',
    'amount_minor' => 99999900, 'amount_usd_cents' => 66666, 'fx_rate_usd_ngn' => 1500.0, 'fx_backfilled' => 0,
]);

t_eq(mam_money_revenue_usd_cents() - $base, 16000,
     'revenue counts only succeeded rows, summed in USD cents ($160), not mixed units');

// Windowed revenue excludes rows outside the window.
$winBase = mam_money_revenue_usd_cents('2999-01-01 00:00:00');
t_eq($winBase, 0, 'a future `since` window yields zero revenue');
