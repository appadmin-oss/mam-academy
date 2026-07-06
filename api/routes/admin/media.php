<?php
/**
 * GET /api/admin/media
 *
 * Media & documents library (admin, 'media' capability). Aggregates the site's
 * managed assets — class documents/resources plus student & staff portal photos
 * — for the admin Media view (and any future integration). Read-only; mutation
 * happens through the server-rendered admin actions (resource_add/_delete,
 * avatar_clear).
 */
declare(strict_types=1);

mam_require_admin_cap('media');

$resources = mam_db_all(
    "SELECT r.id, r.title, r.url, r.type, r.created_at, r.class_id, c.title AS class_title, u.name AS poster
       FROM class_resources r
       LEFT JOIN classes c ON c.id = r.class_id
       LEFT JOIN users u ON u.id = r.posted_by
      ORDER BY r.id DESC LIMIT 200"
);
$photos = mam_db_all(
    "SELECT id, name, role, avatar_url FROM users WHERE avatar_url IS NOT NULL AND avatar_url <> '' ORDER BY role, name LIMIT 200"
);
$headshots = (int)(mam_db_row("SELECT COUNT(*) AS n FROM applications WHERE headshot_url IS NOT NULL AND headshot_url <> ''")['n'] ?? 0);

mam_ok([
    'resources'           => $resources,
    'photos'              => $photos,
    'applicant_headshots' => $headshots,
    'counts'              => ['resources' => count($resources), 'photos' => count($photos)],
]);
