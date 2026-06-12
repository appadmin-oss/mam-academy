<?php
/** Store — server-authoritative totals, availability, stock & geo guards. */
declare(strict_types=1);
t_group('store totals & availability');

mam_db_insert('store_items', ['sku' => 'T-DIG', 'slug' => 't-dig', 'name' => 'Theory eBook',  'goods_type' => 'digital',  'price_usd' => 10.0, 'tax_rate' => 0,    'stock' => -1]);
mam_db_insert('store_items', ['sku' => 'T-PHY', 'slug' => 't-phy', 'name' => 'Workbook',      'goods_type' => 'physical', 'price_usd' => 20.0, 'tax_rate' => 0.10, 'stock' => 5]);
mam_db_insert('store_items', ['sku' => 'T-OUT', 'slug' => 't-out', 'name' => 'Sold-out Tee',  'goods_type' => 'physical', 'price_usd' => 15.0, 'availability' => 'sold_out']);
mam_db_insert('store_items', ['sku' => 'T-GEO', 'slug' => 't-geo', 'name' => 'NG-only Drum',  'goods_type' => 'physical', 'price_usd' => 30.0, 'available_locations' => '["NG"]', 'stock' => 2]);
mam_db_insert('store_items', ['sku' => 'T-OFF', 'slug' => 't-off', 'name' => 'Retired Item',  'goods_type' => 'digital',  'price_usd' => 9.0,  'active' => 0]);

// Digital-only cart: no shipping, quantities multiply.
$t = mam_store_compute_totals([['sku' => 'T-DIG', 'qty' => 2]], 'NG');
t_near($t['subtotal_usd'], 20.0, 'digital ×2 subtotal = 20');
t_near($t['shipping_usd'], 0.0, 'no shipping on a digital-only cart');
t_near($t['total_usd'], 20.0, 'digital-only total = subtotal');

// Mixed cart: flat shipping (default $5) + per-item tax.
$t = mam_store_compute_totals([['sku' => 'T-DIG', 'qty' => 1], ['sku' => 'T-PHY', 'qty' => 1]], 'NG');
t_near($t['tax_usd'], 2.0, 'tax = 10% of the physical line');
t_near($t['shipping_usd'], 5.0, 'physical item triggers the flat shipping rate');
t_near($t['total_usd'], 37.0, 'mixed total = 10 + 20 + 2 tax + 5 shipping');

// Guards: sold-out, beyond-stock, geo-blocked and inactive lines are dropped.
$t = mam_store_compute_totals([['sku' => 'T-OUT', 'qty' => 1]], 'NG');
t_eq(count($t['items']), 0, 'sold-out item is dropped');
$t = mam_store_compute_totals([['sku' => 'T-PHY', 'qty' => 6]], 'NG');
t_eq(count($t['items']), 0, 'quantity beyond stock is dropped');
$t = mam_store_compute_totals([['sku' => 'T-GEO', 'qty' => 1]], 'US');
t_eq(count($t['items']), 0, 'geo-restricted item dropped outside its region');
$t = mam_store_compute_totals([['sku' => 'T-GEO', 'qty' => 1]], 'ng');
t_eq(count($t['items']), 1, 'geo match is case-insensitive');
$t = mam_store_compute_totals([['sku' => 'T-OFF', 'qty' => 1]], 'NG');
t_eq(count($t['items']), 0, 'inactive item is dropped');

// References: distinct, prefixed, charge-safe charset.
$r1 = mam_store_new_reference(); $r2 = mam_store_new_reference();
t_ok(strpos($r1, 'MAM-STORE-') === 0, 'order reference carries the store prefix');
t_ok($r1 !== $r2, 'order references are unique per call');
t_ok((bool)preg_match('/^[A-Z0-9\-]+$/', $r1), 'reference uses the payment-safe charset');
