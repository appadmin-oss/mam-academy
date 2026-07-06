<?php
/**
 * GET /api/admin/ai-triage?app_id=N
 *
 * AI admissions triage (Application A4). Returns an ADVISORY reviewer summary
 * (summary, strengths, flags, suggested action) for one application via the
 * provider-agnostic AI gateway. Staff-only, human-decides — nothing is written
 * to the application; the run is audited in ai_runs.
 *   - AI off → {enabled:false, triage:null}  (no model call)
 *   - AI on  → {enabled:true,  triage:{json,provider,model}|null}
 */
declare(strict_types=1);

mam_require_admin_cap('admissions');

$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0) mam_fail('missing_app_id', 422);

if (!mam_ai_enabled('triage')) {
    mam_ok(['enabled' => false, 'triage' => null]);
}

$u = mam_current_user();
mam_ok([
    'enabled' => true,
    'triage'  => mam_ai_triage_application($appId, $u ? (int)$u['id'] : null),
]);
