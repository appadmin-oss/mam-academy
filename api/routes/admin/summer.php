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
    $valid  = ['submitted', 'confirmed', 'waitlisted', 'enrolled', 'cancelled', 'rejected'];
    if (!$id || !in_array($status, $valid, true)) mam_fail('invalid_params', 422);

    // Enrolling makes an account and a class place, like marking a year-round
    // application enrolled — the console form requires `fees` for it, so the
    // JSON twin does too (the route itself needs `admissions`; it used to need
    // only `view`, so a read-only admin could enrol a child).
    $me = mam_current_user();
    if ($status === 'enrolled' && $me && !mam_admin_can($me, 'fees')) mam_fail('forbidden', 403);

    // ONE implementation with the console form (decisions.php): the state
    // machine, the refusal reason, the identity hold, the enrolment, the
    // invitations, the ID card and the family's email.
    try {
        $res = mam_summer_set_status($id, $status, (int)($me['id'] ?? 0) ?: null,
            isset($input['reason']) ? (string)$input['reason'] : null,
            ['identity_confirmed' => !empty($input['identity_confirmed'])]);
    } catch (RuntimeException $e) {
        $code = $e->getMessage();
        if ($code === 'not_found') mam_fail('not_found', 404);
        if ($code === 'illegal_transition') mam_fail('illegal_transition', 409, ['message' => mam_summer_refusal($code)]);
        mam_fail($code, 422, ['message' => mam_summer_refusal($code)]);
    }
    if (!empty($res['held'])) mam_fail('identity_held', 409, ['message' => $res['message']]);

    $ref = (string)(mam_db_row('SELECT reference FROM summer_applications WHERE id = ?', [$id])['reference'] ?? '');
    mam_ok([
        'ok' => true, 'reference' => $ref, 'status' => $status,
        'enrolment' => $res['enrolment'], 'emailed' => $res['emailed'], 'message' => $res['message'],
    ]);
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

// One count query. (This used to prepare+execute a throwaway statement and cast
// its bool result, so the `?:` always took the true branch and the real count ran
// as a second query anyway — correct by accident, and one query too many.)
$total = (int)(mam_db_row("SELECT COUNT(*) AS n FROM summer_applications $whereClause", $params)['n'] ?? 0);

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
