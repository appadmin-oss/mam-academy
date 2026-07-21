<?php
/**
 * Admin instructor-fines management.
 *
 * GET  ?status=pending|resolved|waived&limit=N  → {fines:[...], counts:{...}}
 * POST action=resolve  {fine_id, note?}          → {ok:true, fine:{...}}
 * POST action=waive    {fine_id, note?}           → {ok:true, fine:{...}}
 */
declare(strict_types=1);
$u = mam_require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = in_array($_GET['status'] ?? '', ['pending','resolved','waived'])
        ? (string)$_GET['status'] : '';
    $limit  = max(1, min(500, (int)($_GET['limit'] ?? 200)));

    $fines  = mam_instructor_fines($limit, $status);

    // Aggregate counts over the full set.
    $all    = $status !== '' ? mam_instructor_fines(5000, '') : $fines;
    $counts = ['pending' => 0, 'resolved' => 0, 'waived' => 0];
    foreach ($all as $f) {
        $st = (string)($f['status'] ?? 'pending');
        if (array_key_exists($st, $counts)) $counts[$st]++;
    }

    mam_ok(['fines' => $fines, 'counts' => $counts]);
    exit;
}

$in     = mam_json_input();
$action = (string)($in['action'] ?? '');
$fineId = (int)($in['fine_id'] ?? 0);
$note   = trim((string)($in['note'] ?? ''));

if (!in_array($action, ['resolve', 'waive'], true)) {
    mam_fail('unknown_action', 400, ['action' => $action]);
}
if ($fineId < 1) {
    mam_fail('missing_field', 400, ['field' => 'fine_id']);
}

try {
    $newStatus = $action === 'waive' ? 'waived' : 'resolved';
    $fine      = mam_fine_resolve($fineId, (int)$u['id'], $newStatus, $note);
    mam_ok(['fine' => $fine]);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), 409);
}
