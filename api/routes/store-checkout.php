<?php
/**
 * POST /api/store/checkout
 * Body: { items:[{sku,qty}], email, name, phone?, country?, shipping_address?, notes? }
 * Creates a pending store_orders row, returns its reference. The caller then
 * hits /api/payment/initialize with { reference, purpose:'store', provider }.
 *
 * Server-authoritative totals — we re-compute everything from the catalogue
 * here; the client's cart payload is treated strictly as a list of intents
 * (SKU + qty), never as a source of price truth.
 */
declare(strict_types=1);
$in = mam_json_input();

$cart = (array)($in['items'] ?? []);
if (count($cart) === 0) mam_fail('empty_cart', 400);

$email = mam_email_val(($in['email'] ?? ''));
if (!$email) mam_fail('invalid_email', 400, ['field' => 'email']);
$name  = mam_str($in, 'name', 200);
if ($name === '') mam_fail('missing_name', 400, ['field' => 'name']);
$phone = mam_str($in, 'phone', 32);
$shipping = mam_str($in, 'shipping_address', 600);
$notes = mam_str($in, 'notes', 600);
$country = strtoupper(preg_replace('/[^A-Z]/i', '', (string)($in['country'] ?? mam_detect_country())));

$totals = mam_store_compute_totals($cart, $country);
if (count($totals['items']) === 0) mam_fail('no_purchasable_items', 400, [
    'message' => 'None of the items in your cart are available in your region or in stock.',
]);
if ($totals['total_usd'] <= 0) mam_fail('zero_total', 400);

// Physical orders require a shipping address.
$hasPhysical = false;
foreach ($totals['items'] as $i) if (($i['goods_type'] ?? '') === 'physical') { $hasPhysical = true; break; }
if ($hasPhysical && $shipping === '') mam_fail('missing_shipping_address', 400, ['field' => 'shipping_address']);

$reference = mam_store_new_reference();
$orderId = mam_db_insert('store_orders', [
    'reference'        => $reference,
    'customer_email'   => $email,
    'customer_name'    => $name,
    'customer_phone'   => $phone ?: null,
    'customer_country' => $country ?: null,
    'shipping_address' => $shipping ?: null,
    'items_json'       => json_encode($totals['items']),
    'subtotal_usd'     => $totals['subtotal_usd'],
    'tax_usd'          => $totals['tax_usd'],
    'shipping_usd'     => $totals['shipping_usd'],
    'total_usd'        => $totals['total_usd'],
    'status'           => 'pending',
    'notes'            => $notes ?: null,
]);

mam_audit('store_order_created', 'store_order', (int)$orderId, ['ref' => $reference, 'total_usd' => $totals['total_usd']]);
mam_ok([
    'reference'    => $reference,
    'order_id'     => (int)$orderId,
    'items'        => $totals['items'],
    'subtotal_usd' => $totals['subtotal_usd'],
    'tax_usd'      => $totals['tax_usd'],
    'shipping_usd' => $totals['shipping_usd'],
    'total_usd'    => $totals['total_usd'],
    'next'         => 'POST /api/payment/initialize with reference, purpose:"store", provider',
]);
