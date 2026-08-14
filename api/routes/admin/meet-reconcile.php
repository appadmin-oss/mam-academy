<?php
/**
 * Admin meeting reconciliation import (LMS 2.0, PDF §11).
 *
 *   POST /api/admin/meet-reconcile {session_id, provider?, csv?|rows?}
 *
 * Imports a Google Meet participant CSV (or a rows array) for a session and
 * reconciles it against the portal clock-in record. If the live Google API is
 * configured it is tried first; otherwise the supplied CSV/rows are used.
 */
declare(strict_types=1);

$u = mam_require_role('admin');

$in  = mam_json_input();
$sid = (int) ($in['session_id'] ?? 0);
if ($sid < 1) mam_fail('validation', 422, ['fields' => ['session_id']]);
$provider = mam_str($in, 'provider', 16) ?: 'meet';

try {
    // Prefer a live fetch when configured; fall back to the supplied data.
    $live = mam_meeting_google_fetch($sid);
    if (is_array($live)) {
        $n = mam_meeting_reconcile_import($sid, $live, $provider, 'api');
        mam_ok(['imported' => $n, 'source' => 'api']);
    }
    $rows = [];
    if (isset($in['csv']) && is_string($in['csv'])) $rows = mam_meeting_parse_csv((string) $in['csv']);
    elseif (isset($in['rows']) && is_array($in['rows'])) $rows = $in['rows'];
    if (!$rows) mam_fail('no_data', 422, ['detail' => 'Provide csv or rows.']);
    $n = mam_meeting_reconcile_import($sid, $rows, $provider, 'import');
    mam_ok(['imported' => $n, 'source' => 'import', 'view' => mam_meeting_session_view($sid)]);
} catch (\RuntimeException $e) {
    mam_fail($e->getMessage(), $e->getMessage() === 'session_not_found' ? 404 : 409);
}
