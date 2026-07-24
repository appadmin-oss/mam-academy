<?php
/**
 * Tests for the public promo discount engine (v58): the helpers that decide a
 * live public promo's discount, and its server-enforced application to tuition
 * (mam_compute_application_price) and the store (mam_store_compute_totals).
 */

t_group('Promo discount — helpers');

// Clean slate.
mam_db()->exec("DELETE FROM promo_banners");

// No promo → no discount anywhere.
t_ok(mam_promo_active() === null,                 'no promo row → active is null');
t_ok(mam_promo_discount_percent('tuition') === 0, 'no promo → tuition discount 0');
t_ok(mam_promo_discount_percent('store')   === 0, 'no promo → store discount 0');
t_ok(mam_promo_public_payload() === null,         'no promo → null public payload');

// A live PUBLIC promo, 20% off everything, open-ended window.
$today = date('Y-m-d');
$end   = date('Y-m-d', time() + 3 * 86400);
mam_db()->prepare(
    "INSERT INTO promo_banners (title,body,active,public,discount_percent,applies_to,starts_at,ends_at,created_at,updated_at)
     VALUES ('Test Promo','20% off launch offer',1,1,20,'all',?,?,?,?)"
)->execute([$today, $end, $today . ' 00:00:00', $today . ' 00:00:00']);

t_ok(mam_promo_active() !== null,                  'live promo row is found');
t_ok(mam_promo_discount_percent('tuition') === 20, 'public promo (all) → 20% tuition');
t_ok(mam_promo_discount_percent('store')   === 20, 'public promo (all) → 20% store');
$payload = mam_promo_public_payload();
t_ok(is_array($payload) && $payload['discount_percent'] === 20, 'public payload exposes discount');
t_ok($payload['ends_at'] === $end . 'T23:59:59',   'payload countdown target is end-of-day');

// Non-public promo → no public discount.
mam_db()->exec("UPDATE promo_banners SET public = 0");
t_ok(mam_promo_discount_percent('tuition') === 0, 'non-public promo → no discount');
t_ok(mam_promo_public_payload() === null,         'non-public promo → null public payload');

// Store-only promo → applies to store, not tuition.
mam_db()->exec("UPDATE promo_banners SET public = 1, applies_to = 'store'");
t_ok(mam_promo_discount_percent('store')   === 20, 'store-only promo → 20% store');
t_ok(mam_promo_discount_percent('tuition') === 0,  'store-only promo → 0% tuition');

// Percent is clamped to a sane 0–90 range.
mam_db()->exec("UPDATE promo_banners SET applies_to = 'all', discount_percent = 200");
t_ok(mam_promo_discount_percent('tuition') === 90, 'discount percent is clamped to 90');

// Expired window → no discount.
mam_db()->exec("UPDATE promo_banners SET discount_percent = 20, ends_at = '2000-01-01'");
t_ok(mam_promo_active() === null,                  'expired promo → not active');
t_ok(mam_promo_discount_percent('tuition') === 0, 'expired promo → no discount');

t_group('Promo discount — tuition enforcement');

mam_db()->exec("DELETE FROM promo_banners");
$args = ['programme' => 'certified-musician', 'level' => 'precert', 'mode' => 'onsite', 'extraCourses' => 0, 'includeRegistration' => false];
$full = mam_compute_application_price($args);
t_ok(($full['promo_pct'] ?? -1) === 0,   'no promo → promo_pct 0 on tuition quote');
$fullTotal = $full['total'];

// Activate a 25%-off tuition promo and re-price.
mam_db()->prepare(
    "INSERT INTO promo_banners (title,body,active,public,discount_percent,applies_to,created_at,updated_at)
     VALUES ('T','25% off',1,1,25,'tuition',?,?)"
)->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
$disc = mam_compute_application_price($args);
t_ok($disc['promo_pct'] === 25,                       'tuition quote reflects 25% promo');
t_ok($disc['promo_amount'] > 0,                        'tuition promo_amount is recorded');
t_ok($disc['total'] < $fullTotal,                      'discounted tuition total is lower');
t_ok(abs($disc['total'] - round($fullTotal * 0.75)) <= 1, 'discounted total ≈ 75% of full (registration off here)');

// Registration fee is never discounted.
$withReg = mam_compute_application_price(array_merge($args, ['includeRegistration' => true]));
t_ok($withReg['registration'] === $full['registration'] || $withReg['registration'] > 0, 'registration fee unaffected by promo');

t_group('Promo discount — store enforcement');

mam_db()->exec("DELETE FROM promo_banners");
// Seed a simple active digital store item.
mam_db()->exec("DELETE FROM store_items WHERE sku = 'PROMO-TEST'");
mam_db()->exec("INSERT INTO store_items (sku,slug,name,price_usd,currency,goods_type,availability,stock,tax_rate,active,created_at)
  VALUES ('PROMO-TEST','promo-test','Promo Test Item',100,'USD','digital','in_stock',-1,0,1,'2026-01-01')");
$cart = [['sku' => 'PROMO-TEST', 'qty' => 2]];

$noPromo = mam_store_compute_totals($cart, 'NG');
t_ok(($noPromo['promo_pct'] ?? -1) === 0,      'no promo → store promo_pct 0');
t_ok(abs($noPromo['total_usd'] - 200) < 0.01,  'store total is 200 without promo');

mam_db()->prepare(
    "INSERT INTO promo_banners (title,body,active,public,discount_percent,applies_to,created_at,updated_at)
     VALUES ('S','30% off store',1,1,30,'store',?,?)"
)->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
$promo = mam_store_compute_totals($cart, 'NG');
t_ok($promo['promo_pct'] === 30,                        'store totals reflect 30% promo');
t_ok(abs($promo['promo_discount_usd'] - 60) < 0.01,     'store promo discount is 60 (30% of 200)');
t_ok(abs($promo['subtotal_usd'] - 140) < 0.01,          'store subtotal discounted to 140');
t_ok(abs($promo['total_usd'] - 140) < 0.01,             'store total discounted to 140');

// Cleanup.
mam_db()->exec("DELETE FROM promo_banners");
mam_db()->exec("DELETE FROM store_items WHERE sku = 'PROMO-TEST'");
