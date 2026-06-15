<?php
/**
 * GET /api/admin/placement?app_id=N
 *
 * AI placement assist (L4 / Application A4). Returns an ADVISORY pathway/level
 * recommendation + rationale for an application, via the provider-agnostic AI
 * gateway. Human-confirmed — nothing is written to the application; the run is
 * audited in ai_runs.
 *   - AI off → {enabled:false, recommendation:null}  (no model call)
 *   - AI on  → {enabled:true,  recommendation:{json,text,provider,model}|null}
 */
declare(strict_types=1);

mam_require_admin_cap('admissions');

$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0) mam_fail('missing_app_id', 422);

if (!mam_ai_enabled('placement')) {
    mam_ok(['enabled' => false, 'recommendation' => null]);
}

$by = mam_current_user();
mam_ok(['enabled' => true, 'recommendation' => mam_ai_placement_recommendation($appId, $by ? (int)$by['id'] : null)]);
