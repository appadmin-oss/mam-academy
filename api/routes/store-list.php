<?php
/**
 * GET /api/store/items[?category=&country=]
 * Public catalogue, region-filtered. Inactive + sold-out items are returned
 * with their flags intact so the UI can show them grayed-out (and so a
 * shareable item URL never 404s after the admin de-activates it).
 */
declare(strict_types=1);
$country = strtoupper(preg_replace('/[^A-Z]/i', '', (string)($_GET['country'] ?? mam_detect_country())));
$category = trim((string)($_GET['category'] ?? ''));
$sql = 'SELECT * FROM store_items WHERE active = 1';
$args = [];
if ($category !== '') { $sql .= ' AND category = ?'; $args[] = $category; }
$sql .= ' ORDER BY sort_order ASC, id DESC';
$rows = mam_db_all($sql, $args);
$out = [];
foreach ($rows as $r) {
    $item = mam_store_hydrate($r);
    $item['available_here'] = mam_store_item_available($item, $country);
    // Never leak the digital file URL to the public catalogue — only paid
    // customers see it (delivered via email). Strip before responding.
    unset($item['file_url']);
    $out[] = $item;
}
mam_ok(['items' => $out, 'country' => $country, 'category' => $category]);
