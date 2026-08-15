<?php
/**
 * GET /api/recital/:slug   → the public programme
 * GET /api/recitals        → upcoming + past recitals
 *
 * No authentication. A concert programme is something a family forwards to a
 * grandparent; requiring a login to read it would defeat the point. Only
 * programmed and completed recitals are reachable, so a draft never leaks.
 */
declare(strict_types=1);

$slug = trim((string) ($params['slug'] ?? $_GET['slug'] ?? ''));

if ($slug === '') {
    mam_ok(['recitals' => array_map('mam_recital_public_card', mam_recital_public_list(24))]);
}

$r = mam_recital_by_slug($slug);
if (!$r || !in_array((string) $r['status'], ['programmed', 'completed'], true)) mam_fail('not_found', 404);

$p = mam_recital_programme_full((int) $r['id']);
mam_ok([
    'recital'   => mam_recital_public_card($r),
    'items'     => array_map('mam_recital_public_item', $p['items']),
    'total_min' => $p['total_min'],
    'ends_at'   => $p['ends_at'],
    'performer_count' => $p['performer_count'],
]);
