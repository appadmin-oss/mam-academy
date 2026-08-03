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

    $app = mam_db_row('SELECT id, reference, status FROM summer_applications WHERE id = ?', [$id]);
    if (!$app) mam_fail('not_found', 404);

    $from = (string)$app['status'];
    // Enforce the lifecycle (was: any status → any status, so a rejected place
    // could be walked back to 'enrolled' and a cancelled one re-confirmed).
    // Re-asserting the current status stays a no-op so the UI can be idempotent.
    if ($from !== $status && !mam_summer_can_transition($from, $status)) {
        mam_fail('illegal_transition', 409, ['from' => $from, 'to' => $status]);
    }

    $wasEnrolled = ($from === 'enrolled');
    if ($from !== $status) {
        mam_db_update('summer_applications',
            ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );
        mam_audit('update', 'summer_application', $id, ['from' => $from, 'to' => $status]);
    }

    // Becoming 'enrolled' must actually put the child on the LMS: a roster
    // identity (no portal login) enrolled into the SUMMER-<year> cohort for
    // their discipline. Previously this flipped the status column and minted a
    // card, leaving "enrolled" summer students off every roster and mark sheet.
    $enrolment = null;
    if ($status === 'enrolled' && !$wasEnrolled) {
        try {
            $enrolment = mam_summer_enrol($id, (int)(mam_current_user()['id'] ?? 0) ?: null);
        } catch (\Throwable $e) {
            mam_log('error', 'summer_enrol_failed', ['summer_app' => $id, 'err' => $e->getMessage()]);
        }
        // Automation #9: issue ID card when summer application is enrolled.
        if (mam_automation_on('id_card_issue')) mam_card_mint_for_summer_app($id);
    }

    mam_ok([
        'ok' => true, 'reference' => $app['reference'], 'status' => $status,
        'enrolment' => $enrolment,
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
