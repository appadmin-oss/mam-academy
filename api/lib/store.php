<?php
/**
 * MAM Store / Library — shared helpers.
 *
 * Keeps the store logic out of the route files so a future extraction to a
 * subdomain (or even a separate service) is a copy-paste exercise rather than
 * untangling: nothing in here references academy-specific tables.
 *
 * Region availability: each item has `available_locations`, a "*" (everywhere)
 * or a JSON array of ISO-2 country codes. The visitor's country is inferred
 * via mam_detect_country() (Cloudflare header → Accept-Language → DEFAULT),
 * with admin/test override via ?country= query string.
 *
 * Pricing: items are priced in USD on the server. Display uses live FX (via
 * the existing currency switcher engine on the frontend). Actual charge
 * settles in NGN through Paystack (same disclosure pattern as the booking
 * fee). The server is authoritative — never trust client-side totals.
 */
declare(strict_types=1);

/** Hydrate JSON columns + cast numbers so the row is API-ready. Pure: no I/O. */
function mam_store_hydrate(array $row): array {
    $row['stock']        = isset($row['stock']) ? (int)$row['stock'] : -1;
    $row['price_usd']    = (float)($row['price_usd'] ?? 0);
    $row['weight_grams'] = isset($row['weight_grams']) ? (int)$row['weight_grams'] : null;
    $row['tax_rate']     = (float)($row['tax_rate'] ?? 0);
    $row['sort_order']   = (int)($row['sort_order'] ?? 0);
    $row['active']       = (int)($row['active'] ?? 1);
    $row['images']       = !empty($row['images']) ? (json_decode((string)$row['images'], true) ?: []) : [];
    if (!empty($row['available_locations']) && $row['available_locations'] !== '*') {
        $decoded = json_decode((string)$row['available_locations'], true);
        $row['available_locations'] = is_array($decoded) ? $decoded : '*';
    }
    return $row;
}

/** True when the item is sellable to a given country code. */
function mam_store_item_available(array $item, string $country): bool {
    $loc = $item['available_locations'] ?? '*';
    if ($loc === '*' || $loc === null) return true;
    if (!is_array($loc)) return true;
    return in_array(strtoupper($country), array_map('strtoupper', $loc), true);
}

/** Compute order totals server-side from a list of {sku, qty}. Authoritative. */
function mam_store_compute_totals(array $cart, string $country): array {
    $items = [];
    $subtotal = 0.0;
    $tax = 0.0;
    foreach ($cart as $line) {
        $sku = (string)($line['sku'] ?? '');
        $qty = max(1, (int)($line['qty'] ?? 1));
        if ($sku === '') continue;
        $row = mam_db_row('SELECT * FROM store_items WHERE sku = ? AND active = 1', [$sku]);
        if (!$row) continue;
        $item = mam_store_hydrate($row);
        if (!mam_store_item_available($item, $country)) continue;
        if ($item['availability'] === 'sold_out') continue;
        if ($item['stock'] >= 0 && $item['stock'] < $qty) continue;
        $line_total = round($item['price_usd'] * $qty, 2);
        $line_tax   = round($line_total * (float)$item['tax_rate'], 2);
        $items[] = [
            'sku'        => $item['sku'],
            'name'       => $item['name'],
            'qty'        => $qty,
            'price_usd'  => $item['price_usd'],
            'line_total' => $line_total,
            'goods_type' => $item['goods_type'],
        ];
        $subtotal += $line_total;
        $tax      += $line_tax;
    }
    // Shipping: physical goods only. Flat $5/order until weight tiers are wired.
    $hasPhysical = false;
    foreach ($items as $i) if (($i['goods_type'] ?? '') === 'physical') { $hasPhysical = true; break; }
    $shipping = $hasPhysical ? 5.00 : 0.00;
    $total = round($subtotal + $tax + $shipping, 2);
    return [
        'items'        => $items,
        'subtotal_usd' => round($subtotal, 2),
        'tax_usd'      => round($tax, 2),
        'shipping_usd' => $shipping,
        'total_usd'    => $total,
    ];
}

/** Generate a short, human-pleasant order reference. */
function mam_store_new_reference(): string {
    return 'MAM-STORE-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
}
