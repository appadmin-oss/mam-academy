<?php
/**
 * GET  /api/admin/summer         — list summer applications (paginated)
 * POST /api/admin/summer/status  — update status for one application
 */
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $input = mam_json_input();
    $id     = (int)($input['id'] ?? 0);
    $status = (string)($input['status'] ?? '');
    $valid  = ['submitted', 'confirmed', 'waitlisted', 'enrolled', 'cancelled'];
    if (!$id || !in_array($status, $valid, true)) mam_fail('invalid_params', 422);

    $app = mam_db_row('SELECT id, reference, status FROM summer_applications WHERE id = ?', [$id]);
    if (!$app) mam_fail('not_found', 404);

    mam_db_update('summer_applications',
        ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
        ['id' => $id]
    );
    mam_audit('update', 'summer_application', $id, ['from' => $app['status'], 'to' => $status]);
    mam_ok(['ok' => true, 'reference' => $app['reference'], 'status' => $status]);
}

// GET — list
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$offset  = ($page - 1) * $perPage;
$status  = (string)($_GET['status'] ?? '');
$q       = trim((string)($_GET['q'] ?? ''));

$where  = [];
$params = [];
if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(reference LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)mam_db()->prepare("SELECT COUNT(*) FROM summer_applications $whereClause")
    ->execute($params) ? mam_db_row("SELECT COUNT(*) AS n FROM summer_applications $whereClause", $params)['n'] : 0;

$rows = mam_db_all(
    "SELECT id, reference, first_name, last_name, email, phone, dob, age_at_submission,
            discipline, status, session_year, created_at
     FROM summer_applications $whereClause
     ORDER BY id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

mam_ok([
    'applications' => $rows,
    'total'        => (int)$total,
    'page'         => $page,
    'per_page'     => $perPage,
    'pages'        => max(1, (int)ceil($total / $perPage)),
]);
