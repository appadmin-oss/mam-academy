<?php
/**
 * GET /api/store/item/{slug}
 * Public detail. Identical privacy guarantees to the list endpoint —
 * file_url is stripped so the only way to receive it is to buy.
 */
declare(strict_types=1);
$slug = preg_replace('/[^a-z0-9\-]/i', '', (string)($params['slug'] ?? ''));
if ($slug === '') mam_fail('missing_slug', 400);
$row = mam_db_row('SELECT * FROM store_items WHERE slug = ?', [$slug]);
if (!$row || !(int)$row['active']) mam_fail('item_not_found', 404);
$item = mam_store_hydrate($row);
unset($item['file_url']);
$country = strtoupper(preg_replace('/[^A-Z]/i', '', (string)($_GET['country'] ?? mam_detect_country())));
$item['available_here'] = mam_store_item_available($item, $country);
$item['country']        = $country;
mam_ok(['item' => $item]);
