<?php
/**
 * Admin store-item CRUD. One file, dispatches on $_SERVER['REQUEST_METHOD']
 * for simplicity — kept tiny so the admin panel can wire it in one fetch().
 *
 *   GET    /api/admin/store/items                 → list everything (incl. inactive)
 *   POST   /api/admin/store/items                 → create
 *   POST   /api/admin/store/items?id=N            → update (id required for update)
 *   POST   /api/admin/store/items?id=N&delete=1   → soft-delete (active=0)
 */
declare(strict_types=1);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = mam_db_all('SELECT * FROM store_items ORDER BY sort_order ASC, id DESC');
    $out = array_map('mam_store_hydrate', $rows);
    mam_ok(['items' => $out]);
}

$in = mam_json_input();
$id = (int)($_GET['id'] ?? 0);

// Soft delete: keep the row for order history integrity, just flip active.
if ($id > 0 && (int)($_GET['delete'] ?? 0) === 1) {
    mam_db_update('store_items', ['active' => 0, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
    mam_audit('store_item_deactivated', 'store_item', $id);
    mam_ok(['id' => $id, 'active' => 0]);
}

// Whitelist of editable columns — server is authoritative; never let an
// arbitrary client field slip into the row.
$allow = ['sku','slug','name','description','goods_type','delivery_type','availability','stock','available_locations','price_usd','currency','images','category','file_url','weight_grams','tax_rate','sort_order','active'];
$row = [];
foreach ($allow as $k) {
    if (!array_key_exists($k, $in)) continue;
    $v = $in[$k];
    // Normalise the two JSON-ish columns to text storage.
    if ($k === 'images' && is_array($v)) $v = json_encode(array_values($v));
    if ($k === 'available_locations' && is_array($v)) $v = json_encode(array_values($v));
    if (in_array($k, ['price_usd','tax_rate'], true)) $v = (float)$v;
    if (in_array($k, ['stock','weight_grams','sort_order','active'], true)) $v = $v === null ? null : (int)$v;
    $row[$k] = $v;
}

if ($id === 0) {
    // CREATE — sku, slug, name, price_usd required.
    foreach (['sku','slug','name','price_usd'] as $req) {
        if (empty($row[$req])) mam_fail('missing_field', 400, ['field' => $req]);
    }
    if (mam_db_row('SELECT id FROM store_items WHERE sku = ? OR slug = ?', [$row['sku'], $row['slug']])) {
        mam_fail('duplicate', 409, ['message' => 'A store item with that SKU or slug already exists.']);
    }
    $newId = mam_db_insert('store_items', $row);
    mam_audit('store_item_created', 'store_item', (int)$newId, ['sku' => $row['sku']]);
    mam_ok(['id' => (int)$newId]);
}

// UPDATE
$row['updated_at'] = date('Y-m-d H:i:s');
mam_db_update('store_items', $row, ['id' => $id]);
mam_audit('store_item_updated', 'store_item', $id, ['fields' => array_keys($row)]);
mam_ok(['id' => $id]);
